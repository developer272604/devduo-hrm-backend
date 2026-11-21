<?php

namespace App\Http\Controllers\People\Records;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Notifications\NotificationService;
use App\Domain\Operations\Money;
use App\Domain\People\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LetterController extends RecordsController
{
    private const KINDS = ['appointment', 'experience', 'relieving', 'salary_certificate'];

    private const FIELDS = ['employee_name', 'employee_number', 'workspace_name', 'joined_on', 'separated_on', 'issued_on'];

    private function templates(Request $r): Builder
    {
        return DB::table('hr_letter_templates')->where('tenant_id', $r->user()->tenant_id);
    }

    private function canIssue(Request $r, Employee $employee): bool
    {
        return ! $this->rec()->isSelf($r->user(), $employee)
            && $this->rec()->reaches($r->user(), $employee, 'people.documents.manage');
    }

    private function canRead(Request $r, Employee $employee): bool
    {
        return $this->canIssue($r, $employee)
            || $this->rec()->isSelf($r->user(), $employee)
            || $this->rec()->reaches($r->user(), $employee, 'people.confidential.view');
    }

    private function canSeeSalary(Request $r, Employee $employee): bool
    {
        return $this->rec()->isSelf($r->user(), $employee)
            || $this->rec()->reaches($r->user(), $employee, 'payroll.compensation.reveal');
    }

    public function templateList(Request $r)
    {
        abort_unless(app(DataScope::class)->hasTenantWide($r->user(), 'people.documents.manage'), 403);

        return $this->templates($r)->latest('id')->paginate(25, ['uuid', 'kind', 'version', 'title', 'body']);
    }

    public function saveTemplate(Request $r)
    {
        $user = $r->user();
        abort_unless(app(DataScope::class)->hasTenantWide($user, 'people.documents.manage'), 403);
        $data = $r->validate([
            'kind' => 'required|in:'.implode(',', self::KINDS),
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:12000',
        ]);
        $allowed = $data['kind'] === 'salary_certificate'
            ? [...self::FIELDS, 'salary_amount', 'salary_currency', 'salary_period']
            : self::FIELDS;
        preg_match_all('/{{\s*([^{}]+?)\s*}}/u', $data['body'], $matches);
        if (array_diff($matches[1], $allowed) || preg_match('/{{|}}/', preg_replace('/{{[^{}]+}}/u', '', $data['body']))) {
            throw ValidationException::withMessages(['body' => 'Use only the supported placeholders for this letter type.']);
        }
        if ($data['kind'] === 'salary_certificate' && array_diff(['salary_amount', 'salary_currency', 'salary_period'], $matches[1])) {
            throw ValidationException::withMessages(['body' => 'Include salary_amount, salary_currency and salary_period in the template.']);
        }

        return DB::transaction(function () use ($r, $user, $data) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->first();
            $version = $this->templates($r)->where('kind', $data['kind'])->max('version') + 1;
            $template = $data + ['uuid' => (string) Str::uuid(), 'version' => $version];
            DB::table('hr_letter_templates')->insert($template + ['tenant_id' => $user->tenant_id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('hr_letter.template_created', 'people', 'template:'.$template['uuid'], ['kind' => $data['kind'], 'version' => $version]);

            return response()->json($template, 201);
        }, 3);
    }

    private function issued(Request $r, Employee $employee): Builder
    {
        $query = DB::table('hr_issued_letters as letters')
            ->join('hr_letter_templates as template', 'letters.template_id', '=', 'template.id')
            ->where('letters.tenant_id', $r->user()->tenant_id)->where('letters.employee_id', $employee->id);
        if (! $this->canSeeSalary($r, $employee)) {
            $query->where('template.kind', '!=', 'salary_certificate');
        }

        return $query;
    }

    public function index(Request $r, string $uuid): array
    {
        $employee = $this->employee($r, $uuid);
        $canIssue = $this->canIssue($r, $employee);
        abort_unless($this->canRead($r, $employee), 403);
        $templates = $this->templates($r);
        $latest = (clone $templates)->selectRaw('MAX(id)')->groupBy('kind');

        return [
            'can_manage_templates' => $canIssue && app(DataScope::class)->hasTenantWide($r->user(), 'people.documents.manage'),
            'can_acknowledge' => $this->rec()->isSelf($r->user(), $employee),
            'issued' => $this->issued($r, $employee)->latest('letters.id')
                ->paginate(25, ['letters.uuid', 'letters.created_at', 'letters.acknowledged_at', 'letters.signature_requested', 'letters.signed_at', 'template.title', 'template.kind', 'template.version']),
            'templates' => $canIssue ? $templates->whereIn('id', $latest)
                ->when(! $this->canSeeSalary($r, $employee), fn (Builder $q) => $q->where('kind', '!=', 'salary_certificate'))
                ->get(['uuid', 'title', 'kind', 'version']) : [],
        ];
    }

    public function issue(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid, 'people.documents.manage', false);
        abort_unless($this->canIssue($r, $employee), 403);
        $data = $r->validate(['template' => 'required|uuid', 'signature_requested' => 'sometimes|boolean', 'salary_amount' => 'nullable|string|max:16',
            'salary_currency' => 'nullable|string|in:'.implode(',', array_keys(Money::SCALES)),
            'salary_period' => 'nullable|in:monthly,annual']);
        $template = $this->templates($r)->where('uuid', $data['template'])->first();
        abort_unless($template, 404);
        if ($template->kind === 'relieving' && ! $employee->separated_on) {
            throw ValidationException::withMessages(['template' => 'Record the employee separation date before issuing a relieving letter.']);
        }

        $user = $r->user();
        $values = [
            'employee_name' => $employee->person?->legal_name,
            'employee_number' => $employee->employee_number,
            'workspace_name' => $user->tenant->name,
            'joined_on' => $employee->joined_on?->toDateString(),
            'separated_on' => $employee->separated_on?->toDateString(),
            'issued_on' => now($user->tenant->settings['timezone'] ?? 'UTC')->toDateString(),
        ];
        if ($template->kind === 'salary_certificate') {
            abort_unless($this->canSeeSalary($r, $employee), 403);
            $salary = $r->validate([
                'salary_amount' => 'required|string|max:16',
                'salary_currency' => 'required|string|in:'.implode(',', array_keys(Money::SCALES)),
                'salary_period' => 'required|in:monthly,annual',
            ]);
            $scale = Money::scale($salary['salary_currency']);
            $salary['salary_amount'] = Money::format(Money::minor($salary['salary_amount'], $scale, 'salary_amount'), $scale);
            $values += $salary;
        }
        $body = preg_replace_callback('/{{\s*([^{}]+?)\s*}}/u', static function ($match) use ($values) {
            $value = $values[$match[1]] ?? null;
            if ($value === null || $value === '') {
                throw ValidationException::withMessages(['template' => 'Missing required letter value: '.$match[1].'.']);
            }

            return $value;
        }, $template->body);

        $id = (string) Str::uuid();
        $signatureRequested = $r->boolean('signature_requested');
        $recipient = $signatureRequested ? $employee->person?->user : null;
        DB::transaction(function () use ($id, $user, $employee, $template, $body, $signatureRequested, $recipient) {
            DB::table('hr_issued_letters')->insert(['tenant_id' => $user->tenant_id, 'employee_id' => $employee->id,
                'template_id' => $template->id, 'issued_by' => $user->id, 'uuid' => $id, 'encrypted_body' => Crypt::encryptString($body),
                'signature_requested' => $signatureRequested, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('hr_letter.issued', 'people', 'letter:'.$id, ['kind' => $template->kind, 'template_version' => $template->version, 'signature_requested' => $signatureRequested]);
            if ($recipient && (int) $recipient->tenant_id === (int) $user->tenant_id && $recipient->allows('people.view')) {
                app(NotificationService::class)->send(
                    $recipient->id,
                    'HR letter ready for signature',
                    'Review and sign the letter in your profile.',
                    'people.letter_signature',
                    '/people/'.$employee->uuid.'?section=documents&letters=1',
                );
            }
        });

        return response()->json(['uuid' => $id], 201);
    }

    public function acknowledge(Request $r, string $uuid, string $letter): array
    {
        $employee = $this->employee($r, $uuid);
        $user = $r->user();
        abort_unless($this->rec()->isSelf($user, $employee), 403);
        $r->validate(['acknowledged' => 'required|accepted']);

        return DB::transaction(function () use ($user, $employee, $letter) {
            $query = DB::table('hr_issued_letters')->where('tenant_id', $user->tenant_id)
                ->where('employee_id', $employee->id)->where('uuid', $letter);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->acknowledged_at !== null) {
                return ['acknowledged_at' => $row->acknowledged_at];
            }

            $at = now()->toDateTimeString();
            $query->update([
                'acknowledged_at' => $at,
                'acknowledged_by' => $user->id,
                'acknowledged_body_sha256' => hash('sha256', Crypt::decryptString($row->encrypted_body)),
            ]);
            Audit::record('hr_letter.acknowledged', 'people', 'letter:'.$letter);

            return ['acknowledged_at' => $at];
        }, 3);
    }

    public function sign(Request $r, string $uuid, string $letter): array
    {
        $employee = $this->employee($r, $uuid);
        $user = $r->user();
        abort_unless($this->rec()->isSelf($user, $employee), 403);
        $data = $r->validate(['signer_name' => 'required|string|max:255', 'consent' => 'required|accepted']);

        return DB::transaction(function () use ($user, $employee, $letter, $data) {
            $query = DB::table('hr_issued_letters')->where('tenant_id', $user->tenant_id)
                ->where('employee_id', $employee->id)->where('uuid', $letter);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->signature_requested, 409, 'A signature was not requested for this letter.');
            if ($row->signed_at !== null) {
                return ['signed_at' => $row->signed_at];
            }

            $name = $employee->person?->legal_name;
            if (! $name || mb_strtolower(trim($name)) !== mb_strtolower(trim($data['signer_name']))) {
                throw ValidationException::withMessages(['signer_name' => 'Enter your full name as shown in your employee record.']);
            }

            $at = now()->toDateTimeString();
            $query->update([
                'signed_at' => $at,
                'signed_by' => $user->id,
                'encrypted_signer_name' => Crypt::encryptString($name),
                'signed_body_sha256' => hash('sha256', Crypt::decryptString($row->encrypted_body)),
            ]);
            Audit::record('hr_letter.signed', 'people', 'letter:'.$letter, ['consent_version' => 1]);

            return ['signed_at' => $at];
        }, 3);
    }

    public function download(Request $r, string $uuid, string $letter)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->canRead($r, $employee), 403);
        $r->validate(['format' => 'nullable|in:text,print,pdf']);
        $row = $this->issued($r, $employee)->where('letters.uuid', $letter)->first([
            'letters.encrypted_body', 'letters.created_at', 'letters.signed_at', 'letters.encrypted_signer_name',
            'letters.signed_body_sha256', 'template.kind', 'template.title', 'template.version',
        ]);
        abort_unless($row, 404);
        Audit::record('hr_letter.downloaded', 'people', 'letter:'.$letter);
        $body = Crypt::decryptString($row->encrypted_body);
        $signer = $row->signed_at && hash_equals((string) $row->signed_body_sha256, hash('sha256', $body))
            ? Crypt::decryptString($row->encrypted_signer_name)
            : null;

        if ($r->input('format') === 'pdf') {
            $pdf = new \Mpdf\Mpdf([
                'format' => 'A4',
                'tempDir' => storage_path('framework/letter-pdf'),
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle($row->title);
            $pdf->WriteHTML(view('people.letter-pdf', ['letter' => $row, 'body' => $body, 'signer' => $signer])->render());

            return response($pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$row->kind.'-'.$letter.'.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($r->input('format') === 'print') {
            return response()->view('people.letter-print', ['letter' => $row, 'body' => $body, 'signer' => $signer], 200, [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            ]);
        }

        return response()->streamDownload(static fn () => print($body), $row->kind.'-'.$letter.'.txt', [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

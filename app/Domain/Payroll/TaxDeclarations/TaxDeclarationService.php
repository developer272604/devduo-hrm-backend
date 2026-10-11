<?php

namespace App\Domain\Payroll\TaxDeclarations;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\CountryPack;
use App\Domain\Payroll\Models\CountryPackVersion;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Models\TaxDeclarationProof;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pack-driven tax declarations. No country workflow is assumed: the published pack version's `declaration_schema` defines the fields
 * ([{key,label,type: decimal|string|enum,required,sensitive,options}]) and optional `regimes`. Without a schema a free key/value set of
 * decimal amounts is accepted. Sensitive values are encrypted at rest and masked in every response.
 */
final class TaxDeclarationService
{
    public function window(CountryPack $pack, string $taxYear): ?object
    {
        return DB::table('payroll_tax_declaration_windows')->where('tenant_id', Own::tid())->where('pack_id', $pack->id)->where('tax_year', $taxYear)->first();
    }

    public function openWindow(User $actor, array $d): object
    {
        $pack = CountryPack::where('uuid', $d['pack_uuid'])->firstOrFail();
        $row = ['pack_id' => $pack->id, 'tax_year' => $d['tax_year'], 'opens_on' => $d['opens_on'], 'closes_on' => $d['closes_on'], 'proof_closes_on' => $d['proof_closes_on'] ?? null, 'locked' => (bool) ($d['locked'] ?? false), 'updated_at' => now()];
        $existing = $this->window($pack, $d['tax_year']);
        if ($existing) {
            DB::table('payroll_tax_declaration_windows')->where('id', $existing->id)->update($row);
        } else {
            DB::table('payroll_tax_declaration_windows')->insert($row + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => Own::tid(), 'created_by' => $actor->id, 'created_at' => now()]);
        }
        Audit::record('tax_declaration.window', 'payroll', $pack->uuid, ['tax_year' => $d['tax_year']]);

        return $this->window($pack, $d['tax_year']);
    }

    private function schema(CountryPack $pack, string $date): array
    {
        $v = CountryPackVersion::where('pack_id', $pack->id)->where('status', 'published')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('version')->first();

        return ['fields' => $v?->declaration_schema['fields'] ?? [], 'regimes' => $v?->declaration_schema['regimes'] ?? []];
    }

    public function schemaFor(string $packUuid, ?string $date = null): array
    {
        $pack = CountryPack::where('uuid', $packUuid)->firstOrFail();

        return $this->schema($pack, $date ?? now()->toDateString()) + ['pack_uuid' => $pack->uuid, 'country' => $pack->country];
    }

    /** Draft or re-submit the employee's own declaration (one row per employee, pack and tax year; each submission bumps the version). */
    public function submit(User $actor, array $d): TaxDeclaration
    {
        $employee = Own::employee($actor);
        $pack = CountryPack::where('uuid', $d['pack_uuid'])->firstOrFail();
        $win = $this->window($pack, $d['tax_year']);
        $today = now()->toDateString();
        if (! $win || $today < substr((string) $win->opens_on, 0, 10) || $today > substr((string) $win->closes_on, 0, 10) || $win->locked) {
            throw ValidationException::withMessages(['tax_year' => 'The declaration window for this tax year is not open.']);
        }
        $schema = $this->schema($pack, $today);
        $data = $this->validated($schema, $d['data']);
        if (($d['regime'] ?? null) && $schema['regimes'] && ! in_array($d['regime'], array_column($schema['regimes'], 'key'), true)) {
            throw ValidationException::withMessages(['regime' => 'Unknown regime for this country pack.']);
        }

        return DB::transaction(function () use ($employee, $pack, $d, $data, $win, $actor) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();
            $decl = TaxDeclaration::where('employee_id', $employee->id)->where('pack_id', $pack->id)->where('tax_year', $d['tax_year'])->first();
            if ($decl && ($decl->locked || $decl->status === 'approved')) {
                throw ValidationException::withMessages(['tax_year' => 'This declaration is approved and locked. Ask payroll to reopen it.']);
            }
            $fields = ['regime' => $d['regime'] ?? null, 'data' => $data, 'status' => 'submitted', 'submitted_at' => now(), 'window_id' => $win->id, 'effective_from' => $d['effective_from'] ?? substr((string) $win->opens_on, 0, 10)];
            if ($decl) {
                $decl->update($fields + ['version' => $decl->version + 1, 'review_note' => null]);
            } else {
                $decl = TaxDeclaration::create($fields + ['employee_id' => $employee->id, 'pack_id' => $pack->id, 'tax_year' => $d['tax_year']]);
            }
            Own::requestApproval($actor,'payroll.tax_declaration.review', $employee, 'Review tax declaration', 'taxdecl:'.$decl->uuid, '/payroll/tax-declarations');
            Audit::record('tax_declaration.submitted', 'payroll', $decl->uuid);

            return $decl;
        });
    }

    private function validated(array $schema, array $data): array
    {
        $out = [];
        if (! $schema['fields']) {
            foreach ($data as $k => $v) {
                if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/', (string) $k) || ! is_string($v) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $v)) {
                    throw ValidationException::withMessages(['data' => 'Declaration values must be non-negative decimal strings keyed by lowercase names.']);
                }
                $out[$k] = $v;
            }

            return $out;
        }
        $errors = [];
        foreach ($schema['fields'] as $f) {
            $v = $data[$f['key']] ?? null;
            if ($v === null || $v === '') {
                if ($f['required'] ?? false) {
                    $errors['data.'.$f['key']] = ($f['label'] ?? $f['key']).' is required.';
                }
                continue;
            }
            $type = $f['type'] ?? 'string';
            if ($type === 'decimal' && (! is_string($v) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $v))) {
                $errors['data.'.$f['key']] = ($f['label'] ?? $f['key']).' must be a decimal amount.';
            } elseif ($type === 'enum' && ! in_array($v, $f['options'] ?? [], true)) {
                $errors['data.'.$f['key']] = ($f['label'] ?? $f['key']).' has an unsupported value.';
            } elseif ($type === 'string' && (! is_string($v) || mb_strlen($v) > 200)) {
                $errors['data.'.$f['key']] = ($f['label'] ?? $f['key']).' must be text up to 200 characters.';
            }
            if (isset($f['pattern']) && is_string($v) && @preg_match('/'.str_replace('/', '\/', $f['pattern']).'/D', $v) !== 1) {
                $errors['data.'.$f['key']] = ($f['label'] ?? $f['key']).' has an invalid format.';
            }
            $out[$f['key']] = $v;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    public function addProof(User $actor, TaxDeclaration $decl, array $d, $file = null): TaxDeclarationProof
    {
        $employee = Own::employee($actor);
        abort_unless($decl->employee_id === $employee->id, 404);
        if (in_array($decl->status, ['approved'], true) || $decl->locked) {
            throw ValidationException::withMessages(['declaration' => 'This declaration is locked.']);
        }
        $win = DB::table('payroll_tax_declaration_windows')->where('id', $decl->window_id)->first();
        $limit = $win ? substr((string) ($win->proof_closes_on ?? $win->closes_on), 0, 10) : null;
        if ($limit && now()->toDateString() > $limit) {
            throw ValidationException::withMessages(['declaration' => 'The proof submission window has closed.']);
        }
        $path = $file ? $file->store('tax-proofs/'.Own::tid(), 'payroll_files') : null;

        return TaxDeclarationProof::create(['declaration_id' => $decl->id, 'category' => $d['category'], 'declared_amount' => $d['declared_amount'] ?? null, 'file_path' => $path, 'status' => 'pending',
            'original_name' => $file?->getClientOriginalName(), 'mime' => $file?->getClientMimeType(), 'size' => $file?->getSize()]);
    }

    public function decide(User $actor, string $uuid, string $decision, ?string $note, array $proofs = []): TaxDeclaration
    {
        abort_unless($actor->allows('payroll.tax_declaration.review'), 403);
        $probe = TaxDeclaration::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $probe->employee_id, 'payroll.tax_declaration.review'), 404);

        return DB::transaction(function () use ($actor, $probe, $decision, $note, $proofs) {
            $d = TaxDeclaration::whereKey($probe->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($d->status, ['submitted', 'under_review'], true), 409, 'This declaration is not waiting for review.');
            if ($decision === 'rejected' && ! $note) {
                throw ValidationException::withMessages(['review_note' => 'Please tell the employee why the declaration was rejected.']);
            }
            foreach ($proofs as $p) {
                $proof = TaxDeclarationProof::where('declaration_id', $d->id)->where('uuid', $p['uuid'])->firstOrFail();
                $accepted = $p['status'] === 'accepted' ? ($p['accepted_amount'] ?? $proof->declared_amount) : null;
                if ($accepted !== null && $proof->declared_amount !== null && Decimal::cmp($accepted, $proof->declared_amount) > 0) {
                    throw ValidationException::withMessages(['proofs' => 'Accepted amount cannot exceed the declared amount.']);
                }
                $proof->update(['status' => $p['status'], 'accepted_amount' => $accepted, 'note' => $p['note'] ?? null, 'reviewed_by' => $actor->id]);
            }
            $d->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'locked' => $decision === 'approved']);
            app(TaskService::class)->completeBySource('payroll', 'taxdecl:'.$d->uuid);
            Audit::record('tax_declaration.'.$decision, 'payroll', $d->uuid);

            return $d;
        });
    }

    /** Masked representation: sensitive schema fields never leave in clear text. */
    public function present(TaxDeclaration $d, bool $withProofs = true): array
    {
        $pack = CountryPack::find($d->pack_id);
        $fields = collect($this->schema($pack, now()->toDateString())['fields'])->keyBy('key');
        $data = [];
        foreach ($d->data ?? [] as $k => $v) {
            $data[$k] = ($fields[$k]['sensitive'] ?? false) ? Own::mask((string) $v) : $v;
        }
        $out = ['uuid' => $d->uuid, 'pack_uuid' => $pack->uuid, 'tax_year' => $d->tax_year, 'regime' => $d->regime, 'status' => $d->status, 'version' => $d->version, 'locked' => (bool) $d->locked, 'data' => $data,
            'submitted_at' => $d->submitted_at, 'reviewed_at' => $d->reviewed_at?->toIso8601String(), 'review_note' => $d->review_note];
        if ($withProofs) {
            $out['proofs'] = $d->proofs()->get()->map(fn ($p) => ['uuid' => $p->uuid, 'category' => $p->category, 'declared_amount' => $p->declared_amount, 'accepted_amount' => $p->accepted_amount, 'status' => $p->status, 'note' => $p->note, 'file_name' => $p->original_name, 'has_file' => (bool) $p->file_path])->all();
        }

        return $out;
    }
}

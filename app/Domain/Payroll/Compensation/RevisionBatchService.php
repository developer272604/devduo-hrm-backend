<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\CompensationRevision;
use App\Domain\Payroll\Models\CompensationRevisionBatch;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Support\RequestFingerprint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Batch intake for employee-specific revision payloads; each resulting revision still requires an independent checker. */
final class RevisionBatchService
{
    public function __construct(private RevisionService $revisions) {}

    public function preview(User $actor, array $data): array
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);
        $rows = [];
        foreach ($data['employees'] as $item) {
            try {
                $rows[] = ['employee_uuid' => $item['employee_uuid'], 'status' => 'valid', 'impact' => $this->revisions->preview($actor, $this->revisionData($data, $item))];
            } catch (\Throwable $e) {
                $rows[] = ['employee_uuid' => $item['employee_uuid'], 'status' => 'error', 'message' => $this->message($e)];
            }
        }

        return ['persisted' => false, 'employees_total' => count($rows), 'employees_ok' => count(array_filter($rows, fn ($r) => $r['status'] === 'valid')),
            'employees_failed' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')), 'rows' => $rows];
    }

    public function submit(User $actor, array $data): CompensationRevisionBatch
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);

        return DB::transaction(function () use ($actor, $data) {
            $hash = RequestFingerprint::make($data);
            $existing = CompensationRevisionBatch::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id && hash_equals((string) ($existing->params['request_hash'] ?? ''), $hash), 409, 'Request key already belongs to another operation.');

                return $existing;
            }

            $preview = $this->preview($actor, $data);
            if ($preview['employees_ok'] === 0) {
                throw ValidationException::withMessages(['employees' => 'No employee has a valid compensation revision. Correct the rows and preview again.']);
            }
            $batch = CompensationRevisionBatch::create(['name' => $data['name'], 'mode' => 'import', 'status' => 'submitted', 'effective_date' => $data['effective_date'],
                'params' => ['type' => $data['type'], 'reason' => $data['reason'], 'request_hash' => $hash], 'preview' => $preview, 'employees_total' => $preview['employees_total'], 'employees_ok' => $preview['employees_ok'],
                'employees_failed' => $preview['employees_failed'], 'errors' => array_values(array_filter($preview['rows'], fn ($r) => $r['status'] === 'error')), 'created_by' => $actor->id, 'idempotency_key' => $data['idempotency_key']]);

            foreach ($data['employees'] as $item) {
                $row = collect($preview['rows'])->firstWhere('employee_uuid', $item['employee_uuid']);
                if ($row['status'] !== 'valid') {
                    continue;
                }
                try {
                    $revision = $this->revisions->propose($actor, $this->revisionData($data, $item) + ['idempotency_key' => (string) Str::uuid()]);
                    $revision->update(['batch_id' => $batch->id]);
                } catch (\Throwable $e) {
                    $errors = $batch->errors ?? [];
                    $errors[] = ['employee_uuid' => $item['employee_uuid'], 'message' => $this->message($e)];
                    $batch->update(['employees_ok' => max(0, $batch->employees_ok - 1), 'employees_failed' => $batch->employees_failed + 1, 'errors' => $errors]);
                }
            }
            $batch->refresh();
            if ($batch->revisions()->count() === 0) {
                throw ValidationException::withMessages(['employees' => 'No compensation revision could be submitted. Correct the errors and try again.']);
            }
            Audit::record('compensation.revision_batch_submitted', 'payroll', $batch->uuid, ['total' => $batch->employees_total, 'submitted' => $batch->revisions()->count(), 'failed' => $batch->employees_failed]);

            return $batch;
        });
    }

    public function present(CompensationRevisionBatch $batch): array
    {
        return ['uuid' => $batch->uuid, 'name' => $batch->name, 'status' => $batch->status, 'effective_date' => substr((string) $batch->effective_date, 0, 10),
            'employees' => ['total' => $batch->employees_total, 'submitted' => $batch->revisions()->count(), 'failed' => $batch->employees_failed],
            'revisions' => $batch->revisions()->orderBy('id')->get()->map(fn (CompensationRevision $r) => ['uuid' => $r->uuid, 'employee_uuid' => $r->employee?->uuid, 'status' => $r->status])->all(),
            'errors' => collect($batch->errors ?? [])->map(fn ($error, $index) => ['row' => $index + 1, 'message' => $error['message'] ?? 'The row could not be submitted.'])->all()];
    }

    public function index(User $actor): array
    {
        abort_unless($actor->allows('payroll.compensation.view'), 403);
        $employeeIds = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.view')->select('employees.id');

        return CompensationRevisionBatch::query()
            ->whereHas('revisions')
            ->whereDoesntHave('revisions', fn ($q) => $q->whereNotIn('employee_id', $employeeIds))
            ->latest('id')->limit(50)->get()->map(fn ($batch) => $this->present($batch))->all();
    }

    private function revisionData(array $batch, array $employee): array
    {
        return ['employee_uuid' => $employee['employee_uuid'], 'type' => $batch['type'], 'effective_date' => $batch['effective_date'], 'reason' => $batch['reason'],
            'comments' => $employee['comments'] ?? null, 'letter_reference' => $employee['letter_reference'] ?? null, 'structure_uuid' => $employee['structure_uuid'] ?? null, 'lines' => $employee['lines']];
    }

    private function message(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->first() ?? 'The row is invalid.';
        }
        if ($e instanceof HttpException) {
            return $e->getMessage();
        }
        if ($e instanceof ModelNotFoundException) {
            return 'Employee is unavailable or outside your payroll scope.';
        }

        report($e);

        return 'The revision could not be submitted due to an unexpected error.';
    }
}

<?php

namespace App\Domain\Attendance\Requests\Bulk;

use App\Domain\Attendance\Bulk\BulkAttendanceAction;
use App\Domain\Attendance\Requests\RegularizationService;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Bulk regularize: approved, single-step regularizations for employee-days (the confirmed bulk run is the approval). */
final class RegularizeBulkAction implements BulkAttendanceAction
{
    public function key(): string
    {
        return 'regularize';
    }

    public function label(): string
    {
        return 'Regularize attendance';
    }

    public function permission(): string
    {
        return 'attendance.adjust';
    }

    public function targetKind(): string
    {
        return 'employee_date';
    }

    public function validate(array $params): array
    {
        $type = $params['type'] ?? 'incorrect';
        $reason = trim((string) ($params['reason'] ?? ''));
        $errors = [];
        if (! isset(RegularizationService::TYPES[$type])) {
            $errors['type'] = 'Choose a regularization type.';
        }
        if ($reason === '') {
            $errors['reason'] = 'A reason is required.';
        }
        if (empty($params['requested_in']) && empty($params['requested_out'])) {
            $errors['requested_in'] = 'Enter the in time, the out time, or both.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['type' => $type, 'reason' => $reason, 'requested_in' => $params['requested_in'] ?? null, 'requested_out' => $params['requested_out'] ?? null];
    }

    public function preview(User $actor, array $target, array $params): array
    {
        $employee = Employee::query()->find($target['employee_id'] ?? 0);
        if (! $employee) {
            return ['status' => 'error', 'message' => 'Employee not found.'];
        }
        try {
            app(RegularizationService::class)->dryRun($employee, $this->data($target, $params));
        } catch (ValidationException $e) {
            return ['status' => 'skipped', 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        return ['status' => 'ok', 'message' => "Will record an approved regularization for {$target['date']}."];
    }

    public function apply(User $actor, array $target, array $params): array
    {
        $employee = Employee::query()->findOrFail($target['employee_id']);
        app(RegularizationService::class)->createApproved($actor, $employee, $this->data($target, $params), (string) ($params['_batch'] ?? 'bulk'));

        return ['status' => 'ok', 'message' => "Regularized {$target['date']}."];
    }

    private function data(array $target, array $params): array
    {
        return ['date' => $target['date'], 'type' => $params['type'], 'reason' => $params['reason'], 'requested_in' => $params['requested_in'], 'requested_out' => $params['requested_out']];
    }
}

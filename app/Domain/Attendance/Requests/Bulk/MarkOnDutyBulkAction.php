<?php

namespace App\Domain\Attendance\Requests\Bulk;

use App\Domain\Attendance\Bulk\BulkAttendanceAction;
use App\Domain\Attendance\Requests\OnDutyService;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Bulk mark on duty: approved full-day on-duty records for employee-days. */
final class MarkOnDutyBulkAction implements BulkAttendanceAction
{
    public function key(): string
    {
        return 'mark_on_duty';
    }

    public function label(): string
    {
        return 'Mark on duty';
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
        $type = $params['type'] ?? 'official_travel';
        $reason = trim((string) ($params['reason'] ?? ''));
        $errors = [];
        if (! isset(OnDutyService::TYPES[$type])) {
            $errors['type'] = 'Choose an on-duty type.';
        }
        if ($reason === '') {
            $errors['reason'] = 'A reason is required.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['type' => $type, 'reason' => $reason, 'location_text' => $params['location_text'] ?? null];
    }

    public function preview(User $actor, array $target, array $params): array
    {
        $employee = Employee::query()->find($target['employee_id'] ?? 0);
        if (! $employee) {
            return ['status' => 'error', 'message' => 'Employee not found.'];
        }
        try {
            app(OnDutyService::class)->validateDay($employee, $params['type'], $target['date'], $params['reason']);
        } catch (ValidationException $e) {
            return ['status' => 'skipped', 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        return ['status' => 'ok', 'message' => "Will mark {$target['date']} as ".strtolower(OnDutyService::TYPES[$params['type']]).'.'];
    }

    public function apply(User $actor, array $target, array $params): array
    {
        $employee = Employee::query()->findOrFail($target['employee_id']);
        app(OnDutyService::class)->createApproved($actor, $employee, ['type' => $params['type'], 'date' => $target['date'], 'reason' => $params['reason'], 'location_text' => $params['location_text'] ?? null], (string) ($params['_batch'] ?? 'bulk'));

        return ['status' => 'ok', 'message' => "Marked {$target['date']} on duty."];
    }
}

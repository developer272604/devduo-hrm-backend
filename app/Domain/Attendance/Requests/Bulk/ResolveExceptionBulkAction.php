<?php

namespace App\Domain\Attendance\Requests\Bulk;

use App\Domain\Attendance\Bulk\BulkAttendanceAction;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Requests\RequestSupport;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Bulk resolve exception: marks open/acknowledged exceptions resolved with a resolution note. */
final class ResolveExceptionBulkAction implements BulkAttendanceAction
{
    public function key(): string
    {
        return 'resolve_exception';
    }

    public function label(): string
    {
        return 'Resolve exceptions';
    }

    public function permission(): string
    {
        return 'attendance.manage';
    }

    public function targetKind(): string
    {
        return 'exception';
    }

    public function validate(array $params): array
    {
        $resolution = trim((string) ($params['resolution'] ?? ''));
        if ($resolution === '' || mb_strlen($resolution) > 1000) {
            throw ValidationException::withMessages(['resolution' => 'Explain how the exceptions were resolved (up to 1000 characters).']);
        }

        return ['resolution' => $resolution];
    }

    public function preview(User $actor, array $target, array $params): array
    {
        $exception = AttendanceException::query()->find($target['exception_id'] ?? 0);
        if (! $exception) {
            return ['status' => 'error', 'message' => 'Exception not found.'];
        }
        if (! in_array($exception->status, AttendanceException::OPEN_STATES, true)) {
            return ['status' => 'skipped', 'message' => "Already {$exception->status}."];
        }
        try {
            app(RequestSupport::class)->assertUnlocked($exception->employee_id, $exception->attendance_date);
        } catch (HttpException $e) {
            return ['status' => 'skipped', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'Will be marked resolved.'];
    }

    public function apply(User $actor, array $target, array $params): array
    {
        $exception = AttendanceException::query()->findOrFail($target['exception_id']);
        $exception->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor->id, 'resolution' => $params['resolution']]);
        app(RequestSupport::class)->recalculate($exception->employee_id, $exception->attendance_date, 'manual', $actor->id);

        return ['status' => 'ok', 'message' => 'Resolved.'];
    }
}

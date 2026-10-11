<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Human lifecycle of exceptions: acknowledge / resolve / dismiss / reopen. Recalculation never reopens resolved or dismissed ones. */
final class ExceptionService
{
    public function transition(AttendanceException $e, string $action, User $actor, ?string $text = null): AttendanceException
    {
        $now = Utc::str();
        $from = $e->status;
        switch ($action) {
            case 'acknowledge':
                $this->need($e, ['open']);
                $e->status = 'acknowledged';
                break;
            case 'resolve':
                $this->need($e, ['open', 'acknowledged']);
                if (blank($text)) {
                    throw ValidationException::withMessages(['resolution' => 'Say how this was resolved.']);
                }
                $e->fill(['status' => 'resolved', 'resolution' => $text, 'resolved_at' => $now, 'resolved_by' => $actor->id]);
                break;
            case 'dismiss':
                $this->need($e, ['open', 'acknowledged']);
                if (blank($text)) {
                    throw ValidationException::withMessages(['resolution' => 'Say why this does not need action.']);
                }
                $e->fill(['status' => 'dismissed', 'resolution' => $text, 'resolved_at' => $now, 'resolved_by' => $actor->id]);
                break;
            case 'reopen':
                $this->need($e, ['resolved', 'dismissed', 'auto_resolved', 'acknowledged']);
                $e->fill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null]);
                break;
            default:
                throw ValidationException::withMessages(['action' => 'Unknown action.']);
        }
        $e->updated_at = $now;
        $e->save();
        $open = AttendanceException::query()->where('employee_id', $e->employee_id)->where('attendance_date', $e->attendance_date)->whereIn('status', AttendanceException::OPEN_STATES)->count();
        \DB::table('attendance_days')->where('tenant_id', $e->tenant_id)->where('employee_id', $e->employee_id)->where('attendance_date', $e->attendance_date)->update(['open_exceptions' => $open]);
        Audit::record($action, 'attendance', 'attendance_exception:'.$e->uuid, ['from' => $from, 'to' => $e->status]);

        return $e;
    }

    private function need(AttendanceException $e, array $states): void
    {
        if (! in_array($e->status, $states, true)) {
            throw ValidationException::withMessages(['status' => 'This exception is already '.str_replace('_', ' ', $e->status).'.']);
        }
    }
}

<?php

namespace App\Domain\Attendance\Boot;

use App\Domain\Attendance\Bulk\BulkAttendanceActions;
use App\Domain\Attendance\Requests\Bulk\MarkOnDutyBulkAction;
use App\Domain\Attendance\Requests\Bulk\RegularizeBulkAction;
use App\Domain\Attendance\Requests\Bulk\ResolveExceptionBulkAction;
use App\Domain\Attendance\Requests\RequestSupport;
use App\Domain\People\Insights\DomainEventTypes;
use Illuminate\Contracts\Foundation\Application;

/** Private attachment disk, bulk actions and timeline labels for attendance requests. */
final class RequestsBoot
{
    public static function register(Application $app): void
    {
    }

    public static function boot(Application $app): void
    {
        $config = $app['config'];
        if (! $config->has('filesystems.disks.'.RequestSupport::DISK)) {
            $config->set('filesystems.disks.'.RequestSupport::DISK, ['driver' => 'local', 'root' => storage_path('app/private/attendance-requests'), 'visibility' => 'private', 'throw' => true, 'report' => false]);
        }
        BulkAttendanceActions::register(new RegularizeBulkAction());
        BulkAttendanceActions::register(new MarkOnDutyBulkAction());
        BulkAttendanceActions::register(new ResolveExceptionBulkAction());
        foreach (['RegularizationSubmitted' => 'Regularization submitted', 'RegularizationApproved' => 'Regularization approved', 'RegularizationRejected' => 'Regularization rejected',
            'OvertimeRequested' => 'Overtime requested', 'OvertimeApproved' => 'Overtime approved', 'OvertimeRejected' => 'Overtime rejected', 'OnDutyRequested' => 'On duty requested',
            'OnDutyApproved' => 'On duty approved', 'OnDutyRejected' => 'On duty rejected', 'AttendanceAdjusted' => 'Attendance adjusted'] as $type => $label) {
            DomainEventTypes::register($type, 'requests', 'clock', $label);
        }
    }
}

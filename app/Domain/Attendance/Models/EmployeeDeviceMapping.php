<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantRecord;

class EmployeeDeviceMapping extends TenantRecord
{
    protected $table = 'employee_device_mappings';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }
}

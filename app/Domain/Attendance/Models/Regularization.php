<?php

namespace App\Domain\Attendance\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Employee request to correct a day's punches. Dates are plain Y-m-d strings; requested_in/out are UTC strings (never cast, so the app timezone cannot shift them). */
class Regularization extends TenantRecord
{
    use HasPublicId;

    protected $table = 'attendance_regularizations';

    protected function casts(): array
    {
        return ['current_snapshot' => 'array', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function actions()
    {
        return $this->hasMany(RegularizationAction::class, 'regularization_id')->orderBy('id');
    }
}

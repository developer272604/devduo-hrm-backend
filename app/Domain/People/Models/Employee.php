<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends TenantRecord
{
    use HasPublicId, SoftDeletes;

    /** Lifecycle states that count as working for data-scope, directory and attendance purposes. */
    public const WORKING = ['active', 'probation', 'notice_period', 'suspended', 'long_leave'];

    public const STATUSES = ['preboarding', 'active', 'probation', 'notice_period', 'suspended', 'long_leave', 'separated', 'inactive', 'archived'];

    protected function casts(): array
    {
        return ['original_joining_date' => 'date:Y-m-d', 'joined_on' => 'date:Y-m-d', 'employment_start_date' => 'date:Y-m-d', 'probation_applicable' => 'boolean',
            'probation_start' => 'date:Y-m-d', 'probation_end' => 'date:Y-m-d', 'confirmation_date' => 'date:Y-m-d', 'contract_start' => 'date:Y-m-d', 'contract_end' => 'date:Y-m-d',
            'retirement_date' => 'date:Y-m-d', 'separated_on' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class)->orderBy('effective_from');
    }

    public function relationships()
    {
        return $this->hasMany(ReportingRelationship::class);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, self::WORKING, true);
    }

    /** Current assignment on a date (default: today in the employee's own timezone). */
    public function assignmentOn(?string $date = null): ?Assignment
    {
        $date ??= now($this->timezone)->toDateString();

        return Assignment::query()->where('employee_id', $this->id)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
    }
}

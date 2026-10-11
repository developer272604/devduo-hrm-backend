<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveAccount extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_accounts';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function entries()
    {
        return $this->hasMany(LeaveLedgerEntry::class, 'account_id');
    }
}

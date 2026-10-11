<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use LogicException;

/**
 * One immutable ledger transaction. `units` is a SIGNED decimal string (4 dp): credits positive, debits negative.
 * Corrections are new rows (reversal / adjustment). Updates and deletes are refused at the model layer.
 * Do not cast `units` to float: always use App\Domain\Leave\Support\Units (bcmath) on it.
 */
class LeaveLedgerEntry extends TenantRecord
{
    use HasPublicId;

    public $timestamps = false;

    protected $table = 'leave_ledger_entries';

    public const TYPES = ['opening', 'accrual', 'reservation', 'reservation_release', 'consumption', 'settlement', 'reversal', 'adjustment', 'carry_forward_out', 'carry_forward_in', 'expiry', 'comp_off_credit', 'encashment', 'transfer_out', 'transfer_in', 'donation_out', 'donation_in'];

    public const BUCKETS = ['opening', 'accrued', 'carried', 'adjusted', 'consumed', 'pending', 'scheduled', 'expired', 'encashed'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new LogicException('Leave ledger entries are immutable. Post a reversal or adjustment instead.'));
        static::deleting(fn () => throw new LogicException('Leave ledger entries cannot be deleted. Post a reversal instead.'));
    }

    public function account()
    {
        return $this->belongsTo(LeaveAccount::class, 'account_id');
    }

    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}

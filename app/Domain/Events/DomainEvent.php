<?php

namespace App\Domain\Events;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

/** Append-only business event. It feeds the Employee Timeline, Organization Memory and future automation. */
class DomainEvent extends TenantRecord
{
    use HasPublicId;

    protected $table = 'domain_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'effective_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        parent::booted();
        $immutable = fn () => throw new \LogicException('Domain events are append-only.');
        static::updating($immutable);
        static::deleting($immutable);
    }
}

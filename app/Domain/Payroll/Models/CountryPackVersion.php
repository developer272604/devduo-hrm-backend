<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;
use LogicException;

class CountryPackVersion extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_country_pack_versions';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'calculation_rules' => 'array', 'validation_rules' => 'array', 'identifiers' => 'array', 'declaration_schema' => 'array', 'published_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    /** Once published a country pack version is immutable (statutory history must be reproducible). Only retirement bookkeeping may change. */
    public const MUTABLE_WHEN_PUBLISHED = ['status', 'effective_to', 'verified_at', 'verified_by', 'verification_note', 'updated_at'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $m) {
            if ($m->getOriginal('status') === 'draft') {
                return;
            }
            $illegal = array_diff(array_keys($m->getDirty()), self::MUTABLE_WHEN_PUBLISHED);
            if ($illegal) {
                throw new LogicException('A published country pack version is immutable (attempted to change: '.implode(', ', $illegal).'). Publish a new version.');
            }
        });
        static::deleting(fn (self $m) => $m->status === 'draft' ? null : throw new LogicException('Published country pack versions cannot be deleted.'));
    }

    public function pack()
    {
        return $this->belongsTo(CountryPack::class, 'pack_id');
    }
}

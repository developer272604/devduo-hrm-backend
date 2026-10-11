<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class TenantRecord extends Model
{
    protected $guarded = ['id', 'tenant_id'];

    /**
     * Laravel caches guardable columns per class, not per table. Table-switching records
     * would silently drop attributes of every table after the first, so only the explicit
     * $guarded list applies.
     */
    protected function isGuardableColumn($key)
    {
        return true;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            $q->where($q->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->id ?? -1);
        });
        static::creating(function (Model $m) {
            $id = app(TenantContext::class)->id;
            if (! $id) {
                throw new \LogicException('Tenant context is required.');
            } $m->tenant_id = $id;
        });
    }
}

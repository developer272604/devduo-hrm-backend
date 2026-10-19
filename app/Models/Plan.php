<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A sellable monthly plan: price plus the modules it unlocks. Platform-wide, managed by the HRMS owner. */
class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'active' => 'boolean', 'monthly_price' => 'float'];
    }

    /** Modules every workspace always keeps, whatever the plan. */
    public const ALWAYS = ['work', 'notifications'];

    public function allModules(): array
    {
        return array_values(array_unique(array_merge(self::ALWAYS, $this->modules ?? [])));
    }
}

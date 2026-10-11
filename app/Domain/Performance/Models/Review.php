<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Review extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_reviews';

    public const FINAL = ['finalized', 'acknowledged'];

    /** Finalized reviews are immutable except for the employee acknowledgement fields. */
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $m) {
            if (! in_array($m->getOriginal('status'), self::FINAL, true)) return;
            $changed = array_keys($m->getDirty());
            $allowed = ['status', 'acknowledged_at', 'employee_comment', 'updated_at'];
            if (array_diff($changed, $allowed) || ($m->isDirty('status') && ! in_array($m->status, self::FINAL, true))) {
                throw new \LogicException('A finalized review cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return ['computed_score' => 'decimal:3', 'final_score' => 'decimal:3', 'trace' => 'array', 'snapshot' => 'array', 'finalized_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }
    public function cycle() { return $this->belongsTo(Cycle::class, 'cycle_id'); }
    public function responses() { return $this->hasMany(Response::class, 'review_id'); }
}

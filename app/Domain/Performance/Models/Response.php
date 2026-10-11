<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Response extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_review_responses';


    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $m) {
            if (Review::query()->whereKey($m->review_id)->whereIn('status', Review::FINAL)->exists()) throw new \LogicException('A finalized review cannot be changed.');
        });
    }

    protected function casts(): array
    {
        return ['score' => 'decimal:3', 'due_on' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
    public function items() { return $this->hasMany(Item::class, 'response_id'); }
}

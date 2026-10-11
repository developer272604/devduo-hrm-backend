<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Item extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_review_items';


    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            $rid = Response::query()->whereKey($m->response_id)->value('review_id');
            if (Review::query()->whereKey($rid)->whereIn('status', Review::FINAL)->exists()) throw new \LogicException('A finalized review cannot be changed.');
        });
    }

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'rating' => 'decimal:2'];
    }
}

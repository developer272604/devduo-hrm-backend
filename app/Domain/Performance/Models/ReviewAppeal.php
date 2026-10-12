<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class ReviewAppeal extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_review_appeals';

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'decision_note' => 'encrypted', 'decided_at' => 'datetime'];
    }

    public function review() { return $this->belongsTo(Review::class, 'review_id'); }
}

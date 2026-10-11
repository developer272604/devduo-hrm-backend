<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessReviewItem extends TenantRecord
{
    use HasPublicId;

    protected $table = 'access_review_items';

    protected function casts(): array
    {
        return ['role_snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

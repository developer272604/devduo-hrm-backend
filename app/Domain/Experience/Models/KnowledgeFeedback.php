<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class KnowledgeFeedback extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_knowledge_feedback';
    protected function casts(): array { return ['helpful' => 'boolean']; }
}

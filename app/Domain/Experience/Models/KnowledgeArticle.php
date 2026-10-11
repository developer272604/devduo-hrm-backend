<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class KnowledgeArticle extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_knowledge_articles';
    protected function casts(): array { return ['effective_from' => 'date:Y-m-d', 'effective_until' => 'date:Y-m-d']; }
}

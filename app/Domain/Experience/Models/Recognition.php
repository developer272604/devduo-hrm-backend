<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Recognition extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_recognitions';
    protected function casts(): array { return ['value_tags' => 'array', 'moderated_at' => 'datetime']; }
}

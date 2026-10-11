<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class DocumentType extends TenantRecord
{
    protected $table = 'document_types';

    public const CATEGORIES = ['identity', 'employment', 'education', 'experience', 'contract', 'certificate', 'policy', 'visa', 'work_permit', 'tax', 'bank', 'other'];

    protected function casts(): array
    {
        return ['requires_expiry' => 'boolean', 'mandatory' => 'boolean', 'active' => 'boolean', 'mandatory_for' => 'array'];
    }
}

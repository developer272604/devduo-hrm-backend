<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocument extends TenantRecord
{
    use HasPublicId, SoftDeletes;

    protected $table = 'employee_documents';

    public const VERIFICATION = ['pending', 'verified', 'rejected'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'verified_at' => 'datetime', 'employee_visible' => 'boolean', 'tags' => 'array'];
    }

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function versions()
    {
        return $this->hasMany(EmployeeDocumentVersion::class, 'document_id')->orderByDesc('version');
    }

    public function currentVersion()
    {
        return $this->belongsTo(EmployeeDocumentVersion::class, 'current_version_id');
    }
}

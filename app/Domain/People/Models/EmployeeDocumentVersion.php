<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Append-only file version. Rows are never updated or deleted. */
class EmployeeDocumentVersion extends TenantRecord
{
    protected $table = 'employee_document_versions';

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Document versions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Document versions are append-only.'));
    }
}

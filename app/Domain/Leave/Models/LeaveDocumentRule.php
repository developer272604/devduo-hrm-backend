<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveDocumentRule extends TenantRecord
{
    protected $table = 'leave_document_rules';
}

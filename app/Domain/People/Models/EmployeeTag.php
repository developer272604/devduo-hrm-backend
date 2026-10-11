<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class EmployeeTag extends TenantRecord
{
    protected $table = 'employee_tags';

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }
}

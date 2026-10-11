<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Note about an employment. Read access is decided by NoteService::visibleTo, never by the caller. */
class EmployeeNote extends TenantRecord
{
    use HasPublicId, SoftDeletes;

    public const VISIBILITIES = ['general_hr', 'manager', 'private_hr', 'employee_visible'];

    protected $table = 'employee_notes';

    protected function casts(): array
    {
        return ['mentions' => 'array', 'attachments' => 'array'];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

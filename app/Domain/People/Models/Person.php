<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends TenantRecord
{
    use HasPublicId, SoftDeletes;

    protected $table = 'people';

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'legal_hold' => 'boolean', 'retention_until' => 'date:Y-m-d', 'anonymized_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employments()
    {
        return $this->hasMany(Employee::class)->orderBy('employment_sequence');
    }

    /** Composes names from parts so display_name and legal_name never drift from their components. */
    public static function composeNames(array $data): array
    {
        $legal = trim(implode(' ', array_filter([$data['legal_first_name'] ?? '', $data['legal_middle_name'] ?? '', $data['legal_last_name'] ?? ''])));
        $data['legal_name'] = $legal;
        $data['display_name'] = trim($data['display_name'] ?? '') ?: (trim($data['preferred_name'] ?? '') ?: $legal);

        return $data;
    }
}

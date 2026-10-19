<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = ['id'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'setup_draft' => 'array', 'setup_completed_at' => 'datetime', 'trial_started_at' => 'datetime', 'trial_ends_at' => 'datetime', 'approved_at' => 'datetime', 'subscription_ends_at' => 'datetime', 'status_changed_at' => 'datetime', 'is_platform' => 'boolean'];
    }
}

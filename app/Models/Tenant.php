<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'setup_draft' => 'array', 'setup_completed_at' => 'datetime'];
    }
}

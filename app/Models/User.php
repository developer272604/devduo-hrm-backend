<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'active'];

    protected $hidden = ['password', 'remember_token', 'session_version', 'failed_logins', 'locked_until'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'email_verified_at' => 'datetime', 'session_version' => 'integer', 'password_changed_at' => 'datetime', 'locked_until' => 'datetime'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissionKeys(): array
    {
        return $this->roles()->with('permissions')->get()->flatMap(fn ($r) => $r->permissions->pluck('key'))->unique()->values()->all();
    }

    public function allows(string $p): bool
    {
        return $this->active && in_array($p, $this->permissionKeys(), true);
    }

    public function hasTenantScope(): bool
    {
        return $this->roles()->where('data_scope', 'tenant')->exists();
    }
}

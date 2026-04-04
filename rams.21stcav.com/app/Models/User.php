<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',       // 'admin' | 'user'
        'is_active',  // false = suspended (cannot log in)
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Role helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true when this user has the 'admin' role.
     * Used in policies, middleware and Blade views:
     *   @if(auth()->user()->isAdmin()) … @endif
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function ramsDocuments(): HasMany
    {
        return $this->hasMany(RamsDocument::class);
    }

    public function omManuals(): HasMany
    {
        return $this->hasMany(OmManual::class);
    }
}

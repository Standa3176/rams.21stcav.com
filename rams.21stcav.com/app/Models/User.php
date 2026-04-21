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

    public function worksheets(): HasMany
    {
        return $this->hasMany(Worksheet::class);
    }

    /**
     * Time entries this user has clocked on any project (Phase 15).
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Retro-edit audit rows created by this user (Phase 15 D-04 / D-07).
     * Non-default FK: audits point at the editor, not the entry's owner.
     */
    public function timeEntryAudits(): HasMany
    {
        return $this->hasMany(TimeEntryAudit::class, 'edited_by_user_id');
    }
}

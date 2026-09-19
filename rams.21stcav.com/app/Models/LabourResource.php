<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 44 Plan 01 Task 1 — one row per person (44-CONTEXT.md D-01), never
 * hard-deleted (D-02).
 *
 * @see database/migrations/2026_09_19_120000_create_labour_resources_table.php
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-01, D-02, D-04)
 *
 * @property int $id
 * @property string $name
 * @property ?string $email
 * @property ?string $phone
 * @property array $roles
 * @property ?int $user_id
 * @property bool $is_active
 */
class LabourResource extends Model
{
    use HasFactory;

    // ── Role enum (D-01: "at least engineer, programmer, other") ───────────

    public const ROLE_ENGINEER = 'engineer';

    public const ROLE_PROGRAMMER = 'programmer';

    public const ROLE_OTHER = 'other';

    public const ROLES = [
        self::ROLE_ENGINEER,
        self::ROLE_PROGRAMMER,
        self::ROLE_OTHER,
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'roles',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'roles'     => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Role helpers ─────────────────────────────────────────────────────────

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? [], true);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Privacy boundary (D-04) ──────────────────────────────────────────────

    /**
     * "A client must never be given an engineer's phone or email. Name
     * only." — the user, verbatim, 2026-09-19 (44-CONTEXT.md D-04).
     *
     * This is the ONE sanctioned shape any client-facing code should reach
     * for instead of serializing this model directly. It structurally omits
     * `email`, `phone`, `roles` and `user_id` — a future caller cannot get
     * the "safe" shape wrong by forgetting to strip a field, because there
     * is nothing to strip: the method only ever builds these two keys.
     *
     * @return array{id: int, name: string}
     */
    public function toClientSafeArray(): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
        ];
    }
}

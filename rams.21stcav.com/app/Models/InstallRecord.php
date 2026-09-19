<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 45 Plan 04 Task 1 — the DURABLE half of the D-06 split.
 *
 * One row per project, never archived, never replaced. `InstallProgramme`
 * remains the REGENERABLE half: `InstallProgrammeService::createForProject()`
 * archives the previous programme and mints a new one on every regenerate,
 * so a `Visit` (or anything else) filed against a programme is orphaned the
 * first time a PM rebuilds the task list. Filed against the RECORD, it is not.
 *
 * Deliberately NOT here:
 *   - `tasks()` — `install_tasks` did not move; they stay on
 *     `InstallProgramme`. Re-pointing them is Phase 51's work.
 *   - `commissioningSignoff()` — the UNIQUE key on
 *     `commissioning_signoffs.install_programme_id` still means "one per
 *     generation". Also Phase 51.
 *   - `SoftDeletes` — durable by definition.
 *
 * @see database/migrations/2026_09_19_150000_create_install_records_table.php
 * @see app/Services/InstallProgrammeService.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-06)
 *
 * @property int $id
 * @property ?int $project_id
 */
class InstallRecord extends Model
{
    use HasFactory;

    // ── Mass-assignable fields ────────────────────────────────────────────────

    protected $fillable = [
        'project_id',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The project this durable record belongs to. Nullable: the FK is
     * nullOnDelete, matching install_programmes.project_id's existing rule.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Every generation filed under this record, newest first — including the
     * archived ones. History belongs to the same durable record.
     */
    public function programmes(): HasMany
    {
        return $this->hasMany(InstallProgramme::class)->orderBy('created_at', 'desc');
    }

    /**
     * The one generation currently in `active` status, or null.
     */
    public function activeProgramme(): HasOne
    {
        return $this->hasOne(InstallProgramme::class)
            ->where('status', InstallProgramme::STATUS_ACTIVE)
            ->latestOfMany();
    }

    /**
     * Visits filed against this record. A visit's MANDATORY parent is the
     * project, not the record (45-CONTEXT.md D-06 refinement), so this set is
     * legitimately a subset of the project's visits.
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 17 — System Schematics + Shared Foundations.
 *
 * One Eloquent model for every drawing kind in v1.3 (schematic / rack /
 * floor_plan), with the kind discriminator carried as a string column.
 * Mirrors the H-07 collapse to a single DocumentArtifactStorage and the
 * Phase 12 InstallProgramme regenerate-archives-prior pattern.
 *
 * Status state machine (DRAW-25):
 *   draft → for_review → approved
 *                            ↓
 *                       superseded   (when a regen lands)
 *
 *   draft → generating → ready    (build pipeline success)
 *   draft → generating → failed   (build pipeline failure)
 *
 * The two flows overlap deliberately: the status enum unifies the workflow
 * states (draft/for_review/approved/superseded) with the build-pipeline
 * states (generating/ready/failed) so a single column tells the UI exactly
 * what's possible. Plan 03 owns the workflow UI; the build-pipeline states
 * are owned by BuildSchematicJob (Plan 02 fills its body).
 *
 * Versioning (DRAW-24):
 *   - version is 1-indexed; revisionLabel() returns "R" . (version - 1).
 *   - DrawingService::regenerate() replicates the row, bumps version,
 *     archives the prior row via superseded_by_id (transactional).
 *   - The index page filters to current revisions with whereNull(
 *     'superseded_by_id').
 *
 * @see app/Services/Drawings/DrawingService.php
 * @see database/migrations/2026_05_01_000001_create_project_drawings_table.php
 */
class ProjectDrawing extends Model
{
    use HasFactory, SoftDeletes;

    // ── Kind discriminator (DRAW-25) ──────────────────────────────────────────
    public const KIND_SCHEMATIC = 'schematic';

    public const KIND_RACK = 'rack';

    public const KIND_FLOOR_PLAN = 'floor_plan';

    // ── Status state machine (DRAW-25) — workflow + pipeline both live here ──
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FOR_REVIEW = 'for_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id', 'site_survey_room_id', 'kind', 'rack_label',
        'version', 'superseded_by_id',
        'source_data', 'generated_svg', 'canvas_state', 'thumbnail_png_path',
        'status', 'error_message', 'filename',
        'completion_email_sent_at', 'failed_email_sent_at',
        'access_token', 'generated_by',
    ];

    protected $casts = [
        'source_data' => 'array',
        'completion_email_sent_at' => 'datetime',
        'failed_email_sent_at' => 'datetime',
        'version' => 'integer',
        'deleted_at' => 'datetime',
    ];

    // canvas_state stays as raw text — Phase 19 will gzcompress it (MOD-05);
    // no auto-cast at the Eloquent layer to keep the gzcompress boundary
    // explicit on the Phase 19 reader/writer.

    // ── Relations ─────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteSurveyRoom::class, 'site_survey_room_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function predecessors(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    // ── Kind helpers ──────────────────────────────────────────────────────────

    public function isSchematic(): bool
    {
        return $this->kind === self::KIND_SCHEMATIC;
    }

    public function isRack(): bool
    {
        return $this->kind === self::KIND_RACK;
    }

    public function isFloorPlan(): bool
    {
        return $this->kind === self::KIND_FLOOR_PLAN;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isSuperseded(): bool
    {
        return ! is_null($this->superseded_by_id);
    }

    public function hasUserEdits(): bool
    {
        return ! empty($this->canvas_state);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_FOR_REVIEW => 'For Review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_SUPERSEDED => 'Superseded',
            self::STATUS_GENERATING => 'Generating',
            self::STATUS_READY => 'Ready',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'badge-grey',
            self::STATUS_FOR_REVIEW => 'badge-yellow',
            self::STATUS_APPROVED => 'badge-green',
            self::STATUS_SUPERSEDED => 'badge-grey',
            self::STATUS_GENERATING => 'badge-teal',
            self::STATUS_READY => 'badge-blue',
            self::STATUS_FAILED => 'badge-red',
            default => 'badge-grey',
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_SCHEMATIC => 'System Schematic',
            self::KIND_RACK => 'Rack Elevation',
            self::KIND_FLOOR_PLAN => 'Floor Plan',
            default => ucfirst(str_replace('_', ' ', (string) $this->kind)),
        };
    }

    /**
     * Revision label per AV industry convention: first version is "R0",
     * second is "R1", etc. version is 1-indexed in storage.
     */
    public function revisionLabel(): string
    {
        return 'R'.max(0, ((int) $this->version) - 1);
    }
}

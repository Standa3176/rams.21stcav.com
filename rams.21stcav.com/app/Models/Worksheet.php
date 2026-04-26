<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Worksheet model — tracks one worksheet generation run per project.
 *
 * Status pipeline:
 *   pending → generating → draft → final (or failed at any step)
 *
 * The generated_data JSON holds the rooms[] array produced by
 * WorksheetGeneratorService. The filename column stores the DOCX
 * path relative to the worksheets/ directory on the local disk.
 *
 * @see WorksheetGeneratorService — populates generated_data
 * @see WorksheetDocxService      — writes the DOCX, updates filename
 * @see BuildWorksheetJob         — orchestrates the async pipeline
 */
class Worksheet extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────

    public const STATUS_PENDING    = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_DRAFT      = 'draft';
    public const STATUS_FINAL      = 'final';
    public const STATUS_FAILED     = 'failed';

    // ── Mass-assignable fields ────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'project_id',
        'project_name',
        'project_ref',
        'client_name',
        'site_address',
        'status',
        'error_message',
        'generated_data',
        'filename',
        'access_token',
        'completion_email_sent_at',
        'failed_email_sent_at',
    ];

    // ── Boot: auto-generate UUID access_token on creation ────────────────────

    /**
     * Mirrors the SiteSurvey precedent: every newly-created worksheet gains a
     * UUID `access_token` so the public client sign-off URL is immediately
     * shareable (no follow-up step required by the project manager).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Worksheet $worksheet): void {
            if (empty($worksheet->access_token)) {
                $worksheet->access_token = (string) Str::uuid();
            }
        });
    }

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'generated_data'           => 'array',
            'completion_email_sent_at' => 'datetime',
            'failed_email_sent_at'     => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * All client sign-off events captured via the public sign-off page,
     * newest first. Append-only — see WorksheetSignoff for the schema.
     */
    public function signoffs(): HasMany
    {
        return $this->hasMany(WorksheetSignoff::class)->orderBy('signed_at', 'desc');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The most-recent client sign-off, or null when the worksheet is unsigned.
     * Drives the green banner on the public page and the embedded signature
     * inside the regenerated DOCX.
     */
    public function latestSignoff(): ?WorksheetSignoff
    {
        return $this->signoffs()->first();
    }

    /**
     * True once at least one client sign-off has been recorded.
     */
    public function isSigned(): bool
    {
        return $this->signoffs()->exists();
    }

    /**
     * Public no-auth URL the project manager shares with the client. The
     * route name is registered on routes/web.php — Laravel resolves it at
     * call time so the model can reference it before the route file loads.
     */
    public function publicUrl(): string
    {
        return route('public-worksheet.show', ['token' => $this->access_token]);
    }

    /**
     * Returns true once the DOCX has been built (generation complete).
     */
    public function isGenerated(): bool
    {
        return $this->filename !== null && $this->generated_data !== null;
    }

    /**
     * Human-readable status label for display.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'Pending',
            self::STATUS_GENERATING => 'Generating…',
            self::STATUS_DRAFT      => 'Draft',
            self::STATUS_FINAL      => 'Final',
            self::STATUS_FAILED     => 'Failed',
            default                 => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * CSS badge class matching the OmManual/RamsDocument convention.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'badge-yellow',
            self::STATUS_GENERATING => 'badge-blue',
            self::STATUS_DRAFT      => 'badge-teal',
            self::STATUS_FINAL      => 'badge-green',
            self::STATUS_FAILED     => 'badge-red',
            default                 => 'badge-grey',
        };
    }
}

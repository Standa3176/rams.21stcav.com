<?php

namespace App\Models;

use Carbon\Carbon;
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
        'pre_install_confirmations',
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
            'generated_data'             => 'array',
            'pre_install_confirmations'  => 'array',
            'completion_email_sent_at'   => 'datetime',
            'failed_email_sent_at'       => 'datetime',
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
        // Order by signed_at desc with id desc as tie-breaker — two sign-offs
        // recorded inside the same second (e.g. resignoff during a test or a
        // browser double-submit) must still resolve to a deterministic latest.
        return $this->hasMany(WorksheetSignoff::class)
            ->orderBy('signed_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Photos uploaded against this worksheet via the public sign-off link.
     * Engineers attach one or more photos per room before requesting client
     * acceptance. Photo objects are scoped per-room via room_name.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(WorksheetPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Photo count per room name (lower-cased trimmed key) — drives the
     * room summary badge on the public worksheet view.
     *
     * @return array<string, int>
     */
    public function photoCountsByRoom(): array
    {
        $counts = [];
        foreach ($this->photos as $p) {
            $key = strtolower(trim((string) $p->room_name));
            if ($key === '') continue;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
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

    // ── Staleness accessors (quick task 260602-o2a) ───────────────────────────
    //
    // A worksheet is "stale" when the project's latestPackage has been edited
    // AFTER the worksheet's snapshot was generated. The snapshot timestamp is
    // the ISO8601 string embedded in generated_data['generated_at'] by
    // WorksheetGeneratorService::build() (line 230) — NOT $this->updated_at,
    // which mutates on every status flip, sign-off, and email-sent event and
    // would false-positive on every non-snapshot row touch.
    //
    // Only worksheets in status=draft|final have a meaningful snapshot to
    // compare against; pending/generating have no snapshot yet, and failed
    // worksheets surface their own retry button (a stale badge over the top
    // of "broken" is wrong UX).
    //
    // Cross-effect with 260602-mlt (parser polish + ship_contact extraction):
    // when prod runs the package re-extract on existing projects, every
    // ProjectPackage.updated_at advances and isStale() correctly returns true
    // on every existing worksheet for those projects. This is NOT a false
    // positive — the underlying data shape DID change, and a regen picks up
    // the new ship_contact / multi-line description fields. Banner doing its
    // job: telling the user to regenerate.

    /**
     * True iff the project's latestPackage was updated after this worksheet's
     * snapshot timestamp (worksheet's view of the data is out of date).
     *
     * Defensive against:
     *  - status not in {draft, final}        → false (short-circuit)
     *  - $this->project is null              → false (orphaned)
     *  - project has no latestPackage        → false (no source to be stale relative to)
     *  - generated_data is null              → false (no snapshot)
     *  - generated_data missing generated_at → false (legacy shape, do not guess)
     */
    public function isStale(): bool
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_FINAL], true)) {
            return false;
        }

        $this->loadMissing('project.latestPackage');

        $project = $this->project;
        if ($project === null) {
            return false;
        }

        $package = $project->latestPackage;
        if ($package === null) {
            return false;
        }

        $data = $this->generated_data;
        if (! is_array($data)) {
            return false;
        }

        $generatedAt = $data['generated_at'] ?? null;
        if (! is_string($generatedAt) || trim($generatedAt) === '') {
            return false;
        }

        return Carbon::parse($generatedAt)->lt($package->updated_at);
    }

    /**
     * When isStale() is true, returns the Carbon timestamp of the source
     * package's last update (for "Project data updated {diffForHumans}" copy).
     * Returns null when fresh, failed, or any defensive branch in isStale().
     */
    public function staleSince(): ?Carbon
    {
        if (! $this->isStale()) {
            return null;
        }

        $updatedAt = $this->project?->latestPackage?->updated_at;

        return $updatedAt instanceof Carbon ? $updatedAt : ($updatedAt ? Carbon::parse($updatedAt) : null);
    }

    // ── Pre-install confirmation accessors (260504-iy4 — H4) ───────────────────
    //
    // Two-namespace JSON shape:
    //   pre_install_confirmations:
    //     survey_review:  { "Boardroom": {"reviewed_at":"...", "reviewed_by":"abc12345"} }
    //     room_complete:  { "Boardroom": {"completed_at":"...", "completed_by":"abc12345"} }
    //
    // Defensive against:
    //  - $this->pre_install_confirmations === null (fresh worksheet)
    //  - Legacy flat shape from pre-260504-iy4 (returns null → engineer re-marks)
    //  - Missing room key inside the namespace (returns null → engineer marks for the first time)
    //
    // The array-path form of data_get() treats each segment as opaque, so a room
    // name like "Floor 2.5" with a literal dot is NOT parsed as nested keys.

    public function surveyReviewedAt(string $roomName): ?string
    {
        return data_get($this->pre_install_confirmations, ['survey_review', $roomName, 'reviewed_at']);
    }

    public function surveyReviewedBy(string $roomName): ?string
    {
        return data_get($this->pre_install_confirmations, ['survey_review', $roomName, 'reviewed_by']);
    }

    public function roomCompletedAt(string $roomName): ?string
    {
        return data_get($this->pre_install_confirmations, ['room_complete', $roomName, 'completed_at']);
    }

    public function roomCompletedBy(string $roomName): ?string
    {
        return data_get($this->pre_install_confirmations, ['room_complete', $roomName, 'completed_by']);
    }
}

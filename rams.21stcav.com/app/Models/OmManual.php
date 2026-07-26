<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OmManual extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'om_manuals';

    // Workflow status constants (string values stored in DB)
    public const STATUS_EXTRACTED  = 'extracted';   // Pass 1 done — awaiting user review
    public const STATUS_GENERATING = 'generating';  // BuildOmManualJob running
    public const STATUS_DRAFT      = 'draft';       // Pass 2 done — .docx built
    public const STATUS_FINAL      = 'final';       // Approved final version
    public const STATUS_FAILED     = 'failed';      // Job failed

    protected $fillable = [
        'user_id',
        'project_id',
        'rams_document_id',
        'project_name',
        'project_ref',
        'client_name',
        'site_address',
        'source_filename',
        'source_path',
        'status',
        'error_message',
        'extracted_data',
        'generated_data',
        'filename',
        'completion_email_sent_at',
        'failed_email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'extracted_data'           => 'array',
            'generated_data'           => 'array',
            'completion_email_sent_at' => 'datetime',
            'failed_email_sent_at'     => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ramsDocument(): BelongsTo
    {
        return $this->belongsTo(RamsDocument::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Returns true once the .docx has been built (Pass 2 complete). */
    public function isGenerated(): bool
    {
        return $this->filename !== null && $this->generated_data !== null;
    }

    /** Human-readable status label. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EXTRACTED  => 'Awaiting Review',
            self::STATUS_GENERATING => 'Generating…',
            self::STATUS_DRAFT      => 'Draft',
            self::STATUS_FINAL      => 'Final',
            self::STATUS_FAILED     => 'Failed',
            default                 => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /** CSS badge class matching the RAMS document convention. */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_EXTRACTED  => 'badge-yellow',
            self::STATUS_GENERATING => 'badge-blue',
            self::STATUS_DRAFT      => 'badge-teal',
            self::STATUS_FINAL      => 'badge-green',
            self::STATUS_FAILED     => 'badge-red',
            default                 => 'badge-grey',
        };
    }

    // ── Stale-data signal (batch 11 UX-09) ────────────────────────────────────
    //
    // Ports the Worksheet::isStale() pattern to O&M. Same threat: an operator
    // updates the source ProjectPackage after this manual has snapshotted, and
    // the .docx now reflects out-of-date scope. Only fires on draft / final —
    // the extracted-awaiting-review state isn't a "shipped" surface, and
    // generating / failed have their own retry paths.

    /**
     * True iff the project's latestPackage was updated after this O&M's
     * generated snapshot. Falls back on defensive branches — see the RAMS
     * / Worksheet counterparts for the same shape.
     */
    public function isStale(): bool
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_FINAL], true)) {
            return false;
        }

        // Quick task 260726-fx4 — eager-load BOTH the latest package and the
        // latest survey so isStale() reflects either data source moving on.
        $this->loadMissing(['project.latestPackage', 'project.latestSurvey']);

        $project = $this->project;
        if ($project === null) {
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

        $generatedAtC = \Illuminate\Support\Carbon::parse($generatedAt);

        $package = $project->latestPackage;
        if ($package !== null && $generatedAtC->lt($package->updated_at)) {
            return true;
        }

        // Survey check — only fires once submitted_at is stamped (public
        // engineer submission or admin manual submit). In-progress survey
        // edits must not flip every downstream doc to stale.
        $survey = $project->latestSurvey;
        if ($survey !== null
            && $survey->submitted_at !== null
            && $generatedAtC->lt($survey->submitted_at)) {
            return true;
        }

        return false;
    }

    /**
     * When isStale() is true, returns the Carbon timestamp of the source
     * package's last update. Null when fresh or any defensive branch fired.
     */
    public function staleSince(): ?\Illuminate\Support\Carbon
    {
        if (! $this->isStale()) {
            return null;
        }

        // Quick task 260726-fx4 — surface whichever source moved most recently.
        $packageAt = $this->project?->latestPackage?->updated_at;
        $surveyAt  = $this->project?->latestSurvey?->submitted_at;

        $candidates = array_filter([
            $packageAt instanceof \Illuminate\Support\Carbon
                ? $packageAt
                : ($packageAt ? \Illuminate\Support\Carbon::parse($packageAt) : null),
            $surveyAt instanceof \Illuminate\Support\Carbon
                ? $surveyAt
                : ($surveyAt  ? \Illuminate\Support\Carbon::parse($surveyAt)  : null),
        ]);

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->max();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SiteSurvey;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * visits:backfill
 *
 * Phase 45 Plan 05 — wraps the history the app already holds in one typed
 * `visits` row per trip to site. This is where ROADMAP v4.0 criterion 2 is
 * delivered.
 *
 * D-01 — WHAT COUNTS AS A VISIT, and why an unsigned worksheet does not.
 * A visit is created from a `SiteSurvey` row, or a `Worksheet` row that has at
 * least one `WorksheetSignoff`. A survey is an attendance by nature. A
 * worksheet is NOT: its own docblock calls it "one worksheet generation run
 * per project", with a `pending → generating → draft → final` pipeline, so it
 * evidences a trip to site only when a client signed on site. Unsigned
 * worksheets stay documents and are reported under
 * `worksheet-unsigned-skipped` so the operator can see they were seen and
 * deliberately passed over. D-01 is authoritative over criterion 2's wording
 * ("wrap SiteSurvey and Worksheet rows"): both relations are `hasMany` on
 * `Project`, so the literal reading would mint an install visit for every
 * regeneration.
 *
 * ⚠️ ONE VISIT PER WORKSHEET, NEVER ONE PER SIGNOFF. `worksheet_signoffs` has
 * NO unique constraint on `worksheet_id` — its migration says so verbatim,
 * "clients can sign multiple times" — so iterating signoffs would mint a
 * duplicate visit for every resignoff. The worksheet query therefore uses
 * `whereHas('signoffs')` (an existence test) rather than joining or iterating
 * the signoff rows.
 *
 * D-02 — `install` IS AN INFERENCE. A signed worksheet proves attendance but
 * not what was done; first fix, install and commissioning all produce the same
 * worksheet. Worksheet-derived visits are therefore typed
 * `Visit::TYPE_INSTALL` and carry `is_backfilled = true`, D-02's explicit
 * queryable marker saying the type was reconstructed rather than asserted by a
 * human. There is deliberately no `legacy` type: an enum value meaning "we do
 * not know" would have to be handled by every filter and report forever.
 *
 * D-03 — `SiteSurvey.survey_type` IS A DEAD COLUMN, superseded by the
 * room-level `space_type` (see
 * `2026_04_05_210000_add_space_type_to_site_survey_rooms.php`) and defaulting
 * to `general`. It is NEVER read here. Survey visits are hardcoded
 * `Visit::TYPE_SITE_SURVEY`.
 *
 * D-04 — A VISIT OUTLIVES ITS PAPERWORK. Both queries use `withTrashed()`, and
 * the survey query deliberately does NOT filter `superseded_at`. Copying
 * `->whereNull('superseded_at')` from `SiteSurveyController` into here would
 * hide exactly the visits D-04 requires be shown: the trip to site happened,
 * and superseding the paperwork does not un-happen it. The cockpit MARKS a
 * superseded visit; it never filters it out. `title` and `scheduled_date` are
 * denormalised from the source for the same reason, so the row still renders
 * after the source is force-deleted.
 *
 * D-05 — A COMMAND, NOT A MIGRATION, and idempotent. The user validates on the
 * live VPS against real project data, so deploy and backfill must be separable
 * decisions, and a bad backfill must be re-runnable rather than unpickable.
 * Dry-run by DEFAULT, per the `BackfillCablePortFksCommand` precedent: review
 * the report, then `--apply`. The `already-wrapped` guard runs FIRST on every
 * row, before any other per-row work, and the `(source_type, source_id)`
 * unique composite index `visits_source_unique` (Plan 45-02) is the database
 * backstop under a concurrent re-run.
 *
 * WHAT THIS COMMAND DELIBERATELY DOES NOT DO:
 *   - It does NOT write to `site_surveys` or `worksheets`. Never `save()`,
 *     never `update()`, never `touch()`. Both models have `boot::creating()`
 *     hooks and deliberate `$fillable` omissions from a security re-audit
 *     (`access_token`, `access_token_expires_at`,
 *     `submitted_notification_sent_at`), and both carry LIVE public access
 *     tokens that every engineer link in the field depends on. `visits` is the
 *     only table written.
 *   - It does NOT delete anything, soft or hard.
 *   - It does NOT backfill `install_record_id`. Plan 45-04's
 *     `install-records:backfill` owns programme linking, and a survey visit
 *     routinely predates any install record existing at all.
 *   - It does NOT assign labour. `labour_resource_ids` starts empty; nothing in
 *     the historical record says who attended.
 *
 * Per-row outcome categories:
 *   - survey                     : a `SiteSurvey` that will be (or was) wrapped
 *   - worksheet-signed           : a `Worksheet` with >= 1 signoff, wrapped ONCE
 *   - worksheet-unsigned-skipped : a `Worksheet` with no signoff — no visit (D-01)
 *   - already-wrapped            : `(source_type, source_id)` already exists in
 *                                  `visits` → skipped before any other work.
 *                                  This is the idempotency invariant: a second
 *                                  `--apply` performs zero writes and leaves
 *                                  every existing visit's `updated_at` alone.
 *   - orphan-no-project          : the source's `project_id` IS NULL. Legal —
 *                                  both `site_surveys.project_id` and
 *                                  `worksheets.project_id` are `nullOnDelete`,
 *                                  so a survey or worksheet survives its
 *                                  project. `visits.project_id` is NOT NULL
 *                                  (the project is a visit's mandatory parent,
 *                                  D-06 refinement), so such a row gets no
 *                                  visit and is reported in its own category
 *                                  rather than crashing the run.
 *   - wrote                      : rows actually persisted (only under --apply)
 *
 * SQL injection on the project arg: cast to `(int)` and PDO parameter-bound,
 * per the `BackfillCablePortFksCommand:53-58` precedent. Arg
 * "5; DROP TABLE site_surveys;" parses as the integer 5.
 *
 * Usage:
 *   php artisan visits:backfill            # dry-run, all projects
 *   php artisan visits:backfill --apply    # write, all projects
 *   php artisan visits:backfill 5          # dry-run, project 5
 *   php artisan visits:backfill 5 --apply  # write, project 5
 *
 * Admin-only by convention (CLI access = admin). Do NOT expose via HTTP.
 *
 * @see app/Models/Visit.php (the only table this command writes)
 * @see app/Console/Commands/BackfillInstallRecordsCommand.php (sibling, 45-04)
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-01..D-05)
 */
class BackfillVisitsCommand extends Command
{
    protected $signature = 'visits:backfill
                            {project? : Project ID to scope the backfill (default: all projects)}
                            {--apply : Actually create visits (default: dry-run reports only)}';

    protected $description = 'Wrap every site survey, and every signed worksheet, in one typed visit. Idempotent and dry-run by default.';

    /**
     * Per-run outcome counters.
     *
     * Reset at the TOP of handle(), never as a property default: Artisan
     * resolves a command once and reuses the same instance for every
     * invocation in the same process, so a default would make a second run's
     * report the cumulative total of both runs — and the idempotency report
     * ("wrote: 0") would read as a write.
     *
     * @var array<string, int>
     */
    private array $summary = [];

    public function handle(): int
    {
        $this->summary = [
            'survey'                     => 0,
            'worksheet-signed'           => 0,
            'worksheet-unsigned-skipped' => 0,
            'already-wrapped'            => 0,
            'orphan-no-project'          => 0,
            'wrote'                      => 0,
        ];

        // int cast neutralises SQL injection on the project arg.
        $projectArg = $this->argument('project');
        $projectId  = $projectArg !== null ? (int) $projectArg : null;

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->info('[DRY RUN] visits:backfill — pass --apply to persist.');
        } else {
            $this->info('visits:backfill — APPLYING writes.');
        }

        // -- Sources --
        //
        // withTrashed() on both, and NO superseded_at filter on the survey
        // query (D-04). whereHas('signoffs') is an existence test, so a
        // worksheet signed three times is returned exactly once.

        $surveys = $this->scoped(SiteSurvey::withTrashed()->orderBy('id'), $projectId)->get();

        $signedWorksheets = $this->scoped(
            Worksheet::withTrashed()->whereHas('signoffs')->orderBy('id'),
            $projectId
        )->get();

        $unsignedWorksheets = $this->scoped(
            Worksheet::withTrashed()->whereDoesntHave('signoffs')->orderBy('id'),
            $projectId
        )->get();

        if ($surveys->isEmpty() && $signedWorksheets->isEmpty() && $unsignedWorksheets->isEmpty()) {
            $this->info('No site_surveys or worksheets found.');

            return self::SUCCESS;
        }

        // -- Surveys (D-03: type is hardcoded, survey_type is never read) --

        foreach ($surveys as $survey) {
            if ($this->skipRow(Visit::SOURCE_SITE_SURVEY, $survey->id, 'survey', $survey->project_id)) {
                continue;
            }

            $this->summary['survey']++;

            $date = $survey->submitted_at ?? $survey->created_at;

            $this->persist($apply, 'survey', $survey->id, [
                'project_id'          => $survey->project_id,
                'install_record_id'   => null,
                'type'                => Visit::TYPE_SITE_SURVEY,
                'status'              => Visit::STATUS_COMPLETED,
                'scheduled_date'      => $date?->toDateString(),
                'labour_resource_ids' => [],
                'source_type'         => Visit::SOURCE_SITE_SURVEY,
                'source_id'           => $survey->id,
                'is_backfilled'       => true,
                'title'               => $this->title('Site survey', $survey->project_name, 'Survey', $survey->id),
                'summary'             => sprintf('Reconstructed by visits:backfill from site survey #%d.', $survey->id),
            ]);
        }

        // -- Signed worksheets (D-02: install is an inference, hence the marker) --

        foreach ($signedWorksheets as $worksheet) {
            if ($this->skipRow(Visit::SOURCE_WORKSHEET, $worksheet->id, 'worksheet', $worksheet->project_id)) {
                continue;
            }

            $this->summary['worksheet-signed']++;

            // latestSignoff() reads the signoffs relation (ordered signed_at
            // desc, id desc) — read-only, and it never mints a second visit
            // because we are iterating worksheets, not signoffs.
            $date = $worksheet->latestSignoff()?->signed_at ?? $worksheet->created_at;

            $this->persist($apply, 'worksheet-signed', $worksheet->id, [
                'project_id'          => $worksheet->project_id,
                'install_record_id'   => null,
                'type'                => Visit::TYPE_INSTALL,
                'status'              => Visit::STATUS_COMPLETED,
                'scheduled_date'      => $date?->toDateString(),
                'labour_resource_ids' => [],
                'source_type'         => Visit::SOURCE_WORKSHEET,
                'source_id'           => $worksheet->id,
                'is_backfilled'       => true,
                'title'               => $this->title('Install', $worksheet->project_name, 'Worksheet', $worksheet->id),
                'summary'             => sprintf(
                    'Reconstructed by visits:backfill from signed worksheet #%d. Type is an inference (D-02).',
                    $worksheet->id
                ),
            ]);
        }

        // -- Unsigned worksheets: reported, never wrapped (D-01) --

        foreach ($unsignedWorksheets as $worksheet) {
            $this->summary['worksheet-unsigned-skipped']++;
            $this->line(sprintf(
                '  worksheet #%d — %s: %s',
                $worksheet->id,
                'worksheet-unsigned-skipped',
                'no WorksheetSignoff — a generation run, not an attendance (D-01)'
            ));
        }

        // -- Summary --

        $this->newLine();
        $this->info('Summary:');
        $this->line(sprintf(
            '  survey: %d  |  worksheet-signed: %d  |  worksheet-unsigned-skipped: %d  |  already-wrapped: %d  |  orphan-no-project: %d  |  wrote: %d',
            $this->summary['survey'],
            $this->summary['worksheet-signed'],
            $this->summary['worksheet-unsigned-skipped'],
            $this->summary['already-wrapped'],
            $this->summary['orphan-no-project'],
            $this->summary['wrote'],
        ));

        Log::info('visits:backfill completed', [
            'project_id' => $projectId,
            'apply'      => $apply,
            'summary'    => $this->summary,
        ]);

        return self::SUCCESS;
    }

    // -- Helpers --

    /**
     * Scope a source query to one project when the positional arg was given.
     */
    private function scoped(Builder $query, ?int $projectId): Builder
    {
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query;
    }

    /**
     * The two pre-flight guards, in the order they must run.
     *
     * 1. `already-wrapped` FIRST, before any other per-row work, so a re-run
     *    reports honestly and writes nothing (D-05).
     * 2. `orphan-no-project` second: `visits.project_id` is NOT NULL, so a
     *    source detached from its project cannot be wrapped.
     *
     * Returns true when the caller must `continue`.
     */
    private function skipRow(string $sourceType, int $sourceId, string $label, ?int $projectId): bool
    {
        if (Visit::where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            $this->summary['already-wrapped']++;
            $this->line(sprintf(
                '  %s #%d — %s: %s',
                $label,
                $sourceId,
                'already-wrapped',
                'a visit already wraps this source'
            ));

            return true;
        }

        if ($projectId === null) {
            $this->summary['orphan-no-project']++;
            $this->line(sprintf(
                '  %s #%d — %s: %s',
                $label,
                $sourceId,
                'orphan-no-project',
                'project_id is NULL (nullOnDelete) — a visit requires a project parent'
            ));

            return true;
        }

        return false;
    }

    /**
     * Write the visit, but only under --apply, and only inside a transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(bool $apply, string $label, int $sourceId, array $attributes): void
    {
        if (! $apply) {
            $this->line(sprintf(
                '  %s #%d — %s: %s',
                $label,
                $sourceId,
                $label,
                'would create a ' . $attributes['type'] . ' visit on project ' . $attributes['project_id']
            ));

            return;
        }

        DB::transaction(function () use ($attributes): void {
            Visit::create($attributes);
            $this->summary['wrote']++;
        });

        $this->line(sprintf(
            '  %s #%d — %s: %s',
            $label,
            $sourceId,
            $label,
            'created a ' . $attributes['type'] . ' visit on project ' . $attributes['project_id']
        ));
    }

    /**
     * Denormalised title (D-04) — the visit must still read once its source is
     * force-deleted. `visits.title` is varchar(200).
     */
    private function title(string $prefix, ?string $projectName, string $fallbackNoun, int $sourceId): string
    {
        $name = trim((string) $projectName);

        if ($name === '') {
            $name = $fallbackNoun . ' #' . $sourceId;
        }

        return mb_substr($prefix . ' - ' . $name, 0, 200);
    }
}

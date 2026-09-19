<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * VIS-02 / VIS-03 / VIS-07 / VIS-08 — `visits:backfill`.
 *
 * Wraps the history the app already holds in one typed `Visit` per trip to
 * site, narrowed by 45-CONTEXT.md D-01: every `SiteSurvey`, and every
 * `Worksheet` that carries at least one `WorksheetSignoff`. An unsigned
 * worksheet is a document-generation run, not an attendance, and produces
 * nothing.
 *
 * The load-bearing cases in here, in order of how easy they are to get wrong:
 *
 *  1. `worksheet_signoffs` has NO unique constraint on `worksheet_id`
 *     (see its migration docblock — "clients can sign multiple times"), so a
 *     command that iterated signoffs would mint one visit per signature.
 *     `test_a_worksheet_with_three_signoffs_produces_exactly_one_visit` is
 *     the guard.
 *  2. Copying `->whereNull('superseded_at')` from `SiteSurveyController.php`
 *     into this command would hide exactly the visits D-04 requires be shown.
 *  3. The command must not write to `site_surveys` or `worksheets` — both
 *     carry live public access tokens and `boot::creating()` hooks, and both
 *     have deliberate `$fillable` omissions from a security re-audit.
 *
 * @see app/Console/Commands/BackfillVisitsCommand.php
 */
class BackfillVisitsCommandTest extends TestCase
{
    use RefreshDatabase;

    // -- Fixtures --

    private function makeSurvey(?Project $project = null, array $overrides = []): SiteSurvey
    {
        $user = User::factory()->create();
        $project ??= Project::factory()->create(['user_id' => $user->id]);

        return SiteSurvey::create(array_merge([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme HQ Refresh',
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'completed',
        ], $overrides));
    }

    private function makeWorksheet(?Project $project = null, array $overrides = []): Worksheet
    {
        $user = User::factory()->create();
        $project ??= Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create(array_merge([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme HQ Install',
            'status'       => Worksheet::STATUS_FINAL,
        ], $overrides));
    }

    private function sign(Worksheet $worksheet, string $signedAt = '2026-05-01 09:00:00'): WorksheetSignoff
    {
        return WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => $signedAt,
        ]);
    }

    // -- Dry run is the default --

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $project = Project::factory()->create();
        $this->makeSurvey($project);
        $this->sign($this->makeWorksheet($project));

        $this->artisan('visits:backfill')->assertSuccessful();

        $this->assertDatabaseCount('visits', 0);
    }

    // -- Apply --

    public function test_apply_creates_one_visit_per_survey_and_one_per_signed_worksheet(): void
    {
        $project = Project::factory()->create();
        $this->makeSurvey($project);
        $this->makeSurvey($project, ['project_name' => 'Second survey']);
        $this->sign($this->makeWorksheet($project));

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(3, Visit::count());
        $this->assertSame(2, Visit::where('source_type', Visit::SOURCE_SITE_SURVEY)->count());
        $this->assertSame(1, Visit::where('source_type', Visit::SOURCE_WORKSHEET)->count());
    }

    public function test_an_unsigned_worksheet_produces_no_visit(): void
    {
        $project = Project::factory()->create();
        $this->makeWorksheet($project);

        $this->artisan('visits:backfill', ['--apply' => true])
            ->expectsOutputToContain('worksheet-unsigned-skipped: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('visits', 0);
    }

    /**
     * THE D-01 pitfall. `worksheet_signoffs` has no unique constraint on
     * `worksheet_id`, so a resigned worksheet carries several rows. One
     * worksheet is one trip to site — assert 1, never 3.
     */
    public function test_a_worksheet_with_three_signoffs_produces_exactly_one_visit(): void
    {
        $project   = Project::factory()->create();
        $worksheet = $this->makeWorksheet($project);

        $this->sign($worksheet, '2026-05-01 09:00:00');
        $this->sign($worksheet, '2026-05-02 09:00:00');
        $this->sign($worksheet, '2026-05-03 09:00:00');

        $this->assertSame(3, WorksheetSignoff::where('worksheet_id', $worksheet->id)->count());

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, Visit::count());
        $this->assertSame(
            1,
            Visit::where('source_type', Visit::SOURCE_WORKSHEET)->where('source_id', $worksheet->id)->count()
        );
    }

    // -- Idempotency (D-05) --

    public function test_a_second_apply_creates_nothing_and_rewrites_nothing(): void
    {
        $project = Project::factory()->create();
        $this->makeSurvey($project);
        $this->sign($this->makeWorksheet($project));

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $countAfterFirst     = Visit::count();
        $updatedAtAfterFirst = Visit::orderBy('id')->get()
            ->mapWithKeys(fn ($v) => [$v->id => (string) $v->getRawOriginal('updated_at')])->all();

        $this->assertSame(2, $countAfterFirst);

        Carbon::setTestNow(Carbon::now()->addDay());

        $this->artisan('visits:backfill', ['--apply' => true])
            ->expectsOutputToContain('already-wrapped: 2')
            ->expectsOutputToContain('wrote: 0')
            ->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame($countAfterFirst, Visit::count(), 'A second --apply must create no rows.');
        $this->assertSame(
            $updatedAtAfterFirst,
            Visit::orderBy('id')->get()
                ->mapWithKeys(fn ($v) => [$v->id => (string) $v->getRawOriginal('updated_at')])->all(),
            'A second --apply must not rewrite an existing visit (updated_at unchanged).'
        );
    }

    public function test_a_dry_run_after_an_apply_reports_every_row_as_already_wrapped(): void
    {
        $project = Project::factory()->create();
        $this->makeSurvey($project);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->artisan('visits:backfill')
            ->expectsOutputToContain('already-wrapped: 1')
            ->assertSuccessful();

        $this->assertSame(1, Visit::count());
    }

    // -- D-04: superseded and soft-deleted sources are still wrapped --

    public function test_a_soft_deleted_survey_is_still_wrapped(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->makeSurvey($project);
        $survey->delete();

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('visits', [
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    public function test_a_superseded_survey_is_still_wrapped(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->makeSurvey($project, ['superseded_at' => '2026-04-01 10:00:00']);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('visits', [
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    public function test_a_soft_deleted_signed_worksheet_is_still_wrapped(): void
    {
        $project   = Project::factory()->create();
        $worksheet = $this->makeWorksheet($project);
        $this->sign($worksheet);
        $worksheet->delete();

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('visits', [
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $worksheet->id,
        ]);
    }

    // -- Typing and the backfilled marker (D-02, D-03) --

    public function test_survey_visits_are_typed_site_survey_and_marked_backfilled(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->makeSurvey($project);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $visit = Visit::where('source_type', Visit::SOURCE_SITE_SURVEY)->firstOrFail();

        $this->assertSame(Visit::TYPE_SITE_SURVEY, $visit->type);
        $this->assertTrue($visit->is_backfilled);
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertSame($project->id, $visit->project_id);
        $this->assertSame($survey->id, $visit->source_id);
        $this->assertNull($visit->install_record_id, 'Programme linking belongs to install-records:backfill.');
    }

    public function test_worksheet_visits_are_typed_install_and_marked_backfilled(): void
    {
        $project   = Project::factory()->create();
        $worksheet = $this->makeWorksheet($project);
        $this->sign($worksheet);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $visit = Visit::where('source_type', Visit::SOURCE_WORKSHEET)->firstOrFail();

        $this->assertSame(Visit::TYPE_INSTALL, $visit->type);
        $this->assertTrue($visit->is_backfilled);
        $this->assertSame($project->id, $visit->project_id);
    }

    /**
     * D-03 — `SiteSurvey.survey_type` is a dead column, superseded by the
     * room-level `space_type`. Deriving a visit type from it would be wrong
     * even when it holds a plausible value.
     */
    public function test_the_dead_survey_type_column_is_never_used_to_derive_a_visit_type(): void
    {
        $project = Project::factory()->create();
        $this->makeSurvey($project, ['survey_type' => 'install']);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(Visit::TYPE_SITE_SURVEY, Visit::firstOrFail()->type);
    }

    // -- Denormalised rendering fields --

    public function test_scheduled_date_and_title_are_denormalised_from_the_source(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->makeSurvey($project, [
            'project_name' => 'Northbank Boardroom Survey',
            'submitted_at' => '2026-03-11 14:30:00',
        ]);

        $worksheet = $this->makeWorksheet($project, ['project_name' => 'Northbank Boardroom Install']);
        $this->sign($worksheet, '2026-06-20 08:15:00');

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $surveyVisit = Visit::where('source_id', $survey->id)
            ->where('source_type', Visit::SOURCE_SITE_SURVEY)->firstOrFail();
        $worksheetVisit = Visit::where('source_id', $worksheet->id)
            ->where('source_type', Visit::SOURCE_WORKSHEET)->firstOrFail();

        $this->assertSame('2026-03-11', $surveyVisit->scheduled_date->toDateString());
        $this->assertStringContainsString('Northbank Boardroom Survey', (string) $surveyVisit->title);

        $this->assertSame('2026-06-20', $worksheetVisit->scheduled_date->toDateString());
        $this->assertStringContainsString('Northbank Boardroom Install', (string) $worksheetVisit->title);
    }

    public function test_a_visit_still_renders_after_its_source_is_force_deleted(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->makeSurvey($project, ['project_name' => 'Doomed Survey']);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $survey->forceDelete();

        $visit = Visit::firstOrFail();

        $this->assertNull($visit->source());
        $this->assertStringContainsString('Doomed Survey', (string) $visit->title);
        $this->assertNotNull($visit->scheduled_date);
    }

    // -- Orphans (project_id is nullable on both sources, NOT NULL on visits) --

    public function test_a_source_with_no_project_lands_in_orphan_no_project(): void
    {
        $this->makeSurvey(null, ['project_id' => null]);

        $this->artisan('visits:backfill', ['--apply' => true])
            ->expectsOutputToContain('orphan-no-project: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('visits', 0);
    }

    // -- Scoping --

    public function test_the_optional_project_argument_scopes_the_backfill(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();

        $surveyA = $this->makeSurvey($a);
        $this->makeSurvey($b);
        $this->sign($this->makeWorksheet($b));

        $this->artisan('visits:backfill', ['project' => $a->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(1, Visit::count());
        $this->assertSame($surveyA->id, Visit::firstOrFail()->source_id);
    }

    // -- VIS-02: nothing is deleted and no existing row is rewritten --

    public function test_no_site_survey_or_worksheet_row_is_written(): void
    {
        $project = Project::factory()->create();

        $survey  = $this->makeSurvey($project);
        $deleted = $this->makeSurvey($project, ['project_name' => 'Soft deleted']);
        $deleted->delete();
        $worksheet = $this->makeWorksheet($project);
        $this->sign($worksheet);
        $unsigned = $this->makeWorksheet($project, ['project_name' => 'Unsigned']);

        $surveysBefore = SiteSurvey::withTrashed()->orderBy('id')->get()
            ->mapWithKeys(fn ($s) => [$s->id => $s->getRawOriginal()])->all();
        $worksheetsBefore = Worksheet::withTrashed()->orderBy('id')->get()
            ->mapWithKeys(fn ($w) => [$w->id => $w->getRawOriginal()])->all();

        Carbon::setTestNow(Carbon::now()->addDay());

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        Carbon::setTestNow();

        $surveysAfter = SiteSurvey::withTrashed()->orderBy('id')->get()
            ->mapWithKeys(fn ($s) => [$s->id => $s->getRawOriginal()])->all();
        $worksheetsAfter = Worksheet::withTrashed()->orderBy('id')->get()
            ->mapWithKeys(fn ($w) => [$w->id => $w->getRawOriginal()])->all();

        $this->assertSame($surveysBefore, $surveysAfter, 'No site_surveys row may be rewritten.');
        $this->assertSame($worksheetsBefore, $worksheetsAfter, 'No worksheets row may be rewritten.');

        // Explicit updated_at assertions so a failure names the property directly.
        $this->assertSame(
            $surveysBefore[$survey->id]['updated_at'],
            $surveysAfter[$survey->id]['updated_at']
        );
        $this->assertSame(
            $worksheetsBefore[$worksheet->id]['updated_at'],
            $worksheetsAfter[$worksheet->id]['updated_at']
        );
        $this->assertSame(
            $worksheetsBefore[$unsigned->id]['updated_at'],
            $worksheetsAfter[$unsigned->id]['updated_at']
        );

        // Nothing deleted, either.
        $this->assertSame(2, SiteSurvey::withTrashed()->count());
        $this->assertSame(2, Worksheet::withTrashed()->count());
        $this->assertSame(1, WorksheetSignoff::count());
    }

    // -- Empty set --

    public function test_an_empty_database_is_a_clean_no_op(): void
    {
        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('visits', 0);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VIS-01 / VIS-08 — the `Visit` model contract.
 *
 * The three D-04 survival cases in here are the phase's load-bearing
 * assertions: a visit must outlive the paperwork it wraps being soft-deleted,
 * superseded, or force-deleted. The unique-index case is the guard the 45-05
 * backfill's idempotency depends on at the DATABASE level, which an
 * application-side `->exists()` check alone would not provide.
 *
 * `SiteSurvey` has no factory in this repo, so survey rows are created with
 * `::create()` rather than expanding scope into the survey test
 * infrastructure. Neither `SiteSurvey` nor `Worksheet` is modified by this
 * phase — both carry deliberate `$fillable` omissions from a security
 * re-audit.
 */
class VisitTest extends TestCase
{
    use RefreshDatabase;

    // ── Vocabulary ───────────────────────────────────────────────────────────

    public function test_type_constants_are_the_six_roadmap_types(): void
    {
        $this->assertSame('site_survey', Visit::TYPE_SITE_SURVEY);
        $this->assertSame('first_fix', Visit::TYPE_FIRST_FIX);
        $this->assertSame('install', Visit::TYPE_INSTALL);
        $this->assertSame('programming', Visit::TYPE_PROGRAMMING);
        $this->assertSame('snag', Visit::TYPE_SNAG);
        $this->assertSame('commissioning', Visit::TYPE_COMMISSIONING);

        $this->assertCount(6, Visit::TYPES);
        $this->assertNotContains('legacy', Visit::TYPES, 'D-02 forbids a `legacy` visit type.');
    }

    public function test_status_constants_are_only_the_two_read_only_states(): void
    {
        $this->assertSame('planned', Visit::STATUS_PLANNED);
        $this->assertSame('completed', Visit::STATUS_COMPLETED);
        $this->assertSame(['planned', 'completed'], Visit::STATUSES);
    }

    public function test_source_type_constants(): void
    {
        $this->assertSame('site_survey', Visit::SOURCE_SITE_SURVEY);
        $this->assertSame('worksheet', Visit::SOURCE_WORKSHEET);
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_factory_creates_a_completed_install_visit_on_a_project(): void
    {
        $visit = Visit::factory()->create();

        $this->assertSame(Visit::TYPE_INSTALL, $visit->type);
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertFalse($visit->isBackfilled());
        $this->assertSame([], $visit->labour_resource_ids);
        $this->assertNull($visit->source_type);
        $this->assertNull($visit->source());
        $this->assertNull($visit->install_record_id);
        $this->assertInstanceOf(Project::class, $visit->project);
    }

    public function test_labour_resource_ids_round_trip_as_an_array_of_ints(): void
    {
        $visit = Visit::factory()->create(['labour_resource_ids' => [1, 2]]);

        $fresh = Visit::findOrFail($visit->id);

        $this->assertIsArray($fresh->labour_resource_ids);
        $this->assertCount(2, $fresh->labour_resource_ids);
        $this->assertSame([1, 2], $fresh->labour_resource_ids);
    }

    public function test_labour_resources_resolves_the_assigned_people(): void
    {
        $a = LabourResource::factory()->create();
        $b = LabourResource::factory()->create();

        $visit = Visit::factory()->create(['labour_resource_ids' => [$a->id, $b->id]]);

        $this->assertCount(2, $visit->labourResources());
        $this->assertCount(0, Visit::factory()->create()->labourResources());
    }

    public function test_backfilled_marker_is_explicit_and_queryable(): void
    {
        $survey = $this->makeSurvey();
        $visit  = Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $survey->project_id,
        ]);

        $this->assertTrue($visit->isBackfilled());
        $this->assertSame(Visit::TYPE_SITE_SURVEY, $visit->type);
        $this->assertSame(1, Visit::where('is_backfilled', true)->count());
    }

    public function test_a_poisoned_source_type_resolves_to_null_not_a_model(): void
    {
        $visit = Visit::factory()->create([
            'source_type' => 'App\\Models\\User',
            'source_id'   => 1,
        ]);

        $this->assertNull($visit->source(), 'source() must never resolve a class name from the column.');
    }

    // ── D-04: the visit outlives its source ──────────────────────────────────

    public function test_visit_survives_its_source_being_soft_deleted(): void
    {
        $survey = $this->makeSurvey();
        $visit  = Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $survey->project_id,
        ]);

        $survey->delete();

        $fresh = Visit::findOrFail($visit->id);

        $this->assertInstanceOf(SiteSurvey::class, $fresh->source());
        $this->assertSame($survey->id, $fresh->source()->id);
        $this->assertTrue($fresh->isSuperseded());
        $this->assertDatabaseHas('visits', ['id' => $visit->id]);
    }

    public function test_visit_survives_its_source_being_superseded(): void
    {
        $survey = $this->makeSurvey();
        $visit  = Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $survey->project_id,
        ]);

        $survey->update(['superseded_at' => now()]);

        // NOTE: no whereNull('superseded_at') anywhere — D-04 requires the
        // cockpit to MARK a superseded visit, never to hide it.
        $found = Visit::where('project_id', $survey->project_id)->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->isSuperseded());
        $this->assertDatabaseHas('visits', ['id' => $visit->id]);
    }

    public function test_visit_still_renders_when_its_source_is_force_deleted(): void
    {
        $survey = $this->makeSurvey();
        $visit  = Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id'     => $survey->project_id,
            'title'          => 'Site survey level 3 boardroom',
            'scheduled_date' => '2026-04-01',
        ]);

        $survey->forceDelete();

        $fresh = Visit::findOrFail($visit->id);

        $this->assertNull($fresh->source());
        $this->assertSame('Site survey level 3 boardroom', $fresh->title);
        $this->assertSame('2026-04-01', $fresh->scheduled_date->format('Y-m-d'));
    }

    public function test_visit_survives_a_soft_deleted_worksheet_source(): void
    {
        $worksheet = Worksheet::factory()->create();
        $visit     = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id' => $worksheet->project_id,
        ]);

        $worksheet->delete();

        $fresh = Visit::findOrFail($visit->id);

        $this->assertInstanceOf(Worksheet::class, $fresh->source());
        $this->assertTrue($fresh->isSuperseded());
        $this->assertSame(Visit::TYPE_INSTALL, $fresh->type);
    }

    // ── Idempotency at the DB level ──────────────────────────────────────────

    public function test_the_same_source_can_never_mint_two_visits(): void
    {
        $survey = $this->makeSurvey();

        Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $survey->project_id,
        ]);

        $this->expectException(QueryException::class);

        Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $survey->project_id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSurvey(): SiteSurvey
    {
        $project = Project::factory()->create();

        return SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);
    }
}

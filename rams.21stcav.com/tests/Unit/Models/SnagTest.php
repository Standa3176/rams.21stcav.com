<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Snag;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46 Plan 02 — locks the MINIMAL snag record D-03 asks for, and fences
 * it against Phase 47.
 *
 * 46-CONTEXT.md D-03: "raising a snag here creates a minimal snag record
 * linked to the visit it came from. Parts, the three outcomes, and linked
 * follow-up snags are Phase 47. Phase 46 must not build the snag lifecycle —
 * it provides the entry point Phase 47 builds out."
 *
 * @see app/Models/Snag.php
 * @see database/migrations/2026_09_20_130000_create_snags_table.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-03)
 */
class SnagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The link D-03 asks for: "a minimal snag record linked to the visit it
     * came from". It must resolve in both directions.
     */
    public function test_a_snag_knows_the_visit_it_came_from(): void
    {
        $visit = Visit::factory()->create();
        $snag = Snag::factory()->create([
            'project_id' => $visit->project_id,
            'visit_id'   => $visit->id,
        ]);

        $this->assertSame($visit->id, $snag->visit->id);
        $this->assertSame($visit->project_id, $snag->project->id);
    }

    /**
     * Phase 47 criterion 1: "one visit may resolve several snags". The link
     * lives on the snag, so N snags may already point at one visit — nothing
     * about that shape has to change in Phase 47.
     */
    public function test_one_visit_may_carry_several_snags(): void
    {
        $visit = Visit::factory()->create();

        Snag::factory()->count(3)->create([
            'project_id' => $visit->project_id,
            'visit_id'   => $visit->id,
        ]);

        $this->assertSame(3, Snag::where('visit_id', $visit->id)->count());
    }

    /**
     * D-04 applied to a snag: what was reported does not stop having been
     * reported because the visit record went. `visits` has no softDeletes, so
     * this is a HARD delete and the FK is the only thing standing between the
     * finding and oblivion — it nulls, it does not cascade.
     */
    public function test_deleting_a_visit_does_not_delete_its_snags(): void
    {
        $visit = Visit::factory()->create();
        $snag = Snag::factory()->create([
            'project_id' => $visit->project_id,
            'visit_id'   => $visit->id,
        ]);

        $visit->delete();

        $this->assertDatabaseHas('snags', ['id' => $snag->id]);
        $this->assertNull($snag->fresh()->visit_id);
        $this->assertSame('Trunking not made good in the comms room', $snag->fresh()->title);
    }

    /**
     * The same D-04 property the visit itself has: the snag still resolves its
     * visit and its project after the visit's SOURCE record is force-deleted.
     * `Visit::source()` goes null; the visit row, and therefore the snag's
     * link, are untouched.
     */
    public function test_a_snag_survives_its_visits_source_being_force_deleted(): void
    {
        $survey = $this->makeSurvey();
        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $survey->project_id]);

        $snag = Snag::factory()->create([
            'project_id' => $visit->project_id,
            'visit_id'   => $visit->id,
        ]);

        $survey->forceDelete();

        $snag->refresh();

        $this->assertNull($snag->visit->source());
        $this->assertSame($visit->id, $snag->visit->id);
        $this->assertSame($visit->project_id, $snag->project->id);
    }

    /**
     * Phase 47 success criterion 2 owns the three outcomes (fixed / not fixed
     * / deferred). A Phase 46 snag has no other state it can honestly be in:
     * it has been raised, and nothing has yet happened to it.
     */
    public function test_a_snag_has_exactly_one_status_in_this_phase(): void
    {
        $this->assertSame(['open'], Snag::STATUSES);
        $this->assertSame('open', Snag::STATUS_OPEN);
    }

    /**
     * THE SCOPE FENCE, asserted as data (T-46-02-03).
     *
     * D-03 is a decision that would otherwise have to be remembered. This
     * makes it enforceable: the day Phase 47 legitimately adds `outcome`, it
     * removes that entry from this list DELIBERATELY, in a commit that says
     * why — the same anti-rot discipline as the cockpit read-only fence.
     *
     * Do not delete this test. Shorten the list, one entry at a time.
     */
    public function test_the_snags_table_carries_no_phase_47_column(): void
    {
        $phase47Columns = [
            'outcome',        // Phase 47 criterion 2 — fixed / not fixed / deferred
            'parts',          // Phase 47 criterion 4 — parts tracked per snag
            'parent_snag_id', // Phase 47 criterion 3 — a not-fixed snag opens a linked one
            'resolved_at',    // Phase 47 — resolution is a lifecycle event, not a raise
            'assigned_to',    // Phase 47 criterion 5 — sitting with a client or third party
            'cost',           // deferred for the whole v4.0 milestone (visit costs)
        ];

        foreach ($phase47Columns as $column) {
            $this->assertFalse(
                Schema::hasColumn('snags', $column),
                "snags.{$column} belongs to Phase 47 (46-CONTEXT.md D-03). Phase 46 raises a "
                . 'snag; it does not manage one. If Phase 47 is now legitimately adding this '
                . 'column, remove it from this list deliberately, in a commit that says why.'
            );
        }
    }

    /**
     * The nine columns D-03 permits, and not one more. A tenth column is a
     * scope decision, so it must fail here first.
     */
    public function test_the_snags_table_carries_exactly_the_nine_permitted_columns(): void
    {
        $expected = [
            'created_at',
            'detail',
            'id',
            'project_id',
            'raised_by_user_id',
            'room_name',
            'status',
            'title',
            'updated_at',
            'visit_id',
        ];

        $actual = Schema::getColumnListing('snags');
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Nullable ON PURPOSE. Phase 47 criterion 1 needs a snag that may exist
     * with no visit attached; a NOT NULL column would have to be altered.
     */
    public function test_a_snag_may_exist_with_no_visit_attached(): void
    {
        $snag = Snag::create([
            'project_id' => Project::factory()->create()->id,
            'visit_id'   => null,
            'title'      => 'Trunking not made good in the comms room',
            'status'     => Snag::STATUS_OPEN,
        ]);

        $this->assertNull($snag->fresh()->visit_id);
        $this->assertNull($snag->fresh()->visit);
    }

    // Helpers ----------------------------------------------------------------

    /**
     * There is no SiteSurveyFactory in this repo; VisitTest builds one the
     * same way (tests/Unit/Models/VisitTest.php:212).
     */
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

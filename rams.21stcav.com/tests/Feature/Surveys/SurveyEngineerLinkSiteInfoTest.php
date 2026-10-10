<?php

namespace Tests\Feature\Surveys;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 46.8 Plan 04 — THE NON-VACUITY PROOF FOR D-01/D-07, closing the Wave 0 gap
 * 46.8-RESEARCH flagged: no existing test proved the RAMS-required badge or
 * the `general_notes` ("Other notes") round-trip, and no existing test proved
 * — by rendered output — that comms room and distance-from-base genuinely
 * left the engineer link's own capture surface rather than merely being
 * absent from one assertion somewhere.
 *
 * Every removal/addition/relabel below is proven against the REAL rendered
 * response of `GET /survey/{token}`, never inferred from a constant or a
 * controller's source alone.
 *
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class SurveyEngineerLinkSiteInfoTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $user = User::factory()->create();

        $project = Project::factory()->create([
            'user_id'      => $user->id,
            'name'         => 'Site Info Fixture',
            'site_address' => '1 Example Way, London',
        ]);

        // `owner()` is the model's relation name (there is no `user()`
        // relation on Project); set it explicitly rather than relying on
        // lazy-loading, which this codebase disables in tests.
        $project->setRelation('owner', $user);

        return $project;
    }

    // ── 1. THE FOUR REMOVALS, EACH ASSERTED SEPARATELY ──────────────────────

    /**
     * FOUR SEPARATE CALLS, NOT ONE COMBINED STRING. A combined check could
     * pass by accident if one literal substring were a prefix of another —
     * these four keys do not collide, but the separate-call discipline is
     * what makes a future field addition fail loudly instead of silently
     * widening an already-passing combined check.
     */
    public function test_comms_room_and_distance_from_base_are_absent_from_the_engineer_links_own_capture(): void
    {
        $project = $this->project();
        $survey  = app(SurveyService::class)->createFromProject($project, $project->owner);

        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('engineerFeedbackSite.comms_room_access_status', $html);
        $this->assertStringNotContainsString('engineerFeedbackSite.comms_room_access_notes', $html);
        $this->assertStringNotContainsString('engineerFeedbackSite.distance_from_base_miles', $html);
        $this->assertStringNotContainsString('engineerFeedbackSite.distance_from_base_notes', $html);
    }

    // ── 2. THE ADDITION + RELABEL, RENDERED ─────────────────────────────────

    public function test_other_notes_is_present_and_the_old_delivery_routes_label_text_is_gone(): void
    {
        $project = $this->project();
        $survey  = app(SurveyService::class)->createFromProject($project, $project->owner);

        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('engineerFeedbackSite.general_notes', $html);
        $this->assertStringContainsString('Site delivery info', $html);

        // The binding `delivery_routes` still appears (in `x-model`) — assert
        // against the LABEL TEXT specifically, not the attribute, which would
        // make this assertion vacuously true for the wrong reason.
        $this->assertStringNotContainsString('>Delivery Routes<', $html);
    }

    // ── 3. THE ROUND TRIP, THROUGH THE REAL DATABASE ────────────────────────

    /**
     * A REAL ROUND TRIP: POST through `survey.step.save` step 0, then
     * re-fetch `survey.show` for the SAME token and prove the typed value is
     * in the RE-RENDERED seed — not merely in the same-request Alpine state,
     * which would prove nothing about persistence.
     */
    public function test_other_notes_round_trips_through_step_save_and_a_fresh_fetch(): void
    {
        $project = $this->project();
        $survey  = app(SurveyService::class)->createFromProject($project, $project->owner);

        $this->post(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 0,
            'data'       => [
                'engineer_feedback_site' => [
                    'general_notes' => 'SENTINEL-OTHER-NOTES-ROUND-TRIP',
                ],
            ],
        ])->assertOk();

        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('SENTINEL-OTHER-NOTES-ROUND-TRIP', $html);
    }

    // ── 4. THE RAMS-REQUIRED BADGE, ALL THREE STATES + THE NO-ROW CASE ──────

    /**
     * THREE DIFFERENT RENDERED STRINGS, one per `ProjectDeliverable::STATE_*`
     * value, never blank and never the same string twice. Each state is set
     * through a FRESH project/survey pair so the three fetches cannot bleed
     * into one another via a shared eager-loaded instance.
     */
    public function test_rams_required_badge_renders_a_distinct_string_for_each_of_the_three_states(): void
    {
        $rendered = [];

        foreach ([
            ProjectDeliverable::STATE_REQUIRED,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            ProjectDeliverable::STATE_NOT_YET_DECIDED,
        ] as $state) {
            $project = $this->project();

            ProjectDeliverable::updateOrCreate(
                ['project_id' => $project->id, 'deliverable_key' => ProjectDeliverable::KEY_RAMS],
                ['state' => $state],
            );

            $owner  = $project->owner;
            $survey = app(SurveyService::class)->createFromProject($project->fresh(), $owner);

            $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
                ->assertOk()
                ->getContent();

            $this->assertNotSame('', trim($this->ramsBadgeText($html)), "State [{$state}] rendered a blank RAMS badge.");

            $rendered[$state] = $this->ramsBadgeText($html);
        }

        $this->assertCount(
            3,
            array_unique($rendered),
            'Two or more of the three states rendered the SAME string — they must each be distinct: '
            .json_encode($rendered),
        );
    }

    /**
     * NO ROW AT ALL — the `STATE_NOT_YET_DECIDED` default must still render a
     * real indicator, not a blank gap and not a 500.
     */
    public function test_rams_required_badge_renders_the_not_yet_decided_default_when_no_deliverable_row_exists(): void
    {
        $project = $this->project();
        $owner   = $project->owner;
        $survey  = app(SurveyService::class)->createFromProject($project->fresh(), $owner);

        $this->assertSame(
            0,
            ProjectDeliverable::where('project_id', $project->id)
                ->where('deliverable_key', ProjectDeliverable::KEY_RAMS)
                ->count(),
            'This test is only meaningful with genuinely no row — the fixture created one by accident.',
        );

        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertNotSame('', trim($this->ramsBadgeText($html)), 'No-row case rendered a blank RAMS badge.');
        $this->assertStringContainsString('not yet decided', $this->ramsBadgeText($html));
    }

    /** Isolates the amber RAMS-required badge's own text from the rest of the page. */
    private function ramsBadgeText(string $html): string
    {
        $needlePos = strpos($html, 'bg-amber-50 border border-amber-200 rounded-2xl p-3 text-xs text-amber-800 font-semibold');
        $this->assertNotFalse($needlePos, 'RAMS-required badge markup not found.');

        $openPos  = strpos($html, '>', $needlePos) + 1;
        $closePos = strpos($html, '</div>', $openPos);

        return trim(substr($html, $openPos, $closePos - $openPos));
    }
}

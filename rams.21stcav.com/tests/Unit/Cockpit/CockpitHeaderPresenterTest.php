<?php

namespace Tests\Unit\Cockpit;

use App\DTO\ProjectHealth;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Support\Cockpit\CockpitHeaderPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitSectionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 45, Plan 45-10, Task 2 — the cockpit page header.
 *
 * Three of the values the design asks for HAVE NO SOURCE in this codebase.
 * Each of the three is pinned here by a test asserting its ABSENCE, so that a
 * later agent has to delete a named, reasoned test before it can wire a
 * plausible-looking substitute:
 *
 *   1. Site contact EMAIL — `SiteSurvey` has `site_contact_name` and
 *      `site_contact_phone` only. `pm_email` is the PROJECT MANAGER's address.
 *      Rendering it under "Site contact" would be a lie.
 *   2. "Proposed install date" — no such field. The nearest value is
 *      `InstallProgramme::planned_start_date`, which is a different fact and is
 *      rendered only under its own honest label, "Planned start".
 *   3. The SECOND stage chip — the design shows two side by side, but a project
 *      has exactly one `Project::status` and no second source exists.
 *
 * The documents KPI's denominator is the fourth trap: a hardcoded 9 above a
 * list that later changes length would misreport delivery progress to a PM.
 * `test_the_documents_denominator_is_the_rendered_module_count()` pins it to
 * `CockpitModulePresenter::modules()->count()`.
 */
class CockpitHeaderPresenterTest extends TestCase
{
    use RefreshDatabase;

    private function presenter(): CockpitHeaderPresenter
    {
        return new CockpitHeaderPresenter(
            new CockpitModulePresenter(new CockpitSectionPresenter())
        );
    }

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Header Test Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function survey(Project $project, array $overrides = []): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => 'Header Test Job',
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'completed',
        ], $overrides));
    }

    // -- Masthead ------------------------------------------------------------

    public function test_masthead_omits_keys_whose_source_is_absent(): void
    {
        $masthead = $this->presenter()->masthead($this->project(['ref' => null]));

        $this->assertArrayNotHasKey('ref', $masthead);
        $this->assertArrayNotHasKey('contact_name', $masthead);
        $this->assertArrayNotHasKey('contact_phone', $masthead);
        $this->assertArrayNotHasKey('planned_start', $masthead);
    }

    public function test_masthead_never_carries_a_null_or_empty_value(): void
    {
        $masthead = $this->presenter()->masthead($this->project(['ref' => null]));

        foreach ($masthead as $key => $value) {
            $this->assertNotNull($value, "Masthead key {$key} is present but null.");
            $this->assertNotSame('', $value, "Masthead key {$key} is present but empty.");
        }
    }

    public function test_masthead_carries_the_facts_that_do_exist(): void
    {
        $project = $this->project([
            'ref'          => 'P-2026-044',
            'site_address' => '1 Example Way, London',
        ]);

        $this->survey($project, [
            'site_contact_name'  => 'Dana Holt',
            'site_contact_phone' => '020 7946 0000',
        ]);

        InstallProgramme::factory()->create([
            'project_id'         => $project->id,
            'planned_start_date' => '2026-10-05',
        ]);

        $masthead = $this->presenter()->masthead($project->fresh());

        $this->assertSame('P-2026-044', $masthead['ref']);
        $this->assertSame('1 Example Way, London', $masthead['site_address']);
        $this->assertSame('Dana Holt', $masthead['contact_name']);
        $this->assertSame('020 7946 0000', $masthead['contact_phone']);
        $this->assertSame('2026-10-05', $masthead['planned_start']->toDateString());
    }

    // -- The three sourceless values -----------------------------------------

    public function test_no_contact_email_key_exists_in_any_project_state(): void
    {
        $bare = $this->presenter()->masthead($this->project());
        $this->assertArrayNotHasKey('contact_email', $bare);

        $project = $this->project();
        $this->survey($project, [
            'site_contact_name' => 'Dana Holt',
            'pm_email'          => 'pm@example.com',
        ]);

        $full = $this->presenter()->masthead($project->fresh());

        $this->assertArrayNotHasKey('contact_email', $full);
        $this->assertNotContains(
            'pm@example.com',
            $full,
            "pm_email is the project manager's address and must never surface under a site-contact label."
        );
    }

    public function test_the_phrase_proposed_install_date_appears_nowhere(): void
    {
        $project = $this->project();
        InstallProgramme::factory()->create([
            'project_id'         => $project->id,
            'planned_start_date' => '2026-10-05',
        ]);

        $presenter = $this->presenter();
        $fresh     = $project->fresh();

        $dump = json_encode([
            $presenter->masthead($fresh),
            $presenter->kpis($fresh, null),
            $presenter->stageChips($fresh),
        ]);

        $this->assertStringNotContainsStringIgnoringCase('Proposed install date', (string) $dump);
        $this->assertStringNotContainsStringIgnoringCase('Proposed install', (string) $dump);
    }

    public function test_exactly_one_stage_chip_is_returned_for_every_project_status(): void
    {
        foreach (array_keys(Project::STATUS_LABELS) as $status) {
            $chips = $this->presenter()->stageChips($this->project(['status' => $status]));

            $this->assertCount(
                1,
                $chips,
                "A project has exactly one status, so status '{$status}' must yield exactly one chip."
            );
        }
    }

    public function test_the_stage_chip_reads_as_english_not_a_raw_enum(): void
    {
        $chips = $this->presenter()->stageChips($this->project(['status' => Project::STATUS_SURVEY_PENDING]));

        $this->assertSame('Survey pending', $chips[0]['label']);
        $this->assertSame(Project::STATUS_SURVEY_PENDING, $chips[0]['key']);

        $installing = $this->presenter()->stageChips($this->project(['status' => Project::STATUS_INSTALLING]));

        $this->assertSame('Installation', $installing[0]['label']);
    }

    public function test_an_unmapped_status_yields_no_chip_rather_than_a_raw_enum(): void
    {
        $project = $this->project();
        DB::table('projects')->where('id', $project->id)->update(['status' => 'not_a_real_status']);

        $chips = $this->presenter()->stageChips($project->fresh());

        $this->assertSame([], $chips);
    }

    // -- KPI cards -----------------------------------------------------------

    public function test_the_overall_card_reports_the_derived_health(): void
    {
        $project = $this->project(['status' => Project::STATUS_INSTALLING]);
        $health  = new ProjectHealth('green', 'On track', false);

        $card = $this->presenter()->kpis($project, $health)['overall'];

        $this->assertSame('green', $card['status']);
        $this->assertSame('On track', $card['reason']);
        $this->assertFalse($card['overdue']);
        $this->assertSame('Installation', $card['stage']);
    }

    public function test_the_overall_card_never_falls_back_to_on_track_when_health_is_absent(): void
    {
        $card = $this->presenter()->kpis($this->project(), null)['overall'];

        $this->assertNull($card['status']);
        $this->assertStringNotContainsStringIgnoringCase('on track', $card['reason']);
        $this->assertStringContainsStringIgnoringCase('could not be read', $card['reason']);
    }

    public function test_next_visit_reads_none_planned_when_nothing_is_planned(): void
    {
        $project = $this->project();
        Visit::factory()->create([
            'project_id'     => $project->id,
            'status'         => Visit::STATUS_COMPLETED,
            'scheduled_date' => '2026-01-04',
        ]);

        $card = $this->presenter()->kpis($project->fresh(), null)['next_visit'];

        $this->assertFalse($card['planned']);
        $this->assertSame('None planned', $card['label']);
        $this->assertArrayNotHasKey('date', $card, 'A past visit must never be shown as the next one.');
    }

    public function test_next_visit_is_the_earliest_planned_visit_with_a_date(): void
    {
        $project = $this->project();

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'status'         => Visit::STATUS_PLANNED,
            'scheduled_date' => '2026-11-20',
        ]);
        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_SNAG,
            'status'         => Visit::STATUS_PLANNED,
            'scheduled_date' => '2026-10-02',
        ]);
        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_SITE_SURVEY,
            'status'         => Visit::STATUS_PLANNED,
            'scheduled_date' => null,
        ]);

        $card = $this->presenter()->kpis($project->fresh(), null)['next_visit'];

        $this->assertTrue($card['planned']);
        $this->assertSame('2026-10-02', $card['date']->toDateString());
        $this->assertSame('Snagging', $card['type_label']);
    }

    public function test_the_documents_denominator_is_the_rendered_module_count(): void
    {
        $project = $this->project();
        $modules = new CockpitModulePresenter(new CockpitSectionPresenter());

        $card = $this->presenter()->kpis($project->fresh(), null)['documents'];

        $this->assertSame(
            $modules->modules($project->fresh())->count(),
            $card['total'],
            'The denominator must be the number of module rows actually rendered, never a literal.'
        );
    }

    public function test_the_documents_numerator_counts_on_file_rows(): void
    {
        $project = $this->project();

        $this->assertSame(0, $this->presenter()->kpis($project, null)['documents']['complete']);

        RamsDocument::factory()->create(['project_id' => $project->id]);

        $card = $this->presenter()->kpis($project->fresh(), null)['documents'];

        $this->assertSame(1, $card['complete']);
        $this->assertSame(9, $card['total']);
        $this->assertSame(11, $card['percent'], 'Percent is integer-floored: floor(1/9*100) = 11.');
    }

    public function test_the_documents_percent_is_zero_rather_than_a_division_error_when_empty(): void
    {
        $card = $this->presenter()->kpis($this->project(), null)['documents'];

        $this->assertSame(0, $card['percent']);
    }

    // -- Read-only fence -----------------------------------------------------

    public function test_deriving_the_header_writes_nothing(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_contact_name' => 'Dana Holt']);
        Visit::factory()->create(['project_id' => $project->id, 'status' => Visit::STATUS_PLANNED]);

        $tables = ['visits', 'site_surveys', 'worksheets', 'install_programmes', 'install_records', 'projects'];
        $before = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $presenter = $this->presenter();
        $presenter->masthead($project->fresh());
        $presenter->kpis($project->fresh(), null);
        $presenter->stageChips($project->fresh());

        $after = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->assertSame($before, $after, 'Deriving the header must write nothing.');
    }
}

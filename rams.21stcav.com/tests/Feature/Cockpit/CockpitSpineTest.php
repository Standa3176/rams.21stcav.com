<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 45, plan 45-07 — the spine: nine drawers closed at rest, the visit
 * rows inside them, and the two qualifier treatments that make the record
 * honest.
 *
 * The load-bearing assertions here are the AT-REST disclosures. Drawers are
 * closed at rest, so the summary count slot is the only place a reconstructed
 * (D-02) or superseded (D-04) visit is visible to a PM who opens nothing. A
 * cockpit that only admits to reconstruction once a drawer is open misleads by
 * omission, which is the precise failure D-02 exists to prevent — so these
 * tests read the <summary> markup specifically, not just the page.
 *
 * Assertions run against the extracted cav-cockpit subtree for the same reason
 * CockpitPageTest does: the shared app layout carries a logout form, a
 * command-palette input, nav buttons and Vite/Alpine scripts on every
 * authenticated page, and none of that is this phase's markup.
 */
class CockpitSpineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The nine drawer titles, in render order. Enumerated as data so a future
     * phase that renames or drops one has to change this list deliberately.
     *
     * @var array<int, string>
     */
    private const NINE_SECTIONS = [
        'Site survey',
        'First fix and install',
        'Programme and commissioning',
        'Snagging',
        'RAMS',
        'Drawings',
        'O&M manual',
        'Cable schedule',
        'Programming',
    ];

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Spine Test Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    /**
     * Same DOM extraction as CockpitPageTest: judge only the markup this phase
     * authors, never the shared layout's chrome.
     */
    private function cockpitSubtree(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found in the response.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function render(Project $project): string
    {
        config(['cockpit.enabled' => true]);

        return $this->cockpitSubtree(
            $this->actingAs(User::factory()->create())
                ->get(route('projects.cockpit', $project))
                ->assertOk()
                ->getContent()
        );
    }

    /**
     * Everything between <summary> and </summary>, concatenated. This is what a
     * PM sees with every drawer shut.
     */
    private function summariesOnly(string $html): string
    {
        preg_match_all('/<summary\b.*?<\/summary>/s', $html, $matches);

        $this->assertNotEmpty($matches[0], 'No drawer summaries were found — the spine did not render.');

        return implode("\n", $matches[0]);
    }

    // -- The spine ---------------------------------------------------------

    public function test_all_nine_sections_render(): void
    {
        $html = $this->render($this->project());

        foreach (self::NINE_SECTIONS as $title) {
            $this->assertStringContainsString(
                '>'.$title.'<',
                $html,
                "Section \"{$title}\" must render. A missing drawer reads as an app fault."
            );
        }

        $this->assertSame(
            9,
            substr_count($html, '<details'),
            'Exactly nine drawers make up the spine.'
        );
    }

    public function test_a_not_required_section_still_renders(): void
    {
        $project = $this->project();

        ProjectDeliverable::create([
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_CABLE_SCHEDULE,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        $html = $this->render($project);

        $this->assertStringContainsString('>Cable schedule<', $html);
        $this->assertStringContainsString('Not required', $html);
        $this->assertStringContainsString('Marked not required at import.', $html);
        $this->assertSame(9, substr_count($html, '<details'));
    }

    public function test_no_drawer_ships_the_open_attribute(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id]);

        $html = $this->render($project);

        preg_match_all('/<details\b[^>]*>/', $html, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertDoesNotMatchRegularExpression(
                '/\sopen(\s|=|>)/',
                $tag,
                "Drawers are closed at rest — this one ships `open`: {$tag}"
            );
        }
    }

    // -- Reconstructed (D-02) ----------------------------------------------

    public function test_a_reconstructed_visit_is_disclosed_in_the_closed_summary(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $summaries = $this->summariesOnly($this->render($project));

        // Singular form drops the numeral.
        $this->assertStringContainsString(
            '1 visit · reconstructed',
            $summaries,
            'The at-rest disclosure D-02 depends on must appear in the summary count slot.'
        );
    }

    public function test_two_reconstructed_visits_are_counted_in_the_summary(): void
    {
        $project = $this->project();

        // A unique index on (source_type, source_id) means one source backs at
        // most one visit — reconstruct from two worksheets, not one twice.
        foreach (range(1, 2) as $n) {
            $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

            Visit::factory()->backfilledFromWorksheet($worksheet)->create([
                'project_id'     => $project->id,
                'scheduled_date' => '2026-09-0'.$n,
            ]);
        }

        Visit::factory()->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-04',
        ]);

        $summaries = $this->summariesOnly($this->render($project));

        $this->assertStringContainsString('3 visits · 2 reconstructed', $summaries);
    }

    public function test_a_worksheet_derived_row_carries_all_four_reconstructed_treatments(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $html = $this->render($project);

        // 1. the chip, dashed by its own modifier class
        $this->assertStringContainsString('cav-chip cav-chip--reconstructed', $html);
        $this->assertStringContainsString('Reconstructed', $html);

        // 2. the dashed left edge
        $this->assertStringContainsString('cav-visit cav-visit--reconstructed', $html);

        // 3. the sub-line, verbatim
        $this->assertStringContainsString(
            'Type inferred from a signed worksheet — the work actually done was not recorded.',
            $html
        );

        // 4. the dotted-underlined type word, described by that sub-line
        $this->assertStringContainsString('cav-inferred-type', $html);
        $this->assertStringContainsString('aria-describedby="cav-visit-'.$visit->id.'-inferred"', $html);
        $this->assertStringContainsString('id="cav-visit-'.$visit->id.'-inferred"', $html);
    }

    public function test_a_survey_derived_row_gets_its_own_copy_and_no_dotted_type_word(): void
    {
        $project = $this->project();

        Visit::factory()->backfilledFromSurvey()->create([
            'project_id'     => $project->id,
            'title'          => 'Site survey',
            'scheduled_date' => '2026-08-11',
        ]);

        $html = $this->render($project);

        $this->assertStringContainsString(
            'Built from the existing survey record, not captured as a visit.',
            $html
        );
        $this->assertStringNotContainsString(
            'Type inferred from a signed worksheet',
            $html
        );
        $this->assertStringNotContainsString(
            'cav-inferred-type',
            $html,
            "A survey-derived visit's type is not in doubt, only its provenance."
        );
    }

    // -- Superseded (D-04) -------------------------------------------------

    public function test_a_superseded_visit_is_disclosed_in_the_closed_summary(): void
    {
        $project = $this->project();

        $this->supersededVisit($project, 'Install day one', '2026-09-02');

        $summaries = $this->summariesOnly($this->render($project));

        $this->assertStringContainsString('1 visit · superseded', $summaries);
    }

    public function test_a_superseded_visit_keeps_its_place_in_date_order(): void
    {
        $project = $this->project();

        $this->supersededVisit($project, 'Install day one', '2026-09-02');

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day two',
            'scheduled_date' => '2026-09-03',
        ]);

        $html = $this->render($project);

        $superseded = strpos($html, 'Install day one');
        $later      = strpos($html, 'Install day two');

        $this->assertNotFalse($superseded, 'A superseded visit is never hidden — D-04.');
        $this->assertNotFalse($later);
        $this->assertLessThan(
            $later,
            $superseded,
            'A superseded visit keeps its date-order position; it is never pushed to the end.'
        );
    }

    public function test_superseded_strikethrough_is_scoped_to_the_name_and_carries_no_opacity(): void
    {
        $project = $this->project();

        $this->supersededVisit($project, 'Install day one', '2026-09-02');

        $html = $this->render($project);

        $this->assertStringContainsString(
            '<span class="cav-superseded-name">Install day one</span>',
            $html,
            'line-through is scoped to the deliverable name only — never the whole row.'
        );
        $this->assertStringContainsString('the visit still happened.', $html);
        $this->assertStringNotContainsString(
            'opacity',
            $html,
            'Opacity may never be applied to an element containing small text.'
        );
    }

    public function test_a_row_may_carry_both_chips_reconstructed_first(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $id        = $worksheet->id;
        $worksheet->delete();

        Visit::factory()->backfilledFromWorksheet()->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
            'source_id'      => $id,
        ]);

        $html = $this->render($project);

        $reconstructed = strpos($html, '>Reconstructed<');
        $superseded    = strpos($html, '>Superseded<');

        $this->assertNotFalse($reconstructed);
        $this->assertNotFalse($superseded);
        $this->assertLessThan($superseded, $reconstructed, 'Reconstructed sits first in the chip strip.');
    }

    // -- Neither qualifier is ever actionable ------------------------------

    public function test_a_qualifier_never_drives_a_drawer_to_attention(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-02',
        ]);
        $this->supersededVisit($project, 'Install day two', '2026-09-03');

        $html = $this->render($project);

        $this->assertStringNotContainsString(
            'cav-pip--attn',
            $html,
            'Gold means someone must act, and a qualifier cannot be actioned on a read-only page.'
        );
        $this->assertStringNotContainsString('cav-status--attn', $html);
        $this->assertStringContainsString('Nothing needs you on this job', $html);
        $this->assertStringNotContainsString('reconstructed</em>', $html);
        $this->assertStringNotContainsString('superseded</em>', $html);
    }

    // -- Programming: the hand-ticked box, unticked only -------------------

    public function test_programming_renders_the_unticked_box_and_not_marked(): void
    {
        $html = $this->render($this->project());

        $this->assertStringContainsString('cav-tick', $html);
        $this->assertStringContainsString('aria-label="Not marked"', $html);
        $this->assertStringContainsString('Not marked', $html);

        // In this phase no data can produce the ticked branch.
        $this->assertStringNotContainsString('cav-tick--on', $html);
        $this->assertStringNotContainsString('Marked done by hand', $html);
        $this->assertStringNotContainsString('Ticked by', $html);
    }

    // -- Empty project ------------------------------------------------------

    public function test_an_empty_project_still_renders_nine_waiting_drawers(): void
    {
        $html = $this->render($this->project());

        $this->assertSame(9, substr_count($html, '<details'));
        $this->assertSame(
            8,
            substr_count($html, 'cav-pip--wait'),
            'Eight lights all waiting; the ninth section carries a box, not a light.'
        );
        $this->assertStringNotContainsString('cav-pip--done', $html);
        $this->assertStringNotContainsString('cav-pip--attn', $html);

        $this->assertStringContainsString('Nothing has been recorded on this job yet', $html);
        $this->assertStringContainsString(
            'This cockpit reads records the app already holds. When a site survey is',
            $html
        );
        $this->assertSame(9, substr_count($html, 'none yet'));
    }

    // -- A broken source must not remove a visit from the spine ------------

    public function test_a_force_deleted_source_still_renders_the_visit(): void
    {
        $project = $this->project();

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => 987654,
        ]);

        $html = $this->render($project);

        $this->assertStringContainsString('Install day one', $html);
        $this->assertStringContainsString('Record unavailable', $html);
        $this->assertStringContainsString(
            'The visit is recorded; the document behind it could not be read.',
            $html
        );
        $this->assertStringContainsString('1 visit', $this->summariesOnly($html));
    }

    // -- Helper -------------------------------------------------------------

    /**
     * A visit whose wrapped worksheet has been soft-deleted — Visit::isSuperseded()
     * derives from the source's superseded_at / deleted_at at read time.
     */
    private function supersededVisit(Project $project, string $title, string $date): Visit
    {
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $id        = $worksheet->id;
        $worksheet->delete();

        return Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => $title,
            'scheduled_date' => $date,
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $id,
        ]);
    }
}

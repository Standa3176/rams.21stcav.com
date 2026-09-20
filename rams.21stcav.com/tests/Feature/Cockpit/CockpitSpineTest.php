<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 45 — the module rows and the side panel's Overview tab: every module
 * row rendered at rest, the visit rows inside the panel, and the two qualifier
 * treatments that make the record honest.
 *
 * THE FILE KEEPS ITS NAME ON PURPOSE. Written by Plan 45-07 against the
 * accordion "spine" of nine <details> drawers, it was retargeted by Plan 45-13
 * when sketch 004 replaced that accordion with module rows and a URL-state side
 * panel. Renaming the file would have detached its git history from the
 * assertions it has always carried — and those assertions, not the markup they
 * happened to read, are the point. The word "spine" now means the row list.
 *
 * THE LOAD-BEARING ASSERTIONS ARE STILL THE AT-REST DISCLOSURES, AND THEY MOVED
 * RATHER THAN DIED. A PM who opens nothing must still learn that a visit is
 * reconstructed (D-02) or superseded (D-04). The accordion's <summary> count
 * slot used to carry that; the module row's COUNT PHRASE carries it now —
 * "1 visit · reconstructed", "3 visits · 2 reconstructed", "2 visits ·
 * superseded". When these tests were retargeted the phrase did NOT disclose
 * the qualifiers, because Plan 45-10's row re-counted visits from scratch.
 * That was treated as the defect it was and fixed in the presenter; the
 * assertions were not softened to match the page. A cockpit that only admits
 * to an inference once a panel is open misleads by omission, which is the
 * precise failure D-02 exists to prevent.
 *
 * The four treatments of a reconstructed visit and the superseded treatment
 * are unchanged and still proven — they moved into the panel's Overview tab,
 * which renders the SAME x-cockpit.visit-row component the drawers used, so
 * these tests open the panel with `?module={key}` and assert exactly what they
 * always did.
 *
 * Assertions run against the extracted cav-cockpit subtree for the same reason
 * CockpitPageTest's do: the shared app layout carries a logout form, a
 * command-palette input, nav buttons and Vite/Alpine scripts on every
 * authenticated page, and none of that is this phase's markup.
 */
class CockpitSpineTest extends TestCase
{
    use RefreshDatabase;

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
        return $this->dom($html)['html'];
    }

    /**
     * @return array{html: string, dom: \DOMDocument, node: \DOMElement}
     */
    private function dom(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found in the response.');

        return [
            'html' => html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'dom'  => $dom,
            'node' => $node,
        ];
    }

    /**
     * @param  array<string, string>  $query  `?module=` opens the side panel.
     */
    private function render(Project $project, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        return $this->cockpitSubtree(
            $this->actingAs(User::factory()->create())
                ->get(route('projects.cockpit', ['project' => $project] + $query))
                ->assertOk()
                ->getContent()
        );
    }

    /**
     * The panel's markup, opened at one module. The Overview tab is the
     * default, and it is where the visit rows live.
     */
    private function panel(Project $project, string $moduleKey): string
    {
        return $this->render($project, ['module' => $moduleKey]);
    }

    /**
     * THE NEW AT-REST SLOT. Everything a module row says about its visits when
     * nothing is open: the text of its `cav-module__count` element, or the
     * empty string when it renders none.
     *
     * Read out of the DOM for the row whose title matches, rather than by
     * searching the whole page for a phrase — so a count belonging to a
     * different module can never satisfy an assertion about this one.
     */
    private function rowCount(string $subtree, string $title): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$subtree);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $rows  = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module ')]");

        foreach ($rows as $row) {
            $rowTitle = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module__title ')]", $row)->item(0);

            if ($rowTitle === null || trim($rowTitle->textContent) !== $title) {
                continue;
            }

            $count = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module__count ')]", $row)->item(0);

            return $count === null ? '' : trim($count->textContent);
        }

        $this->fail("No module row titled \"{$title}\" was rendered.");
    }

    /**
     * The status chip's variant class for one module row, so two renders can
     * be compared for "did this change the row's state?".
     */
    private function rowChip(string $subtree, string $title): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$subtree);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $rows  = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module ')]");

        foreach ($rows as $row) {
            $rowTitle = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module__title ')]", $row)->item(0);

            if ($rowTitle === null || trim($rowTitle->textContent) !== $title) {
                continue;
            }

            $chip = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-schip ')]", $row)->item(0);

            $this->assertNotNull($chip, "The \"{$title}\" row rendered no status chip.");

            return $chip->getAttribute('class').'|'.trim($chip->textContent);
        }

        $this->fail("No module row titled \"{$title}\" was rendered.");
    }

    private function countByClass(string $subtree, string $class): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$subtree);
        libxml_clear_errors();

        return (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->length;
    }

    /**
     * @return array<int, string>
     */
    private function moduleTitles(): array
    {
        return array_column(CockpitModulePresenter::moduleMap(), 'title');
    }

    // -- The row list -------------------------------------------------------

    /**
     * Retargeted from `test_all_nine_sections_render`. Same subject — every
     * module the app knows about reaches the page, because a missing one reads
     * as an app fault — counted against the presenter's own map rather than a
     * literal 9, so the D-16 ruling can be revisited without this test lying.
     */
    public function test_every_module_renders_a_row(): void
    {
        $html = $this->render($this->project());

        foreach ($this->moduleTitles() as $title) {
            $this->assertStringContainsString(
                '>'.$title.'<',
                $html,
                "Module \"{$title}\" must render. A missing row reads as an app fault."
            );
        }

        $this->assertSame(
            count(CockpitModulePresenter::moduleMap()),
            $this->countByClass($html, 'cav-module'),
            'Every module the presenter returns renders exactly one row.'
        );
    }

    public function test_a_not_required_module_still_renders_its_row(): void
    {
        $project = $this->project();

        ProjectDeliverable::create([
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_CABLE_SCHEDULE,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        $html = $this->render($project);

        $this->assertStringContainsString('>Cable schedule<', $html);

        // It recedes by its words, not by disappearing: the row says why its
        // count is empty instead of showing a zero that looks like neglect.
        $this->assertSame('Not required', $this->rowCount($html, 'Cable schedule'));
        $this->assertSame(
            count(CockpitModulePresenter::moduleMap()),
            $this->countByClass($html, 'cav-module')
        );
    }

    /**
     * RETIRED: `test_no_drawer_ships_the_open_attribute`. Its subject was
     * "closed at rest" and it read that off a <details> element; sketch 004
     * ships no <details> at all, so the assertion had nothing left to read.
     * The property is REHOMED here in the new and stricter form D-09 asks for:
     * closed at rest means ABSENT from the DOM, not hidden — a hidden panel
     * would still be read out by a screen reader.
     */
    public function test_no_panel_element_exists_in_the_dom_at_rest(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id]);

        $html = $this->render($project);

        $this->assertSame(0, $this->countByClass($html, 'cav-panel'), 'The panel is absent until ?module= opens it.');
        $this->assertStringNotContainsString('<details', $html, 'The accordion is gone; nothing may reintroduce one.');
        $this->assertStringNotContainsString('<summary', $html);

        // And it really does open — otherwise the assertion above would be
        // satisfied by a panel that never renders at all.
        $this->assertSame(
            1,
            $this->countByClass($this->panel($project, ProjectDeliverable::KEY_WORKSHEET), 'cav-panel')
        );
    }

    // -- Reconstructed (D-02), disclosed AT REST ---------------------------

    /**
     * The accordion's <summary> count slot is gone. The module row's count
     * phrase is the slot now, and it must carry the qualifier: a PM who opens
     * nothing still learns that this visit's type was inferred.
     */
    public function test_a_reconstructed_visit_is_disclosed_in_the_module_row_count_phrase(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        // Singular drops the numeral.
        $this->assertSame(
            '1 visit · reconstructed',
            $this->rowCount($this->render($project), 'First fix and install'),
            'The at-rest disclosure D-02 depends on must appear in the row count phrase.'
        );
    }

    public function test_two_reconstructed_visits_are_counted_in_the_module_row_count_phrase(): void
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

        $this->assertSame(
            '3 visits · 2 reconstructed',
            $this->rowCount($this->render($project), 'First fix and install')
        );
    }

    // -- Reconstructed (D-02), the four treatments, now in the panel -------

    public function test_a_worksheet_derived_row_carries_all_four_reconstructed_treatments(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET);

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

        $html = $this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY);

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

    public function test_a_superseded_visit_is_disclosed_in_the_module_row_count_phrase(): void
    {
        $project = $this->project();

        $this->supersededVisit($project, 'Install day one', '2026-09-02');

        $this->assertSame(
            '1 visit · superseded',
            $this->rowCount($this->render($project), 'First fix and install')
        );
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

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET);

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

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET);

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

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET);

        $reconstructed = strpos($html, '>Reconstructed<');
        $superseded    = strpos($html, '>Superseded<');

        $this->assertNotFalse($reconstructed);
        $this->assertNotFalse($superseded);
        $this->assertLessThan($superseded, $reconstructed, 'Reconstructed sits first in the chip strip.');

        // And both are disclosed at rest, in the order the chips use.
        $this->assertSame(
            '1 visit · reconstructed · superseded',
            $this->rowCount($this->render($project), 'First fix and install')
        );
    }

    // -- Neither qualifier is ever actionable ------------------------------

    /**
     * Retargeted from `test_a_qualifier_never_drives_a_drawer_to_attention`.
     * The drawer pip is gone; the module row's status chip is what carries a
     * module's state now, and the page's one attention-shaped element is the
     * Overall status KPI card.
     *
     * Asserted COMPARATIVELY rather than against a literal status word: two
     * projects built identically except that one's visits carry qualifiers,
     * and the row chip and the status card must be the same in both. That is
     * the real property — a qualifier changes what the page DISCLOSES and
     * never what it ASKS FOR — and it cannot be satisfied by a page that
     * happens to be quiet for some other reason.
     */
    public function test_a_qualifier_never_drives_a_module_to_attention(): void
    {
        $plain = $this->project();
        Worksheet::factory()->create(['project_id' => $plain->id]);
        $plainGone = Worksheet::factory()->create(['project_id' => $plain->id]);
        $plainGone->delete();
        Visit::factory()->create(['project_id' => $plain->id, 'scheduled_date' => '2026-09-02']);
        Visit::factory()->create(['project_id' => $plain->id, 'scheduled_date' => '2026-09-03']);

        $qualified = $this->project();
        $source    = Worksheet::factory()->create(['project_id' => $qualified->id]);
        Visit::factory()->backfilledFromWorksheet($source)->create([
            'project_id'     => $qualified->id,
            'scheduled_date' => '2026-09-02',
        ]);
        $this->supersededVisit($qualified, 'Install day two', '2026-09-03');

        $plainHtml     = $this->render($plain);
        $qualifiedHtml = $this->render($qualified);

        $this->assertSame(
            $this->rowChip($plainHtml, 'First fix and install'),
            $this->rowChip($qualifiedHtml, 'First fix and install'),
            'Gold means someone must act, and a qualifier cannot be actioned on a read-only page.'
        );

        $this->assertSame(
            $this->statusCardValue($plainHtml),
            $this->statusCardValue($qualifiedHtml),
            'A qualifier must not move the Overall status card either.'
        );

        // The two counts differ — which is the point: the disclosure changed,
        // the demand for attention did not.
        $this->assertSame('2 visits', $this->rowCount($plainHtml, 'First fix and install'));
        $this->assertSame(
            '2 visits · reconstructed · superseded',
            $this->rowCount($qualifiedHtml, 'First fix and install')
        );

        // Dead attention classes from the accordion, kept as a regression
        // guard: nothing may reintroduce them to mark a qualifier.
        $this->assertStringNotContainsString('cav-pip--attn', $qualifiedHtml);
        $this->assertStringNotContainsString('cav-status--attn', $qualifiedHtml);

        // Nor may a qualifier be named inside a gold <em> rule, which is what
        // the page uses to say "this is waiting on you".
        $this->assertStringNotContainsString('reconstructed</em>', $qualifiedHtml);
        $this->assertStringNotContainsString('superseded</em>', $qualifiedHtml);
    }

    /**
     * The Overall status KPI card's value — the one attention-shaped statement
     * left on the page after sketch 004 folded the attention line into it.
     */
    private function statusCardValue(string $subtree): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$subtree);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-kpi ')]");

        $this->assertGreaterThan(0, $cards->length, 'No KPI card rendered.');

        return trim($cards->item(0)->textContent);
    }

    // -- Programming claims no completion it cannot evidence ---------------

    /**
     * RETIRED: `test_programming_renders_the_unticked_box_and_not_marked`. The
     * tick box component is deleted and sketch 004 draws no box, so the
     * assertion had no subject left. What it really protected was this:
     * NOTHING ON THIS PAGE CLAIMS A COMPLETION THIS PHASE CANNOT EVIDENCE.
     * Programming has no model, table or relation anywhere in this codebase
     * (ProjectHealthService.php:105-107), so its row renders a chip and no
     * count phrase at all — "0 files" would claim a file store exists, and a
     * ticked box would claim someone marked it done with nowhere to record who
     * or when.
     */
    public function test_programming_claims_no_completion_it_cannot_evidence(): void
    {
        $project = $this->project();
        $html    = $this->render($project);

        $this->assertStringContainsString('>Programming<', $html);
        $this->assertSame('', $this->rowCount($html, 'Programming'), 'Programming renders no count phrase.');

        foreach ([$html, $this->panel($project, ProjectDeliverable::KEY_PROGRAMMING)] as $markup) {
            $this->assertStringNotContainsString('0 files', $markup);
            $this->assertStringNotContainsString('cav-tick', $markup);
            $this->assertStringNotContainsString('Not marked', $markup);
            $this->assertStringNotContainsString('Marked done by hand', $markup);
            $this->assertStringNotContainsString('Ticked by', $markup);
        }
    }

    // -- Empty project ------------------------------------------------------

    public function test_an_empty_project_still_renders_every_module_row_waiting(): void
    {
        $html    = $this->render($this->project());
        $modules = CockpitModulePresenter::moduleMap();

        $this->assertSame(count($modules), $this->countByClass($html, 'cav-module'));

        // Every chip reads "Not started"; nothing claims progress or a file.
        $this->assertSame(count($modules), $this->countByClass($html, 'cav-schip--wait'));
        $this->assertSame(0, $this->countByClass($html, 'cav-schip--live'));
        $this->assertSame(0, $this->countByClass($html, 'cav-schip--file'));

        // Eight rows carry a zero count; Programming carries none, because it
        // has no store to be empty.
        $this->assertSame(count($modules) - 1, $this->countByClass($html, 'cav-module__count'));
        $this->assertSame('', $this->rowCount($html, 'Programming'));
        $this->assertSame('0 visits', $this->rowCount($html, 'Site survey'));
        $this->assertSame('0 documents', $this->rowCount($html, 'RAMS'));
        $this->assertSame('0 tasks', $this->rowCount($html, 'Programme and commissioning'));

        $this->assertStringContainsString('Nothing has been recorded on this job yet', $html);
        $this->assertStringContainsString('This cockpit reads records the app', $html);
    }

    // -- A broken source must not remove a visit ---------------------------

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

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET);

        $this->assertStringContainsString('Install day one', $html);
        $this->assertStringContainsString('Record unavailable', $html);
        $this->assertStringContainsString(
            'The visit is recorded; the document behind it could not be read.',
            $html
        );

        // The visit is counted at rest too — an unreadable source hides
        // nothing.
        $this->assertSame('1 visit', $this->rowCount($this->render($project), 'First fix and install'));
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

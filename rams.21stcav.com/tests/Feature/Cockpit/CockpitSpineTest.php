<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\RamsDocument;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
     * What the PRESENTER says each row's count is, keyed by the row's title.
     *
     * The successor to the retired every-row-has-a-count assertion is driven
     * off this rather than off a literal, so it measures the PAIRING between
     * what the presenter produced and what the page rendered. A literal would
     * only ever re-state the presenter's behaviour in a second place, and the
     * two would drift.
     *
     * @return Collection<string, string>
     */
    private function presentedCounts(Project $project): Collection
    {
        return app(CockpitModulePresenter::class)
            ->modules($project->fresh())
            ->mapWithKeys(static fn (array $module): array => [$module['title'] => $module['count']]);
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
     *
     * It was: 46.2 D-01 revisited exactly that, nine rows down to four, and
     * this test needed NO edit. That is what deriving from the map buys.
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
            // REPOINTED by Plan 46.2-01 from KEY_CABLE_SCHEDULE to KEY_OM:
            // 46.2 D-01 removed the Cable schedule row. The subject is the
            // not-required TREATMENT, not that one particular row can carry it.
            'deliverable_key' => ProjectDeliverable::KEY_OM,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        $html = $this->render($project);

        $this->assertStringContainsString('>O&M manual<', $html);

        // It recedes by its words, not by disappearing: the row says why its
        // count is empty instead of showing a zero that looks like neglect.
        $this->assertSame('Not required', $this->rowCount($html, 'O&M manual'));
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
            $this->rowCount($this->render($project), 'Worksheet'),
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
            $this->rowCount($this->render($project), 'Worksheet')
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
            $this->rowCount($this->render($project), 'Worksheet')
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
            $this->rowCount($this->render($project), 'Worksheet')
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
            $this->rowChip($plainHtml, 'Worksheet'),
            $this->rowChip($qualifiedHtml, 'Worksheet'),
            'Gold means someone must act, and a qualifier cannot be actioned on a read-only page.'
        );

        $this->assertSame(
            $this->statusCardValue($plainHtml),
            $this->statusCardValue($qualifiedHtml),
            'A qualifier must not move the Overall status card either.'
        );

        // The two counts differ — which is the point: the disclosure changed,
        // the demand for attention did not.
        $this->assertSame('2 visits', $this->rowCount($plainHtml, 'Worksheet'));
        $this->assertSame(
            '2 visits · reconstructed · superseded',
            $this->rowCount($qualifiedHtml, 'Worksheet')
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

    // -- No row claims a completion it cannot evidence ----------------------

    /**
     * RETIRED: `test_programming_claims_no_completion_it_cannot_evidence`, by
     * Plan 46.2-01 per 46.2 D-01 — the Programming row it read is gone, so its
     * two row assertions ('>Programming<' present, its count phrase empty) had
     * nothing left to read. It is REPLACED, not deleted: what it and its own
     * predecessor (`test_programming_renders_the_unticked_box_and_not_marked`,
     * retired in Phase 45 when the tick box was deleted) really protected is
     * this, and it survives in a stronger form — NOTHING ON THIS PAGE CLAIMS A
     * COMPLETION THIS PHASE CANNOT EVIDENCE, now asserted over EVERY surviving
     * row and panel rather than over the one row that made the point.
     */
    public function test_no_row_or_panel_claims_a_completion_it_cannot_evidence(): void
    {
        $project = $this->project();
        $html    = $this->render($project);

        $markups = [$html];

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $key) {
            $markups[] = $this->panel($project, $key);
        }

        foreach ($markups as $markup) {
            $this->assertStringNotContainsString('0 files', $markup);
            $this->assertStringNotContainsString('cav-tick', $markup);
            $this->assertStringNotContainsString('Not marked', $markup);
            $this->assertStringNotContainsString('Marked done by hand', $markup);
            $this->assertStringNotContainsString('Ticked by', $markup);
        }

        // The Programming row is gone outright (46.2 D-01), which is a stronger
        // guarantee than a row that claimed nothing.
        $this->assertStringNotContainsString('>Programming<', $html);
    }

    /**
     * T-46.2-01. Five module keys were valid URLs until this plan, so a
     * bookmarked or pasted `?module=snagging` is a REAL request a PM will make.
     * It must render the page with NO panel and HTTP 200 — the "closed at rest
     * means ABSENT" path — never a 500 and never an empty panel frame.
     */
    public function test_a_bookmarked_removed_module_renders_the_page_with_no_panel(): void
    {
        $project = $this->project();

        foreach (['install_programme', 'drawings', 'cable_schedule', 'programming', 'snagging'] as $key) {
            // render() asserts 200 for us; a 500 here would fail before the
            // panel assertion is reached.
            $html = $this->panel($project, $key);

            $this->assertSame(
                0,
                $this->countByClass($html, 'cav-panel'),
                "A removed module key ('{$key}') must open no panel."
            );
            $this->assertSame(
                count(CockpitModulePresenter::moduleMap()),
                $this->countByClass($html, 'cav-module'),
                'The row list still renders in full behind an unknown module key.'
            );
        }
    }

    // -- The count phrase's iff property (D-04, revised 2026-09-26) ---------

    /**
     * THE SUCCESSOR TO `CockpitSpineTest:658`, RETIRED BY NAME CITING D-04.
     *
     * WHAT IT MEASURES: a row renders a `cav-module__count` element **if and
     * only if** the presenter produced a non-empty count for that module, and
     * when it renders, the text is the presenter's string EXACTLY.
     *
     * BOTH DIRECTIONS, AND THE SECOND ONE IS THE POINT. The forward half —
     * "empty means absent" — is what D-04 asked for. The reverse half —
     * "non-empty means present, and identical" — is what protects the ruling
     * from being over-applied: a one-directional test would go on passing while
     * `1 visit · reconstructed` silently stopped rendering, and that is the
     * exact regression hide-at-zero exists to avoid (Phase 45 D-02; 24
     * backfilled visits on live).
     *
     * Driven off `CockpitModulePresenter`'s own output, never a literal, so it
     * cannot drift from the thing it is judging and a fifth module needs no
     * edit here.
     *
     * Run over FOUR fixtures, because an all-empty or all-populated project
     * proves only one direction: nothing, visits only, documents only, and both.
     *
     * ══ NARROWED BY NAME TO THE CLOSED PAGE, PLAN 46.3-02, CITING D-02 ══════
     *
     * This method always rendered with no `?module=`, so it has ALWAYS judged
     * the closed page. What changed is that "the closed page" is now the only
     * place the whole list exists: 46.3 D-02 collapses the other rows away
     * while a drawer is open, so on an OPEN render this loop would iterate the
     * four modules the presenter returned and find three rows missing —
     * `rowCount()` would `fail()` on the first of them.
     *
     * It is NOT weakened to a `>= 1` and NEITHER DIRECTION IS DROPPED. It is
     * narrowed in name and in its docblock to the page it always judged, and
     * the open page gets its OWN assertion of the SAME iff property in
     * test_the_surviving_open_row_obeys_the_same_count_iff_property() below.
     * Two exact assertions, never one vague one.
     */
    public function test_a_row_renders_a_count_if_and_only_if_that_count_is_non_empty_on_the_closed_page(): void
    {
        $seen = ['empty' => 0, 'rendered' => 0];

        foreach (['nothing', 'visits', 'documents', 'both'] as $fixture) {
            $project = $this->project();

            if (in_array($fixture, ['visits', 'both'], true)) {
                $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

                Visit::factory()->backfilledFromWorksheet($worksheet)->create([
                    'project_id'     => $project->id,
                    'scheduled_date' => '2026-09-02',
                ]);

                Visit::factory()->create([
                    'project_id' => $project->id,
                    'type'       => Visit::TYPE_SITE_SURVEY,
                ]);
            }

            if (in_array($fixture, ['documents', 'both'], true)) {
                RamsDocument::factory()->create(['project_id' => $project->id]);
            }

            $html = $this->render($project);

            foreach ($this->presentedCounts($project) as $title => $count) {
                if ($count === '') {
                    $seen['empty']++;

                    $this->assertSame(
                        '',
                        $this->rowCount($html, $title),
                        "[{$fixture}] The \"{$title}\" row rendered a count element for an EMPTY ".
                        'presenter count. D-04: a row with nothing to say says nothing — not an '.
                        'empty span, not "0 visits".'
                    );

                    continue;
                }

                $seen['rendered']++;

                $this->assertSame(
                    $count,
                    $this->rowCount($html, $title),
                    "[{$fixture}] The \"{$title}\" row does not render the presenter's count ".
                    "\"{$count}\" verbatim. A non-zero phrase must reach the page EXACTLY as the ".
                    'presenter built it — its `· reconstructed` and `· superseded` suffixes are '.
                    'the page\'s only at-rest disclosure (Phase 45 D-02).'
                );
            }
        }

        // NON-VACUITY, BOTH WAYS. Without this the test would pass on fixtures
        // that never exercised one of its two directions.
        $this->assertNotSame(0, $seen['empty'], 'No suppressed count was exercised.');
        $this->assertNotSame(0, $seen['rendered'], 'No rendered count was exercised.');
    }

    /**
     * THE OPEN-PAGE HALF OF THE IFF PROPERTY — Plan 46.3-02, 46.3 D-02.
     *
     * The sibling above judges the closed page, where all four rows exist. This
     * one judges what 46.3 D-02 leaves behind: exactly ONE row, and the SAME
     * property on it — a count element iff the presenter produced a non-empty
     * count, and verbatim when it did.
     *
     * ⚠️ IT IS THE IFF PROPERTY AND NEVER "EXACTLY ONE COUNT". The open row may
     * legitimately render NO count: a zero-visit module is exactly the case
     * D-04 now suppresses, so `?module=site_survey` on a project with no visits
     * is a one-row page with no count element on it — and correct.
     *
     * Both fixtures are exercised on purpose: a project with visits (so a
     * rendered count is judged) and one without (so a suppressed count is), and
     * a counter proves each was actually reached.
     */
    public function test_the_surviving_open_row_obeys_the_same_count_iff_property(): void
    {
        $seen = ['empty' => 0, 'rendered' => 0];

        foreach (['nothing', 'both'] as $fixture) {
            $project = $this->project();

            if ($fixture === 'both') {
                $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

                Visit::factory()->backfilledFromWorksheet($worksheet)->create([
                    'project_id'     => $project->id,
                    'scheduled_date' => '2026-09-02',
                ]);

                RamsDocument::factory()->create(['project_id' => $project->id]);
            }

            $counts = $this->presentedCounts($project);

            foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
                $html = $this->panel($project, $key);

                // D-02: one row, and it is this module's.
                $this->assertSame(
                    1,
                    $this->countByClass($html, 'cav-module'),
                    "[{$fixture}] Opening '{$key}' must leave exactly one module row (D-02)."
                );

                $expected = $counts[$definition['title']];

                if ($expected === '') {
                    $seen['empty']++;
                } else {
                    $seen['rendered']++;
                }

                // rowCount() fails outright if the titled row is absent, so
                // this also proves the SURVIVING row is the right one.
                $this->assertSame(
                    $expected,
                    $this->rowCount($html, $definition['title']),
                    "[{$fixture}] The open '{$key}' row does not render the presenter's count ".
                    "\"{$expected}\" verbatim. The iff property holds on the open page too -- and it ".
                    'is the iff property, never "exactly one count": a zero-visit module renders '.
                    'none, by D-04.'
                );
            }
        }

        // NON-VACUITY, BOTH WAYS, on the open page as on the closed one.
        $this->assertNotSame(0, $seen['empty'], 'No suppressed count was exercised on an open page.');
        $this->assertNotSame(0, $seen['rendered'], 'No rendered count was exercised on an open page.');
    }

    /**
     * THE DISCLOSURE SURVIVES THE SUPPRESSION.
     *
     * D-04 (revised 2026-09-26) hides the visit phrase at ZERO and at zero
     * only. The reason it is not a full strip is Phase 45's D-02: with the
     * panel CLOSED this phrase is the page's only disclosure that a visit was
     * INFERRED rather than recorded, or that it has been SUPERSEDED — and
     * there are 24 backfilled visits on live. A PM who opens nothing must
     * still be warned.
     *
     * Without this test the ruling is a comment rather than a guarantee, so if
     * you came here to tidy the phrase away: read D-04 and Phase 45 D-02 first.
     */
    public function test_the_reconstructed_and_superseded_disclosures_survive_on_the_closed_page(): void
    {
        // Case 1 — reconstructed, on the CLOSED page (no ?module=).
        $reconstructed = $this->project();
        $worksheet     = Worksheet::factory()->create(['project_id' => $reconstructed->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $reconstructed->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $closed = $this->render($reconstructed);

        $this->assertSame(0, $this->countByClass($closed, 'cav-panel'), 'The page must be CLOSED for this to mean anything.');
        $this->assertStringContainsString(
            '· reconstructed',
            $this->rowCount($closed, 'Worksheet'),
            'A reconstructed visit is no longer disclosed at rest. D-04 hides the phrase at ZERO '.
            'and at zero only; this is the warning Phase 45 D-02 put there.'
        );

        // Case 2 — superseded, same page state.
        $superseded = $this->project();
        $this->supersededVisit($superseded, 'Install day one', '2026-09-02');

        $closed = $this->render($superseded);

        $this->assertSame(0, $this->countByClass($closed, 'cav-panel'));
        $this->assertStringContainsString(
            '· superseded',
            $this->rowCount($closed, 'Worksheet'),
            'A superseded visit is no longer disclosed at rest, so it reads as though it still stands.'
        );
    }

    // -- Empty project ------------------------------------------------------

    public function test_an_empty_project_still_renders_every_module_row_waiting(): void
    {
        $project = $this->project();
        $html    = $this->render($project);
        $modules = CockpitModulePresenter::moduleMap();

        $this->assertSame(count($modules), $this->countByClass($html, 'cav-module'));

        // Every chip reads "Not started"; nothing claims progress or a file.
        $this->assertSame(count($modules), $this->countByClass($html, 'cav-schip--wait'));
        $this->assertSame(0, $this->countByClass($html, 'cav-schip--live'));
        $this->assertSame(0, $this->countByClass($html, 'cav-schip--file'));

        // ── RETIRED BY NAME, PLAN 46.3-01, CITING D-04 (REVISED 2026-09-26) ──
        //
        // WAS, on this line:
        //
        //     $this->assertSame(count($modules), $this->countByClass($html, 'cav-module__count'));
        //
        // "Every module row renders exactly one count element." Its own
        // history is recorded above it: `count($modules) - 1` ->
        // `count($modules)` by Plan 46.2-01, when 46.2 D-01 removed
        // Programming, the only COUNT_NONE row.
        //
        // IT CANNOT HOLD ANY MORE. D-04 hides the VISIT phrase at zero, so on
        // an empty project the two COUNT_VISITS rows (Site survey, Worksheet)
        // render NO count element at all. The equality is false by
        // construction, and it was NOT deleted to make a red go green: its
        // successor is `test_a_row_renders_a_count_if_and_only_if_that_count_is_non_empty()`
        // below, which is STRONGER — it drives off CockpitModulePresenter's own
        // output rather than counting elements, and it asserts BOTH DIRECTIONS.
        // The retired version could not have noticed a non-zero phrase silently
        // vanishing so long as the total still added up; that is precisely the
        // regression the ruling protects against, and the successor fails on it.
        // Recorded as A-1 in 46.3-COUNT-LEDGER.md.
        //
        // RETIRED IN THE SAME BREATH (A-2): the two `'0 visits'` expectations
        // that stood where the two `''` expectations now stand. `rowCount()`
        // already returned `''` for a row that renders no count element, so the
        // helper needed no change and the assertions stay exact `assertSame`s
        // rather than being loosened to a "contains".
        //
        // RETIRED EARLIER, by Plan 46.2-01 / 46.2 D-01: `rowCount($html,
        // 'Programming') === ''` and `rowCount($html, 'Programme and
        // commissioning') === '0 tasks'`. Both rows are gone.
        //
        // The test still ENUMERATES every row rather than sampling one.
        $this->assertSame('', $this->rowCount($html, 'Site survey'));
        $this->assertSame('', $this->rowCount($html, 'Worksheet'));

        // NOT SUPPRESSED, AND DELIBERATELY SO. The ruling names the VISIT
        // phrase; a planner does not widen a user's decision on their behalf.
        // Whether `0 documents` reads as noise too is an OPEN QUESTION carried
        // to Plan 46.3-04's human checkpoint (CR-2 in 46.3-COUNT-LEDGER.md).
        // These two assertions are what keeps that scoping honest — a later
        // agent who strips the document count without asking fails here.
        $this->assertSame('0 documents', $this->rowCount($html, 'RAMS'));
        $this->assertSame('0 documents', $this->rowCount($html, 'O&M manual'));

        // The successor's empty-project case, asserted here too so this method
        // keeps proving what its retired line proved: exactly the rows with
        // something to say render an element, and no other. DERIVED from the
        // presenter, never the literal 2 — a fifth module added later must not
        // have to move a number in this file.
        $this->assertSame(
            $this->presentedCounts($project)->filter(static fn (string $count): bool => $count !== '')->count(),
            $this->countByClass($html, 'cav-module__count')
        );

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
        $this->assertSame('1 visit', $this->rowCount($this->render($project), 'Worksheet'));
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

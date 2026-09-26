<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 46.3, Plan 46.3-04 — THE NEW LAYOUT AS ONE WALK, THROUGH HTTP.
 *
 * Plans 46.3-01..46.3-03 each proved a piece against a render: the zero rule
 * and the relabel, the inline drawer and the collapse-away, the project-level
 * activity panel. This file walks them together through the real request
 * surface, with JavaScript off, the way a PM meets them:
 *
 *   bare page → open a module → read it → GET BACK → open a different one →
 *   switch tabs → disclose the generate form.
 *
 * ── EVERY STEP IS A REAL GET AGAINST `projects.cockpit` ────────────────────
 *
 * No component is rendered in isolation and no presenter is called for a
 * verdict. Where a DOM query will do the job, the body is parsed rather than
 * grepped — `substr_count` cannot tell a panel that sits BENEATH its row from
 * one that sits beside it, and the position is the whole of D-01.
 *
 * ── THE WAY BACK IS FOLLOWED, NOT INSPECTED (D-02) ─────────────────────────
 *
 * `test_the_way_back_anchor_returns_to_the_bare_page_with_every_row()` parses
 * the anchor's OWN href out of the response and issues that URL. Constructing
 * `route('projects.cockpit', $project)` in the test and asserting it renders
 * four rows would prove the ROUTE and not the CONTROL — and with the other
 * rows collapsed away the control is the only route back, which is precisely
 * what 46.3-CONTEXT.md D-02 flags as "designed, not discovered".
 *
 * ── THE FIXTURE CARRIES A RECONSTRUCTED VISIT ON PURPOSE (D-04) ────────────
 *
 * D-04 is hide-at-zero, NOT remove. A fixture with no visits would walk only
 * the suppressing half and would go on passing if `1 visit · reconstructed`
 * silently stopped rendering — the exact regression hide-at-zero exists to
 * avoid (Phase 45 D-02; 24 backfilled visits on live). So the Worksheet
 * module is seeded with a backfilled visit and the walk asserts BOTH
 * directions on the same closed page: `0 visits` nowhere in the body, and
 * `· reconstructed` inside the Worksheet row's own subtree.
 *
 * ── THE SPEND BOUNDARY, INHERITED FROM 46.2-06 AND UNCHANGED ───────────────
 *
 * `QUEUE_CONNECTION` is `sync` in `phpunit.xml`, so an un-faked `dispatch()`
 * RUNS the generator inline and spends real money on the user's Anthropic
 * account. THIS WALK STOPS AT DISCLOSURE: it opens `?action=generate` and
 * asserts the form renders. It never submits it. `Bus::fake()` and
 * `Http::fake()` are in `setUp()` as belts, not as permission — if a later
 * step ever needs to reach a generator, assert the DISPATCH, never the bytes.
 *
 * ── THE MODULE LIST IS DERIVED, NEVER TYPED ────────────────────────────────
 *
 * Every count and every key comes from `CockpitModulePresenter::moduleMap()`.
 * 46.2 D-01 already moved that number from nine to four once.
 */
class CockpitInlineDrawerEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);

        Storage::fake('local');
        Storage::fake('public');

        // THE SPEND BOUNDARY, as belts. This walk reaches no generator; see
        // the class docblock. Neither of these is permission to run one.
        Bus::fake();
        Http::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /**
     * A project whose Worksheet module HAS visits, one of them reconstructed,
     * and whose Site survey module has none. One fixture, both directions of
     * D-04 — see the class docblock.
     */
    private function project(): Project
    {
        $project = Project::factory()->create([
            'name'         => 'Northbank Fitout',
            'ref'          => 'Q-4626',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);

        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        // THE ROW THAT MUST SHOW ITS PHRASE. `backfilledFromWorksheet()` is a
        // TYPE_INSTALL visit with `is_backfilled` true, which `MODULE_MAP`
        // routes to the Worksheet row and `visitQualifiers()` renders as
        // `· reconstructed`.
        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-02',
        ]);

        return $project;
    }

    // ── Request helper — every step goes through here ───────────────────────

    private function cockpit(Project $project, array $query = [], ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->user())
            ->get(route('projects.cockpit', ['project' => $project] + $query))
            ->assertOk()
            ->getContent();
    }

    // ── DOM helpers — parsed, never grepped ─────────────────────────────────

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /**
     * The module list's own subtree, entity-decoded.
     *
     * TITLE ASSERTIONS ARE SCOPED HERE AND NOT TO THE BODY, and the reason was
     * PAID FOR: the app's own navigation carries a `RAMS` link on every page,
     * so `assertStringNotContainsString('>RAMS<', $body)` failed on the chrome
     * while the collapse-away was working perfectly. The question this walk
     * asks is what the MODULE LIST renders, so the module list is what it
     * reads.
     *
     * The subtree is ENTITY-DECODED on the way out, because `O&M manual`
     * reaches the wire as `O&amp;M manual` — a raw-body title assertion would
     * be false for one of the four modules and true for the other three.
     */
    private function moduleList(string $html): string
    {
        $list = $this->subtree($html, 'cav-modules');

        $this->assertNotSame('', $list, 'The .cav-modules container was not found — the walk would pass vacuously.');

        return $list;
    }

    /** @return \DOMNodeList<\DOMNode> */
    private function nodesByClass(string $html, string $class): \DOMNodeList
    {
        return $this->dom($html)
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
    }

    private function countByClass(string $html, string $class): int
    {
        return $this->nodesByClass($html, $class)->count();
    }

    /** One element's subtree by class, entity-decoded. Empty string when absent. */
    private function subtree(string $html, string $class): string
    {
        $node = $this->nodesByClass($html, $class)->item(0);

        if ($node === null) {
            return '';
        }

        return html_entity_decode(
            $node->ownerDocument->saveHTML($node),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    /** The `cav-brand cav-cockpit` subtree — the page's own region, without the app chrome. */
    private function region(string $html): string
    {
        $region = $this->subtree($html, 'cav-cockpit');

        $this->assertNotSame('', $region, 'The cav-cockpit region was not found — the walk would pass vacuously.');

        return $region;
    }

    /** The `cav-module` row whose title is $title, as markup. Fails loudly when absent. */
    private function rowByTitle(string $html, string $title): string
    {
        foreach ($this->nodesByClass($html, 'cav-module') as $row) {
            $markup = html_entity_decode(
                $row->ownerDocument->saveHTML($row),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );

            if (str_contains($markup, '>'.$title.'<')) {
                return $markup;
            }
        }

        $this->fail("No module row titled '{$title}' was rendered.");
    }

    /** Every module title the presenter knows, derived. */
    private function everyTitle(): array
    {
        return array_column(CockpitModulePresenter::moduleMap(), 'title');
    }

    /** Every module key the presenter knows, derived. */
    private function everyKey(): array
    {
        return array_keys(CockpitModulePresenter::moduleMap());
    }

    /**
     * A private constant off `CockpitReadOnlyFenceTest`, read rather than
     * re-typed, so this walk cannot drift from the fence it is echoing.
     */
    private function fenceList(string $name): array
    {
        $list = (new \ReflectionClass(CockpitReadOnlyFenceTest::class))->getConstant($name);

        $this->assertIsArray($list, "CockpitReadOnlyFenceTest::{$name} is not readable — the walk would assert nothing.");
        $this->assertNotEmpty($list, "CockpitReadOnlyFenceTest::{$name} is empty — the walk would assert nothing.");

        return $list;
    }

    /** Every URL this walk visits, in order, as a query array. */
    private function walkSteps(): array
    {
        [$first, $second] = [$this->everyKey()[0], $this->everyKey()[1]];

        return [
            'the bare page'          => [],
            'the first module open'  => ['module' => $first],
            'a different module'     => ['module' => $second],
            'the files tab'          => ['module' => $second, 'tab' => 'files'],
            'the notes tab'          => ['module' => $second, 'tab' => 'notes'],
            'the generate form'      => ['module' => $second, 'action' => 'generate'],
        ];
    }

    // ── STEP 1 — the bare closed page ───────────────────────────────────────

    /**
     * ROADMAP criterion 1 (the list at rest), criterion 2 (the activity panel
     * is there before anything is opened) and criterion 3 as D-04 REVISED it.
     *
     * DL-01 · DL-02 · DL-03 · DL-04.
     */
    public function test_the_bare_page_shows_every_module_row_the_activity_panel_and_no_drawer(): void
    {
        $body = $this->cockpit($this->project());

        $this->assertSame(
            count(CockpitModulePresenter::moduleMap()),
            $this->countByClass($body, 'cav-module'),
            'The closed page is the whole list — derived from moduleMap(), never a literal.'
        );

        foreach ($this->everyTitle() as $title) {
            $this->assertStringContainsString('>'.$title.'<', $this->moduleList($body), "The {$title} row must be on the bare page.");
        }

        $this->assertSame(0, $this->countByClass($body, 'cav-panel'), 'Closed at rest means ABSENT, not hidden (D-09).');
        $this->assertSame(1, $this->countByClass($body, 'cav-activity'), 'Exactly one project-level Recent activity panel (D-03).');
        $this->assertStringContainsString('Open full project', $body, 'The footnote is the fence region`s bottom bracket.');
    }

    /**
     * ROADMAP criterion 3, WALKED IN BOTH DIRECTIONS — and the second one is
     * the point. DL-04.
     */
    public function test_the_visit_phrase_is_hidden_at_zero_and_kept_when_it_is_not(): void
    {
        $body = $this->cockpit($this->project());

        // FORWARD — the noise the user asked to be rid of, nowhere on the page.
        $this->assertStringNotContainsString('0 visits', $body, 'D-04: `0 visits` never renders.');

        // The Site survey module has no visits in this fixture, so its row
        // renders no count element at all.
        $this->assertSame(
            0,
            $this->countByClass($this->rowByTitle($body, 'Site survey'), 'cav-module__count'),
            'A zero-visit row renders no count element — one mechanism, in the presenter.'
        );

        // REVERSE — the at-rest disclosure slot, still disclosing. This is the
        // half a strip would have deleted along with the noise.
        $worksheetRow = $this->rowByTitle($body, 'Worksheet');

        $this->assertSame(1, $this->countByClass($worksheetRow, 'cav-module__count'));
        $this->assertStringContainsString('1 visit · reconstructed', $worksheetRow,
            'A backfilled visit must still be disclosed on the CLOSED page (Phase 45 D-02).');
    }

    // ── STEP 2 — opening a module ───────────────────────────────────────────

    /**
     * ROADMAP criterion 1 — the drawer is BENEATH ITS OWN ROW, and the other
     * rows are out of the way. DL-01 · DL-02.
     */
    public function test_opening_a_module_renders_its_drawer_beneath_that_row_and_collapses_the_others(): void
    {
        $project = $this->project();

        foreach ($this->everyKey() as $key) {
            $body  = $this->cockpit($project, ['module' => $key]);
            $title = CockpitModulePresenter::moduleMap()[$key]['title'];

            $this->assertSame(1, $this->countByClass($body, 'cav-module'), "Opening '{$key}' must leave exactly one row.");
            $this->assertSame(1, $this->countByClass($body, 'cav-panel'), "Opening '{$key}' must render exactly one panel.");

            // The surviving row is the RIGHT one.
            $this->assertStringContainsString('>'.$title.'<', $this->moduleList($body));

            // The others are ABSENT — asserted by their titles as well as by
            // the element count, because a row could render empty.
            foreach ($this->everyTitle() as $other) {
                if ($other === $title) {
                    continue;
                }

                $this->assertStringNotContainsString('>'.$other.'<', $this->moduleList($body),
                    "With '{$key}' open, the {$other} row must not be rendered at all.");
            }

            // THE POSITION, WHICH IS THE WHOLE OF D-01. Inside `.cav-modules`,
            // the element immediately following the row is the panel.
            $this->assertPanelFollowsItsRow($body, $key);

            // And the activity panel is untouched by any of it.
            $this->assertSame(1, $this->countByClass($body, 'cav-activity'));
        }
    }

    private function assertPanelFollowsItsRow(string $body, string $key): void
    {
        $modules = $this->nodesByClass($body, 'cav-modules')->item(0);

        $this->assertNotNull($modules, 'The .cav-modules container was not found.');

        $children = [];

        foreach ($modules->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child->getAttribute('class');
            }
        }

        $rowAt = null;

        foreach ($children as $index => $class) {
            if (str_contains($class, 'cav-module ') || $class === 'cav-module' || str_contains($class, 'cav-module--active')) {
                $rowAt = $index;
                break;
            }
        }

        $this->assertNotNull($rowAt, "No module row is a child of .cav-modules with '{$key}' open.");
        $this->assertArrayHasKey($rowAt + 1, $children,
            "Nothing follows the module row — the drawer for '{$key}' is not beneath it.");
        $this->assertStringContainsString('cav-panel', $children[$rowAt + 1],
            "The element after the '{$key}' row must be its drawer (D-01), not something else.");
    }

    // ── STEP 3 — THE WAY BACK, FOLLOWED ─────────────────────────────────────

    /**
     * ROADMAP criterion 1, its third clause: "getting back to the list is
     * obvious and needs no JavaScript". DL-02.
     *
     * THE ROUND TRIP, NOT THE LINK. The anchor's own href is parsed out of the
     * response and issued. A test that builds the bare URL itself proves the
     * route and leaves the control unwalked — and with the other rows
     * collapsed away the control is the only way back there is.
     */
    public function test_the_way_back_anchor_returns_to_the_bare_page_with_every_row(): void
    {
        $project = $this->project();
        $user    = $this->user();

        foreach ($this->everyKey() as $key) {
            $open = $this->cockpit($project, ['module' => $key], $user);

            $back = $this->dom($open)
                ->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel__back ')]")
                ->item(0);

            $this->assertNotNull($back, "The way back must be on the '{$key}' drawer.");
            $this->assertStringContainsString('Back to all modules', $back->textContent,
                'The way back is NAMED, not a bare glyph (D-02).');

            $href = $back->getAttribute('href');
            $this->assertNotSame('', $href, 'The way back must be a real href — no JavaScript on this page.');

            // FOLLOW IT.
            $bare = $this->actingAs($user)->get($href)->assertOk()->getContent();

            $this->assertSame(
                count(CockpitModulePresenter::moduleMap()),
                $this->countByClass($bare, 'cav-module'),
                "Following the way back from '{$key}' must restore the whole module list."
            );
            $this->assertSame(0, $this->countByClass($bare, 'cav-panel'),
                'The way back closes the drawer; it does not merely re-open the list beside it.');

            foreach ($this->everyTitle() as $title) {
                $this->assertStringContainsString('>'.$title.'<', $this->moduleList($bare));
            }
        }
    }

    // ── STEP 5 — the tabs ───────────────────────────────────────────────────

    /**
     * ROADMAP criterion 1 across the tab strip. DL-01 · DL-02.
     */
    public function test_every_tab_keeps_one_row_one_drawer_and_the_way_back(): void
    {
        $project = $this->project();

        foreach ($this->everyKey() as $key) {
            foreach (['overview', 'files', 'notes'] as $tab) {
                $body = $this->cockpit($project, ['module' => $key, 'tab' => $tab]);

                $this->assertSame(1, $this->countByClass($body, 'cav-module'), "{$key}/{$tab}: one row.");
                $this->assertSame(1, $this->countByClass($body, 'cav-panel'), "{$key}/{$tab}: one drawer.");
                $this->assertSame(1, $this->countByClass($body, 'cav-activity'), "{$key}/{$tab}: one activity panel.");
                $this->assertStringContainsString('Back to all modules', $body, "{$key}/{$tab}: the way back is present.");

                $this->assertPanelFollowsItsRow($body, $key);
            }
        }
    }

    // ── STEP 6 — the generate disclosure ────────────────────────────────────

    /**
     * ROADMAP criterion 4 — the control reads as opening a form, and the
     * formats that already existed are behind it. DL-05.
     *
     * STOPS AT DISCLOSURE. The form is never submitted; see the class docblock.
     */
    public function test_the_generate_action_discloses_the_form_and_its_existing_format_choice(): void
    {
        $project = $this->project();

        foreach ($this->everyKey() as $key) {
            $closed = $this->subtree($this->cockpit($project, ['module' => $key]), 'cav-qa');
            $open   = $this->subtree($this->cockpit($project, ['module' => $key, 'action' => 'generate']), 'cav-qa');

            $this->assertNotSame('', $closed, "The '{$key}' row renders no document block.");
            $this->assertStringContainsString('Create document', $closed,
                'D-05: the closed control reads as OPENING A FORM.');
            $this->assertSame(0, substr_count($closed, '<form'), 'Closed discloses no form.');

            // Open: the closed copy is gone, the submit button is the one that
            // genuinely generates, and the Format fieldset is on the page.
            $this->assertStringNotContainsString('Create document', $open,
                'The closed-state copy must not survive onto the open form.');
            $this->assertStringContainsString('<form', $open);
            $this->assertStringContainsString('Generate document', $open,
                'The submit control keeps its name, because it does generate.');
            $this->assertStringContainsString('Format', $open, 'The Format fieldset discloses the choice (D-05).');
            $this->assertStringContainsString('value="word"', $open, 'Word already existed and still does.');
        }
    }

    /**
     * DC-07, RE-ASSERTED ON THE NEW LAYOUT — the Worksheet says its PDF is not
     * available rather than offering one that cannot be rendered. Still a GAP,
     * still unchosen, and this phase does not close it.
     */
    public function test_the_worksheet_still_states_that_its_pdf_does_not_exist(): void
    {
        $open = $this->subtree(
            $this->cockpit($this->project(), [
                'module' => ProjectDeliverable::KEY_WORKSHEET,
                'action' => 'generate',
            ]),
            'cav-qa',
        );

        $this->assertStringContainsString('PDF is not available for this document (DC-07).', $open);
        $this->assertStringNotContainsString('value="pdf"', $open);
    }

    // ── STEP 7 — the activity panel is the SAME at every step ───────────────

    /**
     * ROADMAP criterion 2 — "shows the same entries whichever module is open",
     * asserted as SUBTREE EQUALITY rather than as a count, because a different
     * feed of the same length would satisfy a count. DL-03.
     */
    public function test_the_recent_activity_panel_is_identical_at_every_step_of_the_walk(): void
    {
        $project  = $this->project();
        $user     = $this->user();
        $baseline = null;

        foreach ($this->walkSteps() as $label => $query) {
            $panel = $this->subtree($this->cockpit($project, $query, $user), 'cav-activity');

            $this->assertNotSame('', $panel, "Recent activity is missing from {$label}.");
            $this->assertStringContainsString('Recent activity', $panel);

            if ($baseline === null) {
                $baseline = $panel;

                continue;
            }

            $this->assertSame($baseline, $panel, "Recent activity differs at {$label} — it is project-level data (D-03).");
        }
    }

    // ── STEP 8 — the fence's own bans, across the whole walk ────────────────

    /**
     * ROADMAP criterion 5, echoed over the walk. The lists are READ off
     * `CockpitReadOnlyFenceTest`'s own private constants by reflection, never
     * re-typed, so this test cannot drift from the fence.
     *
     * DL-06 (the no-JavaScript half; the bracket half lives in the fence).
     */
    public function test_no_script_no_handler_and_no_forbidden_control_anywhere_in_the_walk(): void
    {
        $project = $this->project();
        $user    = $this->user();

        $markup   = $this->fenceList('FORBIDDEN_MARKUP');
        $handlers = $this->fenceList('BANNED_HANDLER_ATTRIBUTES');

        foreach ($this->walkSteps() as $label => $query) {
            $region = $this->region($this->cockpit($project, $query, $user));

            foreach ($markup as $needle) {
                // `<form` is LIFTED and legitimate since 46-04; the generate
                // step renders one. The two that remain banned are read off
                // the constant, so this loop bans exactly what the fence bans.
                $this->assertStringNotContainsString($needle, $region,
                    "'{$needle}' must not appear in the cockpit region at {$label}.");
            }

            foreach ($handlers as $handler) {
                $this->assertStringNotContainsString($handler, $region,
                    "'{$handler}' must not appear in the cockpit region at {$label}.");
            }
        }
    }

    /**
     * ROADMAP criterion 6's DOM HALF, and it is named as a half on purpose.
     *
     * The stretched-link pairing is CSS — `.cav-module { position: relative }`
     * plus `.cav-module__open::after { inset: 0 }` — and an HTTP walk cannot
     * see a stylesheet. The CSS half is proven by
     * `CockpitVisualTest::test_the_whole_module_row_is_the_click_target_without_javascript()`,
     * which was proven to BITE in Plan 46.3-01 by injecting a competing
     * `position` and watching it go red. What this test adds is the half the
     * walk CAN judge: on every page of the walk, every rendered row still
     * holds exactly one anchor and it carries that row's own module key. A
     * second anchor in the row is the other way the whole-row target dies.
     *
     * DL-06.
     */
    public function test_every_rendered_row_still_holds_exactly_one_whole_row_anchor(): void
    {
        $project = $this->project();
        $user    = $this->user();

        foreach ($this->walkSteps() as $label => $query) {
            $body = $this->cockpit($project, $query, $user);

            foreach ($this->nodesByClass($body, 'cav-module') as $row) {
                $anchors = [];

                foreach ($row->getElementsByTagName('a') as $anchor) {
                    $anchors[] = $anchor->getAttribute('href');
                }

                $this->assertCount(1, $anchors, "A module row at {$label} must hold exactly one anchor.");
                $this->assertStringContainsString('module=', $anchors[0],
                    "The row anchor at {$label} must carry its own module key.");
            }
        }
    }

    // ── STEP 9 — a GET walk writes nothing ──────────────────────────────────

    /**
     * ROADMAP criterion 5's read-only half, over the WHOLE walk rather than
     * over one render. The table list is read off
     * `CockpitReadOnlyFenceTest::WRITE_SURFACE_TABLES` so a fourteenth table
     * is picked up here the moment it is named there.
     */
    public function test_the_whole_walk_moves_no_row_in_any_write_surface_table(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $tables  = $this->fenceList('WRITE_SURFACE_TABLES');

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach ($this->walkSteps() as $query) {
            $this->cockpit($project, $query, $user);
        }

        // And the way back, followed, because it is a GET too.
        $this->actingAs($user)->get(route('projects.cockpit', $project))->assertOk();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(),
                "The walk moved a row in '{$table}'. Every step of it is a GET.");
        }
    }
}

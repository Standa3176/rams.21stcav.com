<?php

namespace Tests\Feature\Cockpit;

use App\DTO\ProjectHealth;
use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProjectHealthService;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 45, plan 45-06 — the flag-gated read-only project cockpit.
 *
 * Covers the route, the controller's two gates, the page shell and the five
 * presentational primitives.
 *
 * READ-ONLY IS THE POINT OF THIS PHASE (ROADMAP criteria 3 and 5): the
 * controller is GET-only, no POST/PATCH/DELETE route exists, and the
 * `cav-brand cav-cockpit` subtree contains no form control and no script.
 * The shared layout's logout form, command-palette input, nav buttons and
 * Vite/Alpine tags are pre-existing global chrome on every authenticated
 * page — out of scope by the decision recorded in 45-06-PLAN.md, and proven
 * unmodified by 45-08's sha256 check. Every fence assertion below therefore
 * runs against the extracted cockpit subtree, never the whole document.
 *
 * CI / fresh-clone note: the cockpit stylesheet is loaded with @vite, which
 * resolves through public/build/manifest.json. If these tests fail with a
 * Vite manifest exception, that is a missing `npm run build`, NOT a Blade or
 * controller fault. Diagnose with:
 *   Select-String -Path public/build/manifest.json -Pattern 'cockpit.css'
 */
class CockpitPageTest extends TestCase
{
    use InteractsWithViews;
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::factory()->create([
            'name'            => 'Cockpit Test Job',
            'quote_reference' => 'Q-451234',
            'site_address'    => '12 Example Street, Leeds',
            'status'          => Project::STATUS_INSTALLING,
        ]);
    }

    /**
     * Extract the cav-cockpit element so the read-only fence judges only the
     * markup this phase authors, never the shared layout's chrome. Entities
     * are decoded so assertions can be written in the contract's own copy.
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

    private function renderCockpit(Project $project): string
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('projects.cockpit', $project))
            ->assertOk()
            ->getContent();
    }

    // ── Task 1 — route, flag gate, auth gate ─────────────────────────────

    public function test_route_name_resolves_even_when_the_flag_is_off(): void
    {
        config(['cockpit.enabled' => false]);
        $project = $this->project();

        $url = route('projects.cockpit', $project);

        $this->assertStringContainsString("projects/{$project->id}/cockpit", $url);
    }

    public function test_flag_off_returns_404_and_emits_no_cockpit_markup(): void
    {
        config(['cockpit.enabled' => false]);
        $project = $this->project();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('projects.cockpit', $project));

        $response->assertNotFound();
        $this->assertStringNotContainsString('cav-cockpit', $response->getContent());
        $this->assertStringNotContainsString('cockpit.css', $response->getContent());
    }

    public function test_flag_on_unauthenticated_is_rejected(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();

        $response = $this->get(route('projects.cockpit', $project));

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
    }

    public function test_flag_on_authenticated_renders_the_project_name(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();

        $this->assertStringContainsString('Cockpit Test Job', $this->renderCockpit($project));
    }

    public function test_controller_eager_loads_the_health_relations_before_assess(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();

        $probe = new class extends ProjectHealthService
        {
            public array $seen = [];

            public function assess(Project $project): ProjectHealth
            {
                $this->seen = [
                    'ramsDocuments' => $project->relationLoaded('ramsDocuments'),
                    'siteSurveys'   => $project->relationLoaded('siteSurveys'),
                    'deliverables'  => $project->relationLoaded('deliverables'),
                ];

                return parent::assess($project);
            }
        };

        $this->app->instance(ProjectHealthService::class, $probe);

        $this->renderCockpit($project);

        $this->assertSame(
            ['ramsDocuments' => true, 'siteSurveys' => true, 'deliverables' => true],
            $probe->seen,
            'ProjectHealthService::assess() must be called only after all three relations are eager-loaded.'
        );
    }

    public function test_controller_is_get_only(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(ProjectCockpitController::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        foreach (['store', 'update', 'destroy', 'create', 'edit', 'delete', 'patch'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods, "ProjectCockpitController must not define {$forbidden}().");
        }
    }

    public function test_no_write_route_exists_for_the_cockpit(): void
    {
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'cockpit')) {
                continue;
            }

            $checked++;
            $this->assertSame(
                ['GET', 'HEAD'],
                array_values(array_diff($route->methods(), ['OPTIONS'])),
                "Cockpit route {$route->uri()} must be GET-only."
            );
        }

        $this->assertSame(1, $checked, 'Exactly one cockpit route must exist.');
    }

    // ── Plan 45-11, Task 2 — the page: masthead, KPIs, stage chip, modules ─

    /**
     * Count elements carrying an exact class, so `cav-module__title` can never
     * be mistaken for a `cav-module` row. Counting in the DOM rather than
     * trusting the presenter is the point: D-16 requires the documents
     * denominator to equal the rows ACTUALLY RENDERED.
     */
    private function countByClass(string $html, string $class): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->length;
    }

    public function test_page_root_carries_both_scope_classes_and_loads_cockpit_css(): void
    {
        config(['cockpit.enabled' => true]);
        $content = $this->renderCockpit($this->project());

        $this->assertStringContainsString('cav-brand cav-cockpit', $content);
        $this->assertMatchesRegularExpression(
            '/<link[^>]+href="[^"]*cockpit[^"]*\.css"/i',
            $content,
            "cockpit.css must be loaded through the layout's @stack('styles') seam."
        );
    }

    public function test_masthead_renders_the_breadcrumb_one_h1_and_the_site_address(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('cav-mast', $html);
        $this->assertStringContainsString('cav-crumbs', $html);
        $this->assertStringContainsString(route('projects.index'), $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'Exactly one h1.');
        $this->assertStringContainsString('Cockpit Test Job', $html);
        $this->assertStringContainsString('12 Example Street, Leeds', $html);
    }

    /**
     * The three facts the design asks for that have no source stay absent —
     * 45-10 settled each one and this asserts the Blade layer did not quietly
     * reinstate them.
     */
    public function test_the_masthead_invents_no_fact_it_has_no_source_for(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringNotContainsString('Proposed install', $html, 'Planned start is a different fact.');
        $this->assertStringNotContainsString('Site contact:', $html, 'No survey, so no contact line at all.');
        $this->assertStringNotContainsString('@example.com', $html, 'pm_email is never shown under a site-contact label.');
    }

    public function test_three_kpi_cards_render_in_the_designed_order(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertSame(3, $this->countByClass($html, 'cav-kpi'), 'Exactly three KPI cards (D-12).');

        $overall   = strpos($html, 'Overall status');
        $nextVisit = strpos($html, 'Next visit');
        $documents = strpos($html, 'Documents');

        $this->assertNotFalse($overall);
        $this->assertNotFalse($nextVisit);
        $this->assertNotFalse($documents);
        $this->assertLessThan($nextVisit, $overall);
        $this->assertLessThan($documents, $nextVisit);

        // No visit is planned on a bare project, and that is said plainly.
        $this->assertStringContainsString('None planned', $html);
    }

    /**
     * D-16, asserted in the DOM at both ends: the denominator is the number of
     * module rows on the same page, never a literal 9.
     */
    public function test_the_documents_denominator_equals_the_rendered_module_row_count(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $rows = $this->countByClass($html, 'cav-module');

        $this->assertGreaterThan(0, $rows);
        $this->assertMatchesRegularExpression(
            '/\b\d+ of '.$rows.' complete\b/',
            $html,
            "The documents card's denominator must equal the {$rows} module rows rendered beside it."
        );
    }

    public function test_exactly_one_stage_chip_renders(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertSame(
            1,
            $this->countByClass($html, 'cav-stage__chip'),
            'A project has exactly one status, so it gets exactly one stage chip.'
        );
        $this->assertStringContainsString('Installation', $html);
    }

    public function test_nine_module_rows_render_in_presenter_order_with_an_anchor_open_link(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();
        $html    = $this->cockpitSubtree($this->renderCockpit($project));

        $map = CockpitModulePresenter::moduleMap();

        $this->assertSame(count($map), $this->countByClass($html, 'cav-module'));

        $cursor = -1;

        foreach ($map as $key => $definition) {
            $at = strpos($html, $definition['title'], $cursor + 1);

            $this->assertNotFalse($at, "Module '{$key}' must render its title.");
            $this->assertGreaterThan($cursor, $at, "Module '{$key}' is out of design order.");
            $cursor = $at;

            $this->assertStringContainsString($definition['description'], $html);

            // The open affordance is an ANCHOR carrying the module in the query
            // string — never a <button>, never a click handler.
            $this->assertStringContainsString(
                'href="'.e(route('projects.cockpit', ['project' => $project, 'module' => $key])).'"',
                $html,
                "Module '{$key}' must open through a plain <a href> carrying ?module={$key}."
            );
        }

        // Counted by class, not by copy: each link carries the copy twice —
        // once as its text and once in the aria-label that names which module
        // it opens, so nine links legitimately produce eighteen matches.
        $this->assertSame(count($map), $this->countByClass($html, 'cav-module__open'));
        $this->assertStringContainsString('Open drawer', $html);
    }

    /**
     * RETIRED HERE, REHOMED HERE: `test_a_tick_box_renders_unticked_and_is_a_square`
     * (Plan 45-06). The tick-box component is deleted and sketch 004 draws no
     * box, so the assertion had no subject. What it protected — NOTHING ON
     * THIS PAGE CLAIMS A COMPLETION THIS PHASE CANNOT EVIDENCE — is this test:
     * Programming has no model, table or relation anywhere in this codebase,
     * so its row renders a chip and no count phrase at all. The same property
     * is asserted from the other side in
     * CockpitSpineTest::test_programming_claims_no_completion_it_cannot_evidence().
     */
    public function test_the_programming_row_renders_its_chip_and_no_count_phrase(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('Programming', $html);

        // "0 files" would claim a file store that ProjectDeliverable.php:14-26
        // says does not exist and forbids building.
        $this->assertStringNotContainsString('0 files', $html);
        $this->assertStringNotContainsString('cav-tick', $html);
        $this->assertStringNotContainsString('Marked done by hand', $html);
        $this->assertStringContainsString('Not started', $html);
    }

    /**
     * The design image draws an "Actions" menu, a "…" overflow and three Quick
     * action tiles. All are WRITES (Phase 46/48) and none is rendered here —
     * not even as a disabled placeholder, which would still read as an offer.
     */
    public function test_the_write_affordances_drawn_in_the_design_are_not_rendered(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        foreach (['Actions', 'Quick actions', 'Create visit', 'Add note', 'Upload files'] as $deferred) {
            $this->assertStringNotContainsString(
                $deferred,
                $html,
                "\"{$deferred}\" is a Phase 46/48 write and must not appear in Phase 45."
            );
        }
    }

    public function test_the_last_element_is_the_single_open_full_project_link(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();
        $html    = $this->cockpitSubtree($this->renderCockpit($project));

        $this->assertStringContainsString('Open full project', $html);
        $this->assertStringContainsString(route('projects.show', $project), $html);
        $this->assertSame(1, substr_count($html, 'Open full project'));
    }

    public function test_heading_order_is_one_h1_then_h2s_only(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        preg_match_all('/<h([1-6])\b/', $html, $m);
        $levels = $m[1];

        $this->assertSame('1', $levels[0] ?? null, 'The first heading in the cockpit must be the h1.');
        $this->assertCount(1, array_filter($levels, fn ($l) => $l === '1'), 'Exactly one h1.');
        $this->assertSame([], array_values(array_diff($levels, ['1', '2'])), 'Only h1 and h2 appear in the cockpit subtree.');
    }

    /**
     * The health summary is one card, not the page. When it cannot be derived
     * the module list below is still accurate, and the card says so rather
     * than claiming "On track" — a null health and a healthy project are
     * different facts.
     */
    public function test_health_failure_degrades_only_the_overall_status_card(): void
    {
        config(['cockpit.enabled' => true]);

        $this->app->instance(ProjectHealthService::class, new class extends ProjectHealthService
        {
            public function assess(Project $project): ProjectHealth
            {
                throw new \RuntimeException('health exploded');
            }
        });

        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('The status summary could not be read.', $html);
        $this->assertStringNotContainsString('On track', $html);
        $this->assertSame(
            count(CockpitModulePresenter::moduleMap()),
            $this->countByClass($html, 'cav-module'),
            'The module list still renders in full.'
        );
    }

    /**
     * The accordion is not merely unused — it is GONE. A dead drawer component
     * left on disk is an invitation for a later agent to render one beside the
     * new design.
     */
    public function test_the_superseded_accordion_components_are_deleted(): void
    {
        foreach ([
            'views/projects/_cockpit-drawer.blade.php',
            'views/components/cockpit/section-group.blade.php',
            'views/components/cockpit/drawer.blade.php',
            'views/components/cockpit/pip.blade.php',
            'views/components/cockpit/tick-box.blade.php',

            // Deleted by Plan 45-13. Plan 45-11 made it unreachable when the
            // health summary moved into the Overall status KPI card, and it
            // was the one cockpit view still using {!! !!}. An unrendered
            // Blade file with unescaped output is a loaded gun for a later
            // phase, so it went, and its .cav-attn rules went with it.
            'views/components/cockpit/attention.blade.php',
        ] as $path) {
            $this->assertFileDoesNotExist(resource_path($path), "{$path} is superseded by sketch 004 and must not exist.");
        }

        // Kept deliberately — Plan 45-12's panel body consumes them, and
        // visit-row carries the D-02 and D-04 treatments.
        foreach ([
            'views/components/cockpit/chip.blade.php',
            'views/components/cockpit/hint.blade.php',
            'views/components/cockpit/visit-row.blade.php',
            'views/components/cockpit/doc-row.blade.php',
            'views/components/cockpit/lifecycle-row.blade.php',
        ] as $path) {
            $this->assertFileExists(resource_path($path));
        }
    }

    // ── Plan 45-11, Task 3 — the side panel and its Overview tab ─────────

    private function renderPanel(Project $project, string $module, string $tab = 'overview'): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project, 'module' => $module, 'tab' => $tab]);

        return $this->cockpitSubtree(
            $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent()
        );
    }

    /**
     * A project whose "First fix and install" module has two visits, one of
     * them completed — so the Overview tab has a real 1-of-2 ring to draw.
     */
    private function projectWithInstallVisits(): Project
    {
        $project = $this->project();

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
            'status'         => Visit::STATUS_COMPLETED,
        ]);

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day two',
            'scheduled_date' => '2026-09-03',
            'status'         => Visit::STATUS_PLANNED,
        ]);

        return $project;
    }

    /**
     * D-09, read strictly. "Closed at rest" means ABSENT, not hidden: a
     * hidden-but-present panel would need CSS or JS to hide it and would
     * still be read out by a screen reader.
     */
    public function test_at_rest_there_is_no_panel_element_in_the_dom_at_all(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertSame(0, $this->countByClass($html, 'cav-panel'));
        $this->assertStringNotContainsString('cav-panel', $html);
    }

    public function test_the_panel_renders_its_header_tab_strip_and_close_link(): void
    {
        $project = $this->projectWithInstallVisits();
        $html    = $this->renderPanel($project, 'worksheet');

        $this->assertSame(1, $this->countByClass($html, 'cav-panel'));

        // Header: the module's title, the project's identifier, its purpose.
        $this->assertStringContainsString('First fix and install', $html);
        $this->assertStringContainsString('Manage visits, tasks and evidence.', $html);

        // Tab strip — three anchors, and a close control that is also an
        // anchor, back to the bare cockpit URL.
        $this->assertSame(3, $this->countByClass($html, 'cav-panel__tab'));
        $this->assertStringContainsString('Overview', $html);
        $this->assertStringContainsString('Files', $html);
        $this->assertStringContainsString('Notes', $html);
        $this->assertStringContainsString('href="'.e(route('projects.cockpit', $project)).'"', $html);
    }

    public function test_only_the_active_tab_carries_aria_current_and_nothing_carries_aria_expanded(): void
    {
        $project = $this->projectWithInstallVisits();

        foreach (['overview', 'files', 'notes'] as $tab) {
            $html = $this->renderPanel($project, 'worksheet', $tab);

            $this->assertSame(1, substr_count($html, 'aria-current="page"'), "Exactly one active tab on {$tab}.");
            $this->assertStringNotContainsString('aria-expanded', $html, 'Nothing here is a disclosure widget.');
        }
    }

    /**
     * A tab switch must never close the panel, so every tab anchor carries
     * the current module forward.
     */
    public function test_each_tab_anchor_preserves_the_open_module(): void
    {
        $project = $this->projectWithInstallVisits();
        $html    = $this->renderPanel($project, 'rams', 'files');

        // Not e()'d: cockpitSubtree() decodes entities, so the ampersand
        // between the two query parameters is a bare & by the time it is
        // compared. Escaping here would compare &amp; against & and fail on
        // a correct href.
        foreach (['overview', 'files', 'notes'] as $tab) {
            $this->assertStringContainsString(
                'href="'.route('projects.cockpit', ['project' => $project, 'module' => 'rams', 'tab' => $tab]).'"',
                $html,
                "The {$tab} tab must keep ?module=rams in its href."
            );
        }
    }

    public function test_the_overview_tab_draws_the_ring_and_lists_the_module_visits(): void
    {
        $project = $this->projectWithInstallVisits();
        $html    = $this->renderPanel($project, 'worksheet');

        $this->assertStringContainsString('cav-ring', $html);
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('1 of 2 visits completed', $html);

        // The ring is an <svg>, not a form control — and it is never the only
        // channel: role="img" plus the same sentence as its aria-label.
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="1 of 2 visits completed"', $html);

        // Visits render through the existing component, so the D-02 and D-04
        // treatments still reach the panel.
        $this->assertSame(2, $this->countByClass($html, 'cav-visit'));
        $this->assertStringContainsString('Install day one', $html);
        $this->assertStringContainsString('Install day two', $html);
    }

    /**
     * A 0% ring on a module with nothing planned would read as "nothing
     * done", when the truth is "nothing planned" — a different and less
     * alarming fact. progress() returns null there, and the panel draws no
     * ring at all.
     */
    public function test_a_module_with_no_visits_draws_no_ring(): void
    {
        $html = $this->renderPanel($this->project(), 'snagging');

        $this->assertStringNotContainsString('cav-ring', $html);

        // Asserted on the ring's own sentence rather than on "0%": the
        // documents KPI above legitimately reads 0% on a bare project, and a
        // whole-subtree search for that string would fail on it.
        $this->assertStringNotContainsString('visits completed', $html);
    }

    /**
     * RE-POINTED BY PLAN 45-12, NOT DELETED. This test previously asserted
     * 45-11's one-line stub ("Files and notes arrive in the next plan"), which
     * 45-12 replaced with the real Files and Notes tabs. The coverage it
     * actually carries — both tabs render a body, and neither draws the
     * Overview ring — is kept and re-pointed at the shipped copy. The stub
     * sentence is asserted ABSENT so it cannot creep back.
     */
    public function test_the_files_and_notes_tabs_render_their_own_bodies(): void
    {
        $project = $this->projectWithInstallVisits();

        $expected = [
            'files' => 'No First fix and install documents have been produced yet.',
            'notes' => 'First fix and install has no notes recorded.',
        ];

        foreach ($expected as $tab => $sentence) {
            $html = $this->renderPanel($project, 'worksheet', $tab);

            $this->assertStringContainsString($sentence, $html);
            $this->assertStringNotContainsString('Files and notes arrive in the next plan', $html);
            $this->assertStringNotContainsString('cav-ring', $html, 'The ring belongs to Overview.');
        }
    }

    /**
     * THE NO-JAVASCRIPT RULING, ENFORCED. Alpine is loaded globally by the
     * layout and is deliberately not used here: the fence bans handler
     * attributes inside this region, and weakening a fence to fit a design is
     * the failure the fence exists to catch.
     */
    public function test_the_panel_carries_no_handler_attribute_no_control_and_no_deferred_write_copy(): void
    {
        $project = $this->projectWithInstallVisits();
        $html    = $this->renderPanel($project, 'worksheet');

        foreach (['<button', '<form', '<input', '<select', '<textarea', '<script'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        foreach (['x-data', 'x-show', 'x-init', 'x-if', 'x-text', 'x-on:', '@click', 'wire:', 'onclick'] as $handler) {
            $this->assertStringNotContainsString($handler, $html, "{$handler} is script inside the cockpit region.");
        }

        foreach (['Create visit', 'Add note', 'Upload files', 'Quick actions'] as $deferred) {
            $this->assertStringNotContainsString($deferred, $html, "\"{$deferred}\" is a Phase 46/48 write.");
        }
    }

    /**
     * Iterates the presenter's own keys, so a module added in a later phase is
     * covered here automatically rather than being forgotten.
     */
    public function test_every_module_opens_on_every_tab(): void
    {
        $project = $this->projectWithInstallVisits();

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $key) {
            foreach (['overview', 'files', 'notes'] as $tab) {
                $html = $this->renderPanel($project, $key, $tab);

                $this->assertSame(1, $this->countByClass($html, 'cav-panel'), "{$key}/{$tab} must render one panel.");
            }
        }
    }

    // ── The read-only fence over this phase's own markup ─────────────────

    public function test_the_cockpit_subtree_contains_no_form_control_and_no_script(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        foreach (['<form', '<input', '<select', '<textarea', '<button', '<script'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                "Read-only fence: {$forbidden} must not appear in the cockpit subtree."
            );
        }
    }

    public function test_no_deferred_write_affordance_copy_appears(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        foreach ([
            'Book another survey', 'Prepare a visit', 'Add a snag', 'Book a visit',
            'Issue to client', 'Send a RAMS to the client', 'Upload a drawing',
            'Add document', 'Add anyway', 'Edit details', 'Re-import from QuoteWerks',
            'Close this project', 'Open register', 'Export CSV', 'Download',
        ] as $copy) {
            $this->assertStringNotContainsString(
                $copy,
                $html,
                "Read-only fence: deferred affordance copy \"{$copy}\" must not appear."
            );
        }
    }

    // ── The state primitives ─────────────────────────────────────────────

    /**
     * RETIRED, REHOMED HERE: `test_the_pip_renders_all_three_states_with_shape_and_accessible_name`
     * (Plan 45-06). The traffic-light pip component is deleted. Its real
     * subject was never the pip: it was that STATE SURVIVES GREYSCALE because
     * it is carried by shape and an accessible name, not by colour alone. The
     * status chip is where module state lives now, so the property is asserted
     * against its three variants here.
     */
    public function test_status_chip_renders_its_three_variants_with_a_shape_channel(): void
    {
        foreach ([
            ['not-started', 'cav-schip--wait', 'Not started'],
            ['in-progress', 'cav-schip--live', 'In progress'],
            ['on-file', 'cav-schip--file', 'On file'],
        ] as [$variant, $class, $label]) {
            $html = (string) $this->blade('<x-cockpit.status-chip variant="'.$variant.'" />');

            $this->assertStringContainsString($class, $html, "status chip {$variant} must carry {$class}");
            $this->assertStringContainsString($label, $html);
            // Colour is never the only channel: a glyph element rides with it.
            $this->assertStringContainsString('cav-schip__glyph', $html);
        }
    }

    public function test_kpi_card_draws_its_bar_as_a_div_not_a_progress_element(): void
    {
        $html = (string) $this->blade('<x-cockpit.kpi-card label="Documents" value="1 of 9 complete" :percent="11" />');

        $this->assertStringContainsString('cav-kpi__bar', $html);
        $this->assertStringContainsString('11%', $html);
        // <progress> is form-associated and would read as a control.
        $this->assertStringNotContainsString('<progress', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function test_chip_renders_its_four_variants(): void
    {
        $reconstructed = (string) $this->blade('<x-cockpit.chip variant="reconstructed" />');
        $this->assertStringContainsString('cav-chip--reconstructed', $reconstructed);
        $this->assertStringContainsString('Reconstructed', $reconstructed);

        foreach ([
            ['superseded', 'Superseded'],
            ['not-required', 'Not required'],
            ['record-unavailable', 'Record unavailable'],
        ] as [$variant, $label]) {
            $html = (string) $this->blade('<x-cockpit.chip variant="'.$variant.'" />');
            $this->assertStringContainsString('cav-chip', $html);
            $this->assertStringNotContainsString('cav-chip--reconstructed', $html);
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_tag_and_hint_render(): void
    {
        $tag = (string) $this->blade('<x-cockpit.tag>RAMS issued</x-cockpit.tag>');
        $this->assertStringContainsString('cav-tag', $tag);
        $this->assertStringContainsString('RAMS issued', $tag);

        $hint = (string) $this->blade('<x-cockpit.hint>Where this comes from.</x-cockpit.hint>');
        $this->assertStringContainsString('cav-hint', $hint);
        $this->assertStringContainsString('Where this comes from.', $hint);
    }

    public function test_cockpit_components_carry_no_raw_hex_colour(): void
    {
        $files = glob(resource_path('views/components/cockpit/*.blade.php'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
            $source = preg_replace('/<!--.*?-->/s', '', $source);

            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9A-Fa-f]{6}\b/',
                $source,
                basename($file).' contains a one-off hex colour — use a --cav-* token.'
            );
        }
    }
}

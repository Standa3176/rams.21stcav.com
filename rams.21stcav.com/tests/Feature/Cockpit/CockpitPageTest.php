<?php

namespace Tests\Feature\Cockpit;

use App\DTO\ProjectHealth;
use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectHealthService;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_health_failure_degrades_only_the_attention_line(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();

        $this->app->instance(ProjectHealthService::class, new class extends ProjectHealthService
        {
            public function assess(Project $project): ProjectHealth
            {
                throw new \RuntimeException('health exploded');
            }
        });

        $html = $this->cockpitSubtree($this->renderCockpit($project));

        $this->assertStringContainsString(
            "This job's summary could not be read just now. The sections below are still accurate.",
            $html
        );
        $this->assertStringContainsString('Visits — someone goes to site', $html);
        $this->assertStringContainsString('Cockpit Test Job', $html);
    }

    // ── Task 2 — page shell ──────────────────────────────────────────────

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

    public function test_masthead_renders_the_name_and_sub_line(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('cav-mast', $html);
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Q-451234', $html);
        $this->assertStringContainsString('Installing', $html);
        $this->assertStringContainsString('12 Example Street, Leeds', $html);
    }

    public function test_the_three_section_group_headings_render_in_order(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $visits    = strpos($html, 'Visits — someone goes to site');
        $documents = strpos($html, 'Documents — produced in the office');
        $reference = strpos($html, '>Reference<');

        $this->assertNotFalse($visits);
        $this->assertNotFalse($documents);
        $this->assertNotFalse($reference);
        $this->assertLessThan($documents, $visits);
        $this->assertLessThan($reference, $documents);
    }

    public function test_attention_line_renders_the_nothing_waiting_copy_without_a_gold_rule(): void
    {
        config(['cockpit.enabled' => true]);
        // A fresh installing project with no stage timestamps assesses green.
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('cav-attn', $html);
        $this->assertStringContainsString('Nothing needs you on this job', $html);
        $this->assertStringContainsString('Every section below is either complete or not yet due.', $html);
        $this->assertStringNotContainsString('<em', $html);
    }

    public function test_attention_line_names_a_single_waiting_item_in_a_gold_ruled_em(): void
    {
        config(['cockpit.enabled' => true]);

        $this->app->instance(ProjectHealthService::class, new class extends ProjectHealthService
        {
            public function assess(Project $project): ProjectHealth
            {
                return new ProjectHealth('amber', 'RAMS awaiting review', false);
            }
        });

        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        $this->assertStringContainsString('One thing needs you', $html);
        $this->assertStringContainsString('<em>RAMS awaiting review</em>', $html);
        $this->assertStringNotContainsString('1 thing', $html);
    }

    public function test_footer_note_and_the_single_navigation_link_render(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();
        $html    = $this->cockpitSubtree($this->renderCockpit($project));

        $this->assertStringContainsString('Open the full project page', $html);
        $this->assertStringContainsString(route('projects.show', $project), $html);
        $this->assertStringContainsString(
            'Visits group by type, so a three-day install is one line until you open it.',
            $html
        );
        $this->assertStringNotContainsString('task planner', $html);
    }

    public function test_heading_order_is_one_h1_then_h2s_only(): void
    {
        config(['cockpit.enabled' => true]);
        $html = $this->cockpitSubtree($this->renderCockpit($this->project()));

        preg_match_all('/<h([1-6])\b/', $html, $m);
        $levels = $m[1];

        $this->assertSame('1', $levels[0] ?? null, 'The first heading in the cockpit must be the h1 project name.');
        $this->assertCount(1, array_filter($levels, fn ($l) => $l === '1'), 'Exactly one h1.');
        $this->assertGreaterThanOrEqual(
            4,
            count(array_filter($levels, fn ($l) => $l === '2')),
            'h2 for the attention line plus one per section group.'
        );
        $this->assertSame([], array_values(array_diff($levels, ['1', '2'])), 'Only h1 and h2 appear in the cockpit subtree.');
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

    // ── Task 3 — the state primitives ────────────────────────────────────

    public function test_pip_renders_all_three_states_with_shape_and_accessible_name(): void
    {
        foreach ([
            ['done', 'cav-pip--done', 'Complete'],
            ['attention', 'cav-pip--attn', 'Needs attention'],
            ['waiting', 'cav-pip--wait', 'Not started'],
        ] as [$state, $class, $label]) {
            $html = (string) $this->blade('<x-cockpit.pip state="'.$state.'" />');

            $this->assertStringContainsString($class, $html, "pip state {$state} must carry {$class}");
            $this->assertStringContainsString('role="img"', $html);
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
        }
    }

    public function test_tick_box_renders_unticked_by_default_and_is_a_square(): void
    {
        $html = (string) $this->blade('<x-cockpit.tick-box />');

        $this->assertStringContainsString('cav-tick', $html);
        $this->assertStringNotContainsString('cav-tick--on', $html);
        $this->assertStringContainsString('aria-label="Not marked"', $html);
        $this->assertStringNotContainsString('✓', $html);
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

<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\User;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 45, Plan 45-15 — THE VISUAL CONTRACT.
 *
 * The user compared the built page against sketch 004 and said it was "a lot
 * less colourful and tier 1 than my example". They were right, and the cause
 * was a SPECIFICATION gap rather than a defect: Plan 45-09 derived every token
 * from the application's own palette, which has one accent, while the design
 * uses nine module hues. 45-15 added the hues. This file exists so that the
 * three things which make that addition safe cannot rot:
 *
 *   1. Every module has its OWN hue, and no two share one. Asserted over the
 *      presenter's map rather than over a hand-written list of nine, so a
 *      tenth module added without a hue fails here instead of rendering an
 *      untinted tile that nobody notices for a release.
 *   2. No hex literal reaches the rendered page. Colour lives in
 *      cav-tokens.css on `.cav-brand` and nowhere else — that scoping is the
 *      only reason the cockpit does not retone the other 2,154 lines of
 *      shared layout.
 *   3. The things the hues could have QUIETLY BROKEN still hold: state is
 *      still carried by a glyph shape and by words, not by colour, so the page
 *      survives greyscale; and "Open drawer" is still an <a>, because the
 *      read-only fence bans <button> and a restyle is exactly the kind of
 *      change that turns a link into one by accident.
 *
 * Assertions run against the extracted `cav-cockpit` subtree, never the whole
 * document — the shared layout carries its own inline colours, a logout form
 * and @vite tags, all pre-existing global chrome and all out of scope by the
 * decision recorded in 45-06-PLAN.md.
 */
class CockpitVisualTest extends TestCase
{
    use InteractsWithViews;
    use RefreshDatabase;

    /** Must match CockpitPanelPresenter::AVATAR_HUES and --cav-av-0..5. */
    private const AVATAR_HUES = 6;

    private function subtree(string $html): string
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

    private function page(?Project $project = null, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        $project ??= Project::factory()->create([
            'name'   => 'Visual Contract Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $url = route('projects.cockpit', ['project' => $project] + $query);

        return $this->subtree(
            $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent()
        );
    }

    // -- 1. Nine modules, nine distinct hues ---------------------------------

    /**
     * Iterated over the PRESENTER'S MAP, not over a literal nine. The count
     * assertion is deliberately `count($map)` rather than `9`: D-16 has
     * already changed that number once, and a test that hardcodes it would
     * have to be edited by whoever changes it next — which is how a test stops
     * being a constraint and becomes a chore.
     */
    public function test_every_module_declares_its_own_distinct_hue(): void
    {
        $map = CockpitModulePresenter::moduleMap();

        $hues = [];

        foreach ($map as $key => $definition) {
            $this->assertArrayHasKey(
                'hue',
                $definition,
                "Module [{$key}] has no hue. Add one to MODULE_MAP and a matching .cav-hue--* rule in cockpit.css."
            );

            $this->assertNotEmpty($definition['hue'], "Module [{$key}] has an empty hue.");

            $hues[] = $definition['hue'];
        }

        $this->assertCount(
            count($map),
            array_unique($hues),
            'Two modules share a hue. A hue is a module IDENTITY, so sharing one makes two rows '
            .'indistinguishable: '.implode(', ', $hues)
        );
    }

    /**
     * The hue must actually REACH THE PAGE. The presenter could carry nine
     * perfect keys and Blade could drop them on the floor, and the assertion
     * above would still be green.
     */
    public function test_every_module_row_renders_its_hue_class(): void
    {
        $html = $this->page();

        foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
            $this->assertStringContainsString(
                'cav-hue--'.$definition['hue'],
                $html,
                "Module [{$key}] renders no cav-hue--{$definition['hue']} class."
            );
        }

        // The tile itself, once per module. A hue class with no tile to paint
        // would pass the loop above and still render nothing.
        $this->assertGreaterThanOrEqual(
            count(CockpitModulePresenter::moduleMap()),
            substr_count($html, 'cav-tile'),
            'Fewer tinted tiles rendered than there are modules.'
        );
    }

    /**
     * EVERY DECLARED HUE HAS A RULE AND A TOKEN PAIR. A class can render and
     * resolve to nothing at all — a token typo fails SILENTLY in CSS, which is
     * the exact failure mode cav-tokens.css's own docblock warns about.
     */
    public function test_every_declared_hue_has_a_rule_and_a_token_pair(): void
    {
        $css    = file_get_contents(resource_path('css/cockpit.css'));
        $tokens = file_get_contents(resource_path('css/cav-tokens.css'));

        foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
            $hue = $definition['hue'];

            $this->assertStringContainsString(
                '.cav-hue--'.$hue.' ',
                $css,
                "No .cav-hue--{$hue} rule in cockpit.css for module [{$key}]."
            );

            foreach (['bg', 'fg'] as $half) {
                $this->assertStringContainsString(
                    '--cav-hue-'.$hue.'-'.$half.':',
                    $tokens,
                    "No --cav-hue-{$hue}-{$half} token on .cav-brand for module [{$key}]."
                );
            }
        }
    }

    // -- 2. No colour outside the token file ---------------------------------

    /**
     * No raw colour anywhere in the RENDERED region. `CockpitPageTest` already
     * greps the component SOURCE; this asserts the same thing about the
     * output, which additionally covers projects/cockpit.blade.php, any value
     * a presenter might hand over, and any style attribute composed at
     * runtime.
     *
     * The one inline style this page legitimately carries is a progress bar's
     * `width: n%`, which contains no colour.
     */
    public function test_no_colour_literal_reaches_the_rendered_cockpit(): void
    {
        $html = $this->page();

        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9A-Fa-f]{6}\b/',
            $html,
            'A hex colour reached the rendered cockpit. Colour belongs in cav-tokens.css on .cav-brand.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:rgb|hsl)a?\s*\(/i',
            $html,
            'A literal rgb()/hsl() colour reached the rendered cockpit.'
        );
    }

    /**
     * `.cav-brand` REMAINS THE TOKEN FILE'S ONLY SELECTOR. This is what keeps
     * the cockpit from retoning every other authenticated page, and adding a
     * second selector is a one-line change that looks harmless in a diff.
     */
    public function test_cav_brand_is_still_the_only_selector_in_the_token_file(): void
    {
        $tokens = file_get_contents(resource_path('css/cav-tokens.css'));

        // Strip comments first, then take every selector preceding a brace.
        $stripped = preg_replace('#/\*.*?\*/#s', '', $tokens);

        preg_match_all('/([^{}]+)\{/', $stripped, $matches);

        $selectors = array_values(array_filter(array_map('trim', $matches[1])));

        $this->assertSame(
            ['.cav-brand'],
            $selectors,
            'cav-tokens.css must declare tokens on .cav-brand and nothing else. Found: '.implode(' | ', $selectors)
        );
    }

    // -- 3. What the restyle could have quietly broken -----------------------

    /**
     * THE GREYSCALE GUARANTEE, re-asserted here because 45-15 touched the
     * chip. Each state carries a distinct glyph SHAPE and spells itself out in
     * words; colour is the third channel, never the only one. A greyscale
     * render, and a colour-blind reader, still distinguish all three.
     */
    public function test_the_status_chip_still_carries_a_glyph_and_its_state_in_words(): void
    {
        foreach ([
            ['not-started', 'Not started', 'cav-schip--wait'],
            ['in-progress', 'In progress', 'cav-schip--live'],
            ['on-file',     'On file',     'cav-schip--file'],
        ] as [$variant, $words, $shapeClass]) {
            $html = (string) $this->blade('<x-cockpit.status-chip variant="'.$variant.'" />');

            $this->assertStringContainsString($words, $html, "The {$variant} chip no longer states itself in words.");
            $this->assertStringContainsString('cav-schip__glyph', $html, "The {$variant} chip lost its glyph.");
            $this->assertStringContainsString($shapeClass, $html, "The {$variant} chip lost the class that gives it its shape.");
        }

        // The three shapes are drawn by three DIFFERENT rules. One rule for
        // all three would mean one shape, and colour alone would carry state.
        $css = file_get_contents(resource_path('css/cockpit.css'));

        foreach (['wait', 'live', 'file'] as $variant) {
            $this->assertStringContainsString(
                '.cav-schip--'.$variant.' .cav-schip__glyph',
                $css,
                "The {$variant} chip's glyph has no shape rule of its own."
            );
        }
    }

    /**
     * "OPEN DRAWER" IS AN ANCHOR. The read-only fence bans <button> inside
     * this region, and the design draws the ACTIVE row's affordance as a
     * filled dark button — precisely the styling change most likely to tempt
     * someone into changing the element to match. It stays an <a> because the
     * panel's state is URL state and opening it is a GET.
     */
    public function test_open_drawer_is_an_anchor_even_when_the_row_is_active(): void
    {
        $project = Project::factory()->create([
            'name'   => 'Anchor Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $first = array_key_first(CockpitModulePresenter::moduleMap());

        foreach ([[], ['module' => $first]] as $query) {
            $html = $this->page($project, $query);

            $this->assertStringNotContainsString('<button', $html, 'A <button> appeared in the cockpit region.');

            $this->assertMatchesRegularExpression(
                '/<a\b[^>]*class="[^"]*cav-module__open[^"]*"/',
                $html,
                'The "Open drawer" affordance is no longer an anchor.'
            );

            $this->assertStringContainsString('Open drawer', $html);
        }

        // The active row is styled as a filled button by CSS, not by markup.
        $css = file_get_contents(resource_path('css/cockpit.css'));

        $this->assertStringContainsString(
            '.cav-module--active .cav-module__open',
            $css,
            'The active row no longer has its filled-link rule.'
        );
    }

    /**
     * A PERSON KEEPS THEIR COLOUR. The slot is `user_id % 6`, decided in
     * CockpitPanelPresenter, so the same actor renders the same circle on
     * every load — an avatar that reshuffled per render would read as a
     * different person each time.
     */
    public function test_an_actor_keeps_the_same_avatar_hue_across_renders(): void
    {
        $project = Project::factory()->create([
            'name'   => 'Avatar Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $actor = User::factory()->create(['name' => 'Jack Brown']);

        ProjectActivityLog::create([
            'project_id'  => $project->id,
            'user_id'     => $actor->id,
            'action'      => 'note_added',
            'description' => 'Left a note.',
        ]);

        $module   = array_key_first(CockpitModulePresenter::moduleMap());
        $expected = 'cav-av--'.($actor->id % self::AVATAR_HUES);

        foreach ([1, 2] as $ignored) {
            $html = $this->page($project, ['module' => $module]);

            $this->assertStringContainsString(
                $expected,
                $html,
                'The activity avatar did not render its user_id-derived hue slot.'
            );
        }
    }
}

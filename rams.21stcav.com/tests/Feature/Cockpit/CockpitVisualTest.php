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
 *      survives greyscale; and the row's open affordance is still an <a>,
 *      because the read-only fence bans <button> and a restyle is exactly
 *      the kind of change that turns a link into one by accident.
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

    /**
     * THE GENERATE GREEN RESOLVES TO A REAL TOKEN PAIR (2026-09-27, item 9).
     *
     * A token typo fails SILENTLY in CSS — the rule applies and the background
     * simply does not change, so the button stays teal and every markup
     * assertion still passes. The class, the rule and both tokens are therefore
     * asserted together, and the CONTRAST FIGURES are asserted as recorded text
     * because the token file's own docblock requires them (text 4.5:1).
     */
    public function test_the_generate_green_has_a_rule_and_a_token_pair_with_recorded_contrast(): void
    {
        $css    = file_get_contents(resource_path('css/cockpit.css'));
        $tokens = file_get_contents(resource_path('css/cav-tokens.css'));

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-qa__go \{[^}]*background:\s*var\(--cav-go\)/s',
            $css,
            'The green generate control has no rule, or it does not read the token.'
        );

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-qa__go:hover \{[^}]*background:\s*var\(--cav-go-dark\)/s',
            $css,
            'The green hover state has no rule, or it does not read the token.'
        );

        foreach (['--cav-go:', '--cav-go-dark:'] as $token) {
            $this->assertStringContainsString($token, $tokens, "{$token} is not declared as a token.");
        }

        // RECORDED, not just measured once and forgotten. White button text on
        // both greens passes AA; the hover is DARKER for that reason.
        $this->assertStringContainsString('5.01:1', $tokens, 'The --cav-go contrast figure is not recorded.');
        $this->assertStringContainsString('7.12:1', $tokens, 'The --cav-go-dark contrast figure is not recorded.');

        // ── THE STRETCHED-LINK TRAP, RE-CHECKED FOR THIS RULE BY NAME ───────
        // `.cav-qa__go` lands on an anchor inside `.cav-module`, where
        // `position: relative` on the row plus `inset: 0` on
        // `.cav-module__open::after` is what makes the WHOLE ROW clickable. A
        // `position` OR a `transform` in this rule collapses that target onto a
        // 28px glyph with nothing else failing.
        $scanned = 0;

        foreach ($this->cssRules($css) as $rule) {
            $touchesGo = false;

            foreach ($rule['selectors'] as $selector) {
                if (str_contains($selector, '.cav-qa__go')) {
                    $touchesGo = true;
                }
            }

            if (! $touchesGo) {
                continue;
            }

            foreach (['position:', 'transform:'] as $property) {
                $this->assertStringNotContainsString(
                    $property,
                    $rule['body'],
                    'The green control rule declares '.$property.' — that collapses the row click target.'
                );
            }

            $scanned++;
        }

        $this->assertSame(2, $scanned, 'This proof scanned '.$scanned.' `.cav-qa__go` rules: the base and the hover.');
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
     * THE OPEN AFFORDANCE IS AN ANCHOR. The read-only fence bans <button>
     * inside this region, and the design draws the ACTIVE row's affordance
     * as a filled dark control — precisely the styling change most likely to
     * tempt someone into changing the element to match. It stays an <a>
     * because the panel's state is URL state and opening it is a GET.
     *
     * RETARGETED BY QUICK TASK 260920, honestly rather than conveniently.
     * This test used to assert the visible copy "Open drawer". That copy is
     * gone: the user asked for the whole row to open the panel, so the pill
     * became a chevron and the row became a stretched link. Simply deleting
     * the copy assertion would have left the anchor's identity half-proved,
     * so what replaced it is STRONGER — the link's accessible name is now
     * asserted (it is the only name the link has), and the old copy is
     * asserted ABSENT so this test cannot pass on a half-reverted change.
     */
    public function test_the_open_affordance_is_an_anchor_even_when_the_row_is_active(): void
    {
        $project = Project::factory()->create([
            'name'   => 'Anchor Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $map   = CockpitModulePresenter::moduleMap();
        $first = array_key_first($map);

        foreach ([[], ['module' => $first]] as $query) {
            $html   = $this->page($project, $query);
            $isOpen = $query !== [];

            $this->assertStringNotContainsString('<button', $html, 'A <button> appeared in the cockpit region.');

            $this->assertMatchesRegularExpression(
                '/<a\b[^>]*class="[^"]*cav-module__open[^"]*"/',
                $html,
                'The row-open affordance is no longer an anchor.'
            );

            // NARROWED PER STATE BY QUICK TASK 260927-tgl. This used to assert
            // `aria-label="Open {title}"` in BOTH renders. It cannot any more,
            // and narrowing it is the honest move rather than deleting it: the
            // row is now a TOGGLE, so while it is open its anchor closes the
            // drawer, and a name reading "Open" on a control that closes is a
            // lie in the one channel a screen-reader user has (the glyph is
            // aria-hidden, so this label is the link's ONLY name).
            //
            // The wrong name is asserted ABSENT in each state as well as the
            // right one PRESENT, so a row that names both states at once — or
            // that reverts to naming one of them in both — fails here.
            $expected = ($isOpen ? 'Close ' : 'Open ').$map[$first]['title'];
            $wrong    = ($isOpen ? 'Open ' : 'Close ').$map[$first]['title'];

            $this->assertStringContainsString(
                'aria-label="'.$expected.'"',
                $html,
                'The chevron link lost the accessible name that is now its only one.'
            );

            $this->assertStringNotContainsString(
                'aria-label="'.$wrong.'"',
                $html,
                'The row-open affordance names the state it is NOT in.'
            );

            $this->assertStringNotContainsString('Open drawer', $html);
        }

        // The active row is styled by CSS, not by markup.
        $css = file_get_contents(resource_path('css/cockpit.css'));

        $this->assertStringContainsString(
            '.cav-module--active .cav-module__open',
            $css,
            'The active row no longer has its own chevron rule.'
        );
    }

    /**
     * THE WHOLE ROW OPENS THE PANEL, AND IT DOES SO WITH NO JAVASCRIPT.
     *
     * WHAT THIS TEST MEASURES, IN ONE SENTENCE: that the stretched-link
     * PAIRING holds — `.cav-module` is the containing block, the anchor's
     * `::after` is the full-row overlay, NO rule gives `.cav-module__open`
     * a `position` of its own and no rule takes the containing block away —
     * with the one-anchor-per-row half asserted against the rendered DOM in
     * the sibling test below.
     *
     * The user asked for the row itself to be clickable. There are two ways
     * to grant that and only one is available here: a click handler is
     * banned inside this region by CockpitReadOnlyFenceTest, so the row is a
     * STRETCHED LINK — `.cav-module { position: relative }` plus an
     * `::after { inset: 0 }` on the single anchor that was already there.
     *
     * Both halves are asserted because either one alone is useless AND
     * silent: an overlay with no positioned ancestor collapses onto the 28px
     * chevron and the row quietly stops being clickable, with every other
     * test on this page still green. Nothing else in the suite would notice.
     *
     * WIDENED BY PLAN 46.3-01 (DL-06), BEFORE ANY LAYOUT MOVED. Up to here
     * the test proved the two declarations that must be PRESENT and nothing
     * about the ones that must be ABSENT — and a `position: absolute` added
     * to `.cav-module__open` itself satisfies both of the original regexes
     * while collapsing the overlay onto the 28px chevron. cockpit.css warns
     * about exactly that in prose at four separate places (`:393`, `:482`,
     * `:1471`, `:1676`), which is the shape of a rule that has never been
     * executable. Waves 2 and 3 of this phase restructure this row, so the
     * assertion is strengthened FIRST and was proven to bite by injecting
     * `position: absolute` and watching it go red.
     */
    public function test_the_whole_module_row_is_the_click_target_without_javascript(): void
    {
        $css = file_get_contents(resource_path('css/cockpit.css'));

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module \{[^}]*position:\s*relative/s',
            $css,
            'The module row is not a positioned ancestor, so the stretched overlay '.
            'has nothing to stretch to and only the chevron stays clickable.'
        );

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module__open::after \{[^}]*inset:\s*0/s',
            $css,
            'The anchor no longer stretches across the row.'
        );

        // The pointer and the tint are the row telling the truth about being
        // clickable. The ban on both survives for the rows that are NOT —
        // asserted from the other side in the visit-row test below.
        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module:hover \{[^}]*cursor:\s*pointer/s',
            $css,
            'A row that opens on click must say so with the pointer.'
        );

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module:hover \{[^}]*background:\s*var\(--cav-row-hover\)/s',
            $css,
            'The row lost its hover tint.'
        );

        // FOCUS MUST RING THE ROW, NOT THE CHEVRON. A 28px ring in a list of
        // nine rows tells a keyboard user almost nothing about where they
        // are, so the outline moves onto the full-width overlay.
        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module__open:focus-visible::after \{[^}]*outline:\s*2px solid var\(--cav-focus\)/s',
            $css,
            'The keyboard focus ring does not surround the row.'
        );

        // The two hover tints are TOKENS. No colour may be authored outside
        // cav-tokens.css, whose only selector is .cav-brand.
        $tokens = file_get_contents(resource_path('css/cav-tokens.css'));

        foreach (['--cav-row-hover:', '--cav-row-hover-active:'] as $token) {
            $this->assertStringContainsString($token, $tokens, "{$token} is not declared as a token.");
        }

        // The OPEN row must not fall back to the neutral hover, which would
        // read as "this row is no longer the one you have open".
        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-module--active:hover \{[^}]*background:\s*var\(--cav-row-hover-active\)/s',
            $css,
            'The active row loses its marker on hover.'
        );

        // ── THE HALF THAT WAS ASSERTED NOWHERE (Plan 46.3-01, DL-06) ────────
        //
        // `inset: 0` on the ::after resolves against `.cav-module` ONLY while
        // `.cav-module__open` itself stays statically positioned. Give the
        // anchor a `position` and the overlay resolves against the ANCHOR — a
        // 28px box — and the row silently stops being clickable while every
        // regex above still matches.
        //
        // Scanned as RULES, not as page text: the selector must END in
        // `.cav-module__open`, so `::after` and `:focus-visible::after` — which
        // are SUPPOSED to be positioned — are excluded by construction rather
        // than by a blacklist that a new pseudo-element would slip past.
        $rules   = $this->cssRules($css);
        $scanned = [];

        foreach ($rules as $rule) {
            foreach ($rule['selectors'] as $selector) {
                if (! str_ends_with($selector, '.cav-module__open')) {
                    continue;
                }

                $scanned[] = $selector;

                $this->assertDoesNotMatchRegularExpression(
                    '/(?:^|[;{}\s])position\s*:/',
                    $rule['body'],
                    "`{$selector}` declares a `position` of its own. That is the ONE edit that ".
                    'breaks the stretched link, and it breaks it silently: the overlay stops '.
                    'resolving against `.cav-module` and collapses onto the 28px chevron. '.
                    'Move the declaration onto `.cav-module__open::after` or remove it.'
                );
            }
        }

        // NON-VACUITY, AS AN EXACT MEMBERSHIP RATHER THAN A FLOOR. A renamed
        // class would otherwise leave the loop scanning nothing and passing
        // loudly. Named rather than counted so a later wave adding a rule does
        // not have to move a number, while a later wave DELETING the base rule
        // still fails here.
        $this->assertContains(
            '.cav-cockpit .cav-module__open',
            $scanned,
            'The base `.cav-module__open` rule was not scanned — this check is measuring nothing. '.
            'If the class was renamed, retarget this scan in the commit that renames it.'
        );

        // ── AND NOTHING MAY TAKE THE CONTAINING BLOCK AWAY ──────────────────
        //
        // The other end of the same pairing. A `position: static` on
        // `.cav-module` — or any value other than `relative` — removes the
        // containing block and the overlay stretches to the nearest positioned
        // ancestor instead, which is the page.
        //
        // "BETWEEN" `.cav-module` AND `.cav-module__open` THERE IS NOTHING
        // TODAY: the anchor is a DIRECT CHILD of the row, which the sibling
        // test asserts against the rendered DOM. If a later plan introduces a
        // wrapper, THIS PAIR OF ASSERTIONS IS THE ONE TO EXTEND — extending
        // them to cover the wrapper is maintenance; deleting them because the
        // markup moved is the failure they exist to catch.
        $rowRules = [];

        foreach ($rules as $rule) {
            foreach ($rule['selectors'] as $selector) {
                if (! str_ends_with($selector, '.cav-module') && ! str_ends_with($selector, '.cav-module--active')) {
                    continue;
                }

                $rowRules[] = $selector;

                if (! preg_match('/(?:^|[;{}\s])position\s*:\s*([a-z-]+)/', $rule['body'], $found)) {
                    continue;
                }

                $this->assertSame(
                    'relative',
                    $found[1],
                    "`{$selector}` declares `position: {$found[1]}`. The module row must stay the ".
                    'containing block for the anchor overlay; anything but `relative` un-stretches '.
                    'the link with nothing else failing.'
                );
            }
        }

        $this->assertContains(
            '.cav-cockpit .cav-module',
            $rowRules,
            'The base `.cav-module` rule was not scanned — this check is measuring nothing.'
        );
    }

    /**
     * EXACTLY ONE ANCHOR PER MODULE ROW, AND NOTHING ELSE INTERACTIVE IN IT.
     *
     * WHAT THIS MEASURES: the DOM half of the stretched link. Every assertion
     * in the sibling test above reads CSS TEXT and none of them reads the page,
     * so all of them would pass on markup that cannot work.
     *
     * The pattern only holds while the row holds ONE anchor. A second one — a
     * "Files" shortcut, a download, a chip that became a link — would sit
     * UNDERNEATH the full-row overlay and be unreachable by mouse, while
     * remaining perfectly reachable by keyboard and perfectly invisible to
     * every CSS assertion in this file. A <button> or a `tabindex` would be
     * the same failure wearing different markup, and both are banned in this
     * region anyway (CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES).
     *
     * The anchor is also asserted to be a DIRECT CHILD of the row. That is the
     * executable form of "there is nothing between `.cav-module` and
     * `.cav-module__open`", which the CSS-side containing-block assertion
     * relies on. A wrapper introduced later must be covered there too.
     *
     * Plan 46.3-01, DL-06.
     *
     * ── ONE ASSERTION RETIRED BY NAME, QUICK TASK 260927-tgl ────────────────
     *
     * RETIRED: the single-state href check that read, for EVERY row,
     *
     *     assertStringContainsString('module='.$keyForTitle[$title], $href)
     *
     * It was never wrong; it was VACUOUS for the row that matters. This test
     * rendered only the CLOSED page, where no row is open, so it could not
     * see the open row at all — and the open row's anchor pointing at
     * `?module={its own key}` was exactly the defect the user reported: the
     * URL the browser is already on, so a second click did nothing and the
     * drawer could not be shut from the row that opened it.
     *
     * SUCCESSOR, and it is strictly stronger because it asserts BOTH HALVES
     * of the toggle over BOTH renders:
     *
     *   * a CLOSED row's anchor opens that module — `module={its own key}`;
     *   * the OPEN row's anchor returns to the BARE list — it equals the
     *     bare cockpit URL and carries no `module=` at all.
     *
     * Either half alone is satisfiable by a broken page. Only the closed half
     * passes on the defect that was shipped. Only the open half passes on a
     * page where EVERY row closes the drawer and nothing opens one. Both
     * together are the property, and both are counted below so that a render
     * producing none of one kind fails loudly rather than passing on an empty
     * loop.
     */
    public function test_a_closed_row_opens_its_module_and_the_open_row_returns_to_the_list(): void
    {
        $project = Project::factory()->create([
            'name'   => 'Toggle Contract Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $map     = CockpitModulePresenter::moduleMap();
        $openKey = array_key_first($map);
        $bare    = route('projects.cockpit', $project);

        // Title -> key, read off the presenter's own map: a closed row's href
        // must carry the key of the row it sits in, never a neighbour's. Nine
        // rows all linking to the first module is a real regression a
        // per-page `assertStringContainsString` cannot see.
        $keyForTitle = [];

        foreach ($map as $key => $definition) {
            $keyForTitle[$definition['title']] = $key;
        }

        $closedSeen = 0;
        $openSeen   = 0;

        foreach ([[], ['module' => $openKey]] as $query) {
            $html = $this->page($project, $query);

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $rows  = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module ')]");

            // Derived, never literal: the whole list while nothing is open,
            // and the one surviving row once something is (46.3 D-02). The
            // collapse-away guard itself lives in CockpitPageTest; this is
            // only the non-vacuity floor for the loop below.
            $this->assertSame(
                $query === [] ? count($map) : 1,
                $rows->length,
                'The row list did not render as expected, so this test would be measuring the wrong page.'
            );

            foreach ($rows as $row) {
                $titleNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-module__title ')]", $row)->item(0);
                $this->assertNotNull($titleNode, 'A module row rendered no title.');

                $title = trim($titleNode->textContent);
                $this->assertArrayHasKey($title, $keyForTitle, "The row titled \"{$title}\" is in no module map.");

                $anchors = $xpath->query('.//a', $row);

                $this->assertSame(
                    1,
                    $anchors->length,
                    "The \"{$title}\" row holds {$anchors->length} anchors. The stretched link needs ".
                    'EXACTLY ONE: a second anchor sits under the full-row overlay and cannot be '.
                    'clicked, which no CSS assertion in this file can see.'
                );

                $anchor = $anchors->item(0);

                $this->assertSame(
                    $row,
                    $anchor->parentNode,
                    "The \"{$title}\" row's anchor is no longer a direct child of the row. A wrapper ".
                    'between them may introduce a containing block of its own — extend the '.
                    'containing-block scan in the sibling test to cover it, in this same commit.'
                );

                $href     = $anchor->getAttribute('href');
                $isOpenRow = $query !== [] && $keyForTitle[$title] === $openKey;

                if ($isOpenRow) {
                    ++$openSeen;

                    $this->assertSame(
                        $bare,
                        $href,
                        "The \"{$title}\" row is OPEN, so its anchor must go back to the bare module ".
                        'list. Pointing it at its own key aims it at the URL the browser is already '.
                        'on, which is the defect the user reported: the row that opened the drawer '.
                        'could not shut it.'
                    );

                    $this->assertStringNotContainsString(
                        'module=',
                        urldecode($href),
                        "The \"{$title}\" row is OPEN and still carries a `module=` in its href."
                    );

                    // The name is the link's ONLY name — the glyph is
                    // aria-hidden — so it has to move with the destination.
                    $this->assertSame(
                        'Close '.$title,
                        $anchor->getAttribute('aria-label'),
                        "The \"{$title}\" row closes the drawer but does not say so."
                    );
                } else {
                    ++$closedSeen;

                    $this->assertStringContainsString(
                        'module='.$keyForTitle[$title],
                        urldecode($href),
                        "The \"{$title}\" row is CLOSED, so its anchor must open its own module."
                    );

                    $this->assertSame(
                        'Open '.$title,
                        $anchor->getAttribute('aria-label'),
                        "The \"{$title}\" row opens a module but does not say so."
                    );
                }

                $this->assertSame(
                    0,
                    $xpath->query('.//button', $row)->length,
                    "The \"{$title}\" row holds a <button>. Opening a module is a GET, and the fence bans it."
                );

                $this->assertSame(
                    0,
                    $xpath->query('.//*[@tabindex]', $row)->length,
                    "The \"{$title}\" row holds a `tabindex`. The one anchor is the whole tab stop."
                );
            }
        }

        // NON-VACUITY, one count per half. A page where every row is a toggle
        // leaves `closedSeen` at zero; a page where no row is leaves
        // `openSeen` at zero. Either would otherwise pass on a loop that
        // simply never reached the branch it was meant to prove.
        $this->assertSame(count($map), $closedSeen, 'No closed row was measured — the opening half is vacuous.');
        $this->assertSame(1, $openSeen, 'No open row was measured — the closing half is vacuous.');
    }

    /**
     * cockpit.css as RULES rather than as one long string.
     *
     * Comments are stripped FIRST and deliberately: four separate comment
     * blocks in that file warn, in prose, about the exact declaration the
     * scan above hunts for. A scan that read prose as code would fail on the
     * warning instead of on the bug — which is worse than not scanning, because
     * it would be fixed by deleting the warning.
     *
     * Nested at-rules need no special handling: the body pattern excludes
     * braces, so a `@media` prelude never matches and its INNER rules do.
     *
     * @return list<array{selectors: list<string>, body: string}>
     */
    private function cssRules(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', (string) $css, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            $selectorList = trim($match[1]);

            if ($selectorList === '' || str_starts_with($selectorList, '@')) {
                continue;
            }

            $rules[] = [
                'selectors' => array_values(array_filter(array_map('trim', explode(',', $selectorList)))),
                'body'      => $match[2],
            ];
        }

        return $rules;
    }

    /**
     * THE MODULE ROW BECAME CLICKABLE; THE VISIT ROW DID NOT.
     *
     * The doctrine at the top of cockpit.css is "a row that looks clickable
     * but is not is worse than one that looks inert". Quick task 260920 gave
     * the module row a pointer because it genuinely became a link. This test
     * exists so that nobody reads that change as permission to do the same
     * to a row that is still static — visits become openable in Phase 46.
     */
    public function test_the_static_visit_row_still_advertises_nothing(): void
    {
        $css = file_get_contents(resource_path('css/cockpit.css'));

        $this->assertMatchesRegularExpression(
            '/\.cav-cockpit \.cav-visit \{[^}]*\}/s',
            $css,
            'The visit row rule has gone.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.cav-cockpit \.cav-visit(:hover)? \{[^}]*cursor:\s*pointer/s',
            $css,
            'The visit row is static until Phase 46 and must not advertise a click.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.cav-cockpit \.cav-visit:hover \{/s',
            $css,
            'The visit row gained a hover state it cannot honour.'
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

        // JUDGED AND LEFT UNCHANGED BY 46.3 D-03. The feed moved out of the
        // module panel into its own project-level panel, so this assertion no
        // longer NEEDS `?module=` to see an avatar — but it is kept, because
        // opening a module is the stricter render: it proves the hue survives
        // on the page where the drawer is also competing for the markup. The
        // avatar's own component and its six fills were not touched.
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

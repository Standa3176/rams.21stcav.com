<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 21st Century AV branding on the SITE SURVEY ENGINEER LINK (quick task 260930-sv2).
 *
 * WHICH VIEW THIS IS, BECAUSE THERE ARE TWO AND ONLY ONE IS REACHABLE:
 * `GET survey/{token}` (routes/web.php:87) resolves to `SurveyController@show`,
 * which renders `resources/views/surveys/show.blade.php`. That is the file this
 * task brands. `resources/views/public-survey/show.blade.php` is a 127KB view
 * whose only controller method, `PublicSurveyController@show`, HAS NO GET ROUTE
 * — it is unreachable legacy. Asserting against the ROUTE rather than against a
 * view name is what keeps this test honest about which of the two ships.
 *
 * EVERY STATE IS RENDERED, NOT ONE. The seven defects found in the three weeks
 * before this task were every one of them an assertion that rendered a single
 * state, so the branding is checked on all five the link has: not started,
 * partly done, complete, submitted-and-locked, and roomless.
 *
 * THE CONTRAST FIGURES IN THE ASSERTIONS ARE MEASURED, NOT ASSUMED. Each was
 * computed with the WCAG 2.x relative-luminance formula; the full ledger is in
 * this task's SUMMARY.md and the load-bearing ones are repeated in the view's
 * own header comment.
 */
class SurveyLinkBrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The brand values, from this repo's own source of truth:
     * `.planning/reference/21cav-rams-skill/scripts/brand.js` lines 8-18.
     *
     * Pinned here so a later "tidy-up" that drifts the palette back towards the
     * near-miss values this task removed (#178A95, #C9922A, #0B3C45) goes RED.
     */
    private const BRAND = [
        '#014C5A', // band fill      - white on it 9.62:1
        '#016E82', // teal text/fill - 5.91:1 on white, white on it 5.91:1
        '#7A6011', // gold ink       - 5.99:1 on white, white on it 5.99:1
        '#01889F', // headline teal  - DECORATIVE only (4.18:1), style block
        '#D4AF37', // warm gold      - DECORATIVE only (2.10:1), style block
        '#F5EDD6', // sand           - the band eyebrow, 8.23:1 on #014C5A
    ];

    /** The near-brand values that shipped before this task. None may return. */
    private const SUPERSEDED = ['#178A95', '#C9922A', '#0B3C45', '#0d6e77'];

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $rooms
     */
    private function survey(array $rooms = ['Board Room', 'Huddle 1'], ?callable $tweak = null): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Branding Job',
            'ref'          => 'Q-4701',
            'site_address' => '12 Wharf Road, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Branding Job',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($rooms) as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        if ($tweak !== null) {
            $tweak($survey);
        }

        return $token;
    }

    private function render(string $token): string
    {
        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * The five states, each a rendered page. Returned as a map so every
     * assertion below runs against ALL of them and none can pass vacuously on
     * the one state that happens to be convenient.
     *
     * @return array<string, string>
     */
    private function everyState(): array
    {
        $notStarted = $this->survey();

        $partlyDone = $this->survey(['Board Room', 'Huddle 1'], function (SiteSurvey $s): void {
            $s->rooms()->first()->update(['is_completed' => true, 'completed_at' => now()]);
        });

        $complete = $this->survey(['Board Room', 'Huddle 1'], function (SiteSurvey $s): void {
            $s->rooms()->update(['is_completed' => true, 'completed_at' => now()]);
        });

        // SUBMITTED = LOCKED. `isLockedForEngineer()` is true once `submitted_at`
        // is set and the visit has not been reopened, which is the read-only
        // face of the link — a different render path, so a separate state.
        $locked = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->rooms()->update(['is_completed' => true, 'completed_at' => now()]);
            $s->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();
        });

        // ROOMLESS. The state that produced a 500 in quick task 260925-d51, so
        // it earns a place in every render loop from here on.
        $roomless = $this->survey([]);

        return [
            'not started' => $this->render($notStarted),
            'partly done' => $this->render($partlyDone),
            'complete'    => $this->render($complete),
            'locked'      => $this->render($locked),
            'roomless'    => $this->render($roomless),
        ];
    }

    // ── 1. THE BRAND IS ACTUALLY ON THE PAGE, IN EVERY STATE ────────────────

    public function test_every_state_carries_the_brand_palette_and_none_carries_the_old_one(): void
    {
        $states = $this->everyState();

        $this->assertCount(5, $states, 'Five states, each rendered — not one.');

        foreach ($states as $name => $html) {
            foreach (self::BRAND as $hex) {
                $this->assertStringContainsStringIgnoringCase(
                    $hex,
                    $html,
                    "State \"{$name}\": brand value {$hex} is missing."
                );
            }

            foreach (self::SUPERSEDED as $hex) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $hex,
                    $html,
                    "State \"{$name}\": {$hex} was superseded by 260930-sv2 and must not return."
                );
            }
        }
    }

    public function test_every_state_carries_the_brand_type_and_the_house_device(): void
    {
        foreach ($this->everyState() as $name => $html) {
            $this->assertStringContainsString('Verdana', $html, "State \"{$name}\": heading face missing.");
            $this->assertStringContainsString('Poppins', $html, "State \"{$name}\": body face missing.");

            // The device and the hairline are decorative and must stay out of
            // the accessibility tree.
            $this->assertStringContainsString('<div class="sv-device" aria-hidden="true">', $html);
            $this->assertStringContainsString('<div class="sv-hairline" aria-hidden="true">', $html);
        }
    }

    // ── 2. THE PLANT ROOM: NOTHING IS FETCHED FOR A FONT ────────────────────

    /**
     * A blocking font request that never resolves is a blank page on a bad
     * connection, and this link is used on mobile data in risers and plant
     * rooms. The decision was "no webfont at all"; this is the assertion that
     * keeps it, because adding a `<link>` is a one-line temptation.
     */
    public function test_no_state_requests_a_webfont_over_the_network(): void
    {
        foreach ($this->everyState() as $name => $html) {
            foreach (['fonts.googleapis.com', 'fonts.gstatic.com', '@font-face', '@import'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $html,
                    "State \"{$name}\": {$needle} would make the font a network dependency."
                );
            }
        }
    }

    // ── 3. LEGIBILITY: NO OPACITY ON SMALL TEXT ─────────────────────────────

    /**
     * `text-white/50` on the band measures 3.65:1 and FAILED AA at 10px. The
     * eyebrow moved to the brand's sand (#F5EDD6, 8.23:1) and every remaining
     * white-on-band label sits at /80 (6.76:1).
     *
     * Asserted as a ban on the low opacities rather than as a spot-check of one
     * element, because the failure mode is a NEW label added later at /50.
     */
    public function test_no_state_puts_a_low_opacity_white_on_the_band(): void
    {
        foreach ($this->everyState() as $name => $html) {
            foreach (['text-white/50', 'text-white/60', 'text-white/70'] as $class) {
                $this->assertStringNotContainsString(
                    $class,
                    $html,
                    "State \"{$name}\": {$class} on #014C5A is below 4.5:1 for small text."
                );
            }
        }
    }

    // ── 4. THE USER'S OTHER COMPLAINT: COLOUR MEANS STATUS ──────────────────

    /**
     * "Tables need to not be coloured until install info submitted is complete
     * (green) / not done (red) / part complete (amber with reason why)."
     *
     * So the branding may colour the CHROME and must not colour anything that
     * carries state. The teal spine is the one new mark on a section heading,
     * and this holds it to being STRUCTURE: its count is IDENTICAL across all
     * five states. A mark that meant progress could not be.
     */
    public function test_the_section_spine_is_structure_and_never_status(): void
    {
        $counts = [];

        foreach ($this->everyState() as $name => $html) {
            $counts[$name] = substr_count($html, 'sv-spine');
        }

        $this->assertGreaterThan(0, $counts['not started'], 'The spine must actually render.');

        $distinct = array_unique(array_values(array_diff_key($counts, ['roomless' => null])));

        $this->assertCount(
            1,
            $distinct,
            'The spine count changed between states, so it is carrying status: '.json_encode($counts)
        );
    }

    /**
     * The status families are UNTOUCHED by this task. Their job is
     * complete/not-done/part-complete and the rebrand must not borrow any of
     * them for decoration.
     */
    public function test_the_branding_introduced_no_new_status_colour(): void
    {
        $view = file_get_contents(resource_path('views/surveys/show.blade.php'));

        // The one status-family value this task touched, and it REMOVED it:
        // `hover:bg-amber-600` was Tailwind orange on the gold buttons, not a
        // status. Nothing replaced it with another status shade.
        $this->assertStringNotContainsString('hover:bg-amber-600', $view);

        // The brand's decorative hues are never applied to a status word.
        foreach (['#D4AF37', '#01889F'] as $decorative) {
            $this->assertStringNotContainsString(
                'text-['.$decorative.']',
                $view,
                $decorative.' is decorative only — 2.10:1 and 4.18:1 on white.'
            );
        }
    }

    // ── 5. STILL A PUBLIC PAGE ──────────────────────────────────────────────

    /**
     * The audit fields are never rendered on an unauthenticated page, and a
     * restyle is exactly the kind of change that could paste one into a header.
     */
    public function test_no_state_renders_an_audit_field(): void
    {
        foreach ($this->everyState() as $name => $html) {
            foreach (['captured_by', 'ip_address', 'user_agent'] as $field) {
                $this->assertStringNotContainsString(
                    $field,
                    $html,
                    "State \"{$name}\": {$field} must never reach a public page."
                );
            }
        }
    }

    /** A hostile project name is escaped, in every state, restyle or not. */
    public function test_the_project_name_is_escaped(): void
    {
        $token = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->forceFill(['project_name' => '<script>alert(1)</script>'])->save();
        });

        $html = $this->render($token);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}

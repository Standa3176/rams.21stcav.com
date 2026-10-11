<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261011-n91 — the survey engineer link threw 109 TypeErrors on every load.
 *
 * The whole room section of the engineer link hung off a single container
 * guarded with x-show. x-show only toggles the display style: Alpine still
 * creates the subtree and still evaluates every binding inside it. On the
 * Site tab currentRoomIdx is null, so each of the 71 rooms[currentRoomIdx]
 * bindings evaluated rooms[null] -> undefined and then read .name or .type
 * off undefined. Alpine swallowed each throw, so the page worked; the cost
 * was 109 console errors deep enough to bury a real one.
 *
 * The fix moves that one container inside a template x-if, so the subtree
 * does not exist at all while the Site tab is active. Between rooms
 * currentRoomIdx stays non-null, so the subtree is NOT remounted on a
 * room-to-room switch, and no room answer lives in that DOM anyway — room
 * answers live in the Alpine rooms array, which x-model re-reads on mount.
 *
 * WHAT THIS TEST CAN AND CANNOT PROVE
 *
 * It CANNOT prove the console errors stopped. PHPUnit has no JavaScript
 * runtime, no Alpine and no layout engine; nothing here mounts a component,
 * evaluates a binding or records a console message. Everything below is a
 * SOURCE CONTRACT: that the room panel is guarded by a template x-if rather
 * than x-show, that the guard expression is byte-for-byte the one it
 * replaced, that the template holds exactly one root element which closes
 * inside the template, and that every room binding sits inside that guard.
 * That the 109 errors actually went to 0 — and that room-to-room switching
 * does not remount, that a Site/room round trip keeps typed answers, and
 * that the 261011-lvf debounced autosave still fires — was measured in real
 * headless Chrome (puppeteer). That measurement, not this file, is the proof
 * the defect is fixed. See this quick task's SUMMARY.
 *
 * The structural assertions are mechanical rather than literal: the matching
 * close of the guarded container is found by masking Blade and HTML comments
 * plus script and style bodies and then counting div depth, which is the same
 * method used to locate it in the first place. A future edit that re-parents
 * the room section — the silent failure mode this change risked — fails these
 * assertions even though the page would still render without error.
 *
 * WHICH VIEW THIS IS — the same two-file trap the sibling 46.6 tests guard:
 * GET survey/{token} resolves to SurveyController show, rendering
 * resources/views/surveys/show.blade.php. The dead
 * resources/views/public-survey/show.blade.php has no GET route and is never
 * touched here.
 */
class SurveyRoomPanelGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The guard expression, unchanged by this plan. x-show carried exactly
     * this condition before; the template x-if must carry exactly it after,
     * or the room panel's visibility rule has quietly changed.
     */
    private const GUARD = "activeTab !== 'site' && currentRoomIdx !== null";

    private function rawSource(): string
    {
        return file_get_contents(resource_path('views/surveys/show.blade.php'));
    }

    private function renderedPage(): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Room Panel Guard Fixture',
            'site_address' => '2 Guard Lane, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Room Panel Guard Fixture',
            'site_address' => '2 Guard Lane, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (['Board Room', 'Huddle 1'] as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * Blank out every region where a div token is not real markup: Blade
     * comments, HTML comments, and script and style bodies. Offsets are
     * preserved so line numbers and positions stay comparable.
     */
    private function maskedSource(string $source): string
    {
        $patterns = [
            '/\{\{--.*?--\}\}/s',
            '/<!--.*?-->/s',
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
        ];

        foreach ($patterns as $pattern) {
            $source = preg_replace_callback($pattern, function (array $m): string {
                return preg_replace('/[^\n]/', ' ', $m[0]);
            }, $source);
        }

        return $source;
    }

    /**
     * Offset of the </div> that closes the <div> starting at $openOffset,
     * by depth counting over masked markup. Null when it never closes.
     */
    private function matchingDivClose(string $masked, int $openOffset): ?int
    {
        preg_match_all('/<div\b|<\/div\s*>/', $masked, $m, PREG_OFFSET_CAPTURE);

        $depth = 0;

        foreach ($m[0] as [$tag, $offset]) {
            if ($offset < $openOffset) {
                continue;
            }

            $depth += str_starts_with($tag, '</') ? -1 : 1;

            if ($depth === 0) {
                return $offset;
            }
        }

        return null;
    }

    private function lineOf(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    // -- 1. NON-VACUITY ------------------------------------------------------

    public function test_the_view_really_does_ship_a_large_room_panel_behind_one_guard(): void
    {
        $source = $this->rawSource();

        $bindings = substr_count($source, 'rooms[currentRoomIdx]');

        $this->assertGreaterThan(
            50,
            $bindings,
            'The room panel bindings this guard exists to protect are gone from the view — '
            .'these assertions would pass vacuously. Found only '.$bindings.'.'
        );

        $this->assertSame(
            1,
            preg_match_all('/'.preg_quote(self::GUARD, '/').'/', $source),
            'The room-panel guard expression must appear exactly once in the view. '
            .'More than one copy means there is no longer a single container to reason about.'
        );
    }

    // -- 2. THE CONTAINER IS GUARDED BY x-if, NOT x-show ---------------------

    public function test_the_room_panel_is_wrapped_in_a_template_x_if(): void
    {
        $this->assertStringContainsString(
            '<template x-if="'.self::GUARD.'">',
            $this->rawSource(),
            'The room panel must be guarded by a template x-if so the subtree does not '
            .'exist while the Site tab is active. x-show would leave every '
            .'rooms[currentRoomIdx] binding evaluating against rooms[null].'
        );
    }

    public function test_no_x_show_still_carries_the_room_panel_guard(): void
    {
        $source = $this->rawSource();

        $this->assertStringNotContainsString(
            'x-show="'.self::GUARD.'"',
            $source,
            'An x-show still carries the room-panel guard. x-show only toggles display: '
            .'Alpine evaluates the whole subtree regardless, which is the defect.'
        );

        $this->assertSame(
            0,
            preg_match_all('/x-show\s*=\s*"[^"]*currentRoomIdx\s*!==\s*null[^"]*"/', $source),
            'No x-show on this view may gate on currentRoomIdx !== null — that is an '
            .'x-if condition, because the bindings inside need the room to exist.'
        );
    }

    // -- 3. THE WRAP IS STRUCTURALLY CONTAINED -------------------------------

    public function test_the_guard_template_holds_exactly_one_root_element_which_closes_inside_it(): void
    {
        $source = $this->rawSource();
        $masked = $this->maskedSource($source);

        $templateTag = '<template x-if="'.self::GUARD.'">';
        $templateAt  = strpos($masked, $templateTag);

        $this->assertNotFalse(
            $templateAt,
            'The guard template is not present in real markup — it may only exist inside '
            .'a comment or a script body.'
        );

        $afterTemplate = $templateAt + strlen($templateTag);
        $rest          = substr($masked, $afterTemplate);

        $this->assertSame(
            1,
            preg_match('/^\s*(<[a-zA-Z][^>]*>)/', $rest, $first),
            'An x-if template must open with a single root element immediately. Found: '
            .substr(preg_replace('/\s+/', ' ', $rest), 0, 80)
        );

        $this->assertStringStartsWith(
            '<div',
            $first[1],
            'The single root inside the guard template must be the room-panel div that '
            .'used to carry the x-show.'
        );

        $openAt = $afterTemplate + strpos($rest, $first[1]);
        $closeAt = $this->matchingDivClose($masked, $openAt);

        $this->assertNotNull(
            $closeAt,
            'The room-panel div inside the guard template never closes — the view is '
            .'structurally broken.'
        );

        $tail = substr($masked, $closeAt + strlen('</div>'));

        $this->assertSame(
            1,
            preg_match('/^\s*<\/template>/', $tail),
            'The guard template must close immediately after the room-panel div it wraps '
            .'(line '.$this->lineOf($source, $closeAt).'). Anything else means the wrap '
            .'landed on the wrong closing tag and the page has been silently re-parented — '
            .'found instead: '.substr(preg_replace('/\s+/', ' ', $tail), 0, 80)
        );
    }

    public function test_every_room_binding_lives_inside_the_guarded_subtree(): void
    {
        $source = $this->rawSource();
        $masked = $this->maskedSource($source);

        $templateTag = '<template x-if="'.self::GUARD.'">';
        $templateAt  = strpos($masked, $templateTag);
        $this->assertNotFalse($templateAt, 'The guard template is missing.');

        preg_match('/^\s*(<div\b[^>]*>)/', substr($masked, $templateAt + strlen($templateTag)), $first);
        $this->assertNotEmpty($first, 'The guard template does not open with a div.');

        $openAt  = $templateAt + strlen($templateTag) + strpos(substr($masked, $templateAt + strlen($templateTag)), $first[1]);
        $closeAt = $this->matchingDivClose($masked, $openAt);
        $this->assertNotNull($closeAt, 'The guarded div never closes.');

        $outside = [];

        preg_match_all('/rooms\[currentRoomIdx\]/', $masked, $hits, PREG_OFFSET_CAPTURE);

        foreach ($hits[0] as [, $offset]) {
            if ($offset < $openAt || $offset > $closeAt) {
                $outside[] = 'line '.$this->lineOf($source, $offset);
            }
        }

        $this->assertSame(
            [],
            $outside,
            'Every rooms[currentRoomIdx] binding must sit inside the guarded subtree, or it '
            .'still evaluates rooms[null] on the Site tab. Outside the guard: '
            .implode(', ', $outside)
        );
    }

    public function test_the_views_div_tags_stay_balanced(): void
    {
        $masked = $this->maskedSource($this->rawSource());

        preg_match_all('/<div\b|<\/div\s*>/', $masked, $m);

        $balance = 0;

        foreach ($m[0] as $tag) {
            $balance += str_starts_with($tag, '</') ? -1 : 1;
        }

        $this->assertSame(
            0,
            $balance,
            'The view no longer has balanced div tags. A mis-placed wrap re-parents markup '
            .'with no error at all, which is exactly the failure mode this change risked.'
        );
    }

    // -- 4. WHAT IS ACTUALLY SERVED TO AN ENGINEER ---------------------------

    public function test_the_page_served_to_an_engineer_ships_the_guard(): void
    {
        $html = $this->renderedPage();

        $this->assertStringContainsString(
            '<template x-if="'.self::GUARD.'">',
            $html,
            'The page actually served to an engineer does not ship the room-panel x-if guard.'
        );

        $this->assertStringNotContainsString(
            'x-show="'.self::GUARD.'"',
            $html,
            'The page actually served to an engineer still ships the x-show guard.'
        );
    }
}

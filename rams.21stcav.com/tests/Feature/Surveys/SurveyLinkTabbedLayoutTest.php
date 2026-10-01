<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-01 (46.6-01 scaffold): the SITE SURVEY ENGINEER LINK gets SCC's bottom
 * tab-bar chrome — a site/contract-details tab FIRST, then one tab per room
 * — replacing the former `screen: 'rooms' | 'step'` picker/wizard-screen
 * split. The room's internal 8-step wizard is UNCHANGED this plan (46.6-03
 * flattens it later); this test only proves the TAB SCAFFOLD, not the
 * wizard content.
 *
 * WHICH VIEW THIS IS — same two-file trap `SurveyLinkBrandingTest` guards
 * against: `GET survey/{token}` (routes/web.php) resolves to
 * `SurveyController@show`, rendering `resources/views/surveys/show.blade.php`.
 * `resources/views/public-survey/show.blade.php` is dead (no GET route) and
 * is never touched by this test.
 *
 * EVERY STATE IS RENDERED — not started, partly done, complete,
 * submitted-and-locked, and roomless — modelled on
 * `SurveyLinkBrandingTest::everyState()`'s five-state pattern so a tab
 * defect can't hide behind one convenient state. The fixture-building here
 * is the SAME SHAPE as that test's private `survey()`/`render()` helpers
 * (copied, not inherited — they are private to that class).
 */
class SurveyLinkTabbedLayoutTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures (same shape as SurveyLinkBrandingTest) ─────────────────────

    /**
     * @param  array<int, string>  $rooms
     */
    private function survey(array $rooms = ['Board Room', 'Huddle 1'], ?callable $tweak = null): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Tab Layout Job',
            'ref'          => 'Q-4702',
            'site_address' => '12 Wharf Road, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Tab Layout Job',
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
     * The five states, each a rendered page, mirroring
     * SurveyLinkBrandingTest::everyState() exactly (same room setups), so a
     * tab-layout defect cannot hide behind a single convenient state.
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

        $locked = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->rooms()->update(['is_completed' => true, 'completed_at' => now()]);
            $s->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();
        });

        $roomless = $this->survey([]);

        return [
            'not started' => $this->render($notStarted),
            'partly done' => $this->render($partlyDone),
            'complete'    => $this->render($complete),
            'locked'      => $this->render($locked),
            'roomless'    => $this->render($roomless),
        ];
    }

    // ── 1. THE SITE TAB IS FIRST, IN DOCUMENT ORDER, IN EVERY STATE ─────────

    public function test_the_site_tab_panel_renders_first_and_is_active_by_default(): void
    {
        $states = $this->everyState();

        $this->assertCount(5, $states, 'Five states, each rendered — not one.');

        foreach ($states as $name => $html) {
            $this->assertStringContainsString(
                'data-tab="site"',
                $html,
                "State \"{$name}\": no data-tab=\"site\" panel rendered."
            );

            // Statically active on first load — no JS execution in this test,
            // so the "is-active" token must be literal, compiled HTML, not an
            // Alpine `:class` binding evaluated client-side.
            $sitePanelPos = strpos($html, 'data-tab="site"');
            $before       = substr($html, max(0, $sitePanelPos - 400), 400);
            $this->assertStringContainsString(
                'is-active',
                $before,
                "State \"{$name}\": the site panel's opening tag does not carry is-active by default."
            );
        }
    }

    public function test_site_tab_precedes_every_room_tab_in_document_order(): void
    {
        $states = $this->everyState();

        foreach (['not started', 'partly done', 'complete'] as $name) {
            $html = $states[$name];

            $sitePos   = strpos($html, 'data-tab="site"');
            $room0Pos  = strpos($html, 'data-tab="room-0"');
            $room1Pos  = strpos($html, 'data-tab="room-1"');

            $this->assertNotFalse($sitePos, "State \"{$name}\": site panel missing.");
            $this->assertNotFalse($room0Pos, "State \"{$name}\": room-0 panel missing.");
            $this->assertNotFalse($room1Pos, "State \"{$name}\": room-1 panel missing.");

            $this->assertLessThan($room0Pos, $sitePos, "State \"{$name}\": site panel must precede room-0.");
            $this->assertLessThan($room1Pos, $room0Pos, "State \"{$name}\": room-0 panel must precede room-1.");
        }

        // locked = one room only (see everyState()).
        $lockedHtml = $states['locked'];
        $sitePos    = strpos($lockedHtml, 'data-tab="site"');
        $room0Pos   = strpos($lockedHtml, 'data-tab="room-0"');
        $this->assertNotFalse($sitePos, 'Locked state: site panel missing.');
        $this->assertNotFalse($room0Pos, 'Locked state: room-0 panel missing.');
        $this->assertLessThan($room0Pos, $sitePos, 'Locked state: site panel must precede room-0.');
    }

    // ── 2. THE ROOMLESS STATE STILL RENDERS A SITE TAB, NO ROOM PANEL, NO 500 ──

    public function test_roomless_state_renders_the_site_tab_and_no_room_panel(): void
    {
        $html = $this->everyState()['roomless'];

        $this->assertStringContainsString('data-tab="site"', $html);
        $this->assertStringNotContainsString('data-tab="room-0"', $html);
    }

    // ── 3. THE LOCKED (SUBMITTED) STATE STILL RENDERS THE TAB BAR ───────────

    public function test_locked_state_still_renders_the_tab_bar_and_its_room_tab(): void
    {
        $html = $this->everyState()['locked'];

        $this->assertStringContainsString('data-sv-tab-bar', $html, 'Locked survey must still show the tab bar — read-only is not tab-less.');
        $this->assertStringContainsString('data-tab="site"', $html);
        $this->assertStringContainsString('data-tab="room-0"', $html);
    }

    // ── 4. THE BOTTOM BAR HAS ONE BUTTON PER ROOM PLUS SITE, SITE FIRST ─────

    public function test_the_tab_bar_has_a_site_button_first_and_one_button_per_room(): void
    {
        $states = $this->everyState();

        foreach (['not started', 'partly done', 'complete'] as $name) {
            $html = $states[$name];

            $siteBtnPos  = strpos($html, 'data-tab-target="site"');
            $room0BtnPos = strpos($html, 'data-tab-target="room-0"');
            $room1BtnPos = strpos($html, 'data-tab-target="room-1"');

            $this->assertNotFalse($siteBtnPos, "State \"{$name}\": Site tab button missing.");
            $this->assertNotFalse($room0BtnPos, "State \"{$name}\": room-0 tab button missing.");
            $this->assertNotFalse($room1BtnPos, "State \"{$name}\": room-1 tab button missing.");
            $this->assertLessThan($room0BtnPos, $siteBtnPos, "State \"{$name}\": Site button must be first.");

            // Exactly one button per room, no stray room-2 for a 2-room survey.
            $this->assertStringNotContainsString('data-tab-target="room-2"', $html, "State \"{$name}\": unexpected third room tab.");
        }
    }

    // ── 5. THE FORMER "ALL ROOMS" PICKER SCREEN NO LONGER EXISTS AS A SCREEN ──

    /**
     * The old `screen: 'rooms' | 'step'` Alpine property drove a full-screen
     * swap. This plan retires it in favour of `activeTab`; asserting its
     * absence (and activeTab's presence) is the direct proof that the
     * picker screen is gone, not just visually hidden behind it.
     */
    public function test_the_old_screen_property_is_gone_and_activeTab_drives_the_page(): void
    {
        $view = file_get_contents(resource_path('views/surveys/show.blade.php'));

        $this->assertStringNotContainsString(
            "screen:          'rooms'",
            $view,
            'The old screen Alpine property must be fully retired, not left dead.'
        );
        $this->assertStringContainsString(
            "activeTab:       'site'",
            $view,
            'activeTab must be the new tab-driving Alpine property, defaulting to the site tab.'
        );
    }

    // ── 6. SWITCHING TABS SHOWS EXACTLY ONE PANEL AT A TIME (markup contract) ──

    /**
     * No JS execution here (PHPUnit, not a browser), so this proves the
     * STATIC contract that makes single-panel-visible possible at runtime:
     * every sv-tab-panel is governed by the SAME is-active toggle rule, and
     * only the site panel carries it by default. A second statically-active
     * panel would mean two panels visible on first paint.
     */
    public function test_only_the_site_panel_is_statically_active_on_first_load(): void
    {
        $states = $this->everyState();

        foreach (['not started', 'partly done', 'complete', 'locked'] as $name) {
            $html = $states[$name];

            preg_match_all('/<section class="([^"]*)" :class="\{ \'is-active\'[^}]*\}"\s+data-tab="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

            $this->assertNotEmpty($matches, "State \"{$name}\": no sv-tab-panel sections matched the expected markup shape.");

            foreach ($matches as $m) {
                $tab      = $m[2];
                $isActive = str_contains($m[1], 'is-active');

                if ($tab === 'site') {
                    $this->assertTrue($isActive, "State \"{$name}\": the site panel must be statically active by default.");
                } else {
                    $this->assertFalse($isActive, "State \"{$name}\": room panel \"{$tab}\" must NOT be statically active — only the site tab is, on first load.");
                }
            }
        }
    }
}

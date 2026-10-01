<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-09 (46.6-05): the now-flattened survey link's tab state survives a
 * reload (`location.hash` + `sessionStorage`), and autosave never silently
 * loses typed text while offline — a failed save queues the whole in-flight
 * payload to a `localStorage` draft map and the status text reads
 * "Needs a connection" rather than appearing to have saved. Both mechanics
 * are ported BY SHAPE (not literal copy) from the worksheet link's proven
 * Phase 46.7 mechanics, under survey-specific key names (`svTab_`,
 * `svDraft_`, `svDraftProbe_`) — SCC's own active tab does NOT survive a
 * reload, so the worksheet wins on both points (46.6-CONTEXT.md D-09).
 *
 * ⚠️ THE OFFLINE DRAFT QUEUE MUST NEVER TOUCH THE engineer-capture
 * INDEXEDDB STORE. That database is schema-frozen at v1 and holds real
 * unsent photos on engineers' phones right now — this plan's text-autosave
 * work is localStorage only, full stop. The partial that defines that store
 * is @include'd by this page already (pre-existing, unrelated to this
 * plan), so some of its tests below assert against the RAW `show.blade.php`
 * source directly, rather than the rendered page, to avoid confusing the
 * partial's own pre-existing `indexedDB`/`engineer-capture` literals with
 * anything this plan might have added.
 *
 * NON-VACUITY: the hash/sessionStorage round-trip and the online-drain are
 * genuinely client-side browser behaviour that a server-rendered PHPUnit
 * assertion cannot execute. What IS asserted here is what CAN be proven
 * server-side — the compiled page SHIPS the mechanism, grep-provable — and
 * that limit is restated in the plan's SUMMARY rather than implied away.
 *
 * WHICH VIEW THIS IS — same two-file trap `SurveyLinkBrandingTest`,
 * `SurveyLinkTabbedLayoutTest` and `SurveyRoomTabFlattenTest` guard against:
 * `GET survey/{token}` (routes/web.php) resolves to `SurveyController@show`,
 * rendering `resources/views/surveys/show.blade.php`. The dead 127KB
 * `resources/views/public-survey/show.blade.php` has no GET route and is
 * never touched here.
 */
class SurveyLinkOfflineAutosaveTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures (same shape as the sibling 46.6 tests) ─────────────────────

    /**
     * @param  array<int, string>  $rooms
     * @return array{0: string, 1: SiteSurvey}
     */
    private function surveyFixture(array $rooms = ['Board Room', 'Huddle 1']): array
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Offline Autosave Test Job',
            'ref'          => 'Q-9001',
            'site_address' => '1 Signal Lost Lane, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Offline Autosave Test Job',
            'site_address' => '1 Signal Lost Lane, Leeds',
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

        return [$token, $survey];
    }

    private function render(string $token): string
    {
        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    private function rawSource(): string
    {
        return file_get_contents(resource_path('views/surveys/show.blade.php'));
    }

    // ── 1. SURVEY_ID is injected, keyed per survey, same shape as WORKSHEET_ID ──

    public function test_survey_id_is_injected_and_keys_both_persistence_mechanisms(): void
    {
        [$token, $survey] = $this->surveyFixture();
        $html = $this->render($token);

        $this->assertStringContainsString(
            "var SURVEY_ID = {$survey->id};",
            $html,
            'SURVEY_ID must be injected as a scalar echo, the same shape WORKSHEET_ID uses on the worksheet link.'
        );
        $this->assertStringContainsString("'svTab_' + SURVEY_ID", $html);
        $this->assertStringContainsString("'svDraft_' + SURVEY_ID", $html);
        $this->assertStringContainsString("'svDraftProbe_' + SURVEY_ID", $html);
    }

    public function test_survey_id_is_keyed_per_survey_not_a_literal_copy(): void
    {
        [$tokenA, $surveyA] = $this->surveyFixture(['Board Room']);
        [$tokenB, $surveyB] = $this->surveyFixture(['Kitchen']);

        $this->assertNotSame($surveyA->id, $surveyB->id);

        $htmlA = $this->render($tokenA);
        $htmlB = $this->render($tokenB);

        $this->assertStringContainsString("var SURVEY_ID = {$surveyA->id};", $htmlA);
        $this->assertStringContainsString("var SURVEY_ID = {$surveyB->id};", $htmlB);
    }

    // ── 2. TAB PERSISTENCE — hash write/read + sessionStorage mirror (D-09) ──

    public function test_tab_persistence_code_ships_hash_write_and_read(): void
    {
        [$token] = $this->surveyFixture();
        $html = $this->render($token);

        $this->assertStringContainsString('history.replaceState', $html);
        $this->assertStringContainsString('window.location.hash', $html);
        $this->assertStringContainsString('window.sessionStorage', $html);
    }

    public function test_tab_persistence_watches_activetab_and_seeds_before_first_paint(): void
    {
        [$token] = $this->surveyFixture();
        $html = $this->render($token);

        // Write side: a $watch mirrors every activeTab change out.
        $this->assertStringContainsString("\$watch('activeTab', (value) => this._persistActiveTab(value))", $html);
        // Read side: called from init(), before Alpine's first paint.
        $this->assertStringContainsString('_seedActiveTabFromPersistence()', $html);
        // x-cloak on <body> is what prevents a flash of the wrong tab while
        // Alpine initialises — confirm it is still there (pre-existing).
        $this->assertStringContainsString('x-data="surveyWizard()" x-cloak', $html);
    }

    // ── 3. OFFLINE DRAFT QUEUE — localStorage only, "Needs a connection" ────

    public function test_failed_autosave_queues_to_the_localstorage_draft_map(): void
    {
        [$token] = $this->surveyFixture();
        $html = $this->render($token);

        $this->assertStringContainsString("window.localStorage.setItem('svDraft_' + SURVEY_ID", $html);
        $this->assertStringContainsString('Needs a connection', $html);
        $this->assertStringContainsString('this._draftPut(roomIndex, step, payload.data)', $html);
    }

    public function test_draft_write_probe_guards_the_whole_mechanism(): void
    {
        [$token] = $this->surveyFixture();
        $html = $this->render($token);

        $this->assertStringContainsString("window.localStorage.setItem('svDraftProbe_' + SURVEY_ID, '1')", $html);
        $this->assertStringContainsString("window.localStorage.removeItem('svDraftProbe_' + SURVEY_ID)", $html);
    }

    public function test_online_listener_drains_queued_drafts_back_through_step_save(): void
    {
        [$token] = $this->surveyFixture();
        $html = $this->render($token);

        $this->assertStringContainsString("addEventListener('online', () => this._draftDrain())", $html);
        $this->assertStringContainsString('/step-save', $html);
    }

    /**
     * This task's test proving T-46.6-05-03 of the plan's threat register:
     * the raw show.blade.php source this plan edited carries NO reference
     * to indexedDB or the engineer-capture database. The pre-existing
     * @include of the shared offline-photo partial is untouched by this
     * plan — asserted separately, on the partial file, not here — but the
     * SOURCE THIS PLAN WROTE must be completely free of both strings.
     */
    public function test_this_plans_source_never_references_indexeddb_or_engineer_capture(): void
    {
        $source = $this->rawSource();

        $this->assertStringNotContainsString('indexedDB', $source);
        $this->assertStringNotContainsString('engineer-capture', $source);
    }

    /**
     * The pre-existing @include line is still exactly one line, unmodified —
     * confirms this plan did not touch the one place show.blade.php
     * references the offline-photo partial.
     */
    public function test_the_preexisting_offline_capture_include_is_unmodified(): void
    {
        $source = $this->rawSource();

        $this->assertSame(
            1,
            substr_count($source, "@include('partials._engineer-offline-capture')"),
            'The pre-existing offline-photo partial include must remain exactly once, untouched by this autosave plan.'
        );
    }

    // ── 4. ROOMLESS / LOCKED STATES STILL RENDER WITHOUT A 500 ──────────────

    public function test_roomless_state_still_renders_without_a_500(): void
    {
        [$token] = $this->surveyFixture([]);
        $html = $this->render($token);

        $this->assertStringContainsString('var SURVEY_ID', $html);
        $this->assertStringContainsString('data-tab="site"', $html);
    }

    public function test_locked_state_still_renders_without_a_500(): void
    {
        [$token, $survey] = $this->surveyFixture(['Board Room']);
        $survey->rooms()->update(['is_completed' => true, 'completed_at' => now()]);
        $survey->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();

        $html = $this->render($token);

        $this->assertStringContainsString("var SURVEY_ID = {$survey->id};", $html);
        $this->assertStringContainsString('data-tab="room-0"', $html);
    }
}

<?php

namespace Tests\Feature\Surveys;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Providers\ClaudeProvider;
use App\Jobs\GenerateRoomWorksSummariesJob;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Services\AICacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261008-gxt Task 2 — a room with no office-authored `works_summary` now
 * renders derived bullets (from whatever planned-works prose is available)
 * instead of falling through to the single-paragraph "Planned AV works"
 * panel. A sibling room WITH office bullets stays byte-for-byte unchanged
 * (the non-vacuity guard — proving the office-wins case was not disturbed).
 *
 * ── Phase 46.9 Plan 03 extension ─────────────────────────────────────────────
 *
 * This file is the phase's single rendered seam. Plan 46.9-01 proved the job's
 * contract against the database; nothing there proves an engineer standing in a
 * room can see anything. The five tests added below drive
 * GenerateRoomWorksSummariesJob and then GET the REAL engineer link, asserting
 * against the bytes the response carries.
 *
 * ⚠ WHAT A SERVER-SIDE ASSERTION CAN AND CANNOT PROVE HERE. The visible bullet
 * <li> elements on the survey are produced CLIENT-SIDE by the Alpine
 * `<template x-for>` at resources/views/surveys/show.blade.php:607-609. There is
 * no server-rendered <li> for a planned action, so no assertion in this file
 * counts one, and none claims to. What is asserted is the `rooms` JSON blob the
 * Blade serialises for Alpine — the payload the engineer's phone actually
 * receives — plus the raw response body. (The WORKSHEET is server-rendered, so
 * counting <li> there is legitimate; that is
 * tests/Feature/Worksheets/WorksheetWorksSummaryDashInvariantTest.php.)
 *
 * ⚠ PRE-EXISTING FAKE GREEN, now corrected rather than re-derived.
 * test_the_rendered_page_carries_the_derived_qualifier_markup() asserted the
 * literal string "Install actions (from scope notes)". That assertion CANNOT
 * FAIL: show.blade.php:600 is an `x-text` ternary and ships BOTH branches as
 * literal text on every render, for every room, in every state. Its docblock
 * claimed the qualifier "only shows for derived"; it does not. The test now
 * asserts the `_ctx` values that are genuinely state-dependent, and keeps a
 * documented assertion that BOTH branches ship — which is the real behaviour,
 * and the reason the old assertion was worthless.
 *
 * Test-environment hazards, all real:
 *  - phpunit.xml:40 sets QUEUE_CONNECTION=sync, so a dispatched job runs inline.
 *    These tests drive handle() directly instead.
 *  - The 30-day AICacheService will serve a stored response straight past any
 *    provider fake and produce a FAKE GREEN. It is stubbed in setUp(), per
 *    tests/Unit/Services/RoomOverviewSummaryFallbackTest.php:33-35.
 *  - Mockery::mock(AIManager::class) WILL NOT WORK: RoomOverviewSummaryService:59
 *    calls AIManager::run() STATICALLY. The only seam that works is a mock
 *    AIProviderContract bound over ClaudeProvider::class, per
 *    RoomOverviewSummaryFallbackTest:44-52.
 */
class SurveyPlannedActionsBulletsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mandatory: bypass the AI cache so the "live" call is always attempted
        // and a stored 30-day response cannot fake a green.
        // Seam: tests/Unit/Services/RoomOverviewSummaryFallbackTest.php:33-35.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash-46-9-03');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);
    }

    /**
     * Build the user + project + package + survey + rooms, and STOP before
     * rendering. Extracted from surveyWithPackage() (which still behaves
     * exactly as before) so the Phase 46.9 tests can run the job in between
     * creating the package and GETting the engineer link. The three
     * pre-existing tests are unaffected — they still call surveyWithPackage().
     *
     * @return array{0: SiteSurvey, 1: ProjectPackage}
     */
    private function buildSurveyWithPackage(array $roomOverviews): array
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Bullets Job',
            'site_address' => '2 Test Street',
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'room_overviews' => $roomOverviews,
            ],
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Bullets Job',
            'site_address' => '2 Test Street',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($roomOverviews) as $i => $ro) {
            $survey->rooms()->create([
                'room_name'  => $ro['room'],
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        return [$survey->fresh(), $package];
    }

    /**
     * GET the REAL engineer link and return both the raw body and the decoded
     * `rooms` JSON blob the page ships to Alpine.
     *
     * @return array{0: string, 1: array<int, array<string,mixed>>}  [html, rooms]
     */
    private function renderEngineerLink(SiteSurvey $survey): array
    {
        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        $rooms = $this->extractRoomsJson($html);
        $this->assertIsArray($rooms, 'rooms JSON blob did not decode.');

        return [$html, $rooms];
    }

    /**
     * @return array{0: SiteSurvey, 1: array<string,mixed>}  [survey, decoded rooms JSON]
     */
    private function surveyWithPackage(array $roomOverviews): array
    {
        [$survey] = $this->buildSurveyWithPackage($roomOverviews);

        [, $rooms] = $this->renderEngineerLink($survey);

        return [$survey->fresh(), $rooms];
    }

    // ── AI provider seams (the ONLY seam that works — see class docblock) ────

    /** Bind a provider that returns the given decoded JSON payload. */
    private function bindProviderReturning(array $payload): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willReturn($payload);
        $mock->method('completeJson')->willReturn($payload);
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    /** Bind a provider that fails hard on every call shape (D-04). */
    private function bindProviderThrowing(string $message): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willThrowException(new \RuntimeException($message));
        $mock->method('completeJson')->willThrowException(new \RuntimeException($message));
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    /**
     * Bind a provider that asserts it is NEVER asked anything. PHPUnit verifies
     * the expectation at teardown. This is the positive half of "the AI must not
     * be consulted about a room it has nothing to say about" (D-11) — a
     * stored-state-only assertion would pass even if a real network call had
     * been made.
     */
    private function bindProviderNeverCalled(): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->expects($this->never())->method('execute');
        $mock->expects($this->never())->method('completeJson');
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    /** Re-read the persisted rows from the database, never from memory. */
    private function storedRooms(ProjectPackage $package): array
    {
        return (array) ($package->fresh()->extracted_data['room_overviews'] ?? []);
    }

    // ── Test A: no office works_summary, prose exists → derived bullets ────

    public function test_room_with_no_office_bullets_but_prose_renders_derived_bullets(): void
    {
        [, $rooms] = $this->surveyWithPackage([
            [
                'room'        => 'Reception',
                'description' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack. Mount a ceiling speaker above the desk.',
                // No works_summary — this is the case under test.
            ],
        ]);

        $ctx = $rooms[0]['_ctx'];

        $this->assertSame('derived', $ctx['planned_actions_source']);
        $this->assertNotEmpty($ctx['planned_actions'], 'Expected derived bullets, got none.');
        $this->assertGreaterThanOrEqual(2, count($ctx['planned_actions']));
    }

    // ── Test B (non-vacuity): a sibling room WITH office bullets is unchanged ──

    public function test_room_with_office_works_summary_is_byte_for_byte_unchanged(): void
    {
        $officeBullets = "Install 55in display\nRun HDMI and power to rack\nMount ceiling speaker";

        [, $rooms] = $this->surveyWithPackage([
            [
                'room'          => 'Reception',
                'description'   => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack.',
                'works_summary' => $officeBullets,
            ],
            [
                'room'        => 'Boardroom',
                'description' => 'Fit a video bar and two displays. Cable to the credenza.',
                // No works_summary for this sibling room — proves Test A's
                // path and this office-wins path coexist in the same survey.
            ],
        ]);

        $office = $rooms[0]['_ctx'];
        $this->assertSame('office', $office['planned_actions_source']);
        $this->assertSame(
            ['Install 55in display', 'Run HDMI and power to rack', 'Mount ceiling speaker'],
            $office['planned_actions'],
            'Office-authored bullets must be byte-for-byte unchanged (today\'s newline-split behaviour).'
        );

        $derived = $rooms[1]['_ctx'];
        $this->assertSame('derived', $derived['planned_actions_source']);
        $this->assertNotEmpty($derived['planned_actions']);
    }

    // ── Rendered-page check — STRENGTHENED (Phase 46.9 Plan 03) ─────────────

    /**
     * ⚠ This test WAS a fake green, and the correction is the point of it.
     *
     * Its old body was a single assertion:
     *
     *     $this->assertStringContainsString("Install actions (from scope notes)", $html);
     *
     * and its old docblock said the qualifier "only shows for derived". It does
     * not. resources/views/surveys/show.blade.php:600 is an `x-text` TERNARY:
     *
     *     x-text="...planned_actions_source === 'derived' ? 'Install actions (from scope notes)' : 'Install actions'"
     *
     * Both branches are literal text in the server-rendered HTML on EVERY
     * render, for EVERY room, in EVERY state, because the choice between them is
     * made by Alpine in the browser. The old assertion was therefore
     * unconditionally true and could not fail in any state.
     *
     * It now asserts the state-dependent `_ctx` values, which are real, AND
     * asserts the ternary's actual behaviour: BOTH branch strings ship even for
     * a room whose source is `office`. That second half is what documents why
     * the original assertion proved nothing, and it will fail if anyone converts
     * the ternary to a server-side @if — at which point the pre-existing
     * assertion would become meaningful and this comment can go.
     */
    public function test_the_rendered_page_carries_the_derived_qualifier_markup(): void
    {
        [$survey] = $this->buildSurveyWithPackage([
            [
                'room'        => 'Reception',
                'description' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack. Mount a ceiling speaker above the desk.',
            ],
            [
                // An OFFICE room in the same render. If the qualifier were
                // server-side, this room's presence could not put the derived
                // branch string in the HTML.
                'room'          => 'Boardroom',
                'description'   => 'Fit a video bar.',
                'works_summary' => "Fit the video bar\nCable to the credenza",
            ],
        ]);

        [$html, $rooms] = $this->renderEngineerLink($survey);

        // The real, state-dependent facts.
        $this->assertSame('derived', $rooms[0]['_ctx']['planned_actions_source']);
        $this->assertNotEmpty($rooms[0]['_ctx']['planned_actions']);
        $this->assertSame('office', $rooms[1]['_ctx']['planned_actions_source']);

        // The ternary's actual behaviour, asserted as fact: BOTH branches are in
        // the bytes regardless of state. This is why the old single-string
        // assertion was vacuous.
        $this->assertStringContainsString("Install actions (from scope notes)", $html);
        $this->assertStringContainsString("'Install actions'", $html);
        $this->assertStringContainsString("planned_actions_source === 'derived' ?", $html);
    }

    // =========================================================================
    // PHASE 46.9 PLAN 03 — the five non-vacuity states, each RENDERED
    // =========================================================================

    /**
     * STATE 1 (AIB-01 / AIB-02) + STATE 5.
     *
     * Empty `works_summary` + non-empty `overview` → the job generates AI
     * bullets and they reach the engineer link.
     *
     * STATE 5 is folded in here as the explicit raw-body assertion: the bullet
     * text is asserted against the bytes the engineer's phone receives, not just
     * against the decoded blob and not against the database. ⚠ Be precise about
     * what that proves — the bullets arrive inside the `rooms` JSON blob the
     * Blade serialises for Alpine. The visible <li> rows are produced
     * client-side by the `<template x-for>` at show.blade.php:607-609, so this
     * test deliberately does NOT count <li> rows and does not claim to.
     *
     * ⚠ FINDING, asserted here as fact rather than glossed:
     * `planned_actions_source` comes back as **'office'**, not 'ai'.
     * SurveyController:491 sets 'office' for ANY non-empty `works_summary`,
     * regardless of who wrote it — so the engineer link CANNOT DISTINGUISH AI
     * bullets from office-authored bullets. That is out of scope by the phase
     * fence (T-46.9-12, accepted and surfaced) and is a checkpoint item for the
     * user in Plan 46.9-04. It is asserted here so the behaviour is pinned and
     * a future change to it is a deliberate, visible decision.
     */
    public function test_state_1_ai_bullets_reach_the_rendered_engineer_link(): void
    {
        [$survey, $package] = $this->buildSurveyWithPackage([
            [
                'room'     => 'Reception',
                'overview' => 'Oregano is now using the Crestron small room system with a Jabra PanaCast 50 video bar, and offers full room control from a single Crestron panel.',
                // No works_summary at all — the pre-job shape QuoteWerks emits
                // (QuoteWerksImportService:181,193-199,227 never writes the key).
            ],
        ]);

        // Bullet text is deliberately plain alphanumerics: the blob is
        // JSON-encoded into the page, so quotes and slashes would be escaped
        // and a raw-body assertion on them would be testing the encoder.
        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Reception', 'summary' => "- Mount the Jabra PanaCast 50 video bar below the display\n- Terminate the Crestron panel cable at the table plate"],
            ],
        ]);

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        [$html, $rooms] = $this->renderEngineerLink($survey);

        $ctx = $rooms[0]['_ctx'];

        // The job wrote, and the page carries what it wrote.
        $this->assertSame(
            [
                '- Mount the Jabra PanaCast 50 video bar below the display',
                '- Terminate the Crestron panel cable at the table plate',
            ],
            $ctx['planned_actions'],
            'The AI bullets must reach _ctx.planned_actions. SurveyController:487-491 splits the '
            .'works_summary on newlines and does NOT strip the leading dash, so the dash is part '
            .'of the rendered value — asserted as the actual behaviour.'
        );

        // STATE 5 — against the raw response body, not the decoded blob.
        $this->assertStringContainsString(
            'Mount the Jabra PanaCast 50 video bar below the display',
            $html,
            'The bullet must be in the bytes the engineer phone receives (inside the rooms JSON '
            .'blob the Blade ships to Alpine), not merely in the database.'
        );

        // ⚠ The finding. See the docblock: SurveyController:491 labels ANY
        // non-empty works_summary 'office', so AI-authored bullets are
        // indistinguishable from office-authored ones on the engineer link.
        $this->assertSame(
            'office',
            $ctx['planned_actions_source'],
            'SurveyController:491 sets planned_actions_source = office for ANY non-empty '
            .'works_summary regardless of author, so the renderer cannot tell AI bullets from '
            .'office bullets. Asserted as fact; raised for the user at the Plan 46.9-04 checkpoint.'
        );

        // D-05: the imported original is kept, byte-identical.
        $stored = $this->storedRooms($package);
        $this->assertStringContainsString('Oregano is now using the Crestron small room system', (string) $stored[0]['overview']);
    }

    /**
     * STATE 2 (AIB-03 / D-03) — the rendered twin of Plan 46.9-01's Test B.
     *
     * An office-authored `works_summary` is byte-identical in the RENDERED
     * `planned_actions` after the job has run and generated for a sibling.
     *
     * The fixture is built to reproduce the dangerous state, not a safe one.
     * `RoomOverviewSummaryService:72-84` runs its final array_map over the
     * UNFILTERED input: any row the AI was not asked about falls into the `else`
     * at :78-82, which assigns `fallbackSummary()`, and that returns '' at
     * :117-121. So the wipe fires ON THE AI-SUCCESS PATH. Reproducing it needs
     * BOTH (a) a prose sibling so the AI really is called, and (b) a row the AI
     * is not asked about. This fixture has three rooms:
     *
     *   0 Reception — office works_summary present  → filtered out by the job
     *   1 Boardroom — prose, no summary             → selected, AI generates
     *   2 Storeroom — EMPTY overview, no summary    → filtered out by the job
     *
     * Rooms 0 and 2 are exactly the rows that `:78-82` would wipe if the job
     * handed the service an unfiltered list. The job's row filter is the only
     * thing preventing it, and this asserts the result on rendered output.
     *
     * (Note this is NOT the `:47-55` short-circuit, which needs a LONE
     * memo-less row and is covered by its own direct-service test in
     * tests/Feature/Jobs/GenerateRoomWorksSummariesJobTest.php.)
     */
    public function test_state_2_office_authored_bullets_are_byte_identical_on_the_rendered_link(): void
    {
        $officeBullets = "Install 55in display\nRun HDMI and power to rack\nMount ceiling speaker";

        [$survey, $package] = $this->buildSurveyWithPackage([
            [
                'room'          => 'Reception',
                'overview'      => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack.',
                'works_summary' => $officeBullets,
            ],
            [
                'room'     => 'Boardroom',
                'overview' => 'Fit a video bar and two displays. Cable back to the credenza rack.',
            ],
            [
                'room'     => 'Storeroom',
                'overview' => '',
            ],
        ]);

        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => "- Fit the video bar beneath the left display\n- Dress the cables into the credenza rack"],
            ],
        ]);

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        [, $rooms] = $this->renderEngineerLink($survey);

        // Room 0 — the office's words, byte-identical, on the rendered link.
        $office = $rooms[0]['_ctx'];
        $this->assertSame('office', $office['planned_actions_source']);
        $this->assertSame(
            ['Install 55in display', 'Run HDMI and power to rack', 'Mount ceiling speaker'],
            $office['planned_actions'],
            'D-03: the office summary must be byte-identical after the job ran. A wipe would '
            .'show up here as an empty list or as a derived list.'
        );

        // Non-vacuity: the AI genuinely ran and genuinely wrote to the sibling.
        // Without this, the office assertion above would also pass if the job
        // had done nothing at all.
        $this->assertSame(
            [
                '- Fit the video bar beneath the left display',
                '- Dress the cables into the credenza rack',
            ],
            $rooms[1]['_ctx']['planned_actions'],
            'The sibling must have been generated — otherwise this test proves only that a job '
            .'which did nothing changed nothing.'
        );

        // Room 2 — the empty-overview row the service would have wiped. It had
        // no summary to begin with, so "unwiped" means the key is still absent
        // rather than present-and-empty.
        $stored = $this->storedRooms($package);
        $this->assertSame($officeBullets, (string) $stored[0]['works_summary'],
            'the stored office summary must also be byte-identical');
        $this->assertSame('', trim((string) ($stored[2]['works_summary'] ?? '')),
            'the empty-overview row must not have gained a generated summary (D-11)');
    }

    /**
     * STATE 3 (AIB-03 / D-11) — a memo-less room generates NOTHING, and still
     * renders derived bullets.
     *
     * ⚠ Deliberately asserted via `_ctx` and NOT via the string
     * "Install actions (from scope notes)". That string ships in every render in
     * every state (show.blade.php:600 is an x-text ternary), so asserting it
     * cannot fail. `planned_actions_source === 'derived'` plus a non-empty
     * `planned_actions` are both real, state-dependent values.
     *
     * The fixture separates the two surfaces' source keys, which is what makes
     * this state reachable at all:
     *   - the JOB reads `overview` only (GenerateRoomWorksSummariesJob:137), so
     *     an empty `overview` means nothing is selected and the AI is never asked;
     *   - the SURVEY prefers `description` for prose (SurveyController:364-368),
     *     so the derived path still has text to split.
     */
    public function test_state_3_a_memo_less_room_generates_nothing_and_still_renders_derived_bullets(): void
    {
        [$survey, $package] = $this->buildSurveyWithPackage([
            [
                'room'        => 'Reception',
                'overview'    => '',
                'description' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack. Mount a ceiling speaker above the desk.',
            ],
        ]);

        // The AI must never be asked about a room it has nothing to say about.
        // PHPUnit verifies this at teardown.
        $this->bindProviderNeverCalled();

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        [, $rooms] = $this->renderEngineerLink($survey);

        $ctx = $rooms[0]['_ctx'];

        $this->assertSame('derived', $ctx['planned_actions_source'],
            'D-11: nothing was generated, so the engineer link must fall to the derived path.');
        $this->assertNotEmpty($ctx['planned_actions'],
            'The derived path must still produce bullets — an engineer must not see an empty list.');
        $this->assertGreaterThanOrEqual(2, count($ctx['planned_actions']));

        // Nothing was generated, and nothing was wiped either.
        $stored = $this->storedRooms($package);
        $this->assertSame('', trim((string) ($stored[0]['works_summary'] ?? '')));
        $this->assertSame('', trim((string) $stored[0]['overview']),
            'D-05: the imported original is untouched.');
    }

    /**
     * STATE 4 (AIB-04 / D-04) — with the AI unavailable, the engineer link still
     * renders bullets.
     *
     * The provider throws on both call shapes. The job must not raise, and —
     * critically — must NOT persist the empty `works_summary` that
     * RoomOverviewSummaryService:86-99 hands back, because an empty-but-present
     * `works_summary` would still be an empty string to
     * SurveyController:486 and would therefore NOT erase the derived path (the
     * controller trims), but WOULD overwrite real bullets on a later regen. The
     * assertion below is on the engineer-visible outcome either way: the link
     * shows bullets.
     */
    public function test_state_4_with_the_ai_unavailable_the_link_still_renders_bullets(): void
    {
        [$survey, $package] = $this->buildSurveyWithPackage([
            [
                'room'     => 'Reception',
                'overview' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack. Mount a ceiling speaker above the desk.',
            ],
        ]);

        $this->bindProviderThrowing('provider unreachable');

        // Must not raise — D-04. An import or a document creation must never
        // fail because of this feature.
        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        [, $rooms] = $this->renderEngineerLink($survey);

        $ctx = $rooms[0]['_ctx'];

        $this->assertNotEmpty($ctx['planned_actions'],
            'D-04: with the AI down the engineer link must still show bullets.');
        $this->assertSame('derived', $ctx['planned_actions_source'],
            'With nothing generated the link degrades to SurveyPdfHelpers::textToBullets().');

        // The fallback marker is persisted; the empty works_summary is not.
        $stored = $this->storedRooms($package);
        $this->assertTrue((bool) ($stored[0]['_summary_fallback'] ?? false),
            'the _summary_fallback marker must be persisted so the review UI can badge the row');
        $this->assertSame('', trim((string) ($stored[0]['works_summary'] ?? '')),
            'an empty works_summary must never be persisted as content');
    }

    /**
     * T-46.9-10 — AI-authored text is untrusted text on a page a client may see.
     *
     * The count of unescaped echo openers in resources/views/surveys/show.blade.php
     * is ZERO and must stay zero. Counted with a comment-stripping read, not a
     * bare `grep -c`: this file's own header prose discusses the constraint, and
     * header prose can self-invalidate a naive count.
     */
    public function test_the_survey_engineer_link_blade_has_zero_unescaped_echoes(): void
    {
        $path = resource_path('views/surveys/show.blade.php');

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        // Strip Blade comments before counting.
        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        $this->assertSame(
            0,
            substr_count($stripped, '{!!'),
            'resources/views/surveys/show.blade.php must carry ZERO unescaped echoes — AI-authored '
            .'room text renders here and a client may see this page.'
        );

        // Non-vacuity of the count itself: the file really was read and really
        // does contain escaped echoes, so a zero is a measurement rather than
        // an empty string being counted.
        $this->assertGreaterThan(0, substr_count($stripped, '{{'),
            'the blade was not actually read — a zero unescaped count would be meaningless');
    }

    /**
     * Extract the `rooms: [...]` JSON array from the inline Alpine init
     * script by balanced-bracket scanning — a regex with a lazy `]` match
     * stops at the FIRST `]` it meets (e.g. an empty `photos: []` inside the
     * first room), so this walks the string counting bracket depth instead.
     *
     * @return array<int, array<string,mixed>>|null
     */
    private function extractRoomsJson(string $html): ?array
    {
        $needle    = 'rooms: ';
        $offset    = 0;
        $jsonStart = null;
        while (($start = strpos($html, $needle, $offset)) !== false) {
            $candidate = $start + strlen($needle);
            if (($html[$candidate] ?? '') === '[') {
                $jsonStart = $candidate;
                break;
            }
            $offset = $start + strlen($needle);
        }
        if ($jsonStart === null) {
            return null;
        }

        $depth = 0;
        $len   = strlen($html);
        for ($i = $jsonStart; $i < $len; $i++) {
            if ($html[$i] === '[') {
                $depth++;
            } elseif ($html[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($html, $jsonStart, $i - $jsonStart + 1);

                    return json_decode($json, true);
                }
            }
        }

        return null;
    }

    private function surveyToken(array $roomOverviews): string
    {
        [$survey] = $this->surveyWithPackage($roomOverviews);

        return $survey->access_token;
    }
}

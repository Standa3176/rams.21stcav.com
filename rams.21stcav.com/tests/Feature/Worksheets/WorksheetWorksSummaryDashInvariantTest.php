<?php

namespace Tests\Feature\Worksheets;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Providers\ClaudeProvider;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\AICacheService;
use App\Services\WorksheetGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 46.9 Plan 03 Task 2 — the C-04 CROSS-SURFACE INVARIANT.
 *
 * The finding this file exists to pin, asserted in BOTH directions on rendered
 * output, because an assertion on only the safe half is worthless:
 *
 *   - A `works_summary` whose lines ARE dash-prefixed renders as
 *     `works_summary_bullets` <li> rows on the worksheet.
 *   - A `works_summary` whose lines are NOT dash-prefixed is DISCARDED by the
 *     worksheet, WHILE STILL RENDERING on the survey engineer link.
 *
 * That asymmetry is the whole point. The two surfaces read the same stored
 * bytes through different filters:
 *
 *   WORKSHEET — WorksheetGeneratorService:111 (and again at :174 for the
 *   AI-backfill path) applies
 *       str_starts_with($bull, '- ') || str_contains($bull, "\n- ")
 *   before a works_summary is admitted to $roomBullets at all.
 *
 *   SURVEY — SurveyController:486-491 applies NO such rule. It trims, splits on
 *   newlines, drops empty lines, and labels the result 'office'.
 *
 * ⚠ CORRECTION TO CONTEXT.md C-04 AND TO THIS PHASE'S PLAN TEXT. Both say
 * :111/:174 "discard any works_summary LINE not dash-prefixed". They do not.
 * Those lines gate the whole BLOCK; once a block passes, :573-579 keeps EVERY
 * non-empty line and merely strips a leading '-' or '•'. So the real failure
 * mode is coarser and worse than described: a block with NO dashed line
 * ANYWHERE is dropped IN FULL, not line by line. This file asserts the real
 * behaviour — see test_a_mixed_block_with_one_dashed_line_is_admitted_in_full(),
 * which is only meaningful under the block reading and would be nonsense under
 * the per-line reading.
 *
 * Why this exists with zero new production code: four features in this project
 * so far have been built, working, unpopulated, and silent about it. An
 * invariant that nothing asserts is an invariant that nothing protects.
 *
 * ── Why counting <li> is legitimate HERE and not on the survey ──────────────
 * The worksheet is SERVER-rendered: resources/views/worksheets/show.blade.php
 * :563-577 is a Blade @foreach emitting `<li style="margin-bottom:.25rem;">`
 * per bullet. The survey's bullet rows are produced CLIENT-side by the Alpine
 * `<template x-for>` at surveys/show.blade.php:607-609, so no server assertion
 * can count them and this file does not try — the survey half is asserted
 * against the `rooms` JSON blob the page ships to Alpine.
 *
 * ⚠ DO NOT EDIT WorksheetShowViewParityTest. Its
 * assertEquals(2, substr_count($content, '<li style="margin-bottom:.25rem;">'))
 * at :186-190 is the highest-risk pin in this phase. This file is purely
 * ADDITIVE and shares no fixture with it.
 *
 * Test-environment hazards, all real:
 *  - The 30-day AICacheService would serve a stored response straight past any
 *    provider fake and produce a FAKE GREEN. Stubbed in setUp(), per
 *    tests/Unit/Services/RoomOverviewSummaryFallbackTest.php:33-35.
 *  - Mockery::mock(AIManager::class) does not work — AIManager::run() is called
 *    STATICALLY. The seam is a mock AIProviderContract bound over
 *    ClaudeProvider::class.
 *  - A provider returning a STRING install_steps is bound, rather than a
 *    never()-expecting or throwing one. The generator makes an unrelated AI
 *    call for install-step narrative at WorksheetGeneratorService:519-531, so a
 *    never() expectation would fail for a reason that has nothing to do with
 *    this invariant. It must return a STRING for the reason in the next note.
 *    This still keeps the suite off the network, and it still proves the
 *    bullets under test came from the STORED works_summary: every bullet
 *    asserted below is byte-identical to the fixture, and the backfill branch
 *    that could have generated one never arms (see the short-description note).
 *
 *  - ⚠ PRE-EXISTING PRODUCTION DEFECT, found by this test and DELIBERATELY NOT
 *    FIXED HERE. If the install-step AI call fails or returns nothing,
 *    WorksheetGeneratorService:543 assigns `$installSteps = $phasedPlan`, and
 *    buildPhasedPlan():772 is declared `: array`. The show view then does
 *    `{{ $room['install_steps'] }}` at worksheets/show.blade.php:587 with no
 *    array handling, so rendering dies with
 *    `htmlspecialchars(): Argument #1 ($string) must be of type string, array
 *    given` — an HTTP 500 on the worksheet page whenever the AI is unavailable.
 *    Reproduced exactly by binding a throwing provider here.
 *
 *    It is NOT fixed in this plan because this plan changes NO production code
 *    and edits NO Blade file (46.9-03 objective: "If a rendered assertion
 *    cannot be made true without a production change, that is a finding — STOP
 *    and report it rather than editing a Blade file to make a test pass"). It
 *    is unrelated to works_summary and to this phase's scope fence. It is
 *    reported in 46.9-03-SUMMARY.md. The fixture therefore binds a provider
 *    that returns a string, which is the normal production path.
 *
 *  - Room descriptions are kept deliberately SHORT (< 40 chars) so the
 *    bullet-backfill branch at WorksheetGeneratorService:122-124 never arms.
 */
class WorksheetWorksSummaryDashInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dashed block. Two non-empty lines, both dash-prefixed — so the block
     * gate at WorksheetGeneratorService:111 admits it and :573-579 strips the
     * leading dash from each line.
     */
    private const DASHED_SUMMARY = "- Mount the Boardroom display at 1200mm AFFL\n- Terminate the Boardroom cable at the floor plate";

    /**
     * The non-dashed block. Two non-empty lines, NEITHER dash-prefixed and no
     * "\n- " anywhere — so the block gate rejects it WHOLESALE.
     */
    private const PLAIN_SUMMARY = "Mount the Reception display at 1200mm AFFL\nTerminate the Reception cable at the floor plate";

    protected function setUp(): void
    {
        parent::setUp();

        // Mandatory: a stored 30-day cache response would fake a green.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash-46-9-03-dash');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);

        // See the class docblock. This returns a STRING install_steps and
        // deliberately returns NO `summaries` key, so if the bullet-backfill
        // branch ever did arm it would produce nothing — it could never supply
        // one of the bullets asserted below.
        $narrative = ['install_steps' => 'Deterministic test narrative for the install steps block.'];

        $provider = $this->createMock(AIProviderContract::class);
        $provider->method('execute')->willReturn($narrative);
        $provider->method('completeJson')->willReturn($narrative);
        $provider->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $provider);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * One project carrying BOTH rooms, so both halves of the invariant are read
     * from the same stored bytes.
     *
     * Rooms reach the worksheet via equipment `area` tags (Strategy 1,
     * WorksheetGeneratorService:357-370 → buildRoomsFromAreaTags:443-463), which
     * is the only room-resolution strategy that does not depend on a completed
     * survey. A room with no install hardware is skipped outright at
     * :496-505, which is why each room carries a display.
     *
     * @return array{0: Project, 1: User, 2: ProjectPackage}
     */
    private function projectWithBothRooms(): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create([
            'user_id'      => $user->id,
            'name'         => 'Dash Invariant',
            'site_address' => '4 Test Street',
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment' => [
                    ['name' => 'Samsung QM55B Display', 'manufacturer' => 'Samsung', 'area' => 'Boardroom', 'quantity' => 1],
                    ['name' => 'Samsung QM55B Display', 'manufacturer' => 'Samsung', 'area' => 'Reception', 'quantity' => 1],
                ],
                'room_overviews' => [
                    [
                        'room'          => 'Boardroom',
                        // Short on purpose: >= 40 chars would arm the
                        // bullet-backfill branch and make an AI call.
                        'description'   => 'Refresh works.',
                        'works_summary' => self::DASHED_SUMMARY,
                    ],
                    [
                        'room'          => 'Reception',
                        'description'   => 'Refresh works.',
                        'works_summary' => self::PLAIN_SUMMARY,
                    ],
                ],
            ],
        ]);

        return [$project, $user, $package];
    }

    /** Generate and persist worksheet content, then render the show view. */
    private function renderWorksheet(Project $project, User $user): string
    {
        $worksheet = Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
            'status'     => Worksheet::STATUS_DRAFT,
        ]);

        $generated = app(WorksheetGeneratorService::class)
            ->generateContent($worksheet->fresh()->load('project'));

        $worksheet->update(['generated_data' => $generated]);

        $html = $this->actingAs($user)
            ->get(route('worksheets.show', $worksheet))
            ->assertOk()
            ->getContent();

        // The <li> style this file counts is also used by the Outstanding Items
        // aggregate at worksheets/show.blade.php:313. This fixture has no
        // sign-offs, so that block is absent — asserted rather than assumed, so
        // the count below cannot be quietly contaminated.
        $this->assertStringNotContainsString('Outstanding Items', $html,
            'the Outstanding Items block shares the counted <li> style — it must be absent for the count to mean anything');

        return $html;
    }

    /** Build the rooms payload the generator produced, for direct inspection. */
    private function generatedRooms(Project $project, User $user): array
    {
        $worksheet = Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
            'status'     => Worksheet::STATUS_DRAFT,
        ]);

        $generated = app(WorksheetGeneratorService::class)
            ->generateContent($worksheet->fresh()->load('project'));

        $byName = [];
        foreach ((array) ($generated['rooms'] ?? []) as $room) {
            $byName[(string) ($room['name'] ?? '')] = $room;
        }

        return $byName;
    }

    /**
     * Render the survey engineer link for the same project and return the
     * decoded `rooms` JSON blob the page ships to Alpine.
     */
    private function renderEngineerLink(Project $project, User $user): array
    {
        $token  = (string) Str::uuid();
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Dash Invariant',
            'site_address' => '4 Test Street',
            // Left as draft deliberately: a COMPLETED survey would merge into
            // the worksheet's room resolution (ProjectDataService:268-271) and
            // change what the worksheet half is measuring.
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (['Boardroom', 'Reception'] as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        $html = $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();

        $rooms = $this->extractRoomsJson($html);
        $this->assertIsArray($rooms, 'rooms JSON blob did not decode.');

        $byName = [];
        foreach ($rooms as $room) {
            $byName[(string) ($room['room_name'] ?? $room['name'] ?? '')] = $room;
        }

        return $byName;
    }

    /** @return array<int,string> the non-empty lines of a works_summary block */
    private function linesOf(string $block): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $block) ?: []),
            fn ($l) => $l !== ''
        ));
    }

    // =========================================================================
    // THE INVARIANT — both directions, same stored bytes
    // =========================================================================

    /**
     * DIRECTION 1 — a dash-prefixed block renders as <li> rows on the worksheet.
     * DIRECTION 2 — a non-dashed block is discarded by the worksheet.
     *
     * Both halves in one test, deliberately, because the asymmetry IS the
     * finding and splitting it invites one half being read in isolation.
     */
    public function test_a_dashed_block_renders_on_the_worksheet_and_a_non_dashed_block_is_discarded(): void
    {
        [$project, $user] = $this->projectWithBothRooms();

        $html = $this->renderWorksheet($project, $user);

        // ── Direction 1: the dashed block IS rendered, dash stripped ─────────
        // WorksheetGeneratorService:573-579 strips the leading '-' per line.
        foreach ($this->linesOf(self::DASHED_SUMMARY) as $line) {
            $stripped = (string) preg_replace('/^[-•]\s*/', '', $line);
            $this->assertStringContainsString($stripped, $html,
                "the dashed works_summary line must render on the worksheet: {$stripped}");
        }
        $this->assertStringContainsString('Engineer Work Summary', $html);

        // ── Direction 2: the non-dashed block is NOT rendered ────────────────
        // WorksheetGeneratorService:111 gates the whole BLOCK, so neither line
        // survives — this is the silent vanish C-04 names.
        foreach ($this->linesOf(self::PLAIN_SUMMARY) as $line) {
            $this->assertStringNotContainsString($line, $html,
                "the non-dashed works_summary line must be DISCARDED by the worksheet: {$line}");
        }

        // ── The count, derived from the fixture, not hard-coded ──────────────
        // Expected = the dashed room's non-empty line count (2) + the plain
        // room's contribution (0, because its block was rejected wholesale).
        // Counting the style string is only safe because renderWorksheet() has
        // already asserted the Outstanding Items block (the only other user of
        // this <li> style, at worksheets/show.blade.php:313) is absent.
        $expected = count($this->linesOf(self::DASHED_SUMMARY));

        $this->assertSame(
            $expected,
            substr_count($html, '<li style="margin-bottom:.25rem;">'),
            'exactly the dashed room\'s lines may render as bullet rows; the non-dashed room must '
            .'contribute none'
        );
    }

    /**
     * DIRECTION 2, the other half — the SAME non-dashed bytes DO render on the
     * survey engineer link.
     *
     * Without this, the discard assertion above is just "a thing that is not
     * there is not there". This is what makes it a cross-surface asymmetry
     * rather than an absence.
     *
     * Asserted against the `rooms` JSON blob, NOT by counting <li> — the survey
     * renders its bullet rows client-side (surveys/show.blade.php:607-609).
     */
    public function test_the_same_non_dashed_block_still_renders_on_the_survey_engineer_link(): void
    {
        [$project, $user] = $this->projectWithBothRooms();

        $rooms = $this->renderEngineerLink($project, $user);

        $this->assertArrayHasKey('Reception', $rooms);
        $reception = $rooms['Reception']['_ctx'];

        // SurveyController:486-491 applies NO dash rule: it trims, splits on
        // newlines and labels the result 'office'. The dash is not stripped
        // either — which is why the dashed room below keeps its dashes.
        $this->assertSame('office', $reception['planned_actions_source']);
        $this->assertSame(
            $this->linesOf(self::PLAIN_SUMMARY),
            $reception['planned_actions'],
            'the non-dashed block must render in full on the survey — this is the half of the '
            .'asymmetry the worksheet silently drops'
        );

        // And the dashed room on the survey keeps its leading dashes, because
        // the survey never strips them. Two surfaces, two different renderings
        // of the same stored bytes.
        $boardroom = $rooms['Boardroom']['_ctx'];
        $this->assertSame('office', $boardroom['planned_actions_source']);
        $this->assertSame(
            $this->linesOf(self::DASHED_SUMMARY),
            $boardroom['planned_actions'],
            'SurveyController does not strip the leading dash — asserted as the actual behaviour'
        );
    }

    /**
     * The generated_data payload, asserted directly — so a future renderer
     * change cannot make the discard invisible by accident.
     *
     * This is the generator-level statement of the same invariant:
     * works_summary_bullets is populated for the dashed room and EMPTY for the
     * non-dashed one.
     */
    public function test_works_summary_bullets_is_populated_for_the_dashed_room_and_empty_for_the_other(): void
    {
        [$project, $user] = $this->projectWithBothRooms();

        $rooms = $this->generatedRooms($project, $user);

        $this->assertArrayHasKey('Boardroom', $rooms);
        $this->assertArrayHasKey('Reception', $rooms);

        $expectedBullets = array_map(
            fn ($l) => (string) preg_replace('/^[-•]\s*/', '', $l),
            $this->linesOf(self::DASHED_SUMMARY)
        );

        $this->assertSame(
            $expectedBullets,
            array_values((array) $rooms['Boardroom']['works_summary_bullets']),
            'the dashed block must survive WorksheetGeneratorService:111 and be dash-stripped at :573-579'
        );

        $this->assertSame(
            [],
            array_values((array) $rooms['Reception']['works_summary_bullets']),
            'the non-dashed block must be rejected wholesale by the block gate at '
            .'WorksheetGeneratorService:111'
        );

        // And because the bullets are empty, the room falls to the templated
        // prose paragraph — so the engineer gets SOMETHING, but not what the
        // office wrote. That substitution is the damage, stated plainly.
        $this->assertNotSame(
            '',
            trim((string) $rooms['Reception']['room_works_description']),
            'the discarded room falls back to a templated paragraph, which is not what the office wrote'
        );
        $this->assertSame(
            '',
            trim((string) $rooms['Boardroom']['room_works_description']),
            'WorksheetGeneratorService:580-586 blanks the prose paragraph when bullets exist'
        );
    }

    /**
     * ⚠ THE CORRECTION TO C-04, asserted.
     *
     * CONTEXT.md C-04 and this phase's plan text both describe :111/:174 as
     * discarding "any LINE not dash-prefixed". If that were true, this test's
     * non-dashed line would be dropped and only the dashed one would render.
     * It is NOT true: :111 gates the BLOCK, and once a block passes, :573-579
     * keeps EVERY non-empty line. So a MIXED block is admitted IN FULL,
     * including its non-dashed lines.
     *
     * This test is therefore the discriminating one: it passes under the block
     * reading and fails under the per-line reading. It is the reason the
     * citation must be fixed rather than inherited.
     */
    public function test_a_mixed_block_with_one_dashed_line_is_admitted_in_full(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create([
            'user_id'      => $user->id,
            'name'         => 'Mixed Block',
            'site_address' => '5 Test Street',
        ]);

        // Line 1 is dash-prefixed, line 2 is not. The block therefore passes
        // str_starts_with($bull, '- ') at :111.
        $mixed = "- Mount the Boardroom display at 1200mm AFFL\nTerminate the Boardroom cable at the floor plate";

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment' => [
                    ['name' => 'Samsung QM55B Display', 'manufacturer' => 'Samsung', 'area' => 'Boardroom', 'quantity' => 1],
                ],
                'room_overviews' => [
                    ['room' => 'Boardroom', 'description' => 'Refresh works.', 'works_summary' => $mixed],
                ],
            ],
        ]);

        $rooms = $this->generatedRooms($project, $user);

        $this->assertSame(
            [
                'Mount the Boardroom display at 1200mm AFFL',
                'Terminate the Boardroom cable at the floor plate',
            ],
            array_values((array) $rooms['Boardroom']['works_summary_bullets']),
            'C-04 as written in CONTEXT.md is WRONG: :111 gates the BLOCK, not each line, so the '
            .'non-dashed second line is admitted in full once the block passes. The real failure '
            .'mode is that a block with NO dashed line anywhere is dropped ENTIRELY.'
        );
    }

    /**
     * Extract the `rooms: [...]` JSON array from the inline Alpine init script
     * by balanced-bracket scanning. Mirrors
     * tests/Feature/Surveys/SurveyPlannedActionsBulletsTest.php:extractRoomsJson()
     * — a regex with a lazy `]` match stops at the FIRST `]` it meets (e.g. an
     * empty `photos: []` inside the first room).
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
                    return json_decode(substr($html, $jsonStart, $i - $jsonStart + 1), true);
                }
            }
        }

        return null;
    }
}

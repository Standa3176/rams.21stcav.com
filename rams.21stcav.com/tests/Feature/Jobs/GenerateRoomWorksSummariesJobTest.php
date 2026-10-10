<?php

namespace Tests\Feature\Jobs;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Providers\ClaudeProvider;
use App\Jobs\GenerateRoomWorksSummariesJob;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\AICacheService;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.9 Plan 01 — contract tests for GenerateRoomWorksSummariesJob.
 *
 * These tests are written RED, before the job class exists, and they are not a
 * formality. Two of them exist because of specific paid-for failures:
 *
 *  - Test B is the crux. RoomOverviewSummaryService WIPES an office-authored
 *    works_summary through TWO distinct paths, and the job's row filter is the
 *    only thing standing between the office's words and that wipe. Both wipes
 *    are reproduced by direct service call in this file, so the filter is
 *    demonstrably load-bearing rather than decorative.
 *  - Every test asserts BOTH sides. A test that asserts only the safe half of a
 *    condition shipped a live stoppage on 2026-09-30.
 *
 * Test-environment hazards, all three real:
 *  - phpunit.xml:40 sets QUEUE_CONNECTION=sync, so a dispatched job runs inline.
 *    These tests drive handle() directly instead, per
 *    GenerateSurveyQuestionsJobTest:143. Dispatch assertions belong to Plan 46.9-02.
 *  - The 30-day AICacheService will serve a stored response straight past any
 *    provider fake and produce a FAKE GREEN. It is stubbed in setUp(), per
 *    RoomOverviewSummaryFallbackTest:33-35.
 *  - GenerateSurveyQuestionsJobTest:135-141's Mockery::mock(AIManager::class)
 *    trick WILL NOT WORK here: RoomOverviewSummaryService:59 calls
 *    AIManager::run() STATICALLY. The only seam that works is a mock
 *    AIProviderContract bound over ClaudeProvider::class, per
 *    RoomOverviewSummaryFallbackTest:44-52.
 */
class GenerateRoomWorksSummariesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mandatory: bypass the AI cache so the "live" call is always attempted
        // and a stored 30-day response cannot fake a green.
        // Seam: RoomOverviewSummaryFallbackTest:33-35.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash-46-9-01');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a package carrying extracted_data.room_overviews directly, as
     * tests/Feature/Surveys/SurveyPlannedActionsBulletsTest.php:36-45 does.
     * No factory is added for this.
     */
    private function packageWithRooms(array $roomOverviews): ProjectPackage
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create([
            'name'         => 'Room Works Summaries',
            'site_address' => '3 Test Street',
        ]);

        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'room_overviews' => $roomOverviews,
            ],
        ]);
    }

    /** Re-read the persisted rows from the database, never from memory. */
    private function storedRooms(ProjectPackage $package): array
    {
        return (array) ($package->fresh()->extracted_data['room_overviews'] ?? []);
    }

    /** Bind a provider that returns the given decoded JSON payload. */
    private function bindProviderReturning(array $payload): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willReturn($payload);
        $mock->method('completeJson')->willReturn($payload);
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    /** Bind a provider that fails hard on every call shape. */
    private function bindProviderThrowing(string $message): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willThrowException(new \RuntimeException($message));
        $mock->method('completeJson')->willThrowException(new \RuntimeException($message));
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    /**
     * Bind a provider that asserts it is NEVER asked anything. This is the
     * positive half of "the AI must not be consulted about a room it has
     * nothing to say about" — a status-only assertion would pass even if a
     * real network call had been made.
     */
    private function bindProviderNeverCalled(): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->expects($this->never())->method('execute');
        $mock->expects($this->never())->method('completeJson');
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }

    // ── Test A (AIB-01): the gap — empty works_summary gets filled ───────────

    /**
     * The real fresh-import shape is TWO keys only — QuoteWerksImportService:193-199
     * emits {room, overview} and works_summary is not even a key. The job must
     * fill it, and must leave the imported original alone (D-05).
     */
    public function test_a_room_with_no_works_summary_key_and_prose_gets_ai_bullets(): void
    {
        $overview = 'Oregano is now using the Crestron small room system with a Jabra PanaCast 50 '
            . 'video bar, and offers full room control from a single Crestron panel with a new '
            . '75" display mounted on the north wall.';

        $package = $this->packageWithRooms([
            ['room' => 'Boardroom', 'overview' => $overview],
        ]);

        $bullets = "- Mount the 75in display on the north wall\n- Run HDMI and power to the rack";

        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => $bullets],
            ],
        ]);

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        $rooms = $this->storedRooms($package);

        $this->assertCount(1, $rooms, 'the job must not add or drop a room row');
        $this->assertSame($bullets, $rooms[0]['works_summary'] ?? null,
            'the AI bullets must be persisted verbatim into works_summary');

        // Both sides: the imported original survives byte-identical (D-05).
        $this->assertSame($overview, $rooms[0]['overview'],
            'the imported overview must be kept byte-identical — nothing is replaced');
    }

    // ── Test B (AIB-02): THE CRUX — the office's words are never destroyed ───

    /**
     * Reception is a memo-less room carrying an OFFICE-AUTHORED works_summary.
     * Boardroom has prose and no summary. After the job, Reception must be
     * byte-identical AND Boardroom must have bullets — both halves, because a
     * job that wrote nothing at all would pass the first assertion alone.
     */
    public function test_an_office_authored_works_summary_survives_while_a_sibling_room_is_generated(): void
    {
        $officeText = "Install 55in display\nRun HDMI to rack";

        $package = $this->packageWithRooms([
            ['room' => 'Reception', 'overview' => '', 'works_summary' => $officeText],
            ['room' => 'Boardroom', 'overview' => 'New Crestron Flex kit with a 75" display and a ceiling microphone array.'],
        ]);

        $bullets = "- Install the Crestron Flex kit\n- Fit the ceiling microphone array";

        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => $bullets],
            ],
        ]);

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        $rooms = $this->storedRooms($package);

        $this->assertCount(2, $rooms, 'the job must not add or drop a room row');
        $this->assertSame($officeText, $rooms[0]['works_summary'],
            'D-03: hand-authored text always wins and is never rewritten, not even reformatted');
        $this->assertSame($bullets, $rooms[1]['works_summary'] ?? null,
            'the sibling room with prose and no summary must still have been generated');
    }

    /**
     * Wipe 1 — the AI-SUCCESS-path wipe, RoomOverviewSummaryService:72-84.
     *
     * summarize()'s final array_map iterates $roomOverviews, the UNFILTERED
     * input, not the filtered $rows/$roomsForAi. Any row that does not come
     * back with a matching non-empty AI summary falls into the else at :78-82,
     * which assigns fallbackSummary() — and fallbackSummary() returns ''
     * UNCONDITIONALLY at :117-121. So an office-authored row is wiped EVEN WHEN
     * THE AI CALL SUCCEEDS, simply because the AI was never asked about it.
     *
     * This is the wipe the two-row fixture above would hit if the job handed the
     * service an unfiltered list, because $roomsForAi is non-empty (Boardroom).
     *
     * If this ever goes green the other way, the service changed and this
     * plan's premise is void — STOP and report rather than adjusting the test.
     */
    public function test_the_service_wipes_an_office_summary_on_the_ai_success_path_lines_72_to_84(): void
    {
        $officeText = "Install 55in display\nRun HDMI to rack";

        $unfiltered = [
            ['room' => 'Reception', 'overview' => '', 'works_summary' => $officeText],
            ['room' => 'Boardroom', 'overview' => 'New Crestron Flex kit with a 75" display.'],
        ];

        // A WORKING provider — the point is that success is not safety.
        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => '- Install the Crestron Flex kit'],
            ],
        ]);

        $out = app(RoomOverviewSummaryService::class)->summarize($unfiltered);

        $this->assertSame('- Install the Crestron Flex kit', $out[1]['works_summary'],
            'non-vacuity: the AI call must genuinely have succeeded for this to be the success-path wipe');
        $this->assertSame('', $out[0]['works_summary'],
            'RoomOverviewSummaryService:72-84 — the else at :78-82 assigns fallbackSummary(), '
            . 'which returns "" unconditionally (:117-121), wiping the office text on AI SUCCESS');
    }

    /**
     * Wipe 2 — the no-overview short-circuit, RoomOverviewSummaryService:47-55.
     *
     * With a single memo-less row, $roomsForAi is empty, the method
     * short-circuits BEFORE any AI call and returns every row with
     * works_summary = ''. A different mechanism from Wipe 1, prevented by the
     * same job-side filter.
     */
    public function test_the_service_wipes_an_office_summary_via_the_no_overview_short_circuit_lines_47_to_55(): void
    {
        $officeText = "Install 55in display\nRun HDMI to rack";

        // The AI must not even be consulted — that is what makes this :47-55
        // and not :72-84.
        $this->bindProviderNeverCalled();

        $out = app(RoomOverviewSummaryService::class)->summarize([
            ['room' => 'Reception', 'overview' => '', 'works_summary' => $officeText],
        ]);

        $this->assertSame('', $out[0]['works_summary'],
            'RoomOverviewSummaryService:47-55 sets works_summary = "" on every row it was handed '
            . 'when no row has an overview');
        $this->assertArrayNotHasKey('_summary_fallback', $out[0],
            'the short-circuit is not a fallback situation — the AI was never asked');
    }

    // ── Test C (AIB-03): an empty overview generates nothing ─────────────────

    /**
     * D-11: a room whose imported overview is empty gets nothing generated.
     * The job must not write a works_summary, must not add a row (D-09), and
     * must not ask the AI about a room it has nothing to say about.
     *
     * The specific thing asserted: the row comes out with NO works_summary KEY
     * AT ALL, because the job filters the row out before the service is called
     * and therefore never writes to that index. An empty-string key would be
     * indistinguishable from the wipe this phase exists to prevent.
     */
    public function test_a_room_with_an_empty_overview_generates_nothing_and_the_ai_is_never_asked(): void
    {
        $package = $this->packageWithRooms([
            ['room' => 'Store', 'overview' => ''],
        ]);

        $this->bindProviderNeverCalled();

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        $rooms = $this->storedRooms($package);

        $this->assertCount(1, $rooms, 'D-09: the job never adds a room row');
        $this->assertArrayNotHasKey('works_summary', $rooms[0],
            'D-11: nothing is generated for a memo-less room, and no empty string is written either');
        $this->assertSame('', $rooms[0]['overview'], 'the row is otherwise untouched');
    }

    // ── Test D (AIB-04): AI down never breaks anything ───────────────────────

    /**
     * With the provider throwing on every call shape, AIManager::run() raises
     * AIGenerationException, the service catches it and returns every row with
     * works_summary = '' plus _summary_fallback = true. The job must NOT
     * persist that empty string over anything — the refuse-to-persist-empty
     * precedent is WorksheetGeneratorService:160-176.
     */
    public function test_an_unreachable_provider_neither_throws_nor_persists_an_empty_summary(): void
    {
        $officeText = "Install 55in display\nRun HDMI to rack";

        $package = $this->packageWithRooms([
            ['room' => 'Reception', 'overview' => '', 'works_summary' => $officeText],
            ['room' => 'Boardroom', 'overview' => 'New Crestron Flex kit with a 75" display.'],
        ]);

        $this->bindProviderThrowing('provider unreachable');

        // Both sides: the job returns normally AND the data is intact.
        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        $this->addToAssertionCount(1); // handle() returned without raising

        $rooms = $this->storedRooms($package);

        $this->assertCount(2, $rooms, 'the job must not add or drop a room row');
        $this->assertSame($officeText, $rooms[0]['works_summary'],
            'the office text must still be byte-identical after an AI outage');
        $this->assertArrayNotHasKey('works_summary', $rooms[1],
            'no empty string may be persisted over a row that had no summary — '
            . 'WorksheetGeneratorService:160-176 is the precedent for that refusal');
        $this->assertTrue($rooms[1]['_summary_fallback'] ?? false,
            'the fallback marker IS persisted so the review UI can badge '
            . '"AI unavailable — click Generate to retry"');
    }

    // ── Test E (AIB-05): the dash-prefix invariant ───────────────────────────

    /**
     * Decision, written down here because the plan asks for one of two options
     * and the reason matters: the job NORMALISES a non-dashed line to "- " and
     * persists it, rather than discarding the AI output.
     *
     * Why normalise and not discard: WorksheetGeneratorService:111,174 gate the
     * works_summary BLOCK — a block with no dashed line at all is discarded
     * wholesale, while the survey has no such rule, so the text would render on
     * the survey and vanish from the worksheet. Discarding would throw away
     * usable AI content and leave the room with nothing; normalising makes both
     * surfaces render the same lines. Normalising is also visually free,
     * because WorksheetGeneratorService:578 strips the leading dash again when
     * it builds works_summary_bullets.
     */
    public function test_non_dashed_ai_lines_are_normalised_so_the_worksheet_cannot_discard_them(): void
    {
        $package = $this->packageWithRooms([
            ['room' => 'Boardroom', 'overview' => 'New Crestron Flex kit with a 75" display.'],
        ]);

        $this->bindProviderReturning([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => "Mount the display\nRun the cable"],
            ],
        ]);

        (new GenerateRoomWorksSummariesJob($package->id))->handle();

        $rooms = $this->storedRooms($package);
        $stored = (string) ($rooms[0]['works_summary'] ?? '');

        $this->assertSame("- Mount the display\n- Run the cable", $stored,
            'every non-empty line must be dash-prefixed before it is persisted');

        // Both sides, stated as the invariant rather than the string: no
        // persisted line may be invisible to the worksheet's dash filter.
        foreach (preg_split('/\r?\n/', $stored) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertStringStartsWith('- ', $line,
                'WorksheetGeneratorService:111,174 discard a works_summary block with no dashed line');
        }
    }
}

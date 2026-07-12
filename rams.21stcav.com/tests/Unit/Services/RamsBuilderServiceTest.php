<?php

namespace Tests\Unit\Services;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
use App\Models\RamsDocument;
use App\Services\EquipmentClassifierService;
use App\Services\MethodStatementGeneratorService;
use App\Services\QuoteParserService;
use App\Services\RamsBuilderService;
use App\Services\RamsDataBuilderService;
use App\Services\RamsDocumentRendererService;
use App\Services\RiskTemplateResolverService;
use App\Services\Rams\Tier1RamsDefaultsService;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for RamsBuilderService D-02 skip-guard logic.
 *
 * Tests the all-summaries-populated check before summarize() in runFromReview().
 */
class RamsBuilderServiceTest extends TestCase
{
    private RoomOverviewSummaryService $roomOverviewSummary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomOverviewSummary = Mockery::mock(RoomOverviewSummaryService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    private function makeRecordMock(): RamsDocument
    {
        $mock = Mockery::mock(RamsDocument::class);
        $mock->shouldReceive('getAttribute')->with('id')->andReturn(1)->byDefault();
        $mock->shouldReceive('getAttribute')->with('user_id')->andReturn(null)->byDefault();
        $mock->shouldReceive('getAttribute')->with('project_ref')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('project_name')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('client_name')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('site_address')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('reviewed_data')->andReturn([])->byDefault();
        $mock->shouldReceive('update')->andReturn(true)->byDefault();
        $mock->shouldReceive('setAttribute')->andReturnSelf()->byDefault();
        return $mock;
    }

    private function makeService(
        RoomOverviewSummaryService $roomOverviewSummary,
        ?MethodStatementGeneratorService $methodStatementMock = null
    ): RamsBuilderService {
        $methodMock = $methodStatementMock ?? Mockery::mock(MethodStatementGeneratorService::class);
        if ($methodStatementMock === null) {
            $methodMock->shouldReceive('generate')->andReturn(['phases' => []])->byDefault();
        }
        return new RamsBuilderService(
            Mockery::mock(QuoteParserService::class),
            Mockery::mock(EquipmentClassifierService::class),
            Mockery::mock(RiskTemplateResolverService::class),
            $methodMock,
            Mockery::mock(RamsDataBuilderService::class),
            Mockery::mock(RamsDocumentRendererService::class),
            Mockery::mock(HazardLibraryService::class),
            $roomOverviewSummary,
            // 260712-twi Task 1: fallback layer service — real instance, no
            // mock needed. Pure array-work + config reads.
            new Tier1RamsDefaultsService(),
        );
    }

    private function invokeRunFromReview(
        RamsBuilderService $service,
        array $reviewedData,
        RamsDocument $record,
        array $formData = []
    ): mixed {
        $method = new \ReflectionMethod(RamsBuilderService::class, 'runFromReview');
        $method->setAccessible(true);
        return $method->invoke($service, $reviewedData, $formData, $record);
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    private function makePopulatedRoomOverviews(): array
    {
        return [
            ['room' => 'Board Room',    'summary' => 'Install display and conferencing system.', 'description' => 'Boardroom with 86" display.'],
            ['room' => 'Training Room', 'summary' => 'Install projector and audio system.',      'description' => 'Training room with projector.'],
        ];
    }

    private function makeEmptySummaryRoomOverviews(): array
    {
        return [
            ['room' => 'Board Room',    'summary' => 'Install display and conferencing system.', 'description' => 'Boardroom with 86" display.'],
            ['room' => 'Training Room', 'summary' => '',                                          'description' => 'Training room with projector.'],
        ];
    }

    private function makeBaseReviewedData(array $roomOverviews = []): array
    {
        return [
            'project'                => ['client_name' => 'Test Client', 'site_address' => 'Test Site', 'quote_ref' => 'Q001', 'project_name' => 'Test Project'],
            'equipment'              => [],
            'activities'             => [],
            'hazards'                => [],
            'ppe'                    => [],
            'access'                 => [],
            'room_overviews'         => $roomOverviews,
            'method_statement_notes' => '',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'A two-sentence project overview.',
            'site_logistics'         => [],
        ];
    }

    // ── Test 1: All summaries populated — summarize() NOT called ─────────────

    /**
     * When every room_overview has a non-empty summary, summarize() must be skipped.
     */
    public function test_skip_summarize_when_all_summaries_populated(): void
    {
        $roomOverviews = $this->makePopulatedRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);
        $record        = $this->makeRecordMock();

        // summarize() must NOT be called in the skip path.
        $this->roomOverviewSummary->shouldNotReceive('summarize');

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $service = $this->makeService($this->roomOverviewSummary, $methodMock);

        $summarizeCalled = false;
        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {
            // Downstream may throw — summarize() assertion is on the mock.
        }

        // Mockery verifies shouldNotReceive on close() in tearDown.
        // Add an explicit assertion to avoid "risky test" warning.
        $this->assertTrue(true, 'summarize() was not called (verified by Mockery)');
    }

    // ── Test 2: Any summary empty — summarize() IS called ────────────────────

    /**
     * When any room_overview has an empty summary, summarize() must be called.
     */
    public function test_calls_summarize_when_any_summary_is_empty(): void
    {
        $roomOverviews  = $this->makeEmptySummaryRoomOverviews();
        $reviewedData   = $this->makeBaseReviewedData($roomOverviews);
        $summarizedData = array_map(fn ($r) => array_merge($r, ['summary' => 'Generated summary.']), $roomOverviews);

        $summarizeCalled = false;
        $this->roomOverviewSummary
            ->shouldReceive('summarize')
            ->once()
            ->with($roomOverviews)
            ->andReturnUsing(function () use ($summarizedData, &$summarizeCalled) {
                $summarizeCalled = true;
                return $summarizedData;
            });

        $record  = $this->makeRecordMock();
        $service = $this->makeService($this->roomOverviewSummary);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        $this->assertTrue($summarizeCalled, 'summarize() was not called when a room summary was empty');
    }

    // ── Test 3: parsedQuote room_overviews and rooms set in skip path ─────────

    /**
     * In the skip path, parsedQuote['room_overviews'] and parsedQuote['rooms'] must be set.
     */
    public function test_parsed_quote_room_overviews_set_in_skip_path(): void
    {
        $roomOverviews = $this->makePopulatedRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);
        $record        = $this->makeRecordMock();

        $this->roomOverviewSummary->shouldNotReceive('summarize');

        $capturedParsed = null;
        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (array $parsed) use (&$capturedParsed) {
                $capturedParsed = $parsed;
                return ['phases' => []];
            });

        $service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        $this->assertNotNull($capturedParsed, 'parsedQuote was not passed to generate()');
        $this->assertArrayHasKey('room_overviews', $capturedParsed);
        $this->assertCount(2, $capturedParsed['room_overviews']);
        $this->assertArrayHasKey('rooms', $capturedParsed);
        $this->assertSame(['Board Room', 'Training Room'], $capturedParsed['rooms']);
    }

    // ── Test 4: Non-array room entry treated as empty — triggers summarize() ──

    /**
     * Non-array room entries must be treated as "empty summary" and trigger summarize().
     */
    public function test_non_array_room_entry_triggers_summarize(): void
    {
        $roomOverviews = [
            ['room' => 'Board Room', 'summary' => 'Some summary.'],
            'not_an_array_entry',
        ];
        $reviewedData = $this->makeBaseReviewedData($roomOverviews);
        $record       = $this->makeRecordMock();

        $summarizeCalled = false;
        $this->roomOverviewSummary
            ->shouldReceive('summarize')
            ->once()
            ->andReturnUsing(function () use (&$summarizeCalled) {
                $summarizeCalled = true;
                return [['room' => 'Board Room', 'summary' => 'Re-generated.']];
            });

        $service = $this->makeService($this->roomOverviewSummary);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        $this->assertTrue($summarizeCalled, 'summarize() was not called for a room_overviews with a non-array entry');
    }

    // ── Test 5: Skip path logs the correct info message ───────────────────────

    public function test_skip_path_logs_skip_message(): void
    {
        Log::spy();

        $reviewedData = $this->makeBaseReviewedData($this->makePopulatedRoomOverviews());
        $record       = $this->makeRecordMock();

        $this->roomOverviewSummary->shouldNotReceive('summarize');

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/all room summaries populated, skipping summarize/'), Mockery::any())
            ->atLeast()->once();

        // Explicit PHPUnit assertion so the test is not marked risky.
        $this->addToAssertionCount(1);
    }

    // ── Test 6: Regenerate path logs the correct info message ─────────────────

    public function test_regenerate_path_logs_regenerate_message(): void
    {
        Log::spy();

        $roomOverviews = $this->makeEmptySummaryRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);
        $record        = $this->makeRecordMock();

        $this->roomOverviewSummary->shouldReceive('summarize')->andReturn($roomOverviews);

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/regenerating room summaries/'), Mockery::any())
            ->atLeast()->once();

        // Explicit PHPUnit assertion so the test is not marked risky.
        $this->addToAssertionCount(1);
    }

    // ── Test 7: reviewedToParsed includes scope_of_works and works_overview ───

    /**
     * reviewedToParsed() must include scope_of_works and works_overview (trimmed).
     */
    public function test_reviewed_to_parsed_includes_scope_and_works_overview(): void
    {
        $reviewedData                    = $this->makeBaseReviewedData();
        $reviewedData['scope_of_works']  = '  Supply and install AV systems.  ';
        $reviewedData['works_overview']  = '  A two-sentence overview.  ';
        $record                          = $this->makeRecordMock();

        $capturedParsed = null;
        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (array $parsed) use (&$capturedParsed) {
                $capturedParsed = $parsed;
                return ['phases' => []];
            });

        $service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($service, $reviewedData, $record);
        } catch (\Throwable) {}

        $this->assertNotNull($capturedParsed);
        $this->assertArrayHasKey('scope_of_works', $capturedParsed);
        $this->assertSame('Supply and install AV systems.', $capturedParsed['scope_of_works']);
        $this->assertArrayHasKey('works_overview', $capturedParsed);
        $this->assertSame('A two-sentence overview.', $capturedParsed['works_overview']);
    }
}

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
    private RamsDocument $record;
    private RamsBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roomOverviewSummary = Mockery::mock(RoomOverviewSummaryService::class);
        $this->record = $this->makeRecordMock();

        $this->service = $this->makeService($this->roomOverviewSummary);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    private function makeRecordMock(array $reviewedData = []): RamsDocument
    {
        $mock = Mockery::mock(RamsDocument::class);
        $mock->shouldReceive('getAttribute')->with('id')->andReturn(1)->byDefault();
        $mock->shouldReceive('getAttribute')->with('user_id')->andReturn(null)->byDefault();
        $mock->shouldReceive('getAttribute')->with('project_ref')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('project_name')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('client_name')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('site_address')->andReturn('')->byDefault();
        $mock->shouldReceive('getAttribute')->with('reviewed_data')->andReturn($reviewedData)->byDefault();
        $mock->shouldReceive('update')->andReturn(true)->byDefault();
        // Allow attribute setting (e.g. status)
        $mock->shouldReceive('setAttribute')->andReturn($mock)->byDefault();
        // Make id directly accessible
        $mock->id = 1;
        return $mock;
    }

    private function makeService(
        RoomOverviewSummaryService $roomOverviewSummary,
        ?MethodStatementGeneratorService $methodStatementMock = null
    ): RamsBuilderService {
        return new RamsBuilderService(
            Mockery::mock(QuoteParserService::class),
            Mockery::mock(EquipmentClassifierService::class),
            Mockery::mock(RiskTemplateResolverService::class),
            $methodStatementMock ?? Mockery::mock(MethodStatementGeneratorService::class),
            Mockery::mock(RamsDataBuilderService::class),
            Mockery::mock(RamsDocumentRendererService::class),
            Mockery::mock(HazardLibraryService::class),
            $roomOverviewSummary,
        );
    }

    private function invokeRunFromReview(array $reviewedData, array $formData = []): mixed
    {
        $method = new \ReflectionMethod(RamsBuilderService::class, 'runFromReview');
        $method->setAccessible(true);
        return $method->invoke($this->service, $reviewedData, $formData, $this->record);
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

    public function test_skip_summarize_when_all_summaries_populated(): void
    {
        $roomOverviews = $this->makePopulatedRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);

        // summarize() must NOT be called in the skip path.
        $this->roomOverviewSummary->shouldNotReceive('summarize');

        Log::spy();

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $this->service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {
            // Downstream mocks may throw — we only care about summarize() not being called.
        }

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/all room summaries populated, skipping summarize/'), Mockery::any())
            ->once();
    }

    // ── Test 2: Any summary empty — summarize() IS called ────────────────────

    public function test_calls_summarize_when_any_summary_is_empty(): void
    {
        $roomOverviews  = $this->makeEmptySummaryRoomOverviews();
        $reviewedData   = $this->makeBaseReviewedData($roomOverviews);
        $summarizedData = array_map(fn ($r) => array_merge($r, ['summary' => 'Generated summary.']), $roomOverviews);

        $this->roomOverviewSummary
            ->shouldReceive('summarize')
            ->once()
            ->with($roomOverviews)
            ->andReturn($summarizedData);

        Log::spy();

        // DB update for room_overviews should be called once (in the summarize path).
        $this->record->shouldReceive('update')
            ->with(Mockery::subset(['reviewed_data' => ['room_overviews' => $summarizedData]]))
            ->once()
            ->andReturn(true);

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $this->service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {
            // Downstream may throw — we only care about summarize() being called.
        }

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/regenerating room summaries/'), Mockery::any())
            ->once();
    }

    // ── Test 3: parsedQuote room_overviews and rooms set in skip path ─────────

    public function test_parsed_quote_room_overviews_set_in_skip_path(): void
    {
        $roomOverviews = $this->makePopulatedRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);

        $this->roomOverviewSummary->shouldNotReceive('summarize');

        $capturedParsed = null;
        $methodStatementMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodStatementMock
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (array $parsed) use (&$capturedParsed) {
                $capturedParsed = $parsed;
                return ['phases' => []];
            });

        $this->service = $this->makeService($this->roomOverviewSummary, $methodStatementMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {}

        $this->assertNotNull($capturedParsed, 'parsedQuote was not passed to generate()');
        $this->assertArrayHasKey('room_overviews', $capturedParsed);
        $this->assertCount(2, $capturedParsed['room_overviews']);
        $this->assertArrayHasKey('rooms', $capturedParsed);
        $this->assertSame(['Board Room', 'Training Room'], $capturedParsed['rooms']);
    }

    // ── Test 4: Non-array room entry treated as empty — triggers summarize() ──

    public function test_non_array_room_entry_triggers_summarize(): void
    {
        $roomOverviews = [
            ['room' => 'Board Room', 'summary' => 'Some summary.'],
            'not_an_array_entry',
        ];
        $reviewedData   = $this->makeBaseReviewedData($roomOverviews);
        $summarizedData = [['room' => 'Board Room', 'summary' => 'Re-generated summary.']];

        $this->roomOverviewSummary
            ->shouldReceive('summarize')
            ->once()
            ->andReturn($summarizedData);

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $this->service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {}
    }

    // ── Test 5: Log messages appear in both paths ─────────────────────────────

    public function test_skip_path_logs_skip_message(): void
    {
        Log::spy();

        $reviewedData = $this->makeBaseReviewedData($this->makePopulatedRoomOverviews());

        $this->roomOverviewSummary->shouldNotReceive('summarize');

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $this->service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {}

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/all room summaries populated, skipping summarize/'), Mockery::any())
            ->atLeast()->once();
    }

    public function test_regenerate_path_logs_regenerate_message(): void
    {
        Log::spy();

        $roomOverviews = $this->makeEmptySummaryRoomOverviews();
        $reviewedData  = $this->makeBaseReviewedData($roomOverviews);

        $this->roomOverviewSummary->shouldReceive('summarize')->andReturn($roomOverviews);

        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []]);
        $this->service = $this->makeService($this->roomOverviewSummary, $methodMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {}

        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/regenerating room summaries/'), Mockery::any())
            ->atLeast()->once();
    }

    // ── Test 6: reviewedToParsed includes scope_of_works and works_overview ───

    public function test_reviewed_to_parsed_includes_scope_and_works_overview(): void
    {
        $reviewedData = $this->makeBaseReviewedData();
        $reviewedData['scope_of_works'] = '  Supply and install AV systems.  ';
        $reviewedData['works_overview'] = '  A two-sentence overview.  ';

        $capturedParsed = null;
        $methodStatementMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodStatementMock
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (array $parsed) use (&$capturedParsed) {
                $capturedParsed = $parsed;
                return ['phases' => []];
            });

        $this->service = $this->makeService($this->roomOverviewSummary, $methodStatementMock);

        try {
            $this->invokeRunFromReview($reviewedData);
        } catch (\Throwable) {}

        $this->assertNotNull($capturedParsed);
        $this->assertArrayHasKey('scope_of_works', $capturedParsed);
        $this->assertSame('Supply and install AV systems.', $capturedParsed['scope_of_works']);
        $this->assertArrayHasKey('works_overview', $capturedParsed);
        $this->assertSame('A two-sentence overview.', $capturedParsed['works_overview']);
    }
}

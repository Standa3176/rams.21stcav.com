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

    /**
     * Phase 26 Plan 07: default RiskTemplateResolverService mock —
     * tieredRowsNotAlreadyPresent() returns no additional rows unless a
     * test overrides the expectation. Every runFromReview()/reviewedToRisk()
     * call now reaches this method, so a mock without it throws
     * BadMethodCallException.
     */
    private function makeDefaultRiskResolverMock(): RiskTemplateResolverService
    {
        $mock = Mockery::mock(RiskTemplateResolverService::class);
        $mock->shouldReceive('tieredRowsNotAlreadyPresent')->andReturn([])->byDefault();

        return $mock;
    }

    private function makeService(
        RoomOverviewSummaryService $roomOverviewSummary,
        ?MethodStatementGeneratorService $methodStatementMock = null,
        ?EquipmentClassifierService $classifierMock = null,
        ?RiskTemplateResolverService $riskResolverMock = null,
    ): RamsBuilderService {
        $methodMock = $methodStatementMock ?? Mockery::mock(MethodStatementGeneratorService::class);
        if ($methodStatementMock === null) {
            $methodMock->shouldReceive('generate')->andReturn(['phases' => []])->byDefault();
        }
        $classifier = $classifierMock ?? Mockery::mock(EquipmentClassifierService::class);
        if ($classifierMock === null) {
            $classifier->shouldReceive('textIndicatesDrilling')->andReturn(false)->byDefault();
        }
        return new RamsBuilderService(
            Mockery::mock(QuoteParserService::class),
            $classifier,
            $riskResolverMock ?? $this->makeDefaultRiskResolverMock(),
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

    /**
     * Phase 26 Plan 05 (HAZ-04): variant of makeService() that accepts a
     * caller-supplied HazardLibraryService mock, so reviewedToRisk() tests
     * can control what resolveFromSeeds() returns per hazard name.
     *
     * Phase 26 Plan 07: also accepts an optional RiskTemplateResolverService
     * mock override so a test can assert on tieredRowsNotAlreadyPresent()
     * call arguments; defaults to the zero-rows stub otherwise.
     */
    private function makeServiceWithHazardLibrary(
        HazardLibraryService $hazardLibrary,
        ?RiskTemplateResolverService $riskResolverMock = null,
    ): RamsBuilderService {
        $methodMock = Mockery::mock(MethodStatementGeneratorService::class);
        $methodMock->shouldReceive('generate')->andReturn(['phases' => []])->byDefault();

        $classifier = Mockery::mock(EquipmentClassifierService::class);
        $classifier->shouldReceive('textIndicatesDrilling')->andReturn(false)->byDefault();

        return new RamsBuilderService(
            Mockery::mock(QuoteParserService::class),
            $classifier,
            $riskResolverMock ?? $this->makeDefaultRiskResolverMock(),
            $methodMock,
            Mockery::mock(RamsDataBuilderService::class),
            Mockery::mock(RamsDocumentRendererService::class),
            $hazardLibrary,
            $this->roomOverviewSummary,
            new Tier1RamsDefaultsService(),
        );
    }

    private function invokeReviewedToRisk(
        RamsBuilderService $service,
        array $rd,
        ?int $userId = null,
        array $activities = [],
        string $scopeNarrative = '',
        bool $drillingRequired = false,
    ): array {
        $method = new \ReflectionMethod(RamsBuilderService::class, 'reviewedToRisk');
        $method->setAccessible(true);
        return $method->invoke($service, $rd, $userId, $activities, $scopeNarrative, $drillingRequired);
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

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 26 Plan 05 (HAZ-04): reviewedToRisk() honours engineer-entered
    // scores and carries score_reviewed/needs_confirmation into generated_data
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A row's own numeric pre_likelihood/pre_severity win over a library
     * re-lookup ONLY when score_reviewed is explicitly true — Plan 26-08's
     * gate. Without that marker, the SAME fixture (library match, differing
     * numbers) must instead resolve to the library's own scores (see the
     * companion test below).
     */
    public function test_reviewedToRisk_prefers_row_numeric_scores_over_library_match(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Working at Height'])
            ->andReturn(collect([(object) [
                'id'              => 1,
                'name'            => 'Working at Height',
                'controls'        => ['Library control'],
                'pre_likelihood'  => 4,
                'pre_severity'    => 5,
                'post_likelihood' => 3,
                'post_severity'   => 4,
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard'           => 'Working at Height',
            'pre_likelihood'   => 3,
            'pre_severity'     => 4,
            'control_measures' => ['Engineer-entered control'],
            'score_reviewed'   => true,
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertSame(3, $out['hazards'][0]['pre_likelihood'], 'row value wins over library match when score_reviewed is true');
        $this->assertSame(4, $out['hazards'][0]['pre_severity'], 'row value wins over library match when score_reviewed is true');
    }

    /**
     * Plan 26-08 (HAZ-03 gap closure, round 2): the SAME fixture as above
     * (a real library match with differing numbers) but with score_reviewed
     * entirely ABSENT from the row (not set to false) — proves "absent ==
     * false" resolves to the library's own scores, not the row's stale
     * numbers. This is the named HAZ-03 mechanism proof: a reviewed row
     * whose score no human ever touched through the numeric UI must not
     * win over the library default.
     */
    public function test_reviewedToRisk_score_reviewed_false_or_absent_defers_to_library_scores(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Working at Height'])
            ->andReturn(collect([(object) [
                'id'              => 1,
                'name'            => 'Working at Height',
                'controls'        => ['Library control'],
                'pre_likelihood'  => 4,
                'pre_severity'    => 5,
                'post_likelihood' => 3,
                'post_severity'   => 4,
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard'           => 'Working at Height',
            'pre_likelihood'   => 3,
            'pre_severity'     => 4,
            'control_measures' => ['Engineer-entered control'],
            // score_reviewed key entirely absent — not merely false.
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertSame(4, $out['hazards'][0]['pre_likelihood'], 'library value wins when score_reviewed is absent');
        $this->assertSame(5, $out['hazards'][0]['pre_severity'], 'library value wins when score_reviewed is absent');
    }

    /**
     * Plan 26-08 (HAZ-02 gap closure, round 2): a legacy-named row that
     * resolves to a genuinely different canonical name (rename, not a
     * case-only casing fix) carries the MATCHED TEMPLATE's exact name and
     * its controls — unconditionally, independent of score_reviewed.
     */
    public function test_reviewedToRisk_renamed_row_carries_matched_template_name_and_controls(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Confined Spaces'])
            ->andReturn(collect([(object) [
                'id'              => 7,
                'name'            => 'Restricted access and ceiling voids',
                'controls'        => ['These are not classified as confined spaces.'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard'           => 'Confined Spaces',
            'control_measures' => [],
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertSame('Restricted access and ceiling voids', $out['hazards'][0]['hazard']);
        $this->assertSame(['These are not classified as confined spaces.'], $out['hazards'][0]['controls']);
    }

    /**
     * Plan 26-08: a case-only/no-op rename (matched template's name differs
     * only in casing) still displays under the template's exact casing, but
     * a NON-empty row control list is preserved unchanged — controls are
     * gap-filled-only, not replaced, when the match is not a genuine rename.
     */
    public function test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Working at Height'])
            ->andReturn(collect([(object) [
                'id'              => 1,
                'name'            => 'Working at height',
                'controls'        => ['Library control — should not be used'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 4,
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard'           => 'Working at Height',
            'control_measures' => ['Engineer-entered control — must survive'],
            'score_reviewed'   => true,
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertSame('Working at height', $out['hazards'][0]['hazard'], 'case-only match renames for display casing');
        $this->assertSame(['Engineer-entered control — must survive'], $out['hazards'][0]['controls'], 'controls unchanged on a case-only/no-op rename');
    }

    /**
     * Plan 26-08: a reviewed pick that folds onto a confirm-tier hazard is
     * escalated to needs_confirmation=true regardless of the source row
     * never having set it.
     */
    public function test_reviewedToRisk_folded_confirm_tier_row_is_escalated_to_needs_confirmation(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Working in Occupied Premises'])
            ->andReturn(collect([(object) [
                'id'              => 9,
                'name'            => 'Occupied premises',
                'controls'        => ['Coordinate work windows to minimise disruption.'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'include_when'    => 'confirm:occupied_premises',
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard' => 'Working in Occupied Premises',
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertTrue($out['hazards'][0]['needs_confirmation'], 'a folded confirm-tier pick must be escalated to needs_confirmation=true');
    }

    /**
     * Plan 26-08 (HAZ-02 gap closure, round 2): two rows in the same batch
     * that both resolve to the SAME canonical template collapse into one
     * row — the same-batch dedup pass that runs before the tiered merge.
     */
    public function test_reviewedToRisk_same_batch_collision_collapses_to_one_row(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Confined Spaces'])
            ->andReturn(collect([(object) [
                'id'              => 7,
                'name'            => 'Restricted access and ceiling voids',
                'controls'        => ['Library control A'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ]]));
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Cable Installation in Ceiling Voids'])
            ->andReturn(collect([(object) [
                'id'              => 7,
                'name'            => 'Restricted access and ceiling voids',
                'controls'        => ['Library control A'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ]]));

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [
            ['hazard' => 'Confined Spaces'],
            ['hazard' => 'Cable Installation in Ceiling Voids'],
        ]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertCount(1, $out['hazards'], 'two same-batch rows folding onto the same canonical target collapse to one row');
        $this->assertSame('Restricted access and ceiling voids', $out['hazards'][0]['hazard']);
    }

    /**
     * score_reviewed on the reviewed_data row survives into the generated
     * hazard row's own score_reviewed key.
     */
    public function test_reviewedToRisk_carries_score_reviewed_marker_to_output(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')->andReturn(collect());

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard'             => 'Manual Handling',
            'pre_likelihood'     => 3,
            'pre_severity'       => 3,
            'score_reviewed'     => true,
            'needs_confirmation' => true,
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertTrue($out['hazards'][0]['score_reviewed']);
        $this->assertTrue($out['hazards'][0]['needs_confirmation']);
    }

    /**
     * A legacy row with no numeric fields at all still resolves via the
     * existing library-lookup-then-risk-string fallback chain, unchanged.
     * score_reviewed/needs_confirmation default to false in the output.
     */
    public function test_reviewedToRisk_legacy_row_without_numeric_fields_falls_back_unchanged(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Electrical Isolation'])
            ->andReturn(collect());

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary);

        $rd = ['hazards' => [[
            'hazard' => 'Electrical Isolation',
            'risk'   => 'High',
        ]]];

        $out = $this->invokeReviewedToRisk($service, $rd);

        $this->assertSame(4, $out['hazards'][0]['pre_likelihood']);
        $this->assertSame(4, $out['hazards'][0]['pre_severity']);
        $this->assertSame(3, $out['hazards'][0]['post_likelihood']);
        $this->assertSame(3, $out['hazards'][0]['post_severity']);
        $this->assertFalse($out['hazards'][0]['score_reviewed']);
        $this->assertFalse($out['hazards'][0]['needs_confirmation']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 26 Plan 07 (HAZ-02 gap closure) — reviewedToRisk() merges tier-1/3
    // candidates from RiskTemplateResolverService::tieredRowsNotAlreadyPresent()
    // onto the reviewed picks, forwarding the REAL derived drilling signal.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_reviewedToRisk_merges_tiered_rows_and_resequences_ids(): void
    {
        $hazardLibrary = Mockery::mock(HazardLibraryService::class);
        $hazardLibrary->shouldReceive('resolveFromSeeds')
            ->with(0, ['Working at Height'])
            ->andReturn(collect());

        $riskResolver = Mockery::mock(RiskTemplateResolverService::class);
        $riskResolver->shouldReceive('tieredRowsNotAlreadyPresent')
            ->once()
            ->with(
                ['Working at Height'],
                ['ceiling_works'],
                true,
                'drill fixing brackets to the ceiling',
                0,
            )
            ->andReturn([
                [
                    'hazard'             => 'Low voltage AV connections',
                    'persons_at_risk'    => ['21CAV Staff', 'Client Staff', 'Others'],
                    'pre_likelihood'     => 3,
                    'pre_severity'       => 3,
                    'controls'           => ['Isolate before connecting'],
                    'post_likelihood'    => 1,
                    'post_severity'      => 2,
                    'score_reviewed'     => false,
                    'needs_confirmation' => false,
                ],
            ]);

        $service = $this->makeServiceWithHazardLibrary($hazardLibrary, $riskResolver);

        $rd = ['hazards' => [[
            'hazard'           => 'Working at Height',
            'pre_likelihood'   => 3,
            'pre_severity'     => 4,
            'control_measures' => ['Engineer-entered control'],
        ]]];

        $out = $this->invokeReviewedToRisk(
            $service,
            $rd,
            0,
            ['ceiling_works'],
            'drill fixing brackets to the ceiling',
            true,
        );

        $this->assertCount(2, $out['hazards'], 'reviewed pick plus the one tiered row returned by the mock');
        $this->assertSame('Working at Height', $out['hazards'][0]['hazard']);
        $this->assertSame(1, $out['hazards'][0]['id']);
        $this->assertSame('Low voltage AV connections', $out['hazards'][1]['hazard']);
        $this->assertSame(2, $out['hazards'][1]['id'], 'ids are re-sequenced 1..N across the FULL merged array');
    }
}

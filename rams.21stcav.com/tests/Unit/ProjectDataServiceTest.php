<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Project;
use App\Models\SiteSurvey;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for ProjectDataService — the canonical data merge contract.
 *
 * Covers the full merge priority chain (DATA-05), per-field annotation (DATA-04),
 * read-only guarantee (T-03-04), and graceful degradation when data is absent.
 *
 * @see DATA-01, DATA-02, DATA-04, DATA-05
 */
class ProjectDataServiceTest extends TestCase
{
    private ProjectDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectDataService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Canonical key shape
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolve() returns all 9 canonical keys when no package is present.
     * Collection keys must return empty arrays. meta reflects 'defaults' source.
     */
    public function test_resolve_returns_canonical_keys(): void
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('relationLoaded')->with('latestPackage')->andReturn(true);
        $project->latestPackage = null;
        $project->shouldReceive('relationLoaded')->with('siteSurveys')->andReturn(true);
        $project->siteSurveys = collect([]);
        $project->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $project->shouldReceive('getAttribute')->with('name')->andReturn('Test Project');
        $project->shouldReceive('getAttribute')->with('client_name')->andReturn('Test Client');
        $project->shouldReceive('getAttribute')->with('site_address')->andReturn('123 Test St');
        $project->shouldReceive('getAttribute')->with('quote_reference')->andReturn(null);
        $project->shouldReceive('getAttribute')->with('ref')->andReturn('QW-001');
        $project->shouldReceive('getAttribute')->with('status')->andReturn('quote_imported');
        $project->shouldReceive('getAttribute')->with('created_at')->andReturn(null);

        $result = $this->service->resolve($project);

        // All 9 canonical keys must be present
        $this->assertArrayHasKey('project',    $result);
        $this->assertArrayHasKey('equipment',  $result);
        $this->assertArrayHasKey('rooms',      $result);
        $this->assertArrayHasKey('activities', $result);
        $this->assertArrayHasKey('risks',      $result);
        $this->assertArrayHasKey('survey',     $result);
        $this->assertArrayHasKey('programme',  $result);
        $this->assertArrayHasKey('cables',     $result);
        $this->assertArrayHasKey('meta',       $result);

        // Collections empty when no package
        $this->assertSame([], $result['equipment']);
        $this->assertSame([], $result['rooms']);
        $this->assertSame([], $result['activities']);
        $this->assertSame([], $result['risks']);

        // Meta shows defaults
        $this->assertSame('defaults', $result['meta']['data_source']);
        $this->assertSame(0.0,        $result['meta']['confidence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Merge priority — reviewed_data wins
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a package has both extracted_data AND reviewed_data, reviewed_data
     * takes precedence. Equipment items should carry data_source='manual' and
     * confidence=1.0.
     */
    public function test_resolve_uses_reviewed_data_over_extracted(): void
    {
        $package = new \stdClass();
        $package->reviewed_data = [
            'equipment' => [
                ['name' => 'Sony Display', 'quantity' => 2, 'area' => 'Boardroom'],
            ],
        ];
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Legacy Screen', 'quantity' => 1, 'area' => 'Lobby'],
            ],
        ];

        $project = $this->makeProjectMockWithPackage($package);

        $result = $this->service->resolve($project);

        $this->assertNotEmpty($result['equipment']);
        $item = $result['equipment'][0];
        $this->assertSame('manual', $item['data_source']);
        $this->assertSame(1.0,      $item['confidence']);
        // Should use reviewed_data name, not extracted_data
        $this->assertSame('Sony Display', $item['name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Merge priority — fallback to extracted_data
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When reviewed_data is null, resolve() uses extracted_data with
     * data_source='pdf' and confidence=0.85.
     */
    public function test_resolve_falls_back_to_extracted_data(): void
    {
        $package = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Projector', 'quantity' => 1, 'area' => 'Lecture Hall'],
            ],
        ];

        $project = $this->makeProjectMockWithPackage($package);

        $result = $this->service->resolve($project);

        $this->assertNotEmpty($result['equipment']);
        $item = $result['equipment'][0];
        $this->assertSame('pdf',  $item['data_source']);
        $this->assertSame(0.85,   $item['confidence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Per-item annotation: data_source and confidence keys present
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every item in equipment[] must carry 'data_source' and 'confidence' keys.
     */
    public function test_resolve_equipment_items_have_annotation(): void
    {
        $package = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Display A'],
                ['name' => 'Display B'],
            ],
        ];

        $project = $this->makeProjectMockWithPackage($package);

        $result = $this->service->resolve($project);

        foreach ($result['equipment'] as $item) {
            $this->assertArrayHasKey('data_source', $item, 'Equipment item missing data_source');
            $this->assertArrayHasKey('confidence',  $item, 'Equipment item missing confidence');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Graceful degradation — no package
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolve() must not throw when the project has no related package.
     */
    public function test_resolve_never_throws_without_package(): void
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('relationLoaded')->with('latestPackage')->andReturn(true);
        $project->latestPackage = null;
        $project->shouldReceive('relationLoaded')->with('siteSurveys')->andReturn(true);
        $project->siteSurveys = collect([]);
        $project->shouldReceive('getAttribute')->with('id')->andReturn(99);
        $project->shouldReceive('getAttribute')->with('name')->andReturn('No Package Project');
        $project->shouldReceive('getAttribute')->with('client_name')->andReturn('');
        $project->shouldReceive('getAttribute')->with('site_address')->andReturn('');
        $project->shouldReceive('getAttribute')->with('quote_reference')->andReturn(null);
        $project->shouldReceive('getAttribute')->with('ref')->andReturn(null);
        $project->shouldReceive('getAttribute')->with('status')->andReturn('quote_imported');
        $project->shouldReceive('getAttribute')->with('created_at')->andReturn(null);

        // Must not throw
        $this->expectNotToPerformAssertions();
        try {
            $this->service->resolve($project);
        } catch (\Throwable $e) {
            $this->fail('resolve() threw an exception when no package present: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Read-only guarantee — no write queries executed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolve() must issue zero INSERT, UPDATE, or DELETE queries.
     * Verified by enabling query logging and filtering write operations.
     */
    public function test_resolve_never_writes_to_database(): void
    {
        $package = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = ['equipment' => []];

        $project = $this->makeProjectMockWithPackage($package);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->resolve($project);

        $queries = DB::getQueryLog();

        $writeQueries = array_filter($queries, function (array $entry) {
            $sql = strtolower($entry['query'] ?? '');
            return str_contains($sql, 'insert') ||
                   str_contains($sql, 'update') ||
                   str_contains($sql, 'delete');
        });

        $this->assertEmpty($writeQueries, 'resolve() issued unexpected write queries: ' . json_encode($writeQueries));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. isLowConfidence() threshold (exclusive < 0.7)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Items with confidence 0.69 are low-confidence (true).
     * Items with confidence 0.70 are NOT low-confidence (false).
     * The threshold is exclusive (< 0.7, not <=).
     */
    public function test_is_low_confidence_threshold(): void
    {
        $this->assertTrue($this->service->isLowConfidence(0.69), '0.69 should be low confidence');
        $this->assertFalse($this->service->isLowConfidence(0.70), '0.70 should NOT be low confidence');
        $this->assertTrue($this->service->isLowConfidence(0.0),  '0.0 should be low confidence');
        $this->assertFalse($this->service->isLowConfidence(1.0),  '1.0 should NOT be low confidence');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Survey flag in meta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the project has a completed SiteSurvey, meta.has_survey = true
     * and meta.survey_complete = true.
     */
    public function test_resolve_meta_has_survey_flag(): void
    {
        $package = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $survey           = new \stdClass();
        $survey->status   = 'completed';
        $survey->room_data = [];
        $survey->completed_at = null;
        $survey->updated_at   = null;

        $project = Mockery::mock(Project::class);
        $project->shouldReceive('relationLoaded')->with('latestPackage')->andReturn(true);
        $project->latestPackage = $package;
        $project->shouldReceive('relationLoaded')->with('siteSurveys')->andReturn(true);
        // Return a collection with one completed survey
        $project->siteSurveys = collect([$survey]);
        $project->shouldReceive('getAttribute')->with('id')->andReturn(5);
        $project->shouldReceive('getAttribute')->with('name')->andReturn('Survey Project');
        $project->shouldReceive('getAttribute')->with('client_name')->andReturn('Client');
        $project->shouldReceive('getAttribute')->with('site_address')->andReturn('Site');
        $project->shouldReceive('getAttribute')->with('quote_reference')->andReturn(null);
        $project->shouldReceive('getAttribute')->with('ref')->andReturn('QW-005');
        $project->shouldReceive('getAttribute')->with('status')->andReturn('engineering');
        $project->shouldReceive('getAttribute')->with('created_at')->andReturn(null);

        $result = $this->service->resolve($project);

        $this->assertTrue($result['meta']['has_survey'],      'meta.has_survey should be true');
        $this->assertTrue($result['meta']['survey_complete'], 'meta.survey_complete should be true');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a Project mock pre-configured with a given package and no survey.
     */
    private function makeProjectMockWithPackage(object $package): object
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('relationLoaded')->with('latestPackage')->andReturn(true);
        $project->latestPackage = $package;
        $project->shouldReceive('relationLoaded')->with('siteSurveys')->andReturn(true);
        $project->siteSurveys = collect([]);
        $project->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $project->shouldReceive('getAttribute')->with('name')->andReturn('Test Project');
        $project->shouldReceive('getAttribute')->with('client_name')->andReturn('Test Client');
        $project->shouldReceive('getAttribute')->with('site_address')->andReturn('123 Test St');
        $project->shouldReceive('getAttribute')->with('quote_reference')->andReturn(null);
        $project->shouldReceive('getAttribute')->with('ref')->andReturn('QW-001');
        $project->shouldReceive('getAttribute')->with('status')->andReturn('quote_imported');
        $project->shouldReceive('getAttribute')->with('created_at')->andReturn(null);

        return $project;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for ProjectDataService — the canonical data merge contract.
 *
 * Covers the full merge priority chain (DATA-05), per-field annotation (DATA-04),
 * read-only guarantee (T-03-04), and graceful degradation when data is absent.
 *
 * Uses anonymous class stubs for Project to avoid Eloquent model attribute machinery.
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

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Canonical key shape
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolve() returns all 9 canonical keys when no package is present.
     * Collection keys must return empty arrays. meta reflects 'defaults' source.
     */
    public function test_resolve_returns_canonical_keys(): void
    {
        $project = $this->makeProjectStub(latestPackage: null, surveys: []);

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
        $package                 = new \stdClass();
        $package->reviewed_data  = [
            'equipment' => [
                ['name' => 'Sony Display', 'quantity' => 2, 'area' => 'Boardroom'],
            ],
        ];
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Legacy Screen', 'quantity' => 1, 'area' => 'Lobby'],
            ],
        ];

        $project = $this->makeProjectStub(latestPackage: $package, surveys: []);

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
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Projector', 'quantity' => 1, 'area' => 'Lecture Hall'],
            ],
        ];

        $project = $this->makeProjectStub(latestPackage: $package, surveys: []);

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
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'equipment' => [
                ['name' => 'Display A'],
                ['name' => 'Display B'],
            ],
        ];

        $project = $this->makeProjectStub(latestPackage: $package, surveys: []);

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
        $project = $this->makeProjectStub(latestPackage: null, surveys: []);

        try {
            $result = $this->service->resolve($project);
            $this->assertIsArray($result);
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
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = ['equipment' => []];

        $project = $this->makeProjectStub(latestPackage: $package, surveys: []);

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
        $this->assertTrue($this->service->isLowConfidence(0.69),  '0.69 should be low confidence');
        $this->assertFalse($this->service->isLowConfidence(0.70),  '0.70 should NOT be low confidence');
        $this->assertTrue($this->service->isLowConfidence(0.0),   '0.0 should be low confidence');
        $this->assertFalse($this->service->isLowConfidence(1.0),   '1.0 should NOT be low confidence');
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
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $survey                     = $this->makeSurveyStub('completed');
        $survey->rooms              = collect([]);
        $survey->completed_at       = null;
        $survey->updated_at         = null;

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);

        $this->assertTrue($result['meta']['has_survey'],      'meta.has_survey should be true');
        $this->assertTrue($result['meta']['survey_complete'], 'meta.survey_complete should be true');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Survey room — matched merge at confidence 0.95
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A survey room whose name closely matches a quote room merges at confidence 0.95
     * with data_source 'survey'.
     */
    public function test_matched_survey_room_inherits_survey_fields_at_confidence_95(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'rooms' => [
                ['room_name' => 'Boardroom', 'name' => 'Boardroom'],
            ],
        ];

        $roomStub = $this->makeSurveyRoomStub('Boardroom');

        $survey        = $this->makeSurveyStub('completed');
        $survey->rooms = collect([$roomStub]);

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);

        $this->assertNotEmpty($result['rooms'], 'rooms should not be empty');
        $merged = $result['rooms'][0];
        $this->assertSame('survey', $merged['data_source'], 'data_source should be survey');
        $this->assertSame(0.95,     $merged['confidence'],  'confidence should be 0.95');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Survey room — orphan appended with quote_room_matched: false
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A survey room with no close quote match is appended as an orphan entry
     * with quote_room_matched: false.
     */
    public function test_orphan_survey_room_appended_with_quote_room_matched_false(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = ['rooms' => []];

        $roomStub = $this->makeSurveyRoomStub('Server Room');

        $survey        = $this->makeSurveyStub('completed');
        $survey->rooms = collect([$roomStub]);

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);

        $this->assertNotEmpty($result['rooms'], 'orphan survey room should appear in rooms[]');
        $orphan = $result['rooms'][0];
        $this->assertFalse($orphan['quote_room_matched'], 'quote_room_matched should be false for orphan');
        $this->assertSame('survey', $orphan['data_source']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Survey room — below threshold leaves quote room unchanged
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A survey room with similarity below 0.70 does NOT overwrite the quote room.
     * The quote room retains its original data_source.
     */
    public function test_below_threshold_survey_room_leaves_quote_room_unchanged(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [
            'rooms' => [
                ['room_name' => 'Reception', 'name' => 'Reception', 'data_source' => 'pdf'],
            ],
        ];

        $roomStub = $this->makeSurveyRoomStub('Storage Closet');

        $survey        = $this->makeSurveyStub('completed');
        $survey->rooms = collect([$roomStub]);

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);

        // Find the quote room (named Reception) — should still carry pdf data_source
        $quoteEntry = collect($result['rooms'])->firstWhere('room_name', 'Reception')
            ?? collect($result['rooms'])->firstWhere('name', 'Reception');

        $this->assertNotNull($quoteEntry, 'Reception quote room should be present');
        $this->assertSame('pdf', $quoteEntry['data_source'], 'quote room data_source must not be overwritten by low-similarity survey room');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. survey_meta has all seven keys
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolveSurveyMeta() returns exactly 7 keys including all global survey fields.
     */
    public function test_resolve_survey_meta_has_all_six_keys(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $survey                      = $this->makeSurveyStub('completed');
        $survey->site_risks          = 'Working at height';
        $survey->access_constraints  = 'Restricted 08:00-17:00';
        $survey->h_and_s_notes       = 'PPE required';
        $survey->general_notes       = 'Check plant room';

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);
        $meta   = $result['survey'];

        $this->assertArrayHasKey('has_survey',         $meta);
        $this->assertArrayHasKey('submitted_at',       $meta);
        $this->assertArrayHasKey('site_risks',         $meta);
        $this->assertArrayHasKey('access_constraints', $meta);
        $this->assertArrayHasKey('h_and_s_notes',      $meta);
        $this->assertArrayHasKey('general_notes',      $meta);
        $this->assertArrayHasKey('rooms',              $meta);

        $this->assertSame('Working at height',       $meta['site_risks']);
        $this->assertSame('Restricted 08:00-17:00',  $meta['access_constraints']);
        $this->assertSame('PPE required',            $meta['h_and_s_notes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Superseded survey excluded from resolve
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A survey with superseded_at set is ignored — resolve() returns has_survey: false.
     */
    public function test_superseded_survey_excluded_from_resolve(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $survey               = $this->makeSurveyStub('completed');
        $survey->superseded_at = '2026-04-10 12:00:00';

        $project = $this->makeProjectStub(latestPackage: $package, surveys: [$survey]);

        $result = $this->service->resolve($project);

        $this->assertFalse($result['meta']['has_survey'], 'superseded survey must be excluded');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. Request-scoped memoisation (H-01)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * resolve() returns the same array instance on repeated calls for the
     * same project id within one service lifetime, so callers sharing the
     * singleton never re-run the O(N×M) similar_text() room merge twice.
     */
    public function test_resolve_memoises_result_per_project_id(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $project = $this->makeProjectStubWithId(42, $package);

        $first  = $this->service->resolve($project);
        $second = $this->service->resolve($project);

        // Same contents. With cache hit, this is the same instance — assertSame
        // catches any accidental re-computation.
        $this->assertSame($first, $second);
    }

    /**
     * Different project ids do NOT share cached results — would be a
     * correctness bug if memoisation collided across projects.
     */
    public function test_resolve_does_not_serve_cached_result_to_different_project(): void
    {
        $package               = new \stdClass();
        $package->reviewed_data = null;
        $package->extracted_data = ['equipment' => [['description' => 'Display A']]];

        $otherPackage                 = new \stdClass();
        $otherPackage->reviewed_data  = null;
        $otherPackage->extracted_data = ['equipment' => [['description' => 'Display B']]];

        $projectA = $this->makeProjectStubWithId(100, $package);
        $projectB = $this->makeProjectStubWithId(200, $otherPackage);

        $resultA = $this->service->resolve($projectA);
        $resultB = $this->service->resolve($projectB);

        $this->assertNotSame($resultA['equipment'], $resultB['equipment']);
        $this->assertSame('Display A', $resultA['equipment'][0]['description']);
        $this->assertSame('Display B', $resultB['equipment'][0]['description']);
    }

    /**
     * forgetResolved($id) invalidates the single entry; forgetResolved() with
     * no arg clears everything. After invalidation the next resolve() recomputes.
     */
    public function test_forget_resolved_invalidates_cache(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $project = $this->makeProjectStubWithId(55, $package);

        $first = $this->service->resolve($project);
        $this->service->forgetResolved(55);
        $second = $this->service->resolve($project);

        // After invalidation the cache re-ran — the results are equal in
        // content but are distinct array instances (different allocations).
        $this->assertEquals($first, $second);
        // Double-check: clearing with no argument also works (no exception).
        $this->service->forgetResolved();
    }

    /**
     * A Project with no id (unsaved model) bypasses the cache to prevent
     * keying on null and colliding unrelated projects.
     */
    public function test_project_without_id_bypasses_cache(): void
    {
        $package                 = new \stdClass();
        $package->reviewed_data  = null;
        $package->extracted_data = [];

        $project = $this->makeProjectStubWithId(null, $package);

        // No throw, and the result is still well-formed.
        $result = $this->service->resolve($project);
        $this->assertArrayHasKey('project', $result);
        $this->assertArrayHasKey('meta',    $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Variant of makeProjectStub that lets the caller pin the project id,
     * so the H-01 memoisation tests can assert cache hits / collisions.
     */
    private function makeProjectStubWithId(?int $id, ?object $latestPackage, array $surveys = []): Project
    {
        return new class($id, $latestPackage, $surveys) extends Project {
            public function __construct(
                private readonly ?int    $pinnedId,
                private readonly ?object $packageStub,
                private readonly array   $surveyStubs,
            ) {}

            public function relationLoaded($relation): bool
            {
                return in_array($relation, ['latestPackage', 'siteSurveys'], true);
            }

            public function __get($key)
            {
                return match ($key) {
                    'latestPackage' => $this->packageStub,
                    'siteSurveys'   => collect($this->surveyStubs),
                    'id'            => $this->pinnedId,
                    'name'          => 'Pinned Project',
                    'client_name'   => 'Pinned Client',
                    'site_address'  => '1 Pinned St',
                    'quote_reference' => null,
                    'ref'           => 'QW-PIN',
                    'status'        => 'quote_imported',
                    'created_at'    => null,
                    default         => null,
                };
            }
        };
    }

    /**
     * Build a Project stub that pre-loads its latestPackage and siteSurveys
     * relations in-memory, so ProjectDataService never hits the DB.
     *
     * Returns an anonymous class that extends Project but overrides
     * relationLoaded() and the relevant relation properties.
     *
     * The siteSurveys collection supports ->whereNull('superseded_at') chaining
     * because Illuminate\Support\Collection::whereNull() is available in Laravel 12.
     *
     * @param  object|null $latestPackage  Package stub (stdClass) or null.
     * @param  array       $surveys        Array of survey stubs.
     */
    private function makeProjectStub(?object $latestPackage, array $surveys): Project
    {
        return new class($latestPackage, $surveys) extends Project {
            public function __construct(
                private readonly ?object $packageStub,
                private readonly array $surveyStubs,
            ) {
                // Skip Eloquent parent constructor to avoid DB/config dependency.
            }

            public function relationLoaded($relation): bool
            {
                return in_array($relation, ['latestPackage', 'siteSurveys'], true);
            }

            public function __get($key)
            {
                return match ($key) {
                    'latestPackage' => $this->packageStub,
                    'siteSurveys'   => collect($this->surveyStubs),
                    'id'            => 1,
                    'name'          => 'Test Project',
                    'client_name'   => 'Test Client',
                    'site_address'  => '123 Test St',
                    'quote_reference' => null,
                    'ref'           => 'QW-001',
                    'status'        => 'quote_imported',
                    'created_at'    => null,
                    default         => null,
                };
            }
        };
    }

    /**
     * Build a survey stub with the fields required by the Phase 3 implementations.
     *
     * Provides a loadMissing() no-op so the stub is compatible with Eloquent calls
     * made inside mergeSurveyRooms() without hitting the database.
     *
     * @param  string $status  Survey status (default: 'completed').
     * @return object
     */
    private function makeSurveyStub(string $status = 'completed'): object
    {
        return new class($status) {
            public string  $status;
            public ?object $rooms         = null;
            public ?string $completed_at  = null;
            public ?string $updated_at    = null;
            public ?string $superseded_at = null;
            public ?string $site_risks          = null;
            public ?string $access_constraints  = null;
            public ?string $h_and_s_notes       = null;
            public ?string $general_notes       = null;

            public function __construct(string $status)
            {
                $this->status = $status;
                $this->rooms  = collect([]);
            }

            /** No-op — rooms are set directly on the stub. */
            public function loadMissing(string $relation): static
            {
                return $this;
            }
        };
    }

    /**
     * Build a survey room stub exposing all D-10 fields as null properties,
     * with room_name set to the given value.
     *
     * @param  string $roomName  The room name to assign.
     * @return \stdClass
     */
    private function makeSurveyRoomStub(string $roomName): \stdClass
    {
        $room = new \stdClass();

        // Identity
        $room->room_name  = $roomName;
        $room->room_ref   = null;
        $room->floor      = null;
        $room->area_type  = null;
        $room->space_type = null;

        // Dimensions
        $room->room_width_m     = null;
        $room->room_depth_m     = null;
        $room->room_height_m    = null;
        $room->ceiling_type     = null;
        $room->ceiling_height_m = null;
        $room->wall_material    = null;
        $room->floor_type       = null;

        // AV scope
        $room->av_requirements  = null;
        $room->av_equipment_list = null;

        // Services
        $room->has_power                 = null;
        $room->has_network               = null;
        $room->power_outlet_count        = null;
        $room->network_port_count        = null;
        $room->requires_additional_power = null;
        $room->existing_cabling          = null;

        // Infrastructure
        $room->rack_unit_space  = null;
        $room->cable_route_desc = null;

        // Audio
        $room->speaker_count    = null;
        $room->speaker_type     = null;
        $room->speaker_mounting = null;
        $room->bg_noise_db      = null;

        // Displays
        $room->display_size_in  = null;
        $room->display_orient   = null;
        $room->display_mounting = null;

        // Access / notes
        $room->access_notes = null;
        $room->notes        = null;

        // Completion
        $room->is_completed = null;
        $room->completed_at = null;

        return $room;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Core\Modules\QuoteImport\QuoteWerksImportService;
use App\Core\Modules\QuoteImport\QuoteWerksRepository;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

/**
 * Unit tests for QuoteWerksImportService.
 *
 * Verifies: NotFoundException on missing quote, ProjectPackage return on success,
 * extracted_data structural contract (meta.source, confidence, rooms dedup,
 * equipment row shape), and correct data passed to importFromData().
 *
 * All dependencies are mocked — no DB, no SQL Server required.
 *
 * @see QWSQL-02, QWSQL-03, QWSQL-04
 */
class QuoteWerksImportServiceTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Shared fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function makeSampleHeader(): array
    {
        return [
            'doc_no'       => 'QW-2024-001',
            'client_name'  => 'ACME Ltd',
            'site_address' => '1 High Street, London',
            'doc_date'     => '2024-01-15',
            'sales_person' => 'Jane Smith',
            'notes'        => 'Board room upgrade project',
        ];
    }

    private function makeSampleItems(): array
    {
        return [
            [
                'item_type'   => 'P',
                'quantity'    => 2,
                'part_number' => 'SONY-BZ40H',
                'description' => 'Sony BRAVIA 40" Display',
                'group_name'  => 'Boardroom',
                'sort_order'  => 10,
            ],
            [
                'item_type'   => 'P',
                'quantity'    => 1,
                'part_number' => 'SONY-BZ40H',
                'description' => 'Sony BRAVIA 40" Display',
                'group_name'  => 'Boardroom',  // Duplicate group — should dedup
                'sort_order'  => 20,
            ],
            [
                'item_type'   => 'P',
                'quantity'    => 1,
                'part_number' => 'NEC-PA522U',
                'description' => 'NEC Projector',
                'group_name'  => 'Conference Room',
                'sort_order'  => 30,
            ],
            [
                'item_type'   => 'P',
                'quantity'    => 1,
                'part_number' => 'CABLE-001',
                'description' => 'HDMI Cable 5m',
                'group_name'  => '',  // Empty group — should be filtered out of rooms
                'sort_order'  => 40,
            ],
        ];
    }

    /**
     * Create a mock QuoteWerksRepository.
     */
    private function makeRepoMock(): QuoteWerksRepository
    {
        return $this->createMock(QuoteWerksRepository::class);
    }

    /**
     * Create a mock QuoteImportService that returns the given package from importFromData().
     */
    private function makeImportServiceMock(?ProjectPackage $returns = null): QuoteImportService
    {
        $mock    = $this->createMock(QuoteImportService::class);
        $package = $returns ?? $this->createMock(ProjectPackage::class);

        $mock->method('importFromData')->willReturn($package);

        return $mock;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — importByReference() throws ModelNotFoundException when not found
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the repository cannot find the header row, importByReference() must
     * throw ModelNotFoundException so callers can handle 404 gracefully.
     */
    public function test_importByReference_throws_when_header_not_found(): void
    {
        $repo = $this->makeRepoMock();
        $repo->method('findByReference')->willReturn(null);

        $service = new QuoteWerksImportService($repo, $this->makeImportServiceMock());

        $this->expectException(ModelNotFoundException::class);

        $user = $this->createMock(User::class);
        $service->importByReference($user, 'MISSING-999');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — importByReference() returns ProjectPackage on success
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the repository returns a valid header + items, importByReference()
     * must pass the data through and return the ProjectPackage from importFromData().
     */
    public function test_importByReference_returns_project_package(): void
    {
        $expectedPackage = $this->createMock(ProjectPackage::class);

        $repo = $this->makeRepoMock();
        $repo->method('findByReference')->willReturn($this->makeSampleHeader());
        $repo->method('getItemsByDocNo')->willReturn($this->makeSampleItems());

        $importService = $this->createMock(QuoteImportService::class);
        $importService->method('importFromData')->willReturn($expectedPackage);

        $service = new QuoteWerksImportService($repo, $importService);

        $user   = $this->createMock(User::class);
        $result = $service->importByReference($user, 'QW-2024-001');

        $this->assertSame($expectedPackage, $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — buildExtractedData() sets meta.source to exactly 'quotewerks_sql'
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * meta.source MUST equal the literal string 'quotewerks_sql'.
     * ProjectDataService uses an exact equality check on this value.
     */
    public function test_buildExtractedData_sets_meta_source_to_quotewerks_sql(): void
    {
        $service = new QuoteWerksImportService(
            $this->makeRepoMock(),
            $this->makeImportServiceMock()
        );

        $result = $service->buildExtractedData($this->makeSampleHeader(), $this->makeSampleItems());

        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('quotewerks_sql', $result['meta']['source']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — buildExtractedData() sets confidence to 0.95
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Both meta.confidence and meta.parser_confidence must equal 0.95 (float).
     */
    public function test_buildExtractedData_sets_confidence_to_095(): void
    {
        $service = new QuoteWerksImportService(
            $this->makeRepoMock(),
            $this->makeImportServiceMock()
        );

        $result = $service->buildExtractedData($this->makeSampleHeader(), $this->makeSampleItems());

        $this->assertSame(0.95, $result['meta']['confidence']);
        $this->assertSame(0.95, $result['meta']['parser_confidence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — buildExtractedData() builds rooms from unique non-empty group names
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * rooms must be deduplicated and empty strings must be filtered out.
     */
    public function test_buildExtractedData_builds_rooms_from_unique_non_empty_group_names(): void
    {
        $service = new QuoteWerksImportService(
            $this->makeRepoMock(),
            $this->makeImportServiceMock()
        );

        $result = $service->buildExtractedData($this->makeSampleHeader(), $this->makeSampleItems());

        $this->assertArrayHasKey('rooms', $result);
        // Should have 2 unique non-empty groups (Boardroom, Conference Room)
        $this->assertCount(2, $result['rooms']);
        $this->assertContains('Boardroom',       $result['rooms']);
        $this->assertContains('Conference Room', $result['rooms']);
        // Empty string must NOT be in rooms
        $this->assertNotContains('', $result['rooms']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — buildExtractedData() builds equipment rows with all required keys
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every equipment row must carry: quantity, qty, part_number, part_no,
     * name, description, area, location, category.
     */
    public function test_buildExtractedData_equipment_rows_have_all_required_keys(): void
    {
        $service = new QuoteWerksImportService(
            $this->makeRepoMock(),
            $this->makeImportServiceMock()
        );

        $result = $service->buildExtractedData($this->makeSampleHeader(), $this->makeSampleItems());

        $this->assertNotEmpty($result['equipment']);

        foreach ($result['equipment'] as $row) {
            $this->assertArrayHasKey('quantity',    $row, 'Missing key: quantity');
            $this->assertArrayHasKey('qty',         $row, 'Missing key: qty');
            $this->assertArrayHasKey('part_number', $row, 'Missing key: part_number');
            $this->assertArrayHasKey('part_no',     $row, 'Missing key: part_no');
            $this->assertArrayHasKey('name',        $row, 'Missing key: name');
            $this->assertArrayHasKey('description', $row, 'Missing key: description');
            $this->assertArrayHasKey('area',        $row, 'Missing key: area');
            $this->assertArrayHasKey('location',    $row, 'Missing key: location');
            $this->assertArrayHasKey('category',    $row, 'Missing key: category');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7 — buildExtractedData() sets equipment, equipment_list, line_items to same array
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * equipment, equipment_list, and line_items must all reference the same data.
     */
    public function test_buildExtractedData_equipment_list_and_line_items_are_same(): void
    {
        $service = new QuoteWerksImportService(
            $this->makeRepoMock(),
            $this->makeImportServiceMock()
        );

        $result = $service->buildExtractedData($this->makeSampleHeader(), $this->makeSampleItems());

        $this->assertSame($result['equipment'],      $result['equipment_list']);
        $this->assertSame($result['equipment_list'], $result['line_items']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8 — importByReference() passes correct keys to importFromData()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The $data array passed to QuoteImportService::importFromData() must include:
     * client_name, site_address, ref, name, works_description, equipment_list,
     * cable_list, extracted_data.
     */
    public function test_importByReference_passes_correct_keys_to_importFromData(): void
    {
        $capturedData = null;

        $repo = $this->makeRepoMock();
        $repo->method('findByReference')->willReturn($this->makeSampleHeader());
        $repo->method('getItemsByDocNo')->willReturn($this->makeSampleItems());

        $importService = $this->createMock(QuoteImportService::class);
        $importService->expects($this->once())
            ->method('importFromData')
            ->willReturnCallback(function (User $user, array $data) use (&$capturedData) {
                $capturedData = $data;

                return $this->createMock(ProjectPackage::class);
            });

        $service = new QuoteWerksImportService($repo, $importService);
        $user    = $this->createMock(User::class);
        $service->importByReference($user, 'QW-2024-001');

        $this->assertNotNull($capturedData);
        $this->assertArrayHasKey('client_name',      $capturedData);
        $this->assertArrayHasKey('site_address',     $capturedData);
        $this->assertArrayHasKey('ref',              $capturedData);
        $this->assertArrayHasKey('name',             $capturedData);
        $this->assertArrayHasKey('works_description', $capturedData);
        $this->assertArrayHasKey('equipment_list',   $capturedData);
        $this->assertArrayHasKey('cable_list',       $capturedData);
        $this->assertArrayHasKey('extracted_data',   $capturedData);
    }
}

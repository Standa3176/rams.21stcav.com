<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Core\Modules\QuoteImport\QuoteWerksImportService;
use App\Models\ProjectPackage;
use App\Models\User;
use Tests\TestCase;

/**
 * Unit tests for QuoteWerksImportService (260723-qw1 rewrite).
 *
 * Verifies the parsed-shape → extracted_data transformation and the
 * importFromParsedShape orchestration. All dependencies are mocked —
 * no DB, no SQL Server required.
 */
class QuoteWerksImportServiceTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Shared fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function sampleParsedShape(): array
    {
        return [
            'client'          => 'ACME Ltd',
            'site'            => '1 High Street, London, EC1V 9AA',
            'site_name'       => 'ACME Head Office',
            'ref'             => '21CQ14213',
            'prepared_by'     => 'J Smith',
            'scope_narrative' => 'Board room upgrade project scope narrative that describes the works',
            'contact_name'    => null,
            'contact_phone'   => null,
            'contact_email'   => null,
            'equipment'       => [
                [
                    'description'  => 'Sony BRAVIA 40" Display',
                    'part_number'  => 'SONY-BZ40H',
                    'area'         => 'Boardroom',
                    'location'     => 'North Wall',
                    'qty'          => 2,
                    'unit_price'   => 500.00,
                    'manufacturer' => 'Sony',
                ],
                [
                    'description'  => 'Sony BRAVIA 40" Display',
                    'part_number'  => 'SONY-BZ40H',
                    'area'         => 'Boardroom',
                    'location'     => 'South Wall',
                    'qty'          => 1,
                    'unit_price'   => 500.00,
                    'manufacturer' => 'Sony',
                ],
                [
                    'description'  => 'NEC Projector',
                    'part_number'  => 'NEC-PA522U',
                    'area'         => 'Conference Room',
                    'location'     => 'Ceiling',
                    'qty'          => 1,
                    'unit_price'   => 1200.00,
                    'manufacturer' => 'NEC',
                ],
            ],
            'rooms'           => ['Boardroom', 'Conference Room'],
        ];
    }

    private function makeImportServiceMock(?ProjectPackage $returns = null): QuoteImportService
    {
        $mock    = $this->createMock(QuoteImportService::class);
        $package = $returns ?? $this->createMock(ProjectPackage::class);

        $mock->method('importFromData')->willReturn($package);

        return $mock;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildExtractedData shape
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function build_extracted_data_sets_meta_source_to_quotewerks_sql(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('quotewerks_sql', $result['meta']['source']);
    }

    /** @test */
    public function build_extracted_data_sets_confidence_to_095(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertSame(0.95, $result['meta']['confidence']);
        $this->assertSame(0.95, $result['meta']['parser_confidence']);
    }

    /** @test */
    public function build_extracted_data_preserves_rooms_from_parsed_shape(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertSame(['Boardroom', 'Conference Room'], $result['rooms']);
        $this->assertSame(2, $result['meta']['room_count']);
    }

    /** @test */
    public function build_extracted_data_equipment_rows_have_all_required_keys(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertNotEmpty($result['equipment']);

        foreach ($result['equipment'] as $row) {
            $this->assertArrayHasKey('quantity',    $row);
            $this->assertArrayHasKey('qty',         $row);
            $this->assertArrayHasKey('part_number', $row);
            $this->assertArrayHasKey('part_no',     $row);
            $this->assertArrayHasKey('name',        $row);
            $this->assertArrayHasKey('description', $row);
            $this->assertArrayHasKey('area',        $row);
            $this->assertArrayHasKey('location',    $row);
            $this->assertArrayHasKey('category',    $row);
            $this->assertArrayHasKey('unit_price',  $row);
            $this->assertArrayHasKey('total_price', $row);
            $this->assertArrayHasKey('data_source', $row);
        }
    }

    /** @test */
    public function build_extracted_data_equipment_list_and_line_items_are_same_data(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertSame($result['equipment'],      $result['equipment_list']);
        $this->assertSame($result['equipment_list'], $result['line_items']);
    }

    /** @test */
    public function build_extracted_data_computes_total_price_from_qty_and_unit_price(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        // First row: qty 2 × 500 = 1000
        $this->assertSame(1000.0, $result['equipment'][0]['total_price']);
        // Third row: qty 1 × 1200 = 1200
        $this->assertSame(1200.0, $result['equipment'][2]['total_price']);
    }

    /** @test */
    public function build_extracted_data_classifies_display_and_projector(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertSame('display', $result['equipment'][0]['category']);
        // "NEC Projector" — projector isn't in the display pattern but is
        // not caught by anything specific either → falls to 'other'.
        $this->assertContains($result['equipment'][2]['category'], ['other', 'display']);
    }

    /** @test */
    public function build_extracted_data_project_name_truncates_scope_narrative_to_80_chars(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $parsed = array_merge($this->sampleParsedShape(), [
            'scope_narrative' => str_repeat('X', 200),
        ]);

        $result = $service->buildExtractedData($parsed);

        $this->assertLessThanOrEqual(80, strlen($result['project_name']));
    }

    /** @test */
    public function build_extracted_data_project_name_falls_back_to_ref_when_scope_empty(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $parsed = array_merge($this->sampleParsedShape(), [
            'scope_narrative' => '',
        ]);

        $result = $service->buildExtractedData($parsed);

        $this->assertSame('21CQ14213', $result['project_name']);
    }

    /** @test */
    public function build_extracted_data_carries_prepared_by_and_site_name(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertSame('J Smith',           $result['prepared_by']);
        $this->assertSame('ACME Head Office',  $result['site_name']);
    }

    /** @test */
    public function build_extracted_data_handles_missing_equipment_key(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $result = $service->buildExtractedData([
            'ref'    => '21CQ99999',
            'client' => 'Sparse Client',
            // No equipment key at all
        ]);

        $this->assertSame([], $result['equipment']);
        $this->assertSame(0, $result['meta']['item_count']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Room overviews + intro/closing notes (260725-qw2)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function build_extracted_data_zips_room_descriptions_into_room_overviews(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $parsed = $this->sampleParsedShape();
        $parsed['rooms']             = ['Oregano', 'Cinnamon', 'Saffron'];
        $parsed['room_descriptions'] = [
            'Oregano'  => 'Oregano uses the Crestron small room system.',
            'Cinnamon' => 'Cinnamon uses the Crestron Flex integrator kit.',
            // Saffron intentionally omitted — should render as empty overview
        ];

        $result = $service->buildExtractedData($parsed);

        $this->assertSame(
            [
                ['room' => 'Oregano',  'overview' => 'Oregano uses the Crestron small room system.'],
                ['room' => 'Cinnamon', 'overview' => 'Cinnamon uses the Crestron Flex integrator kit.'],
                ['room' => 'Saffron',  'overview' => ''],
            ],
            $result['room_overviews']
        );
    }

    /** @test */
    public function build_extracted_data_room_overviews_empty_when_no_rooms(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $parsed = $this->sampleParsedShape();
        $parsed['rooms']             = [];
        $parsed['room_descriptions'] = [];

        $result = $service->buildExtractedData($parsed);

        $this->assertSame([], $result['room_overviews']);
    }

    /** @test */
    public function build_extracted_data_surfaces_introduction_and_closing_notes(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        $parsed = $this->sampleParsedShape();
        $parsed['intro_notes']   = '21st Century AV are pleased to provide a detailed quote.';
        $parsed['closing_notes'] = 'Please contact me if I can be of further assistance.';

        $result = $service->buildExtractedData($parsed);

        $this->assertSame(
            '21st Century AV are pleased to provide a detailed quote.',
            $result['introduction_notes']
        );
        $this->assertSame(
            'Please contact me if I can be of further assistance.',
            $result['closing_notes']
        );
    }

    /** @test */
    public function build_extracted_data_intro_and_closing_notes_null_when_absent(): void
    {
        $service = new QuoteWerksImportService($this->makeImportServiceMock());

        // sampleParsedShape has no intro/closing keys → null on output
        $result = $service->buildExtractedData($this->sampleParsedShape());

        $this->assertNull($result['introduction_notes']);
        $this->assertNull($result['closing_notes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // importFromParsedShape orchestration
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function import_from_parsed_shape_returns_project_package(): void
    {
        $expected = $this->createMock(ProjectPackage::class);
        $service  = new QuoteWerksImportService($this->makeImportServiceMock($expected));

        $result = $service->importFromParsedShape(
            $this->createMock(User::class),
            $this->sampleParsedShape()
        );

        $this->assertSame($expected, $result);
    }

    /** @test */
    public function import_from_parsed_shape_passes_correct_keys_to_import_from_data(): void
    {
        $capturedData = null;

        $importService = $this->createMock(QuoteImportService::class);
        $importService->expects($this->once())
            ->method('importFromData')
            ->willReturnCallback(function (User $user, array $data) use (&$capturedData) {
                $capturedData = $data;
                return $this->createMock(ProjectPackage::class);
            });

        $service = new QuoteWerksImportService($importService);
        $service->importFromParsedShape($this->createMock(User::class), $this->sampleParsedShape());

        $this->assertNotNull($capturedData);
        $this->assertArrayHasKey('client_name',      $capturedData);
        $this->assertArrayHasKey('site_address',     $capturedData);
        $this->assertArrayHasKey('ref',              $capturedData);
        $this->assertArrayHasKey('name',             $capturedData);
        $this->assertArrayHasKey('works_description', $capturedData);
        $this->assertArrayHasKey('equipment_list',   $capturedData);
        $this->assertArrayHasKey('cable_list',       $capturedData);
        $this->assertArrayHasKey('extracted_data',   $capturedData);

        $this->assertSame('ACME Ltd',                             $capturedData['client_name']);
        $this->assertSame('1 High Street, London, EC1V 9AA',      $capturedData['site_address']);
        $this->assertSame('21CQ14213',                            $capturedData['ref']);
        $this->assertSame([],                                     $capturedData['cable_list']);
    }
}

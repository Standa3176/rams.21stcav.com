<?php

namespace Tests\Feature\Drawings;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Core\Modules\QuoteImport\QuoteWerksImportService;
use App\Jobs\ExtractQuoteJob;
use App\Jobs\ReimportQuoteJob;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Drawings\CategoryPortTemplateResolver;
use App\Services\QuoteExtractorService;
use App\Services\QuoteImport\QuoteImportStencilStubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 24 Plan 02 — QuoteImportStencilStubber (D-09).
 *
 * Task 1 locks the standalone stubber's filtering/idempotency/normalisation
 * contract. Task 2 proves all THREE quote-import call sites are wired and
 * that a stubber failure never fails the parent import.
 *
 * @see app/Services/QuoteImport/QuoteImportStencilStubber.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-02-PLAN.md
 */
class QuoteImportStencilStubberTest extends TestCase
{
    use RefreshDatabase;

    private function stubber(): QuoteImportStencilStubber
    {
        return $this->app->make(QuoteImportStencilStubber::class);
    }

    // ── Task 1 ───────────────────────────────────────────────────────────────

    public function test_stubs_hardware_lines_and_is_idempotent(): void
    {
        $lines = [
            // Hardware, resolves to the 'display' template (1 HDMI port).
            ['part_number' => 'FW-85BZ40L', 'name' => 'Sony FW-85BZ40L 85in Commercial Display', 'category' => null],
            // Cable — never produces a device_stencils row regardless of template resolution.
            ['part_number' => 'CAB-HDMI-3M', 'name' => 'HDMI Cable 3m', 'category' => null],
            // sku/description-only shape (ReimportQuoteJob's line_items shape) — no part_number,
            // no category key at all. Description text resolves to the 'switch' template (4 ports).
            ['sku' => 'SW-GS312', 'description' => 'Netgear GS312TP PoE Switch'],
        ];

        $result = $this->stubber()->stubFromEquipmentLines($lines);

        $this->assertSame(2, $result['created'], 'Only the 2 hardware lines should create stencils; the cable line must not.');
        $this->assertCount(2, $result['stencils']);
        $this->assertSame(2, DeviceStencil::query()->count());

        $displayStencil = DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('FW-85BZ40L'))->first();
        $this->assertNotNull($displayStencil);
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $displayStencil->source);
        $this->assertTrue($displayStencil->needs_review);
        $this->assertSame(1, $displayStencil->ports()->count());
        $this->assertSame('hdmi', $displayStencil->ports()->first()->connector_type);

        $switchStencil = DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('SW-GS312'))->first();
        $this->assertNotNull($switchStencil);
        $this->assertTrue($switchStencil->needs_review);
        $this->assertSame(4, $switchStencil->ports()->count());

        $this->assertFalse(
            DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('CAB-HDMI-3M'))->exists(),
            'Cable line items must never produce a device_stencils row.'
        );

        $portCountBeforeRepeat = DevicePort::query()->count();

        // Re-run the identical lines — idempotent, no duplicate stencils/ports.
        $second = $this->stubber()->stubFromEquipmentLines($lines);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, DeviceStencil::query()->count());
        $this->assertSame($portCountBeforeRepeat, DevicePort::query()->count());
    }

    public function test_ambiguous_device_type_still_creates_a_needs_review_zero_port_stub(): void
    {
        $lines = [
            ['part_number' => 'PA20', 'name' => 'PA20 Rack Amplifier', 'category' => null],
        ];

        $result = $this->stubber()->stubFromEquipmentLines($lines);

        $this->assertSame(1, $result['created']);

        $stencil = DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('PA20'))->first();
        $this->assertNotNull($stencil);
        $this->assertTrue($stencil->needs_review);
        $this->assertSame(0, $stencil->ports()->count());
    }

    // ── Task 2 — call site (a): ExtractQuoteJob (PDF upload path) ──────────────

    /**
     * Invokes ExtractQuoteJob's private merge + stub methods directly via
     * reflection (precedent: ExtractQuoteJobClassifyItemTypeTest) — the full
     * handle() pipeline requires PdfTextExtractorService/QuoteParserService/
     * AIManager, none of which are relevant to proving THIS wiring. This
     * exercises the exact production code path: mergeParsedQuoteData()
     * builds $extracted['equipment_list'], then stubDeviceStencils() (the
     * method this plan adds, called between the DB::transaction close and
     * generateContentPack() in handle()) stubs from it.
     */
    public function test_extract_quote_job_stubs_hardware_lines_via_merged_equipment(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'quote-imports/quote.pdf',
            'extracted_data' => [],
            'equipment_list' => [],
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_EXTRACTING,
        ]);

        $reflection = new ReflectionClass(ExtractQuoteJob::class);
        $job        = $reflection->newInstanceWithoutConstructor();

        $packageProp = $reflection->getProperty('package');
        $packageProp->setAccessible(true);
        $packageProp->setValue($job, $package);

        $parsed = [
            'equipment' => [
                [
                    'description' => 'Sony FW-85BZ40L 85in Commercial Display',
                    'part_number' => 'FW-85BZ40L',
                    'qty'         => 1,
                    'area'        => 'Boardroom',
                    'location'    => 'North Wall',
                ],
                [
                    'description' => 'HDMI Cable 3m',
                    'part_number' => 'CAB-HDMI-3M',
                    'qty'         => 2,
                    'area'        => 'Boardroom',
                    'location'    => '',
                ],
            ],
        ];

        $mergeMethod = $reflection->getMethod('mergeParsedQuoteData');
        $mergeMethod->setAccessible(true);
        $extracted = $mergeMethod->invoke($job, [], $parsed);

        $this->assertSame('hardware', $extracted['equipment_list'][0]['category']);
        $this->assertNotSame('hardware', $extracted['equipment_list'][1]['category']);

        $stubMethod = $reflection->getMethod('stubDeviceStencils');
        $stubMethod->setAccessible(true);
        $stubMethod->invoke($job, $extracted);

        $this->assertSame(1, DeviceStencil::query()->count());
        $this->assertTrue(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('FW-85BZ40L'))->exists());
        $this->assertFalse(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('CAB-HDMI-3M'))->exists());
    }

    // ── Task 2 — call site (b): QuoteWerksImportService (default import route) ─

    public function test_quotewerks_import_service_stubs_hardware_lines(): void
    {
        /** @var QuoteWerksImportService $service */
        $service = $this->app->make(QuoteWerksImportService::class);

        $result = $service->buildExtractedData([
            'ref'       => '21CQ99999',
            'client'    => 'ACME Ltd',
            'site'      => '1 High Street, London',
            'equipment' => [
                [
                    'description'  => 'Sony FW-85BZ40L 85in Commercial Display',
                    'part_number'  => 'FW-85BZ40L',
                    'qty'          => 1,
                    'unit_price'   => 1500.0,
                    'manufacturer' => 'Sony',
                ],
                [
                    'description'  => 'Cat6 Patch Cable 3m',
                    'part_number'  => 'CAB-CAT6-3M',
                    'qty'          => 5,
                    'unit_price'   => 3.0,
                    'manufacturer' => null,
                ],
            ],
        ]);

        $this->assertSame('hardware', $result['equipment'][0]['category']);
        $this->assertSame('cables',   $result['equipment'][1]['category']);

        $this->assertSame(1, DeviceStencil::query()->count());
        $this->assertTrue(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('FW-85BZ40L'))->exists());
        $this->assertFalse(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('CAB-CAT6-3M'))->exists());
    }

    // ── Task 2 — call site (c): ReimportQuoteJob (re-import path) ──────────────

    /**
     * QuoteExtractorService is mocked (Claude document-vision API — no real
     * network call in tests). The mocked line_items carry ONLY sku/description
     * (no part_number, no category) — the exact ReimportQuoteJob shape per
     * this plan's <interfaces> section. Also proves the transaction-boundary
     * requirement (RESEARCH.md Assumption A2): the stub call in
     * ReimportQuoteJob::handle() runs strictly AFTER
     * QuoteImportService::completePendingReimport()'s DB::transaction closure
     * returns, never inside it — this test would deadlock/fail on an unclosed
     * transaction if that boundary were violated.
     */
    public function test_reimport_quote_job_stubs_hardware_lines_from_sku_description_shape(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();

        $pending = ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'quote.pdf',
            'quote_path'        => 'quote-imports/quote.pdf',
            'extracted_data'    => [],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 2,
            'status'            => ProjectPackage::STATUS_EXTRACTING,
        ]);

        $mockExtractor = Mockery::mock(QuoteExtractorService::class);
        $mockExtractor->shouldReceive('extractFromPath')->once()->andReturn([
            'qw_number'         => '21CQ88888',
            'client_name'       => 'ACME Ltd',
            'site_address'      => '1 High Street',
            'project_name'      => 'ACME Refresh',
            'works_description' => 'Refresh works',
            'line_items'        => [
                ['sku' => 'FW-85BZ40L', 'qty' => 1, 'description' => 'Sony FW-85BZ40L 85in Commercial Display'],
                ['sku' => 'CAB-HDMI-3M', 'qty' => 2, 'description' => 'HDMI Cable 3m'],
            ],
            'room_summaries'    => [],
            'hazards'           => [],
            'ppe'               => [],
            'persons_at_risk'   => [],
        ]);
        $this->app->instance(QuoteExtractorService::class, $mockExtractor);

        $service = $this->app->make(QuoteImportService::class);
        $job     = new ReimportQuoteJob($pending, $user, null, null);
        $job->handle($service);

        $this->assertSame(ProjectPackage::STATUS_EXTRACTED, $pending->fresh()->status);
        $this->assertSame(1, DeviceStencil::query()->count());
        $this->assertTrue(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('FW-85BZ40L'))->exists());
        $this->assertFalse(DeviceStencil::query()->where('part_number', DeviceStencil::normalisePartNumber('CAB-HDMI-3M'))->exists());
    }

    // ── Task 2 — best-effort isolation ──────────────────────────────────────────

    /**
     * Forces CategoryPortTemplateResolver::resolve() to throw and asserts the
     * parent ProjectPackage still reaches STATUS_EXTRACTED — proves the
     * best-effort try/catch wired around the stubber call, not just that it's
     * present in source.
     */
    public function test_stubber_failure_never_fails_the_parent_import(): void
    {
        $mockResolver = Mockery::mock(CategoryPortTemplateResolver::class);
        $mockResolver->shouldReceive('resolve')->andThrow(new \RuntimeException('forced failure'));
        $this->app->instance(CategoryPortTemplateResolver::class, $mockResolver);

        $service = $this->app->make(QuoteWerksImportService::class);
        $user    = User::factory()->create();

        $package = $service->importFromParsedShape($user, [
            'client'    => 'Best Effort Ltd',
            'site'      => '9 Resilience Road',
            'ref'       => '21CQ77777',
            'equipment' => [
                [
                    'description'  => 'Sony FW-85BZ40L 85in Commercial Display',
                    'part_number'  => 'FW-85BZ40L',
                    'qty'          => 1,
                    'unit_price'   => 1500.0,
                    'manufacturer' => 'Sony',
                ],
            ],
        ]);

        $this->assertSame(ProjectPackage::STATUS_EXTRACTED, $package->status);
        $this->assertSame(0, DeviceStencil::query()->count());
    }
}

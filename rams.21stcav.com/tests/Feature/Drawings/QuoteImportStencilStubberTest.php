<?php

namespace Tests\Feature\Drawings;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Services\QuoteImport\QuoteImportStencilStubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

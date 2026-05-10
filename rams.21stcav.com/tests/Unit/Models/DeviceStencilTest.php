<?php

namespace Tests\Unit\Models;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 21 Plan 01 Task 1 — locks DeviceStencil + DevicePort model + migration
 * shape per CONTEXT.md D-02 and D-04. Asserts:
 *   - Source/Side/Direction enum constants present + correct values
 *   - normalisePartNumber() lowercases + trims (mirrors DeviceCatalogService::all()
 *     normalisation key derivation — case-insensitive lookup contract)
 *   - isCurated() returns false for auto-generated, true otherwise
 *   - ports() relation type === HasMany
 *   - Migration creates both tables with the expected columns
 *   - device_ports.device_stencil_id FK cascades on delete
 *
 * @see database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php
 * @see app/Models/DeviceStencil.php
 * @see app/Models/DevicePort.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-02, D-04, D-09)
 */
class DeviceStencilTest extends TestCase
{
    use RefreshDatabase;

    // ── Migration shape ──────────────────────────────────────────────────────

    public function test_device_stencils_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('device_stencils'));

        $expected = [
            'id',
            'part_number',
            'manufacturer',
            'model',
            'display_name',
            'mxgraph_xml',
            'logo_svg',
            'default_width',
            'default_height',
            'source',
            'metadata',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('device_stencils', $col),
                "device_stencils missing column: {$col}"
            );
        }
    }

    public function test_device_ports_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('device_ports'));

        $expected = [
            'id',
            'device_stencil_id',
            'label',
            'side',
            'connector_type',
            'signal_type',
            'direction',
            'sort_order',
            'port_id',
            'y_pct',
            'x_pct',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('device_ports', $col),
                "device_ports missing column: {$col}"
            );
        }
    }

    public function test_device_ports_fk_cascades_on_stencil_delete(): void
    {
        $stencil = DeviceStencil::create([
            'part_number'   => 'cascade-test-001',
            'manufacturer'  => 'Acme',
            'model'         => 'Widget',
            'display_name'  => 'Acme Widget',
            'mxgraph_xml'   => '<shape></shape>',
            'source'        => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);

        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_OUT,
            'sort_order'        => 0,
            'port_id'           => 'hdmi-1',
        ]);

        $this->assertSame(1, DevicePort::where('device_stencil_id', $stencil->id)->count());

        $stencil->delete();

        $this->assertSame(
            0,
            DevicePort::where('device_stencil_id', $stencil->id)->count(),
            'device_ports rows must cascade-delete when their parent stencil is deleted'
        );
    }

    // ── Enum-style source constants (D-04) ───────────────────────────────────

    public function test_source_constants_have_expected_values(): void
    {
        $this->assertSame('auto-generated', DeviceStencil::SOURCE_AUTO_GENERATED);
        $this->assertSame('engineer-curated', DeviceStencil::SOURCE_ENGINEER_CURATED);
        $this->assertSame('ai-extracted', DeviceStencil::SOURCE_AI_EXTRACTED);
    }

    public function test_is_curated_returns_false_for_auto_generated(): void
    {
        $stencil = new DeviceStencil(['source' => DeviceStencil::SOURCE_AUTO_GENERATED]);

        $this->assertFalse($stencil->isCurated());
    }

    public function test_is_curated_returns_true_for_engineer_curated(): void
    {
        $stencil = new DeviceStencil(['source' => DeviceStencil::SOURCE_ENGINEER_CURATED]);

        $this->assertTrue($stencil->isCurated());
    }

    public function test_is_curated_returns_true_for_ai_extracted(): void
    {
        $stencil = new DeviceStencil(['source' => DeviceStencil::SOURCE_AI_EXTRACTED]);

        $this->assertTrue($stencil->isCurated());
    }

    // ── normalisePartNumber (case-insensitive trim) ──────────────────────────

    public function test_normalise_part_number_lowercases_and_trims(): void
    {
        $this->assertSame('am-300', DeviceStencil::normalisePartNumber('  AM-300 '));
        $this->assertSame('neat-bar-pro', DeviceStencil::normalisePartNumber('NEAT-BAR-PRO'));
        $this->assertSame('foo', DeviceStencil::normalisePartNumber("\t Foo\n"));
        $this->assertSame('', DeviceStencil::normalisePartNumber('   '));
    }

    // ── ports() relation ─────────────────────────────────────────────────────

    public function test_ports_relation_is_has_many(): void
    {
        $stencil = new DeviceStencil;

        $this->assertInstanceOf(HasMany::class, $stencil->ports());
    }

    public function test_ports_relation_returns_ports_ordered_by_sort_order(): void
    {
        $stencil = DeviceStencil::create([
            'part_number'   => 'ordering-test-001',
            'manufacturer'  => 'Acme',
            'model'         => 'Widget',
            'mxgraph_xml'   => '<shape></shape>',
            'source'        => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);

        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 2',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_OUT,
            'sort_order'        => 5,
            'port_id'           => 'hdmi-2',
        ]);

        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_OUT,
            'sort_order'        => 1,
            'port_id'           => 'hdmi-1',
        ]);

        $labels = $stencil->ports()->pluck('label')->toArray();

        $this->assertSame(['HDMI 1', 'HDMI 2'], $labels);
    }

    // ── DevicePort enum constants (D-02) ─────────────────────────────────────

    public function test_device_port_side_constants(): void
    {
        $this->assertSame('left', DevicePort::SIDE_LEFT);
        $this->assertSame('right', DevicePort::SIDE_RIGHT);
        $this->assertSame('top', DevicePort::SIDE_TOP);
        $this->assertSame('bottom', DevicePort::SIDE_BOTTOM);
    }

    public function test_device_port_direction_constants(): void
    {
        $this->assertSame('in', DevicePort::DIRECTION_IN);
        $this->assertSame('out', DevicePort::DIRECTION_OUT);
        $this->assertSame('io', DevicePort::DIRECTION_IO);
    }

    public function test_metadata_cast_to_array(): void
    {
        $stencil = DeviceStencil::create([
            'part_number'   => 'metadata-test-001',
            'manufacturer'  => 'Acme',
            'model'         => 'Widget',
            'mxgraph_xml'   => '<shape></shape>',
            'source'        => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'metadata'      => ['notes' => 'curated 2026-05-10', 'tags' => ['mtr']],
        ]);

        $fresh = $stencil->fresh();
        $this->assertIsArray($fresh->metadata);
        $this->assertSame('curated 2026-05-10', $fresh->metadata['notes']);
        $this->assertSame(['mtr'], $fresh->metadata['tags']);
    }
}

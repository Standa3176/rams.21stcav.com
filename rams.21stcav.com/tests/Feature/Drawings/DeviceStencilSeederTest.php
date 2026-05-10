<?php

namespace Tests\Feature\Drawings;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use Database\Seeders\DeviceStencilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21 Plan 02 Task 2 — locks DeviceStencilSeeder behaviour per
 * CONTEXT.md D-05 (idempotent upsert from manifest pack into device_stencils
 * + device_ports). Asserts:
 *   - First run creates >=58 DeviceStencil rows (5 spike + 53 v1.3 + gap-fill)
 *   - First run creates a meaningful number of DevicePort rows (curated
 *     stencils average >=3 ports; v1.3-promoted may have 0)
 *   - Second run produces zero new rows (idempotency via whereRaw +
 *     updateOrCreate matching pattern from DeviceCatalogSeeder)
 *   - Manually edit a port label, re-run, port label is restored from manifest
 *     (manifest is source of truth — git-tracked curation)
 *   - Every seeded stencil's source = engineer-curated
 *   - v1.3-promoted entries carry metadata.needs_phase_24_curation = true
 *   - Spike-promoted neat-bar-pro has 6 ports + a known port_id
 *
 * @see database/seeders/DeviceStencilSeeder.php
 * @see app/Services/Drawings/DeviceStencilSeedReader.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-05)
 */
class DeviceStencilSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_at_least_58_stencils(): void
    {
        $this->assertSame(0, DeviceStencil::count(),
            'fresh DB starts empty');

        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $count = DeviceStencil::count();
        $this->assertGreaterThanOrEqual(58, $count,
            "Seeder must create >=58 stencils (5 spike + 53 v1.3 + gap-fill); got {$count}");
    }

    public function test_first_run_creates_meaningful_number_of_ports(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $portCount = DevicePort::count();
        // Spike has 6+9+7+4+14 = 40 ports; v1.3-promoted has 0; gap has 0.
        // Floor at 35 to leave room for gap-fill curated additions later.
        $this->assertGreaterThanOrEqual(35, $portCount,
            "Seeder must create >=35 DevicePort rows from the 5 spike stencils alone; got {$portCount}");
    }

    public function test_second_run_produces_zero_new_rows(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $stencilsAfterFirst = DeviceStencil::count();
        $portsAfterFirst = DevicePort::count();

        // Re-run the seeder.
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $this->assertSame($stencilsAfterFirst, DeviceStencil::count(),
            'Second seeder run must NOT insert duplicate stencil rows (idempotency contract)');
        $this->assertSame($portsAfterFirst, DevicePort::count(),
            'Second seeder run must NOT insert duplicate port rows (port re-build is idempotent)');
    }

    public function test_manual_port_edits_are_overwritten_by_reseed(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        // Find the neat-bar-pro stencil's hdmi-in port.
        $stencil = DeviceStencil::where('part_number', strtolower('NEAT-BAR-PRO'))->firstOrFail();
        $port = DevicePort::where('device_stencil_id', $stencil->id)
            ->where('port_id', 'hdmi-in')
            ->firstOrFail();
        $originalLabel = $port->label;

        // Engineer manually edits the label outside the manifest.
        $port->update(['label' => 'CUSTOM EDIT']);

        // Re-seed.
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $portAfter = DevicePort::where('device_stencil_id', $stencil->id)
            ->where('port_id', 'hdmi-in')
            ->firstOrFail();
        $this->assertSame($originalLabel, $portAfter->label,
            'Manifest is source of truth — manual port edits MUST be wiped on reseed');
    }

    public function test_every_seeded_stencil_source_is_engineer_curated(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $nonCurated = DeviceStencil::where('source', '!=', DeviceStencil::SOURCE_ENGINEER_CURATED)->count();
        $this->assertSame(0, $nonCurated,
            'Every seeded stencil MUST have source=engineer-curated (D-05 contract)');
    }

    public function test_v13_promoted_stencils_carry_needs_curation_flag(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        // v1.3 promoted = 53 entries; sanity check on the JSON metadata path.
        $needsCuration = DeviceStencil::query()
            ->whereJsonContains('metadata->needs_phase_24_curation', true)
            ->count();

        $this->assertGreaterThanOrEqual(53, $needsCuration,
            "Expected >=53 stencils flagged needs_phase_24_curation (53 v1.3 + gap-fill); got {$needsCuration}");
    }

    public function test_spike_neat_bar_pro_has_six_ports_with_known_ids(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $stencil = DeviceStencil::where('part_number', strtolower('NEAT-BAR-PRO'))->firstOrFail();
        $this->assertCount(6, $stencil->ports,
            'Neat Bar Pro spike has 6 ports — verified by Task 1 manifest');

        $portIds = $stencil->ports->pluck('port_id')->all();
        $this->assertContains('hdmi-in', $portIds);
        $this->assertContains('usb-c', $portIds);
        $this->assertContains('hdmi-out', $portIds);
        $this->assertContains('lan', $portIds);
    }

    public function test_part_number_lookup_is_case_insensitive(): void
    {
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        // The seeder normalises via DeviceStencil::normalisePartNumber()
        // (lowercase trim) — query with mixed-case input should still hit.
        $stencil = DeviceStencil::query()
            ->whereRaw('LOWER(TRIM(part_number)) = ?', [strtolower('NEAT-BAR-PRO')])
            ->first();

        $this->assertNotNull($stencil);
        $this->assertSame('neat-bar-pro', $stencil->part_number,
            'Stored part_number must be the normalised (lowercase trim) value');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tests for `stencils:coverage-report` (Phase 24 Plan 08).
 *
 * Covers: frequency ranking derived from a live DB query (never the seed
 * pack — Phase 21 D-15 independence rule), hardware-only filtering, Tier
 * 1/2 split reporting, and the --limit option.
 *
 * @see app/Console/Commands/StencilsCoverageReportCommand.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-08-PLAN.md
 */
class StencilsCoverageReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makePackage(array $equipment): ProjectPackage
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => ['equipment' => $equipment],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D-15 independence — never reads the seed pack
    // ─────────────────────────────────────────────────────────────────────────

    public function test_command_class_never_reads_the_seed_pack_directory(): void
    {
        $source = File::get(app_path('Console/Commands/StencilsCoverageReportCommand.php'));

        $this->assertStringNotContainsString('device-stencils-seed', $source);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Frequency ranking
    // ─────────────────────────────────────────────────────────────────────────

    public function test_more_frequent_part_number_ranks_above_less_frequent_one(): void
    {
        // X appears in 3 packages, Y appears in 1.
        for ($i = 0; $i < 3; $i++) {
            $this->makePackage([
                ['part_number' => 'X-DISPLAY-1', 'name' => 'Sony FW-85BZ40L Display', 'category' => null],
            ]);
        }
        $this->makePackage([
            ['part_number' => 'Y-SWITCH-1', 'name' => 'Netgear GS312TP PoE Switch', 'category' => null],
        ]);

        Artisan::call('stencils:coverage-report');
        $output = Artisan::output();

        // Table row order reflects the ranking — X (3 occurrences) must
        // appear before Y (1 occurrence) in the rendered output.
        $xPos = strpos($output, strtolower('X-DISPLAY-1'));
        $yPos = strpos($output, strtolower('Y-SWITCH-1'));

        $this->assertNotFalse($xPos, 'Expected X-DISPLAY-1 in report output.');
        $this->assertNotFalse($yPos, 'Expected Y-SWITCH-1 in report output.');
        $this->assertLessThan($yPos, $xPos, 'X-DISPLAY-1 (3 occurrences) should rank above Y-SWITCH-1 (1 occurrence).');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hardware-only filtering
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cable_and_service_only_part_numbers_never_appear_in_the_ranking(): void
    {
        $this->makePackage([
            ['part_number' => 'CAB-HDMI-3M', 'name' => 'HDMI Cable 3m', 'category' => null],
            ['part_number' => 'PROGRAMMING1', 'name' => 'Crestron programming day rate', 'category' => null],
            ['part_number' => 'HW-REAL-1', 'name' => 'Sony FW-85BZ40L Display', 'category' => null],
        ]);

        Artisan::call('stencils:coverage-report');
        $output = Artisan::output();

        $this->assertStringNotContainsString(strtolower('CAB-HDMI-3M'), $output);
        $this->assertStringNotContainsString(strtolower('PROGRAMMING1'), $output);
        $this->assertStringContainsString(strtolower('HW-REAL-1'), $output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --limit option
    // ─────────────────────────────────────────────────────────────────────────

    public function test_limit_option_returns_at_most_that_many_rows(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->makePackage([
                ['part_number' => "HW-{$i}", 'name' => "Sony FW-85BZ40L Display Unit {$i}", 'category' => null],
            ]);
        }

        $this->artisan('stencils:coverage-report', ['--limit' => 5])
            ->expectsOutputToContain('5 of the top 5 part_number(s)')
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tier 1 / Tier 2 split
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reports_tier_2_for_engineer_curated_stencil_and_tier_1_otherwise(): void
    {
        $this->makePackage([
            ['part_number' => 'CURATED-1', 'name' => 'Sony FW-85BZ40L Display', 'category' => null],
            ['part_number' => 'UNCATALOGUED-1', 'name' => 'Netgear GS312TP PoE Switch', 'category' => null],
        ]);

        DeviceStencil::create([
            'part_number'    => DeviceStencil::normalisePartNumber('CURATED-1'),
            'manufacturer'   => 'Sony',
            'model'          => 'FW-85BZ40L',
            'display_name'   => 'Sony FW-85BZ40L Display',
            'mxgraph_xml'    => '<shape/>',
            'default_width'  => 220,
            'default_height' => 140,
            'source'         => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'needs_review'   => false,
        ]);

        $this->artisan('stencils:coverage-report')
            ->expectsOutputToContain('1 of the top 2 part_number(s)')
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Clean state
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_packages_reports_cleanly(): void
    {
        $this->artisan('stencils:coverage-report')
            ->expectsOutputToContain('No packages with extracted_data found')
            ->assertSuccessful();
    }
}

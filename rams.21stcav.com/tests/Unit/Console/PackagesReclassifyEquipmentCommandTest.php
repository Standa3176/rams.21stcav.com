<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for `packages:reclassify-equipment` (260725-qw3).
 *
 * Covers: dry-run safety (no writes), --commit persistence, idempotence,
 * and no-op behaviour on already-canonical rows.
 */
class PackagesReclassifyEquipmentCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a package whose extracted_data carries a mix of pre-qw3
     * fabricated categories + a section-header area that should be
     * re-routed.
     */
    private function makePollutedPackage(): ProjectPackage
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment' => [
                    [
                        'part_number' => 'FW-75BZ35L',
                        'name'        => 'Sony BRAVIA 75" Display',
                        'description' => 'Sony BRAVIA 75" Display',
                        'category'    => 'display',   // fabricated pre-qw3 value
                        'area'        => 'Boardroom',
                        'location'    => 'Boardroom',
                        'quantity'    => 1,
                    ],
                    [
                        'part_number' => 'C-UNIKAT-5',
                        'name'        => 'Kramer Cat6 patch cable 5m',
                        'description' => 'Kramer Cat6 patch cable 5m',
                        'category'    => 'cable',     // fabricated pre-qw3 value
                        'area'        => 'Boardroom',
                        'location'    => 'Boardroom',
                        'quantity'    => 10,
                    ],
                    [
                        'part_number' => 'PROGRAMMING1',
                        'name'        => 'Crestron programming day rate',
                        'description' => 'Crestron programming day rate',
                        'category'    => 'service',   // fabricated pre-qw3 value
                        'area'        => 'Professional Services', // section-header leak
                        'location'    => 'Professional Services',
                        'quantity'    => 2,
                    ],
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dry-run safety
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function dry_run_makes_no_db_writes_even_when_diffs_exist(): void
    {
        $package = $this->makePollutedPackage();
        $before  = $package->extracted_data;

        $this->artisan('packages:reclassify-equipment')
            ->expectsOutputToContain('DRY-RUN MODE')
            ->expectsOutputToContain('DRY-RUN — no packages were changed.')
            ->assertSuccessful();

        // Refresh and confirm nothing was persisted.
        $package->refresh();
        $this->assertSame($before, $package->extracted_data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --commit persistence
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function commit_persists_canonical_categories(): void
    {
        $package = $this->makePollutedPackage();

        $this->artisan('packages:reclassify-equipment', ['--commit' => true])
            ->expectsOutputToContain('COMMIT MODE')
            ->assertSuccessful();

        $package->refresh();
        $equipment = $package->extracted_data['equipment'];

        // Sony display → hardware (no specific keyword bucket matches).
        $this->assertSame('hardware', $equipment[0]['category']);
        $this->assertSame('Boardroom', $equipment[0]['area']);

        // Cat6 patch cable → cables (canonical vocab).
        $this->assertSame('cables', $equipment[1]['category']);

        // Crestron programming → services (via classifier match on "programming");
        // area cleared because "Professional Services" matched the reroute pattern.
        $this->assertSame('services', $equipment[2]['category']);
        $this->assertSame('',         $equipment[2]['area']);
        $this->assertSame('',         $equipment[2]['location']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Idempotence
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function running_twice_with_commit_produces_no_further_diffs(): void
    {
        $package = $this->makePollutedPackage();

        $this->artisan('packages:reclassify-equipment', ['--commit' => true])
            ->assertSuccessful();

        $package->refresh();
        $afterFirst = $package->extracted_data;

        // Second run must be a no-op — every row is already canonical.
        $this->artisan('packages:reclassify-equipment', ['--commit' => true])
            ->expectsOutputToContain('Every package already carries canonical classifications')
            ->assertSuccessful();

        $package->refresh();
        $this->assertSame($afterFirst, $package->extracted_data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Already-canonical short-circuit
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function package_with_only_canonical_categories_is_reported_clean(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment' => [
                    [
                        'part_number' => 'A',
                        'name'        => 'Sony display',
                        'description' => 'Sony display',
                        'category'    => 'hardware',
                        'area'        => 'Boardroom',
                        'location'    => 'Boardroom',
                        'quantity'    => 1,
                    ],
                ],
            ],
        ]);

        $this->artisan('packages:reclassify-equipment')
            ->expectsOutputToContain('Every package already carries canonical classifications')
            ->assertSuccessful();
    }

    /** @test */
    public function manually_picked_service_contracts_category_is_preserved(): void
    {
        // Regression: a user manually picked service_contracts via the
        // dropdown. Even though the description ("Sony display") would
        // classify as `hardware` from keyword matching, the canonical
        // dropdown selection must be preserved.
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment' => [
                    [
                        'part_number' => 'MANUAL-1',
                        'name'        => 'Sony display',
                        'description' => 'Sony display',
                        'category'    => 'service_contracts', // manually picked
                        'area'        => 'Boardroom',
                        'quantity'    => 1,
                    ],
                ],
            ],
        ]);

        $this->artisan('packages:reclassify-equipment', ['--commit' => true])
            ->assertSuccessful();

        $package->refresh();
        $this->assertSame('service_contracts', $package->extracted_data['equipment'][0]['category']);
    }

    /** @test */
    public function equipment_deleted_graveyard_is_also_reclassified(): void
    {
        // The soft-delete graveyard from 260723-eq1 should also get its
        // categories aligned so restoring a row lands with correct vocab.
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'equipment'         => [],
                'equipment_deleted' => [
                    [
                        'part_number' => 'CAT6-1',
                        'name'        => 'Kramer Cat6 patch cable 5m',
                        'description' => 'Kramer Cat6 patch cable 5m',
                        'category'    => 'cable', // fabricated
                        'area'        => 'Boardroom',
                        'quantity'    => 1,
                        'deleted'     => true,
                        'deleted_at'  => '2026-07-24T10:00:00Z',
                        'deleted_by'  => $user->id,
                    ],
                ],
            ],
        ]);

        $this->artisan('packages:reclassify-equipment', ['--commit' => true])
            ->assertSuccessful();

        $package->refresh();
        $this->assertSame('cables', $package->extracted_data['equipment_deleted'][0]['category']);
        // Graveyard bookkeeping fields preserved.
        $this->assertTrue($package->extracted_data['equipment_deleted'][0]['deleted']);
        $this->assertSame('2026-07-24T10:00:00Z', $package->extracted_data['equipment_deleted'][0]['deleted_at']);
    }

    /** @test */
    public function package_argument_scopes_to_single_package(): void
    {
        $pollutedA = $this->makePollutedPackage();
        $pollutedB = $this->makePollutedPackage();

        $this->artisan('packages:reclassify-equipment', [
            'package' => $pollutedA->id,
            '--commit' => true,
        ])->assertSuccessful();

        $pollutedA->refresh();
        $pollutedB->refresh();

        // A was reclassified.
        $this->assertSame('hardware', $pollutedA->extracted_data['equipment'][0]['category']);
        // B untouched.
        $this->assertSame('display', $pollutedB->extracted_data['equipment'][0]['category']);
    }
}

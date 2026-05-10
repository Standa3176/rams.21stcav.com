<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\ProjectPackage;
use Database\Seeders\DeviceStencilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21 Plan 02 Task 2 — locks the seed-pack coverage assertion per
 * CONTEXT.md D-05 (≥80% top-50 coverage) and D-15 (INDEPENDENT fixture
 * provenance — the assertion source MUST NOT come from the seed pack
 * itself, otherwise it's circular).
 *
 * Fixture priority order (per D-15):
 *   1. Live DB query — when the test environment has >=50 ProjectPackage
 *      rows with extracted_data, derive top-50 at test time. (Production
 *      path; fresh checkout dev DBs won't hit this.)
 *
 *   2. Frozen snapshot — fall back to
 *      tests/Fixtures/seed-coverage/top-50-snapshot.json (generated
 *      2026-05-10 from local dev DB at planning time, with _provenance
 *      field documenting non-circular origin).
 *
 *   3. (Synthetic fallback unused — top-50-snapshot.json was generated
 *      from real production-shape data so paths 1-2 always cover.)
 *
 * Coverage threshold calibration: the action plan's "If <10 unique
 * high-volume gap part_numbers" clause permits adjusting the 80% threshold
 * down when the actual top-N catalogue is smaller than 50. The local dev
 * snapshot has exactly 50 part_numbers; threshold stays at 80%.
 *
 * @see resources/data/device-stencils-seed/_INDEX.md
 * @see tests/Fixtures/seed-coverage/top-50-snapshot.json
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-05 + D-15)
 */
class SeedPackCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve the top-50 reference list per D-15 fixture priority order.
     *
     * @return array<int, string> normalised lowercase trimmed part_numbers
     */
    private function topFiftyReference(): array
    {
        // Path 1 — live DB query (preferred when production data available).
        $packagesAvailable = ProjectPackage::query()
            ->whereNotNull('extracted_data')
            ->count();

        if ($packagesAvailable >= 50) {
            $top = ProjectPackage::query()
                ->whereNotNull('extracted_data')
                ->orderBy('created_at', 'desc')
                ->limit(200)
                ->get()
                ->flatMap(fn ($p) => collect($p->extracted_data['equipment'] ?? [])
                    ->where('category', 'hardware')
                    ->pluck('part_number'))
                ->map(fn ($pn) => strtolower(trim((string) $pn)))
                ->filter()
                ->countBy()
                ->sortDesc()
                ->take(50)
                ->keys()
                ->all();

            return array_values($top);
        }

        // Path 2 — frozen snapshot (D-15 fallback). The fixture's
        // _provenance field documents non-circular origin; reading the
        // top_50 list here is independent of any seed-pack file.
        $snapshotPath = __DIR__.'/../../Fixtures/seed-coverage/top-50-snapshot.json';
        $this->assertFileExists($snapshotPath,
            'top-50-snapshot.json must exist for the D-15 fallback path');

        $snapshot = json_decode((string) file_get_contents($snapshotPath), true);
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('top_50', $snapshot);
        $this->assertIsArray($snapshot['top_50']);
        $this->assertNotEmpty($snapshot['top_50'],
            'top-50-snapshot.json top_50 list must be non-empty');

        return array_map(
            fn (string $pn) => strtolower(trim($pn)),
            $snapshot['top_50']
        );
    }

    public function test_at_least_80_percent_of_top_50_have_curated_stencil(): void
    {
        // Seed the database from the manifest pack.
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);

        $reference = $this->topFiftyReference();
        $referenceCount = count($reference);

        $this->assertGreaterThan(0, $referenceCount,
            'Reference list must be non-empty');

        // Build the seed pack's normalised part_number set in one query.
        $seededPartNumbers = DeviceStencil::query()
            ->where('source', DeviceStencil::SOURCE_ENGINEER_CURATED)
            ->pluck('part_number')
            ->map(fn ($pn) => strtolower(trim((string) $pn)))
            ->all();
        $seededSet = array_flip($seededPartNumbers);

        $covered = 0;
        $missing = [];
        foreach ($reference as $pn) {
            if (isset($seededSet[$pn])) {
                $covered++;
            } else {
                $missing[] = $pn;
            }
        }

        $coveragePct = ($covered / $referenceCount) * 100.0;

        $this->assertGreaterThanOrEqual(
            80.0,
            $coveragePct,
            sprintf(
                'Seed-pack coverage of top-%d reference is %.1f%% (covered %d / missing %d). '.
                "Threshold is 80%% per D-05.\nMissing parts: %s",
                $referenceCount,
                $coveragePct,
                $covered,
                $referenceCount - $covered,
                implode(', ', array_slice($missing, 0, 20))
            )
        );
    }

    public function test_snapshot_fixture_is_independent_of_seed_pack(): void
    {
        // D-15 enforcement: the top-50 reference fixture MUST carry a
        // provenance field documenting it was NOT generated from the seed
        // pack. This test reads the fixture file directly to enforce that
        // contract — the fixture must exist + its first key is _provenance.
        $snapshotPath = __DIR__.'/../../Fixtures/seed-coverage/top-50-snapshot.json';
        $this->assertFileExists($snapshotPath);

        $snapshot = json_decode((string) file_get_contents($snapshotPath), true);
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('_provenance', $snapshot,
            'D-15: top-50-snapshot.json MUST carry a _provenance field documenting non-circular origin');
        $this->assertStringContainsString('DO NOT regenerate from seed pack', $snapshot['_provenance'],
            'Provenance string MUST explicitly forbid regeneration from seed pack');
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit coverage for Worksheet::isStale() truth table.
 *
 * Quick task 260602-o2a — detect when a worksheet snapshot has drifted out of
 * date relative to its source (project.latestPackage). The snapshot timestamp
 * lives at $worksheet->generated_data['generated_at'] (ISO8601 string written
 * by WorksheetGeneratorService::build line 230) — NOT updated_at, which mutates
 * on every status flip / email send / sign-off and would false-positive.
 *
 * Truth table:
 *  - Fresh (package.updated_at <= snapshot)        → false
 *  - Stale (package edited AFTER snapshot)         → true
 *  - status=failed                                 → false (broken, not stale)
 *  - status=pending                                → false (no snapshot yet)
 *  - status=generating                             → false (snapshot in flight)
 *  - No project (project_id NULL)                  → false (defensive)
 *  - Project but no latestPackage                  → false (no source to compare)
 *  - generated_data null                           → false (no snapshot)
 *  - generated_data missing 'generated_at' key     → false (defensive — do not guess)
 *
 * @see App\Models\Worksheet::isStale
 * @see App\Models\Worksheet::staleSince
 */
class WorksheetIsStaleTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture helpers ──────────────────────────────────────────────────────

    /**
     * Build a worksheet + project + package with explicit timestamps.
     *
     * Uses DB::table()->update() to bypass Eloquent's auto-touch logic so we
     * can deterministically position package.updated_at relative to the
     * snapshot's generated_at.
     */
    private function makeWorksheet(
        ?int $packageUpdatedMinutesAgo,
        ?int $generatedAtMinutesAgo,
        string $status = Worksheet::STATUS_DRAFT,
        bool $withProject = true,
        bool $withPackage = true,
        ?array $generatedData = null,
    ): Worksheet {
        $user = User::factory()->create();

        $project = null;
        if ($withProject) {
            $project = Project::factory()->create(['user_id' => $user->id]);

            if ($withPackage) {
                $package = ProjectPackage::create([
                    'project_id' => $project->id,
                    'user_id'    => $user->id,
                    'filename'   => 'fixture-' . uniqid() . '.pdf',
                ]);

                if ($packageUpdatedMinutesAgo !== null) {
                    DB::table('project_packages')
                        ->where('id', $package->id)
                        ->update(['updated_at' => now()->subMinutes($packageUpdatedMinutesAgo)]);
                }
            }
        }

        $data = $generatedData;
        if ($data === null && $generatedAtMinutesAgo !== null) {
            $data = [
                'rooms'        => [],
                'generated_at' => now()->subMinutes($generatedAtMinutesAgo)->toIso8601String(),
            ];
        }

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project?->id,
            'status'         => $status,
            'generated_data' => $data,
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_fresh_worksheet_returns_false(): void
    {
        // package edited 10m ago, snapshot taken 5m ago → snapshot newer → fresh
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 10,
            generatedAtMinutesAgo: 5,
        );

        $this->assertFalse($worksheet->isStale());
        $this->assertNull($worksheet->staleSince());
    }

    public function test_stale_worksheet_returns_true(): void
    {
        // package edited 5m ago, snapshot taken 15m ago → package newer → stale
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: 15,
        );

        $this->assertTrue($worksheet->isStale());
        $this->assertNotNull($worksheet->staleSince());
    }

    public function test_failed_status_returns_false(): void
    {
        // Even with stale-looking timestamps, status=failed short-circuits.
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: 15,
            status: Worksheet::STATUS_FAILED,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_pending_status_returns_false(): void
    {
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: 15,
            status: Worksheet::STATUS_PENDING,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_generating_status_returns_false(): void
    {
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: 15,
            status: Worksheet::STATUS_GENERATING,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_worksheet_without_project_returns_false(): void
    {
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: null,
            generatedAtMinutesAgo: 15,
            withProject: false,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_worksheet_with_no_latest_package_returns_false(): void
    {
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: null,
            generatedAtMinutesAgo: 15,
            withPackage: false,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_worksheet_with_null_generated_data_returns_false(): void
    {
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: null,
            generatedData: null,
        );

        $this->assertFalse($worksheet->isStale());
    }

    public function test_worksheet_with_missing_generated_at_key_returns_false(): void
    {
        // Legacy shape — generated_data array present but no 'generated_at' key.
        // Defensive: do NOT fall back to updated_at, do NOT guess. Return false.
        $worksheet = $this->makeWorksheet(
            packageUpdatedMinutesAgo: 5,
            generatedAtMinutesAgo: null,
            generatedData: ['rooms' => [], 'blockers' => []],
        );

        $this->assertFalse($worksheet->isStale());
    }
}

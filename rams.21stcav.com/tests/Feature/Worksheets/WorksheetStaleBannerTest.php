<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature coverage for the stale-data banner (quick task 260602-o2a).
 *
 * Scenarios:
 *  1. Admin show — stale     → banner + Regenerate button visible
 *  2. Admin show — fresh     → banner NOT visible
 *  3. Public engineer link — stale → informational copy, NO form to retry-generation
 *  4. Post-regen disappearance — bump generated_at, banner gone
 *  5. Regression canary — fresh worksheet show still renders cleanly (200 + title)
 *
 * @see resources/views/worksheets/_stale-banner.blade.php
 * @see App\Models\Worksheet::isStale
 */
class WorksheetStaleBannerTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture helpers ──────────────────────────────────────────────────────

    /**
     * Build a project + package + draft worksheet with explicit timestamps so
     * isStale() returns true (package edited after snapshot).
     *
     * @param  int  $minutesAgoGeneratedAt  How long ago the worksheet snapshot was taken.
     * @param  int  $minutesAgoPackageUpdate  How long ago the package was last edited.
     */
    private function makeProjectWithStaleWorksheet(
        int $minutesAgoGeneratedAt = 15,
        int $minutesAgoPackageUpdate = 5,
    ): Worksheet {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $package = ProjectPackage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'filename'   => 'fixture-' . uniqid() . '.pdf',
        ]);
        DB::table('project_packages')
            ->where('id', $package->id)
            ->update(['updated_at' => now()->subMinutes($minutesAgoPackageUpdate)]);

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'        => 'Room A',
                        'is_surveyed' => true,
                        'equipment'   => [],
                    ],
                ],
                'generated_at' => now()->subMinutes($minutesAgoGeneratedAt)->toIso8601String(),
            ],
        ]);
    }

    private function makeFreshWorksheet(): Worksheet
    {
        // package edited 30m ago, snapshot taken 5m ago — snapshot newer → fresh
        return $this->makeProjectWithStaleWorksheet(
            minutesAgoGeneratedAt: 5,
            minutesAgoPackageUpdate: 30,
        );
    }

    // ── Scenarios ────────────────────────────────────────────────────────────

    public function test_admin_show_renders_banner_and_regenerate_button_when_stale(): void
    {
        $worksheet = $this->makeProjectWithStaleWorksheet();

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertSee('Project data was updated', false);
        // The banner's Regenerate form posts to the retry-generation route.
        $response->assertSee(route('worksheets.retry-generation', $worksheet), false);
    }

    public function test_admin_show_does_not_render_banner_when_fresh(): void
    {
        $worksheet = $this->makeFreshWorksheet();

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertDontSee('Project data was updated', false);
    }

    public function test_public_engineer_link_renders_informational_banner_without_form(): void
    {
        $worksheet = $this->makeProjectWithStaleWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertSee('Project data has been updated since this worksheet was generated', false);
        // Public banner has NO form to the admin retry-generation route.
        $response->assertDontSee(route('worksheets.retry-generation', $worksheet), false);
    }

    public function test_banner_disappears_after_simulated_regeneration(): void
    {
        $worksheet = $this->makeProjectWithStaleWorksheet();

        // Confirm banner currently visible.
        $first = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));
        $first->assertSee('Project data was updated', false);

        // Simulate regen — mirrors BuildWorksheetJob::handle line 97 without
        // invoking the queue: update generated_data with a fresh generated_at.
        $worksheet->update([
            'generated_data' => array_merge($worksheet->generated_data, [
                'generated_at' => now()->toIso8601String(),
            ]),
        ]);

        $second = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));
        $second->assertOk();
        $second->assertDontSee('Project data was updated', false);
    }

    public function test_isStale_does_not_break_existing_worksheet_show_view_parity(): void
    {
        // Regression canary — a fresh (non-stale) worksheet must still render
        // the admin show page cleanly. Catches a partial-template syntax error
        // that would otherwise 500 the page.
        $worksheet = $this->makeFreshWorksheet();

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertSee('Worksheet:', false);
    }
}

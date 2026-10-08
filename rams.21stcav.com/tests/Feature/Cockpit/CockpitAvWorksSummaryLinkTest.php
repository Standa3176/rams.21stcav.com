<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 261008-gxt Task 3 — the "AV Works Summary" editor (project-packages review
 * page, Room/Space Overviews section) was unreachable from the cockpit.
 *
 * Asserted against RENDERED HTML content, never Route::has() — the plan's
 * own framing warns that Route::has() was already true for all three
 * previously-orphaned report links and is exactly why nobody noticed they
 * were unreachable.
 */
class CockpitAvWorksSummaryLinkTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Cockpit Link Test Job',
            'site_address' => '3 Test Street',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function render(Project $project): string
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('projects.cockpit', $project))
            ->assertOk()
            ->getContent();
    }

    // ── Test A: the link is RENDERED, href resolved from the fixture's own package id ──

    public function test_cockpit_renders_a_real_link_to_the_av_works_summary_editor(): void
    {
        config(['cockpit.enabled' => true]);

        $project = $this->project();
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => User::factory()->create()->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $html = $this->render($project);

        $expectedHref = route('project-packages.review.show', $package);

        $this->assertStringContainsString(
            'href="'.$expectedHref.'#s-room-overviews"',
            $html,
            'Expected a real rendered link to the package review page, anchored at #s-room-overviews.'
        );
        $this->assertStringContainsString('AV Works Summary', $html);
    }

    // ── Test B (non-vacuity): a package-less project degrades safely ───────

    public function test_a_project_without_a_package_renders_without_the_link_and_without_error(): void
    {
        config(['cockpit.enabled' => true]);

        $project = $this->project();
        $this->assertNull($project->latestPackage, 'Fixture sanity: this project must have no package.');

        $html = $this->render($project);

        $this->assertStringNotContainsString('AV Works Summary', $html);
        $this->assertStringNotContainsString('s-room-overviews', $html);
    }
}

<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the reconciled project tab strip (260822-04, D-04/D-07/D-08/D-09).
 *
 * Verifies:
 *   - Drawings and Snagging render as real tabs — they had none before this phase
 *   - Programming never has a tab-strip entry (D-05 — flag only, no tab, no
 *     generator). 260822-07 (D-10) gives it a legitimate, REQUIRED appearance
 *     elsewhere on the same page — as one of the 9 selectable rows in the
 *     Project Data tab's new deliverables edit form (D-04's "nine selectable
 *     deliverables" includes Programming by name) — so this assertion is
 *     scoped to the tab-strip markup specifically, not the whole page.
 *   - A not-required-and-empty deliverable's tab is muted and grouped under
 *     "Not required", never hidden (D-08)
 *   - A not-required deliverable that ALREADY holds data renders exactly like
 *     a normal populated tab — never muted, never moved (D-09)
 */
class ProjectTabStripTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawings_and_snagging_render_as_tabs(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Drawings')
            ->assertSee('Snagging');
    }

    public function test_programming_never_appears_as_a_tab(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        // Narrowed to the exact tab-label markup the render loop emits
        // (show.blade.php: <span class="ws-tab__label">{{ $t['label'] }}</span>)
        // — a bare assertDontSee('Programming') would now false-fail against
        // 260822-07's deliverables edit form, which legitimately renders the
        // word "Programming" as a form-row label, not a tab.
        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('ws-tab__label">Programming', false);
    }

    public function test_not_required_empty_deliverable_renders_muted_and_grouped(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        // D-08: muted class applied to a tab button + the "Not required"
        // grouping divider both present. Assert the actual rendered class
        // attribute, not the bare string — the CSS rule for .ws-tab--muted
        // in the page's own <style> block would otherwise make this a
        // false positive on every render, muted or not.
        $response->assertSee('class="ws-tab ws-tab--muted"', false);
        $response->assertSee('<span class="ws-tabs__divider"', false);
        $response->assertSee('Not required', false);
        $response->assertSee('Add anyway', false);
    }

    public function test_not_required_deliverable_holding_data_never_muted(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $owner,
        );

        // RAMS holds a document even though it is flagged Not required —
        // D-09 says this must render exactly like a normal populated tab.
        RamsDocument::create([
            'user_id'      => $owner->id,
            'project_id'   => $project->id,
            'project_ref'  => 'D09-TEST',
            'project_name' => $project->name,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet',
            'form_data'    => ['source' => 'test'],
            'status'       => RamsDocument::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        // No muted group at all — a populated deliverable never qualifies,
        // regardless of its flag (D-09), so the whole muted-group markup
        // (including the divider) must be absent. Checked against the
        // rendered class attribute / tag, not the bare class name, which
        // is always present in the page's own <style> block.
        $response->assertDontSee('class="ws-tab ws-tab--muted"', false);
        $response->assertDontSee('<span class="ws-tabs__divider"', false);
    }
}

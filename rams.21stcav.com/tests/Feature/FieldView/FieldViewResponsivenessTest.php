<?php

namespace Tests\Feature\FieldView;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-03a mobile-first heuristics + INST-03h no-service-worker.
 * Not a full browser test — we assert the rendered HTML does NOT contain
 * known anti-patterns (giant fixed widths, service worker registration).
 */
class FieldViewResponsivenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_does_not_register_a_service_worker(): void
    {
        [$user, $project] = $this->setupProject();

        $response = $this->actingAs($user)->get("/projects/{$project->id}/programme");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString(
            'navigator.serviceWorker',
            $html,
            'INST-03h: no service worker registration allowed',
        );
        $this->assertStringNotContainsString('serviceWorker.register', $html);
    }

    public function test_view_avoids_wide_fixed_pixel_widths(): void
    {
        [$user, $project] = $this->setupProject();

        $response = $this->actingAs($user)->get("/projects/{$project->id}/programme");

        $html = $response->getContent();
        // No Tailwind arbitrary width over 400px in the mobile-first layout
        $this->assertDoesNotMatchRegularExpression('/\bw-\[(?:[4-9]\d{2}|\d{4,})px\]/', $html);
        // No min-w-[1024 or similar desktop-first clamp
        $this->assertStringNotContainsString('min-w-[1024', $html);
    }

    public function test_view_contains_required_ui_spec_markers(): void
    {
        // From 14-UI-SPEC.md — layout skeleton + data-testid hooks executor must emit
        [$user, $project] = $this->setupProject();

        $response = $this->actingAs($user)->get("/projects/{$project->id}/programme");

        $html = $response->getContent();
        $this->assertStringContainsString(
            'data-testid="task-row"',
            $html,
            'UI-SPEC component inventory requires task-row testid',
        );
    }

    private function setupProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        InstallTask::factory()->create(['install_programme_id' => $programme->id]);

        return [$user, $project];
    }
}

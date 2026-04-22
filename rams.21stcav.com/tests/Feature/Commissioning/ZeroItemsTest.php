<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D-13 — a programme with zero commissioning_items can still be signed off
 * (e.g., cable-only jobs). Project advances; snagging PDF shows empty-state
 * copy.
 *
 * Red until Plan 04 ships the finalise endpoint that accepts zero items and
 * Plan 03 ships the empty-state view.
 */
class ZeroItemsTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_empty_programme_signoff_succeeds(): void
    {
        Storage::fake('documents');

        [$user, $programme] = $this->scaffoldProgramme();

        $this->assertSame(0, CommissioningItem::where('install_programme_id', $programme->id)->count());

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Alice',
                'client_role'          => 'IT',
                'client_company'       => 'Acme',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertOk();
        $this->assertSame(Project::STATUS_COMMISSIONING, $programme->project->fresh()->status);
        $this->assertSame(1, CommissioningSignoff::where('install_programme_id', $programme->id)->count());
    }

    public function test_checklist_view_shows_empty_state_when_no_items(): void
    {
        [$user, $programme] = $this->scaffoldProgramme();

        $response = $this->actingAs($user)->get("/projects/{$programme->project_id}/commissioning");

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('No commissioning items', $content);
        // Button should be unlocked (not disabled) when zero items
        $this->assertStringContainsString('Complete Commissioning', $content);
    }

    /**
     * @return array{0: User, 1: InstallProgramme}
     */
    private function scaffoldProgramme(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        return [$user, $programme];
    }
}

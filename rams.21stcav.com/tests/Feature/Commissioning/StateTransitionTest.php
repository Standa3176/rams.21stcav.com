<?php

namespace Tests\Feature\Commissioning;

use App\Exceptions\CommissioningSignoffException;
use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * INST-05h — state-machine gating on signoff finalise.
 *
 * Project must transition STATUS_INSTALLING → STATUS_COMMISSIONING.
 * Invalid source states (e.g. ENGINEERING) must be refused with 422.
 *
 * Red until Plan 04 ships CommissioningService + canTransitionTo gate.
 */
class StateTransitionTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_signoff_advances_project_to_commissioning(): void
    {
        Storage::fake('documents');

        [$user, $programme] = $this->scaffoldProgramme(Project::STATUS_INSTALLING);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Alice',
                'client_role'          => 'IT',
                'client_company'       => 'Acme',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ])
            ->assertOk();

        $project = $programme->project->fresh();
        $this->assertSame(Project::STATUS_COMMISSIONING, $project->status);
        $this->assertNotNull($project->commissioning_started_at);
    }

    public function test_signoff_advances_programme_to_complete(): void
    {
        Storage::fake('documents');

        [$user, $programme] = $this->scaffoldProgramme(Project::STATUS_INSTALLING);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'A',
                'client_role'          => 'B',
                'client_company'       => 'C',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ])
            ->assertOk();

        $this->assertSame(InstallProgramme::STATUS_COMPLETE, $programme->fresh()->status);
    }

    public function test_signoff_from_invalid_source_state_returns_422(): void
    {
        [$user, $programme] = $this->scaffoldProgramme(Project::STATUS_ENGINEERING);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'A',
                'client_role'          => 'B',
                'client_company'       => 'C',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertStatus(422);
    }

    public function test_canTransitionTo_guard_called(): void
    {
        // Any source state that CANNOT transition to STATUS_COMMISSIONING
        // (e.g., STATUS_QUOTE_IMPORTED) must be refused before any write.
        [$user, $programme] = $this->scaffoldProgramme(Project::STATUS_QUOTE_IMPORTED);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'A',
                'client_role'          => 'B',
                'client_company'       => 'C',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ])
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: InstallProgramme}
     */
    private function scaffoldProgramme(string $projectStatus): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => $projectStatus,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        return [$user, $programme];
    }
}

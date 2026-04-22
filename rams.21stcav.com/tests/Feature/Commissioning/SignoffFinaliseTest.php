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
 * INST-05g — happy path for POST /install-programmes/{programme}/commissioning/signoff/finalise.
 *
 * Red until Plan 04 ships CommissioningService::finalise + controller + route.
 */
class SignoffFinaliseTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_finalise_with_all_items_complete_succeeds(): void
    {
        Storage::fake('documents');
        [$user, $programme] = $this->scaffoldProgramme();

        // Seed 3 items across pass/fail/na all complete
        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);
        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_FAIL,
            'notes'                => 'faulty cable',
            'evidence_photo_path'  => 'commissioning-evidence/f.jpg',
        ]);
        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_NA,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Alice Client',
                'client_role'          => 'IT Manager',
                'client_company'       => 'Acme Ltd',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['final_pdf_url', 'project_status']);
        $this->assertSame(Project::STATUS_COMMISSIONING, $response->json('project_status'));
    }

    public function test_finalise_blocked_by_pending_items_returns_422(): void
    {
        [$user, $programme] = $this->scaffoldProgramme();

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Alice',
                'client_role'          => 'IT',
                'client_company'       => 'Acme',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('pending', (string) $response->json('message'));
    }

    public function test_finalise_creates_signoff_row_with_all_fields(): void
    {
        Storage::fake('documents');
        [$user, $programme] = $this->scaffoldProgramme();

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Bob Client',
                'client_role'          => 'Director',
                'client_company'       => 'Beta Ltd',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ])
            ->assertOk();

        $row = CommissioningSignoff::where('install_programme_id', $programme->id)->firstOrFail();
        $this->assertSame('Bob Client', $row->client_name);
        $this->assertSame('Director', $row->client_role);
        $this->assertSame('Beta Ltd', $row->client_company);
        $this->assertNotEmpty($row->signature_png_base64);
        $this->assertNotEmpty($row->certification_text);
        $this->assertNotEmpty($row->snagging_pdf_path);
        $this->assertNotNull($row->signed_at);
        $this->assertSame($user->id, $row->signed_off_engineer_id);
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

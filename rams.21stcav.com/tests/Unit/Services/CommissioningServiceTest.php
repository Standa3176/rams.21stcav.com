<?php

namespace Tests\Unit\Services;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D-16 orchestration, Pitfall 5 (base64 sanitisation), D-15 (cert snapshot).
 * Red until Plan 04 ships CommissioningService::finalise + sanitiseBase64.
 */
class CommissioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_finalise_atomic_transaction_order(): void
    {
        // Orchestration smoke test — verifies that finalise inserts a signoff,
        // builds a PDF (updates snagging_pdf_path), updates the project, and
        // updates the programme — all observable side-effects in one call.
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgramme(Project::STATUS_INSTALLING);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAsUser();

        $signoff = app(CommissioningService::class)->finalise($programme, [
            'client_name'          => 'Alice',
            'client_role'          => 'IT',
            'client_company'       => 'Acme',
            'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
        ]);

        $this->assertInstanceOf(CommissioningSignoff::class, $signoff);
        $this->assertNotSame('pending', $signoff->snagging_pdf_path);
        $this->assertSame(Project::STATUS_COMMISSIONING, $programme->project->fresh()->status);
        $this->assertSame(InstallProgramme::STATUS_COMPLETE, $programme->fresh()->status);
    }

    public function test_sanitiseBase64_strips_data_uri_prefix_and_whitespace(): void
    {
        // Pitfall 5
        $svc = app(CommissioningService::class);

        $raw = "data:image/png;base64,iVBO\n Rw0K\tGgo\r\nAAAA";
        $clean = $svc->sanitiseBase64($raw);

        $this->assertSame('iVBORw0KGgoAAAA', $clean);
    }

    public function test_finalise_snapshot_certification_text_from_config(): void
    {
        // D-15 — signoff row's certification_text must match config value
        // at sign time.
        Storage::fake('documents');
        config()->set('commissioning.certification_text', 'TEST-SNAPSHOT-XYZ');

        [$programme] = $this->scaffoldProgramme(Project::STATUS_INSTALLING);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $this->actingAsUser();

        $signoff = app(CommissioningService::class)->finalise($programme, [
            'client_name'          => 'Alice',
            'client_role'          => 'IT',
            'client_company'       => 'Acme',
            'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
        ]);

        $this->assertSame('TEST-SNAPSHOT-XYZ', $signoff->certification_text);
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgramme(string $status): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => $status,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        return [$programme];
    }

    private function actingAsUser(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
    }
}

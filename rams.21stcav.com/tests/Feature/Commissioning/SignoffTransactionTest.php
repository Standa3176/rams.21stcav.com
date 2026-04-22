<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * D-16 — atomicity of signoff DB::transaction.
 *
 * If ANY of: signoff insert, PDF write, Project state advance, Programme
 * state advance, fails — EVERY write must roll back.
 *
 * Red until Plan 04 ships CommissioningService::finalise with DB::transaction.
 */
class SignoffTransactionTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_pdf_failure_rolls_back_signoff_and_state(): void
    {
        Storage::fake('documents');

        [$user, $programme] = $this->scaffoldProgramme();

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        // Bind the PDF service to a throwing stub
        $this->app->bind(CommissioningPdfService::class, function () {
            return new class extends CommissioningPdfService
            {
                public function __construct()
                {
                    // do not call parent — skip dependencies
                }

                public function buildPreview(InstallProgramme $programme): string
                {
                    return 'preview-stub.pdf';
                }

                public function buildFinal(InstallProgramme $programme, CommissioningSignoff $signoff): string
                {
                    throw new RuntimeException('PDF-BOOM');
                }
            };
        });

        // Suppress the expected RuntimeException at the HTTP boundary —
        // the service's DB::transaction will rethrow. The test's goal is
        // to verify the state rollback, regardless of what the HTTP layer
        // returns (500 or re-thrown from the controller wrap).
        try {
            $this->actingAs($user)
                ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                    'client_name'          => 'Alice',
                    'client_role'          => 'IT',
                    'client_company'       => 'Acme',
                    'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
                ]);
        } catch (\Throwable $e) {
            // Swallowed — we care about DB state, not HTTP response.
        }

        // All four writes must have rolled back
        $this->assertSame(
            0,
            CommissioningSignoff::where('install_programme_id', $programme->id)->count(),
            'Signoff row must NOT exist after PDF failure (D-16 rollback).',
        );
        $this->assertSame(
            Project::STATUS_INSTALLING,
            $programme->project->fresh()->status,
            'Project state must stay STATUS_INSTALLING after PDF failure.',
        );
        $this->assertNotSame(
            InstallProgramme::STATUS_COMPLETE,
            $programme->fresh()->status,
            'Programme state must stay STATUS_ACTIVE after PDF failure.',
        );
    }

    public function test_state_transition_failure_rolls_back_signoff(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();
        // STATUS_ENGINEERING cannot transition to STATUS_COMMISSIONING (must go via INSTALLING)
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_ENGINEERING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Alice',
                'client_role'          => 'IT',
                'client_company'       => 'Acme',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertStatus(422);

        $this->assertSame(
            0,
            CommissioningSignoff::where('install_programme_id', $programme->id)->count(),
            'Invalid state transition must abort before signoff insert.',
        );
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

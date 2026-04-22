<?php

namespace Tests\Unit\Services;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningPdfService;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * INST-05g — CommissioningPdfService unit coverage. Preview + final PDFs,
 * DocumentArtifactStorage TYPE_SNAGGING writes, defensive DomPDF config,
 * memory-safe rendering for large programmes.
 *
 * Red until Plan 04 ships CommissioningPdfService.
 */
class CommissioningPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_preview_produces_file_with_non_zero_bytes(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        /** @var CommissioningPdfService $svc */
        $svc = app(CommissioningPdfService::class);
        $filename = $svc->buildPreview($programme);

        $path = Storage::disk('documents')->path(DocumentArtifactStorage::TYPE_SNAGGING . '/' . $filename);
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }

    public function test_build_final_embeds_signature_base64(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        $signoff = CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $svc = app(CommissioningPdfService::class);
        $filename = $svc->buildFinal($programme, $signoff);

        $path = Storage::disk('documents')->path(DocumentArtifactStorage::TYPE_SNAGGING . '/' . $filename);
        $bytes = (string) file_get_contents($path);

        // PDF should contain at least one /Image /XObject stream from the
        // embedded signature PNG.
        $this->assertStringContainsString('/Image', $bytes);
    }

    public function test_build_final_writes_to_snagging_disk(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        $signoff = CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $svc = app(CommissioningPdfService::class);
        $filename = $svc->buildFinal($programme, $signoff);

        Storage::disk('documents')->assertExists(DocumentArtifactStorage::TYPE_SNAGGING . '/' . $filename);
    }

    public function test_build_respects_data_uri_option(): void
    {
        // Pitfall 4 — even if deployment config narrows allowed_protocols,
        // the service must explicitly include data:// so signatures render.
        // We can't inspect DomPDF Options from outside — use a reflection
        // read on the service source for the marker.
        $source = file_get_contents(base_path('app/Services/CommissioningPdfService.php'));
        $this->assertStringContainsString("'data://'", (string) $source);
    }

    public function test_build_final_with_300_items_stays_under_256M_memory(): void
    {
        // Pitfall 9 — memory ceiling for large programmes
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        CommissioningItem::factory()->count(300)->create([
            'install_programme_id' => $programme->id,
        ]);

        $signoff = CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $before = memory_get_peak_usage(true);
        app(CommissioningPdfService::class)->buildFinal($programme, $signoff);
        $after = memory_get_peak_usage(true);

        $delta = $after - $before;
        $this->assertLessThan(
            256 * 1024 * 1024,
            $delta,
            sprintf('PDF render used %d bytes (>256MB) — regression vs Pitfall 9 budget.', $delta),
        );
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgrammeWithItems(): array
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

        CommissioningItem::factory()->count(2)->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        return [$programme];
    }
}

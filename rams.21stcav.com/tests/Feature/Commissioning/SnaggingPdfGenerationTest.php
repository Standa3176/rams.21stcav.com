<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningPdfService;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * INST-05g PDF generation — preview vs final, TYPE_SNAGGING writes,
 * filename convention, evidence-photo embedding (B-04 + D-14).
 *
 * Red until Plan 04 ships CommissioningPdfService + Blade template +
 * DocumentArtifactStorage::TYPE_SNAGGING.
 */
class SnaggingPdfGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_pdf_has_no_signature_block(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        /** @var CommissioningPdfService $service */
        $service = app(CommissioningPdfService::class);
        $filename = $service->buildPreview($programme);

        $artifacts = app(DocumentArtifactStorage::class);
        $path = $artifacts->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);

        $this->assertNotNull($path);
        $bytes = file_get_contents($path);
        $this->assertGreaterThan(0, strlen((string) $bytes));
        $this->assertStringNotContainsString('Client Sign-off', (string) $bytes);
    }

    public function test_final_pdf_embeds_signature_as_data_uri(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        $signoff = CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        /** @var CommissioningPdfService $service */
        $service = app(CommissioningPdfService::class);
        $filename = $service->buildFinal($programme, $signoff);

        $artifacts = app(DocumentArtifactStorage::class);
        $path = $artifacts->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);

        $this->assertNotNull($path);
        $bytes = (string) file_get_contents($path);

        // PDF must contain at least one embedded image stream. DomPDF writes
        // PNG images as /Image /XObject entries.
        $this->assertStringContainsString('/Image', $bytes);
    }

    public function test_pdf_writes_through_document_artifact_storage_type_snagging(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        /** @var CommissioningPdfService $service */
        $service = app(CommissioningPdfService::class);
        $filename = $service->buildPreview($programme);

        $expectedDir = Storage::disk('documents')->path(DocumentArtifactStorage::TYPE_SNAGGING);
        $this->assertFileExists($expectedDir . DIRECTORY_SEPARATOR . $filename);
    }

    public function test_pdf_filename_follows_convention(): void
    {
        Storage::fake('documents');
        [$programme] = $this->scaffoldProgrammeWithItems();

        $signoff = CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        /** @var CommissioningPdfService $service */
        $service = app(CommissioningPdfService::class);
        $filename = $service->buildFinal($programme, $signoff);

        // M-09 added a microsecond segment (Ymd_His -> Ymd_His_u) so
        // same-second retries cannot collide on filename. The regex must
        // accept that trailing _u segment.
        $this->assertMatchesRegularExpression(
            '/^snagging_programme_\d+_\d{8}_\d{6}_\d{6}_final\.pdf$/',
            $filename,
            'Filename must follow snagging_programme_{id}_{Ymd_His_u}_final.pdf (M-09)',
        );
    }

    public function test_pdf_embeds_evidence_photo_for_fail_item(): void
    {
        // B-04 + D-14 — assert the Blade embeds a data:image/jpeg;base64 URI
        // for a fail item with a real evidence photo on the fake disk.
        Storage::fake('local');

        [$programme] = $this->scaffoldProgrammeWithItems();

        // Write a real fixture JPEG under the evidence path
        $evidenceBytes = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $evidencePath = 'commissioning-evidence/test-item.jpg';
        Storage::disk('local')->put($evidencePath, $evidenceBytes);

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_FAIL,
            'notes'                => 'cable damage',
            'evidence_photo_path'  => $evidencePath,
        ]);

        $items = $programme->fresh()->commissioningItems()->get();
        $rooms = $items->groupBy('room_name');
        $fails = $items->where('status', CommissioningItem::STATUS_FAIL)->values();

        $html = View::make('pdf.commissioning-snagging', [
            'programme'      => $programme,
            'project'        => $programme->project,
            'items'          => $items,
            'rooms'          => $rooms,
            'fails'          => $fails,
            'signoff'        => null,
            'categoryLabels' => CommissioningItem::categoryLabels(),
        ])->render();

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);

        // The specific base64 fragment of the sample JPEG should appear
        $fragment = substr(base64_encode($evidenceBytes), 0, 80);
        $this->assertStringContainsString($fragment, $html);
    }

    public function test_pdf_renders_placeholder_when_evidence_photo_missing(): void
    {
        // B-04 — fail item with a non-null path but NO file on disk.
        Storage::fake('local');

        [$programme] = $this->scaffoldProgrammeWithItems();

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_FAIL,
            'notes'                => 'something',
            'evidence_photo_path'  => 'commissioning-evidence/gone.jpg',
        ]);

        $items = $programme->fresh()->commissioningItems()->get();
        $rooms = $items->groupBy('room_name');
        $fails = $items->where('status', CommissioningItem::STATUS_FAIL)->values();

        $html = View::make('pdf.commissioning-snagging', [
            'programme'      => $programme,
            'project'        => $programme->project,
            'items'          => $items,
            'rooms'          => $rooms,
            'fails'          => $fails,
            'signoff'        => null,
            'categoryLabels' => CommissioningItem::categoryLabels(),
        ])->render();

        $this->assertStringContainsString('(photo missing)', $html);
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

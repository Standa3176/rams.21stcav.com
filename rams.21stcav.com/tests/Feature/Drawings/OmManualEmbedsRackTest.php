<?php

namespace Tests\Feature\Drawings;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\OmManualDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 20 Plan 02 Task 1 — regression test that LOCKS the O&M Manual
 * Drawings section's rack-embed behaviour shipped end-to-end after Phase 17
 * Plan 03 (loop) + Phase 18 Plan 03 (rack-arm in DrawingExportRendererService::bladeViewFor).
 *
 * The OmManualDocxService::build loop at lines 154-213 is ALREADY kind-agnostic
 * (filters by status=READY + non-superseded; orders by kind). This test pins
 * that contract so a future v1.3.x change cannot silently regress to
 * schematic-only embedding.
 *
 * Strategy:
 *   - Bind a DrawingExportRendererService stub that returns paths to fixture
 *     PNGs (1x1 PNG written in setUp) — avoids Browsershot startup time.
 *   - Build the DOCX, open with ZipArchive, read word/document.xml as a string,
 *     assert the rack kindLabel ("Rack Elevation") AND schematic kindLabel
 *     ("System Schematic") both appear (kindLabel comes from
 *     ProjectDrawing::kindLabel()).
 *   - Assert TWO drawing image references appear in document.xml (one per
 *     drawing — PhpWord's addImage emits w:drawing blocks).
 *
 * @see app/Services/OmManualDocxService.php (lines 154-213 — Drawings section loop)
 * @see app/Services/Drawings/DrawingExportRendererService.php (ensurePngForHandover)
 * @see .planning/phases/20-drawing-export-pipeline-o-m-integration/20-02-production-hardening-om-rack-embed-PLAN.md
 */
class OmManualEmbedsRackTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePng;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentArtifactStorage::DISK);

        // Tiny 1x1 valid PNG fixture.
        $this->fixturePng = tempnam(sys_get_temp_dir(), 'om-rack-embed-fix-').'.png';
        file_put_contents(
            $this->fixturePng,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=')
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePng);
        parent::tearDown();
    }

    /**
     * Bind a stub DrawingExportRendererService that returns a fixture PNG
     * for any drawing whose id is NOT in $failingIds; null for ids in $failingIds
     * (simulates render failure → existing skip path).
     */
    private function bindRendererStub(array $failingIds = []): void
    {
        $fixture = $this->fixturePng;
        $this->app->bind(DrawingExportRendererService::class, function () use ($fixture, $failingIds) {
            return new class($fixture, $failingIds) extends DrawingExportRendererService {
                public function __construct(private string $fixture, private array $failingIds) {}

                public function ensurePngForHandover(ProjectDrawing $drawing): ?string
                {
                    if (in_array($drawing->id, $this->failingIds, true)) {
                        return null;
                    }
                    $copy = tempnam(sys_get_temp_dir(), 'fake-handover-png-').'.png';
                    copy($this->fixture, $copy);

                    return $copy;
                }
            };
        });
    }

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id'      => $user->id,
            'name'         => 'OM Rack Embed Test',
            'ref'          => 'OM-RACK-EMBED-001',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 OM Test Street, London',
            'status'       => 'quote_imported',
        ]);
    }

    private function makeOmManual(Project $project): OmManual
    {
        return OmManual::factory()->create([
            'user_id'    => $project->user_id,
            'project_id' => $project->id,
        ]);
    }

    private function makeReadyDrawing(Project $project, string $kind, string $sheet): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id'         => $project->id,
            'site_survey_room_id'=> null,
            'kind'               => $kind,
            'version'            => 1,
            'sheet_number'       => $sheet,
            'status'             => ProjectDrawing::STATUS_READY,
            'generated_svg'      => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'generated_by'       => $project->user_id,
            'source_data'        => [],
        ]);
    }

    private function readDocumentXml(string $docxPath): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($docxPath) === true, "Could not open DOCX as ZIP: {$docxPath}");
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse($xml, 'word/document.xml not found in DOCX');

        return (string) $xml;
    }

    private function buildOmDocx(OmManual $manual): string
    {
        // Minimal $data for OmManualDocxService::build; project block sets
        // header values but the Drawings section reads $manual->project directly.
        $data = [
            'project' => [
                'ref'    => $manual->project_ref,
                'name'   => $manual->project_name,
                'client' => $manual->client_name,
                'site'   => $manual->site_address,
            ],
            'rooms_summary'        => [],
            'existing_reuse'       => [],
            'operation_sections'   => [],
            'maintenance_schedule' => [],
            'fault_finding'        => [],
        ];

        $service = app(OmManualDocxService::class);

        return $service->build($data, $manual);
    }

    // ── 1. Both schematic + rack PNGs embedded ─────────────────────────────

    public function test_om_drawings_section_embeds_both_schematic_and_rack(): void
    {
        $this->bindRendererStub();

        $project = $this->makeProject();
        $manual = $this->makeOmManual($project);

        $this->makeReadyDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');
        $this->makeReadyDrawing($project, ProjectDrawing::KIND_RACK, 'AV-301');

        $docxPath = $this->buildOmDocx($manual);
        $this->assertFileExists($docxPath);

        $xml = $this->readDocumentXml($docxPath);

        // Both kindLabels (heading text) must be present.
        $this->assertStringContainsString(
            'System Schematic',
            $xml,
            'Drawings section must include schematic heading'
        );
        $this->assertStringContainsString(
            'Rack Elevation',
            $xml,
            'Drawings section must include rack heading (regression-locked from Phase 18 P03)'
        );

        // Two drawing image blocks emitted. PhpWord 1.4 writes images as
        // <w:pict><v:shape>...<v:imagedata.../></v:shape></w:pict> (legacy
        // VML path — see vendor/phpoffice/phpword/src/PhpWord/Writer/Word2007/Element/Image.php).
        $this->assertSame(
            2,
            substr_count($xml, '<v:imagedata'),
            'Expected exactly 2 <v:imagedata> entries (one per ready drawing image)'
        );
    }

    // ── 2. Failed render is skipped (rack absent, schematic still present) ─

    public function test_om_drawings_section_skips_failed_renders(): void
    {
        $project = $this->makeProject();
        $manual = $this->makeOmManual($project);

        $schematic = $this->makeReadyDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');
        $rack = $this->makeReadyDrawing($project, ProjectDrawing::KIND_RACK, 'AV-301');

        // Stub returns null for the rack — simulates renderer failure.
        $this->bindRendererStub(failingIds: [$rack->id]);

        $docxPath = $this->buildOmDocx($manual);
        $xml = $this->readDocumentXml($docxPath);

        // Schematic heading present, rack heading absent.
        $this->assertStringContainsString('System Schematic', $xml);
        $this->assertStringNotContainsString(
            'Rack Elevation',
            $xml,
            'Failed rack render must be skipped (existing try/catch + null guard)'
        );

        // Exactly one drawing image emitted (PhpWord uses <v:imagedata>).
        $this->assertSame(
            1,
            substr_count($xml, '<v:imagedata'),
            'Expected only the schematic image; rack must be skipped on failed render'
        );
    }
}

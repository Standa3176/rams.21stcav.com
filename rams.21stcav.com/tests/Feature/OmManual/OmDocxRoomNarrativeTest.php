<?php

namespace Tests\Feature\OmManual;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentTemplateService;
use App\Services\OmManualDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Regression guard for F-OM-02 (audit 2026-05-17).
 *
 * Before this fix: OmManualDocxService rendered ZERO per-room narrative.
 * Searching the source for "narrative" returned no matches. The PDF blade
 * (resources/views/pdf/om-manual.blade.php:301-318 + 639-647) renders a
 * "Room Overviews" sub-section under §1 Executive Summary built from
 * $data['system_overviews'] (AI-generated, primary) with a fallback chain
 * to $r['narrative'] then $r['description'] per room.
 *
 * Net effect before fix: a user downloading the DOCX got a markedly worse
 * document than the PDF for the same generated_data payload — no per-room
 * overview section at all.
 *
 * After fix: OmManualDocxService::buildRoomOverviewsSection() ports the
 * PDF's preference chain into a "1.4 Room Overviews" sub-section inside
 * the Introduction block (the closest structural cousin to the PDF's
 * §1 Executive Summary).
 *
 * Tests render the DOCX, unzip word/document.xml, and grep for the
 * expected fragments — same technique as
 * tests/Feature/Rams/DocxBuilderPdfParityTest.
 *
 * @see OmManualDocxService::buildRoomOverviewsSection
 * @see .planning/audits/worksheet-om-parity-audit-2026-05-17.md (F-OM-02)
 */
class OmDocxRoomNarrativeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // H-07 testing convention: fake the documents disk so DOCX output
        // stays out of storage/app/documents/ between runs.
        Storage::fake('documents');

        // Force the no-template path so the programmatic Section 1 (which
        // hosts the new Room Overviews sub-section) always runs. The
        // template path would render via a different cover route that may
        // not include the new block until the .docx template is rebuilt.
        $this->app->instance(
            DocumentTemplateService::class,
            Mockery::mock(DocumentTemplateService::class, function ($mock) {
                $mock->shouldReceive('exists')->andReturn(false);
                $mock->shouldReceive('path')->andReturn('');
            })
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Fixture helpers ──────────────────────────────────────────────────────

    private function makeManual(array $generatedDataOverrides = []): OmManual
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return OmManual::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => OmManual::STATUS_GENERATING,
            'generated_data' => $generatedDataOverrides,
        ]);
    }

    /**
     * Build the OM DOCX and return the contents of word/document.xml.
     */
    private function renderDocumentXml(OmManual $manual, array $data): string
    {
        $builder = app(OmManualDocxService::class);
        $path = $builder->build($data, $manual);

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    /** Minimal $data payload — only the fields exercised below. */
    private function baseData(array $merge = []): array
    {
        return array_merge([
            'project' => [
                'ref'    => 'TEST-001',
                'name'   => 'Test Install',
                'client' => 'Test Client Ltd',
                'site'   => '1 Test Street, London',
            ],
            'rooms_summary'        => [],
            'operation_sections'   => [],
            'maintenance_schedule' => [],
            'fault_finding'        => [],
            'network_devices'      => [],
            'manufacturer_support' => [],
            'existing_reuse'       => [],
            'system_overviews'     => [],
        ], $merge);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_room_overviews_section_renders_when_system_overviews_present(): void
    {
        // PRIMARY case: AI-generated $data['system_overviews'] populates the
        // narrative block. This is the canonical post-Phase-22.1 path —
        // OmManualGeneratorService::buildSystemOverviewNarratives() emits
        // exactly this shape (room_name + narrative).
        $manual = $this->makeManual();
        $data   = $this->baseData([
            'system_overviews' => [
                [
                    'room_name' => 'Boardroom',
                    'narrative' => 'Sony 85-inch display centred at 1200mm with a Yealink A30 bar below.',
                ],
                [
                    'room_name' => 'Reception',
                    'narrative' => 'Two Samsung QM55B displays driven from a BrightSign player.',
                ],
            ],
        ]);

        $xml = $this->renderDocumentXml($manual, $data);

        // The heading must render.
        $this->assertStringContainsString('Room Overviews', $xml,
            'F-OM-02: "Room Overviews" section heading missing from DOCX (was zero per-room narrative).');

        // Each room name must appear as a sub-heading.
        $this->assertStringContainsString('Boardroom', $xml);
        $this->assertStringContainsString('Reception', $xml);

        // The AI-generated narrative bodies must appear.
        $this->assertStringContainsString('Sony 85-inch display centred at 1200mm', $xml,
            'F-OM-02: Boardroom narrative body missing from DOCX.');
        $this->assertStringContainsString('Samsung QM55B displays driven from a BrightSign player', $xml,
            'F-OM-02: Reception narrative body missing from DOCX.');
    }

    public function test_room_overviews_falls_back_to_rooms_summary_narrative_when_no_system_overviews(): void
    {
        // FALLBACK case: AI overview generation failed / was skipped, but
        // rooms_summary still carries the deterministic narrative mirror
        // populated by OmManualGeneratorService at line 880. The DOCX must
        // pick that up — matches PDF blade lines 312-318.
        $manual = $this->makeManual();
        $data   = $this->baseData([
            'system_overviews' => [],
            'rooms_summary'    => [
                [
                    'name'        => 'Training Room',
                    'narrative'   => 'Dual display setup with ceiling microphone array for hybrid sessions.',
                    'equipment'   => [],
                ],
            ],
        ]);

        $xml = $this->renderDocumentXml($manual, $data);

        $this->assertStringContainsString('Room Overviews', $xml);
        $this->assertStringContainsString('Training Room', $xml);
        $this->assertStringContainsString('Dual display setup with ceiling microphone array', $xml,
            'F-OM-02: rooms_summary[].narrative fallback did not render.');
    }

    public function test_room_overviews_uses_legacy_description_when_narrative_absent(): void
    {
        // LEGACY case: pre-Phase-8 rooms_summary records still have
        // 'description' instead of 'narrative'. The fallback chain must
        // still find them so historical OM regenerations don't degrade.
        $manual = $this->makeManual();
        $data   = $this->baseData([
            'system_overviews' => [],
            'rooms_summary'    => [
                [
                    'name'        => 'Huddle Pod',
                    'description' => 'Legacy narrative carried on the description key.',
                    'equipment'   => [],
                ],
            ],
        ]);

        $xml = $this->renderDocumentXml($manual, $data);

        $this->assertStringContainsString('Huddle Pod', $xml);
        $this->assertStringContainsString('Legacy narrative carried on the description key', $xml,
            'F-OM-02: legacy description fallback chain broken.');
    }

    public function test_system_overviews_take_precedence_over_rooms_summary_narrative(): void
    {
        // Mirrors the PDF's preference chain — when both are present the
        // AI-generated narrative wins, because rooms_summary[*].narrative
        // is the deterministic mirror and may be staler / coarser than the
        // AI-built per-room overview.
        $manual = $this->makeManual();
        $data   = $this->baseData([
            'system_overviews' => [
                [
                    'room_name' => 'Auditorium',
                    'narrative' => 'AI-GENERATED canonical narrative.',
                ],
            ],
            'rooms_summary' => [
                [
                    'name'      => 'Auditorium',
                    'narrative' => 'DETERMINISTIC mirror narrative.',
                    'equipment' => [],
                ],
            ],
        ]);

        $xml = $this->renderDocumentXml($manual, $data);

        $this->assertStringContainsString('AI-GENERATED canonical narrative', $xml,
            'F-OM-02: system_overviews must win over rooms_summary fallback.');
        $this->assertStringNotContainsString('DETERMINISTIC mirror narrative', $xml,
            'F-OM-02: rooms_summary fallback leaked through even though AI overview was present.');
    }

    public function test_room_overviews_section_is_skipped_when_no_data(): void
    {
        // Negative case: the PDF wraps the whole block in @if (! empty(...))
        // so the DOCX must NOT emit an empty "Room Overviews" heading when
        // there is no per-room narrative available anywhere.
        $manual = $this->makeManual();
        $data   = $this->baseData([
            'system_overviews' => [],
            'rooms_summary'    => [],
        ]);

        $xml = $this->renderDocumentXml($manual, $data);

        $this->assertStringNotContainsString('Room Overviews', $xml,
            'F-OM-02: empty section heading rendered with no narrative data — should be skipped.');
    }
}

<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DocxBuilderService;
use App\Services\ProjectContext\ProjectContextBuilder;
use App\Services\RamsDataBuilderService;
use App\Services\WorksheetDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * 46.8 D-02/D-03 — comms room is removed from the RAMS data feed AND the
 * worksheet cover-page "Site Logistics" block, while the SiteSurvey columns
 * themselves survive on disk, completely unread by any of the six touched
 * files.
 *
 * This OVERRIDES 46.5 D-03 ("keep comms room everywhere") for comms room
 * only — the cost (an installing engineer no longer inherits the surveyor's
 * comms-room access notes through RAMS/worksheet output) was put to the user
 * with the consumers named, and they chose full removal. Research confirmed
 * comms room is printed TEXT ONLY in every one of these files — no hazard,
 * PPE or risk score is keyed on it, so removing it changes what prints, not
 * what the RAMS says is safe.
 *
 * @see app/Services/RamsDataBuilderService.php
 * @see app/Services/ProjectContext/ProjectContextBuilder.php
 * @see app/Services/DocxBuilderService.php
 * @see resources/views/pdf/rams.blade.php
 * @see resources/views/pdf/rams-v2.blade.php
 * @see app/Services/WorksheetDocxService.php
 * @see .planning/phases/46.8-survey-capture-refinements/46.8-01-PLAN.md
 */
class CommsRoomRemovedFromRamsFeedTest extends TestCase
{
    use RefreshDatabase;

    private const COMMS    = 'SENTINEL-COMMS';
    private const PARKING  = 'SENTINEL-PARKING';
    private const ACCESS   = 'SENTINEL-ACCESS';
    private const DELIVERY = 'SENTINEL-DELIVERY';
    private const DISTANCE = 'SENTINEL-DISTANCE';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    // ── Task 1 — the data layer never attaches/re-introduces the two keys ──

    public function test_project_context_builder_never_attaches_comms_room(): void
    {
        [, $survey] = $this->makeSurveyAndProject();

        $context = ProjectContextBuilder::build($survey->fresh());

        $this->assertIsArray($context['site_logistics']);
        $this->assertArrayNotHasKey('comms_room_access_status', $context['site_logistics'],
            'ProjectContextBuilder::build() must never attach comms_room_access_status (46.8 D-02).');
        $this->assertArrayNotHasKey('comms_room_access_notes', $context['site_logistics'],
            'ProjectContextBuilder::build() must never attach comms_room_access_notes (46.8 D-02).');

        // Control — the other five site-logistics keys are unaffected.
        $this->assertSame(self::PARKING, $context['site_logistics']['parking_restraints']);
        $this->assertSame(self::ACCESS, $context['site_logistics']['site_access_notes']);
        $this->assertSame(self::DELIVERY, $context['site_logistics']['delivery_routes']);
        $this->assertSame(self::DISTANCE, $context['site_logistics']['distance_from_base_notes']);
    }

    public function test_rams_data_builder_normalise_never_reintroduces_comms_room_even_from_a_stale_caller(): void
    {
        [, $survey] = $this->makeSurveyAndProject();

        $context = ProjectContextBuilder::build($survey->fresh());

        // Simulate a stale caller that still supplies the two comms-room keys
        // in its site_logistics payload (e.g. an un-migrated in-flight job).
        // normalise() must strip them regardless of what the input contains.
        $context['site_logistics']['comms_room_access_status'] = 'yes';
        $context['site_logistics']['comms_room_access_notes']  = self::COMMS;
        // assemble() only runs the heavy hazard/cable pipeline when rooms is
        // non-empty — force it empty so this test exercises normalise()
        // in isolation without needing HazardTemplateSeeder.
        $context['rooms'] = [];

        $data = app(RamsDataBuilderService::class)->assemble(
            parsed: [],
            classified: [],
            risk: ['ppe' => ['Safety boots'], 'hazards' => [], 'access_equipment' => []],
            methodStatement: ['phases' => []],
            formData: ['persons_at_risk' => ['Test Person']],
            projectContext: $context,
        );

        $this->assertArrayNotHasKey('comms_room_access_status', $data['site_logistics'],
            'RamsDataBuilderService::normalise() re-introduced comms_room_access_status from a stale caller.');
        $this->assertArrayNotHasKey('comms_room_access_notes', $data['site_logistics'],
            'RamsDataBuilderService::normalise() re-introduced comms_room_access_notes from a stale caller.');

        $this->assertSame(self::PARKING, $data['site_logistics']['parking_restraints']);
        $this->assertSame(self::ACCESS, $data['site_logistics']['site_access_notes']);
        $this->assertSame(self::DELIVERY, $data['site_logistics']['delivery_routes']);
    }

    // ── Task 2 — the four generated outputs omit comms room ────────────────

    public function test_rams_docx_omits_comms_room_but_keeps_other_site_logistics_rows(): void
    {
        $record = $this->makeRamsDocument();

        $xml = $this->renderDocxXml($record);

        $this->assertStringNotContainsString('Comms room access', $xml,
            'DocxBuilderService must never print the "Comms room access" row (46.8 D-02).');
        $this->assertStringNotContainsString(self::COMMS, $xml,
            'DocxBuilderService must never print the comms-room sentinel text.');
        $this->assertStringNotContainsString('Permission required', $xml,
            'DocxBuilderService must never print the comms-room status label.');

        // Control: the other populated rows still render.
        $this->assertStringContainsString(self::PARKING, $xml);
        $this->assertStringContainsString(self::ACCESS, $xml);
        $this->assertStringContainsString(self::DELIVERY, $xml);
        $this->assertStringContainsString(self::DISTANCE, $xml);
    }

    public function test_rams_docx_with_all_site_logistics_empty_still_omits_the_whole_section(): void
    {
        $record = $this->makeRamsDocument(siteLogistics: []);

        $xml = $this->renderDocxXml($record);

        $this->assertStringNotContainsString('Site Logistics &amp; Access', $xml,
            'The $hasSiteLog guard must still correctly detect "nothing to show" once comms room is removed from it.');
    }

    public function test_rams_pdf_blade_v1_omits_comms_room_but_keeps_other_site_logistics_rows(): void
    {
        $record = $this->makeRamsDocument();

        $html = view('pdf.rams', [
            'rams' => $record,
            'data' => $record->generated_data ?? [],
        ])->render();

        $this->assertStringNotContainsString('Comms room access', $html);
        $this->assertStringNotContainsString(self::COMMS, $html);
        $this->assertStringNotContainsString('Permission required', $html);
        $this->assertStringContainsString(self::PARKING, $html);
        $this->assertStringContainsString(self::ACCESS, $html);
        $this->assertStringContainsString(self::DELIVERY, $html);
    }

    public function test_rams_pdf_blade_v2_omits_comms_room_but_keeps_other_site_logistics_rows(): void
    {
        $record = $this->makeRamsDocument();

        $html = view('pdf.rams-v2', [
            'rams'  => $record,
            'data'  => $record->generated_data ?? [],
            'dto'   => app(\App\Support\Rams\RamsDocumentComposer::class)->compose($record),
            'theme' => app(\App\Support\Rams\RamsTheme::class),
        ])->render();

        $this->assertStringNotContainsString('Comms room access', $html);
        $this->assertStringNotContainsString(self::COMMS, $html);
        $this->assertStringNotContainsString('Permission required', $html);
        $this->assertStringContainsString(self::PARKING, $html);
        $this->assertStringContainsString(self::ACCESS, $html);
        $this->assertStringContainsString(self::DELIVERY, $html);
    }

    public function test_worksheet_docx_omits_comms_room_but_keeps_other_site_logistics_rows_and_survey_column_survives(): void
    {
        [$user, $survey] = $this->makeSurveyAndProject();

        $before = [
            'status' => SiteSurvey::find($survey->id)->comms_room_access_status,
            'notes'  => SiteSurvey::find($survey->id)->comms_room_access_notes,
        ];

        $this->assertSame('yes', $before['status']);
        $this->assertSame(self::COMMS, $before['notes']);

        $worksheet = Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $survey->project_id,
        ]);

        $generatedData = [
            'project' => ['name' => $worksheet->project_name],
            'rooms'   => [],
        ];

        app(WorksheetDocxService::class)->build($generatedData, $worksheet);

        $filename = $worksheet->fresh()->filename;
        $this->assertNotNull($filename, 'WorksheetDocxService must record the generated filename.');

        $xml = $this->worksheetDocumentXml($filename);

        $this->assertStringNotContainsString('Comms room access', $xml,
            'WorksheetDocxService must never print the "Comms room access" row (46.8 D-02).');
        $this->assertStringNotContainsString(self::COMMS, $xml,
            'WorksheetDocxService must never print the comms-room sentinel text.');
        $this->assertStringNotContainsString('Permission required', $xml,
            'WorksheetDocxService must never print the comms-room status label.');

        // Control: the other populated site-logistics rows still render.
        $this->assertStringContainsString(self::PARKING, $xml);
        $this->assertStringContainsString(self::ACCESS, $xml);
        $this->assertStringContainsString(self::DELIVERY, $xml);
        $this->assertStringContainsString(self::DISTANCE, $xml);

        // D-03: the SiteSurvey columns were never written and are
        // byte-identical to what was seeded, after generation.
        $after = SiteSurvey::find($survey->id);
        $this->assertSame($before['status'], $after->comms_room_access_status,
            'D-03: comms_room_access_status must survive generation unread/unwritten.');
        $this->assertSame($before['notes'], $after->comms_room_access_notes,
            'D-03: comms_room_access_notes must survive generation unread/unwritten.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: SiteSurvey} */
    private function makeSurveyAndProject(): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Comms Room Removal Fixture',
            'ref'          => 'CRR-' . fake()->numerify('###'),
            'client_name'  => 'Fixture Client Ltd',
            'site_address' => '1 Fixture Street, London',
            'status'       => 'quote_imported',
        ]);

        $survey = SiteSurvey::create([
            'user_id'                   => $user->id,
            'project_id'                => $project->id,
            'project_name'              => 'Comms Room Removal Fixture',
            'project_ref'               => 'CRR-001',
            'client_name'               => 'Fixture Client Ltd',
            'site_address'              => '1 Fixture Street, London',
            'status'                    => 'draft',
            'comms_room_access_status'  => 'yes',
            'comms_room_access_notes'   => self::COMMS,
            'parking_restraints'        => self::PARKING,
            'distance_from_base_miles'  => 42,
            'distance_from_base_notes'  => self::DISTANCE,
            'site_access_notes'         => self::ACCESS,
            'delivery_routes'           => self::DELIVERY,
            'survey_data'               => [
                'project_id' => $project->id,
                'rooms'      => [
                    [
                        'name'           => 'Boardroom',
                        'type'           => 'meeting_room',
                        'infrastructure' => [],
                        'equipment'      => [],
                        'risks'          => [],
                    ],
                ],
            ],
        ]);

        return [$user, $survey->refresh()];
    }

    private function makeRamsDocument(?array $siteLogistics = null): RamsDocument
    {
        $user    = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Comms Room Removal RAMS Fixture',
        ]);

        $siteLogistics ??= [
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => self::COMMS,
            'parking_restraints'       => self::PARKING,
            'distance_from_base_miles' => 42,
            'distance_from_base_notes' => self::DISTANCE,
            'site_access_notes'        => self::ACCESS,
            'delivery_routes'          => self::DELIVERY,
        ];

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Comms Room Removal RAMS Fixture',
            'project_ref'    => '21CQ00000-02-OPS',
            'client_name'    => 'Fixture Client Ltd',
            'site_address'   => '1 Fixture Street, London',
            'form_data'      => [],
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => [
                'project' => [
                    'name'         => 'Comms Room Removal RAMS Fixture',
                    'ref'          => '21CQ00000-02-OPS',
                    'client'       => 'Fixture Client Ltd',
                    'site_address' => '1 Fixture Street, London',
                    'doc_author'   => 'Sonny',
                ],
                'team'             => [['role' => 'Project Manager', 'name' => 'Sonny']],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_logistics'   => $siteLogistics,
            ],
        ]);
    }

    private function renderDocxXml(RamsDocument $record): string
    {
        $path = app(DocxBuilderService::class)->build($record->generated_data ?? [], $record->fresh());
        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated RAMS DOCX.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    private function worksheetDocumentXml(string $filename): string
    {
        $disk = Storage::disk('documents');
        $this->assertTrue(
            $disk->exists('worksheets/' . $filename),
            'Generated worksheet DOCX not found on the documents disk.'
        );

        $zip = new ZipArchive();
        $this->assertTrue(
            $zip->open($disk->path('worksheets/' . $filename)) === true,
            'Failed to open generated worksheet DOCX.'
        );
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }
}

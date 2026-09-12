<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Services\Rams\RamsDisplayPatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 29 Plan 12 (29-UAT.md Gap 1 + Gap 3 DOCX half) — proves the DOCX
 * renderer's new Section 7.0 A&E block and the CDM `contractor_note`
 * paragraph both actually render, via presence assertions against a real
 * built DOCX's `word/document.xml` — not absence assertions, which is
 * exactly how Gap 1 escaped a green 328-test suite.
 *
 * Mirrors SiteEmergencyRenderSitesRegressionTest's real-pipeline fixture
 * approach: create User + Project + RamsDocument, run
 * RamsDisplayPatchService::patch() then RamsComplianceUpgradeService::
 * upgrade(), then build the real DOCX via DocxBuilderService::build().
 *
 * @see app/Services/DocxBuilderService.php
 * @see app/Services/Rams/SiteEmergencyResolver.php
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-UAT.md
 * @see .planning/reference/21cav-rams-skill/references/standards-and-legislation.md:35-41
 */
class DocxEmergencySectionRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const BANNED_STRING = 'to be identified at site induction';

    /** Every Section 7.0 field EXCEPT nearest_hospital/hospital_address, always populated. */
    private const OTHER_SITE_EMERGENCY_FIELDS = [
        'fire_assembly_point'         => 'Main gate assembly point',
        'fire_warden_name'            => 'Jordan Fire Warden',
        'fire_warden_contact'         => '07700 900111',
        'first_aider_name'            => 'Casey First Aider',
        'first_aider_contact'         => '07700 900222',
        'defibrillator_location'      => 'Reception, ground floor',
        'electrical_isolation_switch' => 'Sub-panel A, plant room',
        'fire_extinguisher_class'     => 'Class A + Class C (CO2)',
    ];

    /**
     * Build a real, persisted RamsDocument with the given `site_emergency`
     * state, run it through the real patch + upgrade pipeline, build the
     * real DOCX, and return the extracted word/document.xml.
     */
    private function buildDocxXmlWith(array $siteEmergency): string
    {
        Storage::fake('documents');

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => '21CQ00000-03-OPS',
            'project_name'   => 'Docx Emergency Section Regression Test',
            'client_name'    => 'Regression Test Ltd',
            'site_address'   => '1 Regression Street, London',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-docx-emergency-regression.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'form_data'      => [],
            'reviewed_data'  => [
                'site_emergency' => $siteEmergency,
            ],
            'generated_data' => [
                'project' => [
                    'name'            => 'Docx Emergency Section Regression Test',
                    'ref'             => '21CQ00000-03-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_emergency'   => $siteEmergency,
            ],
        ]);
        $rams->refresh();

        app(RamsDisplayPatchService::class)->patch($rams);

        $rams->generated_data = RamsComplianceUpgradeService::upgrade($rams->generated_data ?? []);
        $rams->save();
        $rams->refresh();

        $path = app(DocxBuilderService::class)->build($rams->generated_data ?? [], $rams->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    public function test_wholly_empty_site_emergency_shows_holdpoint_not_banned_string(): void
    {
        $xml = $this->buildDocxXmlWith([]);

        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $xml,
        );
        $this->assertStringNotContainsString(self::BANNED_STRING, $xml);
    }

    public function test_verified_site_emergency_shows_hospital_name_and_address(): void
    {
        $siteEmergency = array_merge(self::OTHER_SITE_EMERGENCY_FIELDS, [
            'nearest_hospital' => 'Queen Elizabeth Hospital',
            'hospital_address' => '123 Mindelsohn Way, Birmingham, B15 2GW',
        ]);

        $xml = $this->buildDocxXmlWith($siteEmergency);

        $this->assertStringContainsString('Queen Elizabeth Hospital', $xml);
        $this->assertStringContainsString('Mindelsohn Way', $xml);
        $this->assertStringNotContainsString(self::BANNED_STRING, $xml);
    }

    public function test_welfare_bullet_points_at_section_seven_point_zero(): void
    {
        $xml = $this->buildDocxXmlWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringContainsString('Nearest A&amp;E', $xml);
        $this->assertStringContainsString('see Section 7.0', $xml);
        $this->assertStringNotContainsString('Duty Holders section', $xml);
    }

    public function test_docx_carries_the_verbatim_rule_07_contractor_note(): void
    {
        $xml = $this->buildDocxXmlWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringContainsString(
            '21CAV is currently anticipated to be the sole contractor for the AV installation scope. The client '
                . 'shall confirm whether the overall project involves, or is likely to involve, more than one '
                . 'contractor before works commence.',
            $xml,
        );
    }

    /**
     * @dataProvider forbiddenStatementProvider
     */
    public function test_forbidden_cdm_statement_never_appears(string $forbidden): void
    {
        $xml = $this->buildDocxXmlWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringNotContainsStringIgnoringCase($forbidden, $xml);
    }

    public static function forbiddenStatementProvider(): array
    {
        return [
            'unequivocal sole contractor assertion' => ['21CAV is the sole contractor'],
            'client retains principal designer' => ['retains Principal Designer'],
            'principal contractor must notify' => ['Principal Contractor must notify'],
        ];
    }
}

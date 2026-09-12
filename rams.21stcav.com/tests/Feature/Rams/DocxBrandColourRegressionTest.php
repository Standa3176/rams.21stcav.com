<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 29 Plan 13 (gap closure, 29-UAT.md Gap 4/6) — proves a real DOCX
 * build carries the 21CAV brand palette (#1B7A7A teal / #F4FBFB pale-teal
 * tint / #1A1A2E navy) and never Microsoft Word's stock "Blue, Accent 1"
 * defaults (2E74B5/DEEBF7/1F4D78/333333) that DocxBuilderService shipped
 * with prior to this plan.
 *
 * Mirrors SiteEmergencyRenderSitesRegressionTest's real fixture-build
 * approach (create User + Project + RamsDocument, run
 * DocxBuilderService::build(), extract word/document.xml from the
 * resulting zip and inspect it) rather than a hand-parsed constant check —
 * this is the only way to prove the corrected constants actually reach the
 * rendered XML.
 */
class DocxBrandColourRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_docx_build_never_contains_office_default_hex_and_always_contains_brand_hex(): void
    {
        Storage::fake('documents');

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'generated_data' => [
                'project' => [
                    'name'            => 'Brand Colour Regression Test (DOCX)',
                    'ref'             => '21CQ00000-13-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                ],
                'team' => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'cdm_duty_holders' => [
                    'client'               => 'Regression Test Ltd',
                    'principal_designer'   => 'Not formally appointed at this stage.',
                    'principal_contractor' => '21st Century AV Ltd',
                    'contractor'           => '21st Century AV Ltd',
                    'subcontractor'        => 'N/A',
                    'project_manager'      => 'Sonny',
                    'site_supervisor'      => 'Sonny',
                ],
                'hazards' => [
                    [
                        'ref'         => 'RA01',
                        'hazard'      => 'Working at height',
                        'consequence' => 'Fall injury',
                        'likelihood'  => 2,
                        'severity'    => 3,
                        'controls'    => ['Use of tower scaffold, trained operatives.'],
                    ],
                ],
                'method_statement' => [
                    'phases' => [
                        ['title' => 'Setup', 'steps' => ['Isolate power', 'Set up barriers']],
                    ],
                ],
                'site_emergency' => [
                    'fire_assembly_point'         => 'Main gate assembly point',
                    'fire_warden_name'            => 'Jordan Fire Warden',
                    'fire_warden_contact'         => '07700 900111',
                    'first_aider_name'            => 'Casey First Aider',
                    'first_aider_contact'         => '07700 900222',
                    'defibrillator_location'      => 'Reception, ground floor',
                    'electrical_isolation_switch' => 'Sub-panel A, plant room',
                    'fire_extinguisher_class'     => 'Class A + Class C (CO2)',
                ],
            ],
            'reviewed_data' => null,
            'status'        => RamsDocument::STATUS_COMPLETED,
        ]);

        $path = app(DocxBuilderService::class)->build($rams->generated_data ?? [], $rams->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        // Negative: no Office-default / stale hex survives a real build.
        $this->assertStringNotContainsString('2E74B5', $xml, 'Stale Word "Blue, Accent 1" heading hex must not survive.');
        $this->assertStringNotContainsString('DEEBF7', $xml, 'Stale Word "Blue, Accent 1" alt-row hex must not survive.');
        $this->assertStringNotContainsString('1F4D78', $xml, 'Stale dead BRAND_BLUE_DARK hex must not survive.');
        $this->assertStringNotContainsString('333333', $xml, 'Stale DARK_GREY hex must not survive.');

        // Positive: the corrected brand colours actually reached the rendered XML.
        $this->assertStringContainsString('1B7A7A', $xml, '21CAV brand teal must be present in the rendered DOCX.');
        $this->assertStringContainsString('F4FBFB', $xml, '21CAV brand pale-teal tint must be present in the rendered DOCX.');
    }
}

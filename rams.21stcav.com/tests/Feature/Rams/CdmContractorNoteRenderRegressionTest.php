<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29 Plan 11 (RULE-07/RULE-08 gap closure — 29-UAT.md Gap 3, PDF half)
 * — proves both `pdf.rams` and `pdf.rams-v2` render the RULE-07 verbatim
 * anticipated-sole-contractor sentence
 * (`RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE`) in the CDM 2015
 * Duty Holders section, and that none of the four forbidden CDM statements
 * (`standards-and-legislation.md:35-41`) ever appear in the rendered output.
 *
 * Mirrors `SiteEmergencyRenderSitesRegressionTest::renderBothBladesWith()`'s
 * real-pipeline fixture-build approach (create User + Project + RamsDocument,
 * run `RamsComplianceUpgradeService::upgrade()`, render both blades directly)
 * rather than hand-building a fixture that bypasses `addCdmDutyHolders()`.
 */
class CdmContractorNoteRenderRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{v1: string, v2: string, data: array} */
    private function renderBothBladesThroughRealPipeline(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => '21CQ00000-03-OPS',
            'project_name'   => 'CDM Contractor Note Regression Test',
            'client_name'    => 'Regression Test Ltd',
            'site_address'   => '1 Regression Street, London',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-cdm-contractor-note-regression.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'form_data'      => [],
            'reviewed_data'  => [],
            'generated_data' => [
                'project' => [
                    'name'            => 'CDM Contractor Note Regression Test',
                    'ref'             => '21CQ00000-03-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                    'working_hours'   => 'Monday–Friday, 09:00–17:30',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_emergency'   => [],
            ],
        ]);
        $rams->refresh();

        // Real pipeline entry point — addCdmDutyHolders() populates
        // generated_data['cdm_duty_holders']['contractor_note'] here, which
        // both blades' CDM section now reads.
        $rams->generated_data = RamsComplianceUpgradeService::upgrade($rams->generated_data ?? []);
        $rams->save();
        $rams->refresh();

        $dto   = app(RamsDocumentComposer::class)->compose($rams);
        $theme = app(RamsTheme::class);

        $htmlV1 = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        $htmlV2 = view('pdf.rams-v2', [
            'rams'  => $rams,
            'data'  => $rams->generated_data ?? [],
            'dto'   => $dto,
            'theme' => $theme,
        ])->render();

        return [
            'v1'   => $htmlV1,
            'v2'   => $htmlV2,
            'data' => $rams->generated_data ?? [],
        ];
    }

    private const VERBATIM_CONTRACTOR_NOTE = '21CAV is currently anticipated to be the sole contractor for the AV '
        . 'installation scope. The client shall confirm whether the overall project involves, or is likely to '
        . 'involve, more than one contractor before works commence.';

    /** @var list<string> the four forbidden CDM statements, standards-and-legislation.md:35-41 */
    private const FORBIDDEN_STATEMENTS = [
        'retains Principal Designer',
        'discharges its duties under Regulation',
        'the Principal Contractor must notify HSE',
    ];

    public function test_contractor_note_constant_matches_the_verbatim_rule07_sentence(): void
    {
        $this->assertSame(self::VERBATIM_CONTRACTOR_NOTE, RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE);
    }

    public function test_v1_blade_renders_verbatim_contractor_note_in_cdm_section(): void
    {
        config(['rams.unified_composer' => false]);

        ['v1' => $html, 'data' => $data] = $this->renderBothBladesThroughRealPipeline();

        $this->assertSame(self::VERBATIM_CONTRACTOR_NOTE, $data['cdm_duty_holders']['contractor_note'] ?? null);
        $this->assertStringContainsString(self::VERBATIM_CONTRACTOR_NOTE, $html);
    }

    public function test_v2_blade_renders_verbatim_contractor_note_in_cdm_section(): void
    {
        config(['rams.unified_composer' => true]);

        ['v2' => $html] = $this->renderBothBladesThroughRealPipeline();

        $this->assertStringContainsString(self::VERBATIM_CONTRACTOR_NOTE, $html);
    }

    public function test_neither_blade_contains_any_forbidden_cdm_statement(): void
    {
        ['v1' => $htmlV1, 'v2' => $htmlV2] = $this->renderBothBladesThroughRealPipeline();

        foreach (['v1' => $htmlV1, 'v2' => $htmlV2] as $variant => $html) {
            foreach (self::FORBIDDEN_STATEMENTS as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "Forbidden CDM statement \"{$forbidden}\" leaked into {$variant}.");
            }
            // "21CAV is the sole contractor" must never appear unequivocally
            // (without the "anticipated"/"currently anticipated" qualifier).
            $this->assertDoesNotMatchRegularExpression(
                '/21CAV is the sole contractor/i',
                $html,
                "Unequivocal sole-contractor assertion leaked into {$variant}.",
            );
        }
    }

    public function test_fallback_renders_when_contractor_note_key_absent_predates_backfill(): void
    {
        // Simulates a document rendered before Plan 29-10's backfill has run:
        // cdm_duty_holders present, but no contractor_note key at all.
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'reviewed_data'  => null,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => [
                'project' => [
                    'name'            => 'CDM Contractor Note Fallback Test',
                    'ref'             => '21CQ00000-04-OPS',
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
                'site_emergency'   => [],
                'cdm_duty_holders' => [
                    'client'               => 'Regression Test Ltd',
                    'principal_designer'   => 'Not formally appointed.',
                    'principal_contractor' => 'Not formally appointed.',
                    // deliberately no 'contractor_note' key
                ],
            ],
        ]);

        $html = view('pdf.rams', [
            'rams' => $rams->fresh(),
            'data' => $rams->fresh()->generated_data ?? [],
        ])->render();

        $this->assertStringContainsString(self::VERBATIM_CONTRACTOR_NOTE, $html);
    }
}

<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\RiskTemplateResolverService;
use Carbon\Carbon;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 26 Plan 06 — DOCX-path proof of HAZ-02 (near-empty scope) and
 * HAZ-03 (Working at height residual 1x4).
 *
 * Per CONTEXT.md constraint 2, "any acceptance criterion proving hazard
 * population must go through the DOCX path, or it proves nothing about what
 * engineers actually receive." `DocxBuilderService::buildRiskAssessment()`
 * is the live primary renderer (`rams-v2.blade.php` and
 * `RiskAssessmentComposer` are not live). These tests build the register
 * through the actual live pipeline entry point —
 * `RiskTemplateResolverService::resolve()` — and read the assertion back out
 * of a real generated .docx file, not a fixture or a non-live template.
 *
 * `renderDocumentXml()` below is copied verbatim from the established
 * pattern in `DocxBuilderNewSectionsTest::renderDocumentXml()` (no shared
 * trait exists yet for it — every DOCX-path test file in this suite
 * currently carries its own copy).
 *
 * @see app/Services/RiskTemplateResolverService.php
 * @see app/Services/Rams/HazardIncludeWhenResolver.php
 * @see app/Services/DocxBuilderService.php::buildRiskAssessment()
 * @see database/seeders/HazardTemplateSeeder.php
 */
class WorkingAtHeightResidualScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00'));
        $this->seed(HazardTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function renderDocumentXml(array $generatedOverrides = []): string
    {
        $user = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Working at Height Residual Fixture',
        ]);

        $generated = array_merge([
            'project' => [
                'name'         => 'Working at Height Residual Fixture',
                'ref'          => '21CQ00000-00-OPS',
                'client'       => 'Fixture Client Ltd',
                'site_address' => '1 Test Street, London',
                'doc_author'   => 'Sonny',
            ],
            'team'    => [['role' => 'Project Manager', 'name' => 'Sonny']],
            'hazards' => [],
            'method_statement' => ['phases' => []],
        ], $generatedOverrides);

        $record = RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Working at Height Residual Fixture',
            'project_ref'    => '21CQ00000-00-OPS',
            'client_name'    => 'Fixture Client Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'generated_data' => $generated,
            'reviewed_data'  => null,
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);

        $builder = app(DocxBuilderService::class);
        $path = $builder->build($record->generated_data ?? [], $record->fresh());

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    public function test_near_empty_scope_produces_only_always_tier_hazards_in_docx(): void
    {
        $user = User::factory()->create();

        $risk = app(RiskTemplateResolverService::class)->resolve([], false, $user->id, [], []);

        $this->assertNotEmpty($risk['hazards'], 'resolve() with a genuinely blank scope produced no hazards at all — fixture is broken.');

        $xml = $this->renderDocumentXml(['hazards' => $risk['hazards']]);

        // CONTEXT.md scoping correction 2: "starts empty" means empty of
        // unconditional job-irrelevant PADDING, not a literal zero-row
        // register — the 4 always-tier hazards AND the 5 confirm-tier
        // hazards (D-06: always included, always flagged for human
        // confirmation, never silently dropped) both surface on every job,
        // regardless of signals. This is the documented, correct behaviour
        // (26-04-SUMMARY.md's correction to this plan's own must_haves
        // wording) — 9 rows, not 4.
        $alwaysTier = [
            'Slips, trips and falls',
            'Low voltage AV connections',
            'Fire and evacuation',
            'COSHH substances',
        ];
        $confirmTier = [
            'Occupied premises',
            'Asbestos-containing materials',
            'Vehicle and plant movement',
            'Lone and small-team working',
            'Occupational road risk',
        ];

        foreach ($alwaysTier as $name) {
            $this->assertStringContainsString($name, $xml,
                "always-tier hazard '{$name}' missing from the near-empty-scope DOCX register.");
        }
        foreach ($confirmTier as $name) {
            $this->assertStringContainsString($name, $xml,
                "confirm-tier hazard '{$name}' missing from the near-empty-scope DOCX register (D-06: must always surface for human confirmation).");
        }

        // The old fixed baseline must never resurrect, under any signal
        // combination — including the genuinely blank one.
        $this->assertStringNotContainsString('Manual Handling of AV Equipment', $xml,
            'old fixed-11 baseline title resurrected in the near-empty-scope DOCX register.');
        $this->assertStringNotContainsString('Electrical Isolation', $xml,
            'old fixed-11 baseline title resurrected in the near-empty-scope DOCX register.');
        $this->assertStringNotContainsString('Working at Height', $xml,
            'old Title-Case baseline "Working at Height" resurrected — the near-empty scope has no ceiling/drilling/display/mains signal, so the new sentence-case "Working at height" must not appear either.');
    }

    public function test_working_at_height_renders_residual_1x4_in_docx(): void
    {
        $user = User::factory()->create();

        // ceiling_works triggers signal:mounting_above_reach, which
        // "Working at height" is seeded against (HazardTemplateSeeder).
        $risk = app(RiskTemplateResolverService::class)->resolve(['ceiling_works'], false, $user->id, [], []);

        $workingAtHeight = collect($risk['hazards'])->firstWhere('hazard', 'Working at height');

        $this->assertNotNull($workingAtHeight,
            'ceiling_works did not resolve "Working at height" via signal:mounting_above_reach — cannot prove the residual score without it.');
        $this->assertSame(1, $workingAtHeight['post_likelihood'],
            'seeded "Working at height" row does not carry the expected residual likelihood of 1 — HAZ-03 fixture is stale.');
        $this->assertSame(4, $workingAtHeight['post_severity'],
            'seeded "Working at height" row does not carry the expected residual severity of 4 (not the old baseline\'s 2x3) — HAZ-03 fixture is stale.');

        $xml = $this->renderDocumentXml(['hazards' => $risk['hazards']]);

        $this->assertStringContainsString('Working at height', $xml,
            '"Working at height" missing from the rendered DOCX risk register.');

        // DocxBuilderService::buildRiskAssessment() renders the post-control
        // (residual) risk cell as "{postL}×{postS}={postScore}" — exact
        // string read from app/Services/DocxBuilderService.php:1274.
        $this->assertStringContainsString('1×4=4', $xml,
            'HAZ-03: "Working at height" must render residual 1x4 (=4) in the actual generated DOCX, not the old baseline\'s 2x3 (=6).');
    }
}

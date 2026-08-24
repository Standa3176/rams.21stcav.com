<?php

namespace Tests\Feature\Rams\Composer;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsDocumentDTO;
use App\Support\Rams\Sections\CoverSectionDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for App\Support\Rams\RamsDocumentComposer.
 *
 * Exercises the composer against 5 realistic RamsDocument fixtures
 * seeded from tests/Fixtures/rams/{scenario}/record.json. Each fixture
 * targets a distinct branch of the composer's resolution chains.
 *
 * Flow per fixture:
 *   1. Load record.json (raw seed data)
 *   2. Build Project + RamsDocument via factories, override attrs from JSON
 *   3. Run RamsDisplayPatchService::patch() to mimic controller behaviour
 *   4. Run RamsDocumentComposer::compose()
 *   5. Assert scenario-specific invariants on the resulting DTO
 *
 * Fixture registration lives in dataProvider fixtureNames() so adding a
 * fixture is a one-line change.
 */
class RamsDocumentComposerTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__ . '/../../../Fixtures/rams';

    private function loadFixture(string $name): array
    {
        $path = self::FIXTURE_DIR . '/' . $name . '/record.json';
        $this->assertFileExists($path, "Fixture record.json missing for '{$name}'.");
        $json = file_get_contents($path);
        $data = json_decode((string) $json, true);
        $this->assertIsArray($data, "Fixture '{$name}' record.json is not valid JSON.");
        return $data;
    }

    /**
     * Seed Project + RamsDocument from a fixture and return the composed DTO.
     *
     * $extraPatch is an optional callback that runs BEFORE the patch service
     * to inject related records (e.g. a prior completed RAMS for auto-carry).
     */
    private function composeFrom(string $name, ?callable $extraPatch = null): array
    {
        $fx    = $this->loadFixture($name);
        $owner = User::factory()->create(['name' => 'Alex Bloggs']);

        $project = Project::factory()->for($owner, 'owner')->create([
            'name'         => $fx['project']['name'],
            'client_name'  => $fx['project']['client_name'],
            'site_address' => $fx['project']['site_address'],
            'ref'          => $fx['project']['ref'],
        ]);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => $fx['rams']['project_ref'],
            'project_name'   => $fx['rams']['project_name'],
            'client_name'    => $fx['rams']['client_name'],
            'site_address'   => $fx['rams']['site_address'],
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-' . $name . '.docx',
            'status'         => $fx['rams']['status'],
            'form_data'      => $fx['rams']['form_data']      ?? [],
            'reviewed_data'  => $fx['rams']['reviewed_data']  ?? [],
            'generated_data' => $fx['rams']['generated_data'] ?? [],
        ]);

        if ($extraPatch !== null) {
            $extraPatch($project, $rams, $fx);
            $rams->refresh();
        }

        app(RamsDisplayPatchService::class)->patch($rams);

        $dto = app(RamsDocumentComposer::class)->compose($rams);

        return [$rams, $dto];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fixture 1 — fresh-build
    // ══════════════════════════════════════════════════════════════════════

    public function test_fresh_build_produces_valid_dto_with_populated_cover(): void
    {
        [, $dto] = $this->composeFrom('fresh-build');

        $this->assertInstanceOf(RamsDocumentDTO::class, $dto);
        $this->assertInstanceOf(CoverSectionDto::class, $dto->cover);

        $this->assertSame('Fresh Build Ltd',       $dto->cover->client);
        $this->assertSame('FRESH-001',             $dto->cover->projectRef);
        $this->assertSame('Alex Bloggs',           $dto->cover->preparedBy);
        $this->assertSame('Rev 1.0',               $dto->cover->revision);

        // Section 2 (company info) always populated from config.
        $this->assertFalse($dto->companyInfo->isEmpty());
        $this->assertNotSame('', $dto->companyInfo->name);

        // Section 3 (standards table) always populated from tier1 config.
        $this->assertFalse($dto->standardsTable->isEmpty());

        // Phase 26: no hazards supplied → register stays empty. The
        // RiskAssessmentComposer config-baseline fallback was removed;
        // hazard population is now handled upstream by
        // HazardIncludeWhenResolver (Plan 26-04), not exercised by this
        // fixture.
        $this->assertEmpty($dto->riskAssessment->hazards);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fixture 2 — prior-rams-carry
    // ══════════════════════════════════════════════════════════════════════

    public function test_prior_rams_carry_surfaces_site_emergency_in_dto(): void
    {
        [, $dto] = $this->composeFrom('prior-rams-carry', function (Project $project, RamsDocument $rams, array $fx) {
            RamsDocument::create([
                'user_id'        => $rams->user_id,
                'project_id'     => $project->id,
                'project_ref'    => $fx['rams']['project_ref'],
                'project_name'   => $fx['rams']['project_name'],
                'client_name'    => $fx['rams']['client_name'],
                'site_address'   => $fx['rams']['site_address'],
                'ai_provider'    => 'claude',
                'ai_model'       => 'claude-sonnet-4-6',
                'filename'       => 'rams-prior.docx',
                'status'         => RamsDocument::STATUS_COMPLETED,
                'form_data'      => [],
                'generated_data' => ['project' => $fx['rams']['generated_data']['project']],
                'reviewed_data'  => $fx['prior_rams']['reviewed_data'],
            ]);
        });

        // Patch service should have auto-carried site_emergency from prior RAMS.
        $this->assertFalse($dto->emergency->isEmpty());
        $this->assertSame('Royal Berkshire Hospital, Reading', $dto->emergency->nearestHospital);
        $this->assertSame('Sarah Client',                       $dto->emergency->fireWarden);
        $this->assertSame('Riser cupboard, ground floor',       $dto->emergency->isolationSwitch);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fixture 3 — decommission-heavy
    // ══════════════════════════════════════════════════════════════════════

    public function test_decommission_heavy_scope_dto_carries_all_three_buckets(): void
    {
        [, $dto] = $this->composeFrom('decommission-heavy');

        $this->assertGreaterThanOrEqual(2, count($dto->scope->newInstall));
        $this->assertGreaterThanOrEqual(3, count($dto->scope->decommission));
        $this->assertGreaterThanOrEqual(1, count($dto->scope->retained));

        // Decommission bucket must not have leaked into new_install.
        foreach ($dto->scope->newInstall as $row) {
            $this->assertStringNotContainsStringIgnoringCase('deinstall', strtolower($row['item_name'] . ' ' . $row['notes']));
        }

        // Composer computed initial_r + residual_r from the raw L/S pair.
        $this->assertNotEmpty($dto->riskAssessment->hazards);
        $h = $dto->riskAssessment->hazards[0];
        $this->assertSame(12, $h['initial_r']); // 4×3
        $this->assertSame(4,  $h['residual_r']); // 2×2
        $this->assertSame('RA01', $h['ref']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fixture 4 — missing-survey
    // ══════════════════════════════════════════════════════════════════════

    public function test_missing_survey_produces_empty_optional_sections(): void
    {
        [, $dto] = $this->composeFrom('missing-survey');

        // No site_emergency captured — Emergency DTO empty; renderer will
        // substitute the "TBC AT SITE INDUCTION" placeholder.
        $this->assertTrue($dto->emergency->isEmpty());

        // No engineer feedback — room overviews empty.
        $this->assertTrue($dto->roomOverviews->isEmpty());

        // Welfare DTO should NOT be empty — falls back to static tier1 defaults.
        $this->assertFalse($dto->welfare->isEmpty());
        $this->assertNotSame('', $dto->welfare->toilets);

        // Method statement empty (no phases seeded, no ppe, no team).
        $this->assertTrue($dto->methodStatement->isEmpty());

        // Cover still populated from working_hours in form_data.
        $this->assertSame('Mon–Fri 09:00–17:30', $dto->cover->workingHours);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fixture 5 — empty-scope
    // ══════════════════════════════════════════════════════════════════════

    public function test_empty_scope_dto_is_empty_but_document_still_valid(): void
    {
        [, $dto] = $this->composeFrom('empty-scope');

        $this->assertInstanceOf(RamsDocumentDTO::class, $dto);
        $this->assertTrue($dto->scope->isEmpty(),
            'Scope DTO must report empty when all three equipment buckets are empty arrays.');

        // Emergency populated from reviewed_data.site_emergency.
        $this->assertFalse($dto->emergency->isEmpty());
        $this->assertSame('Leeds General Infirmary', $dto->emergency->nearestHospital);

        // Cover programme dates surfaced from reviewed_data.programme.
        $this->assertSame('10/08/2026', $dto->cover->startDate);
        $this->assertSame('12/08/2026', $dto->cover->endDate);

        // Client contact carried from generated_data.project.
        $this->assertSame('Wesley Empty',            $dto->cover->clientContactName);
        $this->assertSame('wesley@empty.co.uk',      $dto->cover->clientContactEmail);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Structural — every fixture yields a full 16-section DTO
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @dataProvider fixtureNames
     */
    public function test_every_fixture_composes_full_16_section_dto(string $fixture): void
    {
        [, $dto] = $this->composeFrom($fixture);

        $sections = $dto->toArray();
        $this->assertCount(16, $sections, "Fixture '{$fixture}' produced != 16 sections.");

        // Every section key defined in RamsTheme::sectionOrder() must be present.
        $expected = [
            'cover', 'doc_control', 'company_info', 'health_safety',
            'standards_table', 'scope', 'room_overviews', 'exclusions',
            'risk_assessment', 'method_statement', 'emergency', 'coshh',
            'environmental', 'welfare', 'signoff', 'appendix_toolbox',
        ];
        foreach ($expected as $slug) {
            $this->assertArrayHasKey($slug, $sections, "Fixture '{$fixture}' missing section '{$slug}'.");
        }
    }

    public static function fixtureNames(): array
    {
        return [
            'fresh-build'         => ['fresh-build'],
            'prior-rams-carry'    => ['prior-rams-carry'],
            'decommission-heavy'  => ['decommission-heavy'],
            'missing-survey'      => ['missing-survey'],
            'empty-scope'         => ['empty-scope'],
        ];
    }
}

<?php

namespace Tests\Feature\OmManual;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for F-OM-01 (audit 2026-05-17).
 *
 * Before this fix: OmManualGeneratorService::buildContextFromProjectData()
 * read each room's narrative from $ro['description'] inside the
 * room_overviews loop. Phase 22.1 D-01 deleted the 'description' key from
 * the canonical 4-key shape (room / overview / works_summary /
 * solution_type_id). RamsReviewDataService::normaliseRoomOverviews()
 * actively strips 'description' from any input.
 *
 * Net effect: every project quote-imported since Phase 22.1 shipped (late
 * April 2026) had empty 'description' values in room_overviews, so the
 * narrative population loop produced zero entries, and
 * OmManualValidationService rejected the generation with "narrative for
 * {room} missing".
 *
 * The fix reads $ro['overview'] (canonical) with $ro['description'] as a
 * legacy fallback for any pre-22.1 records still in production.
 *
 * @see OmManualGeneratorService::buildContextFromProjectData
 * @see .planning/audits/worksheet-om-parity-audit-2026-05-17.md (F-OM-01)
 */
class OmRoomNarrativeFromOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_narrative_populates_from_canonical_overview_key(): void
    {
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Test AV Install',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street, London',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        // Phase 22.1 canonical shape: 'overview' carries the narrative.
        // 'description' is intentionally absent.
        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test.pdf',
            'quote_path'        => 'quote-imports/test.pdf',
            'extracted_data'    => [
                'rooms' => ['Boardroom'],
                'room_overviews' => [
                    [
                        'room'             => 'Boardroom',
                        'overview'         => 'Sony 85" display + Yealink A30 bar centred at 1200mm. CTP18 controller mounted adjacent.',
                        'works_summary'    => '',
                        'solution_type_id' => null,
                    ],
                ],
            ],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $service = app(OmManualGeneratorService::class);
        $context = $service->buildContextFromProjectData($project->fresh());

        $boardroom = collect($context['rooms'] ?? [])
            ->firstWhere('name', 'Boardroom');

        $this->assertNotNull(
            $boardroom,
            'Boardroom should appear in rooms[] when extracted_data.rooms includes it'
        );
        $this->assertSame(
            'Sony 85" display + Yealink A30 bar centred at 1200mm. CTP18 controller mounted adjacent.',
            $boardroom['narrative'] ?? '',
            'Narrative MUST populate from room_overviews[].overview (Phase 22.1 canonical key)'
        );
        $this->assertSame(
            $boardroom['narrative'],
            $boardroom['description'] ?? '',
            'description should mirror narrative for legacy template back-compat'
        );
    }

    public function test_legacy_description_key_still_works_for_pre_phase_22_1_records(): void
    {
        // Backward-compat: any package extracted BEFORE Phase 22.1's normaliser
        // ran might still have $ro['description']. The fallback chain
        // (overview ?? description) must still find it.
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Legacy Project',
            'client_name'  => 'Old Client Ltd',
            'site_address' => '2 Old Road, Reading',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'legacy.pdf',
            'quote_path'        => 'quote-imports/legacy.pdf',
            'extracted_data'    => [
                'rooms' => ['Reception'],
                'room_overviews' => [
                    [
                        'room'        => 'Reception',
                        'description' => 'Legacy narrative from pre-Phase-22.1 data shape.',
                    ],
                ],
            ],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $service = app(OmManualGeneratorService::class);
        $context = $service->buildContextFromProjectData($project->fresh());

        $reception = collect($context['rooms'] ?? [])
            ->firstWhere('name', 'Reception');

        $this->assertNotNull($reception);
        $this->assertSame(
            'Legacy narrative from pre-Phase-22.1 data shape.',
            $reception['narrative'] ?? '',
            'Fallback to description key must still work for pre-22.1 records'
        );
    }

    public function test_overview_wins_over_description_when_both_present(): void
    {
        // Mixed-shape record (someone hand-edited extracted_data, or a
        // partial migration). Canonical 'overview' MUST take precedence over
        // the deprecated 'description' so the writer's intent is preserved.
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Mixed Project',
            'client_name'  => 'Mixed Client Ltd',
            'site_address' => '3 Mixed Way, Slough',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'mixed.pdf',
            'quote_path'        => 'quote-imports/mixed.pdf',
            'extracted_data'    => [
                'rooms' => ['Training Room'],
                'room_overviews' => [
                    [
                        'room'        => 'Training Room',
                        'overview'    => 'NEW canonical narrative.',
                        'description' => 'OLD legacy narrative.',
                    ],
                ],
            ],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $service = app(OmManualGeneratorService::class);
        $context = $service->buildContextFromProjectData($project->fresh());

        $training = collect($context['rooms'] ?? [])
            ->firstWhere('name', 'Training Room');

        $this->assertNotNull($training);
        $this->assertSame(
            'NEW canonical narrative.',
            $training['narrative'] ?? '',
            'overview must win over description when both present'
        );
    }
}

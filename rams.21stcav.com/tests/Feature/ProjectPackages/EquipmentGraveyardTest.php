<?php

namespace Tests\Feature\ProjectPackages;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260723-eq1 — soft-delete graveyard round-trip.
 *
 * Covers ProjectPackageReviewController::parseReviewPayload() splitting
 * incoming `equipment[]` into active rows (persisted to
 * extracted_data.equipment[]) + deleted rows (persisted to
 * extracted_data.equipment_deleted[] with deleted/deleted_at/deleted_by
 * stamps). Downstream services (OmManualGeneratorService,
 * BuildRamsDocumentJob, MiniOmBuilderService,
 * ProjectPackageRamsReviewService) only read equipment[] — the graveyard
 * is invisible to them.
 */
class EquipmentGraveyardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, package: ProjectPackage}
     */
    private function makeReviewableProject(): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'extracted_data' => [
                'equipment' => [
                    [
                        'part_number' => 'A',
                        'name'        => 'Switch',
                        'category'    => 'hardware',
                        'area'        => 'Server Room',
                        'quantity'    => 1,
                    ],
                ],
            ],
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return ['user' => $user, 'package' => $package];
    }

    /** @return array<string, string> */
    private function projectPayload(): array
    {
        return [
            'project_name' => 'P',
            'quote_ref'    => '',
            'client_name'  => '',
            'site_name'    => '',
            'site_address' => '',
            'prepared_by'  => '',
            'overview'     => '',
        ];
    }

    public function test_mixed_active_and_deleted_rows_split_into_two_arrays(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Active Display',
                    'category'    => 'hardware',
                    'area'        => 'Boardroom',
                    'quantity'    => 2,
                    'deleted'     => '0',
                ],
                [
                    'part_number' => 'B',
                    'name'        => 'Deleted Mic',
                    'category'    => 'hardware',
                    'area'        => 'Boardroom',
                    'quantity'    => 1,
                    'deleted'     => '1',
                ],
                [
                    'part_number' => 'C',
                    'name'        => 'Active Amp',
                    'category'    => 'hardware',
                    'area'        => 'Boardroom',
                    'quantity'    => 1,
                    'deleted'     => '0',
                ],
            ],
            'project' => $this->projectPayload(),
        ])->assertRedirect();

        $f['package']->refresh();

        // Active list — only the two active rows, in submission order.
        $active = $f['package']->extracted_data['equipment'];
        $this->assertCount(2, $active);
        $this->assertSame('A', $active[0]['part_number']);
        $this->assertSame('C', $active[1]['part_number']);

        // Graveyard — only the deleted row, carries stamps.
        $graveyard = $f['package']->extracted_data['equipment_deleted'] ?? [];
        $this->assertCount(1, $graveyard);
        $this->assertSame('B',         $graveyard[0]['part_number']);
        $this->assertSame('Deleted Mic', $graveyard[0]['name']);
        $this->assertTrue($graveyard[0]['deleted']);
        $this->assertNotEmpty($graveyard[0]['deleted_at']);
        $this->assertSame($f['user']->id, $graveyard[0]['deleted_by']);
    }

    public function test_active_list_never_carries_deleted_flag(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Clean Row',
                    'category'    => 'hardware',
                    'area'        => 'Room',
                    'quantity'    => 1,
                    'deleted'     => '0',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $item = $f['package']->extracted_data['equipment'][0];
        $this->assertArrayNotHasKey('deleted',     $item);
        $this->assertArrayNotHasKey('deleted_at',  $item);
        $this->assertArrayNotHasKey('deleted_by',  $item);
    }

    public function test_absent_deleted_key_treats_row_as_active(): void
    {
        // Guards against legacy client payloads that never post `deleted` at all.
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Legacy',
                    'category'    => 'hardware',
                    'area'        => '',
                    'quantity'    => 1,
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $this->assertCount(1, $f['package']->extracted_data['equipment']);
        $this->assertSame([], $f['package']->extracted_data['equipment_deleted'] ?? []);
    }

    public function test_round_trip_preserves_existing_deletion_stamps(): void
    {
        // Second save must not overwrite deleted_at + deleted_by of an already
        // soft-deleted row — the DOM re-posts them via hidden inputs.
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $originalStamp = '2025-01-15T09:30:00+00:00';

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Old Deletion',
                    'category'    => 'hardware',
                    'area'        => 'Room',
                    'quantity'    => 1,
                    'deleted'     => '1',
                    'deleted_at'  => $originalStamp,
                    'deleted_by'  => 999,
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $g = $f['package']->extracted_data['equipment_deleted'][0];
        $this->assertSame($originalStamp, $g['deleted_at']);
        $this->assertSame(999,            $g['deleted_by']);
    }

    public function test_original_totals_snapshot_ignores_deleted_rows(): void
    {
        // On the very first save (no existing _original_totals) the baseline
        // must reflect what the PM sees as active — deleted rows must not
        // inflate the historical total.
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Active',
                    'category'    => 'hardware',
                    'area'        => 'Room',
                    'quantity'    => 5,
                    'deleted'     => '0',
                ],
                [
                    'part_number' => 'A',
                    'name'        => 'Same PN but deleted',
                    'category'    => 'hardware',
                    'area'        => 'Room',
                    'quantity'    => 100,
                    'deleted'     => '1',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $this->assertSame(
            5,
            $f['package']->extracted_data['_original_totals']['A'] ?? null,
            'Original-totals baseline should only count active rows.',
        );
    }

    public function test_approve_route_also_splits_active_and_deleted(): void
    {
        // approve() calls parseReviewPayload() same as update() — deleted rows
        // must NOT be resurrected into the approved snapshot's equipment[].
        // Provide enough scaffolding to satisfy RamsReviewValidatorService.
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.approve', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Live',
                    'category'    => 'hardware',
                    'area'        => 'Boardroom',
                    'quantity'    => 1,
                    'deleted'     => '0',
                ],
                [
                    'part_number' => 'Z',
                    'name'        => 'Trashed',
                    'category'    => 'hardware',
                    'area'        => 'Boardroom',
                    'quantity'    => 1,
                    'deleted'     => '1',
                ],
            ],
            'project'    => array_merge($this->projectPayload(), ['project_name' => 'P']),
            'activities' => [
                ['key' => 'display_installation', 'label' => 'Display Installation'],
            ],
            'hazards' => [
                ['activity_key' => '', 'hazard' => 'Working at height', 'risk' => 'Medium', 'control_measures' => 'Use tower'],
            ],
            'ppe' => ['Safety Boots (steel toe cap)'],
            'room_overviews' => [
                ['room' => 'Boardroom', 'overview' => 'x', 'works_summary' => 'y', 'solution_type_id' => null],
            ],
        ]);

        $f['package']->refresh();
        $active    = $f['package']->extracted_data['equipment'];
        $graveyard = $f['package']->extracted_data['equipment_deleted'] ?? [];

        $this->assertCount(1, $active);
        $this->assertSame('A', $active[0]['part_number']);
        $this->assertCount(1, $graveyard);
        $this->assertSame('Z', $graveyard[0]['part_number']);
    }
}

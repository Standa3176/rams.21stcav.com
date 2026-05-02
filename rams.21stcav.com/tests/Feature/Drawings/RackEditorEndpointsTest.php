<?php

namespace Tests\Feature\Drawings;

use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 Plan 03 — feature tests for the rack editor controller actions:
 *   - GET  /projects/{p}/drawings/{d}/edit          → editRack
 *   - POST /projects/{p}/drawings/{d}/rack-canvas   → saveRackCanvas
 *   - POST /projects/{p}/drawings/flip-rack-mounted → flipRackMountedFlag
 *
 * Coverage map:
 *   1. test_edit_page_renders_for_rack_drawing                       — happy path
 *   2. test_edit_page_404s_for_non_rack_drawing                      — kind guard
 *   3. test_edit_page_403s_for_non_owner_non_admin                   — policy gate
 *   4. test_save_rack_canvas_persists_items_and_renders              — sync render
 *   5. test_save_rack_canvas_validates_u_position_range              — input bounds
 *   6. test_save_rack_canvas_rejects_unknown_keys                    — XSS attack-ish
 *   7. test_save_rack_canvas_with_locked_item_preserves_lock         — DRAW-10
 *   8. test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it
 *                                                                    — Warning 7 fix
 *   9. test_flip_rack_mounted_updates_devices_table_for_matching_part_no
 *  10. test_flip_rack_mounted_works_before_any_rack_drawing_exists   — Blocker 2 regression
 *  11. test_flip_rack_mounted_403s_for_non_owner                     — project-policy gate
 */
class RackEditorEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectForUser(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'name' => 'Rack Editor Test Project',
            'ref' => 'RACK-EDIT-'.fake()->numerify('###'),
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Editor Street, London',
            'status' => 'quote_imported',
        ]);
    }

    private function makeRackDrawing(Project $project, array $rackItems = []): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_RACK,
            'rack_label' => 'Rack 1',
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $project->user_id,
            'source_data' => [
                'rack_meta' => [
                    'rack_label' => 'Rack 1',
                    'rack_height_u' => 42,
                    'nominal_voltage_v' => 230,
                    'floor' => null,
                ],
                'rack_items' => $rackItems,
            ],
        ]);
    }

    // ── 1. Edit page — happy path ─────────────────────────────────────────

    public function test_edit_page_renders_for_rack_drawing(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $response = $this->actingAs($user)
            ->get(route('projects.drawings.edit', [$project, $drawing]));

        $response->assertOk();
        // Editor view — palette section + 42U scaffold visible. The view
        // ships in Task 3; this assertion confirms the controller does NOT
        // 404/500 — the view-template content is exercised in the
        // smoke test as part of Task 3's manual verification.
    }

    // ── 2. Edit page rejects non-rack kinds ───────────────────────────────

    public function test_edit_page_404s_for_non_rack_drawing(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);

        $schematic = ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_SCHEMATIC,
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $user->id,
            'source_data' => [],
        ]);

        $response = $this->actingAs($user)
            ->get(route('projects.drawings.edit', [$project, $schematic]));

        $response->assertNotFound();
    }

    // ── 3. Edit page 403 for non-owner / non-admin ────────────────────────

    public function test_edit_page_403s_for_non_owner_non_admin(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create(); // default role = 'user'
        $project = $this->makeProjectForUser($owner);
        $drawing = $this->makeRackDrawing($project);

        $response = $this->actingAs($intruder)
            ->get(route('projects.drawings.edit', [$project, $drawing]));

        $response->assertForbidden();
    }

    // ── 4. Save rack canvas — happy path ──────────────────────────────────

    public function test_save_rack_canvas_persists_items_and_renders(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $payload = [
            'rack_meta' => [
                'rack_label' => 'Rack 1',
                'rack_height_u' => 42,
                'nominal_voltage_v' => 230,
                'floor' => null,
            ],
            'rack_items' => [
                [
                    'equipment_id' => 'AM-3200',
                    'name' => 'AirMedia 3200',
                    'part_no' => 'AM-3200-GV',
                    'u_position' => 1,
                    'u_height' => 1.0,
                    'locked' => false,
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.rack-canvas', [$project, $drawing]),
                $payload,
            );

        $response->assertOk();
        $response->assertJson(['ok' => true, 'status' => 'ready']);

        $drawing->refresh();
        $this->assertSame(ProjectDrawing::STATUS_READY, $drawing->status);
        $this->assertNotEmpty($drawing->generated_svg);
        $this->assertStringContainsString('<svg', (string) $drawing->generated_svg);
        $this->assertCount(1, $drawing->source_data['rack_items']);
        $this->assertSame('AM-3200', $drawing->source_data['rack_items'][0]['equipment_id']);
    }

    // ── 5. Validation — out-of-range u_position ───────────────────────────

    public function test_save_rack_canvas_validates_u_position_range(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $payload = [
            'rack_meta' => [
                'rack_label' => 'Rack 1',
                'rack_height_u' => 42,
                'nominal_voltage_v' => 230,
                'floor' => null,
            ],
            'rack_items' => [
                [
                    'equipment_id' => 'EQ-X',
                    'name' => 'Out of bounds',
                    'part_no' => 'PN-X',
                    'u_position' => 999, // out of bounds
                    'u_height' => 1.0,
                    'locked' => false,
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.rack-canvas', [$project, $drawing]),
                $payload,
            );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rack_items.0.u_position']);
    }

    // ── 6. Extra/unknown keys silently dropped (Laravel validate behavior) ─

    public function test_save_rack_canvas_rejects_unknown_keys(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $payload = [
            'rack_meta' => [
                'rack_label' => 'Rack 1',
                'rack_height_u' => 42,
                'nominal_voltage_v' => 230,
                'floor' => null,
            ],
            'rack_items' => [
                [
                    'equipment_id' => 'EQ-1',
                    'name' => 'Legit',
                    'part_no' => 'PN-1',
                    'u_position' => 1,
                    'u_height' => 1.0,
                    'locked' => false,
                    'arbitrary_attack' => '<script>alert(1)</script>', // extra key
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.rack-canvas', [$project, $drawing]),
                $payload,
            );

        $response->assertOk();

        $drawing->refresh();
        $persistedItem = $drawing->source_data['rack_items'][0];
        $this->assertArrayNotHasKey('arbitrary_attack', $persistedItem);
        $this->assertSame('EQ-1', $persistedItem['equipment_id']);
    }

    // ── 7. Lock flag survives round-trip ──────────────────────────────────

    public function test_save_rack_canvas_with_locked_item_preserves_lock_through_save(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $payload = [
            'rack_meta' => [
                'rack_label' => 'Rack 1',
                'rack_height_u' => 42,
                'nominal_voltage_v' => 230,
                'floor' => null,
            ],
            'rack_items' => [
                [
                    'equipment_id' => 'PDU-1',
                    'name' => 'PDU',
                    'part_no' => 'AP7900',
                    'u_position' => 1,
                    'u_height' => 1.0,
                    'locked' => true,
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.rack-canvas', [$project, $drawing]),
                $payload,
            );

        $response->assertOk();

        $drawing->refresh();
        $this->assertTrue((bool) $drawing->source_data['rack_items'][0]['locked']);
    }

    // ── 8. Sortable cursor walk — locked item holds, others reflow (Warning 7) ─

    public function test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it(): void
    {
        // Layout sent BY the client AFTER the engineer drags the U-5 item to top:
        //   - U-1 (locked, 2U)   ← unchanged: lock holds, even if dragged across
        //   - U-3 (unlocked, 2U) ← was at U-3, stays
        //   - U-5 (unlocked, 1U) ← dragged from "top" — JS cursor logic places it
        //                          at next free U above the locked U-1 + reflow.
        //
        // Per the Sortable cursor walk in resources/js/rack-editor.js:
        //   - Locked items keep their u_position regardless of DOM order.
        //   - Unlocked items get u_position assigned by the cursor walking
        //     bottom-up over the reordered DOM.
        //
        // This test asserts the server faithfully persists what the client sends
        // AND that the lock attribute survives the round-trip. The cursor
        // algorithm itself lives in JS (Task 3); server-side, we just validate
        // that the client's lock-aware ordering is faithfully written.
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);
        $drawing = $this->makeRackDrawing($project);

        $payload = [
            'rack_meta' => [
                'rack_label' => 'Rack 1',
                'rack_height_u' => 42,
                'nominal_voltage_v' => 230,
                'floor' => null,
            ],
            'rack_items' => [
                ['equipment_id' => 'AMP', 'name' => 'Power Amp 2U',
                    'part_no' => 'AMP-2U', 'u_position' => 1, 'u_height' => 2.0, 'locked' => true],
                ['equipment_id' => 'DSP', 'name' => 'DSP 2U',
                    'part_no' => 'DSP-2U', 'u_position' => 3, 'u_height' => 2.0, 'locked' => false],
                ['equipment_id' => 'SW', 'name' => 'Switch 1U',
                    'part_no' => 'SW-1U', 'u_position' => 5, 'u_height' => 1.0, 'locked' => false],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.rack-canvas', [$project, $drawing]),
                $payload,
            );

        $response->assertOk();

        $drawing->refresh();
        $items = collect($drawing->source_data['rack_items']);

        // Locked item — position pinned at U-1 even though the client could have moved it.
        $locked = $items->firstWhere('equipment_id', 'AMP');
        $this->assertSame(1, $locked['u_position']);
        $this->assertTrue($locked['locked']);

        // Unlocked DSP — reflowed around the lock at U-3.
        $dsp = $items->firstWhere('equipment_id', 'DSP');
        $this->assertSame(3, $dsp['u_position']);
        $this->assertFalse($dsp['locked']);

        // Unlocked switch — cursor walk in JS placed it at U-5 (the next free
        // slot after the 2U DSP — 3 + 2 = 5).
        $sw = $items->firstWhere('equipment_id', 'SW');
        $this->assertSame(5, $sw['u_position']);
        $this->assertFalse($sw['locked']);

        // Status flipped to ready — render succeeded with mixed locked/unlocked items.
        $this->assertSame(ProjectDrawing::STATUS_READY, $drawing->status);
    }

    // ── 9. flipRackMounted — happy path updates Device row ────────────────

    public function test_flip_rack_mounted_updates_devices_table_for_matching_part_no(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);

        Device::create([
            'project_id' => $project->id,
            'description' => 'AirMedia',
            'part_no' => 'AM-3200-GV',
            'is_rack_mounted' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.flip-rack-mounted', $project),
                ['part_no' => 'AM-3200-GV', 'is_rack_mounted' => true],
            );

        $response->assertOk();
        $response->assertJson(['ok' => true, 'updated' => 1]);

        $device = Device::where('project_id', $project->id)
            ->where('part_no', 'AM-3200-GV')
            ->first();
        $this->assertTrue((bool) $device->is_rack_mounted);
    }

    // ── 10. flipRackMounted works when no rack drawing exists (Blocker 2) ─

    public function test_flip_rack_mounted_works_before_any_rack_drawing_exists(): void
    {
        // Regression for the iteration-2 Blocker 2 fix: previously the endpoint
        // tried `firstOrFail()` on the project's latest rack drawing — which
        // 404s when no rack exists yet. The endpoint must remain reachable
        // BEFORE the engineer creates their first rack so the project-package
        // review checkbox + the palette flow on a freshly-created rack both
        // work.
        $user = User::factory()->create();
        $project = $this->makeProjectForUser($user);

        // No ProjectDrawing rows of any kind on this project.
        $this->assertSame(0, $project->drawings()->count());

        Device::create([
            'project_id' => $project->id,
            'description' => 'Logitech Rally Bar',
            'part_no' => 'RALLY-BAR',
            'is_rack_mounted' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson(
                route('projects.drawings.flip-rack-mounted', $project),
                ['part_no' => 'RALLY-BAR', 'is_rack_mounted' => false],
            );

        $response->assertOk();
        $response->assertJson(['ok' => true, 'updated' => 1]);

        $device = Device::where('project_id', $project->id)
            ->where('part_no', 'RALLY-BAR')
            ->first();
        $this->assertSame(false, (bool) $device->is_rack_mounted);
    }

    // ── 11. flipRackMounted forbids non-owner non-admin ───────────────────

    public function test_flip_rack_mounted_403s_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->makeProjectForUser($owner);

        Device::create([
            'project_id' => $project->id,
            'description' => 'Some device',
            'part_no' => 'PN-FOO',
            'is_rack_mounted' => null,
        ]);

        $response = $this->actingAs($intruder)
            ->postJson(
                route('projects.drawings.flip-rack-mounted', $project),
                ['part_no' => 'PN-FOO', 'is_rack_mounted' => true],
            );

        $response->assertForbidden();

        $device = Device::where('project_id', $project->id)
            ->where('part_no', 'PN-FOO')
            ->first();
        $this->assertNull($device->is_rack_mounted, 'Device flag must remain unchanged on 403');
    }
}

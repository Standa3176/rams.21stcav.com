<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 22 Plan 03 Task 2 — feature tests for `cables:backfill-port-fks`.
 *
 * Locks the artisan contract:
 *   - Dry-run is the DEFAULT (no flag needed); --apply opts in to writes
 *   - Optional {project?} arg scopes the backfill to one project
 *   - Per-row decisions categorised as matched / ambiguous /
 *     no-device-match / already-set; counts printed in summary
 *   - WRITES happen ONLY on 'matched' — ambiguous + no-device-match leave
 *     all 4 FKs NULL (D-LOCK + DRAW-41 — DELIBERATELY tested via the
 *     'source matched / dest ambiguous' fixture below)
 *   - Idempotent — second --apply run produces zero new writes
 *   - T-22-A5 mitigated: SQL injection via {project?} arg neutralised by
 *     int cast + Eloquent parameterised bindings
 *   - T-22-A6 mitigated: wrong-tenant write impossible by construction
 *     (per-project Device load inside the iteration loop)
 *
 * @see app/Console/Commands/BackfillCablePortFksCommand.php
 */
class BackfillCablePortFksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_default_writes_nothing(): void
    {
        [$project, $schedule, $items] = $this->makeProjectWithThreeItems();

        $exit = Artisan::call('cables:backfill-port-fks');
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('[DRY RUN]', $output);
        $this->assertMatchesRegularExpression('/matched: 1/', $output);

        // Every row remains FK-null on dry-run.
        foreach ($items as $item) {
            $reloaded = $item->fresh();
            $this->assertNull($reloaded->source_device_id, 'dry-run must not write source_device_id');
            $this->assertNull($reloaded->source_port_id);
            $this->assertNull($reloaded->dest_device_id);
            $this->assertNull($reloaded->dest_port_id);
        }
    }

    public function test_apply_flag_persists_matched_fks(): void
    {
        [$project, $schedule, $items] = $this->makeProjectWithThreeItems();
        [$matchedItem] = $items;

        $exit = Artisan::call('cables:backfill-port-fks', ['--apply' => true]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringNotContainsString('[DRY RUN]', $output);
        $this->assertStringContainsString('APPLYING', $output);

        $reloaded = $matchedItem->fresh();
        $this->assertNotNull($reloaded->source_device_id, 'matched row should now carry source_device_id');
        $this->assertNotNull($reloaded->source_port_id);
        $this->assertNotNull($reloaded->dest_device_id);
        $this->assertNotNull($reloaded->dest_port_id);

        // The ambiguous and no-device-match rows MUST stay NULL.
        foreach (array_slice($items, 1) as $other) {
            $reloadedOther = $other->fresh();
            $this->assertNull($reloadedOther->source_device_id, 'non-matched row must stay NULL on --apply');
            $this->assertNull($reloadedOther->dest_device_id);
        }
    }

    public function test_project_arg_scopes_backfill(): void
    {
        [$projectA, $scheduleA, $itemsA] = $this->makeProjectWithThreeItems();
        [$projectB, $scheduleB, $itemsB] = $this->makeProjectWithThreeItems();

        Artisan::call('cables:backfill-port-fks', ['project' => $projectA->id, '--apply' => true]);

        // Project A's matched item is now populated.
        $this->assertNotNull($itemsA[0]->fresh()->source_device_id);
        // Project B's matched item is UNTOUCHED.
        $this->assertNull($itemsB[0]->fresh()->source_device_id);
    }

    public function test_idempotent_on_second_run(): void
    {
        [$project, $schedule, $items] = $this->makeProjectWithThreeItems();
        [$matchedItem] = $items;

        Artisan::call('cables:backfill-port-fks', ['--apply' => true]);
        $firstHash = [
            'source_device_id' => $matchedItem->fresh()->source_device_id,
            'source_port_id'   => $matchedItem->fresh()->source_port_id,
        ];

        $exit = Artisan::call('cables:backfill-port-fks', ['--apply' => true]);
        $this->assertSame(0, $exit);

        $secondOutput = Artisan::output();
        $this->assertMatchesRegularExpression('/already-set: 1/', $secondOutput);
        $this->assertMatchesRegularExpression('/wrote: 0/', $secondOutput);

        // Values unchanged.
        $this->assertSame($firstHash['source_device_id'], $matchedItem->fresh()->source_device_id);
        $this->assertSame($firstHash['source_port_id'],   $matchedItem->fresh()->source_port_id);
    }

    public function test_sql_injection_via_project_arg_neutralised_t22_a5(): void
    {
        // T-22-A5 — devices table must still exist after running the command
        // with a malicious project arg.
        [$project, $schedule, $items] = $this->makeProjectWithThreeItems();

        $exit = Artisan::call('cables:backfill-port-fks', [
            'project' => '5; DROP TABLE devices;',
            '--apply' => true,
        ]);
        $this->assertSame(0, $exit);

        $this->assertTrue(Schema::hasTable('devices'), 'devices table must survive SQL injection attempt');
        $this->assertTrue(Schema::hasTable('cable_schedule_items'), 'cable_schedule_items table must survive too');

        // Arg cast to int = 5; since no project with id=5 exists for our
        // freshly-created project, no rows are matched.
        $this->assertNull($items[0]->fresh()->source_device_id);
    }

    public function test_cross_project_match_impossible_by_construction_t22_a6(): void
    {
        // T-22-A6 — Project A has the only "Crestron HD-MD-400" device.
        // Project B has a cable item with from_location="Crestron HD-MD-400"
        // but NO Crestron devices of its own. Running backfill --apply WITHOUT
        // a project arg (iterates all) must NOT cross-match Project A's device
        // to Project B's cable item.
        $userA = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $userA->id]);
        $scheduleA = CableSchedule::create([
            'user_id' => $userA->id, 'project_id' => $projectA->id,
            'project_name' => 'A', 'status' => CableSchedule::STATUS_DRAFT,
        ]);
        // Project A has the Crestron device.
        $deviceA = $this->makeCatalogedDevice($projectA, 'Crestron', 'HD-MD-400', 'hdmi');

        $userB = User::factory()->create();
        $projectB = Project::factory()->create(['user_id' => $userB->id]);
        $scheduleB = CableSchedule::create([
            'user_id' => $userB->id, 'project_id' => $projectB->id,
            'project_name' => 'B', 'status' => CableSchedule::STATUS_DRAFT,
        ]);
        // Project B's cable item references Crestron text but has NO devices.
        $itemB = CableScheduleItem::create([
            'cable_schedule_id' => $scheduleB->id,
            'from_location'     => 'Crestron HD-MD-400',
            'to_location'       => 'Crestron HD-MD-400',
            'cable_type'        => 'HDMI',
            'sort_order'        => 0,
        ]);

        Artisan::call('cables:backfill-port-fks', ['--apply' => true]);

        // Project B's row stays NULL — Project A's device cannot cross over.
        $reloaded = $itemB->fresh();
        $this->assertNull($reloaded->source_device_id, 'T-22-A6: Project A device must NOT bind to Project B cable item');
        $this->assertNull($reloaded->dest_device_id);
    }

    public function test_already_set_rows_are_skipped(): void
    {
        [$project, $schedule, $items] = $this->makeProjectWithThreeItems();
        [$matchedItem] = $items;

        // Pre-populate source_device_id to simulate a previously-backfilled row.
        // forceFill+save because $fillable allows it; we want a deterministic
        // starting state regardless of resolver outcome.
        $existingDevice = Device::where('project_id', $project->id)->first();
        $matchedItem->forceFill([
            'source_device_id' => $existingDevice->id,
        ])->save();
        $beforeSourceDeviceId = $matchedItem->fresh()->source_device_id;

        $exit = Artisan::call('cables:backfill-port-fks', ['--apply' => true]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/already-set: 1/', $output);

        // Row's existing FK is untouched.
        $this->assertSame($beforeSourceDeviceId, $matchedItem->fresh()->source_device_id);
    }

    public function test_summary_line_contains_all_categories(): void
    {
        $this->makeProjectWithThreeItems();
        Artisan::call('cables:backfill-port-fks', ['--apply' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('matched:', $output);
        $this->assertStringContainsString('ambiguous:', $output);
        $this->assertStringContainsString('no-device-match:', $output);
        $this->assertStringContainsString('already-set:', $output);
        $this->assertStringContainsString('wrote:', $output);
    }

    public function test_empty_db_exits_cleanly(): void
    {
        $exit = Artisan::call('cables:backfill-port-fks');
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('No cable_schedule_items found', $output);
    }

    public function test_ambiguous_overall_leaves_all_four_fks_null(): void
    {
        // CRITICAL D-LOCK + DRAW-41 test: when overall match is 'ambiguous'
        // (even with partial diagnostic data from one side), the command MUST
        // leave ALL FOUR FK columns NULL on the row. The earlier draft's
        // partial-write branch on ambiguous contradicted the locked decision
        // and was explicitly removed. Re-read 22-03 RED test 10.
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'project_name' => 'P', 'status' => CableSchedule::STATUS_DRAFT,
        ]);

        // Source side: ONE Crestron device with ONE HDMI port → source matched.
        $srcDevice = $this->makeCatalogedDevice($project, 'Crestron', 'HD-MD-400', 'hdmi');

        // Dest side: ONE Samsung device with TWO HDMI ports → dest ambiguous.
        $dstDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Samsung QM65',
            'manufacturer' => 'Samsung',
            'model'        => 'QM65',
            'part_no'      => 'QM65',
            'qty'          => 1,
        ]);
        $dstStencil = DeviceStencil::create([
            'part_number' => DeviceStencil::normalisePartNumber('QM65'),
            'manufacturer' => 'Samsung', 'model' => 'QM65',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        DevicePort::create([
            'device_stencil_id' => $dstStencil->id, 'label' => 'HDMI 1', 'side' => DevicePort::SIDE_RIGHT,
            'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);
        DevicePort::create([
            'device_stencil_id' => $dstStencil->id, 'label' => 'HDMI 2', 'side' => DevicePort::SIDE_RIGHT,
            'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 1, 'port_id' => 'hdmi-2',
        ]);

        $item = CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id,
            'from_location'     => 'Crestron HD-MD-400',
            'to_location'       => 'Samsung QM65',
            'cable_type'        => 'HDMI',
            'sort_order'        => 0,
        ]);

        Artisan::call('cables:backfill-port-fks', ['--apply' => true]);

        $reloaded = $item->fresh();
        // D-LOCK invariant: ambiguous overall → ALL FOUR FKs NULL (not even
        // the source half from the resolver's partial diagnostics).
        $this->assertNull($reloaded->source_device_id, 'D-LOCK: source must stay NULL on overall ambiguous');
        $this->assertNull($reloaded->source_port_id, 'D-LOCK: source_port_id must stay NULL on overall ambiguous');
        $this->assertNull($reloaded->dest_device_id);
        $this->assertNull($reloaded->dest_port_id);
    }

    public function test_dry_run_output_is_clearly_tagged(): void
    {
        $this->makeProjectWithThreeItems();

        Artisan::call('cables:backfill-port-fks');
        $this->assertStringContainsString('[DRY RUN]', Artisan::output());

        Artisan::call('cables:backfill-port-fks', ['--apply' => true]);
        $this->assertStringNotContainsString('[DRY RUN]', Artisan::output());
    }

    // ── Fixture helpers ─────────────────────────────────────────────────────

    /**
     * Build a project with a schedule and 3 cable items:
     *   [0] matched          — Crestron HD-MD-400 ↔ Crestron HD-MD-400 (HDMI, 1 port)
     *   [1] ambiguous        — Crestron DM-NVX-360 ↔ Crestron DM-NVX-360 (HDMI, 2 ports)
     *   [2] no-device-match  — AMX Modero NX-1200 ↔ AMX Modero NX-1200 (no device in project)
     *
     * @return array{0: Project, 1: CableSchedule, 2: array<int, CableScheduleItem>}
     */
    private function makeProjectWithThreeItems(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        // Matched device + stencil (1 HDMI port).
        $this->makeCatalogedDevice($project, 'Crestron', 'HD-MD-400', 'hdmi');

        // Ambiguous device — has 2 HDMI ports.
        $ambiguousDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Crestron DM-NVX-360',
            'manufacturer' => 'Crestron',
            'model'        => 'DM-NVX-360',
            'part_no'      => 'DM-NVX-360',
            'qty'          => 1,
        ]);
        $ambiguousStencil = DeviceStencil::create([
            'part_number' => DeviceStencil::normalisePartNumber('DM-NVX-360'),
            'manufacturer' => 'Crestron', 'model' => 'DM-NVX-360',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        DevicePort::create([
            'device_stencil_id' => $ambiguousStencil->id, 'label' => 'HDMI 1',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi', 'signal_type' => 'video',
            'direction' => DevicePort::DIRECTION_IN, 'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);
        DevicePort::create([
            'device_stencil_id' => $ambiguousStencil->id, 'label' => 'HDMI 2',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi', 'signal_type' => 'video',
            'direction' => DevicePort::DIRECTION_IN, 'sort_order' => 1, 'port_id' => 'hdmi-2',
        ]);

        $items = [];
        // Item 0 — matched
        $items[] = CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id, 'from_location' => 'Crestron HD-MD-400',
            'to_location' => 'Crestron HD-MD-400', 'cable_type' => 'HDMI', 'sort_order' => 0,
        ]);
        // Item 1 — ambiguous
        $items[] = CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id, 'from_location' => 'Crestron DM-NVX-360',
            'to_location' => 'Crestron DM-NVX-360', 'cable_type' => 'HDMI', 'sort_order' => 1,
        ]);
        // Item 2 — no-device-match
        $items[] = CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id, 'from_location' => 'AMX Modero NX-1200',
            'to_location' => 'AMX Modero NX-1200', 'cable_type' => 'HDMI', 'sort_order' => 2,
        ]);

        return [$project, $schedule, $items];
    }

    private function makeCatalogedDevice(Project $project, string $manufacturer, string $model, string $connector): Device
    {
        $device = Device::create([
            'project_id'   => $project->id,
            'description'  => trim($manufacturer . ' ' . $model),
            'manufacturer' => $manufacturer,
            'model'        => $model,
            'part_no'      => $model,
            'qty'          => 1,
        ]);
        $stencil = DeviceStencil::firstOrCreate(
            ['part_number' => DeviceStencil::normalisePartNumber($model)],
            [
                'manufacturer' => $manufacturer, 'model' => $model,
                'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
            ]
        );
        if ($stencil->ports()->count() === 0) {
            DevicePort::create([
                'device_stencil_id' => $stencil->id, 'label' => strtoupper($connector) . ' 1',
                'side' => DevicePort::SIDE_LEFT, 'connector_type' => $connector,
                'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
                'sort_order' => 0, 'port_id' => $connector . '-1',
            ]);
        }
        return $device;
    }
}

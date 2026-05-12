<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 22 Plan 01 Task 1 — schema-level assertions for the additive
 * port-FK migration on `cable_schedule_items`.
 *
 * Locks DRAW-37 schema contract and the D-10 don't-break-v1.3 invariant:
 *   - 5 new columns (source/dest device + port FKs + connector_override_note)
 *     all nullable so legacy rows insert unchanged.
 *   - `nullOnDelete` (not `cascadeOnDelete`) so deleting a referenced Device
 *     leaves the cable row alive — text representation (from_location /
 *     to_location) survives.
 *   - Compound index on (source_port_id, dest_port_id) for Phase 23's
 *     renderer port-pair lookup queries.
 *
 * T-22-A1 (mass-assignment whitelist) is enforced at the model layer
 * (CableScheduleItemRelationsTest in Task 2) — the migration itself adds
 * no fillable defaults beyond what the model whitelists.
 */
class CableScheduleItemMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_fk_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('cable_schedule_items', 'source_device_id'));
        $this->assertTrue(Schema::hasColumn('cable_schedule_items', 'source_port_id'));
        $this->assertTrue(Schema::hasColumn('cable_schedule_items', 'dest_device_id'));
        $this->assertTrue(Schema::hasColumn('cable_schedule_items', 'dest_port_id'));
        $this->assertTrue(Schema::hasColumn('cable_schedule_items', 'connector_override_note'));
    }

    public function test_legacy_row_with_null_fks_inserts_successfully(): void
    {
        $user = User::factory()->create();
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_name' => 'Legacy',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $item = CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id,
            'from_location'     => 'Bar',
            'to_location'       => 'Display',
            'sort_order'        => 0,
        ]);

        $this->assertNotNull($item->id);
        $this->assertNull($item->source_device_id);
        $this->assertNull($item->source_port_id);
        $this->assertNull($item->dest_device_id);
        $this->assertNull($item->dest_port_id);
        $this->assertNull($item->connector_override_note);
    }

    public function test_device_delete_sets_fk_null(): void
    {
        // Ensure SQLite FK enforcement so nullOnDelete actually fires.
        \DB::statement('PRAGMA foreign_keys = ON');

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $device = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Crestron HD-MD-400 HDMI Multiformat Receiver',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'qty'          => 1,
        ]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        // Bypass $fillable — Task 2 wires the fillable whitelist; this test
        // validates the schema migration alone. Use forceCreate so the FK
        // value reaches the DB even before the model fillable extension lands.
        $item = (new CableScheduleItem)->forceFill([
            'cable_schedule_id' => $schedule->id,
            'from_location'     => 'Crestron HD-MD-400 (HDMI 1)',
            'to_location'       => 'Samsung QM65 (HDMI 1)',
            'source_device_id'  => $device->id,
            'sort_order'        => 0,
        ]);
        $item->save();

        $this->assertSame($device->id, $item->fresh()->source_device_id);

        // Delete the device. FK should null out on the cable item; the row survives.
        $device->delete();

        $item->refresh();
        $this->assertNotNull($item->id, 'cable_schedule_items row must survive device deletion (D-10)');
        $this->assertNull($item->source_device_id, 'source_device_id must be NULL after device delete (nullOnDelete, not cascadeOnDelete)');
        // Text representation preserved.
        $this->assertSame('Crestron HD-MD-400 (HDMI 1)', $item->from_location);
    }

    public function test_port_pair_index_exists(): void
    {
        // Laravel 11+ Schema::getIndexes() returns array of ['name', 'columns', 'unique', ...]
        $indexes = Schema::getIndexes('cable_schedule_items');

        $found = collect($indexes)->first(
            fn ($idx) => ($idx['name'] ?? null) === 'cable_schedule_items_port_pair_idx'
        );

        $this->assertNotNull(
            $found,
            'cable_schedule_items_port_pair_idx must exist on (source_port_id, dest_port_id) for Phase 23 renderer queries.'
        );
        $this->assertContains('source_port_id', $found['columns']);
        $this->assertContains('dest_port_id', $found['columns']);
    }

    public function test_connector_override_note_accepts_long_text(): void
    {
        $user = User::factory()->create();
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_name' => 'Override Note Test',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        // 500-char override note — should persist intact.
        $note = str_repeat('A', 500);

        // forceFill bypasses $fillable — Task 2 wires the whitelist; this
        // test asserts schema acceptance of long text in the new column.
        $item = (new CableScheduleItem)->forceFill([
            'cable_schedule_id'       => $schedule->id,
            'from_location'           => 'Source',
            'to_location'             => 'Dest',
            'connector_override_note' => $note,
            'sort_order'              => 0,
        ]);
        $item->save();

        $this->assertSame($note, $item->fresh()->connector_override_note);
    }
}

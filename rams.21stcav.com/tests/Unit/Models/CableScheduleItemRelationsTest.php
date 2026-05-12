<?php

namespace Tests\Unit\Models;

use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Phase 22 Plan 01 Task 2 — locks the CableScheduleItem contract for the
 * port-FK extension.
 *
 * Coverage:
 *   - DRAW-37 fillable whitelist (5 new keys + 9 originals)
 *   - DRAW-37 four belongsTo relations (sourceDevice/sourcePort/destDevice/destPort)
 *   - D-10 guard via reflection on $with — eager-loading port relations
 *     class-wide would force 4 LEFT JOINs on every legacy NULL-FK row
 *   - T-22-A1 mass-assignment whitelist guard (unknown keys silently dropped)
 *
 * No RefreshDatabase trait — these are pure model/reflection assertions; no
 * DB hits beyond Eloquent container resolution.
 */
class CableScheduleItemRelationsTest extends TestCase
{
    public function test_fillable_includes_all_phase_22_keys(): void
    {
        $fillable = (new CableScheduleItem)->getFillable();

        // Phase 22 additions
        $this->assertContains('source_device_id', $fillable);
        $this->assertContains('source_port_id', $fillable);
        $this->assertContains('dest_device_id', $fillable);
        $this->assertContains('dest_port_id', $fillable);
        $this->assertContains('connector_override_note', $fillable);

        // Pre-Phase-22 keys must remain
        $this->assertContains('cable_schedule_id', $fillable);
        $this->assertContains('cable_id', $fillable);
        $this->assertContains('from_location', $fillable);
        $this->assertContains('to_location', $fillable);
        $this->assertContains('cable_type', $fillable);
        $this->assertContains('cores', $fillable);
        $this->assertContains('approx_length_m', $fillable);
        $this->assertContains('notes', $fillable);
        $this->assertContains('sort_order', $fillable);
    }

    public function test_source_device_relation_belongs_to_device_via_source_device_id(): void
    {
        $rel = (new CableScheduleItem)->sourceDevice();
        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(Device::class, get_class($rel->getRelated()));
        $this->assertSame('source_device_id', $rel->getForeignKeyName());
    }

    public function test_source_port_relation_belongs_to_device_port_via_source_port_id(): void
    {
        $rel = (new CableScheduleItem)->sourcePort();
        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(DevicePort::class, get_class($rel->getRelated()));
        $this->assertSame('source_port_id', $rel->getForeignKeyName());
    }

    public function test_dest_device_relation_belongs_to_device_via_dest_device_id(): void
    {
        $rel = (new CableScheduleItem)->destDevice();
        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(Device::class, get_class($rel->getRelated()));
        $this->assertSame('dest_device_id', $rel->getForeignKeyName());
    }

    public function test_dest_port_relation_belongs_to_device_port_via_dest_port_id(): void
    {
        $rel = (new CableScheduleItem)->destPort();
        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(DevicePort::class, get_class($rel->getRelated()));
        $this->assertSame('dest_port_id', $rel->getForeignKeyName());
    }

    public function test_with_property_is_empty_to_prevent_eager_load_regression(): void
    {
        // D-10 guard. If anyone adds $with later, the v1.3 read paths gain
        // 4 LEFT JOINs per row. This test prevents that.
        //
        // Reflect on the $with property directly — Model does NOT expose a
        // getEagerLoads() method on instances without a query context, so
        // the only reliable way to assert the class-level $with is empty is
        // via reflection on the property.
        $reflection = new ReflectionProperty(CableScheduleItem::class, 'with');
        $reflection->setAccessible(true);
        $with = $reflection->getValue(new CableScheduleItem);

        $this->assertSame(
            [],
            $with,
            'CableScheduleItem::$with must stay empty — D-10 invariant. Eager-load AT THE CALL SITE only (the picker page).'
        );
    }

    public function test_unknown_keys_are_dropped_by_fillable(): void
    {
        // T-22-A1 mass-assignment guard — proves Eloquent's $fillable
        // whitelist drops any unrecognised items[N][*] key the picker
        // form might smuggle through.
        $item = new CableScheduleItem();
        $item->fill([
            'from_location'          => 'Bar',
            'admin_only'             => true,         // not in $fillable
            'cable_schedule_id_evil' => 9,            // not in $fillable
            'project_id'             => 999,          // not in $fillable
        ]);

        $this->assertSame('Bar', $item->from_location);
        $this->assertNull($item->admin_only ?? null);
        $this->assertNull($item->cable_schedule_id_evil ?? null);
        $this->assertNull($item->project_id ?? null);
    }
}

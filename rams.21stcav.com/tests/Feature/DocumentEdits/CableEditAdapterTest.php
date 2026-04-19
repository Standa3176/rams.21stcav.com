<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\CableSchedule;
use App\Models\User;
use App\Services\CableScheduleXlsxService;
use App\Services\DocumentEdits\Adapters\CableEditAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CableEditAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeScheduleWithItem(): CableSchedule
    {
        $u = User::factory()->create();
        $s = CableSchedule::create([
            'user_id'     => $u->id,
            'project_name' => 'Cable Test',
            'project_ref' => 'TEST-CABLE',
            'client_name' => 'Acme',
            'status'      => 'draft',
        ]);
        $s->items()->create([
            'cable_id'        => 'C001',
            'from_location'   => 'Rack A',
            'to_location'     => 'Display 1',
            'cable_type'      => 'HDMI',
            'cores'           => '',
            'approx_length_m' => 5.0,
            'notes'           => '',
            'sort_order'      => 0,
        ]);
        return $s->fresh('items');
    }

    public function test_add_cable_item_dedupes_on_cable_id(): void
    {
        $adapter = new CableEditAdapter();
        $base = ['items' => []];
        $a = $adapter->applyOperation($base, ['op' => 'add_cable_item', 'cable_id' => 'C-TEST', 'from_location' => 'X', 'to_location' => 'Y']);
        $this->assertTrue($a['ok']);
        $this->assertCount(1, $a['payload']['items']);

        $b = $adapter->applyOperation($a['payload'], ['op' => 'add_cable_item', 'cable_id' => 'C-TEST']);
        $this->assertCount(1, $b['payload']['items'], 'duplicate cable_id must be a no-op');
    }

    public function test_update_cable_item_field_rejects_unknown_cable(): void
    {
        $adapter = new CableEditAdapter();
        $res = $adapter->applyOperation(['items' => []], [
            'op' => 'update_cable_item_field', 'cable_id' => 'NOPE',
            'field' => 'notes', 'value' => 'x',
        ]);
        $this->assertFalse($res['ok']);
        $this->assertSame('cable_item_not_found', $res['code']);
    }

    public function test_commit_changes_syncs_items_and_regenerates_xlsx(): void
    {
        $schedule = $this->makeScheduleWithItem();

        $this->mock(CableScheduleXlsxService::class, function (MockInterface $m) {
            $m->shouldReceive('build')->andReturnUsing(fn ($s) => $s->update(['source_filename' => 'cable_after.xlsx']));
        });

        $adapter = new CableEditAdapter();
        $payload = $adapter->loadPayload($schedule->id);
        // Remove the existing item, add a new one.
        $payload['items'] = [[
            'cable_id' => 'C002', 'from_location' => 'Rack A', 'to_location' => 'Room 2',
            'cable_type' => 'Cat6a', 'cores' => '4', 'approx_length_m' => 10.5, 'notes' => 'New',
            'sort_order' => 0,
        ]];

        $filename = $adapter->commitChanges($schedule->id, $payload);
        $this->assertSame('cable_after.xlsx', $filename);
        $fresh = $schedule->fresh('items');
        $this->assertCount(1, $fresh->items);
        $this->assertSame('C002', $fresh->items->first()->cable_id);
    }
}

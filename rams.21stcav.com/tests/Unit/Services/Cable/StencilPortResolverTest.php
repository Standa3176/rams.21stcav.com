<?php

namespace Tests\Unit\Services\Cable;

use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use App\Services\Cable\StencilPortResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T2-A — StencilPortResolver locks the shared bulk stencil-by-part_number
 * lookup shape. Coverage:
 *   1. Devices are hydrated with matching stencils by normalised part_no.
 *   2. Devices with null/empty part_no explicitly get stencil relation = null.
 *   3. Devices with an unknown part_no also get stencil relation = null.
 *   4. Regardless of device count, exactly ONE whereIn query is fired for
 *      the stencil lookup (the invariant that lets the resolver replace
 *      three previously-duplicated inline blocks).
 */
class StencilPortResolverTest extends TestCase
{
    use RefreshDatabase;

    private StencilPortResolver $resolver;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new StencilPortResolver();

        $user = User::factory()->create();
        $this->project = Project::factory()->create(['user_id' => $user->id]);
    }

    public function test_attaches_stencil_by_normalised_part_number(): void
    {
        $stencil = DeviceStencil::create([
            'part_number' => 'acme-x1',
            'manufacturer' => 'Acme',
            'model' => 'X1',
            'mxgraph_xml' => '<shape/>',
            'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label' => 'HDMI 1',
            'side' => DevicePort::SIDE_RIGHT,
            'connector_type' => 'hdmi',
            'signal_type' => 'video',
            'direction' => DevicePort::DIRECTION_OUT,
            'sort_order' => 0,
            'port_id' => 'hdmi-1',
        ]);

        // part_no on Device has DIFFERENT casing/whitespace — normalisation
        // is the whole point of this lookup.
        $device = Device::create([
            'project_id' => $this->project->id,
            'description' => 'Test',
            'manufacturer' => 'Acme', 'model' => 'X1',
            'part_no' => '  ACME-X1  ',
            'qty' => 1,
        ]);

        $result = $this->resolver->attachToDevices(collect([$device]));

        $this->assertSame(1, $result->count());
        $attached = $result->first();
        $this->assertNotNull($attached->getRelation('stencil'), 'Expected stencil to be attached.');
        $this->assertSame($stencil->id, $attached->getRelation('stencil')->id);
        $this->assertCount(1, $attached->getRelation('stencil')->ports);
    }

    public function test_device_with_null_part_no_gets_null_stencil_relation(): void
    {
        $device = Device::create([
            'project_id' => $this->project->id,
            'description' => 'No PN',
            'manufacturer' => 'Acme', 'model' => 'X1',
            'part_no' => null,
            'qty' => 1,
        ]);

        $result = $this->resolver->attachToDevices(collect([$device]));

        $this->assertTrue($result->first()->relationLoaded('stencil'));
        $this->assertNull($result->first()->getRelation('stencil'));
    }

    public function test_device_with_unknown_part_no_gets_null_stencil_relation(): void
    {
        // No stencil created for this part_no.
        $device = Device::create([
            'project_id' => $this->project->id,
            'description' => 'Unknown',
            'manufacturer' => 'Acme', 'model' => 'X99',
            'part_no' => 'acme-x99',
            'qty' => 1,
        ]);

        $result = $this->resolver->attachToDevices(collect([$device]));

        $this->assertTrue($result->first()->relationLoaded('stencil'));
        $this->assertNull($result->first()->getRelation('stencil'));
    }

    public function test_uses_single_whereIn_query(): void
    {
        // Create 5 stencils + 5 devices — the resolver must batch into ONE
        // whereIn regardless of how many devices we pass.
        foreach (range(1, 5) as $i) {
            DeviceStencil::create([
                'part_number' => "pn-{$i}",
                'manufacturer' => 'Acme',
                'model' => "X{$i}",
                'mxgraph_xml' => '<shape/>',
                'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
            ]);
        }

        $devices = collect(range(1, 5))->map(fn ($i) => Device::create([
            'project_id' => $this->project->id,
            'description' => "D{$i}",
            'manufacturer' => 'Acme', 'model' => "X{$i}",
            'part_no' => "pn-{$i}",
            'qty' => 1,
        ]));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->resolver->attachToDevices($devices);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $selectStencilsQueries = array_filter(
            $log,
            fn ($e) => stripos($e['query'], 'from "device_stencils"') !== false
                || stripos($e['query'], 'from `device_stencils`') !== false
        );

        $this->assertCount(
            1,
            $selectStencilsQueries,
            'StencilPortResolver::attachToDevices must batch stencil lookup into a single whereIn query.'
        );
    }
}

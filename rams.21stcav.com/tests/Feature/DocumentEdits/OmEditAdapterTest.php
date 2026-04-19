<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\OmManual;
use App\Models\User;
use App\Services\DocumentEdits\Adapters\OmEditAdapter;
use App\Services\OmManualDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OmEditAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeOm(): OmManual
    {
        $u = User::factory()->create();
        return OmManual::create([
            'user_id'        => $u->id,
            'project_name'   => 'O&M Test',
            'project_ref'    => 'TEST-OM',
            'client_name'    => 'Acme',
            'site_address'   => '1 Test St',
            'status'         => OmManual::STATUS_DRAFT,
            'generated_data' => ['project' => ['name' => 'O&M Test'], 'contacts' => []],
            'extracted_data' => [],
        ]);
    }

    public function test_add_contact_appends_and_dedupes(): void
    {
        $adapter = new OmEditAdapter();
        $base = ['generated_data' => ['contacts' => []], 'extracted_data' => []];

        $a = $adapter->applyOperation($base, ['op' => 'add_contact', 'name' => 'Jane', 'role' => 'PM']);
        $this->assertTrue($a['ok']);
        $this->assertCount(1, $a['payload']['generated_data']['contacts']);

        $b = $adapter->applyOperation($a['payload'], ['op' => 'add_contact', 'name' => 'Jane', 'role' => 'PM']);
        $this->assertCount(1, $b['payload']['generated_data']['contacts'], 'duplicate name+role must be a no-op');
    }

    public function test_add_maintenance_item_requires_task_and_frequency(): void
    {
        $adapter = new OmEditAdapter();
        $res = $adapter->applyOperation(['generated_data' => []], ['op' => 'add_maintenance_item', 'task' => 'Clean display']);
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_op', $res['code']);
    }

    public function test_commit_changes_regenerates_docx(): void
    {
        $om = $this->makeOm();

        $this->mock(OmManualDocxService::class, function (MockInterface $m) {
            $m->shouldReceive('build')->andReturnUsing(fn ($payload, $om) => $om->update(['filename' => 'om_after.docx']));
        });

        $adapter = new OmEditAdapter();
        $payload = $adapter->loadPayload($om->id);
        $payload['generated_data']['contacts'][] = ['name' => 'Jane', 'role' => 'PM', 'email' => '', 'phone' => ''];

        $filename = $adapter->commitChanges($om->id, $payload);
        $this->assertSame('om_after.docx', $filename);
        $this->assertSame('om_after.docx', $om->fresh()->filename);
    }
}

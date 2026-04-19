<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocumentEdits\Adapters\RamsEditAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RamsEditAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(): RamsDocument
    {
        $u = User::factory()->create();
        return RamsDocument::create([
            'user_id'       => $u->id,
            'project_name'  => 'RAMS Test',
            'client_name'   => 'Acme',
            'site_address'  => '1 Test St',
            'project_ref'   => 'TEST-RAMS',
            'status'        => 'approved',
            'filename'      => 'test.docx',
            'ai_provider'   => 'claude',
            'ai_model'      => 'claude-sonnet',
            'form_data'     => ['source' => 'test'],
            'generated_data' => ['project' => ['name' => 'RAMS Test', 'ref' => 'TEST-RAMS']],
            'reviewed_data'  => ['exclusions' => ['No structural works']],
        ]);
    }

    public function test_update_project_field_updates_generated_data(): void
    {
        $rams = $this->makeRams();
        $adapter = new RamsEditAdapter();
        $payload = $adapter->loadPayload($rams->id);

        $res = $adapter->applyOperation($payload, ['op' => 'update_project_field', 'field' => 'ref', 'value' => 'NEW-REF']);
        $this->assertTrue($res['ok']);
        $this->assertSame('NEW-REF', $res['payload']['generated_data']['project']['ref']);
    }

    public function test_update_project_field_rejects_field_not_in_allow_list(): void
    {
        $rams = $this->makeRams();
        $res = (new RamsEditAdapter())->applyOperation(
            $adapter_payload = ['generated_data' => [], 'reviewed_data' => []],
            ['op' => 'update_project_field', 'field' => 'secret_key', 'value' => 'x'],
        );
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_op', $res['code']);
    }

    public function test_add_exclusion_is_idempotent(): void
    {
        $adapter = new RamsEditAdapter();
        $base = ['generated_data' => [], 'reviewed_data' => ['exclusions' => ['Foo']]];
        $a = $adapter->applyOperation($base, ['op' => 'add_exclusion', 'text' => 'Foo']);
        $this->assertTrue($a['ok']);
        $this->assertCount(1, $a['payload']['reviewed_data']['exclusions']);

        $b = $adapter->applyOperation($base, ['op' => 'add_exclusion', 'text' => 'Bar']);
        $this->assertCount(2, $b['payload']['reviewed_data']['exclusions']);
    }

    public function test_commit_changes_persists_to_model(): void
    {
        $rams = $this->makeRams();
        $adapter = new RamsEditAdapter();

        $payload = $adapter->loadPayload($rams->id);
        $payload['reviewed_data']['exclusions'][] = 'No asbestos';

        $filename = $adapter->commitChanges($rams->id, $payload);
        $this->assertNull($filename, 'RAMS adapter does not regenerate the artifact');

        $fresh = $rams->fresh();
        $this->assertContains('No asbestos', $fresh->reviewed_data['exclusions']);
    }
}

<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\DocumentChangeSet;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DocumentEdits\Adapters\WorksheetEditAdapter;
use App\Services\WorksheetDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Pass B — full round-trip for the worksheet edit pipeline.
 *
 * Uses a Mockery mock for WorksheetDocxService so the test suite doesn't write
 * real .docx files to the filesystem; every test that exercises the apply
 * endpoint sets worksheet.filename manually inside the expectation closure so
 * the adapter can return it from commitChanges().
 */
class WorksheetAdapterApplyTest extends TestCase
{
    use RefreshDatabase;

    private ?User $currentUser = null;

    private function auth(): User
    {
        $this->currentUser = User::factory()->create();
        $this->actingAs($this->currentUser);
        return $this->currentUser;
    }

    private function makeWorksheet(array $generatedData = []): Worksheet
    {
        // Pass-C ownership checks require the worksheet to belong to the user
        // the test is acting as. If auth() was called first, reuse that user;
        // otherwise fall back to a fresh owner (the auth-required tests will
        // assert 401 before ownership is ever evaluated).
        $u = $this->currentUser ?? User::factory()->create();
        return Worksheet::create([
            'user_id'        => $u->id,
            'project_name'   => 'DocEdit B Test',
            'client_name'    => 'Acme',
            'project_ref'    => 'TEST-B',
            'status'         => 'draft',
            'generated_data' => $generatedData ?: $this->defaultPayload(),
        ]);
    }

    private function defaultPayload(): array
    {
        return [
            'project'  => ['name' => 'DocEdit B Test'],
            'rooms'    => [
                [
                    'name'                   => 'Boardroom',
                    'is_surveyed'            => true,
                    'equipment'              => [],
                    'subsystems'             => ['Display' => [['name' => 'Samsung 75"']]],
                    'tools'                  => ['Drill', 'Spirit level'],
                    'install_steps'          => ['Step 1 — survey', 'Step 2 — unpack kit'],
                    'category_summary'       => 'Display',
                    'room_works_description' => 'Install one display.',
                ],
            ],
            'blockers' => [
                ['type' => 'survey', 'message' => 'Site survey not completed.',
                 'action' => 'Complete site survey.', 'room' => '(project)', 'source' => 'no_survey'],
            ],
        ];
    }

    private function mockDocxService(?string $artifactName = 'worksheet_b_applied.docx'): void
    {
        $this->mock(WorksheetDocxService::class, function (MockInterface $m) use ($artifactName) {
            $m->shouldReceive('build')
                ->andReturnUsing(function (array $payload, Worksheet $ws) use ($artifactName) {
                    $ws->update(['filename' => $artifactName]);
                });
        });
    }

    // ─── Unit-ish: adapter direct ────────────────────────────────────────────

    public function test_add_blocker_appends_and_is_idempotent(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $op = ['op' => 'add_blocker', 'type' => 'power', 'message' => 'Room 2 requires extra outlets.', 'action' => 'Confirm with electrician.', 'room' => 'Boardroom'];
        $first  = $adapter->applyOperation($payload, $op);
        $this->assertTrue($first['ok']);
        $this->assertCount(2, $first['payload']['blockers']);

        $second = $adapter->applyOperation($first['payload'], $op);
        $this->assertTrue($second['ok']);
        $this->assertCount(2, $second['payload']['blockers'], 'same blocker twice must be a no-op');
    }

    public function test_remove_blocker_by_source_is_noop_when_absent(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'remove_blocker', 'source' => 'doesnt_exist']);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['payload']['blockers']);
    }

    public function test_remove_blocker_by_source_removes_match(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'remove_blocker', 'source' => 'no_survey']);
        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['payload']['blockers']);
    }

    public function test_add_tool_is_idempotent(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $a = $adapter->applyOperation($payload, ['op' => 'add_tool', 'room' => 'Boardroom', 'tool' => 'Drill']);
        $this->assertTrue($a['ok']);
        $this->assertSame(['Drill', 'Spirit level'], $a['payload']['rooms'][0]['tools']);

        $b = $adapter->applyOperation($payload, ['op' => 'add_tool', 'room' => 'Boardroom', 'tool' => 'Torque driver']);
        $this->assertSame(['Drill', 'Spirit level', 'Torque driver'], $b['payload']['rooms'][0]['tools']);
    }

    public function test_remove_tool_removes(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'remove_tool', 'room' => 'Boardroom', 'tool' => 'Drill']);
        $this->assertTrue($res['ok']);
        $this->assertSame(['Spirit level'], $res['payload']['rooms'][0]['tools']);
    }

    public function test_append_install_step_appends(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'append_install_step', 'room' => 'Boardroom', 'step' => 'Step 3 — commission']);
        $this->assertTrue($res['ok']);
        $this->assertCount(3, $res['payload']['rooms'][0]['install_steps']);
        $this->assertSame('Step 3 — commission', $res['payload']['rooms'][0]['install_steps'][2]);
    }

    public function test_replace_install_step_at_valid_index(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'replace_install_step', 'room' => 'Boardroom', 'index' => 0, 'step' => 'Step 1 — revised']);
        $this->assertTrue($res['ok']);
        $this->assertSame('Step 1 — revised', $res['payload']['rooms'][0]['install_steps'][0]);
        $this->assertSame('Step 2 — unpack kit', $res['payload']['rooms'][0]['install_steps'][1]);
    }

    public function test_replace_install_step_out_of_range(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'replace_install_step', 'room' => 'Boardroom', 'index' => 99, 'step' => 'x']);
        $this->assertFalse($res['ok']);
        $this->assertSame('step_index_out_of_range', $res['code']);
    }

    public function test_update_room_summary_changes_category_summary(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'update_room_summary', 'room' => 'Boardroom', 'category_summary' => 'Display and Audio']);
        $this->assertTrue($res['ok']);
        $this->assertSame('Display and Audio', $res['payload']['rooms'][0]['category_summary']);
    }

    public function test_room_not_found_returns_error_code(): void
    {
        $adapter = new WorksheetEditAdapter();
        $payload = $this->defaultPayload();

        $res = $adapter->applyOperation($payload, ['op' => 'add_tool', 'room' => 'Nowhere', 'tool' => 'Hammer']);
        $this->assertFalse($res['ok']);
        $this->assertSame('room_not_found', $res['code']);
    }

    // ─── Controller end-to-end ───────────────────────────────────────────────

    public function test_apply_creates_revision_with_mutated_payload_and_artifact(): void
    {
        $this->mockDocxService('worksheet_after_apply.docx');
        $this->auth();
        $ws = $this->makeWorksheet();

        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $csRes  = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Add a blocker and a tool.',
            'operations_json' => [
                ['op' => 'add_blocker', 'type' => 'power', 'message' => 'Confirm outlets.', 'action' => 'Talk to sparky.', 'room' => 'Boardroom'],
                ['op' => 'add_tool', 'room' => 'Boardroom', 'tool' => 'Torque driver'],
            ],
        ]);
        $csRes->assertStatus(201)->assertJsonPath('change_set.status', 'validated');
        $csId = $csRes->json('change_set.id');

        $apply = $this->postJson("/documents/worksheet/{$ws->id}/changes/{$csId}/apply");
        $apply->assertStatus(200)
            ->assertJsonPath('change_set.status', 'applied')
            ->assertJsonPath('artifact_filename', 'worksheet_after_apply.docx');

        $newRevId = $apply->json('new_revision.id');
        $this->assertNotNull($newRevId);

        /** @var DocumentRevision $rev */
        $rev = DocumentRevision::findOrFail($newRevId);
        $this->assertSame('ai_chat', $rev->source);
        $this->assertSame('worksheet_after_apply.docx', $rev->artifact_filename);

        $payload = $rev->payload_snapshot;
        $this->assertCount(2, $payload['blockers'], 'Base blocker + the one we added');
        $this->assertContains('Torque driver', $payload['rooms'][0]['tools']);

        // Worksheet row itself updated.
        $this->assertSame('worksheet_after_apply.docx', $ws->fresh()->filename);
        $fresh = $ws->fresh()->generated_data;
        $this->assertContains('Torque driver', $fresh['rooms'][0]['tools']);
    }

    public function test_show_change_set_includes_preview_diff(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();

        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $csRes  = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Preview diff check.',
            'operations_json' => [
                ['op' => 'add_tool',              'room' => 'Boardroom', 'tool' => 'Torque driver'],
                ['op' => 'append_install_step',   'room' => 'Boardroom', 'step' => 'Step 3 — commission'],
            ],
        ]);
        $csId = $csRes->json('change_set.id');

        $show = $this->getJson("/documents/worksheet/{$ws->id}/changes/{$csId}");
        $show->assertStatus(200)
            ->assertJsonPath('preview.before_summary.tools_total', 2)
            ->assertJsonPath('preview.after_summary.tools_total',  3)
            ->assertJsonPath('preview.changed_blockers_count',     0)
            ->assertJsonPath('preview.changed_rooms.0.room',       'Boardroom');
    }

    public function test_apply_returns_422_when_operation_targets_unknown_room(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $csRes = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'    => 'assistant',
            'content' => 'Bad room.',
            'operations_json' => [
                ['op' => 'add_tool', 'room' => 'Nowhere', 'tool' => 'Hammer'],
            ],
        ]);
        $csId = $csRes->json('change_set.id');

        $apply = $this->postJson("/documents/worksheet/{$ws->id}/changes/{$csId}/apply");
        $apply->assertStatus(422)
            ->assertJsonPath('error', 'adapter_apply_failed')
            ->assertJsonFragment(['code' => 'room_not_found']);

        $this->assertSame('rejected', DocumentChangeSet::find($csId)->status);
    }

    public function test_apply_does_not_500_when_docx_service_throws(): void
    {
        // Simulate DOCX service failure — adapter catches and returns null
        // filename, controller still records revision and marks applied.
        $this->mock(WorksheetDocxService::class, function (MockInterface $m) {
            $m->shouldReceive('build')->andThrow(new \RuntimeException('synthetic docx failure'));
        });
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $csRes = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Add tool but docx will fail.',
            'operations_json' => [['op' => 'add_tool', 'room' => 'Boardroom', 'tool' => 'Torque driver']],
        ]);
        $csId = $csRes->json('change_set.id');

        $apply = $this->postJson("/documents/worksheet/{$ws->id}/changes/{$csId}/apply");
        $apply->assertStatus(200)
            ->assertJsonPath('change_set.status', 'applied')
            ->assertJsonPath('artifact_filename', null);

        $this->assertSame('applied', DocumentChangeSet::find($csId)->status);
    }
}

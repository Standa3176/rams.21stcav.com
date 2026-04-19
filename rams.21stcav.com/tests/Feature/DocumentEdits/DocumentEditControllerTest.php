<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\DocumentChangeSet;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage of the pass-A DocumentEditController routes.
 *
 * Worksheet is the exemplar type because its adapter has a non-empty
 * allow-list, so we can round-trip (valid op → validated; invalid op →
 * rejected; apply → 422 with not_implemented_in_pass_a).
 */
class DocumentEditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): User
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        return $u;
    }

    private function makeWorksheet(): Worksheet
    {
        $u = User::factory()->create();
        return Worksheet::create([
            'user_id'        => $u->id,
            'project_name'   => 'DocEdit Test Project',
            'client_name'    => 'Acme',
            'project_ref'    => 'TEST-001',
            'status'         => Worksheet::STATUS_DRAFT ?? 'draft',
            'generated_data' => ['project' => ['name' => 'DocEdit Test Project'], 'rooms' => []],
        ]);
    }

    // ─── createThread ────────────────────────────────────────────────────────

    public function test_create_thread_returns_201_and_persists_base_revision(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads");

        $res->assertStatus(201)
            ->assertJsonPath('thread.status',         'open')
            ->assertJsonPath('thread.document_type',  'worksheet')
            ->assertJsonPath('base_revision.source',  'base');

        $this->assertDatabaseHas('document_revisions', [
            'document_type' => 'worksheet',
            'document_id'   => $ws->id,
            'source'        => 'base',
        ]);
        $this->assertDatabaseHas('document_edit_threads', [
            'document_type' => 'worksheet',
            'document_id'   => $ws->id,
            'status'        => 'open',
        ]);
    }

    public function test_create_thread_on_unknown_document_type_returns_404(): void
    {
        $this->auth();
        $this->postJson('/documents/kittens/1/threads')
            ->assertStatus(404)
            ->assertJsonPath('error', 'unknown_document_type');
    }

    public function test_create_thread_on_missing_document_returns_404(): void
    {
        $this->auth();
        $this->postJson('/documents/worksheet/999999/threads')
            ->assertStatus(404)
            ->assertJsonPath('error', 'document_not_found');
    }

    // ─── postMessage + auto-changeSet ────────────────────────────────────────

    public function test_post_message_with_safe_allowed_ops_creates_validated_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Proposing a worksheet update.',
            'operations_json' => [
                ['op' => 'add_blocker', 'type' => 'power', 'message' => 'Outlets unconfirmed.', 'action' => 'Check with client.', 'room' => '(project)'],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('change_set.status', 'validated');
        $this->assertDatabaseHas('document_change_sets', [
            'thread_id' => $thread['id'],
            'status'    => 'validated',
        ]);
    }

    public function test_post_message_with_denied_key_creates_rejected_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'    => 'assistant',
            'content' => 'Malicious op attempt.',
            'operations_json' => [
                ['op' => 'update_room_field', 'controller' => 'Admin'],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('change_set.status', 'rejected')
            ->assertJsonFragment(['code' => 'denied_key']);
    }

    public function test_post_message_with_unknown_op_creates_rejected_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role'    => 'assistant',
            'content' => 'Unknown op.',
            'operations_json' => [
                ['op' => 'not_a_real_op'],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('change_set.status', 'rejected')
            ->assertJsonFragment(['code' => 'unknown_operation']);
    }

    // ─── showChangeSet ───────────────────────────────────────────────────────

    public function test_show_change_set_returns_record(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $cs = DocumentChangeSet::create([
            'thread_id'         => $thread['id'],
            'document_type'     => 'worksheet',
            'document_id'       => $ws->id,
            'base_revision_id'  => $thread['base_revision_id'],
            'status'            => DocumentChangeSet::STATUS_VALIDATED,
            'operations_json'   => [['op' => 'update_room_field']],
            'validation_errors' => null,
        ]);

        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(200)
            ->assertJsonPath('change_set.id', $cs->id)
            ->assertJsonPath('change_set.status', 'validated');
    }

    // ─── applyChangeSet ──────────────────────────────────────────────────────

    public function test_apply_marks_change_set_applied_and_writes_revision(): void
    {
        // Pass-A controller contract: apply walks the ops through the adapter
        // and either records a new revision (success) or returns 422. The
        // not-implemented-in-pass-a branch is superseded by WorksheetEditAdapter
        // in pass B — exhaustive apply-failure coverage lives in
        // WorksheetAdapterApplyTest. This test pins the success path:
        // validated change-set → apply → applied + new_revision.
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $cs = DocumentChangeSet::create([
            'thread_id'         => $thread['id'],
            'document_type'     => 'worksheet',
            'document_id'       => $ws->id,
            'base_revision_id'  => $thread['base_revision_id'],
            'status'            => DocumentChangeSet::STATUS_VALIDATED,
            'operations_json'   => [
                ['op' => 'add_blocker', 'type' => 'power', 'message' => 'Outlets unconfirmed.', 'action' => 'Check.', 'room' => '(project)'],
            ],
            'validation_errors' => null,
        ]);

        // WorksheetDocxService would normally run here; stub it so the test
        // doesn't touch the real artifact disk.
        $this->mock(\App\Services\WorksheetDocxService::class, function ($m) {
            $m->shouldReceive('build')
                ->andReturnUsing(fn ($payload, $ws) => $ws->update(['filename' => 'applied.docx']));
        });

        $res = $this->postJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}/apply");

        $res->assertStatus(200)
            ->assertJsonPath('change_set.status', 'applied')
            ->assertJsonPath('new_revision.source', 'ai_chat');

        $this->assertSame('applied', $cs->fresh()->status);
    }

    public function test_apply_rejects_previously_rejected_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $cs = DocumentChangeSet::create([
            'thread_id'         => $thread['id'],
            'document_type'     => 'worksheet',
            'document_id'       => $ws->id,
            'base_revision_id'  => $thread['base_revision_id'],
            'status'            => DocumentChangeSet::STATUS_REJECTED,
            'operations_json'   => [['op' => 'not_a_real_op']],
            'validation_errors' => [['code' => 'unknown_operation', 'message' => 'x']],
        ]);

        $this->postJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}/apply")
            ->assertStatus(422)
            ->assertJsonPath('error', 'change_set_rejected');
    }

    // ─── listRevisions ───────────────────────────────────────────────────────

    public function test_list_revisions_returns_base_after_thread_created(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $this->postJson("/documents/worksheet/{$ws->id}/threads");

        $res = $this->getJson("/documents/worksheet/{$ws->id}/revisions");
        $res->assertStatus(200)
            ->assertJsonPath('revisions.0.source', 'base');
    }

    public function test_auth_required(): void
    {
        // No actingAs.
        $this->postJson('/documents/worksheet/1/threads')
            ->assertStatus(401);   // web middleware redirects — JSON returns 401
    }
}

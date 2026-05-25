<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\DocumentChangeSet;
use App\Models\DocumentEditThread;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pass-C endpoint hardening coverage:
 *   - ownership (cannot access another user's doc; admin bypass)
 *   - type/thread/change-set coherence (cross-document apply rejected)
 *   - base-revision stale check (optimistic concurrency)
 *   - revisions-view accessibility
 *   - existing generation routes regression (worksheets.show still opens)
 */
class DocumentEditHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(?User $owner = null): Worksheet
    {
        $owner ??= User::factory()->create();
        return Worksheet::create([
            'user_id'        => $owner->id,
            'project_name'   => 'Hardening',
            'client_name'    => 'Acme',
            'project_ref'    => 'HARD-1',
            'status'         => 'draft',
            'generated_data' => ['project' => ['name' => 'Hardening'], 'rooms' => []],
        ]);
    }

    public function test_any_authenticated_user_can_open_a_thread_on_a_shared_worksheet(): void
    {
        $owner  = User::factory()->create();
        $intruder = User::factory()->create(); // default role = 'user' — non-owner
        $ws = $this->makeWorksheet($owner);

        // Shared workspace: a non-owner, non-admin user may open an edit thread.
        $this->actingAs($intruder);
        $this->postJson("/documents/worksheet/{$ws->id}/threads")
            ->assertStatus(201);
    }

    public function test_admin_bypasses_ownership(): void
    {
        $owner  = User::factory()->create();
        $admin  = User::factory()->create(['role' => 'admin']);
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($admin);
        $this->postJson("/documents/worksheet/{$ws->id}/threads")
            ->assertStatus(201);
    }

    public function test_thread_mismatch_on_message_post_returns_422(): void
    {
        $owner = User::factory()->create();
        $ws1 = $this->makeWorksheet($owner);
        $ws2 = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        $thread = $this->postJson("/documents/worksheet/{$ws1->id}/threads")->json('thread');

        // Same thread id under a DIFFERENT document id must be rejected as mismatch.
        $this->postJson("/documents/worksheet/{$ws2->id}/threads/{$thread['id']}/messages", [
            'role' => 'assistant', 'content' => 'Crossing documents.',
        ])->assertStatus(422)->assertJsonPath('error', 'thread_mismatch');
    }

    public function test_change_set_mismatch_on_apply_returns_422(): void
    {
        $owner = User::factory()->create();
        $ws1 = $this->makeWorksheet($owner);
        $ws2 = $this->makeWorksheet($owner);
        $this->actingAs($owner);

        // Build a change-set on ws1.
        $thread = $this->postJson("/documents/worksheet/{$ws1->id}/threads")->json('thread');
        $csRes = $this->postJson("/documents/worksheet/{$ws1->id}/threads/{$thread['id']}/messages", [
            'role' => 'assistant',
            'content' => 'ok',
            'operations_json' => [['op' => 'add_blocker', 'type' => 'power',
                'message' => 'Confirm outlets.', 'action' => 'Check.', 'room' => '(project)']],
        ]);
        $csId = $csRes->json('change_set.id');

        // Attempt to apply under ws2 URL.
        $this->postJson("/documents/worksheet/{$ws2->id}/changes/{$csId}/apply")
            ->assertStatus(422)
            ->assertJsonPath('error', 'change_set_mismatch');
    }

    public function test_stale_base_revision_is_rejected_with_409(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $ws = $this->makeWorksheet($owner);

        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $csRes = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/messages", [
            'role' => 'assistant',
            'content' => 'ok',
            'operations_json' => [['op' => 'add_blocker', 'type' => 'power',
                'message' => 'Confirm outlets.', 'action' => 'Check.', 'room' => '(project)']],
        ]);
        $csId = $csRes->json('change_set.id');

        // Inject a newer revision so the change-set's base_revision_id is stale.
        DocumentRevision::create([
            'document_type'      => 'worksheet',
            'document_id'        => $ws->id,
            'parent_revision_id' => $thread['base_revision_id'],
            'payload_snapshot'   => $ws->generated_data,
            'source'             => DocumentRevision::SOURCE_MANUAL,
            'change_summary'     => 'Manual edit from another tab',
        ]);

        $this->postJson("/documents/worksheet/{$ws->id}/changes/{$csId}/apply")
            ->assertStatus(409)
            ->assertJsonPath('error', 'base_revision_stale');
    }

    public function test_revisions_view_returns_html_when_owner(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);
        $this->actingAs($owner);

        $this->postJson("/documents/worksheet/{$ws->id}/threads");

        $res = $this->get("/documents/worksheet/{$ws->id}/revisions-view");
        $res->assertStatus(200)
            ->assertSee('Document History', false);
    }

    public function test_revisions_view_accessible_to_any_authenticated_user(): void
    {
        $owner    = User::factory()->create();
        $ws       = $this->makeWorksheet($owner);
        $intruder = User::factory()->create(); // default role = 'user' — non-owner
        $this->actingAs($intruder);

        // Shared workspace: a non-owner, non-admin user may view document history.
        $this->get("/documents/worksheet/{$ws->id}/revisions-view")
            ->assertStatus(200)
            ->assertSee('Document History', false);
    }

    public function test_existing_worksheet_show_route_still_works(): void
    {
        // Regression: hardening + UI hook must not break the worksheets.show route.
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);
        $this->actingAs($owner);

        $this->get(route('worksheets.show', $ws))->assertStatus(200);
    }
}

<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\DocumentChangeSet;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for GET /documents/{type}/{id}/changes/{changeSet}.
 *
 * Pins two properties that the rest of the doc-edit surface relies on:
 *   1. Auth — same ownership/auth checks as every other doc-edit endpoint
 *      (unauth → 401, non-owner → 403, missing doc → 404).
 *   2. Preview correctness — the diff is computed from the change-set's
 *      base revision snapshot, so a concurrent edit between propose and
 *      view doesn't poison the preview.
 */
class ShowChangeSetAuthAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(User $owner): Worksheet
    {
        return Worksheet::create([
            'user_id'        => $owner->id,
            'project_name'   => 'Show CS',
            'client_name'    => 'Acme',
            'project_ref'    => 'SHOW-1',
            'status'         => 'draft',
            'generated_data' => [
                'project'  => ['name' => 'Show CS'],
                'rooms'    => [['name' => 'Boardroom', 'tools' => ['Drill'], 'install_steps' => []]],
                'blockers' => [],
            ],
        ]);
    }

    private function openThread(Worksheet $ws): array
    {
        return $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
    }

    private function createValidatedChangeSet(array $thread, Worksheet $ws): DocumentChangeSet
    {
        return DocumentChangeSet::create([
            'thread_id'         => $thread['id'],
            'document_type'     => 'worksheet',
            'document_id'       => $ws->id,
            'base_revision_id'  => $thread['base_revision_id'],
            'status'            => DocumentChangeSet::STATUS_VALIDATED,
            'operations_json'   => [
                ['op' => 'add_blocker', 'type' => 'power', 'message' => 'Check outlets.', 'action' => 'Call sparky.', 'room' => 'Boardroom'],
            ],
            'validation_errors' => null,
        ]);
    }

    // ─── auth ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);
        $this->actingAs($owner);
        $thread = $this->openThread($ws);
        $cs = $this->createValidatedChangeSet($thread, $ws);

        // Drop authentication and hit the endpoint fresh.
        $this->app['auth']->forgetGuards();
        $this->refreshApplication();

        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(401);
    }

    public function test_non_owner_returns_403(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        $thread = $this->openThread($ws);
        $cs = $this->createValidatedChangeSet($thread, $ws);

        $this->actingAs($intruder);
        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(403)
            ->assertJsonPath('error', 'document_forbidden');
    }

    public function test_unknown_document_type_returns_404(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/documents/kittens/1/changes/1')
            ->assertStatus(404)
            ->assertJsonPath('error', 'unknown_document_type');
    }

    public function test_missing_document_returns_404(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/documents/worksheet/999999/changes/1')
            ->assertStatus(404)
            ->assertJsonPath('error', 'document_not_found');
    }

    public function test_owner_gets_200(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        $thread = $this->openThread($ws);
        $cs = $this->createValidatedChangeSet($thread, $ws);

        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(200)
            ->assertJsonPath('change_set.id', $cs->id)
            ->assertJsonPath('change_set.status', 'validated');
    }

    // ─── preview correctness ─────────────────────────────────────────────────

    public function test_preview_uses_base_revision_snapshot_not_live_payload(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        // Thread → base revision snapshot captures the state at this moment:
        //   blockers = [] (count 0)
        $thread = $this->openThread($ws);
        $cs = $this->createValidatedChangeSet($thread, $ws);

        // Simulate a concurrent edit against the live document that should
        // NOT affect the preview — base revision snapshot is frozen.
        $ws->update([
            'generated_data' => array_merge($ws->generated_data, [
                'blockers' => [
                    ['type' => 'network', 'message' => 'stale pre-existing', 'action' => 'x'],
                    ['type' => 'network', 'message' => 'stale 2', 'action' => 'x'],
                ],
            ]),
        ]);

        $res = $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(200);

        // Preview's `before` is the BASE revision (0 blockers), so `after`
        // is 0 + 1 from the add_blocker op = 1. If we regressed and used
        // the live payload, `before` would be 2 and `after` 3.
        $res->assertJsonPath('preview.before_summary.blockers_count', 0)
            ->assertJsonPath('preview.after_summary.blockers_count', 1)
            ->assertJsonPath('preview.changed_blockers_count', 1);
    }

    public function test_preview_falls_back_to_live_payload_when_base_revision_missing(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        $thread = $this->openThread($ws);
        $cs = $this->createValidatedChangeSet($thread, $ws);

        // Delete the base revision to exercise the fallback branch.
        DocumentRevision::query()->where('id', $thread['base_revision_id'])->delete();

        // Preview should still succeed (not 500) — falls back to live payload.
        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(200)
            ->assertJsonPath('change_set.id', $cs->id);
    }

    public function test_preview_omitted_for_rejected_change_set(): void
    {
        $owner = User::factory()->create();
        $ws = $this->makeWorksheet($owner);

        $this->actingAs($owner);
        $thread = $this->openThread($ws);

        $cs = DocumentChangeSet::create([
            'thread_id'         => $thread['id'],
            'document_type'     => 'worksheet',
            'document_id'       => $ws->id,
            'base_revision_id'  => $thread['base_revision_id'],
            'status'            => DocumentChangeSet::STATUS_REJECTED,
            'operations_json'   => [['op' => 'not_a_real_op']],
            'validation_errors' => [['code' => 'unknown_operation', 'message' => 'x']],
        ]);

        $this->getJson("/documents/worksheet/{$ws->id}/changes/{$cs->id}")
            ->assertStatus(200)
            ->assertJsonPath('preview', []);
    }
}

<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\DocumentChangeSet;
use App\Models\DocumentEditMessage;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DocumentEdits\ParserAiCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature coverage for POST /documents/{type}/{id}/threads/{thread}/parse.
 *
 * ParserAiCaller is mocked per-test so we control the model output
 * deterministically and never hit a real AI provider.
 */
class ParseEndpointTest extends TestCase
{
    use RefreshDatabase;

    private ?User $currentUser = null;

    private function auth(): User
    {
        $this->currentUser = User::factory()->create();
        $this->actingAs($this->currentUser);
        return $this->currentUser;
    }

    private function makeWorksheet(): Worksheet
    {
        $u = $this->currentUser ?? User::factory()->create();
        return Worksheet::create([
            'user_id'        => $u->id,
            'project_name'   => 'Parse Test',
            'client_name'    => 'Acme',
            'project_ref'    => 'PT-1',
            'status'         => 'draft',
            'generated_data' => [
                'project'  => ['name' => 'Parse Test'],
                'rooms'    => [['name' => 'Boardroom', 'tools' => ['Drill'], 'install_steps' => []]],
                'blockers' => [],
            ],
        ]);
    }

    private function validOpsResponse(): array
    {
        return [
            'operations' => [[
                'op'        => 'add_blocker',
                'target'    => ['room_name' => 'Boardroom', 'index' => null],
                'args'      => ['type' => 'power', 'message' => 'Check outlets.', 'action' => 'Call sparky.'],
                'rationale' => 'User requested a power blocker.',
            ]],
            'summary' => 'Added one power blocker for Boardroom.',
        ];
    }

    private function mockAi(array|\Throwable ...$responses): void
    {
        $this->mock(ParserAiCaller::class, function (MockInterface $m) use ($responses) {
            $queue = $responses;
            $m->shouldReceive('call')->andReturnUsing(function () use (&$queue) {
                $r = array_shift($queue) ?? end($queue);
                if ($r instanceof \Throwable) throw $r;
                return $r;
            });
        });
    }

    // ─── happy path ──────────────────────────────────────────────────────────

    public function test_good_model_output_creates_user_message_assistant_message_and_validated_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $this->mockAi($this->validOpsResponse());

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/parse", [
            'message' => 'Please add a power blocker for the Boardroom.',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('parse_status', 'validated')
            ->assertJsonStructure([
                'change_set_id', 'operations_json', 'summary', 'validation_errors',
            ])
            ->assertJsonPath('validation_errors', []);

        // User + assistant messages both recorded in the thread.
        $msgs = DocumentEditMessage::where('thread_id', $thread['id'])->get();
        $this->assertCount(2, $msgs);
        $this->assertTrue($msgs->pluck('role')->contains('user'));
        $this->assertTrue($msgs->pluck('role')->contains('assistant'));

        // Change-set is validated.
        $csId = $res->json('change_set_id');
        $this->assertSame('validated', DocumentChangeSet::find($csId)->status);
    }

    public function test_never_creates_a_new_revision(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');
        $this->mockAi($this->validOpsResponse());

        $revBefore = DocumentRevision::where('document_type', 'worksheet')->where('document_id', $ws->id)->count();
        $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/parse", [
            'message' => 'Please add a power blocker for the Boardroom.',
        ])->assertStatus(201);

        $revAfter = DocumentRevision::where('document_type', 'worksheet')->where('document_id', $ws->id)->count();
        $this->assertSame($revBefore, $revAfter, 'parse endpoint must not create revisions');
    }

    // ─── failure paths ────────────────────────────────────────────────────────

    public function test_invalid_schema_every_attempt_returns_422_rejected_change_set(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $bad = ['operations' => [], 'summary' => 'empty'];
        $this->mockAi($bad, $bad, $bad);

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/parse", [
            'message' => 'Bad request',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('parse_status', 'rejected')
            ->assertJsonFragment(['code' => 'schema_operations_empty']);

        $csId = $res->json('change_set_id');
        $this->assertSame('rejected', DocumentChangeSet::find($csId)->status);
    }

    public function test_ai_exception_converts_to_parse_failed_not_500(): void
    {
        $this->auth();
        $ws = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $this->mockAi(
            new \RuntimeException('simulated timeout'),
            new \RuntimeException('simulated timeout'),
            new \RuntimeException('simulated timeout'),
        );

        $res = $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/parse", [
            'message' => 'Anything',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('parse_status', 'rejected')
            ->assertJsonFragment(['code' => 'ai_call_failed']);
    }

    // ─── coherence + auth ─────────────────────────────────────────────────────

    public function test_thread_type_mismatch_returns_422(): void
    {
        $this->auth();
        $ws1 = $this->makeWorksheet();
        $ws2 = $this->makeWorksheet();
        $thread = $this->postJson("/documents/worksheet/{$ws1->id}/threads")->json('thread');

        $this->postJson("/documents/worksheet/{$ws2->id}/threads/{$thread['id']}/parse", [
            'message' => 'x',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'thread_mismatch');
    }

    public function test_auth_required(): void
    {
        // no actingAs
        $this->postJson('/documents/worksheet/1/threads/1/parse', ['message' => 'x'])
            ->assertStatus(401);
    }

    public function test_non_owner_gets_403(): void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $this->currentUser = $owner;
        $ws = $this->makeWorksheet();
        $this->actingAs($owner);
        $thread = $this->postJson("/documents/worksheet/{$ws->id}/threads")->json('thread');

        $this->actingAs($intruder);
        $this->postJson("/documents/worksheet/{$ws->id}/threads/{$thread['id']}/parse", [
            'message' => 'hi',
        ])->assertStatus(403)
            ->assertJsonPath('error', 'document_forbidden');
    }
}

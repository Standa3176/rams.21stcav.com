<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\CableSchedule;
use App\Models\OmManual;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Quick task 260817-w4k — DocumentEditController::authorizeDocument() used to
 * check "authenticated" and "exists", resolve the owner id, and then throw it
 * away. No can(), no authorize(), no Gate:: — across all five document types
 * and all seven route handlers, on a surface that MUTATES documents.
 *
 * Nothing was exploitable: every policy returns true for any authenticated
 * user (shared workspace). The defect was that the policy was never asked. The
 * first genuine per-user rule added to any policy file would have been silently
 * ignored here, and invisibly so — the policy would look enforced.
 *
 * This suite pins the wiring rather than the permissions:
 *   - all five types can still apply a real AI edit (survey especially — it had
 *     no policy at all until this task, and Laravel DENIES an ability with no
 *     policy behind it, so a naive can('update') would have broken it)
 *   - a denied policy produces 403 JSON, proved with Gate::before(fn () => false)
 *   - 401 / 404 behaviour is unchanged, and 404 still precedes 403 so a denial
 *     cannot be used to confirm a document exists
 *
 * It deliberately does NOT assert who may edit what. Every policy stays
 * permissive; changing that is a product decision, not a test's business.
 */
class DocumentEditPolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** The five types DocumentEditController::documentFor() resolves. */
    public static function documentTypeProvider(): array
    {
        return [
            'rams'      => ['rams'],
            'survey'    => ['survey'],
            'worksheet' => ['worksheet'],
            'om'        => ['om'],
            'cable'     => ['cable'],
        ];
    }

    /**
     * Builds a real document of $type plus an operation its adapter accepts.
     *
     * @return array{id:int, op:array<string,mixed>}
     */
    private function makeDocument(string $type, User $owner): array
    {
        switch ($type) {
            case 'rams':
                $rams = RamsDocument::create([
                    'user_id'        => $owner->id,
                    'project_name'   => 'W4K RAMS',
                    'client_name'    => 'Acme',
                    'site_address'   => '1 Test St',
                    'project_ref'    => 'W4K-RAMS',
                    'status'         => 'approved',
                    'filename'       => 'w4k.docx',
                    'ai_provider'    => 'claude',
                    'ai_model'       => 'claude-sonnet',
                    'form_data'      => ['source' => 'test'],
                    'generated_data' => ['project' => ['name' => 'W4K RAMS', 'ref' => 'W4K-RAMS']],
                    'reviewed_data'  => ['exclusions' => ['No structural works']],
                ]);

                return ['id' => $rams->id, 'op' => ['op' => 'add_exclusion', 'text' => 'No hot works.']];

            case 'survey':
                $survey = SiteSurvey::create([
                    'user_id'      => $owner->id,
                    'project_name' => 'W4K Survey',
                    'status'       => 'draft',
                ]);
                $room = $survey->rooms()->create(['room_name' => 'Boardroom', 'sort_order' => 0]);

                return ['id' => $survey->id, 'op' => [
                    'op'           => 'update_room_dimensions',
                    'room_id'      => $room->id,
                    'room_width_m' => 6.0,
                    'room_depth_m' => 4.0,
                    'room_height_m' => 3.0,
                ]];

            case 'worksheet':
                $ws = Worksheet::create([
                    'user_id'        => $owner->id,
                    'project_name'   => 'W4K Worksheet',
                    'client_name'    => 'Acme',
                    'project_ref'    => 'W4K-WS',
                    'status'         => 'draft',
                    'generated_data' => ['project' => ['name' => 'W4K Worksheet'], 'rooms' => []],
                ]);

                return ['id' => $ws->id, 'op' => [
                    'op'      => 'add_blocker',
                    'type'    => 'power',
                    'message' => 'Outlets unconfirmed.',
                    'action'  => 'Check with client.',
                    'room'    => '(project)',
                ]];

            case 'om':
                $om = OmManual::create([
                    'user_id'        => $owner->id,
                    'project_name'   => 'W4K O&M',
                    'project_ref'    => 'W4K-OM',
                    'client_name'    => 'Acme',
                    'site_address'   => '1 Test St',
                    'status'         => OmManual::STATUS_DRAFT,
                    'generated_data' => ['project' => ['name' => 'W4K O&M'], 'contacts' => []],
                    'extracted_data' => [],
                ]);

                return ['id' => $om->id, 'op' => ['op' => 'add_contact', 'name' => 'Jane', 'role' => 'PM']];

            case 'cable':
                $cable = CableSchedule::create([
                    'user_id'      => $owner->id,
                    'project_name' => 'W4K Cable',
                    'status'       => CableSchedule::STATUS_DRAFT,
                ]);

                return ['id' => $cable->id, 'op' => [
                    'op'            => 'add_cable_item',
                    'cable_id'      => 'CAB-W4K',
                    'from_location' => 'Rack A',
                    'to_location'   => 'Display 1',
                ]];
        }

        $this->fail("No fixture for document type '{$type}'.");
    }

    /**
     * The headline regression: routing authorizeDocument() through the policy
     * must not stop any type from actually applying an edit. Survey is the one
     * that would break — it had no policy file at all, and Laravel denies an
     * ability with no policy or gate behind it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documentTypeProvider')]
    public function test_document_type_can_still_apply_an_ai_edit(string $type): void
    {
        $user = User::factory()->create();
        ['id' => $id, 'op' => $op] = $this->makeDocument($type, $user);

        $this->actingAs($user);

        $thread = $this->postJson("/documents/{$type}/{$id}/threads")
            ->assertStatus(201)
            ->json('thread');

        $changeSet = $this->postJson("/documents/{$type}/{$id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Proposing an edit.',
            'operations_json' => [$op],
        ])->assertStatus(201)
          ->assertJsonPath('change_set.status', 'validated')
          ->json('change_set');

        $this->postJson("/documents/{$type}/{$id}/changes/{$changeSet['id']}/apply")
            ->assertStatus(200)
            ->assertJsonPath('change_set.status', 'applied');

        $this->assertDatabaseHas('document_change_sets', [
            'id'     => $changeSet['id'],
            'status' => 'applied',
        ]);
    }

    /**
     * The check that must not be removable again. With the gate denying, every
     * mutating entry point has to answer 403 JSON — not 200, and not an HTML
     * AuthorizationException page rendered into a fetch() client.
     *
     * If the can('update', ...) call in authorizeDocument() is deleted, thread
     * creation succeeds with 201 and this fails.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documentTypeProvider')]
    public function test_denied_update_policy_returns_403_json_on_thread_create(string $type): void
    {
        $user = User::factory()->create();
        ['id' => $id] = $this->makeDocument($type, $user);

        $this->actingAs($user);
        Gate::before(fn () => false);

        $this->postJson("/documents/{$type}/{$id}/threads")
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        // Nothing was created behind the denial.
        $this->assertDatabaseMissing('document_edit_threads', [
            'document_type' => $type,
            'document_id'   => $id,
        ]);
    }

    /**
     * Apply is the endpoint that actually mutates the document, so it gets its
     * own denial test: a change-set validated while the policy allowed must not
     * be applicable once the policy denies.
     */
    public function test_denied_update_policy_returns_403_on_apply(): void
    {
        $user = User::factory()->create();
        ['id' => $id, 'op' => $op] = $this->makeDocument('worksheet', $user);

        $this->actingAs($user);
        $thread = $this->postJson("/documents/worksheet/{$id}/threads")->json('thread');
        $changeSet = $this->postJson("/documents/worksheet/{$id}/threads/{$thread['id']}/messages", [
            'role'            => 'assistant',
            'content'         => 'Proposing an edit.',
            'operations_json' => [$op],
        ])->json('change_set');

        Gate::before(fn () => false);

        $this->postJson("/documents/worksheet/{$id}/changes/{$changeSet['id']}/apply")
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->assertDatabaseHas('document_change_sets', [
            'id'     => $changeSet['id'],
            'status' => 'validated',
        ]);
    }

    /** Read endpoints share authorizeDocument(), so they deny too. */
    public function test_denied_update_policy_returns_403_on_revisions_list(): void
    {
        $user = User::factory()->create();
        ['id' => $id] = $this->makeDocument('survey', $user);

        $this->actingAs($user);
        Gate::before(fn () => false);

        $this->getJson("/documents/survey/{$id}/revisions")
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * Unchanged by this task: no session → 401, and specifically NOT the new
     * 403 (an anonymous caller must not be told a document is merely forbidden).
     *
     * The body is asserted as the auth middleware's `message`, not the
     * controller's `error` key: these routes sit inside the `auth` group
     * (routes/web.php:790), so the middleware answers before the controller
     * runs and authorizeDocument()'s own 401 branch is defence-in-depth that
     * HTTP never reaches. Asserting `error` here would be asserting against a
     * response that cannot contain it.
     */
    public function test_unauthenticated_request_is_401_not_403(): void
    {
        $owner = User::factory()->create();
        ['id' => $id] = $this->makeDocument('worksheet', $owner);

        $this->postJson("/documents/worksheet/{$id}/threads")
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * Covers the controller's own 401 branch directly, since the auth
     * middleware shields it over HTTP. Guards against someone "tidying up"
     * an unauthenticated request into the 403 path if the middleware is ever
     * relaxed on these routes.
     */
    public function test_authorize_document_returns_401_for_a_request_with_no_user(): void
    {
        $owner = User::factory()->create();
        ['id' => $id] = $this->makeDocument('worksheet', $owner);

        $controller = app(\App\Http\Controllers\DocumentEditController::class);
        $method = new \ReflectionMethod($controller, 'authorizeDocument');

        $response = $method->invoke($controller, \Illuminate\Http\Request::create('/'), 'worksheet', $id);

        $this->assertNotNull($response, 'A request with no user must be refused.');
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('unauthenticated', $response->getData(true)['error']);
    }

    /** Unchanged by this task: missing row → 404. */
    public function test_missing_document_is_404(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/documents/worksheet/999999/threads')
            ->assertStatus(404)
            ->assertJsonPath('error', 'document_not_found');
    }

    /**
     * Ordering guard. Existence is settled before authorization, so a caller who
     * is denied cannot tell a missing document apart from one they may not touch
     * by reading the status code — both are 404 when the row is absent.
     */
    public function test_missing_document_is_404_even_when_the_policy_denies(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn () => false);

        $this->postJson('/documents/worksheet/999999/threads')
            ->assertStatus(404)
            ->assertJsonPath('error', 'document_not_found');
    }

    /**
     * type=drawing is in DocumentEditAdapterRegistry but NOT in documentFor()
     * (ProjectDrawing has no user_id and never was listed), so every doc-edit
     * endpoint already answered 404 for it. Pinned so the pre-existing gap is
     * visible and 260817-w4k is not blamed for it later.
     */
    public function test_drawing_type_is_404_unchanged_by_this_task(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/documents/drawing/1/threads')
            ->assertStatus(404)
            ->assertJsonPath('error', 'document_not_found');
    }
}

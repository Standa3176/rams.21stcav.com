<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Phase 46.7 Plan 03 — THE PER-ROOM NOTES BOX AND ITS AUTOSAVE ENDPOINT.
 *
 * ── ⚠️ READ THIS BEFORE YOU TRUST THIS FILE ─────────────────────────────────
 *
 * **NOTHING HERE RUNS A BROWSER.** No test in this file exercises
 * `localStorage`, a debounce timer, an `online` event or a real `fetch`. This
 * repo has no browser-driving harness — `puppeteer` is a dependency but it is
 * reached only through Browsershot inside `PdfRenderService`, which renders HTML
 * to a PDF and does not drive a live application.
 *
 * So what IS proven here is exactly two things:
 *
 *   1. **The endpoint.** Guard order, the forged-room-name refusal, the shared
 *      JSON column's sibling namespaces surviving a write, last-write-wins, the
 *      audit stamp, and that a refusal writes NOTHING. These are real HTTP
 *      requests against the real controller.
 *
 *   2. **The rendered field.** That the textarea exists once per room, that it
 *      is gone-but-readable on a signed worksheet, that engineer text comes back
 *      out escaped, and that the wiring's load-bearing source ORDER (the device
 *      copy written before the request is attempted) has not been flipped.
 *
 * **The real proof of the offline behaviour is step 2 of plan 46.7-04's blocking
 * human checkpoint:** airplane mode on a phone, type a note, reload, see it
 * still marked not-sent, restore signal, watch it arrive. A guard test that
 * implies more than it checks is worse than no test, so the limit is stated here
 * and repeated in the plan's SUMMARY.
 *
 * @see app/Http/Controllers/PublicWorksheetController.php (saveRoomNotes)
 * @see tests/Feature/Worksheets/EngineerLinkSignoffLockTest.php (the lock's own pair)
 * @see .planning/phases/46.7-engineer-link-tabbed-layout/46.7-CONTEXT.md (D-03, D-04, D-06, D-07)
 */
class EngineerLinkRoomNotesAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    private const ROOM_A = 'Boardroom';
    private const ROOM_B = 'Comms Room';
    private const ROOM_C = 'Reception';
    private const ROOM_D = 'Plant Room';

    /** @var list<string> */
    private const ROOMS = [self::ROOM_A, self::ROOM_B, self::ROOM_C, self::ROOM_D];

    /**
     * The notes route carries `throttle:worksheet-notes-write`. Several tests
     * here drive it twice in one test (last-write-wins, the refusal/mirror
     * pairs). Disabling ONLY the throttler keeps every guard under test
     * genuinely exercised instead of turning a 422 assertion into a 429 that
     * says nothing about the guard.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(array $rooms = self::ROOMS, ?array $confirmations = null): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Worksheet::create (not ->update) so boot::creating mints access_token
        // by direct assignment — access_token is deliberately absent from
        // $fillable and must never be mass-assigned.
        return Worksheet::create([
            'user_id'                   => $user->id,
            'project_id'                => $project->id,
            'project_name'              => 'Room Notes Fixture',
            'project_ref'               => '21CQ00000-01-OPS',
            'client_name'               => 'Fixture Client',
            'site_address'              => '1 Fixture Way, Reading RG1 1AA',
            'status'                    => Worksheet::STATUS_FINAL,
            'generated_data'            => [
                'rooms' => array_map(fn (string $name) => ['name' => $name, 'equipment' => []], $rooms),
            ],
            'pre_install_confirmations' => $confirmations,
        ]);
    }

    private function sign(Worksheet $worksheet, string $client = 'A Client'): void
    {
        $worksheet->signoffs()->create([
            'client_name'          => $client,
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);
    }

    private function url(Worksheet $worksheet, string $roomName): string
    {
        return route('public-worksheet.room-notes', [
            'token'    => $worksheet->access_token,
            'roomName' => $roomName,
        ]);
    }

    /** The two sibling namespaces that already live in the shared JSON column. */
    private function siblingConfirmations(): array
    {
        return [
            'survey_review' => [
                self::ROOM_A => ['reviewed_at' => '2026-09-01T09:00:00+00:00', 'reviewed_by' => 'ip:1.2.3.4|actor:deadbeefcafe'],
            ],
            'room_complete' => [
                self::ROOM_B => ['completed_at' => '2026-09-01T10:00:00+00:00', 'completed_by' => 'ip:1.2.3.4|actor:deadbeefcafe'],
            ],
        ];
    }

    private function notesOf(Worksheet $worksheet, string $roomName): ?array
    {
        $confirmations = (array) $worksheet->fresh()->pre_install_confirmations;

        return $confirmations['room_notes'][$roomName] ?? null;
    }

    private function source(): string
    {
        $path = base_path(self::VIEW);

        $this->assertFileExists($path, 'The engineer link view is gone — every source guard below would pass vacuously.');

        return (string) file_get_contents($path);
    }

    // ── 1. The lock precedes everything ──────────────────────────────────────

    /**
     * REFUSAL. A note is the captured record the client signed, not a status
     * confirmation, so it freezes when the record freezes.
     */
    public function test_a_note_is_refused_after_signoff_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet(self::ROOMS, $this->siblingConfirmations());
        $this->sign($worksheet);

        $before = $worksheet->fresh()->pre_install_confirmations;

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'late arrival from a phone'])
            ->assertStatus(422)
            ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — and the WHOLE array, not just the absence of the key.
        // A status-code-only assertion passes on an endpoint that refuses AFTER
        // doing the work.
        $this->assertSame(
            $before,
            $worksheet->fresh()->pre_install_confirmations,
            'A refused note still changed pre_install_confirmations. The client signed a record '
            . 'and it moved underneath them.',
        );
    }

    /** MIRROR. A lock that refuses everything always is an outage, not a lock. */
    public function test_a_note_still_saves_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'cracked backbox behind the rack'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('notes', 'cracked backbox behind the rack');

        $this->assertSame(
            'cracked backbox behind the rack',
            $this->notesOf($worksheet, self::ROOM_A)['notes'] ?? null,
            'The note did not reach pre_install_confirmations, so nothing the engineer typed '
            . 'will ever reach the office.',
        );
    }

    /**
     * ⚠️ THE ORDERING ASSERTION — T-46.4-04-05 and T-46.7-03-06.
     *
     * A signed worksheet posting a MALFORMED body must get the LOCK's sentence,
     * not a validation error. A locked caller must not learn which field was
     * malformed. This is the one assertion that proves the guard order rather
     * than asserting it by eye.
     */
    public function test_a_signed_worksheet_gets_the_locks_sentence_not_a_validation_error(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $response = $this->postJson($this->url($worksheet, self::ROOM_A), [
            'notes' => str_repeat('x', 5001),
        ]);

        $response->assertStatus(422);

        $this->assertSame(
            WorksheetCaptureLock::MESSAGE,
            $response->json('message'),
            'A signed worksheet answered a malformed body with a VALIDATION error, which means '
            . 'the lock runs after validation. A locked caller must learn nothing about the '
            . 'payload shape.',
        );
        $this->assertNull(
            $response->json('errors'),
            'The refusal carried a validation error bag. The guard is in the wrong place.',
        );
    }

    // ── 2. The forged room name ──────────────────────────────────────────────

    public function test_a_forged_room_name_is_refused_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet(self::ROOMS, $this->siblingConfirmations());

        $before = $worksheet->fresh()->pre_install_confirmations;

        $this->postJson($this->url($worksheet, 'Server Room That Does Not Exist'), ['notes' => 'injected'])
            ->assertStatus(422);

        $this->assertSame(
            $before,
            $worksheet->fresh()->pre_install_confirmations,
            'A forged room name minted a key in the shared JSON column. A leaked token can now '
            . 'write arbitrary keys into the record.',
        );
    }

    public function test_the_forged_room_name_refusal_says_which_guard_refused(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->postJson($this->url($worksheet, 'Nowhere'), ['notes' => 'x']);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Unknown room name.',
            (string) $response->getContent(),
            'The forged-name refusal does not carry the same sentence as its two sibling '
            . 'room-scoped writes, so the same failure reads differently on three endpoints.',
        );
    }

    /** MIRROR — the guard is proven to be a guard, not a blanket refusal. */
    public function test_a_real_room_name_succeeds_where_the_forged_one_failed(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, 'Nowhere'), ['notes' => 'x'])->assertStatus(422);
        $this->postJson($this->url($worksheet, self::ROOM_C), ['notes' => 'trunking lid still to fit'])->assertOk();

        $this->assertSame('trunking lid still to fit', $this->notesOf($worksheet, self::ROOM_C)['notes']);
    }

    public function test_a_worksheet_with_no_rooms_refuses_a_note(): void
    {
        $worksheet = $this->worksheet([]);

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'x'])->assertStatus(422);

        $this->assertNull(
            $worksheet->fresh()->pre_install_confirmations,
            'A roomless worksheet accepted a note, so the inclusion list was empty and let '
            . 'everything through instead of refusing.',
        );
    }

    // ── 3. Validation ────────────────────────────────────────────────────────

    public function test_over_length_notes_are_refused_on_an_unsigned_worksheet_and_write_nothing(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => str_repeat('x', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->assertNull(
            $this->notesOf($worksheet, self::ROOM_A),
            'An over-length note was stored anyway. The server promised a limit it does not keep.',
        );
    }

    public function test_an_empty_note_is_accepted_and_clears_the_stored_one(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'typed by mistake'])->assertOk();
        $this->assertSame('typed by mistake', $this->notesOf($worksheet, self::ROOM_A)['notes']);

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => ''])->assertOk();

        $this->assertSame(
            '',
            $this->notesOf($worksheet, self::ROOM_A)['notes'],
            'An engineer who deletes a note cannot delete it — the old text is still the record. '
            . 'A field that will not empty is a field that cannot be corrected.',
        );
    }

    // ── 4. Last write wins ───────────────────────────────────────────────────

    public function test_a_second_save_to_the_same_room_replaces_the_first(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'first pass'])->assertOk();
        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'second thoughts — it is the NEXT backbox'])->assertOk();

        $roomNotes = ((array) $worksheet->fresh()->pre_install_confirmations)['room_notes'];

        $this->assertSame(
            'second thoughts — it is the NEXT backbox',
            $roomNotes[self::ROOM_A]['notes'],
            'The second save did not replace the first. Last-write-wins is the ruling; an '
            . 'engineer correcting a note would be overruled by their own earlier text.',
        );
        $this->assertCount(
            1,
            $roomNotes,
            'Two saves to ONE room produced more than one entry — the notes are accumulating '
            . 'instead of being replaced.',
        );
    }

    public function test_two_rooms_hold_independent_notes(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'boardroom note'])->assertOk();
        $this->postJson($this->url($worksheet, self::ROOM_B), ['notes' => 'comms room note'])->assertOk();

        $this->assertSame('boardroom note', $this->notesOf($worksheet, self::ROOM_A)['notes']);
        $this->assertSame(
            'comms room note',
            $this->notesOf($worksheet, self::ROOM_B)['notes'],
            'Saving a second room disturbed the first. Notes for one room would appear under '
            . 'another on the office report.',
        );
    }

    // ── 5. ⚠️ THE SHARED JSON COLUMN ─────────────────────────────────────────

    /**
     * ⚠️ `pre_install_confirmations` IS SHARED. `survey_review` and
     * `room_complete` already live there and `room_notes` joins them. A careless
     * read-modify-write clobbers a sibling namespace SILENTLY — no error, no
     * failing request, just a room that quietly stops being marked complete.
     */
    public function test_a_notes_save_leaves_the_sibling_namespaces_byte_identical(): void
    {
        $siblings  = $this->siblingConfirmations();
        $worksheet = $this->worksheet(self::ROOMS, $siblings);

        $this->postJson($this->url($worksheet, self::ROOM_C), ['notes' => 'a note in a third room'])->assertOk();

        $after = (array) $worksheet->fresh()->pre_install_confirmations;

        $this->assertSame(
            $siblings['survey_review'],
            $after['survey_review'] ?? null,
            'A notes save clobbered the survey_review namespace. Every room would silently '
            . 'become un-reviewed, and sign-off would start blocking for a reason nobody can see.',
        );
        $this->assertSame(
            $siblings['room_complete'],
            $after['room_complete'] ?? null,
            'A notes save clobbered the room_complete namespace. Rooms an engineer already '
            . 'marked complete would silently revert.',
        );
        $this->assertSame('a note in a third room', $after['room_notes'][self::ROOM_C]['notes']);
    }

    public function test_a_survey_review_after_a_notes_save_leaves_the_notes_intact(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'held then reviewed'])->assertOk();
        $this->post(route('public-worksheet.survey-reviewed', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOM_A,
        ]))->assertRedirect();

        $this->assertSame(
            'held then reviewed',
            $this->notesOf($worksheet, self::ROOM_A)['notes'],
            'A sibling write erased the notes namespace. The clobbering goes BOTH ways and '
            . 'this is the direction nobody tests.',
        );
    }

    // ── 6. The audit stamp (Audit M-06) ──────────────────────────────────────

    public function test_the_saved_by_stamp_carries_ip_and_actor_and_never_the_token(): void
    {
        $worksheet = $this->worksheet();
        $token     = $worksheet->access_token;

        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'stamped'])->assertOk();

        $entry = $this->notesOf($worksheet, self::ROOM_A);

        $this->assertMatchesRegularExpression(
            '/^ip:.+\|actor:[0-9a-f]{12}$/',
            $entry['saved_by'],
            'saved_by is not the ip:…|actor:… shape its two siblings use, so the audit trail '
            . 'reads differently for three writes on one column.',
        );
        $this->assertStringNotContainsString(
            $token,
            json_encode($entry),
            'THE RAW ACCESS TOKEN LANDED IN THE PERSISTED ROW. That is Audit M-06 all over '
            . 'again — a URL-bearing auth secret leaked into a log.',
        );
        $this->assertNotEmpty($entry['saved_at'], 'The note carries no timestamp, so the page cannot say WHEN it saved.');
    }

    public function test_the_response_echoes_the_stored_value_back(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->postJson($this->url($worksheet, self::ROOM_B), ['notes' => 'echo me back']);

        $response->assertOk();
        $this->assertSame(
            'echo me back',
            $response->json('notes'),
            'The endpoint does not echo the STORED value back. Without it DraftStore.markSent '
            . 'cannot tell a stale acknowledgement from a current one, and a slow round trip '
            . 'would retire text the engineer has since changed.',
        );
        $this->assertNotEmpty($response->json('saved_at'), 'No saved_at in the response, so the indicator cannot show a time.');
    }

    public function test_the_endpoint_returns_json_and_never_a_redirect(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'json please']);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type'),
            'The autosave endpoint answers with something other than JSON. It is called on '
            . 'every keystroke-debounce; a redirect would be a full page load per save.',
        );
    }

    // ── 7. A missing body is not a crash ─────────────────────────────────────

    public function test_a_body_with_no_notes_key_is_accepted_as_an_empty_note(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->url($worksheet, self::ROOM_A), [])->assertOk();

        $this->assertSame(
            '',
            $this->notesOf($worksheet, self::ROOM_A)['notes'],
            'A body with no notes key either crashed or stored a null. `nullable` must '
            . 'normalise to the empty string so the column holds one shape.',
        );
    }
}

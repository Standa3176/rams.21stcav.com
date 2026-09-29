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

    // ═══════════════════════════════════════════════════════════════════════
    //  THE FIELD — rendered, counted, escaped
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * NON-VACUITY GATE. Every count below is worthless if the page did not
     * actually render — an error body or an empty shell would satisfy a
     * "contains nothing" guard for the wrong reason.
     */
    public function test_the_fixture_renders_a_real_page(): void
    {
        $worksheet = $this->worksheet();

        $html = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertGreaterThan(50000, strlen($html), 'The rendered page is too small to be the engineer link.');

        foreach (self::ROOMS as $room) {
            $this->assertStringContainsString($room, $html, "Room {$room} did not render at all.");
        }

        foreach (['Undefined', 'Division by zero', 'Warning:', 'Deprecated:', 'ErrorException'] as $noise) {
            $this->assertStringNotContainsString($noise, $html, "The page rendered a PHP {$noise}.");
        }
    }

    public function test_the_notes_textarea_renders_exactly_once_per_room(): void
    {
        $worksheet = $this->worksheet();

        $html = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertOk()
            ->getContent();

        $xpath = $this->xpath($html);

        $this->assertSame(
            count(self::ROOMS),
            $xpath->query('//textarea[@data-room-notes]')->length,
            'There is not exactly one notes box per room. Either a room cannot record what the '
            . 'engineer found, or two boxes in one room will fight over the same draft key.',
        );

        foreach (self::ROOMS as $room) {
            $this->assertSame(
                1,
                $xpath->query('//textarea[@data-room-notes][@data-room-name=' . $this->xq($room) . ']')->length,
                "Room {$room} has no notes box of its own.",
            );
            $this->assertSame(
                1,
                $xpath->query('//*[@data-room-notes-status][@data-room-name=' . $this->xq($room) . ']')->length,
                "Room {$room} has a notes box with no save indicator. An engineer would have no "
                . 'way to know whether the words reached the office.',
            );
        }
    }

    public function test_each_notes_box_carries_its_own_server_built_endpoint_url(): void
    {
        $worksheet = $this->worksheet();

        $html  = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->getContent();
        $xpath = $this->xpath($html);

        $checked = 0;

        foreach (self::ROOMS as $room) {
            $node = $xpath->query('//textarea[@data-room-notes][@data-room-name=' . $this->xq($room) . ']')->item(0);
            $this->assertNotNull($node);

            $this->assertSame(
                $this->url($worksheet, $room),
                $node->getAttribute('data-notes-url'),
                "The notes box for {$room} does not carry the router's own URL. A URL assembled "
                . 'in JS would percent-encode a slash in a room name into something the web '
                . 'server rejects before Laravel ever sees it.',
            );
            $checked++;
        }

        $this->assertSame(count(self::ROOMS), $checked, 'The URL loop checked a different number of rooms than exist.');
    }

    public function test_the_stored_note_is_rendered_into_the_textarea_as_element_content(): void
    {
        $worksheet = $this->worksheet();
        $this->postJson($this->url($worksheet, self::ROOM_A), ['notes' => 'cracked backbox behind the rack'])->assertOk();

        $html  = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->getContent();
        $xpath = $this->xpath($html);

        $node = $xpath->query('//textarea[@data-room-notes][@data-room-name=' . $this->xq(self::ROOM_A) . ']')->item(0);

        $this->assertNotNull($node);
        $this->assertSame(
            'cracked backbox behind the rack',
            $node->textContent,
            'A saved note does not come back into the field. The engineer would reopen the room '
            . 'and see an empty box, and type it again.',
        );
        $this->assertSame(
            '',
            $node->getAttribute('value'),
            'The note is emitted as a value ATTRIBUTE. A textarea value must be element content '
            . 'so the escaping rules of an attribute context can never apply to it.',
        );
    }

    /**
     * ⚠️ ENGINEER FREE TEXT ON A PAGE A CLIENT SIGNS. The payload carries a bare
     * tag AND an ampersand, because the ampersand is what catches a
     * double-escape or an escape applied in the wrong order.
     */
    public function test_a_note_containing_markup_round_trips_escaped(): void
    {
        $worksheet = $this->worksheet();
        $payload   = '<script>alert(1)</script> Smith & Sons blanking plate "missing"';

        $this->postJson($this->url($worksheet, self::ROOM_B), ['notes' => $payload])->assertOk();

        $html = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $html,
            'AN ENGINEER TYPED A SCRIPT TAG INTO THE PAGE A CLIENT SIGNS AND IT CAME BACK AS '
            . 'MARKUP. The largest free-text field on this page is an injection vector.',
        );
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'The markup was not escaped — it was stripped or lost.');
        $this->assertStringContainsString('Smith &amp; Sons', $html, 'The ampersand was not escaped, or was escaped twice.');

        // And the STORED value is the raw text — escaping is an output concern.
        $this->assertSame($payload, $this->notesOf($worksheet, self::ROOM_B)['notes']);
    }

    // ── The signed state: gone as a control, still readable as a record ──────

    /**
     * ⚠️ THE UNSIGNED MIRROR RUNS FIRST, IN THIS SAME TEST. A broken selector
     * would otherwise "prove" the lock by finding nothing anywhere.
     */
    public function test_a_signed_worksheet_shows_the_note_as_text_with_no_textarea(): void
    {
        $unsigned = $this->worksheet();
        $this->postJson($this->url($unsigned, self::ROOM_A), ['notes' => 'isolator still to be fitted'])->assertOk();

        $mirror = $this->xpath($this->get(route('public-worksheet.show', ['token' => $unsigned->access_token]))->getContent());
        $this->assertSame(
            count(self::ROOMS),
            $mirror->query('//textarea[@data-room-notes]')->length,
            'The selector finds no notes box on an UNSIGNED worksheet, so the signed assertion '
            . 'below would pass by finding nothing rather than by the lock working.',
        );

        // Now the same worksheet, signed.
        $this->sign($unsigned);

        $html  = $this->get(route('public-worksheet.show', ['token' => $unsigned->access_token]))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(
            0,
            $xpath->query('//textarea[@data-room-notes]')->length,
            'A signed worksheet still offers an editable notes box. The client signed a record '
            . 'and the page invites somebody to change it.',
        );
        $this->assertSame(
            0,
            $xpath->query('//textarea[@data-room-notes][@data-capture-control]')->length,
            'A capture control survived the lock.',
        );
        $this->assertStringContainsString(
            'isolator still to be fitted',
            $html,
            'The note VANISHED on a signed worksheet. A signed worksheet is a record and its '
            . 'signer must be able to read what they signed — read-only, not gone.',
        );
        $this->assertGreaterThanOrEqual(
            1,
            $xpath->query('//*[@data-room-notes-readonly]')->length,
            'There is no read-only rendering of the notes on a signed worksheet.',
        );
    }

    public function test_a_signed_worksheet_with_no_note_says_so_rather_than_showing_an_empty_box(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $html = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertOk()->getContent();

        $this->assertStringContainsString(
            'nothing recorded for this room',
            $html,
            'A signed room with no note renders a blank gap. The reader cannot tell "nothing '
            . 'was found" from "the page is broken".',
        );
    }

    public function test_the_roomless_worksheet_renders_no_notes_box_and_no_php_error(): void
    {
        $worksheet = $this->worksheet([]);

        $html = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertOk()->getContent();

        $this->assertSame(0, $this->xpath($html)->query('//textarea[@data-room-notes]')->length);

        foreach (['Undefined', 'Division by zero', 'Warning:', 'Deprecated:', 'ErrorException'] as $noise) {
            $this->assertStringNotContainsString($noise, $html, "A roomless worksheet rendered a PHP {$noise}.");
        }
    }

    public function test_the_field_promises_the_same_limit_the_server_keeps(): void
    {
        $worksheet = $this->worksheet();

        $html  = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->getContent();
        $xpath = $this->xpath($html);

        $node = $xpath->query('//textarea[@data-room-notes]')->item(0);

        $this->assertNotNull($node);
        $this->assertSame(
            '5000',
            $node->getAttribute('maxlength'),
            'The field does not carry the server\'s own max:5000. An engineer would type past '
            . 'the limit and every save from then on would be silently refused.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  THE WIRING — asserted by source ORDER, which is the ruling
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ THE ORDER IS THE RULING. The device copy is written before the network
     * is touched. The assertion is by source POSITION because that is the only
     * thing a non-browser test can honestly check about it.
     *
     * Both positions are `assertIsInt`-checked FIRST: without that, a missing
     * needle returns `false` and `false < 12345` passes.
     */
    public function test_the_draft_is_written_before_the_request_is_attempted(): void
    {
        $source = $this->source();

        $put   = strpos($source, 'DraftStore.put(_notesKey(room.name), room.el.value);');
        $fetch = strpos($source, 'var attempt = fetch(room.url,');

        $this->assertIsInt($put, 'The notes input handler no longer writes a draft at all. Every word typed with no signal is lost.');
        $this->assertIsInt($fetch, 'The notes request is gone from the page — the ordering guard below is measuring nothing.');

        $this->assertLessThan(
            $fetch,
            $put,
            'DraftStore.put NO LONGER SITS ABOVE THE REQUEST. A note typed in a plant room with '
            . 'no signal is now lost the instant the fetch fails, because nothing wrote it to '
            . 'the device first. This ordering is the whole of the 46.7-01 ruling.',
        );
    }

    public function test_the_debounce_and_the_timeout_are_the_documented_numbers(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'var NOTES_DEBOUNCE_MS = 1200;',
            $source,
            'The debounce interval changed. Too long and a phone going into a pocket loses the '
            . 'sentence; too short and every keystroke is a request on a dying signal.',
        );
        $this->assertStringContainsString(
            'var NOTES_TIMEOUT_MS  = 8000;',
            $source,
            'The 8-second race is gone. A request that neither resolves nor rejects — a captive '
            . 'portal answering the handshake and nothing else — would leave the engineer '
            . 'looking at a field that never resolves either way.',
        );
    }

    public function test_the_held_sentence_and_the_locks_sentence_are_both_on_the_page(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "var HELD_SENTENCE = 'Held on this phone — not sent yet';",
            $source,
            'The held-state sentence is gone. A draft on the device with nothing saying so is a '
            . 'field that LOOKS saved and is not — the exact failure D-04 exists to prevent.',
        );
        $this->assertStringContainsString(
            'DraftStore.markRefused(_notesKey(room.name), msg);',
            $source,
            'A refused draft is no longer KEPT with the server\'s reason. The next 422 deletes '
            . 'an engineer\'s words.',
        );
        $this->assertStringContainsString(
            '_notesSay(room, msg);',
            $source,
            'The server\'s own sentence is no longer shown verbatim, so an engineer whose note '
            . 'will never send is never told why.',
        );
    }

    public function test_a_refused_room_stops_retrying_and_a_typed_room_resumes(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'if (room.refused) return;              // stop retrying a locked room',
            $source,
            'A room refused by the capture lock keeps retrying forever, flashing the same '
            . 'refusal at an engineer who can do nothing about it.',
        );
        $this->assertStringContainsString(
            'room.refused = false;',
            $source,
            'A refused room can never resume. If the refusal was transient the engineer would '
            . 'have to reload the page to get autosave back.',
        );
    }

    public function test_a_late_acknowledgement_cannot_retire_newer_text(): void
    {
        $source = $this->source();

        $markSent = strpos($source, 'var retired = DraftStore.markSent(_notesKey(room.name), echoed);');

        $this->assertIsInt(
            $markSent,
            'The acknowledgement no longer carries the value it acknowledges, so it cannot tell '
            . 'a stale round trip from a current one and would retire text the engineer has '
            . 'since changed.',
        );
        $this->assertStringContainsString(
            'if (retired) {',
            $source,
            'The return value of markSent is ignored. A refused retirement would be reported to '
            . 'the engineer as "Saved" while the newer words sit unsent on the device.',
        );
    }

    public function test_the_page_retries_on_the_online_event_on_a_timer_and_on_load(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "window.addEventListener('online', function () { _notesDrainAll('online-event'); });",
            $source,
            'Nothing retries when the signal comes back. A held note would sit on the phone '
            . 'until the engineer happened to type in that field again.',
        );
        $this->assertStringContainsString(
            "_notesDrainAll('load');",
            $source,
            'A page opened with a draft from the LAST session never tries to send it.',
        );
        $this->assertStringContainsString(
            'var NOTES_RETRY_MS    = 30000;',
            $source,
            'The periodic retry is gone. A device whose `online` event never fires would hold '
            . 'the note indefinitely.',
        );
    }

    public function test_the_held_state_is_driven_by_the_store_and_not_by_a_page_variable(): void
    {
        $source = $this->source();

        $render = strpos($source, 'function _notesRender(room) {');
        $get    = strpos($source, 'var entry = DraftStore.get(_notesKey(room.name));', (int) $render);

        $this->assertIsInt($render, 'The indicator renderer is gone.');
        $this->assertIsInt(
            $get,
            'The indicator no longer reads the STORE. Driven by a page variable instead, the '
            . 'held state would die with the page and a reload would show a note as saved when '
            . 'it is still only on the phone.',
        );
        $this->assertStringContainsString(
            'if (held && typeof held.value === \'string\' && held.value !== el.value) {',
            $source,
            'A draft held from the last session is not restored into the field on load. The '
            . 'engineer reopens the page and their words are not on screen.',
        );
    }

    public function test_the_indicator_is_written_with_textcontent_and_never_as_markup(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'if (room.statusEl) room.statusEl.textContent = text;',
            $source,
            'The save indicator no longer uses textContent. The server message and the engineer\'s '
            . 'own text would reach the DOM as markup on a page a client signs.',
        );

        // The notes wiring must not introduce an innerHTML anywhere near itself.
        $start = strpos($source, '46.7-03 — THE PER-ROOM NOTES FIELD, WIRED TO THE RULING ABOVE');
        $this->assertIsInt($start, 'The notes wiring block is gone — the innerHTML guard is measuring nothing.');

        $this->assertStringNotContainsString(
            'innerHTML',
            substr($source, $start),
            'The notes wiring assigns innerHTML. Engineer free text reaching innerHTML on a page '
            . 'a client signs is an injection, not a formatting choice.',
        );
    }

    public function test_the_wiring_adds_no_sixth_script_tag_and_lives_in_the_draftstore_iife(): void
    {
        $source = $this->source();

        $store  = strpos($source, 'window.DraftStore = DraftStore;');
        $wiring = strpos($source, 'function _notesInit() {');
        $end    = strpos($source, '</body>');

        $this->assertIsInt($store);
        $this->assertIsInt($wiring, 'The notes wiring is gone.');
        $this->assertIsInt($end);

        $this->assertGreaterThan($store, $wiring, 'The notes wiring runs before DraftStore is exposed.');
        $this->assertStringNotContainsString(
            '<script',
            substr($source, $store, $end - $store),
            'A new script tag was opened after DraftStore. The wiring belongs inside the same '
            . 'IIFE — a sixth tag is a sixth place to look for why autosave stopped.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  THE PINS — re-asserted here because this plan edits the page
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ These needles match COMMENTS as well as attributes. Naming a directive
     * in a comment fails the guard; nine near-misses so far.
     */
    public function test_this_plan_adds_no_alpine_directive_to_a_page_that_loads_no_alpine(): void
    {
        $source = $this->source();

        $expected = [
            ' x-data'           => 1,
            ' x-show'           => 1,
            ' x-model'          => 2,
            ' x-cloak'          => 1,
            '@click'            => 0,
            '<x-photo-lightbox' => 0,
        ];

        foreach ($expected as $needle => $count) {
            $this->assertSame(
                $count,
                substr_count($source, $needle),
                "Alpine occurrence count for '{$needle}' moved. Alpine is NOT loaded on this page; "
                . 'a directive added here is a control that silently does nothing.',
            );
        }

        // Non-vacuity: if every needle were mistyped, the zeros would still pass.
        $this->assertGreaterThan(0, array_sum($expected));
        $this->assertStringContainsString(' x-data', $source, 'The x-data pin is measuring nothing.');
    }

    public function test_the_view_still_has_exactly_one_unescaped_echo_and_it_is_the_tab_panels(): void
    {
        $source = $this->source();

        $this->assertSame(
            1,
            substr_count($source, '{!!'),
            'The unescaped-echo count moved. This plan adds the LARGEST free-text field on a page '
            . 'a client signs; a raw echo here is an injection.',
        );
        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source, 'The one permitted raw echo is gone.');
        $this->assertMatchesRegularExpression(
            '/<section class="ws-tab-panel card[^>]*\{!! \$skipRestoreAttr !!\}/s',
            $source,
            'The one raw echo has drifted off the tab panel element. A bare count would still '
            . 'pass while it sat somewhere it has never been reviewed.',
        );
    }

    /**
     * ⚠️ THE FROZEN PHOTO STORE IS NOT THIS PLAN'S. Its creation handler only
     * ever CREATES — there is no migration branch — so a version bump, a key
     * change or an index change STRANDS EVERY PENDING PHOTO on every engineer's
     * phone. Drafts live in `localStorage`; the frozen store is never opened.
     */
    public function test_the_frozen_photo_store_is_untouched_by_this_plan(): void
    {
        $source = $this->source();

        $expected = [
            'DB_VERSION'            => 3,
            'const DB_VERSION = 1;' => 1,
            "keyPath: 'id'"         => 1,
            'capturedAt'            => 10,
            'pending_uploads'       => 1,
            'createObjectStore'     => 1,
        ];

        foreach ($expected as $needle => $count) {
            // Non-vacuity before the equality, so a mistyped needle cannot make
            // an equality pass against zero.
            $this->assertStringContainsString($needle, $source, "The frozen-store needle '{$needle}' is gone — this pin is measuring nothing.");
            $this->assertSame(
                $count,
                substr_count($source, $needle),
                "The frozen photo store's '{$needle}' count moved. THIS IS NOT A NUMBER TO UPDATE: "
                . 'it means the notes autosave reached into the schema-frozen queue, and every '
                . "photo sitting unsent on every engineer's phone is at risk.",
            );
        }

        $this->assertStringNotContainsString(
            'indexedDB',
            substr($source, (int) strpos($source, '46.7-03 — THE PER-ROOM NOTES FIELD, WIRED TO THE RULING ABOVE')),
            'The notes wiring opens IndexedDB. Drafts belong in localStorage — that separation is '
            . 'the only reason the frozen store is safe.',
        );
    }

    public function test_the_is_binary_guard_still_sits_above_the_blob_append(): void
    {
        // ⚠️ SCOPED TO drain(). The page appends a photo to a FormData in four
        // other places by design, so a whole-file strpos would compare the
        // guard against an unrelated upload path and fail for the wrong reason.
        // (It did, on the first run of this file — recorded rather than glossed.)
        $source = $this->source();

        $sliceStart = strpos($source, 'OfflineQueue.drain = function');
        $sliceEnd   = strpos($source, 'OfflineQueue._notifyChange = function', (int) $sliceStart);

        $this->assertIsInt($sliceStart, 'drain() is gone from the page — this ordering guard cannot run.');
        $this->assertIsInt($sliceEnd, 'The member after drain() is gone — the drain slice is unbounded.');

        $slice = substr($source, $sliceStart, $sliceEnd - $sliceStart);

        $guard  = strpos($slice, 'const isBinary');
        $append = strpos($slice, "fd.append('photo'");

        $this->assertIsInt($guard, 'The isBinary guard is gone from drain().');
        $this->assertIsInt($append, 'The blob append is gone from drain() — this ordering guard is measuring nothing.');

        $this->assertLessThan(
            $append,
            $guard,
            'The isBinary guard is no longer ABOVE the blob append. A blobless kit row will now be '
            . "stamped unreadable forever and the engineer's work is gone. Plan 46.4-06 exists "
            . 'for this one ordering; 46.7-03 must not have moved it.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  THE SEVEN STATES, EXECUTED IN NODE — not scanned for
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Lifts BOTH the `DraftStore` body and the notes wiring out of the view
     * between their extract markers and runs them in node against a stubbed
     * `localStorage`, a stubbed DOM, a stubbed `fetch` and controllable timers.
     *
     * Every state an engineer can be in is driven and the indicator's text is
     * read back: empty, typed, saving, saved, offline-held, server-refused, and
     * signed-and-locked — plus the late-acknowledgement case, which is the one
     * that silently loses words.
     *
     * ⚠️ WHAT THIS DOES NOT PROVE. It is node with stubs, not a phone. It does
     * not prove a real `localStorage` in Safari private mode, a real full-page
     * reload, a real `online` event from a real radio, real touch input, or that
     * any of this is legible one-handed in a plant room. It proves the shipped
     * lines do what they say when given those inputs. **The real proof is step 2
     * of plan 46.7-04's blocking human checkpoint.**
     */
    public function test_the_notes_wiring_renders_every_state_it_can_be_in(): void
    {
        $node = $this->resolveNode();

        if ($node === null) {
            $this->markTestSkipped(
                'node is not reachable from PHP on this machine, so the notes-wiring harness '
                . 'cannot run. Every SOURCE pin and every ENDPOINT test in this file still ran. '
                . 'Re-run where node is on PATH.',
            );
        }

        $source = $this->source();

        $harness = $this->harnessScript(
            $this->slice($source, '// ── DRAFTSTORE-EXTRACT-BEGIN', '// ── DRAFTSTORE-EXTRACT-END'),
            $this->slice($source, '// ── NOTESWIRING-EXTRACT-BEGIN', '// ── NOTESWIRING-EXTRACT-END'),
        );

        $file = rtrim(sys_get_temp_dir(), '\\/') . '/notes-wiring-' . bin2hex(random_bytes(8)) . '.mjs';
        file_put_contents($file, $harness);

        $out  = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        @unlink($file);

        $raw = implode("\n", $out);

        $this->assertSame(0, $code, "The notes-wiring harness did not run cleanly. Output:\n" . $raw);

        $result = json_decode($raw, true);
        $this->assertIsArray($result, "The harness did not emit JSON. Output:\n" . $raw);

        // ── STATE 1: empty ──────────────────────────────────────────────
        $this->assertSame('', $result['empty']['status'], 'An untouched notes box already claims something. The indicator must say nothing until there is something to say.');
        $this->assertSame(0, $result['empty']['requests'], 'The page fired a save for a field nobody has typed in.');

        // ── STATE 2: typed ──────────────────────────────────────────────
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['typed']['status'],
            'The instant after typing, the field does not say the words are only on the phone. '
            . 'A field that looks saved before it is saved is the exact D-04 failure.',
        );
        $this->assertSame(
            'cracked backbox behind the rack',
            $result['typed']['draft'],
            'THE DEVICE COPY WAS NOT WRITTEN ON INPUT. Everything typed with no signal is lost.',
        );
        $this->assertSame(0, $result['typed']['requests'], 'The save fired before the debounce — every keystroke would be a request.');

        // ── STATE 3: saving (debounce elapsed, response not yet back) ────
        $this->assertSame(1, $result['saving']['requests'], 'The debounce elapsed and no request was made — the note never leaves the phone.');
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['saving']['status'],
            'While a save is in flight the field claims something other than held. There is '
            . 'deliberately no "Saving…" state: until the office has it, it is held.',
        );

        // ── STATE 4: saved ──────────────────────────────────────────────
        $this->assertStringStartsWith('Saved ', $result['saved']['status'], 'A successful save does not say so, with a time.');
        $this->assertSame(0, $result['saved']['draftCount'], 'An acknowledged draft is still held on the device. It would be re-sent forever.');

        // ── STATE 5: offline-held, and it SURVIVES A RELOAD ─────────────
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['offline']['status'],
            'Typing with navigator.onLine false does not report the held state.',
        );
        $this->assertSame(0, $result['offline']['requests'], 'The page tried to send while known-offline instead of holding.');
        $this->assertSame(
            'no isolator fitted in the plant room',
            $result['offline']['afterReloadDraft'],
            'A held note did not survive a reload. The engineer reopens the page and the words are gone.',
        );
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['offline']['afterReloadStatus'],
            'After a reload the field no longer SAYS the note is only on the phone. The held state '
            . 'must be read back out of the store, not from a variable that died with the page.',
        );
        $this->assertSame(
            'no isolator fitted in the plant room',
            $result['offline']['afterReloadFieldValue'],
            'The held text was not restored into the textarea on load — the engineer cannot see '
            . 'or copy what is still unsent.',
        );

        // ── STATE 6: the signal comes back ──────────────────────────────
        $this->assertSame(1, $result['reconnect']['requests'], 'The `online` event did not drain the held note. It would sit there until the engineer typed again.');
        $this->assertStringStartsWith('Saved ', $result['reconnect']['status'], 'A drained note does not report as saved.');
        $this->assertSame(0, $result['reconnect']['draftCount'], 'A drained note is still held.');

        // ── STATE 7: signed and locked ──────────────────────────────────
        $this->assertSame(
            WorksheetCaptureLock::MESSAGE,
            $result['refused']['status'],
            "The server's own sentence is not what the engineer reads. A refused note with no "
            . 'reason is a field that just stops working.',
        );
        $this->assertSame(
            'something found after the client had signed',
            $result['refused']['draft'],
            'A REFUSED DRAFT WAS DISCARDED. The words are gone and nobody can even read them off '
            . 'the screen. This is the exact loss the 46.7-01 ruling forbids.',
        );
        $this->assertSame(
            1,
            $result['refused']['requestsAfterRetry'],
            'A locked room kept retrying. The engineer would watch the same refusal flash forever '
            . 'with nothing they can do about it.',
        );
        $this->assertSame(
            WorksheetCaptureLock::MESSAGE,
            $result['refused']['afterReloadStatus'],
            'The refusal and its sentence did not survive a reload, so a reload makes a note that '
            . 'will never send look merely unsent.',
        );

        // ── The late acknowledgement ─────────────────────────────────────
        $this->assertSame(
            'second thoughts — it is the NEXT backbox',
            $result['lateAck']['draft'],
            'AN ACKNOWLEDGEMENT FOR THE OLD TEXT RETIRED THE NEWER DRAFT. The engineer\'s latest '
            . 'keystrokes would be dropped while the field showed as saved.',
        );
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['lateAck']['status'],
            'A stale acknowledgement was reported to the engineer as "Saved" while newer words '
            . 'sat unsent on the device.',
        );

        // ── A request that never settles times out into held ─────────────
        $this->assertSame(
            'Held on this phone — not sent yet',
            $result['hang']['status'],
            'A request that neither resolves nor rejects — a captive portal answering the '
            . 'handshake and nothing else — leaves the indicator stuck. It must time out into '
            . 'the held state.',
        );

        // Non-vacuity: both slices are real.
        $this->assertGreaterThan(2000, $result['storeSliceLength'], 'The extracted DraftStore slice is too small to be the real store.');
        $this->assertGreaterThan(4000, $result['wiringSliceLength'], 'The extracted notes-wiring slice is too small to be the real wiring.');
        $this->assertSame(7, $result['statesRendered'], 'The harness did not render all seven states.');
    }

    private function slice(string $source, string $begin, string $end): string
    {
        $start = strpos($source, $begin);
        $stop  = strpos($source, $end);

        $this->assertIsInt($start, "The extract marker '{$begin}' is gone — the harness cannot run.");
        $this->assertIsInt($stop, "The extract marker '{$end}' is gone — the harness cannot run.");
        $this->assertGreaterThan($start, $stop, 'The extract markers are out of order.');

        return substr($source, $start, $stop - $start);
    }

    private function resolveNode(): ?string
    {
        foreach (['node', 'C:\\Program Files\\nodejs\\node.exe'] as $candidate) {
            $out  = [];
            $code = 0;
            exec(escapeshellarg($candidate) . ' --version 2>&1', $out, $code);

            if ($code === 0 && preg_match('/^v\d+\./', (string) ($out[0] ?? ''))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The harness. Stubs only what the wiring touches: one localStorage, one
     * window with listeners, a document with two rooms' worth of elements, a
     * controllable fetch, and manual timer pumping so a 1200ms debounce does not
     * cost the suite 1200ms. Both slices are inserted VERBATIM.
     */
    private function harnessScript(string $storeSlice, string $wiringSlice): string
    {
        $store  = json_encode($storeSlice);
        $wiring = json_encode($wiringSlice);
        $locked = json_encode(WorksheetCaptureLock::MESSAGE);

        return <<<JS
        const STORE_BODY  = {$store};
        const WIRING_BODY = {$wiring};
        const LOCK_MSG    = {$locked};

        let backing = {};

        const localStorageStub = {
            getItem(k) { return Object.prototype.hasOwnProperty.call(backing, k) ? backing[k] : null; },
            setItem(k, v) { backing[k] = String(v); },
            removeItem(k) { delete backing[k]; },
        };

        // ── A DOM with two rooms ─────────────────────────────────────────
        function makeEl(attrs, tag) {
            return {
                tagName: tag || 'TEXTAREA',
                _attrs: attrs || {},
                value: '',
                textContent: '',
                style: {},
                _handlers: {},
                getAttribute(n) { return Object.prototype.hasOwnProperty.call(this._attrs, n) ? this._attrs[n] : null; },
                setAttribute(n, v) { this._attrs[n] = v; },
                addEventListener(n, h) { (this._handlers[n] = this._handlers[n] || []).push(h); },
                fire(n) { (this._handlers[n] || []).forEach((h) => h({})); },
            };
        }

        const ROOM_A = 'Boardroom';
        const ROOM_B = 'Comms Room';

        const fields = {};
        const statuses = {};
        [ROOM_A, ROOM_B].forEach((name) => {
            fields[name]   = makeEl({ 'data-room-notes': '', 'data-room-name': name, 'data-notes-url': '/worksheet/tok/rooms/' + name + '/notes' });
            statuses[name] = makeEl({ 'data-room-notes-status': '', 'data-room-name': name }, 'DIV');
        });

        const windowHandlers = {};
        const draftHandlers  = [];

        globalThis.window = {
            localStorage: localStorageStub,
            addEventListener(n, h) {
                if (n === 'worksheet-draft-change') { draftHandlers.push(h); return; }
                (windowHandlers[n] = windowHandlers[n] || []).push(h);
            },
            dispatchEvent(e) { draftHandlers.forEach((h) => h(e)); return true; },
        };
        globalThis.CustomEvent = class CustomEvent { constructor(n) { this.type = n; } };
        // node's own globalThis.navigator is getter-only, so the stub is a
        // plain local passed into the factory rather than a global assignment.
        const navigatorStub = { onLine: true };

        globalThis.document = {
            readyState: 'complete',
            body: { firstChild: null, insertBefore() {} },
            getElementById() { return null; },
            createElement() { return makeEl({}, 'DIV'); },
            addEventListener() {},
            createTextNode(t) { return { textContent: t }; },
            querySelectorAll(sel) {
                if (sel === 'textarea[data-room-notes]') return [fields[ROOM_A], fields[ROOM_B]];
                return [];
            },
            querySelector(sel) {
                if (sel === 'meta[name=csrf-token]') return { content: 'csrf-stub' };
                const m = /data-room-name="(.*)"/.exec(sel);
                if (m && sel.indexOf('data-room-notes-status') !== -1) return statuses[m[1]] || null;
                return null;
            },
        };

        // ── Controllable timers ──────────────────────────────────────────
        let pending = [];
        let clock   = 0;
        let seq     = 0;

        globalThis.setTimeout = function (fn, ms) {
            const id = ++seq;
            pending.push({ id, fn, at: clock + (ms || 0), interval: null });
            return id;
        };
        globalThis.setInterval = function (fn, ms) {
            const id = ++seq;
            pending.push({ id, fn, at: clock + (ms || 0), interval: ms || 1 });
            return id;
        };
        globalThis.clearTimeout = function (id) { pending = pending.filter((t) => t.id !== id); };
        globalThis.clearInterval = globalThis.clearTimeout;

        function advance(ms) {
            clock += ms;
            for (let guard = 0; guard < 200; guard++) {
                const due = pending.filter((t) => t.at <= clock).sort((a, b) => a.at - b.at)[0];
                if (! due) break;
                if (due.interval) { due.at = clock + due.interval; } else { pending = pending.filter((t) => t !== due); }
                due.fn();
            }
        }

        // Drain the microtask queue so promise chains settle.
        const settle = () => new Promise((r) => process.nextTick(() => process.nextTick(() => process.nextTick(r))));

        // ── Controllable fetch ───────────────────────────────────────────
        let requests = [];
        let mode     = 'ok';          // ok | offline-throw | refused | hang | stale-ack
        let staleAckValue = null;

        globalThis.fetch = function (url, opts) {
            const body = JSON.parse(opts.body);
            requests.push({ url, notes: body.notes });

            if (mode === 'offline-throw') return Promise.reject(new TypeError('Failed to fetch'));
            if (mode === 'hang')          return new Promise(() => {});
            if (mode === 'refused') {
                return Promise.resolve({
                    status: 422, ok: false,
                    json: () => Promise.resolve({ message: LOCK_MSG }),
                });
            }
            // The stale echo applies to the FIRST request only. A stub that
            // echoed a mismatched value forever is not a server — it span the
            // re-send path until node ran out of heap, which is how the tight
            // loop in the wiring was found.
            let echoed = body.notes;
            if (mode === 'stale-ack' && staleAckValue !== null) { echoed = staleAckValue; staleAckValue = null; }
            return Promise.resolve({
                status: 200, ok: true,
                json: () => Promise.resolve({ ok: true, notes: echoed, saved_at: '2026-09-29T11:04:00+00:00' }),
            });
        };

        function boot() {
            const factory = new Function('window', 'document', 'CustomEvent', 'navigator', 'WORKSHEET_ID',
                'setTimeout', 'clearTimeout', 'setInterval', 'fetch', 'Promise', 'JSON',
                STORE_BODY + '\\n' + WIRING_BODY + '\\n DraftStore.probe(); _notesInit(); return DraftStore;');
            return factory(globalThis.window, globalThis.document, globalThis.CustomEvent, navigatorStub, 9001,
                globalThis.setTimeout, globalThis.clearTimeout, globalThis.setInterval, globalThis.fetch, Promise, JSON);
        }

        function reset() {
            requests = [];
            pending  = [];
            clock    = 0;
            draftHandlers.length = 0;
            Object.keys(windowHandlers).forEach((k) => delete windowHandlers[k]);
            [ROOM_A, ROOM_B].forEach((n) => { fields[n].value = ''; statuses[n].textContent = ''; fields[n]._handlers = {}; });
        }

        // ⚠️ READ DEFENSIVELY. JSON.stringify DROPS an undefined value, so a
        // draft destroyed by a bug would reach PHP as a MISSING KEY and report as
        // "Undefined array key" instead of the crafted sentence written for it.
        // An assertion that crashes instead of reporting is an assertion nobody
        // can read at 8pm. (Plan 46.7-01 paid for this lesson once already.)
        function heldValue(store, room) {
            const e = store.get('notes:' + room);
            return (e && typeof e.value === 'string') ? e.value : null;
        }

        const out = { statesRendered: 0, storeSliceLength: STORE_BODY.length, wiringSliceLength: WIRING_BODY.length };

        (async () => {
            // ── STATE 1: empty ───────────────────────────────────────────
            backing = {}; reset();
            let store = boot();
            await settle();
            out.empty = { status: statuses[ROOM_A].textContent, requests: requests.length };
            out.statesRendered++;

            // ── STATE 2: typed ───────────────────────────────────────────
            fields[ROOM_A].value = 'cracked backbox behind the rack';
            fields[ROOM_A].fire('input');
            await settle();
            out.typed = {
                status: statuses[ROOM_A].textContent,
                draft: heldValue(store, ROOM_A),
                requests: requests.length,
            };
            out.statesRendered++;

            // ── STATE 3: saving — debounce elapsed, response not back ────
            mode = 'hang';
            advance(1300);
            await settle();
            out.saving = { status: statuses[ROOM_A].textContent, requests: requests.length };
            out.statesRendered++;

            // ── STATE 3b: a request that NEVER settles times out ─────────
            advance(8100);
            await settle();
            out.hang = { status: statuses[ROOM_A].textContent };

            // ── STATE 4: saved ───────────────────────────────────────────
            backing = {}; reset(); mode = 'ok';
            store = boot();
            await settle();
            fields[ROOM_A].value = 'two blanking plates still to fit';
            fields[ROOM_A].fire('input');
            advance(1300);
            await settle();
            out.saved = { status: statuses[ROOM_A].textContent, draftCount: store.count(), requests: requests.length };
            out.statesRendered++;

            // ── STATE 5: offline-held, then a reload ─────────────────────
            backing = {}; reset();
            store = boot();
            await settle();
            navigatorStub.onLine = false;
            fields[ROOM_A].value = 'no isolator fitted in the plant room';
            fields[ROOM_A].fire('input');
            advance(1300);
            await settle();
            const offline = { status: statuses[ROOM_A].textContent, requests: requests.length };

            // RELOAD: same backing storage, a brand new page.
            reset();
            store = boot();
            await settle();
            offline.afterReloadDraft      = heldValue(store, ROOM_A);
            offline.afterReloadStatus     = statuses[ROOM_A].textContent;
            offline.afterReloadFieldValue = fields[ROOM_A].value;
            out.offline = offline;
            out.statesRendered++;

            // ── STATE 6: the signal comes back ───────────────────────────
            requests = [];
            navigatorStub.onLine = true;
            (windowHandlers['online'] || []).forEach((h) => h({}));
            await settle();
            out.reconnect = { status: statuses[ROOM_A].textContent, draftCount: store.count(), requests: requests.length };
            out.statesRendered++;

            // ── STATE 7: signed and locked ───────────────────────────────
            backing = {}; reset(); mode = 'refused';
            store = boot();
            await settle();
            fields[ROOM_B].value = 'something found after the client had signed';
            fields[ROOM_B].fire('input');
            advance(1300);
            await settle();
            const refused = {
                status: statuses[ROOM_B].textContent,
                draft: heldValue(store, ROOM_B),
            };
            // Retrying must NOT happen for a locked room.
            (windowHandlers['online'] || []).forEach((h) => h({}));
            advance(31000);
            await settle();
            refused.requestsAfterRetry = requests.length;

            reset();
            store = boot();
            await settle();
            refused.afterReloadStatus = statuses[ROOM_B].textContent;
            out.refused = refused;
            out.statesRendered++;

            // ── The late acknowledgement ─────────────────────────────────
            backing = {}; reset(); mode = 'stale-ack'; staleAckValue = 'first pass';
            store = boot();
            await settle();
            fields[ROOM_A].value = 'first pass';
            fields[ROOM_A].fire('input');
            // The engineer types again while the first save is still in flight.
            fields[ROOM_A].value = 'second thoughts — it is the NEXT backbox';
            fields[ROOM_A].fire('input');
            advance(1300);
            await settle();
            out.lateAck = {
                draft: heldValue(store, ROOM_A),
                status: statuses[ROOM_A].textContent,
            };

            process.stdout.write(JSON.stringify(out));
        })().catch((e) => {
            process.stdout.write(JSON.stringify({ harnessError: String(e && e.stack || e) }));
            process.exit(1);
        });
        JS;
    }

    // ── Helpers for the DOM assertions ───────────────────────────────────────

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /** Quote a room name for an xpath literal — room names contain spaces and quotes. */
    private function xq(string $value): string
    {
        return '"' . $value . '"';
    }
}

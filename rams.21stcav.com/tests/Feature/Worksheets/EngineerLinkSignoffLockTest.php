<?php

namespace Tests\Feature\Worksheets;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DeviceLabelPhotoService;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 04 — D-07, THE SIGN-OFF LOCK, ASSERTED ON THE SERVER.
 *
 * The user, verbatim: *"client cannot chage anything as they are signing to
 * confirm work is complete."*
 *
 * ── WHY EVERY TEST HERE IS A PAIR ───────────────────────────────────────────
 *
 * A lock that refuses everything always is not a lock, it is an outage. Each of
 * the SIX capture endpoints gets TWO tests: it refuses on a signed worksheet,
 * and the SAME request succeeds on an unsigned one.
 *
 * ⚠️ IT WAS FIVE UNTIL 46.7-03 ADDED `saveRoomNotes`. A sixth locked endpoint
 * belongs HERE, not in a parallel file: this is the ONE place that says what
 * "capture is closed" means, and a future reader will not know to check a second
 * one. When a seventh arrives, it comes here too.
 *
 * ── WHY EVERY REFUSAL ALSO ASSERTS NO SIDE EFFECT ───────────────────────────
 *
 * A status-code-only assertion passes on an endpoint that refuses AFTER doing
 * the work. Each refusal therefore also proves nothing was written: no photo
 * row, no stored file, no created Device, no confirmed label, no deleted row.
 *
 * ── THE THREE THINGS THAT ARE DELIBERATELY *NOT* LOCKED ─────────────────────
 *
 *  1. `POST /worksheet/{token}/sign`. Sign-off is APPEND-ONLY and re-signing is
 *     a documented feature producing a snag-list audit trail. Locking it would
 *     delete a feature to satisfy a decision that never mentioned it.
 *     test_re_signing_an_already_signed_worksheet_still_appends_a_second_signoff
 *     is the assertion that stops this lock eating it silently.
 *
 *  2. `markRoomComplete` and `markSurveyReviewed`. These are engineer STATUS
 *     confirmations, not the captured record the client attested to. D-07
 *     enumerates exactly "kit rows, photo uploads, photo labels, photo deletes"
 *     and names NEITHER of them. Ruled out BY NAME here, and put to the user at
 *     checkpoint step 6 of plan 46.4-07 rather than decided silently — so a
 *     future reader sees a decision, not an oversight. Threat T-46.4-04-06,
 *     disposition ACCEPT.
 *
 *     ⚠️ AND THIS IS WHY `saveRoomNotes` IS *NOT* HERE WITH THEM, even though
 *     all three are room-scoped writes to the same JSON column. The two above
 *     record that somebody LOOKED at something. A note is free text that appears
 *     on the page a client signs and on the office's report — it IS the captured
 *     record, not a confirmation about it. So it locks, like a photo or a serial
 *     reading does, and it stays off the allow-list below so the reflection test
 *     ENFORCES that rather than excusing it.
 *
 *  3. Anything that only READS. `show`, `servePhoto`, `serveSurveyPhoto` and
 *     `downloadReferenceFile` stay open: a signed worksheet is a RECORD, and a
 *     client who signed it must be able to open the link and see what they
 *     signed. Read-only, not gone.
 *
 * ── THE FALLOUT, MEASURED AND NAMED (plan 04 task 3) ────────────────────────
 *
 * Every suite that could plausibly sign a worksheet and then write to it was run
 * and read, not assumed. EXACTLY ONE existing test changed:
 *
 *   · `EngineerLinkPhotoTrayGuardTest::test_this_plan_ships_no_lock_of_its_own`
 *     → INVERTED and renamed to
 *       `…::test_the_trays_capture_controls_are_gone_once_the_worksheet_is_signed`.
 *     The old behaviour it pinned was a KNOWN ONE-WAVE GAP, not a feature (plan
 *     02's summary says so in terms), so D-07 supersedes it. No assertion was
 *     deleted, no count loosened, and no assertGreaterThanOrEqual introduced.
 *
 * NO fixture had to move, and nothing else went red: tests/Feature/Worksheet 61,
 * Assets 11, Visits 27, Security 14, Documents 18, Cockpit 387, D-06 baseline 159
 * — all with 0 failed.
 *
 * @see app/Support/Worksheets/WorksheetCaptureLock.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-07)
 */
class EngineerLinkSignoffLockTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ROOMS = ['Boardroom', 'Comms Room'];

    /**
     * The public worksheet write routes carry per-route throttles
     * (`throttle:worksheet-photo-write`, `…-status-write`, `…-label-photo-upload`,
     * and since 46.7-03 `…-notes-write`).
     * Several tests here drive the same endpoint twice in a pair. Disabling ONLY
     * the throttler keeps every guard under test genuinely exercised instead of
     * turning a 422 assertion into a 429 that says nothing about the lock.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Sign-off Lock Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(fn (string $name) => ['name' => $name], self::ROOMS),
            ],
        ]);
    }

    /**
     * Append a sign-off row directly — the same shape `sign()` writes, minus the
     * request-derived audit columns. `ip_address` / `user_agent` are set by the
     * controller only and are NEVER rendered; nothing here reads them.
     */
    private function sign(Worksheet $worksheet, string $client = 'A Client'): void
    {
        $worksheet->signoffs()->create([
            'client_name'          => $client,
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);
    }

    private function photo(Worksheet $worksheet): \App\Models\WorksheetPhoto
    {
        $path = 'worksheet-photos/' . $worksheet->id . '/fixture.jpg';
        Storage::disk('local')->put($path, 'jpeg-bytes');

        return $worksheet->photos()->create([
            'room_name'     => self::ROOMS[0],
            'bucket'        => \App\Models\WorksheetPhoto::BUCKET_COMPLETION,
            'filename'      => $path,
            'original_name' => 'capture.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 1,
        ]);
    }

    /** A Device + a DeviceLabelPhoto for the confirm / delete endpoints. */
    private function labelPhoto(Worksheet $worksheet): DeviceLabelPhoto
    {
        $device = Device::create([
            'project_id'  => $worksheet->project_id,
            'room_name'   => self::ROOMS[0],
            'description' => 'Ceiling microphone',
        ]);

        $path = 'device-label-photos/fixture.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return DeviceLabelPhoto::create([
            'project_id'   => $worksheet->project_id,
            'device_id'    => $device->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => self::ROOMS[0],
            'photo_path'   => $path,
            'confirmed'    => false,
            'captured_at'  => now(),
        ]);
    }

    /**
     * Stub the vision service so the uploadLabelPhoto MIRROR case (unsigned →
     * succeeds) does not call Claude. The refusal case never reaches the
     * service at all, which is itself part of what is being asserted.
     */
    private function stubLabelPhotoService(Worksheet $worksheet): void
    {
        $this->mock(DeviceLabelPhotoService::class, function ($mock) use ($worksheet) {
            $mock->shouldReceive('capture')->andReturnUsing(function (...$args) use ($worksheet) {
                return DeviceLabelPhoto::create([
                    'project_id'   => $worksheet->project_id,
                    'worksheet_id' => $worksheet->id,
                    'room_name'    => self::ROOMS[0],
                    'photo_path'   => 'device-label-photos/stub.jpg',
                    'confirmed'    => false,
                    'captured_at'  => now(),
                ]);
            });
        });
    }

    // ── 1. uploadPhoto ───────────────────────────────────────────────────────

    public function test_upload_photo_is_refused_after_signoff_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $rowsBefore  = DB::table('worksheet_photos')->count();
        $filesBefore = count(Storage::disk('local')->allFiles());

        $this->postJson(route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]), [
            'room_name' => self::ROOMS[0],
            'photo'     => UploadedFile::fake()->image('late.jpg'),
            'bucket'    => \App\Models\WorksheetPhoto::BUCKET_COMPLETION,
        ])->assertStatus(422)
          ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — the refusal must precede both the DB write and the
        // file store, or a "refused" upload still fills the disk.
        $this->assertSame($rowsBefore, DB::table('worksheet_photos')->count());
        $this->assertSame($filesBefore, count(Storage::disk('local')->allFiles()));
    }

    public function test_upload_photo_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson(route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]), [
            'room_name' => self::ROOMS[0],
            'photo'     => UploadedFile::fake()->image('ok.jpg'),
            'bucket'    => \App\Models\WorksheetPhoto::BUCKET_COMPLETION,
        ])->assertOk();

        $this->assertSame(1, $worksheet->photos()->count());
    }

    // ── 2. deletePhoto ───────────────────────────────────────────────────────

    public function test_delete_photo_is_refused_after_signoff_and_deletes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $photo     = $this->photo($worksheet);
        $this->sign($worksheet);

        $this->deleteJson(route('public-worksheet.photos.delete', [
            'token' => $worksheet->access_token, 'photo' => $photo->id,
        ]))->assertStatus(422)
           ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — row AND file both survive. deletePhoto unlinks the
        // file BEFORE the row, so a guard in the wrong place would leave an
        // orphaned row pointing at nothing.
        $this->assertDatabaseHas('worksheet_photos', ['id' => $photo->id]);
        Storage::disk('local')->assertExists($photo->filename);
    }

    public function test_delete_photo_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();
        $photo     = $this->photo($worksheet);

        $this->deleteJson(route('public-worksheet.photos.delete', [
            'token' => $worksheet->access_token, 'photo' => $photo->id,
        ]))->assertOk();

        $this->assertDatabaseMissing('worksheet_photos', ['id' => $photo->id]);
    }

    // ── 3. uploadLabelPhoto ──────────────────────────────────────────────────

    public function test_upload_label_photo_is_refused_after_signoff_and_creates_no_device(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $devicesBefore = DB::table('devices')->count();

        $this->postJson(route('public-worksheet.label-photo.upload', ['token' => $worksheet->access_token]), [
            'photo'            => UploadedFile::fake()->image('label.jpg'),
            'room_name'        => self::ROOMS[0],
            'item_description' => 'Ceiling microphone',
        ])->assertStatus(422)
          ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — and note WHICH one. uploadLabelPhoto does a
        // Device::firstOrCreate before it stores anything, so a guard placed
        // after validation would still mint an asset-register row for a signed
        // worksheet. The device count is the assertion that catches that.
        $this->assertSame($devicesBefore, DB::table('devices')->count());
        $this->assertSame(0, DB::table('device_label_photos')->count());
    }

    public function test_upload_label_photo_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();
        $this->stubLabelPhotoService($worksheet);

        $this->postJson(route('public-worksheet.label-photo.upload', ['token' => $worksheet->access_token]), [
            'photo'            => UploadedFile::fake()->image('label.jpg'),
            'room_name'        => self::ROOMS[0],
            'item_description' => 'Ceiling microphone',
        ])->assertOk();

        $this->assertSame(1, DB::table('device_label_photos')->count());
    }

    // ── 4. confirmLabelPhoto ─────────────────────────────────────────────────

    public function test_confirm_label_photo_is_refused_after_signoff_and_confirms_nothing(): void
    {
        $worksheet = $this->worksheet();
        $label     = $this->labelPhoto($worksheet);
        $this->sign($worksheet);

        $this->postJson(route('public-worksheet.label-photo.confirm', [
            'token' => $worksheet->access_token, 'photo' => $label->id,
        ]), ['serial_number' => 'SN-LATE-0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — the label is still unconfirmed AND the serial never
        // reached the Device row in the asset register.
        $this->assertFalse((bool) $label->fresh()->confirmed);
        $this->assertNull($label->fresh()->device->serial_number);
    }

    public function test_confirm_label_photo_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();
        $label     = $this->labelPhoto($worksheet);

        $this->postJson(route('public-worksheet.label-photo.confirm', [
            'token' => $worksheet->access_token, 'photo' => $label->id,
        ]), ['serial_number' => 'SN-OK-0001'])->assertOk();

        $this->assertTrue((bool) $label->fresh()->confirmed);
        $this->assertSame('SN-OK-0001', $label->fresh()->device->serial_number);
    }

    // ── 5. deleteLabelPhoto ──────────────────────────────────────────────────

    public function test_delete_label_photo_is_refused_after_signoff_and_deletes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $label     = $this->labelPhoto($worksheet);
        $this->sign($worksheet);

        $this->deleteJson(route('public-worksheet.label-photo.delete', [
            'token' => $worksheet->access_token, 'photo' => $label->id,
        ]))->assertStatus(422)
           ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        $this->assertDatabaseHas('device_label_photos', ['id' => $label->id]);
        Storage::disk('public')->assertExists($label->photo_path);
    }

    public function test_delete_label_photo_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();
        $label     = $this->labelPhoto($worksheet);

        $this->deleteJson(route('public-worksheet.label-photo.delete', [
            'token' => $worksheet->access_token, 'photo' => $label->id,
        ]))->assertOk();

        $this->assertDatabaseMissing('device_label_photos', ['id' => $label->id]);
    }

    // ── 6. saveRoomNotes (46.7-03) ───────────────────────────────────────────

    /**
     * ⚠️ THE SIXTH LOCKED ENDPOINT, AND THE ONE MOST LIKELY TO BE ARGUED ABOUT.
     *
     * `saveRoomNotes` writes `pre_install_confirmations` — the same column as
     * `markRoomComplete` and `markSurveyReviewed`, which are BOTH on the
     * allow-list. The column is not what decides it. **What is written decides
     * it:** a note is engineer free text that appears on the page a client signs,
     * so it is the captured record and it freezes with the record.
     *
     * The no-side-effect assertion compares the WHOLE `pre_install_confirmations`
     * array, not just the absence of a `room_notes` key — because this is a
     * SHARED JSON column and a refusal that still read-modify-wrote it could
     * clobber a sibling namespace on its way out.
     */
    public function test_save_room_notes_is_refused_after_signoff_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet();

        // Give the column real sibling content first, so "unchanged" is a
        // statement about something rather than about null.
        $worksheet->pre_install_confirmations = [
            'survey_review' => [self::ROOMS[0] => ['reviewed_at' => '2026-09-01T09:00:00+00:00', 'reviewed_by' => 'ip:1.2.3.4|actor:deadbeefcafe']],
            'room_complete' => [self::ROOMS[1] => ['completed_at' => '2026-09-01T10:00:00+00:00', 'completed_by' => 'ip:1.2.3.4|actor:deadbeefcafe']],
        ];
        $worksheet->save();

        $this->sign($worksheet);

        $before = $worksheet->fresh()->pre_install_confirmations;

        $this->postJson(route('public-worksheet.room-notes', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOMS[0],
        ]), ['notes' => 'a note typed after the client had already signed'])
            ->assertStatus(422)
            ->assertJsonPath('message', WorksheetCaptureLock::MESSAGE);

        // NO SIDE EFFECT — the WHOLE array, compared as a whole array.
        $this->assertSame(
            $before,
            $worksheet->fresh()->pre_install_confirmations,
            'A refused note changed pre_install_confirmations. Either the note landed on a '
            . 'signed record, or the refusal clobbered a sibling namespace on the way out — '
            . 'and both are silent.',
        );
    }

    public function test_save_room_notes_still_succeeds_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson(route('public-worksheet.room-notes', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOMS[0],
        ]), ['notes' => 'cracked backbox behind the rack'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $confirmations = (array) $worksheet->fresh()->pre_install_confirmations;

        $this->assertSame(
            'cracked backbox behind the rack',
            $confirmations['room_notes'][self::ROOMS[0]]['notes'] ?? null,
            'The lock refuses a note on an UNSIGNED worksheet too. That is not a lock, it is '
            . 'an outage — the notes box would never work at all.',
        );
    }

    /**
     * A draft held on a phone with no signal drains in after the client has
     * signed. The refusal is correct; the engineer must READ the sentence.
     * 46.7-01's `markRefused` keeps the draft and shows exactly this string.
     */
    public function test_a_held_note_draining_in_after_signoff_gets_the_sentence_the_page_displays(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $response = $this->postJson(route('public-worksheet.room-notes', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOMS[0],
        ]), ['notes' => 'held in a plant room with no signal for forty minutes']);

        $response->assertStatus(422);
        $this->assertSame(
            WorksheetCaptureLock::MESSAGE,
            $response->json('message'),
            'The refused draft gets no sentence to show. The engineer is left with words on '
            . 'their screen and no idea why they will not send.',
        );
        $this->assertStringContainsString('signed off', WorksheetCaptureLock::MESSAGE);
    }

    // ── Existence, not equality ──────────────────────────────────────────────

    public function test_a_worksheet_signed_twice_is_still_locked(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet, 'First Client');
        $this->sign($worksheet, 'Second Client');

        $this->assertSame(2, $worksheet->signoffs()->count());
        $this->assertTrue(WorksheetCaptureLock::isLocked($worksheet->fresh()));

        $this->postJson(route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]), [
            'room_name' => self::ROOMS[0],
            'photo'     => UploadedFile::fake()->image('late.jpg'),
        ])->assertStatus(422);
    }

    // ── /sign stays OPEN — the assertion that stops the lock eating a feature ─

    /**
     * ⚠️ D-07 DOES NOT MENTION SIGNING, AND THIS LOCK MUST NOT TOUCH IT.
     *
     * Sign-off is append-only and re-signing produces a snag-list audit trail —
     * a documented feature. If a future reader "completes" the lock by adding
     * `sign` to the guarded set, this test is what goes red.
     */
    public function test_re_signing_an_already_signed_worksheet_still_appends_a_second_signoff(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet, 'First Client');

        $this->post(route('public-worksheet.sign', ['token' => $worksheet->access_token]), [
            'client_name'          => 'Second Client',
            'signature_image'      => 'data:image/png;base64,' . base64_encode('png-bytes'),
            'signed_with_comments' => true,
            'comments'             => 'Trunking lid in the comms room still to fit.',
        ])->assertRedirect(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $this->assertSame(2, $worksheet->signoffs()->count());
        $this->assertSame('Second Client', $worksheet->fresh()->latestSignoff()->client_name);
    }

    // ── Status confirmations stay OPEN — ruled out BY NAME (T-46.4-04-06) ─────

    /**
     * ⚠️ RULED OUT, NOT OVERLOOKED.
     *
     * D-07 enumerates the capture surface as "kit rows, photo uploads, photo
     * labels, photo deletes". `markRoomComplete` and `markSurveyReviewed` are
     * ENGINEER STATUS CONFIRMATIONS — they write
     * `pre_install_confirmations`, not the record the client signed — and D-07
     * names neither. Threat T-46.4-04-06 disposition is ACCEPT, and the
     * question is put to the user at checkpoint step 6 of plan 46.4-07.
     *
     * If the user says "lock them too", this test inverts and the two method
     * names move out of the reflection allow-list below. That is a two-line
     * change by design.
     */
    public function test_room_complete_and_survey_reviewed_still_succeed_after_signoff(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $this->post(route('public-worksheet.room-complete', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOMS[0],
        ]))->assertRedirect(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $this->post(route('public-worksheet.survey-reviewed', [
            'token' => $worksheet->access_token, 'roomName' => self::ROOMS[0],
        ]))->assertRedirect(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $confirmations = (array) $worksheet->fresh()->pre_install_confirmations;
        $this->assertArrayHasKey(self::ROOMS[0], $confirmations['room_complete']);
        $this->assertArrayHasKey(self::ROOMS[0], $confirmations['survey_review']);
    }

    // ── Reading stays OPEN — read-only, not gone ──────────────────────────────

    /**
     * A signed worksheet is a RECORD. The client who signed it must be able to
     * open the link and see what they signed; the engineer must be able to see
     * the photos they captured. Only the ability to CHANGE it goes.
     */
    public function test_the_page_still_renders_and_photos_still_serve_after_signoff(): void
    {
        $worksheet = $this->worksheet();
        $photo     = $this->photo($worksheet);
        $this->sign($worksheet);

        $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertOk()
            ->assertSee(self::ROOMS[0]);

        $this->get(route('public-worksheet.photos.serve', [
            'token' => $worksheet->access_token, 'photo' => $photo->id,
        ]))->assertOk();
    }

    // ── The completeness check that cannot rot ────────────────────────────────

    /**
     * ⚠️ THIS IS WHAT FORCES PLAN 46.4-05's THREE NEW KIT ENDPOINTS INTO THE
     * SAME GUARD.
     *
     * Reflect over every public method DECLARED on PublicWorksheetController,
     * subtract a named allow-list, and assert the remaining ones each call the
     * lock. A new write endpoint added without a guard fails here, by name,
     * without anyone having to remember to extend this file.
     *
     * Each allow-list entry carries its own one-line reason. An entry added to
     * silence a failure is therefore an entry that has to be justified in
     * writing.
     */
    public function test_every_unlisted_public_controller_method_calls_the_capture_lock(): void
    {
        $allowed = [
            // Reads — reading is not capturing (see the class docblock).
            'show'                  => 'GET — renders the record read-only after sign-off',
            'servePhoto'            => 'GET — streams a captured photo',
            'serveSurveyPhoto'      => 'GET — streams a survey reference photo',
            'downloadReferenceFile' => 'GET — streams a project reference file',
            // Writes deliberately left open, each ruled out above by name.
            'sign'                  => 'append-only audit trail; D-07 never mentions signing',
            'markRoomComplete'      => 'engineer status confirmation, not the captured record (T-46.4-04-06)',
            'markSurveyReviewed'    => 'engineer status confirmation, not the captured record (T-46.4-04-06)',
        ];

        // ⚠️ SEVEN. 46.7-03 added a sixth LOCKED endpoint (`saveRoomNotes`) and
        // deliberately did NOT add an eighth allow-list entry. If this number has
        // grown, something was excused rather than guarded — read the new entry's
        // reason and decide whether it is a status confirmation or the record.
        $this->assertCount(
            7,
            $allowed,
            'The capture-lock allow-list has changed size. An entry added to silence a failure '
            . 'is an unguarded write endpoint with a note attached.',
        );
        $this->assertArrayNotHasKey(
            'saveRoomNotes',
            $allowed,
            'saveRoomNotes has been excused from the capture lock. A note is the captured '
            . 'record the client signed, not a status confirmation about it — it must freeze '
            . 'when the record freezes. Guard the method; do not list it.',
        );

        $reflection = new \ReflectionClass(\App\Http\Controllers\PublicWorksheetController::class);
        $source     = file($reflection->getFileName());

        $unguarded = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue; // inherited from Controller — not an endpoint of ours
            }
            if (array_key_exists($method->getName(), $allowed)) {
                continue;
            }

            $body = implode('', array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            if (! str_contains($body, 'WorksheetCaptureLock::isLocked')) {
                $unguarded[] = $method->getName();
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            'These public PublicWorksheetController methods write without the D-07 capture lock '
            . 'and are not on the reasoned allow-list: %s. Either guard them with '
            . 'WorksheetCaptureLock::isLocked(), or add them to the allow-list WITH A REASON.',
            implode(', ', $unguarded),
        ));

        // The allow-list must not silently outlive the methods it excuses.
        foreach (array_keys($allowed) as $name) {
            $this->assertTrue($reflection->hasMethod($name), "Allow-listed method {$name} no longer exists — prune the list.");
        }
    }

    // ── The queued-row case, handed to plan 46.4-06 ───────────────────────────

    /**
     * ⚠️ REQUIREMENT ADDRESSED TO PLAN 46.4-06 — READ THIS BEFORE BUILDING THE
     * QUEUE PANEL.
     *
     * A worksheet can be signed while an engineer still has rows queued in
     * IndexedDB on their phone. Those rows drain into a now-locked endpoint and
     * receive this 422. **That refusal is correct** — the client signed a record
     * and late arrivals must not alter it — **but the engineer must be TOLD, in
     * words, not left staring at a row stuck on "failed".**
     *
     * The queue panel MUST surface the server's `message` VERBATIM for this
     * case. `WorksheetCaptureLock::MESSAGE` is what the engineer will read, and
     * that is exactly why it is a sentence and not the word "Locked".
     *
     * This test posts a photo shaped as `drain()` builds it — multipart, field
     * name `photo`, `room_name` and the `fields` bag spread in as top-level keys,
     * and NO bucket key (a row queued before this deploy has none) — so plan 06
     * has a real response body to display.
     */
    public function test_a_drained_queue_row_gets_the_human_sentence_plan_06_must_display(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $response = $this->post(
            route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]),
            [
                'photo'     => UploadedFile::fake()->image('queued-on-site.jpg'),
                'room_name' => self::ROOMS[0],
                'caption'   => 'Rack front, cable management complete',
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422);
        $this->assertSame(WorksheetCaptureLock::MESSAGE, $response->json('message'));
        // A sentence, not a code word — this is the string on the engineer's screen.
        $this->assertStringContainsString('signed off', WorksheetCaptureLock::MESSAGE);
        $this->assertSame(0, $worksheet->photos()->count());
    }

    // ── The docblock that used to say the opposite ────────────────────────────

    /**
     * The controller docblock said, verbatim, "The page remains active after
     * sign-off so engineers can continue updating notes / photos via the admin
     * pipeline." Half of that is now FALSE. A docblock a future reader trusts
     * over the code is exactly how D-07 gets undone in six months.
     */
    public function test_the_controller_docblock_no_longer_contradicts_d07(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PublicWorksheetController.php'));
        $head   = substr($source, 0, (int) strpos($source, 'class PublicWorksheetController'));

        $this->assertStringNotContainsString('engineers can continue updating', $head);
        $this->assertStringContainsString('D-07', $head);
        // The half that is STILL TRUE was corrected, not deleted.
        $this->assertStringContainsString('snag-list', $head);
    }
}

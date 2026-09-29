<?php

namespace Tests\Feature\Worksheets;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use App\Models\WorksheetPhoto;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 07 Task 1 — THE WHOLE PHASE, ONCE, IN THE ORDER AN ENGINEER
 * ACTUALLY WORKS, ENDING AT SIGN-OFF.
 *
 * Plans 01-06 each ended green on their own slice. Nobody had done the whole
 * job in sequence on ONE worksheet, and the three failure modes this phase was
 * most likely to ship are all invisible to a single-slice test:
 *
 *   · a tray rendering in the wrong bucket   — caught by SUBTREE assertions
 *   · a queued row that never arrives        — NOT provable here (see below)
 *   · a write that still succeeds after sign — caught by the DERIVED step-9 loop
 *
 * ── WHY STEP 9 IS DERIVED AND NOT A HAND-WRITTEN LIST OF EIGHT ──────────────
 *
 * A hand-written list is a list someone forgets to extend. `guardedEndpoints()`
 * reflects over `PublicWorksheetController` and returns every public method
 * DECLARED there whose body calls `WorksheetCaptureLock::isLocked`. The walk
 * then asserts that its own attempt map covers EXACTLY that set — no more, no
 * fewer. Add a ninth guarded write to the controller and this file goes red by
 * name, without anyone having to remember it existed.
 *
 * That pairs with, and does not duplicate,
 * `EngineerLinkSignoffLockTest::test_every_unlisted_public_controller_method_calls_the_capture_lock`:
 *
 *   · that test catches a write added WITHOUT a guard
 *   · this test catches a write added WITH a guard but never exercised end to end
 *
 * Neither owns an allow-list this file has to keep in step.
 *
 * ── WHY THE OFFLINE QUEUE IS NOT WALKED HERE ────────────────────────────────
 *
 * It is browser state — IndexedDB — and there is no browser harness in this
 * repo. Plan 06's SUMMARY says so, `OfflineQueueKitKindGuardTest` pins the
 * schema and the branch ordering by source scan, and the real proof is
 * checkpoint step 3 of `46.4-07-PLAN.md`: airplane mode, one photo AND one kit
 * row, restore signal, confirm both arrive. Nothing in this file may be read as
 * evidence for IC-03.
 *
 * ── THE RAW-TOKEN SWEEP IS NARROWED, AND DELIBERATELY SO ────────────────────
 *
 * ⚠️ The plan's step 14 asks for "no raw token" across every captured body. That
 * is UNACHIEVABLE AS WRITTEN and the narrowing is a finding, not a weakening:
 *
 *   · the engineer link IS `/worksheet/{token}` — every form action, every photo
 *     URL and every fetch target on that page necessarily contains the token
 *   · the OFFICE worksheet page renders photo-serve URLs that carry the token
 *     too, deliberately (`show.blade.php:691,700` — "the worksheet's access_token
 *     is known to the admin viewing")
 *
 * So the raw token is swept over the bodies where it would be a LEAK — every
 * JSON API response, and the project asset list — and not over the two pages
 * whose own URL it is. The email / phone / actor-stamp sweep runs over
 * EVERYTHING, because none of those may appear anywhere at all.
 *
 * @see app/Http/Controllers/PublicWorksheetController.php
 * @see app/Support/Worksheets/WorksheetCaptureLock.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-01..D-10)
 */
class EngineerLinkInstallCaptureEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /** Room A, then room B. `Str::slug()` of each is the room panel's id on the page. */
    private const ROOM_A = 'Boardroom';

    private const ROOM_B = 'Comms Room';

    private const ROOM_A_ID = 'room-boardroom';

    private const START_CAPTION = 'Rack bay before works';

    private const DELETION_REASON = 'Wrong part, returned to stores';

    private const SERIAL = 'SN-BOARDROOM-0001';

    /** D-02's named gap — never a blank cell, never a guessed name. */
    private const UNASSIGNED = 'Unassigned — no engineer allocated to this visit';

    // ── LR-04's non-vacuity fuel. A leak test with nothing to leak is a lie. ──

    private const ENGINEER_ONE_NAME = 'Marcus Webb';

    private const ENGINEER_ONE_EMAIL = 'marcus.webb@21stcav.example';

    private const ENGINEER_ONE_PHONE = '07700 900111';

    private const ENGINEER_TWO_NAME = 'Priya Raman';

    private const ENGINEER_TWO_EMAIL = 'priya.raman@21stcav.example';

    private const ENGINEER_TWO_PHONE = '07700 900222';

    /**
     * Every body the walk produces, swept at the END in one loop. A leak that
     * appears on only one of fourteen screens still gets caught — the shape
     * `CockpitInlineDrawerEndToEndTest` uses.
     *
     * @var array<string,string>
     */
    private array $bodies = [];

    /**
     * The subset that must ALSO be free of the raw token. See the class
     * docblock for why this is narrower than `$bodies`.
     *
     * @var array<string,string>
     */
    private array $tokenFreeBodies = [];

    /**
     * The public write routes carry per-token throttles. This walk drives the
     * kit endpoints five times and the photo endpoints repeatedly; disabling
     * ONLY the throttler keeps every guard under test genuinely exercised
     * instead of turning a 422 assertion into a 429 that says nothing.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function engineer(string $name, string $email, string $phone): LabourResource
    {
        return LabourResource::factory()->create([
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Install Capture Walk',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(
                    fn (string $name) => ['name' => $name],
                    [self::ROOM_A, self::ROOM_B],
                ),
            ],
        ]);
    }

    // ── Body capture ─────────────────────────────────────────────────────────

    private function capture(string $label, TestResponse $response, bool $tokenFree = false): TestResponse
    {
        $content = $response->getContent();

        $this->bodies[$label] = (string) $content;

        if ($tokenFree) {
            $this->tokenFreeBodies[$label] = (string) $content;
        }

        return $response;
    }

    private function visitLink(Worksheet $worksheet, string $label): TestResponse
    {
        return $this->capture(
            $label,
            $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertOk(),
        );
    }

    // ── DOM helpers — parsed, never grepped ──────────────────────────────────

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    private function subtree(\DOMXPath $xpath, string $query): string
    {
        $html = '';

        foreach ($xpath->query($query) as $node) {
            $html .= $node->ownerDocument->saveHTML($node);
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The innerHTML of the tray for one bucket in one room. */
    private function tray(\DOMXPath $xpath, string $bucket, string $room): string
    {
        return $this->subtree($xpath, sprintf(
            "//div[@data-photo-tray][@data-bucket='%s'][@data-room-key='%s']",
            $bucket,
            strtolower($room),
        ));
    }

    private function kitList(\DOMXPath $xpath, string $room): string
    {
        return $this->subtree($xpath, sprintf(
            "//ul[@data-kit-list][@data-room-key='%s']",
            strtolower($room),
        ));
    }

    // ── Step 9's derived endpoint list ───────────────────────────────────────

    /**
     * Every public method DECLARED on PublicWorksheetController whose body calls
     * the D-07 lock. DERIVED FROM THE SOURCE, never hand-written.
     *
     * @return list<string>
     */
    private function guardedEndpoints(): array
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\PublicWorksheetController::class);
        $source     = file($reflection->getFileName());

        $guarded = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue; // inherited from Controller — not an endpoint of ours
            }

            $body = implode('', array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            if (str_contains($body, 'WorksheetCaptureLock::isLocked')) {
                $guarded[] = $method->getName();
            }
        }

        sort($guarded);

        return $guarded;
    }

    // ── THE WALK ─────────────────────────────────────────────────────────────

    public function test_the_whole_phase_walks_once_in_order_and_stops_dead_at_signoff(): void
    {
        $engineerOne = $this->engineer(self::ENGINEER_ONE_NAME, self::ENGINEER_ONE_EMAIL, self::ENGINEER_ONE_PHONE);
        $engineerTwo = $this->engineer(self::ENGINEER_TWO_NAME, self::ENGINEER_TWO_EMAIL, self::ENGINEER_TWO_PHONE);

        $worksheet = $this->worksheet();
        $token     = $worksheet->access_token;

        Visit::factory()->create([
            'project_id'          => $worksheet->project_id,
            'type'                => Visit::TYPE_INSTALL,
            'source_type'         => Visit::SOURCE_WORKSHEET,
            'source_id'           => $worksheet->id,
            'labour_resource_ids' => [$engineerOne->id, $engineerTwo->id],
        ]);

        // ── 1. GET the link: both rooms, four trays, all empty ───────────────

        $xpath = $this->dom($this->visitLink($worksheet, '01-first-open')->getContent());

        // 46.5 D-06 — 4 became 6: two rooms, THREE trays each. Derived from
        // the vocabulary rather than re-typed, so a fourth bucket moves it.
        $this->assertSame(
            2 * count(WorksheetPhoto::BUCKETS),
            $xpath->query('//div[@data-photo-tray]')->length,
            'Two rooms should render a Start, a During and a Completion tray each.',
        );

        foreach ([self::ROOM_A, self::ROOM_B] as $room) {
            foreach (WorksheetPhoto::BUCKETS as $bucket) {
                $this->assertStringContainsString(
                    '(<span data-photo-count>0</span>)',
                    $this->tray($xpath, $bucket, $room),
                    "The {$bucket} tray in {$room} did not open empty.",
                );
            }
        }

        // ── 2. A start photo, captioned, lands in the START tray ONLY ────────

        $this->capture('02-start-photo', $this->postJson(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => self::ROOM_A,
                'photo'     => UploadedFile::fake()->image('before.jpg'),
                'caption'   => self::START_CAPTION,
                'bucket'    => WorksheetPhoto::BUCKET_START,
            ],
        )->assertOk());

        $xpath = $this->dom($this->visitLink($worksheet, '02-after-start-photo')->getContent());

        // ⚠️ SUBTREE, NOT assertSee. A whole-page assertion passes when both
        // photos land in the SAME tray — precisely the bug most likely to ship.
        $this->assertStringContainsString(self::START_CAPTION, $this->tray($xpath, WorksheetPhoto::BUCKET_START, self::ROOM_A));
        $this->assertStringNotContainsString(self::START_CAPTION, $this->tray($xpath, WorksheetPhoto::BUCKET_COMPLETION, self::ROOM_A));
        // 46.5 D-06 — and not into the new middle tray either.
        $this->assertStringNotContainsString(self::START_CAPTION, $this->tray($xpath, WorksheetPhoto::BUCKET_DURING, self::ROOM_A));

        // ── 3. A completion photo. The ROOM pill counts both ─────────────────

        $this->capture('03-completion-photo', $this->postJson(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => self::ROOM_A,
                'photo'     => UploadedFile::fake()->image('after.jpg'),
                'bucket'    => WorksheetPhoto::BUCKET_COMPLETION,
            ],
        )->assertOk());

        $xpath = $this->dom($this->visitLink($worksheet, '03-after-completion-photo')->getContent());

        $this->assertStringContainsString('(<span data-photo-count>1</span>)', $this->tray($xpath, WorksheetPhoto::BUCKET_START, self::ROOM_A));
        $this->assertStringContainsString('(<span data-photo-count>1</span>)', $this->tray($xpath, WorksheetPhoto::BUCKET_COMPLETION, self::ROOM_A));

        // The room pill stays WHOLE-ROOM — bucketing changed how photos are
        // filed, not how many the room has. The Mark Room Complete soft gate
        // reads this same number.
        //
        // ⚠️ THE XPATH WAS RETARGETED BY 46.7-02, AND THE COUNT WAS NOT TOUCHED.
        // This assertion read `//details[@id='room-boardroom']/summary` from
        // 46.4-02 until phase 46.7 turned the outer per-room `<details>` into a
        // tab panel (D-01): the element is now `<section class="ws-tab-panel">`
        // and the pills moved from its `<summary>` onto an `<h2
        // class="room-panel-head">`. THE ELEMENT CHANGED; THE COUNTED THING DID
        // NOT. `📷 2` is still exactly `📷 2`, still whole-room, still the number
        // the Mark Room Complete gate reads — the assertion is superseded in its
        // selector only, never loosened. If you are tempted to relax this to a
        // greater-than-or-equal, stop: the whole point is that two photos in two
        // different buckets add up to two on ONE pill.
        $this->assertStringContainsString(
            '📷 2',
            $this->subtree($xpath, "//section[@id='" . self::ROOM_A_ID . "']/h2"),
            'The room pill did not count both buckets.',
        );

        // ── 4. Three kit rows: two in room A, one in room B ──────────────────

        $rowOne = $this->capture('04-kit-add-one', $this->postJson(
            route('public-worksheet.additional-kit.add', ['token' => $token]),
            [
                'room_name'          => self::ROOM_A,
                'labour_resource_id' => $engineerOne->id,
                'qty'                => 3,
                'part_description'   => 'Cat6A patch lead 2m',
            ],
        )->assertStatus(201), tokenFree: true)->json('id');

        $this->capture('04-kit-add-two', $this->postJson(
            route('public-worksheet.additional-kit.add', ['token' => $token]),
            [
                'room_name'          => self::ROOM_A,
                'labour_resource_id' => null,
                'qty'                => 1,
                'part_description'   => 'Blanking plate 1U',
            ],
        )->assertStatus(201), tokenFree: true);

        $roomBRow = $this->capture('04-kit-add-three', $this->postJson(
            route('public-worksheet.additional-kit.add', ['token' => $token]),
            [
                'room_name'          => self::ROOM_B,
                'labour_resource_id' => $engineerOne->id,
                'qty'                => 2,
                'part_description'   => 'Trunking lid 100mm',
            ],
        )->assertStatus(201), tokenFree: true)->json('id');

        $this->assertSame(3, WorksheetAdditionalKit::query()->where('worksheet_id', $worksheet->id)->count());

        $xpath = $this->dom($this->visitLink($worksheet, '04-after-kit-rows')->getContent());

        $listA = $this->kitList($xpath, self::ROOM_A);
        $listB = $this->kitList($xpath, self::ROOM_B);

        // Each row in its OWN room's list — asserted by subtree for the same
        // reason the trays are.
        $this->assertStringContainsString('Cat6A patch lead 2m', $listA);
        $this->assertStringContainsString('Blanking plate 1U', $listA);
        $this->assertStringNotContainsString('Trunking lid 100mm', $listA);

        $this->assertStringContainsString('Trunking lid 100mm', $listB);
        $this->assertStringNotContainsString('Cat6A patch lead 2m', $listB);

        // D-02's fallback renders as a NAMED gap on the null row.
        $this->assertStringContainsString(self::UNASSIGNED, $listA);

        // ── 5. MODIFY room A's first row: qty 3 → 5, reassign to engineer 2 ──

        $this->capture('05-kit-modify', $this->postJson(
            route('public-worksheet.additional-kit.modify', ['token' => $token, 'row' => $rowOne]),
            [
                'qty'                => 5,
                'labour_resource_id' => $engineerTwo->id,
            ],
        )->assertOk()->assertJsonPath('amended', true), tokenFree: true);

        $xpath = $this->dom($this->visitLink($worksheet, '05-after-modify')->getContent());
        $listA = $this->kitList($xpath, self::ROOM_A);

        $this->assertStringContainsString('5 ×', $listA);
        $this->assertStringContainsString(self::ENGINEER_TWO_NAME, $listA);
        $this->assertStringContainsString('data-kit-chip="amended"', $listA, 'The row does not read as amended on the engineer link.');

        // ── 6. MARK the room B row with a reason ─────────────────────────────

        $this->capture('06-kit-mark', $this->postJson(
            route('public-worksheet.additional-kit.mark-deleted', ['token' => $token, 'row' => $roomBRow]),
            ['deletion_reason' => self::DELETION_REASON],
        )->assertOk()->assertJsonPath('marked', true), tokenFree: true);

        // D-08: the row STAYS. Nothing is ever hard deleted.
        $this->assertDatabaseHas('worksheet_additional_kit', ['id' => $roomBRow]);

        $xpath = $this->dom($this->visitLink($worksheet, '06-after-mark')->getContent());
        $listB = $this->kitList($xpath, self::ROOM_B);

        $this->assertStringContainsString('Trunking lid 100mm', $listB, 'A marked row vanished — D-08 says it stays.');
        $this->assertStringContainsString('data-kit-chip="marked"', $listB);
        $this->assertStringContainsString(self::DELETION_REASON, $listB);

        $markedAt     = WorksheetAdditionalKit::find($roomBRow)->marked_for_deletion_at;
        $markedReason = WorksheetAdditionalKit::find($roomBRow)->deletion_reason;

        // ── 7. A forged room name: 422, and no row created ───────────────────

        $before = WorksheetAdditionalKit::query()->where('worksheet_id', $worksheet->id)->count();

        $this->capture('07-forged-room', $this->postJson(
            route('public-worksheet.additional-kit.add', ['token' => $token]),
            [
                'room_name'        => 'Server Room That Does Not Exist',
                'qty'              => 1,
                'part_description' => 'Forged',
            ],
        )->assertStatus(422), tokenFree: true);

        $this->assertSame($before, WorksheetAdditionalKit::query()->where('worksheet_id', $worksheet->id)->count());

        // ── 8. Marking an already-marked row: 422, first reason untouched ────

        $this->capture('08-double-mark', $this->postJson(
            route('public-worksheet.additional-kit.mark-deleted', ['token' => $token, 'row' => $roomBRow]),
            ['deletion_reason' => 'A second engineer overwrote the first explanation'],
        )->assertStatus(422), tokenFree: true);

        $reread = WorksheetAdditionalKit::find($roomBRow);
        $this->assertSame($markedReason, $reread->deletion_reason, 'A second mark rewrote the first engineer\'s reason.');
        $this->assertTrue($markedAt->equalTo($reread->marked_for_deletion_at), 'A second mark moved the original timestamp.');

        // A label photo and a plain photo to aim the post-sign-off deletes at.
        $device = Device::create([
            'project_id'    => $worksheet->project_id,
            'room_name'     => self::ROOM_A,
            'description'   => 'Sony 85-inch display',
            'serial_number' => self::SERIAL,
        ]);

        $labelPath = 'device-label-photos/walk.jpg';
        Storage::disk('public')->put($labelPath, 'jpeg-bytes');

        $labelPhoto = DeviceLabelPhoto::create([
            'project_id'   => $worksheet->project_id,
            'device_id'    => $device->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => self::ROOM_A,
            'photo_path'   => $labelPath,
            'confirmed'    => false,
            'captured_at'  => now(),
            'captured_by'  => 'ip:10.0.0.1|actor:deadbeefcafe',
        ]);

        $survivingPhoto = $worksheet->photos()->first();

        // ── 9. SIGN. Then EVERY guarded write refuses ────────────────────────

        $this->capture('09-sign', $this->post(
            route('public-worksheet.sign', ['token' => $token]),
            [
                'client_name'     => 'A Client',
                'signature_image' => 'data:image/png;base64,' . base64_encode('png-bytes'),
                'happy_with_work' => true,
            ],
        )->assertRedirect(route('public-worksheet.show', ['token' => $token])));

        $this->assertTrue(WorksheetCaptureLock::isLocked($worksheet->fresh()));

        // The attempt map. Keyed by controller method so the derived list below
        // can be compared against it BY NAME.
        $attempts = [
            'uploadPhoto' => fn () => $this->postJson(
                route('public-worksheet.photos.upload', ['token' => $token]),
                [
                    'room_name' => self::ROOM_A,
                    'photo'     => UploadedFile::fake()->image('too-late.jpg'),
                    'bucket'    => WorksheetPhoto::BUCKET_COMPLETION,
                ],
            ),
            'deletePhoto' => fn () => $this->deleteJson(
                route('public-worksheet.photos.delete', ['token' => $token, 'photo' => $survivingPhoto->id]),
            ),
            'uploadLabelPhoto' => fn () => $this->postJson(
                route('public-worksheet.label-photo.upload', ['token' => $token]),
                [
                    'photo'            => UploadedFile::fake()->image('label.jpg'),
                    'room_name'        => self::ROOM_A,
                    'item_description' => 'Ceiling microphone',
                ],
            ),
            'confirmLabelPhoto' => fn () => $this->postJson(
                route('public-worksheet.label-photo.confirm', ['token' => $token, 'photo' => $labelPhoto->id]),
                ['serial_number' => 'SN-AFTER-THE-FACT'],
            ),
            'deleteLabelPhoto' => fn () => $this->deleteJson(
                route('public-worksheet.label-photo.delete', ['token' => $token, 'photo' => $labelPhoto->id]),
            ),
            'addAdditionalKit' => fn () => $this->postJson(
                route('public-worksheet.additional-kit.add', ['token' => $token]),
                [
                    'room_name'        => self::ROOM_A,
                    'qty'              => 1,
                    'part_description' => 'Added after the client signed',
                ],
            ),
            'modifyAdditionalKit' => fn () => $this->postJson(
                route('public-worksheet.additional-kit.modify', ['token' => $token, 'row' => $rowOne]),
                ['qty' => 99],
            ),
            'markAdditionalKitForDeletion' => fn () => $this->postJson(
                route('public-worksheet.additional-kit.mark-deleted', ['token' => $token, 'row' => $rowOne]),
                ['deletion_reason' => 'Marked after the client signed'],
            ),
            // 46.7-03 — the NINTH guarded write. Added because the derivation
            // below demanded it by name, which is the derivation working.
            'saveRoomNotes' => fn () => $this->postJson(
                route('public-worksheet.room-notes', ['token' => $token, 'roomName' => self::ROOM_A]),
                ['notes' => 'typed after the client had already signed'],
            ),
        ];

        // ⚠️ THE LIST IS DERIVED. A ninth guarded write added to the controller
        // and not exercised here fails on THIS line, by name.
        $derived = $this->guardedEndpoints();
        $mapped  = array_keys($attempts);
        sort($mapped);

        $this->assertSame($derived, $mapped, sprintf(
            'The post-sign-off refusal loop is out of step with the controller. '
            . 'Guarded there: %s. Exercised here: %s. Add the missing endpoint to $attempts — '
            . 'do NOT prune the derivation.',
            implode(', ', $derived),
            implode(', ', $mapped),
        ));
        $this->assertGreaterThanOrEqual(8, count($derived), 'Fewer guarded endpoints than the eight D-07 named — a guard was removed.');

        // Snapshot EVERYTHING a refused write could move.
        $snapshot = [
            'worksheet_photos'       => DB::table('worksheet_photos')->count(),
            'worksheet_additional_kit' => DB::table('worksheet_additional_kit')->count(),
            'devices'                => DB::table('devices')->count(),
            'device_label_photos'    => DB::table('device_label_photos')->count(),
            'local_files'            => count(Storage::disk('local')->allFiles()),
            'public_files'           => count(Storage::disk('public')->allFiles()),
        ];
        $rowOneQty     = WorksheetAdditionalKit::find($rowOne)->qty;
        $rowOneChanges = count(WorksheetAdditionalKit::find($rowOne)->amendments);
        // 46.7-03 — the shared JSON column is what a refused NOTE could move,
        // and nothing above would notice: it is not a row, a file or a count.
        $confirmationsBefore = $worksheet->fresh()->pre_install_confirmations;

        foreach ($attempts as $method => $attempt) {
            $this->capture(
                '09-refused-' . $method,
                $attempt()->assertStatus(422)->assertJsonPath('message', WorksheetCaptureLock::MESSAGE),
                tokenFree: true,
            );
        }

        // NO SIDE EFFECT — on any of them. A status-code-only assertion passes
        // on an endpoint that refuses AFTER doing the work.
        $this->assertSame($snapshot['worksheet_photos'], DB::table('worksheet_photos')->count());
        $this->assertSame($snapshot['worksheet_additional_kit'], DB::table('worksheet_additional_kit')->count());
        $this->assertSame($snapshot['devices'], DB::table('devices')->count());
        $this->assertSame($snapshot['device_label_photos'], DB::table('device_label_photos')->count());
        $this->assertSame($snapshot['local_files'], count(Storage::disk('local')->allFiles()));
        $this->assertSame($snapshot['public_files'], count(Storage::disk('public')->allFiles()));

        $this->assertSame($rowOneQty, WorksheetAdditionalKit::find($rowOne)->qty, 'A refused modify still changed the row.');
        $this->assertSame($rowOneChanges, count(WorksheetAdditionalKit::find($rowOne)->amendments), 'A refused modify still appended to the trail.');
        $this->assertFalse(WorksheetAdditionalKit::find($rowOne)->isMarked(), 'A refused mark still flagged the row.');
        $this->assertSame(
            $confirmationsBefore,
            $worksheet->fresh()->pre_install_confirmations,
            'A refused write moved pre_install_confirmations. Either a note landed on a signed '
            . 'record, or a refusal clobbered the survey_review / room_complete namespaces that '
            . 'share that column — and both are completely silent.',
        );
        $this->assertFalse((bool) $labelPhoto->fresh()->confirmed);
        $this->assertSame(self::SERIAL, $device->fresh()->serial_number, 'A refused confirm overwrote a captured serial.');

        // ── 10. Signing AGAIN still works, and appends ───────────────────────

        $this->capture('10-sign-again', $this->post(
            route('public-worksheet.sign', ['token' => $token]),
            [
                'client_name'          => 'Second Client',
                'signature_image'      => 'data:image/png;base64,' . base64_encode('png-bytes'),
                'signed_with_comments' => true,
                'comments'             => 'Trunking lid in the comms room still to fit.',
            ],
        )->assertRedirect(route('public-worksheet.show', ['token' => $token])));

        $this->assertSame(2, $worksheet->signoffs()->count(), 'Re-signing stopped appending — the lock ate a documented feature.');

        // ── 11. The signed page: a record you can read, not one you can move ─

        $signedPage = $this->visitLink($worksheet, '11-signed-page')->getContent();
        $xpath      = $this->dom($signedPage);

        // Everything that was captured still renders.
        $this->assertStringContainsString(self::START_CAPTION, $this->tray($xpath, WorksheetPhoto::BUCKET_START, self::ROOM_A));
        $this->assertStringContainsString('Cat6A patch lead 2m', $this->kitList($xpath, self::ROOM_A));
        $this->assertStringContainsString('Trunking lid 100mm', $this->kitList($xpath, self::ROOM_B));
        $this->assertStringContainsString(self::DELETION_REASON, $this->kitList($xpath, self::ROOM_B));

        // Not one capture control does.
        $this->assertSame(0, $xpath->query('//*[@data-capture-control]')->length,
            'A capture control survived sign-off on the rendered page.');
        $this->assertSame(0, $xpath->query('//*[@data-kit-trigger]')->length);

        // And the page says why, in a sentence a client can read.
        $this->assertStringContainsString('can no longer be changed', $signedPage);

        // ── 12. The office page ──────────────────────────────────────────────

        $staff = User::factory()->create();

        $office = $this->capture(
            '12-office-worksheet',
            $this->actingAs($staff)->get(route('worksheets.show', $worksheet))->assertOk(),
        )->getContent();

        // A named engineer AND a named gap — never a blank cell.
        $this->assertStringContainsString(self::ENGINEER_TWO_NAME, $office);
        $this->assertStringContainsString(self::ENGINEER_ONE_NAME, $office);
        $this->assertStringContainsString(self::UNASSIGNED, $office);

        // ⚠️ THE FROM-VALUE. This is the whole point of D-08: an office that can
        // only see the latest numbers cannot reconcile anything.
        $this->assertStringContainsString('qty: 3 → 5', $office);
        $this->assertStringContainsString(self::DELETION_REASON, $office);

        $this->capture(
            '12-office-reconcile',
            $this->actingAs($staff)->post(route('worksheets.additional-kit.reconcile', [
                'worksheet' => $worksheet->id,
                'row'       => $roomBRow,
            ]))->assertRedirect(),
        );

        $this->assertNotNull(WorksheetAdditionalKit::find($roomBRow)->reconciled_at,
            'A marked row could not be reconciled — the office has no way to action it.');

        // ── 13. The asset list (D-05) ────────────────────────────────────────

        $assets = $this->capture(
            '13-asset-list',
            $this->actingAs($staff)->get(route('projects.asset-list', $worksheet->project_id))->assertOk(),
            tokenFree: true,
        )->getContent();

        $this->assertStringContainsString(self::SERIAL, $assets);

        // ── 14. THE SWEEP, over every body the walk produced ─────────────────

        $this->assertGreaterThanOrEqual(14, count($this->bodies),
            'Fewer bodies captured than the walk has steps — the sweep would be thin.');

        // NON-VACUITY FIRST. Every forbidden value has a live counterpart that
        // DOES appear, so none of the absences below can pass because there was
        // nothing to leak.
        $this->assertStringContainsString(self::ENGINEER_ONE_NAME, $office);
        $this->assertStringContainsString(self::ENGINEER_TWO_NAME, $this->bodies['11-signed-page']);
        $this->assertStringContainsString('3', $office);
        $this->assertStringContainsString(self::DELETION_REASON, $this->bodies['11-signed-page']);
        $this->assertStringContainsString(self::SERIAL, $assets);
        $this->assertNotNull(LabourResource::find($engineerOne->id)->email, 'The fixture lost its email — LR-04 would pass vacuously.');
        $this->assertNotNull(LabourResource::find($engineerTwo->id)->phone);
        $this->assertNotNull(WorksheetAdditionalKit::find($rowOne)->created_by_actor, 'No actor stamp was written — the stamp sweep would be vacuous.');

        $actorStamp = (string) WorksheetAdditionalKit::find($rowOne)->created_by_actor;
        $actorSlice = substr(hash('sha256', $token), 0, 12);

        // LR-04 and the actor stamp: forbidden EVERYWHERE, no exceptions.
        $forbiddenEverywhere = [
            'engineer 1 email' => self::ENGINEER_ONE_EMAIL,
            'engineer 2 email' => self::ENGINEER_TWO_EMAIL,
            'engineer 1 phone' => self::ENGINEER_ONE_PHONE,
            'engineer 2 phone' => self::ENGINEER_TWO_PHONE,
            'actor stamp'      => $actorStamp,
            'actor slice'      => 'actor:' . $actorSlice,
            'label captured_by' => 'ip:10.0.0.1|actor:deadbeefcafe',
        ];

        foreach ($this->bodies as $label => $body) {
            foreach ($forbiddenEverywhere as $what => $needle) {
                $this->assertStringNotContainsString($needle, $body,
                    "The {$what} leaked into the '{$label}' response.");
            }
        }

        // ⚠️ THE TWO uploadPhoto SUCCESS BODIES CARRY THE TOKEN, BY DESIGN — AND
        // ARE ASSERTED NARROWLY RATHER THAN WAVED THROUGH.
        //
        // `uploadPhoto` returns `url => route('public-worksheet.photos.serve', …)`
        // so the page can render the thumbnail it has just captured without a
        // reload. That URL IS the photo's address on a token-addressed surface;
        // it is not a second place the token lands. The rule enforced here is
        // therefore exact: the token may appear ONCE in each of those bodies, and
        // only inside `url`. A future field that echoed the token back would push
        // the count to two and fail.
        foreach (['02-start-photo', '03-completion-photo'] as $uploadBody) {
            $decoded = json_decode($this->bodies[$uploadBody], true);

            $this->assertSame(1, substr_count($this->bodies[$uploadBody], $token),
                "The token appears more than once in '{$uploadBody}' — something other than `url` is echoing it.");
            $this->assertStringContainsString($token, (string) ($decoded['url'] ?? ''),
                "The single token occurrence in '{$uploadBody}' is not the photo-serve URL.");
        }

        // The raw token: forbidden in every JSON body and on the asset list.
        // See the class docblock for why the two token-addressed PAGES are out.
        $this->assertGreaterThanOrEqual(14, count($this->tokenFreeBodies));

        foreach ($this->tokenFreeBodies as $label => $body) {
            $this->assertStringNotContainsString($token, $body,
                "The raw access token leaked into the '{$label}' response.");
        }
    }
}

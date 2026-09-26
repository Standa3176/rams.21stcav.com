<?php

namespace Tests\Feature\Worksheets;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 05 — the three additional-kit endpoints: ADD, MODIFY and
 * MARK-FOR-DELETION-WITH-A-REASON.
 *
 * The user, verbatim: *"Engineer can add items , modify items and mark items
 * for deletion (with reason)."* (D-08)
 *
 * ── THE FOUR THINGS THIS FILE EXISTS TO PIN ─────────────────────────────────
 *
 * 1. **NOTHING IS EVER HARD DELETED (D-08).** A marked row STAYS in the table,
 *    flagged, with its reason, and still reaches the office's list. There is no
 *    DELETE route, no unmark and no soft-delete trait —
 *    `test_there_is_no_hard_delete_route` asserts 405 on
 *    `DELETE /additional-kit/{row}` because that is the cheapest possible proof
 *    that this phase has no hard-delete path at all.
 *
 * 2. **AMENDMENTS ARE APPEND-ONLY.** A correction is an amendment, not an
 *    overwrite. A field that did not change is ABSENT from `changes` — asserted
 *    as an absence, because a trail full of `{from: 3, to: 3}` is noise the
 *    office stops reading. A no-op modify is 422 so the trail can never gain an
 *    empty entry, and the first entry is compared BYTE-FOR-BYTE after a second
 *    modify.
 *
 * 3. **THE ENGINEER IS NEVER TYPED (D-02).** A posted `labour_resource_id` must
 *    be one this worksheet's visit allocated, or null. There is no free-text
 *    engineer field anywhere in this phase, so an unresolved engineer can never
 *    turn into a spelling variant. All three D-02 fallbacks (no visit, empty
 *    allocation, null id) are asserted to SUCCEED, not to 500 — the engineer
 *    link is used standing in a plant room.
 *
 * 4. **THE TOKEN IS THE ONLY CREDENTIAL, AND IT MUST NOT LEAK (audit M-06).**
 *    `created_by_actor`, `marked_by_actor` and every `amendments[].actor` carry
 *    `ip:…|actor:<12 hex of sha256($token)>`. The RAW TOKEN is asserted absent
 *    from all three, with a real token in play.
 *
 * ── AND THE ONE IT INHERITS ─────────────────────────────────────────────────
 *
 * D-07: all three endpoints refuse once the worksheet is signed, guarded BEFORE
 * validation, with a no-side-effect assertion on every refusal.
 * `EngineerLinkSignoffLockTest::test_every_unlisted_public_controller_method_calls_the_capture_lock`
 * is the completeness check that made that non-optional — these three methods
 * are deliberately NOT on its allow-list.
 *
 * @see app/Http/Controllers/PublicWorksheetController.php
 * @see app/Models/WorksheetAdditionalKit.php
 * @see app/Support/Worksheets/AllocatedEngineers.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-02, D-06, D-07, D-08, D-09, D-10)
 */
class EngineerLinkAdditionalKitTest extends TestCase
{
    use RefreshDatabase;

    private const ROOMS = ['Boardroom', 'Reception'];

    /**
     * All three endpoints sit on `worksheet-kit-write` (30/min, per-token).
     * Several tests below drive the same endpoint two or three times in a row;
     * disabling ONLY the throttler keeps every guard genuinely exercised
     * instead of turning a 422 assertion into a 429 that says nothing.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Additional Kit Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(fn (string $name) => ['name' => $name], self::ROOMS),
            ],
        ]);
    }

    /** A worksheet whose generated_data has no rooms at all — reachable live. */
    private function roomlessWorksheet(): Worksheet
    {
        $worksheet = $this->worksheet();
        $worksheet->generated_data = ['rooms' => []];
        $worksheet->save();

        return $worksheet;
    }

    /**
     * An engineer WITH CONTACT DETAILS ON THE RECORD. LR-04 assertions are
     * worthless without them, and a 422 that names a rejected engineer is an
     * enumeration oracle.
     */
    private function engineer(string $name): LabourResource
    {
        return LabourResource::factory()->create([
            'name'  => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@21stcav.example',
            'phone' => '07700 900123',
        ]);
    }

    /** @param array<int,int> $ids */
    private function visit(Worksheet $worksheet, array $ids): Visit
    {
        return Visit::factory()->create([
            'project_id'          => $worksheet->project_id,
            'type'                => Visit::TYPE_INSTALL,
            'source_type'         => Visit::SOURCE_WORKSHEET,
            'source_id'           => $worksheet->id,
            'labour_resource_ids' => $ids,
        ]);
    }

    private function sign(Worksheet $worksheet): void
    {
        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);
    }

    private function addUrl(Worksheet $worksheet): string
    {
        return '/worksheet/' . $worksheet->access_token . '/additional-kit';
    }

    private function modifyUrl(Worksheet $worksheet, int $rowId): string
    {
        return '/worksheet/' . $worksheet->access_token . '/additional-kit/' . $rowId;
    }

    private function markUrl(Worksheet $worksheet, int $rowId): string
    {
        return '/worksheet/' . $worksheet->access_token . '/additional-kit/' . $rowId . '/mark-deleted';
    }

    /** @param array<string,mixed> $overrides */
    private function row(Worksheet $worksheet, array $overrides = []): WorksheetAdditionalKit
    {
        return WorksheetAdditionalKit::factory()->create(array_merge([
            'worksheet_id'     => $worksheet->id,
            'room_name'        => self::ROOMS[0],
            'qty'              => 3,
            'part_description' => 'Trunking, 50x50 white',
            'sort_order'       => 1,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ADD
    // ═══════════════════════════════════════════════════════════════════════

    public function test_add_creates_one_row_and_returns_it(): void
    {
        $worksheet = $this->worksheet();
        $engineer  = $this->engineer('Dean Whitcombe');
        $this->visit($worksheet, [$engineer->id]);

        $response = $this->postJson($this->addUrl($worksheet), [
            'room_name'          => self::ROOMS[0],
            'labour_resource_id' => $engineer->id,
            'qty'                => 3,
            'part_description'   => 'Trunking, 50x50 white',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'room_name'        => self::ROOMS[0],
            'qty'              => 3,
            'part_description' => 'Trunking, 50x50 white',
            'engineer_name'    => 'Dean Whitcombe',
        ]);

        $this->assertSame(1, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());

        $row = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->sole();
        $this->assertSame(self::ROOMS[0], $row->room_name);
        $this->assertSame($engineer->id, $row->labour_resource_id);
        $this->assertSame(3, $row->qty);
        $this->assertSame('Trunking, 50x50 white', $row->part_description);
    }

    public function test_two_adds_create_two_separate_rows_not_an_appended_string(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'HDMI 2.1 lead, 3m',
        ])->assertStatus(201);

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 2,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(201);

        $rows = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)
            ->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('HDMI 2.1 lead, 3m', $rows[0]->part_description);
        $this->assertSame('Backbox, 35mm', $rows[1]->part_description);
    }

    public function test_sort_order_increments_within_the_room(): void
    {
        $worksheet = $this->worksheet();

        foreach ([self::ROOMS[0], self::ROOMS[0], self::ROOMS[1]] as $room) {
            $this->postJson($this->addUrl($worksheet), [
                'room_name'        => $room,
                'qty'              => 1,
                'part_description' => 'Cat6A U/FTP patch lead, 2m, grey',
            ])->assertStatus(201);
        }

        $boardroom = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)
            ->where('room_name', self::ROOMS[0])->orderBy('id')->pluck('sort_order')->all();
        $reception = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)
            ->where('room_name', self::ROOMS[1])->orderBy('id')->pluck('sort_order')->all();

        $this->assertSame([1, 2], $boardroom);
        // ⚠️ WITHIN the room — the second room starts at 1, it does not
        // continue the worksheet-wide sequence.
        $this->assertSame([1], $reception);
    }

    public function test_a_forged_room_name_is_refused(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => 'Not A Real Room',
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(422);

        $this->assertSame(0, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    public function test_a_worksheet_with_no_rooms_is_refused_and_does_not_500(): void
    {
        $worksheet = $this->roomlessWorksheet();

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(422);

        $this->assertSame(0, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    // ── D-02's three fallbacks: NONE of these may 500 ────────────────────────

    public function test_a_null_engineer_is_accepted_and_stored_null(): void
    {
        $worksheet = $this->worksheet();
        $engineer  = $this->engineer('Dean Whitcombe');
        $this->visit($worksheet, [$engineer->id]);

        $response = $this->postJson($this->addUrl($worksheet), [
            'room_name'          => self::ROOMS[0],
            'labour_resource_id' => null,
            'qty'                => 1,
            'part_description'   => 'Backbox, 35mm',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['engineer_name' => 'Unassigned — no engineer allocated to this visit']);
        $this->assertNull(WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->sole()->labour_resource_id);
    }

    public function test_a_worksheet_with_no_visit_at_all_still_accepts_a_row(): void
    {
        $worksheet = $this->worksheet(); // no Visit created

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(201);

        $this->assertSame(1, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    public function test_a_visit_that_allocates_nobody_still_accepts_a_row(): void
    {
        $worksheet = $this->worksheet();
        $this->visit($worksheet, []);

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(201);

        $this->assertSame(1, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    public function test_an_engineer_not_allocated_to_this_visit_is_refused_without_naming_them(): void
    {
        $worksheet = $this->worksheet();
        $allocated = $this->engineer('Dean Whitcombe');
        $bystander = $this->engineer('Priya Shah');
        $this->visit($worksheet, [$allocated->id]);

        $response = $this->postJson($this->addUrl($worksheet), [
            'room_name'          => self::ROOMS[0],
            'labour_resource_id' => $bystander->id,
            'qty'                => 1,
            'part_description'   => 'Backbox, 35mm',
        ]);

        $response->assertStatus(422);
        // ⚠️ NO LOOKUP ORACLE. A chatty refusal turns a public token into a
        // staff-directory search box.
        $this->assertStringNotContainsString('Priya Shah', $response->getContent());
        $this->assertSame(0, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    // ── The seven writable-looking audit columns, in ONE request ──────────────

    public function test_every_audit_column_is_server_forced_and_never_read_from_the_body(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->addUrl($worksheet), [
            'room_name'              => self::ROOMS[0],
            'qty'                    => 1,
            'part_description'       => 'Backbox, 35mm',
            // Every one of these is a forgery attempt.
            'created_via'            => 'office',
            'created_by_actor'       => 'ip:1.2.3.4|actor:forged',
            'reconciled_at'          => now()->toIso8601String(),
            'marked_for_deletion_at' => now()->toIso8601String(),
            'deletion_reason'        => 'I said so.',
            'marked_by_actor'        => 'ip:1.2.3.4|actor:forged',
            'amendments'             => [['at' => 'whenever', 'actor' => 'me', 'changes' => []]],
        ])->assertStatus(201);

        $row = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->sole();

        $this->assertSame(WorksheetAdditionalKit::CREATED_VIA_ENGINEER_LINK, $row->created_via);
        $this->assertStringNotContainsString('forged', (string) $row->created_by_actor);
        $this->assertNull($row->reconciled_at);
        $this->assertNull($row->marked_for_deletion_at);
        $this->assertNull($row->deletion_reason);
        $this->assertNull($row->marked_by_actor);
        $this->assertSame([], $row->amendments);
    }

    public function test_the_created_by_actor_stamp_never_contains_the_raw_token(): void
    {
        $worksheet = $this->worksheet();
        $token     = (string) $worksheet->access_token;

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ])->assertStatus(201);

        $row   = WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->sole();
        $stamp = (string) $row->created_by_actor;

        $this->assertMatchesRegularExpression('/^ip:.+\|actor:[0-9a-f]{12}$/', $stamp);
        // Audit M-06: substr($token, 0, 8) once leaked 32 bits of a URL-bearing
        // auth secret into exactly this kind of column.
        $this->assertFalse(
            str_contains($stamp, $token),
            'The raw access token leaked into created_by_actor — this is the M-06 class of defect.',
        );
        $this->assertStringContainsString(substr(hash('sha256', $token), 0, 12), $stamp);
    }

    // ── Validation bounds ────────────────────────────────────────────────────

    public function test_qty_bounds_are_enforced(): void
    {
        $worksheet = $this->worksheet();

        foreach ([0, -1, 1000, '3.5', 'three'] as $bad) {
            $this->postJson($this->addUrl($worksheet), [
                'room_name'        => self::ROOMS[0],
                'qty'              => $bad,
                'part_description' => 'Backbox, 35mm',
            ])->assertStatus(422);
        }

        foreach ([1, 999] as $good) {
            $this->postJson($this->addUrl($worksheet), [
                'room_name'        => self::ROOMS[0],
                'qty'              => $good,
                'part_description' => 'Backbox, 35mm',
            ])->assertStatus(201);
        }

        $this->assertSame(2, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    public function test_a_part_description_over_500_characters_is_refused(): void
    {
        $worksheet = $this->worksheet();

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => str_repeat('a', 501),
        ])->assertStatus(422);

        $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => str_repeat('a', 500),
        ])->assertStatus(201);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  MODIFY (D-08) — an amendment, never an overwrite
    // ═══════════════════════════════════════════════════════════════════════

    public function test_modifying_qty_appends_exactly_one_amendment_entry(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['qty' => 3]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5])
            ->assertOk()
            ->assertJson(['qty' => 5, 'amended' => true]);

        $row->refresh();

        $this->assertSame(5, $row->qty);
        $this->assertCount(1, $row->amendments);
        $this->assertSame(['from' => 3, 'to' => 5], $row->amendments[0]['changes']['qty']);
        $this->assertNotNull($row->amended_at);
        $this->assertTrue($row->isAmended());
    }

    public function test_changing_description_and_engineer_together_appends_ONE_entry_with_BOTH_changes(): void
    {
        $worksheet = $this->worksheet();
        $engineer  = $this->engineer('Dean Whitcombe');
        $this->visit($worksheet, [$engineer->id]);
        $row = $this->row($worksheet, [
            'part_description'   => 'Trunking, 50x50 white',
            'labour_resource_id' => null,
        ]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), [
            'part_description'   => 'Trunking, 75x75 white',
            'labour_resource_id' => $engineer->id,
        ])->assertOk();

        $row->refresh();

        // ONE entry per REQUEST, not one per field.
        $this->assertCount(1, $row->amendments);
        $changes = $row->amendments[0]['changes'];
        $this->assertSame(
            ['from' => 'Trunking, 50x50 white', 'to' => 'Trunking, 75x75 white'],
            $changes['part_description'],
        );
        $this->assertSame(['from' => null, 'to' => $engineer->id], $changes['labour_resource_id']);
    }

    public function test_an_unchanged_field_is_ABSENT_from_the_recorded_changes(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['qty' => 3, 'part_description' => 'Trunking, 50x50 white']);

        $this->postJson($this->modifyUrl($worksheet, $row->id), [
            'qty'              => 5,
            // Posted UNCHANGED, on purpose — this is what a pre-filled drawer
            // submits.
            'part_description' => 'Trunking, 50x50 white',
        ])->assertOk();

        $row->refresh();
        $changes = $row->amendments[0]['changes'];

        $this->assertArrayHasKey('qty', $changes);
        // ⚠️ THE ABSENCE IS THE ASSERTION. A trail of {from: x, to: x} entries
        // is noise the office stops reading, and a trail nobody reads is not an
        // audit trail.
        $this->assertArrayNotHasKey('part_description', $changes);
        $this->assertSame(['qty'], array_keys($changes));
    }

    public function test_a_no_op_modify_is_refused_and_appends_no_entry(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['qty' => 3, 'part_description' => 'Trunking, 50x50 white']);

        $response = $this->postJson($this->modifyUrl($worksheet, $row->id), [
            'qty'              => 3,
            'part_description' => 'Trunking, 50x50 white',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Nothing changed.', $response->getContent());

        $row->refresh();
        // An EMPTY amendment is worse than no amendment.
        $this->assertSame([], $row->amendments);
        $this->assertNull($row->amended_at);
        $this->assertFalse($row->isAmended());
    }

    public function test_the_trail_is_append_only_and_the_first_entry_is_byte_identical_after_a_second_modify(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['qty' => 3]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5])->assertOk();

        $firstEntry     = $row->fresh()->amendments[0];
        $firstEntryJson = json_encode($firstEntry);

        $this->travel(2)->minutes();

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 9])->assertOk();

        $row->refresh();

        $this->assertCount(2, $row->amendments);
        // BYTE-FOR-BYTE. An office that cannot see what a row USED to say
        // cannot reconcile it.
        $this->assertSame($firstEntryJson, json_encode($row->amendments[0]));
        $this->assertSame($firstEntry, $row->amendments[0]);
        // Oldest first, and the second entry starts where the first ended.
        $this->assertSame(['from' => 5, 'to' => 9], $row->amendments[1]['changes']['qty']);
    }

    public function test_the_amendment_actor_is_the_stamp_and_never_the_raw_token(): void
    {
        $worksheet = $this->worksheet();
        $token     = (string) $worksheet->access_token;
        $row       = $this->row($worksheet, ['qty' => 3]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5])->assertOk();

        $entry = $row->fresh()->amendments[0];

        $this->assertMatchesRegularExpression('/^ip:.+\|actor:[0-9a-f]{12}$/', $entry['actor']);
        $this->assertFalse(
            str_contains($entry['actor'], $token),
            'The raw access token leaked into an amendment actor stamp (M-06 class).',
        );
        $this->assertNotEmpty($entry['at']);
    }

    public function test_modifying_with_an_engineer_not_allocated_to_the_visit_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $allocated = $this->engineer('Dean Whitcombe');
        $bystander = $this->engineer('Priya Shah');
        $this->visit($worksheet, [$allocated->id]);
        $row = $this->row($worksheet, ['labour_resource_id' => $allocated->id]);

        $response = $this->postJson($this->modifyUrl($worksheet, $row->id), [
            'labour_resource_id' => $bystander->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Priya Shah', $response->getContent());

        $row->refresh();
        $this->assertSame($allocated->id, $row->labour_resource_id);
        $this->assertSame([], $row->amendments);
    }

    public function test_modify_is_refused_on_a_MARKED_row_and_changes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $row       = WorksheetAdditionalKit::factory()
            ->marked('Ordered twice — only one length fitted.')
            ->create(['worksheet_id' => $worksheet->id, 'room_name' => self::ROOMS[0], 'qty' => 3]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5])
            ->assertStatus(422);

        $row->refresh();
        // ⚠️ THE NO-SIDE-EFFECT HALF. A status-code-only test passes on an
        // endpoint that amends the row and THEN refuses.
        $this->assertSame(3, $row->qty);
        $this->assertSame([], $row->amendments);
        $this->assertNull($row->amended_at);
    }

    public function test_modify_is_refused_on_a_RECONCILED_row_and_changes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $row       = WorksheetAdditionalKit::factory()
            ->reconciled()
            ->create(['worksheet_id' => $worksheet->id, 'room_name' => self::ROOMS[0], 'qty' => 3]);

        $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5])
            ->assertStatus(422);

        $row->refresh();
        $this->assertSame(3, $row->qty);
        $this->assertSame([], $row->amendments);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  MARK FOR DELETION (D-08) — replaces DELETE entirely
    // ═══════════════════════════════════════════════════════════════════════

    public function test_marking_sets_the_flag_the_reason_verbatim_and_the_actor(): void
    {
        $worksheet = $this->worksheet();
        $token     = (string) $worksheet->access_token;
        $row       = $this->row($worksheet);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertOk()->assertJson([
            'id'              => $row->id,
            'marked'          => true,
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ]);

        $row->refresh();

        $this->assertNotNull($row->marked_for_deletion_at);
        $this->assertSame('Ordered twice — only one length fitted.', $row->deletion_reason);
        $this->assertMatchesRegularExpression('/^ip:.+\|actor:[0-9a-f]{12}$/', (string) $row->marked_by_actor);
        $this->assertFalse(str_contains((string) $row->marked_by_actor, $token));
        $this->assertTrue($row->isMarked());
        $this->assertFalse($row->isOpen());
    }

    public function test_a_marked_row_is_STILL_returned_by_the_worksheets_additional_kit_relation(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['part_description' => 'Trunking, 50x50 white']);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertOk();

        // ⚠️ THIS IS THE ASSERTION THAT CATCHES A FUTURE "OPTIMISATION" INTO A
        // DELETE. D-08: nothing is ever hard deleted; the office decides.
        $kit = $worksheet->fresh()->additionalKit;

        $this->assertCount(1, $kit);
        $this->assertSame('Trunking, 50x50 white', $kit->first()->part_description);
        $this->assertTrue($kit->first()->isMarked());
        $this->assertDatabaseHas('worksheet_additional_kit', ['id' => $row->id]);
    }

    public function test_a_missing_reason_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->postJson($this->markUrl($worksheet, $row->id), [])->assertStatus(422);

        $this->assertNull($row->fresh()->marked_for_deletion_at);
    }

    public function test_a_whitespace_only_reason_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => "   \t  ",
        ])->assertStatus(422);

        $row->refresh();
        $this->assertNull($row->marked_for_deletion_at);
        $this->assertNull($row->deletion_reason);
    }

    public function test_a_reason_under_three_characters_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        // `x` is not an explanation, and the office cannot action it.
        $this->postJson($this->markUrl($worksheet, $row->id), ['deletion_reason' => 'x'])
            ->assertStatus(422);
        $this->postJson($this->markUrl($worksheet, $row->id), ['deletion_reason' => 'xy'])
            ->assertStatus(422);

        $this->assertNull($row->fresh()->marked_for_deletion_at);
    }

    public function test_a_reason_over_500_characters_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => str_repeat('a', 501),
        ])->assertStatus(422);

        $this->assertNull($row->fresh()->marked_for_deletion_at);
    }

    public function test_a_second_mark_is_refused_and_leaves_the_first_reason_and_timestamp_untouched(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertOk();

        $row->refresh();
        $firstReason = $row->deletion_reason;
        $firstStamp  = $row->marked_for_deletion_at->toIso8601String();
        $firstActor  = $row->marked_by_actor;

        $this->travel(5)->minutes();

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Actually it was the wrong colour.',
        ])->assertStatus(422);

        $row->refresh();

        // A SECOND MARK MUST NOT OVERWRITE THE FIRST EXPLANATION.
        $this->assertSame($firstReason, $row->deletion_reason);
        $this->assertSame($firstStamp, $row->marked_for_deletion_at->toIso8601String());
        $this->assertSame($firstActor, $row->marked_by_actor);
        $this->assertStringNotContainsString('wrong colour', (string) $row->deletion_reason);
    }

    public function test_marking_a_reconciled_row_is_refused(): void
    {
        $worksheet = $this->worksheet();
        $row       = WorksheetAdditionalKit::factory()
            ->reconciled()
            ->create(['worksheet_id' => $worksheet->id, 'room_name' => self::ROOMS[0]]);

        $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertStatus(422);

        $row->refresh();
        $this->assertNull($row->marked_for_deletion_at);
        $this->assertNull($row->deletion_reason);
    }

    /**
     * ⚠️ THE CHEAPEST PROOF THAT THIS PHASE HAS NO HARD-DELETE PATH.
     *
     * The URI exists (POST modify lives there), so a DELETE against it returns
     * 405 Method Not Allowed rather than 404 — which is a STRONGER statement
     * than a 404 would be: it proves the router knows the URI and has no DELETE
     * verb registered for it. D-08: nothing is ever hard deleted, and there is
     * no unmark either.
     */
    public function test_there_is_no_hard_delete_route(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->delete($this->modifyUrl($worksheet, $row->id))->assertStatus(405);

        $this->assertDatabaseHas('worksheet_additional_kit', ['id' => $row->id]);

        // And no route anywhere in the app hard-deletes a kit row.
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'additional-kit')) {
                $this->assertNotContains(
                    'DELETE',
                    $route->methods(),
                    "A DELETE route was registered on an additional-kit URI ({$route->uri()}) — D-08 forbids it.",
                );
            }
        }
    }

    public function test_there_is_no_unmark_endpoint(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())->filter()->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString('unmark', strtolower($name));
            $this->assertStringNotContainsString('kit.restore', strtolower($name));
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  EVERYWHERE
    // ═══════════════════════════════════════════════════════════════════════

    public function test_a_row_belonging_to_a_different_worksheet_is_404_on_modify(): void
    {
        $mine     = $this->worksheet();
        $theirs   = $this->worksheet();
        $theirRow = $this->row($theirs, ['qty' => 3]);

        $this->postJson($this->modifyUrl($mine, $theirRow->id), ['qty' => 5])
            ->assertStatus(404);

        $this->assertSame(3, $theirRow->fresh()->qty);
    }

    public function test_a_row_belonging_to_a_different_worksheet_is_404_on_mark(): void
    {
        $mine     = $this->worksheet();
        $theirs   = $this->worksheet();
        $theirRow = $this->row($theirs);

        $this->postJson($this->markUrl($mine, $theirRow->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertStatus(404);

        $this->assertNull($theirRow->fresh()->marked_for_deletion_at);
    }

    // ── D-07: the lock, inherited from plan 04 ───────────────────────────────

    public function test_add_is_refused_after_signoff_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $response = $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ]);

        $response->assertStatus(422);
        // Asserted through the DECODED json: the message contains an em dash,
        // which json_encode escapes to — in the raw body. A raw-string
        // assertion would fail on the encoding, not on the behaviour.
        $response->assertJson(['message' => WorksheetCaptureLock::MESSAGE]);
        $this->assertSame(0, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    public function test_modify_is_refused_after_signoff_and_changes_nothing(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['qty' => 3]);
        $this->sign($worksheet);

        $response = $this->postJson($this->modifyUrl($worksheet, $row->id), ['qty' => 5]);

        $response->assertStatus(422);
        // Asserted through the DECODED json: the message contains an em dash,
        // which json_encode escapes to — in the raw body. A raw-string
        // assertion would fail on the encoding, not on the behaviour.
        $response->assertJson(['message' => WorksheetCaptureLock::MESSAGE]);

        $row->refresh();
        $this->assertSame(3, $row->qty);
        $this->assertSame([], $row->amendments);
        $this->assertNull($row->amended_at);
    }

    public function test_mark_is_refused_after_signoff_and_marks_nothing(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);
        $this->sign($worksheet);

        $response = $this->postJson($this->markUrl($worksheet, $row->id), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ]);

        $response->assertStatus(422);
        // Asserted through the DECODED json: the message contains an em dash,
        // which json_encode escapes to — in the raw body. A raw-string
        // assertion would fail on the encoding, not on the behaviour.
        $response->assertJson(['message' => WorksheetCaptureLock::MESSAGE]);

        $row->refresh();
        $this->assertNull($row->marked_for_deletion_at);
        $this->assertNull($row->deletion_reason);
        $this->assertNull($row->marked_by_actor);
    }

    /**
     * The MIRROR. A lock that refuses everything always is an outage, not a
     * lock — plan 04's pattern, kept.
     */
    public function test_all_three_endpoints_still_succeed_on_an_unsigned_worksheet(): void
    {
        $worksheet = $this->worksheet();

        $add = $this->postJson($this->addUrl($worksheet), [
            'room_name'        => self::ROOMS[0],
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ]);
        $add->assertStatus(201);
        $addedId = (int) $add->json('id');

        $this->postJson($this->modifyUrl($worksheet, $addedId), ['qty' => 4])->assertOk();

        $this->postJson($this->markUrl($worksheet, $addedId), [
            'deletion_reason' => 'Ordered twice — only one length fitted.',
        ])->assertOk();
    }

    public function test_the_locked_refusal_precedes_validation(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        // A payload that is ALSO invalid (forged room, qty 0, no description).
        // A locked caller must not learn which field was malformed.
        $response = $this->postJson($this->addUrl($worksheet), [
            'room_name' => 'Not A Real Room',
            'qty'       => 0,
        ]);

        $response->assertStatus(422);
        // Asserted through the DECODED json: the message contains an em dash,
        // which json_encode escapes to — in the raw body. A raw-string
        // assertion would fail on the encoding, not on the behaviour.
        $response->assertJson(['message' => WorksheetCaptureLock::MESSAGE]);
        $this->assertStringNotContainsString('part_description', $response->getContent());
    }

    // ── The throttle key ─────────────────────────────────────────────────────

    public function test_all_three_routes_sit_on_the_per_token_kit_write_limiter(): void
    {
        foreach ([
            'public-worksheet.additional-kit.add',
            'public-worksheet.additional-kit.modify',
            'public-worksheet.additional-kit.mark-deleted',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} is not registered.");
            $this->assertContains(
                'throttle:worksheet-kit-write',
                $route->gatherMiddleware(),
                "Route {$name} is not on the worksheet-kit-write limiter — a leaked token could flood it.",
            );
        }
    }
}

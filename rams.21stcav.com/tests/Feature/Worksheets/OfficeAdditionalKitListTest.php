<?php

namespace Tests\Feature\Worksheets;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 03 Task 2 — THE ACCEPTANCE TEST OF THE WHOLE PHASE.
 *
 * The user, verbatim: additional items must be "presented to the office admin
 * as a clean list rather than free text." The bar is whether somebody can read
 * the kit off this screen and reconcile it against the quote WITHOUT RE-KEYING
 * ANYTHING.
 *
 * ── THREE ROW STATES, AND THE ASSERTIONS ARE PRESENCE-FIRST ─────────────────
 *
 * OPEN       — normal.
 * MARKED     — D-08. The row is PRESENT, flagged IN PLACE, with its reason on
 *              screen. It is NOT gone and it is NOT in a second table.
 *              ⚠️ Every marked-row test asserts the ROW IS THERE before it
 *              asserts the status text. A test that checked only "Marked for
 *              deletion" would pass on a screen that dropped the row.
 * AMENDED    — D-08. Current values, an `Amended` chip, and a trail reading
 *              `qty: 3 → 5`.
 *              ⚠️ Every amended-row test asserts the FROM-VALUES. "Must not
 *              silently show the latest values" IS the requirement; a test
 *              that checked only the badge would pass on a screen with no
 *              trail at all.
 *
 * ── WHAT MUST NEVER RENDER ──────────────────────────────────────────────────
 *
 * `created_by_actor`, `marked_by_actor` and every `amendments[].actor` hold
 * `ip:…|actor:<sha256 slice>`. Same family as
 * `device_label_photos.captured_by`, which once leaked the leading hex of a
 * worksheet UUID token. Every leak assertion here seeds a REALISTIC stamp and
 * is paired with a positive assertion, so it cannot pass vacuously.
 *
 * LR-04: an engineer's email and phone reach nothing. The fixture engineer
 * carries both on purpose.
 *
 * @see resources/views/worksheets/show.blade.php
 * @see app/Http/Controllers/WorksheetController.php (reconcileAdditionalKit)
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-02, D-08, D-09)
 */
class OfficeAdditionalKitListTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_STAMP  = 'ip:198.51.100.9|actor:c0ffee1234567890';
    private const ACTOR_STAMP2 = 'ip:198.51.100.10|actor:abad1dea98765432';

    private ?User $staff = null;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $this->staff = User::factory()->create();
        $project     = Project::factory()->create(['user_id' => $this->staff->id]);

        return Worksheet::factory()->create([
            'user_id'        => $this->staff->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => ['rooms' => []],
        ]);
    }

    /** An engineer who DOES carry an email and a phone — LR-04's live wire. */
    private function engineer(string $name = 'Rachel Okafor'): LabourResource
    {
        return LabourResource::factory()->create([
            'name'  => $name,
            'email' => 'rachel.okafor@example.test',
            'phone' => '07700 900123',
        ]);
    }

    private function row(Worksheet $worksheet, array $attributes = [], array $forced = []): WorksheetAdditionalKit
    {
        $row = WorksheetAdditionalKit::factory()->create(array_merge([
            'worksheet_id'     => $worksheet->id,
            'room_name'        => 'Boardroom',
            'qty'              => 3,
            'part_description' => 'Cat6A U/FTP patch lead, 2m, grey',
            'sort_order'       => 0,
        ], $attributes));

        $row->forceFill(array_merge(['created_by_actor' => self::ACTOR_STAMP], $forced))->save();

        return $row->refresh();
    }

    private function showAs(Worksheet $worksheet): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff ?? User::factory()->create())
            ->get(route('worksheets.show', $worksheet));
    }

    private function reconcile(Worksheet $worksheet, WorksheetAdditionalKit $row): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff ?? User::factory()->create())
            ->post(route('worksheets.additional-kit.reconcile', [
                'worksheet' => $worksheet->id,
                'row'       => $row->id,
            ]));
    }

    // =========================================================================
    // THE PLAIN ROW
    // =========================================================================

    public function test_the_office_reads_a_table_of_kit_rows_with_every_column_it_reconciles_against(): void
    {
        $worksheet = $this->worksheet();
        $engineer  = $this->engineer();

        $this->row($worksheet, [
            'room_name'          => 'Boardroom',
            'labour_resource_id' => $engineer->id,
            'qty'                => 4,
            'part_description'   => 'Cat6A U/FTP patch lead, 2m, grey',
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Additional Kit', false);
        // Every column the office needs in order not to re-key anything.
        $response->assertSee('Room', false);
        $response->assertSee('Engineer', false);
        $response->assertSee('Qty', false);
        $response->assertSee('Part description', false);
        $response->assertSee('Captured', false);
        $response->assertSee('Status', false);
        // And the row itself.
        $response->assertSee('Boardroom', false);
        $response->assertSee('Rachel Okafor', false);
        $response->assertSee('Cat6A U/FTP patch lead, 2m, grey', false);
    }

    public function test_a_row_with_no_allocated_engineer_names_the_gap_instead_of_leaving_a_blank(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['labour_resource_id' => null]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Cat6A U/FTP patch lead, 2m, grey', false);
        $response->assertSee('Unassigned — no engineer allocated to this visit', false);
    }

    public function test_a_worksheet_with_no_kit_renders_a_named_empty_state(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('No additional kit has been recorded', false);
    }

    // =========================================================================
    // THE MARKED ROW (D-08) — PRESENCE FIRST, ALWAYS
    // =========================================================================

    public function test_a_marked_row_is_still_in_the_table_and_carries_its_reason(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['part_description' => 'Trunking, 50x50 white'], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Ordered twice — only one length fitted.',
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $this->assertTrue($row->isMarked());

        $response = $this->showAs($worksheet);

        $response->assertOk();
        // ⚠️ PRESENCE FIRST. The row must still be on the office's list — this
        // is the assertion that catches a screen which filtered marked rows
        // out and then still printed the word "Marked" somewhere.
        $response->assertSee('Trunking, 50x50 white', false);
        $response->assertSee('50', false);
        // THEN the marking.
        $response->assertSee('Marked for deletion', false);
        // AND the reason, verbatim.
        $response->assertSee('Ordered twice — only one length fitted.', false);
    }

    public function test_a_marked_row_is_not_moved_into_a_second_table(): void
    {
        // One list, one quote. A second table is a second place to forget to
        // look. The marked row sits in the SAME table as the open one — proved
        // by there being exactly one kit table on the page.
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['part_description' => 'Open row — HDMI 2.1 lead, 3m', 'sort_order' => 0]);
        $this->row($worksheet, ['part_description' => 'Marked row — Backbox, 35mm', 'sort_order' => 1], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Wrong depth.',
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $content = $this->showAs($worksheet)->assertOk()->getContent();

        $this->assertStringContainsString('Open row — HDMI 2.1 lead, 3m', $content);
        $this->assertStringContainsString('Marked row — Backbox, 35mm', $content);
        $this->assertSame(
            1,
            substr_count($content, 'data-kit-table'),
            'The marked row was split into a second table — the office reconciles ONE list.',
        );
    }

    public function test_a_marked_row_with_no_reason_says_so_rather_than_rendering_a_blank(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['part_description' => 'Legacy row with no reason'], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => null,
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Legacy row with no reason', false);
        $response->assertSee('No reason recorded', false);
    }

    // =========================================================================
    // THE AMENDED ROW (D-08) — THE FROM-VALUES ARE THE REQUIREMENT
    // =========================================================================

    public function test_an_amended_row_shows_current_values_a_chip_and_a_trail_carrying_the_from_values(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, [
            'qty'              => 5,
            'part_description' => 'Trunking, 75x75 white',
        ], [
            'amendments' => [[
                'at'      => now()->subHour()->toIso8601String(),
                'actor'   => self::ACTOR_STAMP2,
                'changes' => [
                    'qty'              => ['from' => 3, 'to' => 5],
                    'part_description' => ['from' => 'Trunking, 50x50 white', 'to' => 'Trunking, 75x75 white'],
                ],
            ]],
            'amended_at' => now()->subHour(),
        ]);

        $this->assertTrue($row->isAmended());

        $response = $this->showAs($worksheet);

        $response->assertOk();
        // The CURRENT values.
        $response->assertSee('Trunking, 75x75 white', false);
        // The chip.
        $response->assertSee('Amended', false);
        // ⚠️ THE FROM-VALUES. Without these the screen is silently showing the
        // latest values, which is the exact failure D-08 exists to prevent.
        // 'Trunking, 50x50 white' exists NOWHERE on this row's current state —
        // if it renders, it came from the trail.
        $response->assertSee('Trunking, 50x50 white', false);
        $response->assertSee('qty: 3 → 5', false);
    }

    public function test_the_amendment_trail_is_collapsed_by_default_and_uses_no_javascript(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, [], [
            'amendments' => [[
                'at'      => now()->toIso8601String(),
                'actor'   => self::ACTOR_STAMP2,
                'changes' => ['qty' => ['from' => 1, 'to' => 3]],
            ]],
            'amended_at' => now(),
        ]);

        $content = $this->showAs($worksheet)->assertOk()->getContent();

        // <details> — the page's own no-JS idiom. Alpine is not loaded on the
        // engineer link where plan 05 renders the same rows.
        $this->assertStringContainsString('<details', $content);
        // Collapsed: no `open` attribute on the kit trail.
        $this->assertStringNotContainsString('<details open', $content);
    }

    public function test_the_trail_renders_every_entry_oldest_first(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['qty' => 9], [
            'amendments' => [
                [
                    'at'      => now()->subDays(2)->toIso8601String(),
                    'actor'   => self::ACTOR_STAMP2,
                    'changes' => ['qty' => ['from' => 1, 'to' => 4]],
                ],
                [
                    'at'      => now()->subDay()->toIso8601String(),
                    'actor'   => self::ACTOR_STAMP2,
                    'changes' => ['qty' => ['from' => 4, 'to' => 9]],
                ],
            ],
            'amended_at' => now()->subDay(),
        ]);

        $content = $this->showAs($worksheet)->assertOk()->getContent();

        $first  = strpos($content, 'qty: 1 → 4');
        $second = strpos($content, 'qty: 4 → 9');

        $this->assertNotFalse($first, 'The oldest amendment is missing from the trail.');
        $this->assertNotFalse($second, 'The newest amendment is missing from the trail.');
        $this->assertLessThan($second, $first, 'The trail is not oldest-first.');
    }

    public function test_a_row_that_is_both_amended_and_marked_shows_both(): void
    {
        // The likeliest real combination: the engineer fixes a qty, then
        // decides the item comes off altogether.
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['qty' => 6, 'part_description' => 'Backbox, 47mm'], [
            'amendments' => [[
                'at'      => now()->subHour()->toIso8601String(),
                'actor'   => self::ACTOR_STAMP2,
                'changes' => ['qty' => ['from' => 2, 'to' => 6]],
            ]],
            'amended_at'             => now()->subHour(),
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Supplied by the client in the end.',
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Backbox, 47mm', false);           // presence first
        $response->assertSee('Marked for deletion', false);
        $response->assertSee('Supplied by the client in the end.', false);
        $response->assertSee('Amended', false);
        $response->assertSee('qty: 2 → 6', false);              // the from-value
    }

    // =========================================================================
    // RECONCILE
    // =========================================================================

    public function test_reconciling_an_open_row_stamps_it_and_the_row_then_reads_as_reconciled(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->assertNull($row->reconciled_at);

        $this->reconcile($worksheet, $row)->assertRedirect();

        $this->assertNotNull($row->refresh()->reconciled_at);
        $this->showAs($worksheet)->assertOk()->assertSee('Reconciled', false);
    }

    public function test_reconciling_twice_is_idempotent(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->reconcile($worksheet, $row)->assertRedirect();
        $first = $row->refresh()->reconciled_at;

        $this->travel(2)->minutes();

        $this->reconcile($worksheet, $row)->assertRedirect();

        $this->assertTrue(
            $first->equalTo($row->refresh()->reconciled_at),
            'A second reconcile moved the timestamp — reconcile must be idempotent.',
        );
    }

    public function test_a_marked_row_CAN_be_reconciled(): void
    {
        // ⚠️ Refusing here would leave every marked row outstanding on the
        // office's list forever — the exact "can't action it" failure D-08
        // exists to prevent. Reconcile means THE OFFICE HAS ACTIONED THIS ROW,
        // whatever state it is in.
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet, ['part_description' => 'Marked but actionable'], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Returned to stores.',
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $this->reconcile($worksheet, $row)->assertRedirect();

        $row->refresh();
        $this->assertNotNull($row->reconciled_at, 'A marked row could not be reconciled.');
        $this->assertTrue($row->isMarked(), 'Reconciling silently unmarked the row.');

        // And it is still on the list, still marked, still carrying its reason.
        $response = $this->showAs($worksheet);
        $response->assertSee('Marked but actionable', false);
        $response->assertSee('Marked for deletion', false);
        $response->assertSee('Returned to stores.', false);
    }

    public function test_the_reconcile_control_says_out_loud_what_it_costs_the_engineer(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('the engineer can no longer amend or mark it', false);
    }

    public function test_reconcile_refuses_a_row_belonging_to_another_worksheet(): void
    {
        $worksheet = $this->worksheet();
        $other     = Worksheet::factory()->create([
            'user_id'    => $this->staff->id,
            'project_id' => $worksheet->project_id,
        ]);
        $foreignRow = $this->row($other);

        $this->actingAs($this->staff)
            ->post(route('worksheets.additional-kit.reconcile', [
                'worksheet' => $worksheet->id,
                'row'       => $foreignRow->id,
            ]))
            ->assertNotFound();

        $this->assertNull($foreignRow->refresh()->reconciled_at);
    }

    public function test_an_unauthenticated_user_cannot_reconcile(): void
    {
        $worksheet = $this->worksheet();
        $row       = $this->row($worksheet);

        $this->post(route('worksheets.additional-kit.reconcile', [
            'worksheet' => $worksheet->id,
            'row'       => $row->id,
        ]))->assertRedirect(route('login'));

        $this->assertNull($row->refresh()->reconciled_at);
    }

    // =========================================================================
    // ESCAPING AND LEAKS
    // =========================================================================

    public function test_a_hostile_part_description_is_escaped(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['part_description' => '<script>alert(1)</script>']);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        // Non-vacuity on the ESCAPED form — the value really did render.
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_hostile_deletion_reason_is_escaped(): void
    {
        // ⚠️ `deletion_reason` is NEW ENGINEER FREE TEXT and exactly as
        // dangerous as the part description. It is not metadata.
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['part_description' => 'Row with a hostile reason'], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => '<script>alert(2)</script>',
            'marked_by_actor'        => self::ACTOR_STAMP2,
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Row with a hostile reason', false);   // presence first
        $response->assertDontSee('<script>alert(2)</script>', false);
        $response->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
    }

    public function test_no_actor_stamp_reaches_the_office_table(): void
    {
        $worksheet = $this->worksheet();
        $this->row($worksheet, ['part_description' => 'Row carrying three actor stamps'], [
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Not needed.',
            'marked_by_actor'        => self::ACTOR_STAMP2,
            'amendments'             => [[
                'at'      => now()->toIso8601String(),
                'actor'   => self::ACTOR_STAMP2,
                'changes' => ['qty' => ['from' => 1, 'to' => 2]],
            ]],
            'amended_at' => now(),
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        // NON-VACUITY FIRST: the row, the reason and the trail all rendered,
        // so there genuinely was something to leak on every path.
        $response->assertSee('Row carrying three actor stamps', false);
        $response->assertSee('Not needed.', false);
        $response->assertSee('qty: 1 → 2', false);

        $response->assertDontSee(self::ACTOR_STAMP, false);
        $response->assertDontSee(self::ACTOR_STAMP2, false);
        $response->assertDontSee('c0ffee1234567890', false);
        $response->assertDontSee('abad1dea98765432', false);
        $response->assertDontSee('198.51.100.9', false);
        $response->assertDontSee('198.51.100.10', false);
    }

    public function test_the_worksheet_links_to_the_projects_asset_list(): void
    {
        // Task 3 — the two install-capture artefacts are reachable from one
        // page. The office should not have to know a URL.
        $worksheet = $this->worksheet();

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Asset list', false);
        $response->assertSee(route('projects.asset-list', $worksheet->project), false);
    }

    public function test_an_engineers_email_and_phone_reach_nothing(): void
    {
        // LR-04. The same code path feeds the page a CLIENT reads (D-01).
        $worksheet = $this->worksheet();
        $engineer  = $this->engineer();
        $this->row($worksheet, ['labour_resource_id' => $engineer->id]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Rachel Okafor', false);               // non-vacuity
        $response->assertDontSee('rachel.okafor@example.test', false);
        $response->assertDontSee('07700 900123', false);
    }
}

<?php

namespace Tests\Feature\Worksheets;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 01 Task 2 — the additional-kit row.
 *
 * Today the only place extra kit can land in RAMS is
 * `worksheet_signoffs.comments`, a max:5000 textarea — or a phone call. This
 * is the first structured field for it, and the rulings it holds are the ones
 * the rest of the phase branches on:
 *
 *   D-08  add, modify and MARK FOR DELETION WITH A REASON. NOTHING IS EVER
 *         HARD DELETED. A marked row stays in the table, flagged, and the
 *         office decides.
 *   D-10  NO unit field. Put to the user (each / metres / boxes) and declined:
 *         "3.no." qty and part description only.
 *
 * @see app/Models/WorksheetAdditionalKit.php
 * @see database/migrations/2026_09_26_100100_create_worksheet_additional_kit_table.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-06, D-08, D-09, D-10)
 */
class WorksheetAdditionalKitModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every column on the table that an engineer's request must NOT be able to
     * set. Eight of them. Asserted as ONE set in ONE case, because a
     * per-column test is a test somebody forgets to extend.
     */
    private const AUDIT_COLUMNS = [
        'created_via',
        'created_by_actor',
        'reconciled_at',
        'marked_for_deletion_at',
        'deletion_reason',
        'marked_by_actor',
        'amendments',
        'amended_at',
    ];

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
        ]);
    }

    // ── The round trip ───────────────────────────────────────────────────────

    public function test_a_kit_row_persists_and_reads_back_identically(): void
    {
        $worksheet = $this->worksheet();
        $engineer = LabourResource::factory()->create(['name' => 'Sean Pearce']);

        $row = WorksheetAdditionalKit::create([
            'worksheet_id'       => $worksheet->id,
            'room_name'          => 'Boardroom',
            'labour_resource_id' => $engineer->id,
            'qty'                => 12,
            'part_description'   => 'Cat6A U/FTP patch lead, 2m, grey',
            'sort_order'         => 3,
        ])->fresh();

        $this->assertSame($worksheet->id, $row->worksheet_id);
        $this->assertSame('Boardroom', $row->room_name);
        $this->assertSame($engineer->id, $row->labour_resource_id);
        $this->assertSame(12, $row->qty);
        $this->assertSame('Cat6A U/FTP patch lead, 2m, grey', $row->part_description);
        $this->assertSame(3, $row->sort_order);
    }

    /**
     * D-02's fallback stores NULL. There is no free-text engineer field
     * anywhere in this phase, so a null can never become a spelling variant.
     */
    public function test_a_kit_row_survives_a_round_trip_with_no_engineer(): void
    {
        $row = WorksheetAdditionalKit::create([
            'worksheet_id'       => $this->worksheet()->id,
            'room_name'          => 'Reception',
            'labour_resource_id' => null,
            'qty'                => 1,
            'part_description'   => '3m HDMI 2.1 lead',
        ])->fresh();

        $this->assertNull($row->labour_resource_id);
        $this->assertNull($row->labourResource);
    }

    public function test_a_deleted_labour_resource_nulls_the_row_rather_than_removing_it(): void
    {
        $engineer = LabourResource::factory()->create();

        $row = WorksheetAdditionalKit::create([
            'worksheet_id'       => $this->worksheet()->id,
            'room_name'          => 'Reception',
            'labour_resource_id' => $engineer->id,
            'qty'                => 1,
            'part_description'   => '3m HDMI 2.1 lead',
        ]);

        $engineer->delete();

        $this->assertNotNull($row->fresh(), 'The kit row must outlive the engineer record.');
        $this->assertNull($row->fresh()->labour_resource_id);
    }

    // ── Mass-assignment: eight columns, one create(), one assertion set ──────

    /**
     * T-46.4-01-02. A single create() carrying ALL EIGHT audit columns leaves
     * every one of them at its default.
     *
     * `created_by_actor`, `marked_by_actor` and every `amendments[].actor`
     * carry the `ip:…|actor:<sha256 slice>` stamp. `device_label_photos
     * .captured_by` once leaked a UUID token fragment into exactly this kind
     * of column and needed a migration to null every legacy value. A public
     * token is the only credential on this page, so a payload that could set
     * these is a payload that could forge them.
     */
    public function test_all_eight_audit_columns_are_not_mass_assignable(): void
    {
        $row = WorksheetAdditionalKit::create([
            // The six that ARE fillable.
            'worksheet_id'           => $this->worksheet()->id,
            'room_name'              => 'Boardroom',
            'labour_resource_id'     => null,
            'qty'                    => 2,
            'part_description'       => 'Trunking, 50x50 white',
            'sort_order'             => 0,
            // The eight that are NOT. Every one of these is a forgery attempt.
            'created_via'            => 'office',
            'created_by_actor'       => 'ip:1.2.3.4|actor:deadbeefdeadbeef',
            'reconciled_at'          => now(),
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'forged',
            'marked_by_actor'        => 'ip:1.2.3.4|actor:deadbeefdeadbeef',
            'amendments'             => [['at' => 'forged', 'actor' => 'forged', 'changes' => []]],
            'amended_at'             => now(),
        ])->fresh();

        $this->assertSame(
            WorksheetAdditionalKit::CREATED_VIA_ENGINEER_LINK,
            $row->created_via,
            'created_via must fall to its default, never to a caller-supplied value.'
        );
        $this->assertNull($row->created_by_actor);
        $this->assertNull($row->reconciled_at);
        $this->assertNull($row->marked_for_deletion_at);
        $this->assertNull($row->deletion_reason);
        $this->assertNull($row->marked_by_actor);
        $this->assertSame([], $row->amendments);
        $this->assertNull($row->amended_at);

        // And the guard itself, stated once so a future column cannot be added
        // to $fillable by accident without this line going red.
        foreach (self::AUDIT_COLUMNS as $column) {
            $this->assertNotContains(
                $column,
                (new WorksheetAdditionalKit)->getFillable(),
                "{$column} is an audit column and must never be mass-assignable."
            );
        }
    }

    public function test_exactly_six_columns_are_fillable(): void
    {
        $this->assertSame(
            [
                'worksheet_id',
                'room_name',
                'labour_resource_id',
                'qty',
                'part_description',
                'sort_order',
            ],
            (new WorksheetAdditionalKit)->getFillable()
        );
    }

    // ── D-10: there is no unit field, and there is not going to be one ───────

    public function test_there_is_no_unit_field_anywhere_on_a_kit_row(): void
    {
        $columns = Schema::getColumnListing('worksheet_additional_kit');

        foreach (['unit', 'units', 'uom', 'unit_of_measure'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                'D-10: a unit field (each / metres / boxes) was put to the user and DECLINED — "3.no." '
                . 'Adding one helpfully, even as a nullable placeholder "for later", is the exact '
                . 'failure this assertion exists to catch.'
            );
        }
    }

    // ── amendments: a list, always ───────────────────────────────────────────

    public function test_amendments_casts_to_an_array_and_is_never_null(): void
    {
        $row = WorksheetAdditionalKit::create([
            'worksheet_id'     => $this->worksheet()->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ]);

        $this->assertSame([], $row->amendments);
        $this->assertSame([], $row->fresh()->amendments);

        // Even a row written straight through the query builder with a literal
        // NULL reads as a list — plan 03's Blade foreaches this without a
        // guard, and plan 05 appends to it without a guard.
        DB::table('worksheet_additional_kit')->where('id', $row->id)->update(['amendments' => null]);

        $this->assertSame([], $row->fresh()->amendments);
    }

    public function test_an_appended_amendment_reads_back_in_shape(): void
    {
        $row = WorksheetAdditionalKit::create([
            'worksheet_id'     => $this->worksheet()->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ]);

        // The write path plan 05 owns: forceFill, never a fillable update.
        $row->forceFill([
            'amendments' => [[
                'at'      => '2026-09-26T10:00:00+00:00',
                'actor'   => 'ip:1.2.3.4|actor:abcd1234',
                'changes' => ['qty' => ['from' => 1, 'to' => 4]],
            ]],
            'amended_at' => now(),
        ])->save();

        $fresh = $row->fresh();

        $this->assertCount(1, $fresh->amendments);
        $this->assertSame(['from' => 1, 'to' => 4], $fresh->amendments[0]['changes']['qty']);
        $this->assertTrue($fresh->isAmended());
    }

    // ── The three state helpers plans 03 and 05 branch on ────────────────────

    public function test_the_three_state_helpers_agree_on_one_definition(): void
    {
        $open = WorksheetAdditionalKit::create([
            'worksheet_id'     => $this->worksheet()->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Open row',
        ]);

        $this->assertFalse($open->isMarked());
        $this->assertFalse($open->isAmended());
        $this->assertTrue($open->isOpen());

        $marked = WorksheetAdditionalKit::create([
            'worksheet_id'     => $this->worksheet()->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Marked row',
        ]);
        $marked->forceFill([
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Ordered twice — only one fitted.',
            'marked_by_actor'        => 'ip:1.2.3.4|actor:abcd1234',
        ])->save();

        $this->assertTrue($marked->fresh()->isMarked());
        $this->assertFalse($marked->fresh()->isOpen());

        $reconciled = WorksheetAdditionalKit::create([
            'worksheet_id'     => $this->worksheet()->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Reconciled row',
        ]);
        $reconciled->forceFill(['reconciled_at' => now()])->save();

        $this->assertFalse($reconciled->fresh()->isMarked());
        $this->assertFalse(
            $reconciled->fresh()->isOpen(),
            'Reconciled is the office having dealt with it — D-09: "own" means added via the '
            . 'engineer link AND not yet reconciled.'
        );
    }

    // ── D-08: marking is a flag, not a disappearance ─────────────────────────

    /**
     * ⚠️ This asserts the row is PRESENT **and** FLAGGED. An assertion that
     * only counted rows would pass if marking silently deleted and something
     * else inserted a replacement.
     */
    public function test_a_marked_row_is_still_returned_and_is_flagged(): void
    {
        $worksheet = $this->worksheet();

        $marked = WorksheetAdditionalKit::create([
            'worksheet_id'     => $worksheet->id,
            'room_name'        => 'Boardroom',
            'qty'              => 4,
            'part_description' => 'Cat6A patch lead, 1m',
        ]);
        $marked->forceFill([
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Fitted in the wrong room — moved to Reception.',
            'marked_by_actor'        => 'ip:1.2.3.4|actor:abcd1234',
        ])->save();

        $returned = $worksheet->fresh()->additionalKit;

        $this->assertTrue(
            $returned->contains(fn (WorksheetAdditionalKit $r): bool => $r->is($marked)),
            'A marked row must still reach the office. Marking is an INSTRUCTION to the office, '
            . 'not a delete — the office decides what to do with it.'
        );
        $this->assertTrue($returned->firstWhere('id', $marked->id)->isMarked());
        $this->assertSame(
            'Fitted in the wrong room — moved to Reception.',
            $returned->firstWhere('id', $marked->id)->deletion_reason
        );
    }

    public function test_the_model_offers_no_soft_delete_trait_standing_in_for_the_mark(): void
    {
        $this->assertNotContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(WorksheetAdditionalKit::class),
            'D-08: no soft-delete trait standing in for the mark. A soft-deleted row would vanish '
            . 'from every default query, which is exactly the behaviour the mark exists to avoid.'
        );

        $this->assertNotContains('deleted_at', Schema::getColumnListing('worksheet_additional_kit'));
    }

    // ── Ordering and cascade ─────────────────────────────────────────────────

    public function test_additional_kit_orders_by_room_then_sort_order_then_id(): void
    {
        $worksheet = $this->worksheet();

        $make = function (string $room, int $sort, string $desc) use ($worksheet): WorksheetAdditionalKit {
            return WorksheetAdditionalKit::create([
                'worksheet_id'     => $worksheet->id,
                'room_name'        => $room,
                'qty'              => 1,
                'part_description' => $desc,
                'sort_order'       => $sort,
            ]);
        };

        $make('Reception', 0, 'r-first');
        $make('Boardroom', 5, 'b-second');
        $make('Boardroom', 1, 'b-first');
        $make('Boardroom', 5, 'b-third');

        $this->assertSame(
            ['b-first', 'b-second', 'b-third', 'r-first'],
            $worksheet->fresh()->additionalKit->pluck('part_description')->all()
        );
    }

    public function test_deleting_a_worksheet_cascades_its_kit_rows(): void
    {
        $worksheet = $this->worksheet();

        WorksheetAdditionalKit::create([
            'worksheet_id'     => $worksheet->id,
            'room_name'        => 'Boardroom',
            'qty'              => 1,
            'part_description' => 'Backbox, 35mm',
        ]);

        // Worksheet uses SoftDeletes, so a plain delete() leaves the row
        // reachable on purpose. The database cascade fires on a hard delete.
        $worksheet->forceDelete();

        $this->assertSame(0, WorksheetAdditionalKit::where('worksheet_id', $worksheet->id)->count());
    }

    // ── The rulings are written where the next implementer will read them ────

    public function test_the_mark_and_amendment_rulings_are_in_the_migration_docblock(): void
    {
        $source = file_get_contents(base_path(
            'database/migrations/2026_09_26_100100_create_worksheet_additional_kit_table.php'
        ));

        $this->assertStringContainsString('worksheet_additional_kit_events', $source,
            'The REJECTED alternative — an events table — must be named, with the trigger that '
            . 'would promote the JSON column to rows.');
        $this->assertStringContainsString('deletion_reason', $source);
        $this->assertStringContainsString('never hard deleted', strtolower($source));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 46.4 Plan 01 Task 2 — one row of extra kit an engineer used on site.
 *
 * "additional items are separated out so they can be presented to the office
 * admin as a clean list rather than free text." — the user, verbatim.
 *
 * ── THE SHAPE, IN ONE LINE ──────────────────────────────────────────────────
 *
 *   room · engineer (or nobody) · qty · part description
 *
 * AND NOTHING ELSE. D-10: a unit field (each / metres / boxes) was put to the
 * user and DECLINED — "3.no." Do not add one, not even as a nullable
 * placeholder "for later". Claude's-discretion ruling of the same day: a kit
 * row takes NO PHOTO either — "dont need kit pics, on serial cpature." A kit
 * row is text and numbers, has no blob at all, and that is what keeps D-06's
 * offline queueing a clean branch rather than an optional-blob special case.
 *
 * ── SIX COLUMNS FILLABLE, EIGHT DELIBERATELY NOT ────────────────────────────
 *
 * The public token is the only credential on the engineer link. There is no
 * login and no session identity, so a request body that could set an audit
 * column is a request body that could FORGE one.
 *
 * NOT fillable, and each for a reason:
 *   created_via             provenance — a payload must not claim to be the office
 *   created_by_actor        the actor stamp
 *   reconciled_at           the OFFICE's act, never the engineer's
 *   marked_for_deletion_at  D-08 — set only with a reason, by the plan-05 endpoint
 *   deletion_reason         moves with the mark; never independently
 *   marked_by_actor         the actor stamp
 *   amendments              append-only; an update payload must not REPLACE the trail
 *   amended_at              moves with the trail
 *
 * This mirrors `Worksheet`'s own deliberate omissions from the security
 * re-audit (`access_token`, `access_token_expires_at`,
 * `submitted_notification_sent_at`) — never mass-assign a token.
 *
 * ⚠️ `created_by_actor`, `marked_by_actor` AND EVERY `amendments[].actor` HOLD
 * THE `ip:…|actor:<sha256 slice>` STAMP AND ARE NEVER RENDERED. Same rule as
 * `device_label_photos.captured_by`, which once leaked a UUID token fragment
 * into exactly this kind of column and needed a migration to null every legacy
 * value. A new "engineer" field must not become a second place the token
 * leaks. The engineer's NAME comes from `AllocatedEngineers`, never from here.
 *
 * ── D-08: NOTHING IS EVER HARD DELETED ──────────────────────────────────────
 *
 * There is no `delete()` helper here, no soft-delete trait, and no `unmark`.
 * A marked row STAYS, flagged, with its reason, and the office decides. The
 * absence of a way back is a KNOWN GAP, recorded in 46.4-01-SUMMARY.md rather
 * than solved by inventing an endpoint the user did not ask for.
 *
 * ── THE THREE STATE HELPERS ─────────────────────────────────────────────────
 *
 * `isMarked()`, `isAmended()` and `isOpen()` exist so plans 03 and 05 branch
 * on ONE definition instead of three that drift apart.
 *
 * @see database/migrations/2026_09_26_100100_create_worksheet_additional_kit_table.php
 * @see app/Support/Worksheets/AllocatedEngineers.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-06, D-08, D-09, D-10)
 *
 * @property int $id
 * @property int $worksheet_id
 * @property string $room_name
 * @property ?int $labour_resource_id
 * @property int $qty
 * @property string $part_description
 * @property string $created_via
 * @property ?string $created_by_actor       NEVER RENDERED — actor stamp
 * @property ?\Illuminate\Support\Carbon $reconciled_at
 * @property int $sort_order
 * @property ?\Illuminate\Support\Carbon $marked_for_deletion_at
 * @property ?string $deletion_reason
 * @property ?string $marked_by_actor        NEVER RENDERED — actor stamp
 * @property array $amendments               entries carry an `actor` key — NEVER RENDERED
 * @property ?\Illuminate\Support\Carbon $amended_at
 */
class WorksheetAdditionalKit extends Model
{
    use HasFactory;

    /** Singular table name — this is a LIST OF KIT, not a list of kits. */
    protected $table = 'worksheet_additional_kit';

    // ── Provenance ───────────────────────────────────────────────────────────

    public const CREATED_VIA_ENGINEER_LINK = 'engineer_link';

    // ── Mass assignment: SIX columns, and the eight omissions are the point ──

    protected $fillable = [
        'worksheet_id',
        'room_name',
        'labour_resource_id',
        'qty',
        'part_description',
        'sort_order',
    ];

    /**
     * `amendments` starts as an empty LIST, never NULL, so plan 03's Blade can
     * foreach it and plan 05 can append to it without either guarding first.
     */
    protected $attributes = [
        'amendments' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'qty'                    => 'integer',
            'sort_order'             => 'integer',
            'reconciled_at'          => 'datetime',
            'marked_for_deletion_at' => 'datetime',
            'amended_at'             => 'datetime',
            // `amendments` is deliberately NOT cast here — see the accessor
            // below, which is strictly stronger than an `array` cast because
            // it cannot return NULL for a legacy or raw-inserted row.
        ];
    }

    // ── amendments: an append-only trail that always reads as a list ─────────

    /**
     * Not `'array'` in $casts: that cast returns NULL for a NULL column, and
     * every reader downstream would then need its own `?? []`. This returns a
     * list unconditionally — including for a row written straight through the
     * query builder.
     *
     * ⚠️ APPEND ONLY. Plan 05 reads, pushes one entry, and writes back via
     * forceFill(). It never rewrites or removes an existing entry: an office
     * that cannot see what a row USED to say cannot reconcile it.
     *
     * @return Attribute<array<int,array<string,mixed>>, string>
     */
    protected function amendments(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: fn (?array $value): string => json_encode(array_values($value ?? [])),
        );
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    /**
     * ⚠️ The related model carries `email` AND `phone`. This relation exists
     * for the OFFICE side and for referential work. ANY CLIENT-FACING NAME
     * MUST COME THROUGH `AllocatedEngineers`, or at the very least through
     * `LabourResource::toClientSafeArray()` — never by serialising this
     * relation (D-01 made the engineer link client-visible; LR-04 says name
     * only).
     */
    public function labourResource(): BelongsTo
    {
        return $this->belongsTo(LabourResource::class);
    }

    // ── State (ONE definition each — plans 03 and 05 never re-derive these) ──

    /**
     * The engineer has asked the office to take this row off. The row is still
     * here, and it still reaches the office's list.
     */
    public function isMarked(): bool
    {
        return $this->marked_for_deletion_at !== null;
    }

    /** The row has been changed since it was added, and the trail says how. */
    public function isAmended(): bool
    {
        return $this->amendments !== [];
    }

    /**
     * Neither marked nor reconciled — the office still has something to do
     * with it, and (D-09) the engineer link may still modify or mark it.
     */
    public function isOpen(): bool
    {
        return ! $this->isMarked() && $this->reconciled_at === null;
    }
}

<?php

namespace App\Support\Worksheets;

use App\Models\LabourResource;
use App\Models\Visit;
use App\Models\Worksheet;

/**
 * Phase 46.4 Plan 01 Task 3 — THE ONLY DOOR AN ENGINEER'S NAME COMES THROUGH
 * IN THIS PHASE.
 *
 * ── WHY THIS CLASS EXISTS AT ALL ────────────────────────────────────────────
 *
 * D-02: the engineer is NOT TYPED. "engineer will be allocated when visit is
 * booked" — the user, verbatim. `Visit::$labour_resource_ids` already holds
 * them, and the visit reaches the worksheet by `source_type` / `source_id`.
 * Resolving rather than typing kills the "same name typed thirty times"
 * problem and the spelling drift that would wreck the office's list.
 *
 * ── WHY IT IS A CHOKEPOINT AND NOT A HELPER ─────────────────────────────────
 *
 * D-01 keeps engineer and client on ONE page, one URL — so the CLIENT reads a
 * page that names engineers. `LabourResource` carries `name`, `email` AND
 * `phone`. LR-04 says a client gets a NAME AND NOTHING ELSE.
 *
 * Two mechanisms enforce that here, and BOTH are load-bearing:
 *
 *   1. `->select(['id', 'name'])` — the hydrated models NEVER HOLD an email or
 *      a phone. A future `->toArray()`, a Blade `@json`, a `dd()`, a
 *      serialised job payload: none of them can reach a column that was never
 *      fetched. This is the one that makes a leak PHYSICALLY IMPOSSIBLE rather
 *      than merely absent.
 *
 *   2. The return is a PLAIN ARRAY of plain arrays, built by
 *      `LabourResource::toClientSafeArray()` — not a model, not a collection.
 *      Nothing downstream can lazily reach back through a model for a hidden
 *      attribute, because there is no model downstream.
 *
 * ⚠️ DO NOT "helpfully" widen the select, return the models, or add an
 * `email`/`phone` to the projection for an office screen. An office screen
 * that needs contact details queries `LabourResource` itself, on an
 * authenticated route. This class serves a page with no login on it.
 *
 * ── THE FALLBACKS, ALL RULED (D-02) ─────────────────────────────────────────
 *
 * The engineer link is the one surface in this app used by somebody standing
 * in a plant room with bad signal. NONE of these may throw:
 *
 *   no visit at all               -> []
 *   visit with an empty/NULL list -> []
 *   an id whose resource is gone  -> the survivors, silently
 *
 * An empty result renders as `Unassigned — no engineer allocated to this
 * visit`. Because THERE IS NO FREE-TEXT ENGINEER FIELD ANYWHERE IN THIS
 * PHASE, an unresolved engineer can never turn into a spelling variant.
 *
 * ⚠️ `scopeActive()` IS DELIBERATELY NOT APPLIED. An engineer deactivated
 * after the visit must still appear on it — LR-02, deactivation preserves
 * history. Filtering to active belongs on the ALLOCATION form, not on this
 * read.
 *
 * @see app/Models/Visit.php (labour_resource_ids :180, source_type/source_id :67-68)
 * @see app/Models/LabourResource.php::toClientSafeArray (the D-04 shape)
 * @see tests/Unit/Support/AllocatedEngineersTest.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-01, D-02)
 */
class AllocatedEngineers
{
    /**
     * The engineers allocated to this worksheet's visit, in the order the
     * visit lists them.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function forWorksheet(Worksheet $worksheet): array
    {
        $visit = Visit::query()
            ->where('source_type', Visit::SOURCE_WORKSHEET)
            ->where('source_id', $worksheet->id)
            ->orderBy('id')
            ->first();

        if ($visit === null) {
            return [];
        }

        $ids = self::normaliseIds($visit->labour_resource_ids);

        if ($ids === []) {
            return [];
        }

        // ⚠️ THE SELECT IS LOAD-BEARING. See the class docblock. Widening it
        // puts an engineer's email one `->toArray()` away from a page a client
        // reads.
        $byId = LabourResource::query()
            ->select(['id', 'name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $engineers = [];

        foreach ($ids as $id) {
            $resource = $byId->get($id);

            // A resource that no longer exists is a GAP IN THE LIST, never an
            // exception. The visit outlives the people on it.
            if ($resource === null) {
                continue;
            }

            // toClientSafeArray() only ever builds `id` and `name` — there is
            // nothing to forget to strip.
            $engineers[] = $resource->toClientSafeArray();
        }

        return $engineers;
    }

    /**
     * `labour_resource_ids` is an array cast on a nullable column, so NULL, a
     * non-list, and string ids are all reachable on a real row. Duplicates are
     * collapsed while preserving first-seen order — a visit listing the same
     * person twice must not offer them twice.
     *
     * @return array<int, int>
     */
    private static function normaliseIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

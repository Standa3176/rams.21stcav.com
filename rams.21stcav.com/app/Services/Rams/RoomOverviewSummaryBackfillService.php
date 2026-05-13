<?php

namespace App\Services\Rams;

/**
 * Phase 22.1 D-07 — pure-function backfill resolver for the one-off
 * `summary → works_summary` migration on `reviewed_data.room_overviews[*]`.
 *
 * Mirrors the Phase 22 CablePortFkResolverService pattern: pure (no DB
 * writes, no Eloquent calls), accepts one input row, returns a decision
 * dict. The artisan command BackfillRoomOverviewSummaryCommand applies the
 * decisions inside a DB::transaction.
 *
 * 4 outcome categories (per CONTEXT.md D-07 + Plan 03 §"specifics"):
 *   - 'backfilled'          : works_summary empty, summary non-empty → copy
 *                             summary into works_summary; legacy summary
 *                             field is left in place verbatim (schema-trim
 *                             happens in Plan 06 AFTER this command has run)
 *   - 'already-set'         : works_summary non-empty, summary empty → no-op
 *   - 'both-set-no-action'  : both non-empty → no overwrite (preserves both
 *                             verbatim; caller logs a warning so a human can
 *                             reconcile the divergence)
 *   - 'neither-set'         : both empty → no-op
 *
 * Idempotency property: after a 'backfilled' run, the row now has both
 * works_summary AND summary populated (same value). Re-running on the same
 * row lands in 'both-set-no-action', NOT 'already-set' — this is the
 * correct semantics: the legacy summary field is intentionally preserved
 * so the schema-trim step in Plan 06 has a clean before/after to test
 * against. Both fields collapse to one canonical key only after Plan 06.
 *
 * @see app/Services/Cable/CablePortFkResolverService.php (Phase 22 template)
 * @see app/Console/Commands/BackfillRoomOverviewSummaryCommand.php (consumer)
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-07
 */
class RoomOverviewSummaryBackfillService
{
    // ══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Resolve the backfill decision for a single room_overviews[] row.
     *
     * @param array{
     *     room?: string,
     *     overview?: string,
     *     works_summary?: string,
     *     summary?: string,
     *     description?: string,
     *     solution_type_id?: int|null
     * } $row
     *
     * @return array{
     *     action: 'backfilled'|'already-set'|'both-set-no-action'|'neither-set',
     *     updated_row: array
     * }
     */
    public function resolveRow(array $row): array
    {
        $worksSummary = trim((string) ($row['works_summary'] ?? ''));
        $summary      = trim((string) ($row['summary']       ?? ''));

        // ── backfilled: works_summary empty, summary non-empty → copy ────────
        if ($worksSummary === '' && $summary !== '') {
            $updated = $row;
            // Preserve the legacy summary verbatim — Plan 06 owns the trim.
            $updated['works_summary'] = (string) $row['summary'];
            return ['action' => 'backfilled', 'updated_row' => $updated];
        }

        // ── already-set: only works_summary populated → no-op ────────────────
        if ($worksSummary !== '' && $summary === '') {
            return ['action' => 'already-set', 'updated_row' => $row];
        }

        // ── both-set-no-action: divergence — preserve both verbatim ──────────
        if ($worksSummary !== '' && $summary !== '') {
            return ['action' => 'both-set-no-action', 'updated_row' => $row];
        }

        // ── neither-set: both empty → no-op ──────────────────────────────────
        return ['action' => 'neither-set', 'updated_row' => $row];
    }
}

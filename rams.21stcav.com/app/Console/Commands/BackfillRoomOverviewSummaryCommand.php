<?php

namespace App\Console\Commands;

use App\Models\RamsDocument;
use App\Services\Rams\RoomOverviewSummaryBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * rams:backfill-room-overview-summary
 *
 * Phase 22.1 D-07 — one-shot artisan command that copies legacy
 * `reviewed_data.room_overviews[*].summary` into `works_summary` where the
 * latter is empty. Mirrors the Phase 22 BackfillCablePortFksCommand pattern:
 * dry-run by DEFAULT (--apply to persist), idempotent, per-row 4-category
 * report.
 *
 * Per-row outcome categories (CONTEXT.md D-07):
 *   - backfilled          : summary → works_summary, written inside DB::tx
 *   - already-set         : works_summary already populated, summary empty
 *                           → skip
 *   - both-set-no-action  : both populated → no overwrite, logs WARNING
 *                           with rams_id + room name so a human can reconcile
 *   - neither-set         : both empty → skip
 *
 * Idempotency: re-running --apply on a row backfilled by a prior invocation
 * lands the row in 'both-set-no-action' (because the legacy summary is left
 * in place verbatim until Plan 06 trims the schema). The row is NOT touched
 * on the second pass and `rows-written` reports 0 — proves idempotency.
 *
 * T-22.1-05 (SQL injection via rams_id arg): mitigated by `(int) $this
 * ->argument('rams')` cast — PHP integer cast silently drops junk after the
 * first non-numeric char. Eloquent `where('id', ...)` uses PDO parameterised
 * bindings. Test
 * test_t22_1_05_sql_injection_via_rams_id_arg_neutralised_by_int_cast
 * asserts Schema::hasTable('rams_documents') survives a `"5; DROP TABLE
 * rams_documents;"` payload.
 *
 * Usage:
 *   php artisan rams:backfill-room-overview-summary               # dry-run, all records
 *   php artisan rams:backfill-room-overview-summary --apply       # write, all records
 *   php artisan rams:backfill-room-overview-summary 42            # dry-run, RAMS id 42
 *   php artisan rams:backfill-room-overview-summary 42 --apply    # write, RAMS id 42
 *
 * Admin-only by CLI-access convention (no HTTP surface).
 *
 * @see app/Services/Rams/RoomOverviewSummaryBackfillService.php
 * @see app/Console/Commands/BackfillCablePortFksCommand.php (Phase 22 template)
 */
class BackfillRoomOverviewSummaryCommand extends Command
{
    protected $signature = 'rams:backfill-room-overview-summary
                            {rams? : RamsDocument ID to scope the backfill (default: all records)}
                            {--apply : Actually write reviewed_data updates (default: dry-run reports only)}';

    protected $description = 'Phase 22.1 — copy legacy room_overviews[*].summary into works_summary. Dry-run by default, idempotent.';

    public function __construct(
        private readonly RoomOverviewSummaryBackfillService $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ── T-22.1-05: int cast neutralises SQL injection on the rams arg ────
        $ramsArg = $this->argument('rams');
        $ramsId  = $ramsArg !== null ? (int) $ramsArg : null;
        $apply   = (bool) $this->option('apply');

        if (! $apply) {
            $this->info('[DRY RUN] rams:backfill-room-overview-summary — pass --apply to persist.');
        } else {
            $this->info('rams:backfill-room-overview-summary — APPLYING writes.');
        }

        // ── Scope query ──────────────────────────────────────────────────────
        $query = RamsDocument::query()->orderBy('id');
        if ($ramsId !== null) {
            $query->where('id', $ramsId);
        }
        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('No rams_documents found.');
            return self::SUCCESS;
        }

        // ── 4-category + apply-time write counter ────────────────────────────
        $summary = [
            'backfilled'         => 0,
            'already-set'        => 0,
            'both-set-no-action' => 0,
            'neither-set'        => 0,
            'rows-written'       => 0,
        ];

        foreach ($records as $record) {
            $reviewed = $record->reviewed_data ?? [];
            $rooms    = (array) ($reviewed['room_overviews'] ?? []);
            if (empty($rooms)) {
                continue;
            }

            $changed              = false;
            $perRecordBackfilled  = 0;

            foreach ($rooms as $idx => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $decision      = $this->resolver->resolveRow($row);
                $tag           = $decision['action'];
                $summary[$tag] = ($summary[$tag] ?? 0) + 1;

                $this->line(sprintf(
                    '  rams #%d room "%s" — %s',
                    $record->id,
                    (string) ($row['room'] ?? '(unnamed)'),
                    $tag,
                ));

                if ($tag === 'both-set-no-action') {
                    Log::warning('BackfillRoomOverviewSummaryCommand: both works_summary and summary populated — no overwrite, manual review recommended', [
                        'rams_id' => $record->id,
                        'room'    => (string) ($row['room'] ?? ''),
                    ]);
                }

                if ($tag === 'backfilled') {
                    $rooms[$idx] = $decision['updated_row'];
                    $changed = true;
                    $perRecordBackfilled++;
                }
            }

            // ── Atomic write only when --apply AND at least one row was
            //    actually backfilled. Mirrors the Phase 22 BackfillCablePort
            //    FksCommand `if ($apply && $tag === 'matched')` precedent ───
            if ($apply && $changed) {
                DB::transaction(function () use ($record, $reviewed, $rooms) {
                    $reviewed['room_overviews'] = array_values($rooms);
                    $record->update(['reviewed_data' => $reviewed]);
                });
                $summary['rows-written'] += $perRecordBackfilled;
            }
        }

        // ── Final 5-counter summary line ─────────────────────────────────────
        $this->newLine();
        $this->info('Summary:');
        $this->line(sprintf(
            '  backfilled: %d  |  already-set: %d  |  both-set-no-action: %d  |  neither-set: %d  |  rows-written: %d',
            $summary['backfilled'],
            $summary['already-set'],
            $summary['both-set-no-action'],
            $summary['neither-set'],
            $summary['rows-written'],
        ));

        Log::info('rams:backfill-room-overview-summary completed', [
            'rams_id' => $ramsId,
            'apply'   => $apply,
            'summary' => $summary,
        ]);

        return self::SUCCESS;
    }
}

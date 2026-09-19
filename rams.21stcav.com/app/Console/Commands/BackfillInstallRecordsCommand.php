<?php

namespace App\Console\Commands;

use App\Models\InstallProgramme;
use App\Models\InstallRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * install-records:backfill
 *
 * Phase 45 Plan 04 Task 3 — links pre-existing `install_programmes` rows to
 * one durable `install_records` row per project (45-CONTEXT.md D-06).
 *
 * Dry-run by DEFAULT, mirroring `BackfillCablePortFksCommand` (`:20-23`).
 * The link deliberately does NOT happen in the migration: a migration that
 * wrote rows would be an un-reviewable, un-dry-runnable data change against
 * live production programmes. Review the dry-run report, then `--apply`.
 *
 * Per-row outcome categories:
 *   - linked            : programme has a project and no record yet → will be
 *                         (or was) stamped with the project's durable record
 *   - already-linked    : install_record_id is already non-null → skipped
 *                         BEFORE any work, never re-checked or rewritten.
 *                         This is the idempotency invariant: a second --apply
 *                         performs zero writes and leaves updated_at alone.
 *   - orphan-no-project : project_id IS NULL. Legal — install_programmes
 *                         .project_id is nullOnDelete, so a programme
 *                         survives its project. Such a row gets NO record
 *                         (a record is defined per project) and is reported
 *                         in its own category rather than silently skipped.
 *   - wrote             : rows actually persisted (only ever under --apply)
 *
 * ALL generations for a project — archived and soft-deleted included — are
 * linked to the SAME record. History belongs to the durable parent.
 *
 * SAFETY: this command never hard-deletes anything, and never calls save()
 * on an install_tasks, commissioning_items or commissioning_signoffs row.
 * `install_tasks` did not move in Phase 45 (see the install_records migration
 * docblock — re-pointing them is Phase 51's work).
 *
 * SQL injection on the project arg: cast to `(int)` and PDO
 * parameter-bound, per the BackfillCablePortFksCommand:53-58 precedent.
 * Arg "5; DROP TABLE install_programmes;" parses as the integer 5.
 *
 * Usage:
 *   php artisan install-records:backfill            # dry-run, all projects
 *   php artisan install-records:backfill --apply    # write, all projects
 *   php artisan install-records:backfill 5          # dry-run, project 5
 *   php artisan install-records:backfill 5 --apply  # write, project 5
 *
 * Admin-only by convention (CLI access = admin). Do NOT expose via HTTP.
 *
 * @see app/Models/InstallRecord.php
 * @see app/Services/InstallProgrammeService.php (the live writer — UNTOUCHED
 *      by this command)
 */
class BackfillInstallRecordsCommand extends Command
{
    protected $signature = 'install-records:backfill
                            {project? : Project ID to scope the backfill (default: all projects)}
                            {--apply : Actually write install_record_id (default: dry-run reports only)}';

    protected $description = 'Link existing install_programmes rows to one durable install_records row per project. Idempotent and dry-run by default.';

    public function handle(): int
    {
        // int cast neutralises SQL injection on the project arg.
        $projectArg = $this->argument('project');
        $projectId  = $projectArg !== null ? (int) $projectArg : null;

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->info('[DRY RUN] install-records:backfill — pass --apply to persist.');
        } else {
            $this->info('install-records:backfill — APPLYING writes.');
        }

        // withTrashed(): an archived or soft-deleted generation is still part
        // of the project's history and belongs to the same durable record.
        $query = InstallProgramme::withTrashed()
            ->orderBy('project_id')
            ->orderBy('id');

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $programmes = $query->get();

        if ($programmes->isEmpty()) {
            $this->info('No install_programmes found.');

            return self::SUCCESS;
        }

        $summary = [
            'linked'            => 0,
            'already-linked'    => 0,
            'orphan-no-project' => 0,
            'wrote'             => 0,
        ];

        // Per-project record cache, so N generations for one project resolve
        // the record once rather than N times.
        $recordIdByProject = [];

        foreach ($programmes as $programme) {
            // Idempotency guard — checked FIRST, before any resolution work.
            if ($programme->install_record_id !== null) {
                $summary['already-linked']++;
                $this->line(sprintf('  #%d — %s: %s', $programme->id, 'already-linked', 'install_record_id already set'));
                continue;
            }

            if ($programme->project_id === null) {
                $summary['orphan-no-project']++;
                $this->line(sprintf('  #%d — %s: %s', $programme->id, 'orphan-no-project', 'project_id is NULL (nullOnDelete) — a record is defined per project, so none is created'));
                continue;
            }

            $summary['linked']++;

            if (! $apply) {
                $this->line(sprintf('  #%d — %s: %s', $programme->id, 'linked', "would link to project {$programme->project_id}'s durable record"));
                continue;
            }

            DB::transaction(function () use ($programme, &$recordIdByProject, &$summary) {
                if (! isset($recordIdByProject[$programme->project_id])) {
                    $recordIdByProject[$programme->project_id] = InstallRecord::firstOrCreate(
                        ['project_id' => $programme->project_id]
                    )->id;
                }

                $recordId = $recordIdByProject[$programme->project_id];

                $programme->update(['install_record_id' => $recordId]);

                $summary['wrote']++;

                $this->line(sprintf('  #%d — %s: %s', $programme->id, 'linked', "install_record_id = {$recordId}"));
            });
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line(sprintf(
            '  linked: %d  |  already-linked: %d  |  orphan-no-project: %d  |  wrote: %d',
            $summary['linked'],
            $summary['already-linked'],
            $summary['orphan-no-project'],
            $summary['wrote'],
        ));

        Log::info('install-records:backfill completed', [
            'project_id' => $projectId,
            'apply'      => $apply,
            'summary'    => $summary,
        ]);

        return self::SUCCESS;
    }
}

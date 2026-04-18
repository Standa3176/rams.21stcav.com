<?php

namespace App\Console\Commands;

use App\Services\WorkerMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * QueueHealthCheckCommand
 *
 * Deterministic, read-only health probe for the document-generation queue.
 *
 * Exit codes are stable so external schedulers (cron / Windows Task Scheduler)
 * can trigger recovery only when the queue is unhealthy:
 *   0  — healthy
 *   1  — unhealthy (pending-age over threshold OR heartbeat stale with work queued
 *       OR generating-count stuck beyond threshold)
 *   2  — critical (worker absent AND work queued)
 *
 * Output:
 *   default  — human-readable table
 *   --json   — single-line JSON object for machine consumption
 *
 * Thresholds (seconds, deterministic — no env overrides on purpose so cron
 * decisions are reproducible across environments):
 *   PENDING_AGE_WARN = 300    (5 min)
 *   PENDING_AGE_CRIT = 900    (15 min)
 *   HEARTBEAT_STALE  = 120    (2 min)
 *   GENERATING_STUCK = 900    (15 min — a doc in `generating` longer than this
 *                             without queue progress is considered stuck)
 */
class QueueHealthCheckCommand extends Command
{
    protected $signature = 'queue:health-check {--json : Emit JSON instead of a table}';

    protected $description = 'Report document-generation queue health with deterministic exit codes.';

    public const EXIT_HEALTHY   = 0;
    public const EXIT_UNHEALTHY = 1;
    public const EXIT_CRITICAL  = 2;

    public const PENDING_AGE_WARN_S = 300;
    public const PENDING_AGE_CRIT_S = 900;
    public const HEARTBEAT_STALE_S  = 120;
    public const GENERATING_STUCK_S = 900;

    public function handle(WorkerMonitorService $monitor): int
    {
        $snapshot = $this->snapshot($monitor);
        $verdict  = $this->verdict($snapshot);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot + ['verdict' => $verdict['label'], 'exit_code' => $verdict['code']], JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable($snapshot, $verdict);
        }

        return $verdict['code'];
    }

    /** Collect the deterministic snapshot used by both output + verdict. */
    public function snapshot(WorkerMonitorService $monitor): array
    {
        // jobs.created_at is an unsignedInteger (UNIX epoch) per Laravel's default
        // queue migration — not a DATETIME — so use it as an integer directly.
        $oldestCreatedAt = DB::table('jobs')->min('created_at');
        $oldestAgeS      = $oldestCreatedAt !== null ? max(0, time() - (int) $oldestCreatedAt) : 0;

        return [
            'timestamp'              => date('c'),
            'pending_jobs'           => (int) DB::table('jobs')->count(),
            'failed_jobs'            => (int) DB::table('failed_jobs')->count(),
            'oldest_pending_age_s'   => $oldestAgeS,
            'jobs_by_queue'          => DB::table('jobs')->selectRaw('queue, count(*) as c')->groupBy('queue')->pluck('c', 'queue')->all(),
            'generating'             => [
                'rams'      => $this->generatingCount('rams_documents'),
                'om_manual' => $this->generatingCount('om_manuals'),
                'cable'     => $this->generatingCount('cable_schedules'),
                'worksheet' => $this->generatingCount('worksheets'),
            ],
            'oldest_generating_age_s' => $this->oldestGeneratingAgeSeconds(),
            'worker'                 => [
                'is_running'          => $monitor->isRunning(),
                'heartbeat_age_s'     => $monitor->heartbeatAgeSeconds(),
                'worker_log_age_s'    => $monitor->workerLogAgeSeconds(),
                'is_stalled'          => $monitor->isStalled((int) DB::table('jobs')->count()),
            ],
            'thresholds' => [
                'pending_age_warn_s' => self::PENDING_AGE_WARN_S,
                'pending_age_crit_s' => self::PENDING_AGE_CRIT_S,
                'heartbeat_stale_s'  => self::HEARTBEAT_STALE_S,
                'generating_stuck_s' => self::GENERATING_STUCK_S,
            ],
        ];
    }

    /** Deterministic verdict derivation — pure function of snapshot. */
    public function verdict(array $s): array
    {
        $pending       = $s['pending_jobs'];
        $oldestAge     = $s['oldest_pending_age_s'];
        $genAge        = $s['oldest_generating_age_s'] ?? 0;
        $workerRunning = $s['worker']['is_running'];

        if ($pending > 0 && ! $workerRunning) {
            return ['label' => 'CRITICAL', 'code' => self::EXIT_CRITICAL, 'reason' => 'Pending jobs with no live worker'];
        }
        if ($pending > 0 && $oldestAge >= self::PENDING_AGE_CRIT_S) {
            return ['label' => 'CRITICAL', 'code' => self::EXIT_CRITICAL, 'reason' => "Oldest pending job age {$oldestAge}s ≥ critical threshold"];
        }
        if ($pending > 0 && $oldestAge >= self::PENDING_AGE_WARN_S) {
            return ['label' => 'UNHEALTHY', 'code' => self::EXIT_UNHEALTHY, 'reason' => "Oldest pending job age {$oldestAge}s ≥ warn threshold"];
        }
        if ($genAge >= self::GENERATING_STUCK_S) {
            return ['label' => 'UNHEALTHY', 'code' => self::EXIT_UNHEALTHY, 'reason' => "Oldest `generating` document age {$genAge}s ≥ stuck threshold"];
        }

        return ['label' => 'HEALTHY', 'code' => self::EXIT_HEALTHY, 'reason' => 'All thresholds within bounds'];
    }

    private function generatingCount(string $table): int
    {
        return (int) DB::table($table)->where('status', 'generating')->count();
    }

    /** Oldest `updated_at` across all 4 doc tables where status = generating (seconds ago). */
    private function oldestGeneratingAgeSeconds(): int
    {
        $oldest = null;
        foreach (['rams_documents', 'om_manuals', 'cable_schedules', 'worksheets'] as $table) {
            $ts = DB::table($table)->where('status', 'generating')->min('updated_at');
            if ($ts !== null) {
                $epoch = strtotime((string) $ts);
                if ($oldest === null || $epoch < $oldest) {
                    $oldest = $epoch;
                }
            }
        }
        return $oldest === null ? 0 : max(0, time() - $oldest);
    }

    private function renderTable(array $s, array $verdict): void
    {
        $this->line('');
        $this->line("<fg=cyan>Queue Health — {$s['timestamp']}</>");
        $this->line('');
        $this->table(['Metric', 'Value'], [
            ['Pending jobs',          (string) $s['pending_jobs']],
            ['Failed jobs',           (string) $s['failed_jobs']],
            ['Oldest pending age',    $s['oldest_pending_age_s'] . 's'],
            ['Generating: RAMS',      (string) $s['generating']['rams']],
            ['Generating: O&M',       (string) $s['generating']['om_manual']],
            ['Generating: Cable',     (string) $s['generating']['cable']],
            ['Generating: Worksheet', (string) $s['generating']['worksheet']],
            ['Oldest generating age', $s['oldest_generating_age_s'] . 's'],
            ['Worker running',        $s['worker']['is_running'] ? 'yes' : 'no'],
            ['Heartbeat age',         $s['worker']['heartbeat_age_s'] === null ? '(missing)' : $s['worker']['heartbeat_age_s'] . 's'],
            ['Worker log age',        $s['worker']['worker_log_age_s'] === null ? '(missing)' : $s['worker']['worker_log_age_s'] . 's'],
            ['Worker stalled',        $s['worker']['is_stalled'] ? 'yes' : 'no'],
        ]);
        $color = match ($verdict['label']) {
            'HEALTHY'   => 'green',
            'UNHEALTHY' => 'yellow',
            default     => 'red',
        };
        $this->line('');
        $this->line("Verdict: <fg={$color};options=bold>{$verdict['label']}</> — {$verdict['reason']}");
        $this->line('');
    }
}

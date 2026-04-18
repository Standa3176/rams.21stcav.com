<?php

namespace App\Console\Commands;

use App\Services\WorkerMonitorService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * QueueRecoverCommand
 *
 * CLI-only recovery procedure. Runs queue:health-check, and if the queue is
 * unhealthy (exit 1 or 2), performs a deterministic recovery:
 *
 *   1. Broadcasts queue:restart so any currently-running worker finishes its
 *      active job and exits on the next poll.
 *   2. Invokes queue:work --stop-when-empty --tries=2 --timeout=300 to drain
 *      pending jobs in-process.
 *
 * Concurrency protection: acquires a named lock via the configured cache
 * driver. A second invocation while a recovery is in progress exits with
 * EXIT_LOCKED (3). No retries — the cron schedule itself is the retry loop.
 *
 * HTTP safety: refuses to run if !runningInConsole(). This is belt-and-braces —
 * the command is not exposed via a route — but makes accidental misuse
 * explicit.
 *
 * --dry-run reports the actions that WOULD be taken without invoking them.
 */
class QueueRecoverCommand extends Command
{
    protected $signature = 'queue:recover {--dry-run : Show actions without executing}';

    protected $description = 'Detect queue stall and drain deterministically (CLI-only, locked).';

    public const EXIT_HEALTHY          = 0;
    public const EXIT_RECOVERED        = 0;
    public const EXIT_RECOVERY_FAILED  = 1;
    public const EXIT_LOCKED           = 3;
    public const EXIT_NOT_CONSOLE      = 4;

    public const LOCK_KEY    = 'queue-recover';
    public const LOCK_TTL_S  = 600; // 10 min — longer than --timeout=300 grace.

    public function handle(WorkerMonitorService $monitor, CacheFactory $cacheFactory): int
    {
        if (! app()->runningInConsole()) {
            $this->error('queue:recover must only run from a console context.');
            return self::EXIT_NOT_CONSOLE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $lockProvider = $this->resolveLockProvider($cacheFactory);

        // On cache drivers that do not support atomic locks (array driver under
        // tests / file driver in some setups), skip locking. Real deployments
        // use a supported driver (database/redis).
        $lock = $lockProvider?->lock(self::LOCK_KEY, self::LOCK_TTL_S);
        if ($lock !== null && ! $lock->get()) {
            $this->warn('Another queue:recover invocation holds the lock — exiting.');
            Log::info('QueueRecoverCommand: skipped (lock held)');
            return self::EXIT_LOCKED;
        }

        try {
            $healthExit = $this->call('queue:health-check', ['--json' => true]);

            if ($healthExit === QueueHealthCheckCommand::EXIT_HEALTHY) {
                $this->info('Queue is healthy — no action.');
                Log::info('QueueRecoverCommand: skipped (queue healthy)');
                return self::EXIT_HEALTHY;
            }

            $this->warn('Queue is unhealthy (health-check exit=' . $healthExit . '). Recovery starting.');
            Log::warning('QueueRecoverCommand: recovery starting', [
                'health_exit' => $healthExit,
                'dry_run'     => $isDryRun,
            ]);

            $plan = [
                ['cmd' => 'queue:restart',   'args' => []],
                ['cmd' => 'queue:work',      'args' => ['--stop-when-empty' => true, '--tries' => 2, '--timeout' => 300, '--sleep' => 3]],
            ];

            foreach ($plan as $step) {
                $displayArgs = $this->formatArgs($step['args']);
                $this->line(($isDryRun ? '[dry-run] ' : '') . "would call: artisan {$step['cmd']} {$displayArgs}");
                if ($isDryRun) {
                    continue;
                }
                Log::info('QueueRecoverCommand: running ' . $step['cmd'], ['args' => $step['args']]);
                $exitCode = $this->call($step['cmd'], $step['args']);
                Log::info('QueueRecoverCommand: finished ' . $step['cmd'], ['exit' => $exitCode]);
                if ($step['cmd'] === 'queue:work' && $exitCode !== 0) {
                    Log::error('QueueRecoverCommand: queue:work exited non-zero', ['exit' => $exitCode]);
                    return self::EXIT_RECOVERY_FAILED;
                }
            }

            if ($isDryRun) {
                $this->info('Dry-run complete.');
                return self::EXIT_HEALTHY;
            }

            $this->info('Recovery complete.');
            Log::info('QueueRecoverCommand: recovery complete');
            return self::EXIT_RECOVERED;
        } finally {
            $lock?->release();
        }
    }

    private function resolveLockProvider(CacheFactory $cacheFactory): ?LockProvider
    {
        /** @var CacheRepository $store */
        $store = $cacheFactory->store();
        $underlying = method_exists($store, 'getStore') ? $store->getStore() : null;
        return $underlying instanceof LockProvider ? $underlying : null;
    }

    private function formatArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $k => $v) {
            if (is_bool($v)) {
                if ($v) {
                    $parts[] = $k;
                }
                continue;
            }
            $parts[] = "{$k}={$v}";
        }
        return implode(' ', $parts);
    }
}

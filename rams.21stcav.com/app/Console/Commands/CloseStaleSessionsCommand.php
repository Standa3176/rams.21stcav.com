<?php

namespace App\Console\Commands;

use App\Services\TimeEntryService;
use Illuminate\Console\Command;

/**
 * programme:close-stale-sessions
 *
 * Phase 15 (INST-04e + D-17/D-18): auto-closes time entries whose last
 * heartbeat is older than --minutes (default 120). Delegates to
 * TimeEntryService::closeStaleSessions which handles:
 *   - Row-level locking (DB::transaction + lockForUpdate per entry)
 *   - Fallback when last_heartbeat_at IS NULL (closed_at = clocked_in_at + 1 min)
 *   - Log::warning per closure (user_id, project_id, entry_id, last_heartbeat_at, closed_at)
 *   - closure_reason = 'stale_auto_close' so the dashboard can distinguish auto vs manual
 *
 * Scheduled hourly via routes/console.php (withoutOverlapping). All state-transition
 * logic lives in TimeEntryService per CLAUDE.md thin-controller/thin-command pattern.
 *
 * Usage:
 *   php artisan programme:close-stale-sessions              # default 120 min threshold
 *   php artisan programme:close-stale-sessions --minutes=60 # custom threshold
 *
 * Exit codes:
 *   0 — success (even when 0 entries closed)
 *   1 — invalid option (e.g. --minutes=0 or negative)
 */
class CloseStaleSessionsCommand extends Command
{
    protected $signature = 'programme:close-stale-sessions
                            {--minutes=120 : Sessions with last_heartbeat_at older than this are closed}';

    protected $description = 'Auto-close time entries whose last heartbeat is older than --minutes (default 2h).';

    public function __construct(
        private readonly TimeEntryService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes <= 0) {
            $this->error('--minutes must be a positive integer.');

            return self::FAILURE;
        }

        $count = $this->service->closeStaleSessions($minutes);

        $this->info("Closed {$count} stale time entries (threshold: {$minutes} min).");

        return self::SUCCESS;
    }
}

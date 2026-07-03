<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Manages queue worker status detection for the admin Worker Monitor page.
 *
 * IMPORTANT — exec() hangs indefinitely on this managed host for any
 * subprocess command. Every attempt to call exec() from a PHP-FPM worker
 * (synchronously, deferred, or in a shutdown function) ties up that worker
 * permanently, eventually exhausting the pool and causing 504s site-wide.
 *
 * Therefore: the status-detection path (isRunning, getLastActivity, the
 * admin controller) contains ZERO exec() calls. The one exec() footprint
 * that remains is spawnWorker() — reachable only from CLI context AND
 * behind WORKER_EXEC_ENABLED=false (see H-05 hardening 2026-07-02).
 *
 * Status detection uses only file-based signals:
 *   1. Heartbeat file  — written by the worker on every loop via
 *      WorkerHeartbeatJob. A present and fresh heartbeat is the strongest
 *      possible signal that the worker is running.
 *   2. Worker log mtime — used ONLY as a secondary fallback when no heartbeat
 *      file is present (e.g. worker just started and hasn't written one yet).
 *      Once a heartbeat file exists, the log mtime is NEVER consulted, because
 *      a stale heartbeat is a definitive "stopped" signal and a residual fresh
 *      log entry from a previous run must not override it.
 *
 * Detection priority:
 *   Heartbeat present + within TTL   → RUNNING
 *   Heartbeat present + stale        → STOPPED  (log NOT checked)
 *   Heartbeat absent + log fresh     → RUNNING  (fallback)
 *   Heartbeat absent + log stale/absent → STOPPED
 *
 * Configuration (env variables):
 *   WORKER_HEARTBEAT_TTL   Signal TTL in seconds          (default: 300)
 *   QUEUE_PHP_BINARY       PHP binary for commands        (default: php)
 *
 * Constructor parameters (all optional):
 *   $heartbeatPath   Absolute path to heartbeat file. Defaults to storage_path().
 *   $logPath         Absolute path to worker log.    Defaults to storage_path().
 *   $ttlOverride     TTL in seconds.                 Defaults to env / 300.
 *
 * Explicit constructor injection is intended for unit tests only. Normal
 * application code resolves this class via the Laravel container (all nulls,
 * env-driven configuration).
 *
 * Starting/stopping the worker must be done via SSH or a cron job.
 * The admin page shows the exact command to run.
 */
class WorkerMonitorService
{
    public const WORKER_LOG       = 'logs/worker.log';
    public const WORKER_HEARTBEAT = 'worker-heartbeat';

    public function __construct(
        private ?string $heartbeatPath = null,
        private ?string $logPath       = null,
        private ?int    $ttlOverride   = null,
    ) {}

    // ── Config helpers ────────────────────────────────────────────────────────

    /**
     * Signal TTL in seconds.
     *
     * Constructor injection takes priority (useful for tests so they do not
     * depend on the env/config stack). Otherwise reads WORKER_HEARTBEAT_TTL
     * from the environment with a 300-second (5-minute) default.
     */
    private function ttl(): int
    {
        if ($this->ttlOverride !== null) {
            return $this->ttlOverride;
        }

        return (int) env('WORKER_HEARTBEAT_TTL', 300);
    }

    /**
     * PHP binary path used in generated SSH / cron command strings.
     *
     * Configurable via QUEUE_PHP_BINARY env variable (e.g. /usr/bin/php8.3).
     * Defaults to bare "php", which relies on PATH resolution on the server.
     */
    private function phpBinary(): string
    {
        return (string) env('QUEUE_PHP_BINARY', 'php');
    }

    /** Resolved absolute path to the heartbeat file. */
    private function heartbeatFile(): string
    {
        return $this->heartbeatPath ?? storage_path(self::WORKER_HEARTBEAT);
    }

    /** Resolved absolute path to the worker log file. */
    private function workerLogFile(): string
    {
        return $this->logPath ?? storage_path(self::WORKER_LOG);
    }

    // ── Status detection ──────────────────────────────────────────────────────

    /**
     * Returns true if the worker appears to be running based on file signals.
     * Safe to call on every upload / generate request — no exec(), no blocking I/O.
     *
     * Priority rules (prevents log-mtime false positives):
     *
     *   1. Heartbeat file present and readable:
     *      - timestamp within TTL  → true  (definitely running)
     *      - timestamp stale       → false (definitely stopped; log NOT checked)
     *      - empty / corrupt (ts=0)→ fall through to log mtime check
     *
     *   2. No heartbeat file (or empty/corrupt timestamp):
     *      - Log modified within TTL → true  (worker started recently)
     *      - Otherwise               → false
     *
     * The key invariant: once a heartbeat file exists with a valid timestamp,
     * the log mtime is never consulted. A stale heartbeat beats a fresh log.
     */
    public function isRunning(): bool
    {
        $heartbeat = $this->heartbeatFile();

        if (file_exists($heartbeat)) {
            $ts = (int) @file_get_contents($heartbeat);

            if ($ts > 0) {
                // Valid heartbeat timestamp — use it as the sole signal.
                // Do NOT fall through to the log check.
                return (time() - $ts) < $this->ttl();
            }
            // ts === 0: empty or corrupt file — fall through to log fallback.
        }

        // No heartbeat file (or unreadable timestamp): fall back to log mtime.
        $log = $this->workerLogFile();
        if (file_exists($log) && (time() - filemtime($log)) < $this->ttl()) {
            return true;
        }

        return false;
    }

    /**
     * Age of the heartbeat file in seconds, or null if missing / unreadable.
     * Used by queue:health-check to report freshness independent of isRunning().
     */
    public function heartbeatAgeSeconds(): ?int
    {
        $heartbeat = $this->heartbeatFile();
        if (! file_exists($heartbeat)) {
            return null;
        }
        $ts = (int) @file_get_contents($heartbeat);
        if ($ts <= 0) {
            return null;
        }
        return max(0, time() - $ts);
    }

    /** Age of worker.log mtime in seconds, or null if missing. */
    public function workerLogAgeSeconds(): ?int
    {
        $log = $this->workerLogFile();
        if (! file_exists($log)) {
            return null;
        }
        return max(0, time() - filemtime($log));
    }

    /**
     * "Stalled" means pending work exists AND both freshness signals are absent
     * or stale beyond a short grace window. Decoupled from isRunning() because a
     * heartbeat within the (longer) TTL still counts as "running" for the
     * existing UI, but a shorter staleness window catches a hung loop sooner.
     */
    public function isStalled(int $pendingJobs = 0, int $stalenessGraceSeconds = 120): bool
    {
        if ($pendingJobs <= 0) {
            return false;
        }
        $hb  = $this->heartbeatAgeSeconds();
        $log = $this->workerLogAgeSeconds();

        // No heartbeat AND no recent log activity → stalled
        if ($hb === null && ($log === null || $log >= $stalenessGraceSeconds)) {
            return true;
        }
        // Heartbeat present but gone stale → stalled
        if ($hb !== null && $hb >= $stalenessGraceSeconds) {
            return true;
        }
        return false;
    }

    /**
     * Called before dispatching a queue job.
     *
     * CRITICAL: This method must NEVER block an HTTP request.
     * exec() can hang indefinitely on Windows/managed hosts, causing 504 timeouts
     * that leave records stuck in "generating" forever.
     *
     * In HTTP context: only logs a warning — never calls exec()/spawnWorker().
     * In console context (artisan commands, tinker): may spawn if exec is enabled.
     */
    public function ensureRunning(): void
    {
        if ($this->isRunning()) {
            return;
        }

        // NEVER exec() from an HTTP request — it can hang the PHP-FPM worker
        if (! app()->runningInConsole()) {
            Log::warning(
                'WorkerMonitorService: worker not running (HTTP context — will not spawn). ' .
                'Job dispatched to queue but may not process until worker is started. ' .
                'Start it via: php artisan queue:listen --tries=2 --timeout=300'
            );
            return;
        }

        // Console context (artisan/tinker) — safe to attempt spawn
        if ($this->canExec()) {
            Log::info('WorkerMonitorService: worker not running — auto-spawning (console context).');
            $lines = $this->spawnWorker();
            Log::info('WorkerMonitorService: spawn result', ['output' => $lines]);
        } else {
            Log::warning(
                'WorkerMonitorService: worker does not appear to be running. ' .
                'Start it via SSH or a cron job — see Admin › Worker Monitor.'
            );
        }
    }

    // ── Log reading ───────────────────────────────────────────────────────────

    /** Returns the last 10 lines of the worker log. */
    public function getLastActivity(): string
    {
        $log = $this->workerLogFile();

        if (! file_exists($log)) {
            return 'No worker log found yet.';
        }

        $size = filesize($log);
        if ($size === 0) {
            return '(log file is empty)';
        }

        $fp = @fopen($log, 'r');
        if (! $fp) {
            return 'Could not open log file.';
        }

        $seek = max(0, $size - 4096);
        fseek($fp, $seek);
        $content = fread($fp, 4096);
        fclose($fp);

        $lines = array_slice(explode("\n", trim((string) $content)), -10);

        return implode("\n", $lines);
    }

    public function getLogPath(): string
    {
        return $this->workerLogFile();
    }

    // ── Heartbeat management ──────────────────────────────────────────────────

    /**
     * Write the heartbeat timestamp.
     * Called from CLI-only contexts (artisan commands, queue jobs) — never
     * called from an HTTP request.
     */
    public function writeHeartbeat(): void
    {
        @file_put_contents($this->heartbeatFile(), time());
    }

    /** Clear the heartbeat (e.g. when the worker is asked to stop). */
    public function clearHeartbeat(): void
    {
        @unlink($this->heartbeatFile());
    }

    // ── Exec-based spawn (opt-in via WORKER_EXEC_ENABLED=true, CLI-only) ─────

    /**
     * Returns true when exec()-based spawn is enabled and safe to attempt.
     *
     * All three gates must pass:
     *   1. exec() function is available (not disabled by disable_functions).
     *   2. WORKER_EXEC_ENABLED=true in .env (off by default).
     *   3. We are running in a console context (artisan/tinker/queue) — HTTP
     *      requests are hard-blocked because exec() can hang PHP-FPM workers
     *      and cause pool-wide 504s, and because a rogue admin path calling
     *      this from a request is the audit's H-05 attack surface.
     *
     * H-05: killProcesses() (Windows wmic/taskkill + *nix pkill -f) was deleted
     * on 2026-07-02 — nothing called it from application code, and its
     * broad-brush `pkill -f "artisan queue:work"` would happily terminate
     * unrelated worker processes on a shared host.
     */
    public function canExec(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        if (! (bool) env('WORKER_EXEC_ENABLED', false)) {
            return false;
        }

        // Defence-in-depth: even if the caller forgets the runningInConsole()
        // check (as ensureRunning() correctly does), refuse from HTTP.
        return app()->runningInConsole();
    }

    /**
     * Spawn a new queue worker in the background.
     * Returns an array of human-readable log lines for display.
     * Writes a preliminary heartbeat so the status badge flips to Running immediately.
     *
     * CLI-only: hard-refuses to run inside an HTTP request. Callers that
     * bypass canExec() and invoke this directly still get blocked here.
     */
    public function spawnWorker(): array
    {
        // H-05 belt-and-braces: hard-block HTTP context regardless of caller.
        if (! app()->runningInConsole()) {
            Log::error(
                'WorkerMonitorService: spawnWorker() called from HTTP context — refusing. ' .
                'This method must never run under PHP-FPM; exec() can hang the worker pool.'
            );
            return ['✗ spawnWorker refused: HTTP context (this method is CLI-only).'];
        }

        $lines   = [];
        $php     = $this->phpBinary();
        $artisan = base_path('artisan');
        $log     = $this->workerLogFile();

        if (PHP_OS_FAMILY === 'Windows') {
            // cmd /c start /B fully detaches the process from the parent.
            // Windows lacks a portable equivalent to escapeshellarg for this
            // "start" invocation, but $php/$artisan/$log are all derived
            // from env/base_path (not user input) so quoting is sufficient.
            $cmd = 'cmd /c start /B ""'
                . ' "' . $php . '"'
                . ' "' . $artisan . '"'
                . ' queue:work --tries=2 --timeout=300 --sleep=3 --memory=256'
                . ' >> "' . $log . '" 2>&1';
        } else {
            $cmd = 'nohup '
                . escapeshellarg($php) . ' '
                . escapeshellarg($artisan) . ' '
                . 'queue:work --tries=2 --timeout=300 --sleep=3 --memory=256'
                . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        }

        $lines[] = '$ ' . $cmd;

        $startTime = microtime(true);

        try {
            exec($cmd, $execOut, $exitCode);
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $startTime, 2);
            $lines[] = "✗ exec() threw after {$elapsed}s: " . $e->getMessage();
            Log::error('WorkerMonitorService: spawnWorker exec() exception', [
                'error'   => $e->getMessage(),
                'elapsed' => $elapsed,
            ]);
            return $lines;
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($exitCode !== 0) {
            $lines[] = "✗ Spawn failed (exit {$exitCode}, {$elapsed}s)" . (empty($execOut) ? '.' : ': ' . implode(' ', $execOut));
        } else {
            $lines[] = "✓ Worker process spawned (exit 0, {$elapsed}s).";

            if (! empty($execOut)) {
                foreach ($execOut as $line) {
                    $lines[] = '  ' . $line;
                }
            }

            $this->writeHeartbeat();
            $lines[] = '✓ Heartbeat written — status will show Running.';
        }

        return $lines;
    }

    // ── SSH command helpers ───────────────────────────────────────────────────

    /** Returns the exact SSH command the admin should run to start the worker. */
    public function startCommand(): string
    {
        return 'nohup ' . $this->phpBinary() . ' ' . base_path('artisan')
            . ' queue:work --tries=2 --timeout=300 --sleep=3 --memory=256'
            . ' >> ' . storage_path(self::WORKER_LOG)
            . ' 2>&1 &';
    }

    /** Returns the cron entry to keep the worker alive automatically. */
    public function cronEntry(): string
    {
        return '* * * * * ' . $this->phpBinary() . ' ' . base_path('artisan')
            . ' queue:work --stop-when-empty --tries=2 --timeout=300'
            . ' >> ' . storage_path(self::WORKER_LOG)
            . ' 2>&1';
    }
}

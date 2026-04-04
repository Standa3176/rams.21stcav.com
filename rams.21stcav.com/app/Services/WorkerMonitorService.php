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
 * Therefore: this service contains ZERO exec() calls.
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
     * Called before dispatching a queue job.
     * Logs a warning if the worker does not appear to be running.
     * Does NOT attempt to start the worker (exec() hangs on this server).
     */
    public function ensureRunning(): void
    {
        if (! $this->isRunning()) {
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

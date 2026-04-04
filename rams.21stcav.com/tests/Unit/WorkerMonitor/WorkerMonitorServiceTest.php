<?php

namespace Tests\Unit\WorkerMonitor;

use App\Services\WorkerMonitorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerMonitorService::isRunning().
 *
 * Pure PHPUnit — no Laravel container required.
 *
 * The service accepts optional constructor paths and TTL so tests can control
 * every signal independently of the runtime environment. Temporary files are
 * written to sys_get_temp_dir() and cleaned up in tearDown().
 *
 * Test scenarios
 * ──────────────
 * A  Fresh heartbeat                          → true
 * B  Stale heartbeat + fresh log              → false  ← key regression guard
 * C  Stale heartbeat + stale log              → false
 * D  No heartbeat + fresh log (fallback path) → true
 * E  No files at all                          → false
 * F  Empty/corrupt heartbeat + fresh log      → true   (falls through to log)
 * G  Empty/corrupt heartbeat + stale log      → false
 * H  TTL boundary (short TTL)                 → boundary behaviour correct
 */
class WorkerMonitorServiceTest extends TestCase
{
    private string $heartbeatPath;
    private string $logPath;
    private WorkerMonitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique paths per test run so parallel suites do not collide.
        $id = uniqid('wmtest_', true);
        $this->heartbeatPath = sys_get_temp_dir() . '/worker_heartbeat_' . $id;
        $this->logPath       = sys_get_temp_dir() . '/worker_log_'       . $id;

        $this->service = new WorkerMonitorService(
            heartbeatPath: $this->heartbeatPath,
            logPath:       $this->logPath,
            ttlOverride:   300,
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeatPath);
        @unlink($this->logPath);
        parent::tearDown();
    }

    // ── Scenario A: Fresh heartbeat ───────────────────────────────────────────

    public function test_is_running_returns_true_with_fresh_heartbeat(): void
    {
        file_put_contents($this->heartbeatPath, time());

        $this->assertTrue($this->service->isRunning());
    }

    // ── Scenario B: Stale heartbeat + fresh log → must return false ───────────
    //
    // This is the primary regression guard for the priority-change introduced
    // in this upgrade. The old implementation checked both signals independently:
    // a stale heartbeat plus a fresh log returned true (false positive). The new
    // implementation stops as soon as it reads a valid (but stale) heartbeat
    // timestamp, so the log is never consulted.

    public function test_is_running_returns_false_with_stale_heartbeat_even_when_log_is_fresh(): void
    {
        // Heartbeat written 400 s ago — past the 300 s TTL.
        file_put_contents($this->heartbeatPath, time() - 400);

        // Log touched right now — would pass the log-mtime check on its own.
        file_put_contents($this->logPath, '[INFO] Queue worker started');
        touch($this->logPath, time());

        $this->assertFalse($this->service->isRunning());
    }

    // ── Scenario C: Both signals stale ────────────────────────────────────────

    public function test_is_running_returns_false_when_both_heartbeat_and_log_are_stale(): void
    {
        file_put_contents($this->heartbeatPath, time() - 400);

        file_put_contents($this->logPath, 'old log entry');
        touch($this->logPath, time() - 400);

        $this->assertFalse($this->service->isRunning());
    }

    // ── Scenario D: No heartbeat file, fresh log (log fallback path) ──────────

    public function test_is_running_returns_true_with_no_heartbeat_and_fresh_log(): void
    {
        // No heartbeat file — secondary fallback applies.
        file_put_contents($this->logPath, '[INFO] Queue worker started');
        touch($this->logPath, time());

        $this->assertTrue($this->service->isRunning());
    }

    public function test_is_running_returns_false_with_no_heartbeat_and_stale_log(): void
    {
        file_put_contents($this->logPath, 'old entry');
        touch($this->logPath, time() - 400);

        $this->assertFalse($this->service->isRunning());
    }

    // ── Scenario E: No files at all ───────────────────────────────────────────

    public function test_is_running_returns_false_with_no_files(): void
    {
        // Neither heartbeat nor log exists.
        $this->assertFalse($this->service->isRunning());
    }

    // ── Scenario F: Empty/corrupt heartbeat + fresh log → falls through ───────
    //
    // An empty heartbeat (ts = 0) is treated as "no valid timestamp" and the
    // service falls through to the log-mtime check, not as "stale".

    public function test_is_running_falls_through_to_fresh_log_when_heartbeat_is_empty(): void
    {
        file_put_contents($this->heartbeatPath, '');

        file_put_contents($this->logPath, '[INFO] Queue worker started');
        touch($this->logPath, time());

        $this->assertTrue($this->service->isRunning());
    }

    // ── Scenario G: Empty heartbeat + stale log ───────────────────────────────

    public function test_is_running_returns_false_when_heartbeat_is_empty_and_log_is_stale(): void
    {
        file_put_contents($this->heartbeatPath, '');

        file_put_contents($this->logPath, 'old log');
        touch($this->logPath, time() - 400);

        $this->assertFalse($this->service->isRunning());
    }

    // ── Scenario H: TTL boundary behaviour ───────────────────────────────────

    public function test_ttl_boundary_with_fresh_heartbeat_just_within_window(): void
    {
        $service = new WorkerMonitorService(
            heartbeatPath: $this->heartbeatPath,
            logPath:       $this->logPath,
            ttlOverride:   10,
        );

        // 8 s old — within 10 s TTL.
        file_put_contents($this->heartbeatPath, time() - 8);
        $this->assertTrue($service->isRunning());
    }

    public function test_ttl_boundary_with_heartbeat_just_outside_window(): void
    {
        $service = new WorkerMonitorService(
            heartbeatPath: $this->heartbeatPath,
            logPath:       $this->logPath,
            ttlOverride:   10,
        );

        // 12 s old — outside 10 s TTL.
        file_put_contents($this->heartbeatPath, time() - 12);
        $this->assertFalse($service->isRunning());
    }

    // ── API surface: write / clear heartbeat ──────────────────────────────────

    public function test_write_heartbeat_creates_file_with_current_timestamp(): void
    {
        $before = time();
        $this->service->writeHeartbeat();
        $after  = time();

        $this->assertFileExists($this->heartbeatPath);

        $ts = (int) file_get_contents($this->heartbeatPath);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function test_clear_heartbeat_removes_file(): void
    {
        file_put_contents($this->heartbeatPath, time());
        $this->assertFileExists($this->heartbeatPath);

        $this->service->clearHeartbeat();
        $this->assertFileDoesNotExist($this->heartbeatPath);
    }

    public function test_is_running_returns_true_after_write_heartbeat(): void
    {
        $this->service->writeHeartbeat();
        $this->assertTrue($this->service->isRunning());
    }

    public function test_is_running_returns_false_after_clear_heartbeat_with_no_log(): void
    {
        $this->service->writeHeartbeat();
        $this->service->clearHeartbeat();
        $this->assertFalse($this->service->isRunning());
    }
}

<?php

namespace Tests\Unit\WorkerMonitor;

use App\Services\WorkerMonitorService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the stall-detection logic added to WorkerMonitorService in commit 3
 * of the queue-reliability pass. Each test constructs the service with
 * explicit tmp paths so the state space is fully deterministic.
 */
class WorkerMonitorStalledTest extends TestCase
{
    private string $tmpDir;
    private string $heartbeatPath;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0777, true);
        $this->heartbeatPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'heartbeat';
        $this->logPath       = $this->tmpDir . DIRECTORY_SEPARATOR . 'worker.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->heartbeatPath)) @unlink($this->heartbeatPath);
        if (is_file($this->logPath))       @unlink($this->logPath);
        if (is_dir($this->tmpDir))         @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function service(): WorkerMonitorService
    {
        return new WorkerMonitorService(
            heartbeatPath: $this->heartbeatPath,
            logPath:       $this->logPath,
            ttlOverride:   300,
        );
    }

    public function test_not_stalled_when_no_pending_jobs(): void
    {
        // Even with no heartbeat/log, empty queue = not stalled by definition.
        $this->assertFalse($this->service()->isStalled(pendingJobs: 0));
    }

    public function test_stalled_when_pending_and_no_signals(): void
    {
        $this->assertTrue($this->service()->isStalled(pendingJobs: 1));
    }

    public function test_not_stalled_when_heartbeat_is_fresh(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());
        $this->assertFalse($this->service()->isStalled(pendingJobs: 3));
    }

    public function test_stalled_when_heartbeat_exceeds_grace(): void
    {
        // Heartbeat is 3 minutes old; default grace is 120s.
        file_put_contents($this->heartbeatPath, (string) (time() - 180));
        $this->assertTrue($this->service()->isStalled(pendingJobs: 1));
    }

    public function test_not_stalled_when_log_is_fresh_and_no_heartbeat(): void
    {
        // Create a fresh log file, no heartbeat.
        file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s') . "] activity\n");
        $this->assertFalse($this->service()->isStalled(pendingJobs: 1));
    }

    public function test_stalled_when_log_stale_and_no_heartbeat(): void
    {
        file_put_contents($this->logPath, "old activity\n");
        // Force old mtime via touch().
        touch($this->logPath, time() - 200);
        $this->assertTrue($this->service()->isStalled(pendingJobs: 1));
    }

    public function test_heartbeat_age_reports_null_when_missing(): void
    {
        $this->assertNull($this->service()->heartbeatAgeSeconds());
    }

    public function test_heartbeat_age_reports_seconds_when_present(): void
    {
        file_put_contents($this->heartbeatPath, (string) (time() - 45));
        $this->assertGreaterThanOrEqual(45, $this->service()->heartbeatAgeSeconds());
        $this->assertLessThanOrEqual(50,  $this->service()->heartbeatAgeSeconds());
    }

    public function test_worker_log_age_reports_null_when_missing(): void
    {
        $this->assertNull($this->service()->workerLogAgeSeconds());
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Console\Commands\QueueRecoverCommand;
use App\Services\WorkerMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for queue:recover.
 *
 * The command delegates the drain step to queue:work --stop-when-empty which,
 * under the sync/array test harness, is effectively a no-op since jobs there
 * run inline. The tests therefore exercise control-flow branches:
 *   - healthy path (skip without action)
 *   - unhealthy path (invokes drain plan)
 *   - dry-run (no invocation)
 *   - lock-held path (second concurrent invocation exits safely)
 */
class QueueRecoverCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;
    private string $heartbeatPath;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qrc_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0777, true);
        $this->heartbeatPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'heartbeat';
        $this->logPath       = $this->tmpDir . DIRECTORY_SEPARATOR . 'worker.log';

        $this->app->bind(WorkerMonitorService::class, fn () => new WorkerMonitorService(
            heartbeatPath: $this->heartbeatPath,
            logPath:       $this->logPath,
            ttlOverride:   300,
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeatPath);
        @unlink($this->logPath);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_healthy_queue_exits_zero_without_running_recovery(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());

        $exit = Artisan::call('queue:recover');
        $output = Artisan::output();

        $this->assertSame(QueueRecoverCommand::EXIT_HEALTHY, $exit);
        $this->assertStringContainsString('Queue is healthy', $output);
        $this->assertStringNotContainsString('would call: artisan queue:restart', $output);
    }

    public function test_dry_run_reports_plan_without_executing(): void
    {
        // Force "critical" verdict: pending job but no worker.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'PendingJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 1000,
            'created_at'   => time() - 1000,
        ]);

        $exit = Artisan::call('queue:recover', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(QueueRecoverCommand::EXIT_HEALTHY, $exit);
        $this->assertStringContainsString('[dry-run] would call: artisan queue:restart', $output);
        $this->assertStringContainsString('[dry-run] would call: artisan queue:work', $output);
    }

    public function test_lock_prevents_concurrent_recovery(): void
    {
        // Use a cache driver that actually supports locks for this test.
        // 'array' driver does not implement LockProvider. Switch to 'file' for
        // this single test case.
        config(['cache.default' => 'file']);
        Cache::driver('file')->lock(QueueRecoverCommand::LOCK_KEY, 60)->get();

        // Force unhealthy so the path reaches the lock check.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'Stale']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 2000,
            'created_at'   => time() - 2000,
        ]);

        $exit = Artisan::call('queue:recover');

        $this->assertSame(QueueRecoverCommand::EXIT_LOCKED, $exit);

        // Release held lock to leave test env clean.
        Cache::driver('file')->lock(QueueRecoverCommand::LOCK_KEY)->forceRelease();
    }

    public function test_unhealthy_queue_runs_restart_and_drain_plan(): void
    {
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'Stale']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 2000,
            'created_at'   => time() - 2000,
        ]);

        $exit   = Artisan::call('queue:recover');
        $output = Artisan::output();

        // Both drain plan lines should appear, no dry-run prefix.
        $this->assertStringContainsString('would call: artisan queue:restart', $output);
        $this->assertStringContainsString('would call: artisan queue:work --stop-when-empty',  $output);
        $this->assertStringNotContainsString('[dry-run]', $output);

        // Under the sync queue driver `queue:work --stop-when-empty` exits
        // immediately (no worker loop); the command therefore reports recovered.
        $this->assertSame(QueueRecoverCommand::EXIT_RECOVERED, $exit);
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Console\Commands\QueueHealthCheckCommand;
use App\Services\WorkerMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for queue:health-check.
 *
 * Each test builds a deterministic DB + heartbeat file state, invokes the
 * command, and asserts both exit code and JSON output shape.
 */
class QueueHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;
    private string $heartbeatPath;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qhc_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0777, true);
        $this->heartbeatPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'heartbeat';
        $this->logPath       = $this->tmpDir . DIRECTORY_SEPARATOR . 'worker.log';

        // Bind a WorkerMonitorService with explicit paths so tests don't touch real storage.
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

    public function test_healthy_when_empty_queue_and_fresh_heartbeat(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());

        $exit = $this->artisan('queue:health-check', ['--json' => true])->run();

        $this->assertSame(QueueHealthCheckCommand::EXIT_HEALTHY, $exit);
    }

    public function test_critical_when_pending_jobs_but_no_worker(): void
    {
        // Heartbeat absent + log absent + pending job → no-worker critical.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'TestJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time(),
            'created_at'   => time() - 10,
        ]);

        $exit = $this->artisan('queue:health-check', ['--json' => true])->run();

        $this->assertSame(QueueHealthCheckCommand::EXIT_CRITICAL, $exit);
    }

    public function test_unhealthy_when_oldest_pending_exceeds_warn_threshold(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'OldJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 400,
            'created_at'   => time() - 400, // > PENDING_AGE_WARN_S (300)
        ]);

        $exit = $this->artisan('queue:health-check', ['--json' => true])->run();

        $this->assertSame(QueueHealthCheckCommand::EXIT_UNHEALTHY, $exit);
    }

    public function test_critical_when_oldest_pending_exceeds_crit_threshold(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'VeryOldJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 1000,
            'created_at'   => time() - 1000, // > PENDING_AGE_CRIT_S (900)
        ]);

        $exit = $this->artisan('queue:health-check', ['--json' => true])->run();

        $this->assertSame(QueueHealthCheckCommand::EXIT_CRITICAL, $exit);
    }

    public function test_json_output_shape(): void
    {
        file_put_contents($this->heartbeatPath, (string) time());

        $exit   = Artisan::call('queue:health-check', ['--json' => true]);
        $output = trim(Artisan::output());

        $this->assertSame(QueueHealthCheckCommand::EXIT_HEALTHY, $exit);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Output was not valid JSON: {$output}");

        $this->assertSame(0,          $decoded['pending_jobs']);
        $this->assertSame('HEALTHY',  $decoded['verdict']);
        $this->assertSame(0,          $decoded['exit_code']);
        $this->assertArrayHasKey('generating',  $decoded);
        $this->assertArrayHasKey('rams',        $decoded['generating']);
        $this->assertArrayHasKey('worker',      $decoded);
        $this->assertArrayHasKey('is_stalled',  $decoded['worker']);
        $this->assertArrayHasKey('thresholds',  $decoded);
    }
}

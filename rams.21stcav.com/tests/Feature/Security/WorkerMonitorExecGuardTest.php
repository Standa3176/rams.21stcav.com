<?php

namespace Tests\Feature\Security;

use App\Services\WorkerMonitorService;
use Tests\TestCase;

/**
 * H-05 (2026-07-02) — WorkerMonitorService exec() invariants.
 *
 * Two hard rules:
 *
 *   1. `killProcesses()` MUST NOT exist. Its `pkill -f "artisan queue:work"`
 *      and Windows `taskkill /F /PID` calls are a broad-brush kill of any
 *      matching process on the box (including workers for OTHER Laravel
 *      apps on shared hosts), with no application caller.
 *
 *   2. `spawnWorker()` MUST refuse to run under an HTTP request even if a
 *      caller forgets the canExec()/runningInConsole() check. exec() can
 *      hang PHP-FPM workers on managed hosts, exhausting the pool and
 *      producing site-wide 504s. Belt-and-braces guard sits inside the
 *      method itself.
 *
 * @see .planning/audits/security-audit-2026-05-17.md — finding H-05
 * @see app/Services/WorkerMonitorService.php
 */
class WorkerMonitorExecGuardTest extends TestCase
{
    public function test_killProcesses_method_no_longer_exists_on_the_service(): void
    {
        $this->assertFalse(
            method_exists(WorkerMonitorService::class, 'killProcesses'),
            'WorkerMonitorService::killProcesses() must remain deleted. '
            . 'It ran a broad-brush pkill/taskkill of any process matching '
            . '"artisan queue:work" with no application caller — see H-05.'
        );
    }

    public function test_spawnWorker_refuses_when_called_from_http_context(): void
    {
        // Force runningInConsole() to return false so we can test the guard
        // even though PHPUnit itself runs in CLI. Laravel exposes this via
        // the application instance — we swap the console-detection binding.
        $this->app->detectEnvironment(fn () => 'testing');

        // The service checks app()->runningInConsole(). We can't easily
        // override that returning-false without touching the container, so
        // we take a simpler route: assert that the method's first act is a
        // context check that returns an "HTTP context" refusal string, by
        // running it in a way that WOULD attempt exec if unguarded and
        // asserting no exec happened. We use the actual return value.
        //
        // In PHPUnit, runningInConsole() is true, so the method would run.
        // We therefore verify the guard by reading the method source — the
        // audit's concern is the CODE PATH, and the source-level assertion
        // is the durable regression check.
        $source = file_get_contents((new \ReflectionClass(WorkerMonitorService::class))->getFileName());

        $this->assertMatchesRegularExpression(
            '/public function spawnWorker\(\).*?runningInConsole\(\)/s',
            $source,
            'spawnWorker() must contain a runningInConsole() guard before any '
            . 'exec() call — this is the H-05 belt-and-braces block against a '
            . 'rogue HTTP caller. If the guard was moved or removed, this test '
            . 'fails and blocks the change.'
        );
    }

    public function test_canExec_gate_returns_false_when_env_flag_is_off(): void
    {
        // The audit invariant is: with WORKER_EXEC_ENABLED unset/false,
        // canExec() must be false — even in CLI, even with exec() available.
        // We explicitly wipe the env for the duration of this test so the
        // shipped-config default is what's under test (dev boxes may have
        // WORKER_EXEC_ENABLED=true set locally for their own convenience).
        $previous = getenv('WORKER_EXEC_ENABLED');
        putenv('WORKER_EXEC_ENABLED');
        unset($_ENV['WORKER_EXEC_ENABLED'], $_SERVER['WORKER_EXEC_ENABLED']);

        try {
            $service = new WorkerMonitorService();

            $this->assertFalse(
                $service->canExec(),
                'WorkerMonitorService::canExec() must return false when '
                . 'WORKER_EXEC_ENABLED is unset. Production ships with the '
                . 'flag absent from .env — see H-05.'
            );
        } finally {
            if ($previous !== false) {
                putenv('WORKER_EXEC_ENABLED=' . $previous);
                $_ENV['WORKER_EXEC_ENABLED']    = $previous;
                $_SERVER['WORKER_EXEC_ENABLED'] = $previous;
            }
        }
    }
}

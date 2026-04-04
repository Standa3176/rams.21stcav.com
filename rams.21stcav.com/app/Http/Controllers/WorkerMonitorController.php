<?php

namespace App\Http\Controllers;

use App\Services\WorkerMonitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin-only Worker Monitor.
 *
 * exec() hangs indefinitely on this managed host — using it from any
 * PHP-FPM worker (even in a shutdown function) permanently ties up that
 * worker, eventually exhausting the pool and causing 504s site-wide.
 *
 * This controller therefore contains ZERO exec() calls.
 * Start / Stop / Restart show the SSH command the admin must run manually,
 * or instruct the admin to use the cron job setup.
 */
class WorkerMonitorController extends Controller
{
    public function __construct(private readonly WorkerMonitorService $monitor) {}

    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.worker', [
            'isRunning'    => $this->monitor->isRunning(),
            'lastActivity' => $this->monitor->getLastActivity(),
            'logPath'      => $this->monitor->getLogPath(),
            'startCommand' => $this->monitor->startCommand(),
            'cronEntry'    => $this->monitor->cronEntry(),
        ]);
    }

    /**
     * "Start" — no exec(); just redirects back with the SSH command highlighted.
     */
    public function start(): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return redirect()
            ->route('admin.worker.index')
            ->with('action', 'start');
    }

    /**
     * "Stop" — writes the queue:restart signal file so the running worker
     * picks it up on its next loop iteration. No exec() needed.
     */
    public function stop(): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        // queue:restart works by writing a timestamp to the cache.
        // The running worker checks this on every loop and exits cleanly.
        try {
            \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', microtime(true));
        } catch (\Throwable) {
            // Cache may not be available; ignore — the worker will stop on its own.
        }

        $this->monitor->clearHeartbeat();

        return redirect()
            ->route('admin.worker.index')
            ->with('action', 'stop');
    }

    /**
     * "Restart" — sends the queue:restart cache signal (stops the running
     * worker gracefully) then redirects with the SSH start command shown.
     */
    public function restart(): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        try {
            \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', microtime(true));
        } catch (\Throwable) {
            // Ignore — same as stop().
        }

        $this->monitor->clearHeartbeat();

        return redirect()
            ->route('admin.worker.index')
            ->with('action', 'restart');
    }
}

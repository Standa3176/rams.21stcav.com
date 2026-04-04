@extends('layouts.app')

@section('title', 'Queue Worker Monitor')

@section('content')
<style>
.worker-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.worker-status-badge {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .4rem 1rem;
    border-radius: 999px;
    font-size: .85rem;
    font-weight: 600;
}
.worker-status-badge.running {
    background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;
}
.worker-status-badge.stopped {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
}
.worker-status-badge .dot {
    width: 9px; height: 9px; border-radius: 50%;
}
.running .dot { background: #16a34a; animation: blink 1.6s ease-in-out infinite; }
.stopped .dot { background: #dc2626; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.cmd-block {
    background: #1e293b;
    color: #7dd3fc;
    font-family: 'Courier New', monospace;
    font-size: .82rem;
    line-height: 1.6;
    padding: .85rem 1rem;
    border-radius: 6px;
    word-break: break-all;
    user-select: all;
    cursor: text;
    margin: .5rem 0 .5rem;
}
.log-block {
    background: #0f172a;
    color: #94a3b8;
    font-family: 'Courier New', monospace;
    font-size: .78rem;
    line-height: 1.6;
    padding: 1rem 1.25rem;
    border-radius: 6px;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 240px;
    overflow-y: auto;
}
.section-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #94a3b8;
    margin-bottom: .4rem;
}
.btn-group { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
.copy-btn {
    background: #334155; color: #e2e8f0; border: none; border-radius: 5px;
    padding: .3rem .7rem; font-size: .78rem; cursor: pointer;
}
.copy-btn:hover { background: #475569; }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 class="page-title" style="margin:0 0 .25rem;">Queue Worker Monitor</h1>
        <p style="margin:0;color:#64748b;font-size:.875rem;">Status is file-based — no exec() is used anywhere on this page.</p>
    </div>
    <a href="{{ route('admin.worker.index') }}" class="btn btn-outline btn-sm">↻ Refresh</a>
</div>

{{-- Action banners --}}
@if (session('action') === 'start')
<div class="worker-card" style="border-color:#a5b4fc;background:#eef2ff;">
    <p style="font-weight:600;color:#3730a3;margin:0 0 .4rem;">▶ To start the worker, run this command via SSH or the Plesk terminal:</p>
    <div class="cmd-block" id="startCmd">{{ $startCommand }}</div>
    <button class="copy-btn" onclick="copyCmd('startCmd')">📋 Copy</button>
    <p style="margin:.5rem 0 0;font-size:.8rem;color:#4338ca;">After running it, wait 10 seconds then refresh this page.</p>
</div>
@endif

@if (session('action') === 'stop')
<div class="alert alert-success" style="margin-bottom:1rem;">
    Stop signal sent via the queue cache key. The running worker will stop after it finishes its current job.
    If it does not stop within a minute, use <code>kill &lt;PID&gt;</code> via SSH.
</div>
@endif

@if (session('action') === 'restart')
<div class="worker-card" style="border-color:#a5b4fc;background:#eef2ff;">
    <p style="font-weight:600;color:#3730a3;margin:0 0 .4rem;">↺ Stop signal sent. Once the worker stops, run this to restart it:</p>
    <div class="cmd-block" id="restartCmd">{{ $startCommand }}</div>
    <button class="copy-btn" onclick="copyCmd('restartCmd')">📋 Copy</button>
</div>
@endif

{{-- Status card --}}
<div class="worker-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <div class="section-title">Worker Status <span style="font-weight:400;text-transform:none;font-size:.7rem;">(file-based detection)</span></div>
            <span class="worker-status-badge {{ $isRunning ? 'running' : 'stopped' }}">
                <span class="dot"></span>
                {{ $isRunning ? 'Running' : 'Stopped' }}
            </span>
            <p style="margin:.5rem 0 0;font-size:.78rem;color:#64748b;">
                Detected via heartbeat file and worker log timestamp. Updates within ~90 seconds of a change.
            </p>
        </div>

        <div class="btn-group">
            @if (! $isRunning)
                <form method="POST" action="{{ route('admin.worker.start') }}">
                    @csrf
                    <button class="btn btn-primary btn-sm">▶ Show Start Command</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.worker.restart') }}">
                    @csrf
                    <button class="btn btn-outline btn-sm"
                            onclick="return confirm('Send stop signal and show restart command?')">
                        ↺ Restart
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.worker.stop') }}">
                    @csrf
                    <button class="btn btn-danger-outline btn-sm"
                            onclick="return confirm('Send stop signal to the worker?')">
                        ⏹ Stop
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

{{-- Manual SSH command --}}
<div class="worker-card" style="border-color:#c7d2fe;background:#eef2ff;">
    <div class="section-title" style="color:#4338ca;">Start Worker via SSH / Plesk Terminal</div>
    <p style="font-size:.83rem;color:#3730a3;margin:0 0 .25rem;">
        Run this command on the server to start the worker (paste into Plesk Terminal or SSH):
    </p>
    <div class="cmd-block" id="manualStart">{{ $startCommand }}</div>
    <button class="copy-btn" onclick="copyCmd('manualStart')">📋 Copy command</button>
</div>

{{-- Cron setup --}}
<div class="worker-card" style="border-color:#bbf7d0;background:#f0fdf4;">
    <div class="section-title" style="color:#166534;">Recommended: Keep Worker Running with a Cron Job</div>
    <p style="font-size:.83rem;color:#14532d;margin:0 0 .4rem;">
        Add this to <strong>Plesk → Scheduled Tasks</strong> (every minute) so the worker restarts automatically if it stops:
    </p>
    <div class="cmd-block" id="cronEntry" style="color:#86efac;">{{ $cronEntry }}</div>
    <button class="copy-btn" onclick="copyCmd('cronEntry')">📋 Copy cron entry</button>
    <p style="font-size:.78rem;color:#166534;margin:.5rem 0 0;">
        <code>--stop-when-empty</code> — the worker processes all waiting jobs then exits cleanly.
        The cron fires again one minute later to pick up new jobs. No long-running processes.
    </p>
</div>

{{-- Log --}}
<div class="worker-card">
    <div class="section-title">Recent Worker Activity (last 10 lines of worker log)</div>
    <div class="log-block">{{ $lastActivity }}</div>
    <p style="margin:.4rem 0 0;font-size:.75rem;color:#94a3b8;">{{ $logPath }}</p>
</div>

{{-- Emergency Recovery --}}
<div class="worker-card" style="border-color:#fca5a5;background:#fef2f2;">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;">
        <span style="font-size:1.3rem;">🚨</span>
        <div class="section-title" style="color:#991b1b;margin:0;">Emergency Recovery — Site returning 504 / Gateway Timeout?</div>
    </div>
    <p style="font-size:.83rem;color:#7f1d1d;margin:0 0 .4rem;">
        If the whole site stops loading with a <strong>504 Gateway Timeout</strong>, it means PHP-FPM worker
        processes have become stuck. Run this command via <strong>SSH or the CWP terminal</strong> to
        instantly restart PHP-FPM and restore the site:
    </p>
    <div class="cmd-block" id="fpmRestart" style="color:#fca5a5;background:#1c0a0a;">systemctl restart php-fpm83</div>
    <button class="copy-btn" onclick="copyCmd('fpmRestart')" style="background:#7f1d1d;margin-bottom:.6rem;">📋 Copy command</button>
    <p style="font-size:.8rem;color:#991b1b;margin:0;">
        ⚠️ This command <strong>cannot be run as a page button</strong> — it restarts the PHP process
        that would handle the button click, so no response would ever come back.
        You must run it directly on the server. The site returns within a few seconds of running it.
    </p>
    <hr style="border:none;border-top:1px solid #fca5a5;margin:.75rem 0;">
    <p style="font-size:.8rem;color:#7f1d1d;margin:0;">
        <strong>Root cause:</strong> This server's PHP configuration causes <code>exec()</code> to hang
        indefinitely for any subprocess. This page no longer calls exec() anywhere — but if an older
        version of the worker code was running when this happened, use the command above to recover.
    </p>
</div>

{{-- Info --}}
<div class="worker-card" style="border-color:#fde68a;background:#fffbeb;">
    <div class="section-title" style="color:#92400e;">Why no auto-start button?</div>
    <p style="font-size:.83rem;color:#78350f;margin:0;">
        This server's PHP configuration causes any attempt to spawn a process from PHP
        (<code>exec()</code>, <code>proc_open()</code>, etc.) to hang indefinitely.
        Calling these from a web request would freeze that PHP-FPM worker permanently,
        eventually taking down the whole site. The Start button therefore shows the
        SSH command instead — run it manually or set up the cron job above.
    </p>
</div>

<script>
function copyCmd(id) {
    const text = document.getElementById(id).innerText.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Copied to clipboard.');
        }).catch(() => selectText(id));
    } else {
        selectText(id);
    }
}
function selectText(id) {
    const el = document.getElementById(id);
    const range = document.createRange();
    range.selectNode(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
}
</script>

{{-- Gentle auto-refresh every 30 s to keep status current --}}
<meta http-equiv="refresh" content="30">

@endsection

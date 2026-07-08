@extends('layouts.app')

@section('title', 'Queue Worker Monitor')

@section('content')
<style>
/*
 * Queue worker monitor — tier-one polish (2026-07-08).
 * .worker-card, .worker-status-badge, .cmd-block, .log-block, .section-title,
 * .copy-btn all retuned to semantic tokens. Terminal-look .cmd-block +
 * .log-block keep their dark chrome — the intent is "this is a shell
 * command, copy-and-paste it into your SSH" — but tuned to ink-900 base
 * instead of arbitrary slate.
 */

.worker-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 20px 24px;
    margin-bottom: 16px;
}
.worker-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: -0.005em;
}
.worker-status-badge.running {
    background: var(--success-light);
    color: var(--success);
    border: 1px solid color-mix(in oklab, var(--success) 30%, transparent);
}
.worker-status-badge.stopped {
    background: var(--danger-light);
    color: #991B1B;
    border: 1px solid color-mix(in oklab, var(--danger) 30%, transparent);
}
.worker-status-badge .dot {
    width: 7px; height: 7px;
    border-radius: 50%;
}
.running .dot {
    background: var(--success);
    box-shadow: 0 0 0 3px color-mix(in oklab, var(--success) 22%, transparent);
    animation: blink 1.6s ease-in-out infinite;
}
.stopped .dot { background: var(--danger); }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.35} }
@media (prefers-reduced-motion: reduce) {
    .running .dot { animation: none; }
}

.cmd-block {
    background: var(--ink-900);
    color: #7DD3FC;
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.6;
    padding: 12px 14px;
    border-radius: 8px;
    word-break: break-all;
    user-select: all;
    cursor: text;
    margin: 8px 0;
}
.log-block {
    background: var(--ink-900);
    color: var(--subtle);
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.6;
    padding: 14px 18px;
    border-radius: 8px;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 240px;
    overflow-y: auto;
}
.section-title {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    margin-bottom: 6px;
}
.btn-group { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
.copy-btn {
    background: var(--ink-2);
    color: var(--hairline);
    border: none;
    border-radius: 6px;
    padding: 5px 12px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background 120ms;
}
.copy-btn:hover { background: var(--body); }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Queue Worker Monitor</h1>
        <div class="page-subtitle">Status is file-based — no exec() is used anywhere on this page.</div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.worker.index') }}" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Refresh
        </a>
    </div>
</div>

{{-- Action banners --}}
@if (session('action') === 'start')
<div class="worker-card" style="border-color:var(--brand-100);background:var(--brand-50);">
    <p style="font-weight:600;color:var(--brand-800);margin:0 0 6px;font-size:13px;">To start the worker, run this command via SSH or the Plesk terminal:</p>
    <div class="cmd-block" id="startCmd">{{ $startCommand }}</div>
    <button class="copy-btn" onclick="copyCmd('startCmd')">Copy command</button>
    <p style="margin:8px 0 0;font-size:12px;color:var(--brand-700);">After running it, wait 10 seconds then refresh this page.</p>
</div>
@endif

@if (session('action') === 'stop')
<div class="alert alert-success" style="margin-bottom:16px;">
    Stop signal sent via the queue cache key. The running worker will stop after it finishes its current job.
    If it does not stop within a minute, use <code>kill &lt;PID&gt;</code> via SSH.
</div>
@endif

@if (session('action') === 'restart')
<div class="worker-card" style="border-color:var(--brand-100);background:var(--brand-50);">
    <p style="font-weight:600;color:var(--brand-800);margin:0 0 8px;font-size:13px;">Restart initiated</p>
    @if (session('exec_output'))
        <div class="section-title" style="color:var(--brand-700);">Output</div>
        <div class="log-block" style="color:var(--brand-100);background:var(--brand-800);">{{ session('exec_output') }}</div>
        <p style="margin:8px 0 0;font-size:12px;color:var(--brand-700);">
            Status badge will update within ~90 s if the worker is running.
            If you see ✗ errors above, check the logs or run the start command below manually.
        </p>
    @else
        <p style="margin:0 0 8px;color:var(--brand-700);font-size:13px;">
            Stop signal sent. Run the start command below to complete the restart.
        </p>
        <div class="cmd-block" id="restartCmd">{{ $startCommand }}</div>
        <button class="copy-btn" onclick="copyCmd('restartCmd')">Copy command</button>
    @endif
</div>
@endif

{{-- Status card --}}
<div class="worker-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div>
            <div class="section-title">Worker Status <span style="font-weight:400;text-transform:none;font-size:10px;">(file-based detection)</span></div>
            <span class="worker-status-badge {{ $isRunning ? 'running' : 'stopped' }}">
                <span class="dot"></span>
                {{ $isRunning ? 'Running' : 'Stopped' }}
            </span>
            <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted);">
                Detected via heartbeat file and worker log timestamp. Updates within ~90 seconds of a change.
            </p>
        </div>

        <div class="btn-group">
            <form method="POST" action="{{ route('admin.worker.restart') }}"
                  data-confirm="Send queue restart signal? The worker will reload its code on the next job cycle."
                  data-confirm-label="Restart">
                @csrf
                <button class="btn btn-outline btn-sm">
                    ↺ Restart Worker
                </button>
            </form>
            @if ($isRunning)
                <form method="POST" action="{{ route('admin.worker.stop') }}"
                      data-confirm="Send stop signal to the worker?"
                      data-confirm-label="Stop"
                      data-confirm-danger="1">
                    @csrf
                    <button class="btn btn-danger-outline btn-sm">
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
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Copied to clipboard.');
        }).catch(() => fallbackCopy(id, text));
    } else {
        fallbackCopy(id, text);
    }
}
function fallbackCopy(id, text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        alert('Copied to clipboard.');
    } catch (e) {
        // Last resort: highlight the element so the user can Ctrl+C manually
        const el = document.getElementById(id);
        const range = document.createRange();
        range.selectNode(el);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        alert('Select + Ctrl+C to copy (clipboard API not available on HTTP).');
    }
    document.body.removeChild(ta);
}
</script>

{{-- Gentle auto-refresh every 30 s to keep status current --}}
<meta http-equiv="refresh" content="30">

@endsection

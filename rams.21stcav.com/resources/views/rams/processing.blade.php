@extends('layouts.app')

@section('title', 'Processing Quote')

@section('content')

<style>
/* ── Layout ─────────────────────────────────────────────────────── */
.proc-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 58vh;
    text-align: center;
    gap: 1.5rem;
    padding: 2rem 1rem;
}

/* ── Spinner ────────────────────────────────────────────────────── */
.proc-spinner {
    width: 56px;
    height: 56px;
    border: 5px solid #e2e8f0;
    border-top-color: #007B8A;
    border-radius: 50%;
    animation: spin 0.9s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Text ───────────────────────────────────────────────────────── */
.proc-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.proc-sub {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0;
}
.proc-hint {
    font-size: 0.8rem;
    color: #94a3b8;
    margin: 0;
}

/* ── Dot progress ───────────────────────────────────────────────── */
.proc-dots {
    display: flex;
    gap: 0.45rem;
    justify-content: center;
}
.proc-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #cbd5e1;
    transition: background 0.3s;
}
.proc-dot.active { background: #007B8A; }

/* ── Action card (shown after timeout) ─────────────────────────── */
.proc-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1.75rem 2rem;
    max-width: 460px;
    width: 100%;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.proc-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.5rem;
}
.proc-card-body {
    font-size: 0.875rem;
    color: #475569;
    margin: 0 0 1.25rem;
    line-height: 1.6;
}

/* ── Error state ────────────────────────────────────────────────── */
.proc-error-icon { font-size: 2.5rem; }
</style>

<div class="proc-wrapper" id="procWrapper">

    {{-- ── Waiting state (visible by default) ─────────────────────── --}}
    <div id="waitingState">
        <div class="proc-spinner" id="procSpinner"></div>
    </div>

    <div id="waitingText">
        <p class="proc-title">Processing your quote…</p>
        <p class="proc-sub">Extracting data from the PDF. This usually takes under 10 seconds.</p>

        {{-- 5-dot progress indicator — dots light up as polls run ──── --}}
        <div class="proc-dots" id="procDots" style="margin-top:1rem;">
            <span class="proc-dot" id="dot0"></span>
            <span class="proc-dot" id="dot1"></span>
            <span class="proc-dot" id="dot2"></span>
            <span class="proc-dot" id="dot3"></span>
            <span class="proc-dot" id="dot4"></span>
        </div>

        <p class="proc-hint" style="margin-top:0.75rem;" id="pollStatus">Checking status…</p>
    </div>

    {{-- ── Still-processing state (shown after 10 s) ──────────────── --}}
    <div id="stillState" style="display:none;">
        <div class="proc-card">
            <p class="proc-card-title">⏳ Still processing…</p>
            <p class="proc-card-body">
                Extraction is taking a little longer than usual — the PDF may be large or the
                queue worker is busy. The document will appear on your project page once ready.
            </p>
            <a href="{{ $projectUrl }}" class="btn btn-teal" style="margin-bottom:.75rem;display:inline-block;">
                View Project
            </a>
            <br>
            <button class="btn btn-outline btn-sm" onclick="restartPolling()" style="margin-top:.5rem;">
                Keep checking for me
            </button>
        </div>
    </div>

    {{-- ── Error state ──────────────────────────────────────────────── --}}
    <div id="errorState" style="display:none;">
        <div class="proc-card" style="border-color:#fca5a5;">
            <p class="proc-error-icon">⚠️</p>
            <p class="proc-card-title" style="color:#991b1b;">Extraction failed</p>
            <p class="proc-card-body">
                Something went wrong while reading your PDF. Please return to the project page
                and retry the extraction, or re-upload the quote.
            </p>
            <a href="{{ $projectUrl }}" class="btn btn-outline" style="border-color:#fca5a5;color:#991b1b;">
                Return to Project
            </a>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const POLL_URL    = '{{ route("rams.check-ready", $rams) }}';
    const MAX_POLLS   = 5;     // 5 × 2 s = 10 seconds before "still processing"
    const POLL_MS     = 2000;  // interval between polls

    let pollCount = 0;
    let timerId   = null;

    // ── DOM refs ───────────────────────────────────────────────────
    const dots       = Array.from(document.querySelectorAll('.proc-dot'));
    const statusEl   = document.getElementById('pollStatus');
    const waitingEl  = document.getElementById('waitingText');
    const waitState  = document.getElementById('waitingState');
    const stillEl    = document.getElementById('stillState');
    const errorEl    = document.getElementById('errorState');

    // ── Poll ───────────────────────────────────────────────────────
    function poll() {
        // Light up the next dot.
        if (pollCount < dots.length) {
            dots[pollCount].classList.add('active');
        }

        statusEl.textContent = 'Checking… (' + (pollCount + 1) + ' of ' + MAX_POLLS + ')';

        fetch(POLL_URL, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            credentials: 'same-origin',
        })
        .then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        })
        .then(function (data) {
            if (data.ready) {
                statusEl.textContent = 'Ready! Redirecting…';
                window.location.href = data.review_url;
                return;
            }

            if (data.failed) {
                showError();
                return;
            }

            // Not ready yet.
            pollCount++;

            if (pollCount >= MAX_POLLS) {
                showStillProcessing();
            } else {
                timerId = setTimeout(poll, POLL_MS);
            }
        })
        .catch(function () {
            // Network error — treat as "not ready yet" rather than hard failure.
            pollCount++;
            if (pollCount >= MAX_POLLS) {
                showStillProcessing();
            } else {
                timerId = setTimeout(poll, POLL_MS);
            }
        });
    }

    // ── State transitions ──────────────────────────────────────────
    function showStillProcessing() {
        clearTimeout(timerId);
        waitingEl.style.display = 'none';
        waitState.style.display = 'none';
        stillEl.style.display   = 'block';
    }

    function showError() {
        clearTimeout(timerId);
        waitingEl.style.display = 'none';
        waitState.style.display = 'none';
        errorEl.style.display   = 'block';
    }

    // Exposed globally so the "Keep checking" button can restart polling.
    window.restartPolling = function () {
        pollCount = 0;
        dots.forEach(function (d) { d.classList.remove('active'); });
        stillEl.style.display   = 'none';
        waitingEl.style.display = 'block';
        waitState.style.display = 'block';
        statusEl.textContent    = 'Checking status…';
        timerId = setTimeout(poll, POLL_MS);
    };

    // ── Start polling after first interval ────────────────────────
    timerId = setTimeout(poll, POLL_MS);
})();
</script>
@endpush

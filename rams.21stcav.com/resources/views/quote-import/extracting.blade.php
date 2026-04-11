@extends('layouts.app')

@section('title', 'Extracting Quote Data')

@section('content')
<div class="page-header">
    <div class="page-header__body">
        <h1 class="page-title">Extracting Quote Data</h1>
        <p class="page-subtitle">Parsing your PDF locally. This usually takes a few seconds.</p>
    </div>
</div>

<div class="card" style="max-width:480px;margin:2rem auto;text-align:center;padding:2.5rem 2rem;">
    <div id="spinner" style="margin-bottom:1.5rem;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--teal,#178A95)"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             style="animation:spin 1.2s linear infinite;display:inline-block;">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
            <line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/>
            <line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>
            <line x1="2" y1="12" x2="6" y2="12"/>
            <line x1="18" y1="12" x2="22" y2="12"/>
            <line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/>
            <line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>
        </svg>
    </div>

    <p style="font-size:1rem;font-weight:500;color:var(--text-primary,#1a1a2e);margin-bottom:.5rem;">
        {{ $package->quote_filename }}
    </p>
    <p id="status-label" style="font-size:.875rem;color:var(--text-muted,#6b7280);">
        Parsing quote data…
    </p>
</div>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<script>
(function () {
    const statusUrl = '{{ route('quote-import.extract-status', $package) }}';
    const startedAt = Date.now();

    function elapsed() {
        return Math.round((Date.now() - startedAt) / 1000);
    }

    function poll() {
        fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.terminal) {
                    if (data.redirect) {
                        document.getElementById('status-label').textContent = 'Done! Redirecting…';
                        window.location.href = data.redirect;
                    } else {
                        document.getElementById('status-label').textContent = 'Extraction failed — redirecting…';
                        window.location.href = '{{ route('quote-import.create') }}';
                    }
                } else {
                    document.getElementById('status-label').textContent =
                        'Extracting… (' + elapsed() + 's)';
                    setTimeout(poll, 2000);
                }
            })
            .catch(() => {
                document.getElementById('status-label').textContent =
                    'Extracting… (' + elapsed() + 's)';
                setTimeout(poll, 4000);
            });
    }

    // First poll after 2 s; timer ticks every 2 s thereafter.
    setTimeout(poll, 2000);
})();
</script>
@endsection

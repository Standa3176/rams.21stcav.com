@extends('layouts.app')

@section('title', 'Import Quote')

@section('content')

<div class="page-header">
    <h1 class="page-title">Import Quote</h1>
    <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Projects</a>
</div>

@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif
@if (session('warning'))
    <div class="alert alert-warning">
        {{ session('warning') }}
        @if (session('qw_force_url'))
            <a href="{{ session('qw_force_url') }}"
               onclick="event.preventDefault(); document.getElementById('qwForceForm').submit();"
               style="margin-left:.5rem; font-weight:600; text-decoration:underline;">
                Continue anyway →
            </a>
            {{-- Hidden POST form so the "force import" submission is CSRF-safe
                 and travels via the same route as the initial lookup (matches
                 route middleware throttle + form-request validation). --}}
            <form method="POST" action="{{ route('quotewerks.lookup') }}" id="qwForceForm" style="display:none;">
                @csrf
                <input type="hidden" name="reference" value="{{ session('qw_last_reference') }}">
                <input type="hidden" name="force" value="1">
            </form>
        @endif
    </div>
@endif

{{-- ── Dual-tab: Upload PDF | QuoteWerks Lookup (audit UI-04 2026-07-08).
     Tab strip retuned — was flex with no gap so the two labels ran into
     each other on some viewports. Alpine :style attr binding replaced
     with class binding + `.qi-tab.is-active` for a clean 20px underline
     accent that matches .rams-tabs / .psv-tabs elsewhere in the app. --}}
<style>
    .qi-tabs {
        display: flex;
        gap: 24px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 20px;
    }
    .qi-tab {
        padding: 10px 0;
        border: none;
        background: none;
        cursor: pointer;
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-muted);
        position: relative;
        margin-bottom: -1px;
        transition: color 120ms;
    }
    .qi-tab:hover { color: var(--ink-900); }
    .qi-tab.is-active { color: var(--teal-700); font-weight: 600; }
    .qi-tab.is-active::after {
        content: "";
        position: absolute;
        left: 0; right: 0; bottom: 0;
        height: 2px;
        background: var(--teal-700);
        border-radius: 2px 2px 0 0;
    }

    .qi-dropzone {
        border: 2px dashed var(--teal-500);
        border-radius: 8px;
        padding: 32px 24px;
        text-align: center;
        cursor: pointer;
        transition: background 150ms, border-color 150ms;
        background: color-mix(in oklab, var(--teal-100) 20%, var(--card));
    }
    .qi-dropzone:hover { border-color: var(--teal-700); background: color-mix(in oklab, var(--teal-100) 35%, var(--card)); }
    /* Re-audit UX-03 — visible keyboard focus so tabbing here isn't silent. */
    .qi-dropzone:focus-visible {
        outline: none;
        border-color: var(--accent-600);
        box-shadow: var(--shadow-focus);
    }
    .qi-dropzone.is-drag { border-color: var(--success); background: color-mix(in oklab, var(--success-light) 60%, var(--card)); }
    .qi-dropzone.has-file { border-color: var(--success); background: color-mix(in oklab, var(--success-light) 40%, var(--card)); }
    .qi-dropzone-label { color: var(--body); font-size: 13px; }
    .qi-dropzone-label label { color: var(--teal-700); cursor: pointer; font-weight: 600; }
    .qi-dropzone-selected { color: var(--success-700); font-weight: 600; font-size: 13px; }
    .qi-dropzone svg { display: inline-block; margin-bottom: 8px; color: var(--teal-700); }
</style>

<div x-data="{ importTab: 'pdf' }" style="max-width:680px;">

    <div class="qi-tabs" role="tablist">
        <button type="button" @click="importTab='pdf'"
                class="qi-tab" :class="{ 'is-active': importTab==='pdf' }"
                role="tab" :aria-selected="importTab==='pdf'">
            Upload PDF
        </button>
        <button type="button" @click="importTab='quotewerks'"
                class="qi-tab" :class="{ 'is-active': importTab==='quotewerks' }"
                role="tab" :aria-selected="importTab==='quotewerks'">
            QuoteWerks Lookup
        </button>
    </div>

    {{-- ── PDF Upload Tab ─────────────────────────────────────────────── --}}
    <div x-show="importTab==='pdf'" role="tabpanel">

<div class="card" style="max-width:680px;">
    <form method="POST" action="{{ route('quote-import.store') }}" enctype="multipart/form-data" id="importForm">
        @csrf

        {{-- ── Quote PDF ──────────────────────────────────────────────────── --}}
        <div class="form-group">
            <label class="form-label" for="quote_pdf">
                QuoteWerks PDF <span class="req">*</span>
            </label>

            {{-- Re-audit UX-03 — the file input is display:none, so keyboard
                 users could only trigger upload via the small inline
                 "click to browse" label. Added role=button + tabindex=0 to
                 the drop zone and wired Enter/Space to click the hidden
                 input, so the whole tile becomes a proper button. --}}
            <div id="dropZone"
                 class="qi-dropzone"
                 role="button"
                 tabindex="0"
                 aria-label="Upload QuoteWerks PDF — press Enter or Space to open the file picker, or drop a PDF file onto this area"
                 onclick="document.getElementById('quote_pdf').click()"
                 onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); document.getElementById('quote_pdf').click(); }">
                <div id="dropLabel" class="qi-dropzone-label">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <path d="M14 2v6h6"/><path d="M12 18v-6M9 15l3 3 3-3"/>
                    </svg>
                    <div>
                        Drag &amp; drop your QuoteWerks PDF here, or
                        <label for="quote_pdf">click to browse</label>
                    </div>
                </div>
                <div id="fileSelected" style="display:none;" class="qi-dropzone-selected"></div>
            </div>

            <input id="quote_pdf" name="quote_pdf" type="file" accept=".pdf"
                   style="display:none;"
                   class="@error('quote_pdf') is-invalid @enderror">
            @error('quote_pdf')
                <div class="invalid-feedback" style="display:block; margin-top:.25rem;">{{ $message }}</div>
            @enderror

            <p class="form-help">Max 20 MB. Must be a PDF exported directly from QuoteWerks.</p>
        </div>

        {{-- ── Project assignment ─────────────────────────────────────────── --}}
        <div class="form-group" style="margin-top:1.25rem;">
            <label class="form-label">Link to Project</label>

            <div style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-start;">
                <div style="flex:1; min-width:200px;">
                    {{-- audit UI-05 — data-optional stops the "empty required" coral
                         paint from firing on the "New project (auto-create)"
                         default, which is a valid selection. --}}
                    <select name="project_id" id="project_id" class="form-control" data-optional>
                        <option value="">— New project (auto-create) —</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}"
                                {{ (string) $selectedProjectId === (string) $p->id ? 'selected' : '' }}>
                                {{ $p->name }}
                                @if($p->ref) ({{ $p->ref }}) @endif
                                — {{ $p->client_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <p class="form-help">
                Select an existing project to add this quote revision to it,
                or leave blank to auto-create a new project from the extracted data.
            </p>
            <input type="hidden" name="create_project" value="1">
        </div>

        {{-- ── AI Provider ────────────────────────────────────────────────── --}}
        <details style="margin-top:1rem;">
            <summary style="cursor:pointer; font-size:.85rem; color:#555; user-select:none;">
                AI Provider (optional)
            </summary>
            <div style="margin-top:.75rem;">
                <select name="ai_provider" class="form-control" style="max-width:200px;">
                    <option value="">Default ({{ ucfirst($defaultProvider) }})</option>
                    <option value="claude"  {{ old('ai_provider') === 'claude'  ? 'selected' : '' }}>Claude (Anthropic)</option>
                    <option value="openai"  {{ old('ai_provider') === 'openai'  ? 'selected' : '' }}>OpenAI GPT-4o</option>
                </select>
            </div>
        </details>

        {{-- ── Actions ─────────────────────────────────────────────────────── --}}
        <div style="display:flex; gap:.75rem; margin-top:1.5rem; align-items:center;">
            <button type="submit" class="btn btn-teal" id="submitBtn">
                Extract &amp; Review
            </button>
            <a href="{{ route('projects.index') }}" class="btn btn-outline">Cancel</a>
            <span id="loadingText" style="display:none; font-size:.85rem; color:#666;">
                Extracting data from PDF…
            </span>
        </div>
    </form>
</div>

{{-- ── Drag-and-drop + progress JS ──────────────────────────────────────── --}}
<script>
(function () {
    const zone       = document.getElementById('dropZone');
    const input      = document.getElementById('quote_pdf');
    const dropLabel  = document.getElementById('dropLabel');
    const fileSelected = document.getElementById('fileSelected');
    const form       = document.getElementById('importForm');
    const submitBtn  = document.getElementById('submitBtn');
    const loadingText = document.getElementById('loadingText');

    function showFile(name) {
        dropLabel.style.display  = 'none';
        fileSelected.style.display = '';
        fileSelected.textContent = '✓ ' + name;
        // audit UI-04 — class toggles replace inline hex so the drag/drop
        // states inherit the tier-one token palette.
        zone.classList.remove('is-drag');
        zone.classList.add('has-file');
    }

    zone.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        if (input.files[0]) showFile(input.files[0].name);
    });

    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.classList.add('is-drag');
    });

    zone.addEventListener('dragleave', () => {
        zone.classList.remove('is-drag');
    });

    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('is-drag');
        const file = e.dataTransfer.files[0];
        if (file && file.type === 'application/pdf') {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            showFile(file.name);
        }
    });

    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Extracting…';
        loadingText.style.display = '';
    });
})();
</script>

    </div>{{-- end PDF Upload tab panel --}}

    {{-- ── QuoteWerks Lookup Tab (D-10, D-11) ────────────────────────── --}}
    <div x-show="importTab==='quotewerks'" role="tabpanel">
        <div class="card">

            {{-- Quick lookup by reference --}}
            <h2 class="section-heading" style="font-size:.9rem; margin:0 0 1rem;">Lookup by Reference</h2>
            <form method="POST" action="{{ route('quotewerks.lookup') }}" id="qwLookupForm">
                @csrf
                <div class="form-group">
                    <label class="form-label" for="qw_reference">QuoteWerks Reference <span class="req">*</span></label>
                    <div style="display:flex; gap:.75rem; align-items:flex-start;">
                        <input type="text" name="reference" id="qw_reference" class="form-control"
                               placeholder="e.g. 21CQ14213 or 21CQ29531-05-OPS"
                               value="{{ old('reference', session('qw_last_reference')) }}"
                               style="max-width:300px;">
                        <button type="submit" class="btn btn-teal">Import</button>
                    </div>
                    @error('reference')
                        <div class="invalid-feedback" style="display:block; margin-top:.25rem;">{{ $message }}</div>
                    @enderror
                    <p class="form-help">
                        Live revision only — matches on DocNo OR RevisionMasterDocNo where Superceeded = 0.
                        <br>
                        <span style="color:var(--text-faint); font-size:.8rem;">
                            QuoteWerks is only available on the live VPS via the office tunnel.
                        </span>
                    </p>
                </div>
            </form>

            {{-- Search by client --}}
            <hr style="margin:1.5rem 0; border-color:var(--border);">
            <h2 class="section-heading" style="font-size:.9rem; margin:0 0 1rem;">Search by Client</h2>
            <form method="POST" action="{{ route('quotewerks.search') }}" id="qwSearchForm">
                @csrf
                <div style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                        <label class="form-label" for="qw_client">Client Name</label>
                        <input type="text" name="client" id="qw_client" class="form-control"
                               placeholder="Search client name..."
                               value="{{ old('client', session('qw_search_query')) }}">
                    </div>
                    <div class="form-group" style="min-width:160px; margin:0;">
                        <label class="form-label" for="qw_date_from">From Date</label>
                        <input type="date" name="date_from" id="qw_date_from" class="form-control"
                               value="{{ old('date_from') }}">
                    </div>
                    <button type="submit" class="btn btn-outline" style="margin-bottom:0;">Search</button>
                </div>
                @error('client')
                    <div class="invalid-feedback" style="display:block; margin-top:.5rem;">{{ $message }}</div>
                @enderror
            </form>

            {{-- Search results (flashed from QuoteWerksImportController::search) --}}
            @php($searchResults = session('qw_search_results'))
            @if(is_array($searchResults) && count($searchResults) > 0)
                <table class="data-table" style="margin-top:1rem; font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Client</th>
                            <th>Date</th>
                            <th>Subject</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($searchResults as $result)
                        <tr>
                            <td style="font-weight:600;">{{ $result['doc_no'] }}</td>
                            <td>{{ $result['client_name'] }}</td>
                            <td style="white-space:nowrap; color:var(--text-faint); font-size:.8rem;">{{ $result['doc_date'] ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($result['subject'] ?? '', 40) }}</td>
                            <td>
                                <form method="POST" action="{{ route('quotewerks.lookup') }}" style="margin:0;">
                                    @csrf
                                    <input type="hidden" name="reference" value="{{ $result['doc_no'] }}">
                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">Import</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif(is_array($searchResults))
                <p style="color:var(--text-muted); font-size:.875rem; margin-top:1rem;">No quotes found matching your search.</p>
            @endif
        </div>
    </div>{{-- end QuoteWerks Lookup tab panel --}}

</div>{{-- end x-data dual-tab wrapper --}}

@endsection

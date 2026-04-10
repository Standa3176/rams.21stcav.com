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
    <div class="alert alert-warning">{{ session('warning') }}</div>
@endif

{{-- ── Dual-tab: Upload PDF | QuoteWerks Lookup (D-10) ────────────────── --}}
<div x-data="{ importTab: 'pdf' }" style="max-width:680px;">

    {{-- Tab strip --}}
    <div style="display:flex; border-bottom:1px solid var(--border); margin-bottom:1.25rem;">
        <button @click="importTab='pdf'"
                style="padding:.75rem 1.25rem; border:none; background:none; cursor:pointer; font-size:.9375rem; font-weight:600; border-bottom:2px solid transparent;"
                :style="importTab==='pdf' ? 'border-bottom-color:var(--teal); color:var(--teal)' : 'color:var(--text-muted)'"
                role="tab" :aria-selected="importTab==='pdf'">
            Upload PDF
        </button>
        <button @click="importTab='quotewerks'"
                style="padding:.75rem 1.25rem; border:none; background:none; cursor:pointer; font-size:.9375rem; font-weight:600; border-bottom:2px solid transparent;"
                :style="importTab==='quotewerks' ? 'border-bottom-color:var(--teal); color:var(--teal)' : 'color:var(--text-muted)'"
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

            <div id="dropZone" style="
                border: 2px dashed #007B8A;
                border-radius: 8px;
                padding: 2rem 1.5rem;
                text-align: center;
                cursor: pointer;
                transition: background .15s;
                background: #f8fdfd;
            ">
                <div id="dropLabel" style="color:#666; font-size:.9rem;">
                    <span style="font-size:1.5rem; display:block; margin-bottom:.4rem;">📄</span>
                    Drag &amp; drop your QuoteWerks PDF here, or
                    <label for="quote_pdf" style="color:#007B8A; cursor:pointer; font-weight:600;">
                        click to browse
                    </label>
                </div>
                <div id="fileSelected" style="display:none; color:#007B8A; font-weight:600; font-size:.9rem;"></div>
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
                    <select name="project_id" id="project_id" class="form-control">
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
        zone.style.borderColor = '#28a745';
        zone.style.background  = '#f0fff4';
    }

    zone.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        if (input.files[0]) showFile(input.files[0].name);
    });

    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.style.background = '#e8f8f9';
    });

    zone.addEventListener('dragleave', () => {
        zone.style.background = '#f8fdfd';
    });

    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.style.background = '#f8fdfd';
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
                               placeholder="e.g. 21CQ12345" value="{{ old('reference') }}"
                               style="max-width:300px;">
                        <button type="submit" class="btn btn-teal">Import</button>
                    </div>
                    @error('reference')
                        <div class="invalid-feedback" style="display:block; margin-top:.25rem;">{{ $message }}</div>
                    @enderror
                    <p class="form-help">Enter the QuoteWerks document number to import directly from the database.</p>
                </div>
            </form>

            {{-- Search by client --}}
            <hr style="margin:1.5rem 0; border-color:var(--border);">
            <h2 class="section-heading" style="font-size:.9rem; margin:0 0 1rem;">Search by Client</h2>
            <form method="GET" action="{{ route('quotewerks.search') }}" id="qwSearchForm">
                <div style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                        <label class="form-label" for="qw_client">Client Name</label>
                        <input type="text" name="client" id="qw_client" class="form-control"
                               placeholder="Search client name..." value="{{ request('client') }}">
                    </div>
                    <div class="form-group" style="min-width:160px; margin:0;">
                        <label class="form-label" for="qw_date_from">From Date</label>
                        <input type="date" name="date_from" id="qw_date_from" class="form-control"
                               value="{{ request('date_from') }}">
                    </div>
                    <button type="submit" class="btn btn-outline" style="margin-bottom:0;">Search</button>
                </div>
            </form>

            {{-- Search results --}}
            @if(isset($searchResults) && count($searchResults) > 0)
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
            @elseif(isset($searchResults))
                <p style="color:var(--text-muted); font-size:.875rem; margin-top:1rem;">No quotes found matching your search.</p>
            @endif
        </div>
    </div>{{-- end QuoteWerks Lookup tab panel --}}

</div>{{-- end x-data dual-tab wrapper --}}

@endsection

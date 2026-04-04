@extends('layouts.app')

@section('title', 'Upload Quote PDF')

@section('content')

<div class="page-header">
    <h1 class="page-title">Upload QuoteWerks PDF</h1>
    <a href="{{ route('rams.index') }}" class="btn btn-outline">← Back to Documents</a>
</div>

{{-- Flash messages --}}
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- Info banner --}}
<div class="alert alert-info" style="margin-bottom:1.5rem;">
    <strong>Automatic extraction:</strong> Upload a QuoteWerks PDF and the AI will read the quote,
    extract project details, line items and room solutions, then generate a complete RAMS document
    ready for download as a Word file.
</div>

<div class="section-block">

    <h2 class="section-heading">Select Quote PDF</h2>

    <form method="POST"
          action="{{ route('rams.upload.store') }}"
          enctype="multipart/form-data"
          id="uploadForm">
        @csrf

        {{-- Drop zone --}}
        <div class="drop-zone" id="dropZone">
            <div class="drop-zone-icon">📄</div>
            <p class="drop-zone-label">Drag &amp; drop your QuoteWerks PDF here</p>
            <p class="drop-zone-sub">or</p>
            <label for="quote_pdf" class="btn btn-outline" style="cursor:pointer;">
                Browse file…
            </label>
            <input type="file"
                   name="quote_pdf"
                   id="quote_pdf"
                   accept=".pdf,application/pdf"
                   style="display:none;"
                   required>
            <p class="drop-zone-hint">PDF only · maximum 20 MB</p>
        </div>

        {{-- Selected file preview --}}
        <div id="filePreview" style="display:none; margin-top:1rem;">
            <div class="file-chip">
                <span class="file-chip-icon">📎</span>
                <span id="fileName"></span>
                <span id="fileSize" class="file-chip-size"></span>
                <button type="button" class="file-chip-remove" onclick="clearFile()">✕</button>
            </div>
        </div>

        @error('quote_pdf')
            <p class="invalid-feedback" style="display:block; margin-top:.5rem;">{{ $message }}</p>
        @enderror

        {{-- What happens next --}}
        <div class="pipeline-steps" style="margin-top:1.5rem;">
            <div class="step">
                <span class="step-num">1</span>
                <span>AI reads the PDF &amp; extracts project details, SKUs, quantities and room solutions</span>
            </div>
            <div class="step">
                <span class="step-num">2</span>
                <span>AI generates a full UK RAMS document including hazard register &amp; control measures</span>
            </div>
            <div class="step">
                <span class="step-num">3</span>
                <span>Branded Word document downloads automatically — ready to review and edit</span>
            </div>
        </div>

        <div style="margin-top:1.75rem;">
            <button type="submit"
                    id="submitBtn"
                    class="btn btn-teal btn-full"
                    style="max-width:320px;">
                Generate RAMS from Quote
            </button>
        </div>

    </form>

    {{-- Progress overlay (shown while AI is running) --}}
    <div id="progressOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,50,60,.82);z-index:9999;flex-direction:column;align-items:center;justify-content:center;gap:1.25rem;">
        <div style="display:flex;gap:.6rem;align-items:center;">
            <span id="progressDot" style="width:14px;height:14px;border-radius:50%;background:#5de0ee;transition:background .4s;display:inline-block;"></span>
            <span id="progressLabel" style="color:#fff;font-size:1.05rem;font-weight:500;"></span>
        </div>
        <p style="color:rgba(255,255,255,.6);font-size:.875rem;">This may take 30–60 seconds…</p>
    </div>


</div>

{{-- Or divider to manual form --}}
<div style="text-align:center; color:#888; margin:1rem 0; font-size:.875rem;">
    — or —
</div>
<div style="text-align:center;">
    <a href="{{ route('rams.create') }}" class="btn btn-outline">
        Fill in the RAMS form manually
    </a>
</div>

@endsection

@push('styles')
<style>
    /* ── Drop zone ──────────────────────────────────────────── */
    .drop-zone {
        border: 2.5px dashed #ccd;
        border-radius: 8px;
        padding: 2.5rem 1.5rem;
        text-align: center;
        background: #fafbff;
        transition: border-color .2s, background .2s;
        cursor: pointer;
    }
    .drop-zone.drag-over {
        border-color: #007B8A;
        background: #edf8f9;
    }
    .drop-zone-icon { font-size: 2.5rem; margin-bottom: .5rem; }
    .drop-zone-label { font-size: 1rem; font-weight: 600; color: #333; margin-bottom: .25rem; }
    .drop-zone-sub   { font-size: .875rem; color: #888; margin: .35rem 0; }
    .drop-zone-hint  { font-size: .8rem; color: #aaa; margin-top: .75rem; }

    /* ── File chip ──────────────────────────────────────────── */
    .file-chip {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        background: #e9f6f7;
        border: 1.5px solid #007B8A;
        border-radius: 20px;
        padding: .35rem .9rem;
        font-size: .875rem;
        color: #007B8A;
        font-weight: 500;
    }
    .file-chip-icon { font-size: 1rem; }
    .file-chip-size { color: #555; font-weight: 400; font-size: .8rem; }
    .file-chip-remove {
        background: transparent;
        border: none;
        color: #c0392b;
        cursor: pointer;
        font-size: .9rem;
        padding: 0 .2rem;
        line-height: 1;
    }

    /* ── Pipeline steps ─────────────────────────────────────── */
    .pipeline-steps {
        display: flex;
        flex-direction: column;
        gap: .75rem;
    }
    .step {
        display: flex;
        align-items: flex-start;
        gap: .75rem;
        font-size: .9rem;
        color: #444;
    }
    .step-num {
        flex-shrink: 0;
        width: 26px;
        height: 26px;
        background: #007B8A;
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .8rem;
        font-weight: 700;
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const input      = document.getElementById('quote_pdf');
    const dropZone   = document.getElementById('dropZone');
    const filePreview = document.getElementById('filePreview');
    const fileName   = document.getElementById('fileName');
    const fileSize   = document.getElementById('fileSize');
    const submitBtn  = document.getElementById('submitBtn');
    const loadingMsg = document.getElementById('loadingMsg');

    // ── Drag-and-drop ────────────────────────────────────────
    dropZone.addEventListener('click', () => input.click());

    ['dragenter', 'dragover'].forEach(evt =>
        dropZone.addEventListener(evt, e => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        })
    );
    ['dragleave', 'drop'].forEach(evt =>
        dropZone.addEventListener(evt, () => dropZone.classList.remove('drag-over'))
    );

    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        const file = e.dataTransfer.files[0];
        if (file && file.type === 'application/pdf') {
            setFile(file);
        }
    });

    // ── File input change ────────────────────────────────────
    input.addEventListener('change', () => {
        if (input.files[0]) setFile(input.files[0]);
    });

    function setFile(file) {
        // Push the file into the real input via DataTransfer
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;

        fileName.textContent = file.name;
        fileSize.textContent = '(' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        filePreview.style.display = 'block';
        dropZone.style.display    = 'none';
    }

    window.clearFile = function () {
        input.value           = '';
        filePreview.style.display = 'none';
        dropZone.style.display    = 'block';
    };

    // ── Animated progress overlay on submit ─────────────────
    const STEPS = [
        'Uploading files\u2026',
        'Reading quote PDF\u2026',
        'Extracting project scope\u2026',
        'Identifying hazards\u2026',
        'Building method statement\u2026',
        'Finalising document\u2026',
    ];

    const overlay = document.getElementById('progressOverlay');
    const dotEl   = document.getElementById('progressDot');
    const labelEl = document.getElementById('progressLabel');

    document.getElementById('uploadForm').addEventListener('submit', function () {
        if (!input.files[0]) return;
        submitBtn.disabled = true;
        overlay.style.display = 'flex';

        let step = 0;
        function advance() {
            dotEl.style.background = '#5de0ee';
            labelEl.textContent    = STEPS[step];
            step++;
            if (step < STEPS.length) {
                setTimeout(advance, step === 1 ? 3000 : 5000);
            }
        }
        setTimeout(advance, 400);
    });
})();
</script>
@endpush

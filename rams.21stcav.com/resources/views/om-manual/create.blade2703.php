@extends('layouts.app')

@section('title', 'New O&M Manual')

@push('styles')
<style>
/* Drop zone ─────────────────────────────────────────────────────────── */
.drop-zone {
    border: 2px dashed #ccc;
    border-radius: 6px;
    padding: 2.5rem 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: #fafafa;
    position: relative;
}
.drop-zone:hover,
.drop-zone.drag-over { border-color: #007B8A; background: #f0fafb; }
.drop-zone input[type=file] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}
.drop-zone .dz-icon { font-size: 2.5rem; margin-bottom: .5rem; }
.drop-zone .dz-text { color: #555; font-size: .9rem; }
.drop-zone .dz-hint { color: #999; font-size: .8rem; margin-top: .25rem; }
.drop-zone .dz-selected {
    color: #007B8A;
    font-weight: 600;
    margin-top: .5rem;
    font-size: .875rem;
}

/* Progress overlay ──────────────────────────────────────────────────── */
.loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}
.loading-overlay.visible { display: flex; }
.loading-box {
    background: #fff;
    border-radius: 8px;
    padding: 2rem 2.5rem;
    text-align: center;
    max-width: 380px;
    width: 90%;
    box-shadow: 0 8px 32px rgba(0,0,0,.2);
}
.loading-box h3 { color: #007B8A; margin-bottom: .5rem; font-size: 1.05rem; }
.loading-box p  { color: #555; font-size: .875rem; margin-bottom: 1.25rem; }

/* Step list ─────────────────────────────────────────────────────────── */
.step-list { list-style: none; text-align: left; margin: 0 auto; max-width: 260px; }
.step-list li {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .3rem 0;
    font-size: .875rem;
    color: #666;
    transition: color .3s;
}
.step-list li.active  { color: #007B8A; font-weight: 600; }
.step-list li.done    { color: #28a745; }
.step-icon { font-size: 1rem; width: 20px; text-align: center; }

/* Spinner ───────────────────────────────────────────────────────────── */
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
    width: 28px; height: 28px;
    border: 3px solid #e0f4f6;
    border-top-color: #007B8A;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    margin: 0 auto 1rem;
}

/* Progress bar ──────────────────────────────────────────────────────── */
.progress-bar-wrap {
    height: 4px;
    background: #e0e0e0;
    border-radius: 2px;
    margin-top: 1rem;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: #007B8A;
    border-radius: 2px;
    transition: width .8s ease;
    width: 0%;
}
</style>
@endpush

@section('content')

    <div class="page-header">
        <h1 class="page-title">New O&amp;M Manual</h1>
        <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
    </div>

    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    {{-- How it works callout --}}
    <div class="alert alert-info" style="margin-bottom:1.5rem;">
        <strong>Two-step process:</strong>
        <strong>Step 1</strong> — Upload the QuoteWerks quote PDF. AI extracts the project details and all installed equipment.
        <strong>Step 2</strong> — Review &amp; edit the equipment list, then click <em>Generate Manual</em> to produce the complete Word document.
    </div>

    <div class="card">
        <div class="section-heading">Upload QuoteWerks Quote PDF</div>

        <form method="POST"
              action="{{ route('om-manuals.store') }}"
              enctype="multipart/form-data"
              id="om-upload-form">
            @csrf

            {{-- PDF drop zone --}}
            <div class="form-group">
                <label class="form-label">
                    QuoteWerks Quote PDF <span class="req">*</span>
                </label>
                <div class="drop-zone" id="pdf-drop-zone">
                    <input type="file"
                           name="quote_pdf"
                           id="quote_pdf"
                           accept=".pdf,application/pdf"
                           required>
                    <div class="dz-icon">📄</div>
                    <div class="dz-text">Click or drag &amp; drop your QuoteWerks PDF</div>
                    <div class="dz-hint">PDF only · max 20 MB</div>
                    <div class="dz-selected" id="pdf-selected-name"></div>
                </div>
                @error('quote_pdf')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Optional project link --}}
            <div class="form-group">
                <label class="form-label" for="project_id">Link to Project <span style="color:#999; font-weight:400;">(optional)</span></label>
                <select name="project_id" id="project_id" class="form-control" style="max-width:420px;">
                    <option value="">— Standalone (no project) —</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}"
                            {{ (string) $selectedProjectId === (string) $p->id ? 'selected' : '' }}>
                            {{ $p->name }}
                            @if($p->ref) ({{ $p->ref }}) @endif
                            — {{ $p->client_name }}
                        </option>
                    @endforeach
                </select>
                <p class="form-help">Linking attaches this manual to the project's document history.</p>
            </div>

            {{-- AI Provider selector (collapsed) --}}
            <details class="secondary-section" style="margin-bottom:1.25rem;">
                <summary>AI Provider (optional)</summary>
                <div class="details-body">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Provider</label>
                        <select name="ai_provider" class="form-control" style="max-width:220px;">
                            <option value="claude"  {{ $defaultProvider === 'claude'  ? 'selected' : '' }}>Claude (Anthropic)</option>
                            <option value="openai"  {{ $defaultProvider === 'openai'  ? 'selected' : '' }}>OpenAI GPT-4</option>
                        </select>
                    </div>
                </div>
            </details>

            <button type="submit" class="btn btn-teal btn-full" id="upload-btn">
                Extract Equipment from PDF →
            </button>
        </form>
    </div>

    {{-- Loading overlay --}}
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <h3>Analysing Quote PDF…</h3>
            <p>The AI is reading your quote and extracting all installed equipment. This usually takes 20–40 seconds.</p>
            <ul class="step-list" id="step-list">
                <li class="active"><span class="step-icon">→</span> Reading PDF document</li>
                <li><span class="step-icon">○</span> Identifying rooms &amp; areas</li>
                <li><span class="step-icon">○</span> Extracting equipment list</li>
                <li><span class="step-icon">○</span> Structuring project data</li>
            </ul>
            <div class="progress-bar-wrap">
                <div class="progress-bar-fill" id="progress-fill"></div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
// ── File input display ────────────────────────────────────────────────
const fileInput = document.getElementById('quote_pdf');
const selectedName = document.getElementById('pdf-selected-name');
const dropZone = document.getElementById('pdf-drop-zone');

fileInput.addEventListener('change', function () {
    selectedName.textContent = this.files[0] ? '✓ ' + this.files[0].name : '';
});

['dragenter', 'dragover'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    });
});
['dragleave', 'drop'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
    });
});
dropZone.addEventListener('drop', function (e) {
    const files = e.dataTransfer.files;
    if (files.length) {
        // Create a DataTransfer to assign to the file input
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        fileInput.files = dt.files;
        selectedName.textContent = '✓ ' + files[0].name;
    }
});

// ── Progress animation on submit ──────────────────────────────────────
const form    = document.getElementById('om-upload-form');
const overlay = document.getElementById('loading-overlay');
const fill    = document.getElementById('progress-fill');
const steps   = document.querySelectorAll('#step-list li');

const stepMessages = [
    'Reading PDF document',
    'Identifying rooms & areas',
    'Extracting equipment list',
    'Structuring project data',
];

let currentStep = 0;
let animInterval = null;

function advanceStep() {
    if (currentStep < steps.length) {
        steps[currentStep].classList.remove('active');
        steps[currentStep].classList.add('done');
        steps[currentStep].querySelector('.step-icon').textContent = '✓';
    }
    currentStep++;
    if (currentStep < steps.length) {
        steps[currentStep].classList.add('active');
        steps[currentStep].querySelector('.step-icon').textContent = '→';
    }
    // Advance progress bar
    fill.style.width = Math.min(90, (currentStep / steps.length) * 100) + '%';
}

form.addEventListener('submit', function (e) {
    if (! fileInput.files.length) return; // let native validation handle it
    overlay.classList.add('visible');
    fill.style.width = '5%';

    // Auto-advance steps every ~8 seconds (total ~32s for 4 steps)
    animInterval = setInterval(advanceStep, 8000);
});
</script>
@endpush

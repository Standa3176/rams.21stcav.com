@extends('layouts.app')

@section('title', 'New Cable Schedule')

@section('content')

<div class="page-header">
    <h1 class="page-title">New Cable Schedule</h1>
    <a href="{{ route('cable-schedules.index') }}" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="alert alert-info">
    Upload a QuoteWerks PDF and the AI will analyse the quote to generate a draft cable schedule.
    You can review and edit it on the next screen.
</div>

@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please correct the following:</strong>
        <ul style="margin:.5rem 0 0 1.2rem;font-size:.875rem;">
            @foreach ($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('cable-schedules.store') }}" enctype="multipart/form-data" id="cs-form">
    @csrf

    {{-- Project details --}}
    <div class="section-block">
        <h2 class="section-heading">Project Details</h2>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="project_name">Project Name <span class="req">*</span></label>
                <input type="text" id="project_name" name="project_name" class="form-control @error('project_name') is-invalid @enderror"
                       value="{{ old('project_name') }}" required maxlength="200">
                @error('project_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label" for="project_ref">Project Reference</label>
                <input type="text" id="project_ref" name="project_ref" class="form-control"
                       value="{{ old('project_ref') }}" maxlength="50" placeholder="e.g. 21CQ-25001">
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client Name</label>
                <input type="text" id="client_name" name="client_name" class="form-control"
                       value="{{ old('client_name') }}" maxlength="150">
            </div>
        </div>
    </div>

    {{-- Upload --}}
    <div class="section-block">
        <h2 class="section-heading">Quote PDF</h2>

        <div class="drop-zone" id="dropZone">
            <div class="drop-zone-icon">📄</div>
            <p class="drop-zone-label">Drag &amp; drop your QuoteWerks PDF here</p>
            <p class="drop-zone-sub">or</p>
            <label for="quote_pdf" class="btn btn-outline" style="cursor:pointer;">Browse file…</label>
            <input type="file" name="quote_pdf" id="quote_pdf" accept=".pdf,application/pdf"
                   style="display:none;" required>
            <p class="drop-zone-hint">PDF only · max 20 MB</p>
        </div>

        <div id="filePreview" style="display:none;margin-top:1rem;">
            <div class="file-chip">
                <span>📎</span>
                <span id="fileName"></span>
                <span id="fileSize" style="color:#555;font-size:.8rem;"></span>
                <button type="button" onclick="clearFile()"
                        style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:.9rem;">✕</button>
            </div>
        </div>

        @error('quote_pdf')
            <p class="invalid-feedback" style="display:block;margin-top:.5rem;">{{ $message }}</p>
        @enderror
    </div>

    <div style="margin-top:1rem;">
        <button type="submit" id="submitBtn" class="btn btn-teal" style="min-width:220px;">
            Generate Cable Schedule
        </button>
    </div>

    {{-- Loading overlay --}}
    <div id="loadingOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,50,60,.82);z-index:9999;flex-direction:column;align-items:center;justify-content:center;gap:1rem;">
        <div style="width:36px;height:36px;border:3px solid rgba(255,255,255,.3);border-top-color:#5de0ee;border-radius:50%;animation:spin .8s linear infinite;"></div>
        <p style="color:#fff;font-size:1rem;font-weight:500;">Analysing quote and generating cable schedule…</p>
        <p style="color:rgba(255,255,255,.6);font-size:.875rem;">This may take 30–60 seconds.</p>
    </div>

</form>

@endsection

@push('styles')
<style>
.drop-zone {
    border: 2.5px dashed #ccd; border-radius: 8px; padding: 2.5rem 1.5rem;
    text-align: center; background: #fafbff; cursor: pointer;
    transition: border-color .2s, background .2s;
}
.drop-zone.drag-over { border-color: #007B8A; background: #edf8f9; }
.drop-zone-icon { font-size: 2.5rem; margin-bottom: .5rem; }
.drop-zone-label { font-size: 1rem; font-weight: 600; color: #333; margin-bottom: .25rem; }
.drop-zone-sub { font-size: .875rem; color: #888; margin: .35rem 0; }
.drop-zone-hint { font-size: .8rem; color: #aaa; margin-top: .75rem; }
.file-chip {
    display: inline-flex; align-items: center; gap: .5rem;
    background: #e9f6f7; border: 1.5px solid #007B8A;
    border-radius: 20px; padding: .35rem .9rem;
    font-size: .875rem; color: #007B8A; font-weight: 500;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const input    = document.getElementById('quote_pdf');
    const dropZone = document.getElementById('dropZone');
    const preview  = document.getElementById('filePreview');
    const nameEl   = document.getElementById('fileName');
    const sizeEl   = document.getElementById('fileSize');

    dropZone.addEventListener('click', () => input.click());
    ['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag-over'); }));
    ['dragleave','drop'].forEach(e => dropZone.addEventListener(e, () => dropZone.classList.remove('drag-over')));
    dropZone.addEventListener('drop', e => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f && f.type==='application/pdf') setFile(f); });
    input.addEventListener('change', () => { if (input.files[0]) setFile(input.files[0]); });

    function setFile(file) {
        const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
        nameEl.textContent = file.name;
        sizeEl.textContent = '(' + (file.size/1024/1024).toFixed(2) + ' MB)';
        preview.style.display = 'block';
        dropZone.style.display = 'none';
    }

    window.clearFile = function () {
        input.value = '';
        preview.style.display = 'none';
        dropZone.style.display = 'block';
    };

    document.getElementById('cs-form').addEventListener('submit', function () {
        if (!input.files[0]) return;
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('loadingOverlay').style.display = 'flex';
    });
})();
</script>
@endpush

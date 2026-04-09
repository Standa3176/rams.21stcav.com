@extends('layouts.app')

@section('title', 'Review Equipment — ' . $manual->project_name)

@push('styles')
<style>
/* Project fields ─────────────────────────────────────────────────────── */
.project-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

/* Room card ─────────────────────────────────────────────────────────── */
.room-card {
    background: #fff;
    border: 1.5px solid #ddd;
    border-radius: 6px;
    margin-bottom: 1rem;
    overflow: hidden;
}
.room-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f0fafb;
    border-bottom: 1.5px solid #ddd;
    padding: .65rem 1rem;
    gap: .75rem;
}
.room-card-header .room-name-input {
    flex: 1;
    font-weight: 700;
    font-size: .95rem;
    border: 1.5px solid transparent;
    border-radius: 3px;
    padding: .2rem .4rem;
    background: transparent;
    color: #007B8A;
}
.room-card-header .room-name-input:focus {
    outline: none;
    border-color: #007B8A;
    background: #fff;
}
.room-card-body { padding: .75rem 1rem; }

/* Equipment table ────────────────────────────────────────────────────── */
.eq-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.eq-table th {
    font-size: .775rem;
    font-weight: 600;
    color: #555;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .35rem .5rem;
    border-bottom: 2px solid #eee;
    text-align: left;
    background: #fafafa;
}
.eq-table td { padding: .35rem .5rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.eq-table tbody tr:last-child td { border-bottom: none; }
.eq-table input.form-control { font-size: .85rem; padding: .25rem .45rem; }
.eq-table input[type=number] { width: 60px; }

/* Add / remove buttons ───────────────────────────────────────────────── */
.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    padding: .15rem .35rem;
    border-radius: 3px;
    line-height: 1;
    color: #c0392b;
    transition: background .15s;
}
.btn-icon:hover { background: #fde8e8; }
.btn-icon.add { color: #007B8A; }
.btn-icon.add:hover { background: #e0f4f6; }

/* Generate button ────────────────────────────────────────────────────── */
.generate-bar {
    position: sticky;
    bottom: 0;
    background: #fff;
    border-top: 2px solid #007B8A;
    padding: .85rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    box-shadow: 0 -2px 8px rgba(0,0,0,.08);
    z-index: 100;
    border-radius: 0 0 6px 6px;
}

/* Loading overlay ────────────────────────────────────────────────────── */
.loading-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
.loading-overlay.visible { display:flex; }
.loading-box { background:#fff; border-radius:8px; padding:2rem 2.5rem; text-align:center; max-width:380px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,.2); }
.loading-box h3 { color:#007B8A; margin-bottom:.5rem; font-size:1.05rem; }
.loading-box p  { color:#555; font-size:.875rem; margin-bottom:1rem; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner { width:30px; height:30px; border:3px solid #e0f4f6; border-top-color:#007B8A; border-radius:50%; animation:spin .7s linear infinite; margin:0 auto 1rem; }
.step-list { list-style:none; text-align:left; margin:0 auto; max-width:280px; }
.step-list li { display:flex; align-items:center; gap:.6rem; padding:.3rem 0; font-size:.875rem; color:#666; transition:color .3s; }
.step-list li.active { color:#007B8A; font-weight:600; }
.step-list li.done   { color:#28a745; }
.progress-bar-wrap { height:4px; background:#e0e0e0; border-radius:2px; margin-top:1rem; overflow:hidden; }
.progress-bar-fill { height:100%; background:#007B8A; border-radius:2px; transition:width .8s ease; width:0%; }

@media (max-width: 768px) {
    .project-grid { grid-template-columns: 1fr; }
    .eq-table td:nth-child(4),
    .eq-table th:nth-child(4) { display: none; }
}
</style>
@endpush

@section('content')

    <div class="page-header">
        <h1 class="page-title">Review Equipment — {{ $manual->project_name }}</h1>
        <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="alert alert-info">
        <strong>Review the extracted data below.</strong>
        Check room names and equipment are correct, edit as needed, then click <strong>Generate Manual</strong>
        to run the AI and build your Word document. The generation step takes up to 2 minutes.
    </div>

    {{-- ── Project details ──────────────────────────────────────────────── --}}
    <div class="card">
        <div class="section-heading">Project Details</div>
        <div class="project-grid" id="project-fields">
            <div class="form-group">
                <label class="form-label">Project Name <span class="req">*</span></label>
                <input type="text" class="form-control" id="proj_name"
                       value="{{ $manual->project_name }}" maxlength="200">
            </div>
            <div class="form-group">
                <label class="form-label">Reference</label>
                <input type="text" class="form-control" id="proj_ref"
                       value="{{ $manual->project_ref ?? '' }}" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label">Client</label>
                <input type="text" class="form-control" id="proj_client"
                       value="{{ $manual->client_name ?? '' }}" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label">Site Address</label>
                <input type="text" class="form-control" id="proj_site"
                       value="{{ $manual->site_address ?? '' }}" maxlength="500">
            </div>
        </div>
    </div>

    {{-- ── Rooms & Equipment ────────────────────────────────────────────── --}}
    <div class="card">
        <div class="section-heading" style="display:flex;align-items:center;justify-content:space-between;">
            <span>Rooms &amp; Equipment</span>
            <button type="button" class="btn btn-outline btn-sm" id="add-room-btn">+ Add Room</button>
        </div>

        <div id="rooms-container">
            @php
                $rooms = $manual->extracted_data['rooms'] ?? [];
            @endphp

            @foreach ($rooms as $ri => $room)
            <div class="room-card" data-room="{{ $ri }}">
                <div class="room-card-header">
                    <input type="text"
                           class="room-name-input"
                           placeholder="Room / Area name"
                           value="{{ $room['name'] ?? '' }}">
                    <input type="text"
                           class="form-control"
                           style="max-width:90px;font-size:.8rem;"
                           placeholder="Drg ref"
                           title="Drawing reference"
                           value="{{ $room['drawing_ref'] ?? '' }}">
                    <button type="button" class="btn-icon remove-room-btn" title="Remove room">✕</button>
                </div>
                <div class="room-card-body">
                    <table class="eq-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">Qty</th>
                                <th>Description</th>
                                <th>Model</th>
                                <th>Part No.</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody class="eq-rows">
                            @foreach ($room['equipment'] ?? [] as $eq)
                            <tr>
                                <td><input type="number" class="form-control" min="1" value="{{ $eq['qty'] ?? 1 }}"></td>
                                <td><input type="text"   class="form-control" placeholder="e.g. Display" value="{{ $eq['description'] ?? '' }}"></td>
                                <td><input type="text"   class="form-control" placeholder="e.g. Samsung QB65C" value="{{ $eq['model'] ?? '' }}"></td>
                                <td><input type="text"   class="form-control" placeholder="Part no." value="{{ $eq['part_no'] ?? '' }}"></td>
                                <td><button type="button" class="btn-icon remove-eq-btn" title="Remove row">✕</button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-outline btn-sm add-eq-btn"
                            style="margin-top:.6rem;">+ Add Equipment Row</button>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── Sticky action bar ─────────────────────────────────────────────── --}}
    <div class="card" style="padding:0;overflow:visible;">
        <div class="generate-bar">
            <div style="font-size:.875rem;color:#555;">
                Save your edits, then generate the complete O&amp;M Manual (Word document).
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                {{-- Save (update extracted_data only) --}}
                <form method="POST"
                      action="{{ route('om-manuals.update', $manual) }}"
                      id="save-form"
                      style="margin:0;">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="extracted_json" id="extracted_json_save">
                    <button type="submit" class="btn btn-outline" id="save-btn">
                        💾 Save Changes
                    </button>
                </form>

                {{-- Generate (Pass 2 AI + docx) --}}
                <form method="POST"
                      action="{{ route('om-manuals.generate', $manual) }}"
                      id="generate-form"
                      style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-teal" id="generate-btn">
                        ⚡ Generate Manual
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Loading overlay (shown when generate is clicked) --}}
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <h3>Generating O&amp;M Manual…</h3>
            <p>The AI is writing operating procedures, maintenance schedules, fault-finding guides and more. Please wait.</p>
            <ul class="step-list" id="gen-step-list">
                <li class="active"><span class="gen-step-icon">→</span> Writing operating procedures</li>
                <li><span class="gen-step-icon">○</span> Building maintenance schedule</li>
                <li><span class="gen-step-icon">○</span> Creating fault-finding guide</li>
                <li><span class="gen-step-icon">○</span> Compiling network &amp; manufacturer info</li>
                <li><span class="gen-step-icon">○</span> Building Word document</li>
            </ul>
            <div class="progress-bar-wrap">
                <div class="progress-bar-fill" id="gen-progress-fill"></div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
// ── Helpers ───────────────────────────────────────────────────────────

function newEquipmentRow() {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="number" class="form-control" min="1" value="1"></td>
        <td><input type="text"   class="form-control" placeholder="e.g. Display"></td>
        <td><input type="text"   class="form-control" placeholder="e.g. Samsung QB65C"></td>
        <td><input type="text"   class="form-control" placeholder="Part no."></td>
        <td><button type="button" class="btn-icon remove-eq-btn" title="Remove row">✕</button></td>
    `;
    return tr;
}

function newRoomCard() {
    const div = document.createElement('div');
    div.className = 'room-card';
    div.innerHTML = `
        <div class="room-card-header">
            <input type="text" class="room-name-input" placeholder="Room / Area name" value="New Room">
            <input type="text" class="form-control" style="max-width:90px;font-size:.8rem;" placeholder="Drg ref" title="Drawing reference" value="">
            <button type="button" class="btn-icon remove-room-btn" title="Remove room">✕</button>
        </div>
        <div class="room-card-body">
            <table class="eq-table">
                <thead>
                    <tr>
                        <th style="width:50px;">Qty</th>
                        <th>Description</th>
                        <th>Model</th>
                        <th>Part No.</th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody class="eq-rows"></tbody>
            </table>
            <button type="button" class="btn btn-outline btn-sm add-eq-btn" style="margin-top:.6rem;">+ Add Equipment Row</button>
        </div>
    `;
    // Add one default empty row
    div.querySelector('.eq-rows').appendChild(newEquipmentRow());
    return div;
}

// ── Event delegation ──────────────────────────────────────────────────

const container = document.getElementById('rooms-container');

container.addEventListener('click', function (e) {
    // Remove equipment row
    if (e.target.classList.contains('remove-eq-btn')) {
        const row = e.target.closest('tr');
        const tbody = row.closest('tbody');
        if (tbody.querySelectorAll('tr').length > 1) {
            row.remove();
        } else {
            alert('Each room needs at least one equipment row.');
        }
    }
    // Remove room
    if (e.target.classList.contains('remove-room-btn')) {
        const card = e.target.closest('.room-card');
        if (container.querySelectorAll('.room-card').length > 1) {
            card.remove();
        } else {
            alert('The manual needs at least one room.');
        }
    }
    // Add equipment row
    if (e.target.classList.contains('add-eq-btn')) {
        const tbody = e.target.closest('.room-card-body').querySelector('.eq-rows');
        tbody.appendChild(newEquipmentRow());
    }
});

document.getElementById('add-room-btn').addEventListener('click', function () {
    container.appendChild(newRoomCard());
    // Scroll the new room into view
    container.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

// ── Serialise form to JSON ────────────────────────────────────────────

function serialiseData() {
    const project = {
        name:   document.getElementById('proj_name').value.trim(),
        ref:    document.getElementById('proj_ref').value.trim(),
        client: document.getElementById('proj_client').value.trim(),
        site:   document.getElementById('proj_site').value.trim(),
    };

    const rooms = [];
    container.querySelectorAll('.room-card').forEach(card => {
        const header = card.querySelector('.room-card-header');
        const inputs = header.querySelectorAll('input');
        const roomName   = inputs[0].value.trim();
        const drawingRef = inputs[1].value.trim();

        const equipment = [];
        card.querySelectorAll('.eq-rows tr').forEach(row => {
            const cols = row.querySelectorAll('input');
            equipment.push({
                qty:         parseInt(cols[0].value) || 1,
                description: cols[1].value.trim(),
                model:       cols[2].value.trim(),
                part_no:     cols[3].value.trim(),
            });
        });

        rooms.push({ name: roomName, drawing_ref: drawingRef, equipment });
    });

    return JSON.stringify({ project, rooms });
}

// ── Save form ─────────────────────────────────────────────────────────

document.getElementById('save-form').addEventListener('submit', function () {
    document.getElementById('extracted_json_save').value = serialiseData();
});

// ── Generate form ─────────────────────────────────────────────────────
// Save data first, then allow generate to submit; show loading overlay.

document.getElementById('generate-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    // Serialise once; reuse in both the save payload and the hidden save input
    const serialised  = serialiseData();

    // Auto-save first to persist any edits before generation
    const savePayload = new FormData(document.getElementById('save-form'));
    savePayload.set('extracted_json', serialised);

    try {
        await fetch(document.getElementById('save-form').action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: savePayload,
        });
    } catch (_) {
        // Non-fatal: proceed even if the save-first request fails
    }

    // Show loading animation
    const overlay   = document.getElementById('loading-overlay');
    const fill      = document.getElementById('gen-progress-fill');
    const steps     = document.querySelectorAll('#gen-step-list li');
    let   step      = 0;

    overlay.classList.add('visible');
    fill.style.width = '5%';

    const interval = setInterval(() => {
        if (step < steps.length) {
            steps[step].classList.remove('active');
            steps[step].classList.add('done');
            steps[step].querySelector('.gen-step-icon').textContent = '✓';
        }
        step++;
        if (step < steps.length) {
            steps[step].classList.add('active');
            steps[step].querySelector('.gen-step-icon').textContent = '→';
        }
        fill.style.width = Math.min(90, (step / steps.length) * 100) + '%';
    }, 20000); // advance every 20s for ~5 steps = ~100s total

    // Now actually submit the generate form
    this.submit();
});
</script>
@endpush

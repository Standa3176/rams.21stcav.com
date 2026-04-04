@extends('layouts.app')

@section('title', 'New Site Survey')

@section('content')

<div class="page-header">
    <h1 class="page-title">New Site Survey</h1>
    <div style="display:flex;gap:.5rem;">
        <a href="{{ route('site-surveys.blank-form') }}" class="btn btn-outline btn-sm" target="_blank">&#128438; Blank Form PDF</a>
        <a href="{{ route('site-surveys.index') }}" class="btn btn-outline btn-sm">&#8592; Back</a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please correct the following:</strong>
        <ul style="margin:.5rem 0 0 1.2rem;font-size:.875rem;">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('site-surveys.store') }}" id="survey-form">
    @csrf

    {{-- Project Details --}}
    <div class="section-block">
        <h2 class="section-heading">Project Details</h2>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="project_id">Link to Project</label>
                <select id="project_id" name="project_id" class="form-control">
                    <option value="">— Standalone (no project) —</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" {{ (old('project_id', $selectedProjectId) == $p->id) ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="project_name">Project Name <span class="req">*</span></label>
                <input type="text" id="project_name" name="project_name"
                       class="form-control @error('project_name') is-invalid @enderror"
                       value="{{ old('project_name') }}" required maxlength="200">
                @error('project_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label" for="project_ref">Reference</label>
                <input type="text" id="project_ref" name="project_ref" class="form-control"
                       value="{{ old('project_ref') }}" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client Name</label>
                <input type="text" id="client_name" name="client_name" class="form-control"
                       value="{{ old('client_name') }}" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label" for="surveyor_name">Surveyor Name</label>
                <input type="text" id="surveyor_name" name="surveyor_name" class="form-control"
                       value="{{ old('surveyor_name') }}" maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label" for="survey_date">Survey Date</label>
                <input type="date" id="survey_date" name="survey_date" class="form-control"
                       value="{{ old('survey_date', date('Y-m-d')) }}">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="site_address">Site Address</label>
            <textarea id="site_address" name="site_address" class="form-control" rows="2" maxlength="500">{{ old('site_address') }}</textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="general_notes">General Notes</label>
            <textarea id="general_notes" name="general_notes" class="form-control" rows="3" maxlength="3000">{{ old('general_notes') }}</textarea>
        </div>
    </div>

    {{-- Rooms --}}
    <div class="section-block">
        <h2 class="section-heading">Rooms / Areas</h2>
        <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
            Add each room or area to be surveyed. You can upload photos after saving.
        </p>

        <div id="rooms-container">
            @php $roomsOld = old('rooms', [[]]); @endphp
            @foreach ($roomsOld as $ri => $room)
                @include('site-survey._room-form', ['ri' => $ri, 'room' => $room, 'isNew' => true])
            @endforeach
        </div>

        <button type="button" class="btn btn-outline btn-sm" onclick="addRoom()">+ Add Room</button>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-teal" style="min-width:180px;">Save Survey</button>
        <a href="{{ route('site-surveys.index') }}" class="btn btn-outline">Cancel</a>
    </div>
</form>

@endsection

@push('scripts')
<script>
let roomIndex = {{ count(old('rooms', [[]])) }};

function addRoom() {
    const i = roomIndex++;
    const container = document.getElementById('rooms-container');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = roomCardHtml(i);
    container.appendChild(wrapper.firstElementChild);
    container.lastElementChild.querySelector('input[name*="room_name"]').focus();
}

function toggleInfra(btn) {
    const card = btn.closest('.room-card');
    const panel = card.querySelector('.infra-panel');
    const hidden = panel.style.display === 'none';
    panel.style.display = hidden ? 'block' : 'none';
    btn.textContent = hidden ? '▲ Hide Infrastructure' : '▼ Infrastructure Details';
}

function roomCardHtml(i) {
    const d = document.createElement('div');
    d.className = 'room-card';
    d.style.cssText = 'border:1.5px solid #e0e0e0;border-radius:6px;padding:1.25rem;margin-bottom:1rem;background:#fafafa;';

    // Header
    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;';
    const title = document.createElement('strong');
    title.style.color = '#007B8A';
    title.textContent = 'Room ' + (i + 1);
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.style.cssText = 'background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;padding:0 .25rem;';
    removeBtn.textContent = '\u2715';
    removeBtn.onclick = function() { d.remove(); };
    hdr.appendChild(title);
    hdr.appendChild(removeBtn);
    d.appendChild(hdr);

    // Basic grid (name/ref/floor)
    const grid1 = document.createElement('div');
    grid1.className = 'form-grid-2';
    grid1.innerHTML =
        field('Room Name', `rooms[${i}][room_name]`, 'text', '', true, 150) +
        field('Room Ref', `rooms[${i}][room_ref]`, 'text', '', false, 50) +
        field('Floor', `rooms[${i}][floor]`, 'text', 'e.g. Ground, 1st', false, 50);
    d.appendChild(grid1);

    // AV requirements
    const avReq = document.createElement('div');
    avReq.className = 'form-group';
    avReq.innerHTML = '<label class="form-label">AV Requirements</label>'
        + `<textarea name="rooms[${i}][av_requirements]" class="form-control" rows="2" maxlength="1000"></textarea>`;
    d.appendChild(avReq);

    // Power/network quick checkboxes
    const checks = document.createElement('div');
    checks.style.cssText = 'display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem;';
    checks.innerHTML =
        checkbox(`rooms[${i}][has_power]`, 'Power present') +
        checkbox(`rooms[${i}][has_network]`, 'Network present') +
        checkbox(`rooms[${i}][requires_additional_power]`, 'Additional power required');
    d.appendChild(checks);

    // Infrastructure toggle
    const infraBtn = document.createElement('button');
    infraBtn.type = 'button';
    infraBtn.className = 'btn btn-outline btn-sm';
    infraBtn.style.marginBottom = '.75rem';
    infraBtn.textContent = '\u25BC Infrastructure Details';
    infraBtn.onclick = function() { toggleInfra(this); };
    d.appendChild(infraBtn);

    // Infrastructure panel (hidden by default)
    const panel = document.createElement('div');
    panel.className = 'infra-panel';
    panel.style.display = 'none';

    const grid2 = document.createElement('div');
    grid2.className = 'form-grid-2';
    grid2.innerHTML =
        numField('Width (m)', `rooms[${i}][room_width_m]`) +
        numField('Depth (m)', `rooms[${i}][room_depth_m]`) +
        numField('Height (m)', `rooms[${i}][room_height_m]`) +
        selectField('Ceiling Type', `rooms[${i}][ceiling_type]`, {
            '': '— Select —', 'concrete': 'Concrete', 'suspended': 'Suspended',
            'plasterboard': 'Plasterboard', 'open': 'Open (exposed)', 'other': 'Other'
        }) +
        numField('Ceiling Height (m)', `rooms[${i}][ceiling_height_m]`) +
        selectField('Wall Material', `rooms[${i}][wall_material]`, {
            '': '— Select —', 'brick': 'Brick', 'plasterboard': 'Plasterboard',
            'glass': 'Glass', 'concrete': 'Concrete', 'other': 'Other'
        }) +
        selectField('Floor Type', `rooms[${i}][floor_type]`, {
            '': '— Select —', 'concrete': 'Concrete', 'carpet': 'Carpet',
            'tiles': 'Tiles', 'raised': 'Raised Access', 'other': 'Other'
        }) +
        numField('Power Outlets', `rooms[${i}][power_outlet_count]`, 0, 999, true) +
        numField('Network Ports', `rooms[${i}][network_port_count]`, 0, 999, true);
    panel.appendChild(grid2);

    const existCab = document.createElement('div');
    existCab.className = 'form-group';
    existCab.innerHTML = '<label class="form-label">Existing Cabling</label>'
        + `<textarea name="rooms[${i}][existing_cabling]" class="form-control" rows="2" maxlength="500"></textarea>`;
    panel.appendChild(existCab);

    const avEquip = document.createElement('div');
    avEquip.className = 'form-group';
    avEquip.innerHTML = '<label class="form-label">Existing AV Equipment</label>'
        + `<textarea name="rooms[${i}][av_equipment_list]" class="form-control" rows="2" maxlength="1000"></textarea>`;
    panel.appendChild(avEquip);

    const accessNotes = document.createElement('div');
    accessNotes.className = 'form-group';
    accessNotes.innerHTML = '<label class="form-label">Access / Hazard Notes</label>'
        + `<textarea name="rooms[${i}][access_notes]" class="form-control" rows="2" maxlength="500"></textarea>`;
    panel.appendChild(accessNotes);

    d.appendChild(panel);

    // General notes
    const notesGrp = document.createElement('div');
    notesGrp.className = 'form-group';
    notesGrp.style.marginBottom = '0';
    notesGrp.innerHTML = '<label class="form-label">Notes</label>'
        + `<textarea name="rooms[${i}][notes]" class="form-control" rows="2" maxlength="500"></textarea>`;
    d.appendChild(notesGrp);

    return d.outerHTML;
}

// Helpers
function field(label, name, type, placeholder, required, maxlength) {
    return `<div class="form-group"><label class="form-label">${label}${required ? ' <span class="req">*</span>' : ''}</label>`
        + `<input type="${type}" name="${name}" class="form-control"${required ? ' required' : ''}`
        + `${placeholder ? ` placeholder="${placeholder}"` : ''} maxlength="${maxlength}"></div>`;
}
function numField(label, name, min = 0, max = 999, isInt = false) {
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<input type="number" name="${name}" class="form-control" min="${min}" max="${max}"${isInt ? ' step="1"' : ' step="0.01"'}></div>`;
}
function selectField(label, name, options) {
    let opts = Object.entries(options).map(([v, t]) => `<option value="${v}">${t}</option>`).join('');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<select name="${name}" class="form-control">${opts}</select></div>`;
}
function checkbox(name, label) {
    return `<label class="check-item" style="cursor:pointer;">`
        + `<input type="hidden" name="${name}" value="0">`
        + `<input type="checkbox" name="${name}" value="1">`
        + ` <span>${label}</span></label>`;
}
</script>
@endpush

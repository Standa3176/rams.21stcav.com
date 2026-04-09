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

@php $currentType = old('survey_type', 'general'); @endphp

<form method="POST" action="{{ route('site-surveys.store') }}" id="survey-form">
    @csrf

    {{-- Project Details --}}
    <div class="section-block">
        <h2 class="section-heading">Project Details</h2>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="project_id">Link to Project</label>
                <select id="project_id" name="project_id" class="form-control" onchange="onProjectChange(this)">
                    <option value="">— Standalone (no project) —</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" {{ (old('project_id', $selectedProjectId) == $p->id) ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="survey_type">Default Space Type <span style="font-weight:400;color:#6B7280;">(for new spaces added)</span></label>
                <select id="survey_type" name="survey_type" class="form-control"
                        onchange="CURRENT_DEFAULT_TYPE = this.value">
                    <option value="general"        {{ $currentType === 'general'        ? 'selected' : '' }}>General AV / Meeting Room</option>
                    <option value="pa_system"      {{ $currentType === 'pa_system'      ? 'selected' : '' }}>PA / Background Music</option>
                    <option value="infrastructure" {{ $currentType === 'infrastructure' ? 'selected' : '' }}>Infrastructure / Cable Route</option>
                    <option value="signage"        {{ $currentType === 'signage'        ? 'selected' : '' }}>Digital Signage</option>
                    <option value="upgrade"        {{ $currentType === 'upgrade'        ? 'selected' : '' }}>Upgrade / Strip-out</option>
                    <option value="mixed"          {{ $currentType === 'mixed'          ? 'selected' : '' }}>Mixed (all sections)</option>
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

    {{-- Areas / Rooms --}}
    <div class="section-block">
        <h2 class="section-heading" id="areas-heading">{{ match($currentType) {
            'pa_system'      => 'PA Zones / Areas',
            'infrastructure' => 'Locations / Routes',
            'signage'        => 'Display Positions',
            default          => 'Rooms / Areas',
        } }}</h2>
        <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
            Add each area to be surveyed. You can upload photos after saving.
        </p>

        <div id="rooms-container">
            @php $roomsOld = old('rooms', [[]]); @endphp
            @foreach ($roomsOld as $ri => $room)
                @include('site-survey._room-form', [
                    'ri'         => $ri,
                    'room'       => $room,
                    'isNew'      => true,
                    'surveyType' => $currentType,
                ])
            @endforeach
        </div>

        <button type="button" id="add-area-btn" class="btn btn-outline btn-sm" onclick="addRoom()">
            + Add {{ match($currentType) {
                'pa_system'      => 'PA Zone',
                'infrastructure' => 'Location',
                'signage'        => 'Display Position',
                default          => 'Room',
            } }}
        </button>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-teal" style="min-width:180px;">Save Survey</button>
        <a href="{{ route('site-surveys.index') }}" class="btn btn-outline">Cancel</a>
    </div>
</form>

@endsection

@push('scripts')
<script>
// ── State ──────────────────────────────────────────────────────────────────────
let CURRENT_DEFAULT_TYPE = '{{ $currentType }}';
let roomIndex = {{ count(old('rooms', [[]])) }};

// ── Project auto-fill ──────────────────────────────────────────────────────────
function onProjectChange(sel) {
    const id = sel.value;
    if (! id) return;
    fetch(`/site-surveys/project-data/${id}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
        if (! data) return;
        if (data.name)         document.getElementById('project_name').value = data.name;
        if (data.ref != null)  document.getElementById('project_ref').value  = data.ref ?? '';
        if (data.client_name)  document.getElementById('client_name').value  = data.client_name;
        if (data.site_address) document.getElementById('site_address').value = data.site_address;
    })
    .catch(() => {});
}

// ── Per-card type change — only affects that one card ─────────────────────────
function onSpaceTypeChange(sel) {
    applySpaceType(sel.closest('.room-card'), sel.value);
}

function applySpaceType(card, type) {
    const showPa      = type === 'pa_system'  || type === 'mixed';
    const showSignage = type === 'signage'     || type === 'mixed';
    const showUpgrade = type === 'upgrade'     || type === 'mixed';
    const showAreaType = type !== 'general';

    card.querySelectorAll('.type-panel--pa').forEach(el => el.style.display = showPa ? 'block' : 'none');
    card.querySelectorAll('.type-panel--signage').forEach(el => el.style.display = showSignage ? 'block' : 'none');
    card.querySelectorAll('.type-panel--upgrade').forEach(el => el.style.display = showUpgrade ? 'block' : 'none');
    card.querySelectorAll('.area-type-group').forEach(el => el.style.display = showAreaType ? 'block' : 'none');
}

// ── Add new space card (uses current default type) ────────────────────────────
function addRoom() {
    const i = roomIndex++;
    const container = document.getElementById('rooms-container');
    const wrapper   = document.createElement('div');
    wrapper.innerHTML = roomCardHtml(i, CURRENT_DEFAULT_TYPE);
    container.appendChild(wrapper.firstElementChild);
    container.lastElementChild.querySelector('input[name*="room_name"]')?.focus();
}

// ── Infrastructure accordion ───────────────────────────────────────────────────
function toggleInfra(btn) {
    const panel  = btn.closest('.room-card').querySelector('.infra-panel');
    const hidden = panel.style.display === 'none' || panel.style.display === '';
    panel.style.display = hidden ? 'block' : 'none';
    btn.textContent = hidden ? '\u25b2 Hide Measurements' : '\u25bc Measurements \u0026 Infrastructure';
}

// ── Kit list drawer ────────────────────────────────────────────────────────────
function toggleKit(btn) {
    const drawer  = btn.nextElementSibling;
    const chevron = btn.querySelector('.kit-chevron');
    const isOpen  = drawer.style.maxHeight && drawer.style.maxHeight !== '0px';
    drawer.style.maxHeight  = isOpen ? '0' : '600px';
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// ── Build a new room card as HTML string ────────────────────────────────────────
function roomCardHtml(i, type) {
    type = type || 'general';
    const showPa      = type === 'pa_system'  || type === 'mixed';
    const showSignage = type === 'signage'     || type === 'mixed';
    const showUpgrade = type === 'upgrade'     || type === 'mixed';
    const showAreaType = type !== 'general';

    const n = k => `rooms[${i}][${k}]`;

    return `<div class="room-card" style="border:1.5px solid #e0e0e0;border-radius:6px;padding:1.25rem;margin-bottom:1rem;background:#fafafa;position:relative;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <strong style="color:#007B8A;">Space ${i + 1}</strong>
            <button type="button" onclick="this.closest('.room-card').remove()" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;padding:0 .25rem;">&#10005;</button>
        </div>

        <div class="form-grid-2" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Space / Survey Type</label>
                <select name="${n('space_type')}" class="form-control" onchange="onSpaceTypeChange(this)">
                    <option value="general"        ${type==='general'        ? 'selected' : ''}>General AV / Meeting Room</option>
                    <option value="pa_system"      ${type==='pa_system'      ? 'selected' : ''}>PA / Background Music</option>
                    <option value="infrastructure" ${type==='infrastructure' ? 'selected' : ''}>Infrastructure / Cable Route</option>
                    <option value="signage"        ${type==='signage'        ? 'selected' : ''}>Digital Signage</option>
                    <option value="upgrade"        ${type==='upgrade'        ? 'selected' : ''}>Upgrade / Strip-out</option>
                    <option value="mixed"          ${type==='mixed'          ? 'selected' : ''}>Mixed (all sections)</option>
                </select>
            </div>
            <div class="area-type-group form-group" style="margin-bottom:0;${showAreaType ? '' : 'display:none'}">
                <label class="form-label">Area Classification</label>
                <select name="${n('area_type')}" class="form-control">
                    <option value="">— Select —</option>
                    <option value="room">Meeting Room</option>
                    <option value="open_plan">Open Plan Area</option>
                    <option value="lobby">Lobby / Reception</option>
                    <option value="auditorium">Auditorium / Theatre</option>
                    <option value="outdoor_area">Outdoor Area</option>
                    <option value="zone">PA Zone / Coverage Area</option>
                    <option value="rack_location">Rack / Equipment Room</option>
                    <option value="cable_route">Cable Route / Riser</option>
                    <option value="display_position">Display Position</option>
                    <option value="stairwell">Stairwell / Corridor</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        <div class="form-grid-2">
            ${fld('Space Name <span class="req">*</span>', n('room_name'), 'text', 'e.g. Boardroom, Reception, Zone A', true, 150)}
            ${fld('Ref / Number', n('room_ref'), 'text', '', false, 50)}
            ${fld('Floor / Level', n('floor'), 'text', 'e.g. Ground, 1st, B1', false, 50)}
        </div>

        <div class="form-group">
            <label class="form-label">AV Requirements</label>
            <textarea name="${n('av_requirements')}" class="form-control" rows="2" maxlength="5000"></textarea>
        </div>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem;">
            ${chk(n('has_power'), 'Power present')}
            ${chk(n('has_network'), 'Network present')}
            ${chk(n('requires_additional_power'), 'Additional power required')}
        </div>

        <button type="button" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;" onclick="toggleInfra(this)">
            &#9660; Measurements &amp; Infrastructure
        </button>

        <div class="infra-panel" style="display:none;">
            <div class="form-grid-2">
                ${num('Width (m)', n('room_width_m'))}
                ${num('Depth (m)', n('room_depth_m'))}
                ${num('Height (m)', n('room_height_m'), 0, 99)}
                ${sel('Ceiling Type', n('ceiling_type'), {'':'— Select —','concrete':'Concrete','suspended':'Suspended','plasterboard':'Plasterboard','open':'Open (exposed)','other':'Other'})}
                ${num('Ceiling Height (m)', n('ceiling_height_m'), 0, 99)}
                ${sel('Wall Material', n('wall_material'), {'':'— Select —','brick':'Brick','plasterboard':'Plasterboard','glass':'Glass','concrete':'Concrete','other':'Other'})}
                ${sel('Floor Type', n('floor_type'), {'':'— Select —','concrete':'Concrete','carpet':'Carpet','tiles':'Tiles','raised':'Raised Access','other':'Other'})}
                ${num('Power Outlets', n('power_outlet_count'), 0, 999, true)}
                ${num('Network Ports', n('network_port_count'), 0, 999, true)}
            </div>
            ${ta('Existing Cabling', n('existing_cabling'), 500)}
            ${ta('Existing AV Equipment', n('av_equipment_list'), 5000)}
            ${ta('Access / Hazard Notes', n('access_notes'), 500)}
        </div>

        <div class="type-panel type-panel--pa" style="${showPa ? '' : 'display:none'}">
            <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#178A95;margin:.75rem 0 .5rem;border-top:1px solid #eee;padding-top:.75rem;">PA System Details</div>
            <div class="form-grid-2">
                ${num('Number of Speakers', n('speaker_count'), 0, 999, true)}
                ${sel('Speaker Type', n('speaker_type'), {'':'— Select —','ceiling':'Ceiling (flush)','pendant':'Pendant','surface':'Surface mount','column':'Column array','horn':'Horn / outdoor','sub':'Subwoofer','line_array':'Line array','other':'Other'})}
                ${sel('Speaker Mounting', n('speaker_mounting'), {'':'— Select —','ceiling_recessed':'Ceiling — recessed','ceiling_surface':'Ceiling — surface','pendant':'Pendant drop','wall':'Wall mount','bracket':'Bracket / truss','floor_stand':'Floor stand','other':'Other'})}
                ${num('Background Noise (dB)', n('bg_noise_db'), 0, 120, true)}
            </div>
        </div>

        <div class="type-panel type-panel--signage" style="${showSignage ? '' : 'display:none'}">
            <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#178A95;margin:.75rem 0 .5rem;border-top:1px solid #eee;padding-top:.75rem;">Digital Signage Details</div>
            <div class="form-grid-2">
                ${num('Display Size (inches)', n('display_size_in'), 0, 999, false, '0.1')}
                ${sel('Orientation', n('display_orient'), {'':'— Select —','landscape':'Landscape','portrait':'Portrait'})}
                <div class="form-group" style="grid-column:1/-1;">
                    ${sel('Mounting Type', n('display_mounting'), {'':'— Select —','wall_flush':'Wall — flush / fixed','wall_tilt':'Wall — tilt / articulating','ceiling':'Ceiling drop mount','floor_stand':'Floor stand / totem','desk_stand':'Desk / counter stand','other':'Other'})}
                </div>
            </div>
        </div>

        <div class="type-panel type-panel--upgrade" style="${showUpgrade ? '' : 'display:none'}">
            <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#178A95;margin:.75rem 0 .5rem;border-top:1px solid #eee;padding-top:.75rem;">Upgrade / Strip-out Details</div>
            ${ta('Existing Equipment Condition', n('existing_condition'), 3000, 'Describe the general condition of existing AV equipment…')}
            ${ta('Items to Remove / Strip Out', n('items_to_remove'), 3000, 'List equipment to be decommissioned and removed…')}
            ${ta('Items to Retain / Reuse', n('items_to_retain'), 3000, 'List equipment that will be kept and integrated into the new system…')}
        </div>

        <div class="form-group" style="margin-bottom:0;margin-top:.5rem;">
            <label class="form-label">Other Notes</label>
            <textarea name="${n('notes')}" class="form-control" rows="2" maxlength="500"></textarea>
        </div>
    </div>`;
}

// ── HTML helpers ───────────────────────────────────────────────────────────────
function fld(labelHtml, name, type, ph, req, max) {
    return `<div class="form-group"><label class="form-label">${labelHtml}</label>`
        + `<input type="${type}" name="${name}" class="form-control"${req ? ' required' : ''}`
        + `${ph ? ` placeholder="${ph}"` : ''} maxlength="${max}"></div>`;
}
function num(label, name, min = 0, max = 999, isInt = false, step = null) {
    const s = step || (isInt ? '1' : '0.01');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<input type="number" name="${name}" class="form-control" min="${min}" max="${max}" step="${s}"></div>`;
}
function sel(label, name, opts) {
    const options = Object.entries(opts).map(([v, t]) => `<option value="${v}">${t}</option>`).join('');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<select name="${name}" class="form-control">${options}</select></div>`;
}
function ta(label, name, max, ph = '') {
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<textarea name="${name}" class="form-control" rows="2" maxlength="${max}"${ph ? ` placeholder="${ph}"` : ''}></textarea></div>`;
}
function chk(name, label) {
    return `<label class="check-item" style="cursor:pointer;">`
        + `<input type="hidden" name="${name}" value="0">`
        + `<input type="checkbox" name="${name}" value="1"> <span>${label}</span></label>`;
}
</script>
@endpush

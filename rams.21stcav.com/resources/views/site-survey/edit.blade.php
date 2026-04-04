@extends('layouts.app')

@section('title', 'Edit Survey — ' . $survey->project_name)

@section('content')

<div class="page-header">
    <h1 class="page-title">Edit Survey</h1>
    <div style="display:flex;gap:.5rem;">
        <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">&#8592; Back</a>
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

<form method="POST" action="{{ route('site-surveys.update', $survey) }}" id="survey-form">
    @csrf
    @method('PUT')

    {{-- Project Details --}}
    <div class="section-block">
        <h2 class="section-heading">Project Details</h2>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="project_id">Link to Project</label>
                <select id="project_id" name="project_id" class="form-control">
                    <option value="">— Standalone (no project) —</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" {{ (old('project_id', $survey->project_id) == $p->id) ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="project_name">Project Name <span class="req">*</span></label>
                <input type="text" id="project_name" name="project_name"
                       class="form-control @error('project_name') is-invalid @enderror"
                       value="{{ old('project_name', $survey->project_name) }}" required maxlength="200">
                @error('project_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label" for="project_ref">Reference</label>
                <input type="text" id="project_ref" name="project_ref" class="form-control"
                       value="{{ old('project_ref', $survey->project_ref) }}" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client Name</label>
                <input type="text" id="client_name" name="client_name" class="form-control"
                       value="{{ old('client_name', $survey->client_name) }}" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label" for="surveyor_name">Surveyor Name</label>
                <input type="text" id="surveyor_name" name="surveyor_name" class="form-control"
                       value="{{ old('surveyor_name', $survey->surveyor_name) }}" maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label" for="survey_date">Survey Date</label>
                <input type="date" id="survey_date" name="survey_date" class="form-control"
                       value="{{ old('survey_date', $survey->survey_date?->format('Y-m-d')) }}">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="site_address">Site Address</label>
            <textarea id="site_address" name="site_address" class="form-control" rows="2" maxlength="500">{{ old('site_address', $survey->site_address) }}</textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="general_notes">General Notes</label>
            <textarea id="general_notes" name="general_notes" class="form-control" rows="3" maxlength="3000">{{ old('general_notes', $survey->general_notes) }}</textarea>
        </div>
    </div>

    {{-- Rooms --}}
    <div class="section-block">
        <h2 class="section-heading">Rooms / Areas</h2>
        <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
            Existing rooms are preserved. Removed rooms will be deleted along with their photos.
        </p>

        <div id="rooms-container">
            @foreach ($survey->rooms as $ri => $room)
                @include('site-survey._room-form', ['ri' => $ri, 'room' => $room, 'isNew' => false])
            @endforeach
        </div>

        <button type="button" class="btn btn-outline btn-sm" onclick="addRoom()">+ Add Room</button>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-teal" style="min-width:180px;">Save Changes</button>
        <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline">Cancel</a>
    </div>
</form>

@endsection

@push('scripts')
<script>
let roomIndex = {{ $survey->rooms->count() }};

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
    btn.textContent = hidden ? '\u25b2 Hide Infrastructure' : '\u25bc Infrastructure Details';
}

function roomCardHtml(i) {
    const d = document.createElement('div');
    d.className = 'room-card';
    d.style.cssText = 'border:1.5px solid #e0e0e0;border-radius:6px;padding:1.25rem;margin-bottom:1rem;background:#fafafa;';

    const hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;';
    const title = document.createElement('strong');
    title.style.color = '#007B8A';
    title.textContent = 'New Room';
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.style.cssText = 'background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;padding:0 .25rem;';
    removeBtn.textContent = '\u2715';
    removeBtn.onclick = function() { d.remove(); };
    hdr.appendChild(title);
    hdr.appendChild(removeBtn);
    d.appendChild(hdr);

    const grid1 = document.createElement('div');
    grid1.className = 'form-grid-2';
    grid1.innerHTML =
        fld('Room Name', `rooms[${i}][room_name]`, 'text', '', true, 150) +
        fld('Room Ref', `rooms[${i}][room_ref]`, 'text', '', false, 50) +
        fld('Floor', `rooms[${i}][floor]`, 'text', 'e.g. Ground, 1st', false, 50);
    d.appendChild(grid1);

    const avReq = document.createElement('div');
    avReq.className = 'form-group';
    avReq.innerHTML = '<label class="form-label">AV Requirements</label>'
        + `<textarea name="rooms[${i}][av_requirements]" class="form-control" rows="2" maxlength="1000"></textarea>`;
    d.appendChild(avReq);

    const checks = document.createElement('div');
    checks.style.cssText = 'display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem;';
    checks.innerHTML = chk(`rooms[${i}][has_power]`, 'Power present')
        + chk(`rooms[${i}][has_network]`, 'Network present')
        + chk(`rooms[${i}][requires_additional_power]`, 'Additional power required');
    d.appendChild(checks);

    const infraBtn = document.createElement('button');
    infraBtn.type = 'button';
    infraBtn.className = 'btn btn-outline btn-sm';
    infraBtn.style.marginBottom = '.75rem';
    infraBtn.textContent = '\u25bc Infrastructure Details';
    infraBtn.onclick = function() { toggleInfra(this); };
    d.appendChild(infraBtn);

    const panel = document.createElement('div');
    panel.className = 'infra-panel';
    panel.style.display = 'none';
    const grid2 = document.createElement('div');
    grid2.className = 'form-grid-2';
    grid2.innerHTML =
        num('Width (m)', `rooms[${i}][room_width_m]`) +
        num('Depth (m)', `rooms[${i}][room_depth_m]`) +
        num('Height (m)', `rooms[${i}][room_height_m]`) +
        sel('Ceiling Type', `rooms[${i}][ceiling_type]`, {'':'— Select —','concrete':'Concrete','suspended':'Suspended','plasterboard':'Plasterboard','open':'Open (exposed)','other':'Other'}) +
        num('Ceiling Height (m)', `rooms[${i}][ceiling_height_m]`) +
        sel('Wall Material', `rooms[${i}][wall_material]`, {'':'— Select —','brick':'Brick','plasterboard':'Plasterboard','glass':'Glass','concrete':'Concrete','other':'Other'}) +
        sel('Floor Type', `rooms[${i}][floor_type]`, {'':'— Select —','concrete':'Concrete','carpet':'Carpet','tiles':'Tiles','raised':'Raised Access','other':'Other'}) +
        num('Power Outlets', `rooms[${i}][power_outlet_count]`, true) +
        num('Network Ports', `rooms[${i}][network_port_count]`, true);
    panel.appendChild(grid2);

    ['existing_cabling|Existing Cabling|500', 'av_equipment_list|Existing AV Equipment|1000', 'access_notes|Access / Hazard Notes|500'].forEach(function(s) {
        const parts = s.split('|');
        const ta = document.createElement('div');
        ta.className = 'form-group';
        ta.innerHTML = `<label class="form-label">${parts[1]}</label>`
            + `<textarea name="rooms[${i}][${parts[0]}]" class="form-control" rows="2" maxlength="${parts[2]}"></textarea>`;
        panel.appendChild(ta);
    });
    d.appendChild(panel);

    const notesGrp = document.createElement('div');
    notesGrp.className = 'form-group';
    notesGrp.style.marginBottom = '0';
    notesGrp.innerHTML = '<label class="form-label">Other Notes</label>'
        + `<textarea name="rooms[${i}][notes]" class="form-control" rows="2" maxlength="500"></textarea>`;
    d.appendChild(notesGrp);

    return d.outerHTML;
}

function fld(label, name, type, ph, req, max) {
    return `<div class="form-group"><label class="form-label">${label}${req ? ' <span class="req">*</span>' : ''}</label>`
        + `<input type="${type}" name="${name}" class="form-control"${req ? ' required' : ''}${ph ? ` placeholder="${ph}"` : ''} maxlength="${max}"></div>`;
}
function num(label, name, isInt) {
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<input type="number" name="${name}" class="form-control" min="0" max="999" step="${isInt ? '1' : '0.01'}"></div>`;
}
function sel(label, name, opts) {
    const options = Object.entries(opts).map(function(e) { return `<option value="${e[0]}">${e[1]}</option>`; }).join('');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<select name="${name}" class="form-control">${options}</select></div>`;
}
function chk(name, label) {
    return `<label class="check-item" style="cursor:pointer;">`
        + `<input type="hidden" name="${name}" value="0">`
        + `<input type="checkbox" name="${name}" value="1"> <span>${label}</span></label>`;
}
</script>
@endpush

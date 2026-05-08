@extends('layouts.app')

@section('title', 'Edit Survey — ' . $survey->project_name)

@section('content')

<x-edit-action-bar :form-id="'survey-form'" :cancel-url="route('site-surveys.show', $survey)">
    <x-slot:title>Edit Survey — {{ $survey->project_name }}</x-slot:title>
</x-edit-action-bar>

@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please correct the following:</strong>
        <ul style="margin:.5rem 0 0 1.2rem;font-size:.875rem;">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    </div>
@endif

@php $currentType = old('survey_type', $survey->survey_type ?? 'general'); @endphp

<form method="POST" action="{{ route('site-surveys.update', $survey) }}" id="survey-form">
    @csrf
    @method('PUT')

    {{-- Project Details --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Project Details</h2>
        </div>
        <div class="form-section__body">
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="project_id">Link to Project</label>
                <select id="project_id" name="project_id" class="form-control" data-optional onchange="onProjectChange(this)">
                    <option value="">— Standalone (no project) —</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" {{ (old('project_id', $survey->project_id) == $p->id) ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="survey_type">Default Space Type <span style="font-weight:400;color:#6B7280;">(for new spaces added)</span></label>
                <select id="survey_type" name="survey_type" class="form-control" data-optional
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
                       value="{{ old('project_name', $survey->project_name) }}" required maxlength="200" placeholder=" ">
                @error('project_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label class="form-label" for="project_ref">Reference</label>
                <input type="text" id="project_ref" name="project_ref" class="form-control" data-optional
                       value="{{ old('project_ref', $survey->project_ref) }}" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client Name</label>
                <input type="text" id="client_name" name="client_name" class="form-control" data-optional
                       value="{{ old('client_name', $survey->client_name) }}" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label" for="surveyor_name">Surveyor Name</label>
                <input type="text" id="surveyor_name" name="surveyor_name" class="form-control"
                       value="{{ old('surveyor_name', $survey->surveyor_name) }}" placeholder=" " maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label" for="survey_date">Survey Date</label>
                <input type="date" id="survey_date" name="survey_date" class="form-control"
                       value="{{ old('survey_date', $survey->survey_date?->format('Y-m-d')) }}">
            </div>
            <div class="form-group">
                <label class="form-label" for="visit_time">Visit Time</label>
                <input type="text" id="visit_time" name="visit_time" class="form-control"
                       value="{{ old('visit_time', $survey->visit_time) }}" placeholder="e.g. 9:00 AM – 12:00 PM" maxlength="100">
            </div>
        </div>

        {{-- Site Contact --}}
        <h3 class="section-heading" style="margin-top:1rem;">Site Contact</h3>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="site_contact_name">Site Contact Name</label>
                <input type="text" id="site_contact_name" name="site_contact_name" class="form-control"
                       value="{{ old('site_contact_name', $survey->site_contact_name) }}" placeholder="e.g. Jane Smith" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label" for="site_contact_phone">Site Contact Phone</label>
                <input type="text" id="site_contact_phone" name="site_contact_phone" class="form-control"
                       value="{{ old('site_contact_phone', $survey->site_contact_phone) }}" placeholder="e.g. 07700 123456" maxlength="50">
            </div>
        </div>

        {{-- Project Manager --}}
        <fieldset style="border:1px solid var(--border,#e5e7eb);border-radius:6px;padding:1rem 1.25rem;margin-bottom:1rem;">
            <legend style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#178A95;padding:0 .5rem;">Project Manager</legend>
            <div class="form-grid-2">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="pm_name">Name</label>
                    <input type="text" id="pm_name" name="pm_name" class="form-control"
                           value="{{ old('pm_name', $survey->pm_name) }}" placeholder="e.g. John Doe" maxlength="150">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="pm_phone">Phone</label>
                    <input type="text" id="pm_phone" name="pm_phone" class="form-control"
                           value="{{ old('pm_phone', $survey->pm_phone) }}" placeholder="e.g. 07700 654321" maxlength="50">
                </div>
                <div class="form-group" style="margin-bottom:0;grid-column:1/-1;">
                    <label class="form-label" for="pm_email">Email</label>
                    <input type="email" id="pm_email" name="pm_email" class="form-control"
                           value="{{ old('pm_email', $survey->pm_email) }}" placeholder="e.g. pm@company.com" maxlength="150">
                </div>
            </div>
        </fieldset>

        <div class="form-group">
            <label class="form-label" for="site_address">Site Address</label>
            <textarea id="site_address" name="site_address" class="form-control" rows="2" maxlength="500" placeholder=" ">{{ old('site_address', $survey->site_address) }}</textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="general_notes">General Notes</label>
            <textarea id="general_notes" name="general_notes" class="form-control" rows="3" maxlength="3000" data-optional>{{ old('general_notes', $survey->general_notes) }}</textarea>
        </div>
        </div>
    </div>

    {{-- Office Review Notes — survey-level office annotation surface (quick task 260508-v7g, D-LOCK-2) --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Office Review Notes</h2>
        </div>
        <div class="form-section__body">
            <p style="font-size:.875rem;color:#6B7280;margin:0 0 .75rem;">
                Survey-level office annotations — site-wide context, client conversations, scope summaries.
                Renders on the cover of the client report PDF.
            </p>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="office_review_notes">Notes</label>
                <textarea id="office_review_notes" name="office_review_notes"
                          class="form-control" rows="4" maxlength="5000" data-optional
                          placeholder="e.g. Walk-through with client 06/05 — confirmed delivery window 7–10am Tuesday, parking permits issued by site, 2 access cards waiting at reception.">{{ old('office_review_notes', $survey->office_review_notes) }}</textarea>
            </div>
        </div>
    </div>

    {{-- Site Logistics — engineer-feedback site-level capture (quick task 260503-rgg) --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Site Logistics</h2>
        </div>
        <div class="form-section__body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="comms_room_access_status">Comms Room Access</label>
                    <select id="comms_room_access_status" name="comms_room_access_status" class="form-control" data-optional>
                        <option value="">— Select —</option>
                        @php $cras = old('comms_room_access_status', $survey->comms_room_access_status); @endphp
                        <option value="yes"        @selected($cras === 'yes')>Yes — engineer needs permission</option>
                        <option value="no"         @selected($cras === 'no')>No — open access</option>
                        <option value="outsourced" @selected($cras === 'outsourced')>Outsourced (third-party manages)</option>
                        <option value="unknown"    @selected($cras === 'unknown')>Unknown</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="distance_from_base_miles">Distance from Base (miles)</label>
                    <input type="number" id="distance_from_base_miles" name="distance_from_base_miles" class="form-control"
                           value="{{ old('distance_from_base_miles', $survey->distance_from_base_miles) }}"
                           min="0" max="9999" step="0.1" placeholder="e.g. 47.5" data-optional>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="comms_room_access_notes">Comms Room Access — Notes</label>
                <textarea id="comms_room_access_notes" name="comms_room_access_notes" class="form-control"
                          rows="2" maxlength="2000" data-optional
                          placeholder="e.g. Permit required 48h notice; key from FM desk Mon-Fri 9-5">{{ old('comms_room_access_notes', $survey->comms_room_access_notes) }}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="distance_from_base_notes">Route / Travel Notes</label>
                <textarea id="distance_from_base_notes" name="distance_from_base_notes" class="form-control"
                          rows="2" maxlength="2000" data-optional
                          placeholder="e.g. M25 J7 then 12mi A23; allow 2h in rush hour">{{ old('distance_from_base_notes', $survey->distance_from_base_notes) }}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="parking_restraints">Parking Restraints</label>
                <textarea id="parking_restraints" name="parking_restraints" class="form-control"
                          rows="2" maxlength="2000" data-optional
                          placeholder="e.g. No on-street parking, must use NCP £18/day; loading bay 8am-10am only">{{ old('parking_restraints', $survey->parking_restraints) }}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_access_notes">Site Access Notes</label>
                <textarea id="site_access_notes" name="site_access_notes" class="form-control"
                          rows="3" maxlength="3000" data-optional
                          placeholder="e.g. Loading bay south side; goods lift 1.8m × 1.4m × 2.2m, 500kg; security pass collected from reception">{{ old('site_access_notes', $survey->site_access_notes) }}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="delivery_routes">Delivery Routes</label>
                <textarea id="delivery_routes" name="delivery_routes" class="form-control"
                          rows="3" maxlength="3000" data-optional
                          placeholder="e.g. Deliveries to bay 4 between 7am-11am; contact Site Manager 0207 xxx xxxx 1h before arrival">{{ old('delivery_routes', $survey->delivery_routes) }}</textarea>
            </div>
        </div>
    </div>

    {{-- Areas / Rooms --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading" id="areas-heading">{{ match($currentType) {
                'pa_system'      => 'PA Zones / Areas',
                'infrastructure' => 'Locations / Routes',
                'signage'        => 'Display Positions',
                default          => 'Rooms / Areas',
            } }}</h2>
        </div>
        <div class="form-section__body">
        <p style="color:#666;font-size:.875rem;margin-bottom:1rem;">
            Existing areas are preserved. Removed areas will be deleted along with their photos.
        </p>

        <div id="rooms-container">
            @foreach ($survey->rooms as $ri => $room)
                @include('site-survey._room-form', [
                    'ri'         => $ri,
                    'room'       => $room,
                    'isNew'      => false,
                    'surveyType' => $currentType,
                    'kitItems'   => $kitByArea[$room->room_name] ?? [],
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
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-teal" style="min-width:180px;">Save Changes</button>
        <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline">Cancel</a>
    </div>
</form>

{{-- ════════════════════════════════════════════════════════════════════════
     VARIATIONS & ADDITIONS — quick task 260508-v7g
     Separate form (HTML5 doesn't allow nested forms). Each variation Add /
     Edit / Delete posts directly to the variations CRUD endpoints.
     D-LOCK-1: flat capture. D-LOCK-6: auth-only (controller enforces).
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="form-section" x-data="variationsPanel()" style="margin-top:1.5rem;">
    <div class="form-section__header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 class="section-heading" style="margin:0;">Variations &amp; Additions</h2>
        <button type="button" class="btn btn-teal btn-sm" @click="openAdd()">+ Add Variation</button>
    </div>
    <div class="form-section__body">
        <p style="font-size:.875rem;color:#6B7280;margin:0 0 .75rem;">
            Scope changes captured during or after the survey — extra hardware, cable changes,
            access issues, client-provided changes. Renders in the client report PDF and the
            downloadable Variations CSV (sales team uses for quote revisions).
        </p>

        @if ($survey->variations->isEmpty())
            <p style="font-size:.875rem;color:#9CA3AF;font-style:italic;margin:0;">No variations recorded for this survey yet.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid #E5E7EB;">
                            <th style="text-align:left;padding:.4rem .5rem;">Room</th>
                            <th style="text-align:left;padding:.4rem .5rem;">Type</th>
                            <th style="text-align:left;padding:.4rem .5rem;">Description</th>
                            <th style="text-align:right;padding:.4rem .5rem;">Qty</th>
                            <th style="text-align:left;padding:.4rem .5rem;">Status</th>
                            <th style="text-align:left;padding:.4rem .5rem;">Photo</th>
                            <th style="text-align:right;padding:.4rem .5rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($survey->variations as $variation)
                            {{-- Read-only row --}}
                            <tr x-data="{ editing: false }" :data-editing="editing"
                                style="border-bottom:1px solid #F3F4F6;">
                                <td x-show="!editing" style="padding:.4rem .5rem;vertical-align:top;">{{ $variation->room_name ?: '—' }}</td>
                                <td x-show="!editing" style="padding:.4rem .5rem;vertical-align:top;">{{ str_replace('_', ' ', ucfirst($variation->type)) }}</td>
                                <td x-show="!editing" style="padding:.4rem .5rem;vertical-align:top;">{{ $variation->description }}</td>
                                <td x-show="!editing" style="padding:.4rem .5rem;text-align:right;vertical-align:top;">{{ $variation->qty }}</td>
                                <td x-show="!editing" style="padding:.4rem .5rem;vertical-align:top;">
                                    <span class="status-pill status-{{ $variation->status }}">{{ $variation->status }}</span>
                                </td>
                                <td x-show="!editing" style="padding:.4rem .5rem;vertical-align:top;">
                                    @if ($variation->photo)
                                        <a href="{{ route('site-surveys.photos.serve', $variation->photo) }}" target="_blank"
                                           style="color:#178A95;text-decoration:underline;">📷 view</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td x-show="!editing" style="padding:.4rem .5rem;text-align:right;vertical-align:top;white-space:nowrap;">
                                    <button type="button" class="btn btn-outline btn-xs" @click="editing = true">Edit</button>
                                    <form method="POST" action="{{ route('site-surveys.variations.destroy', [$survey, $variation]) }}" style="display:inline;">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-xs"
                                                data-confirm="Delete this variation? This cannot be undone.">Delete</button>
                                    </form>
                                </td>

                                {{-- Inline-edit row --}}
                                <td x-show="editing" colspan="7" x-cloak
                                    style="padding:.75rem;background:#F9FAFB;border-left:3px solid #178A95;">
                                    <form method="POST" action="{{ route('site-surveys.variations.update', [$survey, $variation]) }}">
                                        @csrf @method('PATCH')
                                        @include('site-survey._variation-fields', ['variation' => $variation, 'survey' => $survey])
                                        <div style="display:flex;gap:.5rem;margin-top:.75rem;">
                                            <button type="submit" class="btn btn-teal btn-sm">Save</button>
                                            <button type="button" class="btn btn-outline btn-sm" @click="editing = false">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Add modal — Alpine x-show, full-screen overlay --}}
    <div x-show="showAdd" x-cloak class="variation-modal-backdrop"
         @click.self="showAdd = false" @keydown.escape.window="showAdd = false">
        <div class="variation-modal-card">
            <h3 style="margin:0 0 1rem;color:#0B3C45;">Add Variation</h3>
            <form method="POST" action="{{ route('site-surveys.variations.store', $survey) }}">
                @csrf
                @include('site-survey._variation-fields', ['variation' => null, 'survey' => $survey])
                <div style="display:flex;gap:.5rem;margin-top:1rem;">
                    <button type="submit" class="btn btn-teal">Save Variation</button>
                    <button type="button" class="btn btn-outline" @click="showAdd = false">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function variationsPanel() {
    return {
        showAdd: false,
        openAdd() { this.showAdd = true; },
    };
}
</script>
<style>
    /* Status pill colour scheme — matches the client-report PDF
       (resources/views/pdf/site-survey/client-report.blade.php) */
    .status-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .status-proposed { background: #FEF3C7; color: #92400E; }
    .status-quoted   { background: #DBEAFE; color: #1E40AF; }
    .status-approved { background: #D1FAE5; color: #065F46; }
    .status-rejected { background: #FEE2E2; color: #991B1B; }

    /* Variation Add modal */
    .variation-modal-backdrop {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        display: flex; align-items: center; justify-content: center;
        z-index: 100;
    }
    .variation-modal-card {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
</style>
@endpush

@endsection

@push('scripts')
<script>
// ── State ──────────────────────────────────────────────────────────────────────
let CURRENT_DEFAULT_TYPE = '{{ $currentType }}';
let roomIndex = {{ $survey->rooms->count() }};

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

// ── Collapsible room cards ────────────────────────────────────────────────────
function toggleAdminCard(header) {
    const card    = header.closest('.room-card');
    const body    = card.querySelector('.room-card-body');
    const chevron = header.querySelector('.room-card-chevron');
    const open    = body.style.display === 'none' || body.style.display === '';
    body.style.display      = open ? 'block' : 'none';
    chevron.style.transform = open ? 'rotate(90deg)' : 'rotate(0deg)';
}

// Update the card header label as the user types the room name
function updateCardLabel(input) {
    const label = input.closest('.room-card')?.querySelector('.room-card-label');
    if (label) label.textContent = input.value || 'New Space';
}

// ── Per-card type change ───────────────────────────────────────────────────────
function onSpaceTypeChange(sel) {
    applySpaceType(sel.closest('.room-card'), sel.value);
}
function applySpaceType(card, type) {
    const showPa       = type === 'pa_system'  || type === 'mixed';
    const showSignage  = type === 'signage'     || type === 'mixed';
    const showUpgrade  = type === 'upgrade'     || type === 'mixed';
    const showAreaType = type !== 'general';
    card.querySelectorAll('.type-panel--pa').forEach(el => el.style.display = showPa ? 'block' : 'none');
    card.querySelectorAll('.type-panel--signage').forEach(el => el.style.display = showSignage ? 'block' : 'none');
    card.querySelectorAll('.type-panel--upgrade').forEach(el => el.style.display = showUpgrade ? 'block' : 'none');
    card.querySelectorAll('.area-type-group').forEach(el => el.style.display = showAreaType ? 'block' : 'none');

    // Engineer-feedback sub-section visibility — mirrors the Blade @php block in
    // _room-form.blade.php so live type changes hide irrelevant sub-sections.
    const showMounting   = type !== 'infrastructure';
    const showCableRt    = type === 'infrastructure' || type === 'mixed';
    const showWallCon    = type !== 'infrastructure';
    const showTableInf   = type === 'general' || type === 'mixed';
    const showFloorBox   = type === 'general' || type === 'mixed';
    const showBrackets   = ['general','pa_system','signage','mixed'].includes(type);
    card.querySelectorAll('.subsection--mounting').forEach(el => el.style.display = showMounting ? '' : 'none');
    card.querySelectorAll('.subsection--cable-routes').forEach(el => el.style.display = showCableRt ? '' : 'none');
    card.querySelectorAll('.subsection--wall-construction').forEach(el => el.style.display = showWallCon ? '' : 'none');
    card.querySelectorAll('.subsection--table-info').forEach(el => el.style.display = showTableInf ? '' : 'none');
    card.querySelectorAll('.subsection--floor-box').forEach(el => el.style.display = showFloorBox ? '' : 'none');
    card.querySelectorAll('.subsection--brackets').forEach(el => el.style.display = showBrackets ? '' : 'none');
    // .subsection--wah is always shown — no toggle.
}

// ── Add new space (expanded, new rooms always open) ───────────────────────────
function addRoom() {
    const i         = roomIndex++;
    const container = document.getElementById('rooms-container');
    const wrapper   = document.createElement('div');
    wrapper.innerHTML = roomCardHtml(i, CURRENT_DEFAULT_TYPE);
    container.appendChild(wrapper.firstElementChild);
    container.lastElementChild.querySelector('input[name*="room_name"]')?.focus();
}

// ── Infrastructure accordion ──────────────────────────────────────────────────
function toggleInfra(btn) {
    const body   = btn.closest('.room-card-body');
    const panel  = body.querySelector('.infra-panel');
    const hidden = panel.style.display === 'none' || panel.style.display === '';
    panel.style.display = hidden ? 'block' : 'none';
    btn.textContent = hidden ? '\u25b2 Hide Measurements' : '\u25bc Measurements \u0026 Infrastructure';
}

// ── Kit list drawer ───────────────────────────────────────────────────────────
function toggleKit(btn) {
    const drawer  = btn.nextElementSibling;
    const chevron = btn.querySelector('.kit-chevron');
    const isOpen  = drawer.style.maxHeight && drawer.style.maxHeight !== '0px';
    drawer.style.maxHeight  = isOpen ? '0' : '600px';
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// ── Pre-Install Checks (internal admin form) ──────────────────────────────────
function answerCheckInternal(questionId, answer) {
    const itemEl = document.getElementById('check-internal-' + questionId);
    if (!itemEl) return;
    const btns = itemEl.querySelectorAll('[data-answer]');
    btns.forEach(b => { b.style.opacity = '0.6'; b.disabled = true; });

    fetch(itemEl.dataset.answerUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ answer: answer }),
    })
    .then(r => r.json())
    .then(data => {
        btns.forEach(b => { b.style.opacity = '1'; b.disabled = false; });
        const colorMap = {
            yes:   { bg:'#D1FAE5', border:'#059669', fg:'#065F46' },
            no:    { bg:'#FEE2E2', border:'#FCA5A5', fg:'#991B1B' },
            other: { bg:'#FEF3C7', border:'#FCD34D', fg:'#92400E' },
        };
        btns.forEach(b => {
            const ans = b.dataset.answer;
            if (ans === answer) {
                b.style.background  = colorMap[ans].bg;
                b.style.borderColor = colorMap[ans].border;
                b.style.color       = colorMap[ans].fg;
            } else {
                b.style.background  = '#ffffff';
                b.style.borderColor = '#D1D5DB';
                b.style.color       = '#374151';
            }
        });
        const wrapEl = document.getElementById('other-wrap-' + questionId);
        if (wrapEl) wrapEl.style.display = answer === 'other' ? 'block' : 'none';
    })
    .catch(() => {
        btns.forEach(b => { b.style.opacity = '1'; b.disabled = false; });
    });
}

function saveOtherTextInternal(questionId, textarea) {
    const itemEl = document.getElementById('check-internal-' + questionId);
    if (!itemEl) return;
    fetch(itemEl.dataset.answerUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ other_text: textarea.value }),
    }).catch(() => { /* silent */ });
}

// ── Build new room card HTML (new rooms start expanded) ───────────────────────
function roomCardHtml(i, type) {
    type = type || 'general';
    const showPa      = type === 'pa_system'  || type === 'mixed';
    const showSignage = type === 'signage'     || type === 'mixed';
    const showUpgrade = type === 'upgrade'     || type === 'mixed';
    const showAreaType = type !== 'general';
    const n = k => `rooms[${i}][${k}]`;

    // New rooms get the engineer-feedback fields (mounting heights, cable routes,
    // wall construction, table info, floor box info, brackets, working-at-height
    // methods) after first save — re-render via the _room-form partial. Avoids
    // duplicating ~250 lines of new markup in JS-string form here. (260503-rgg)
    return `<div class="room-card" style="border:1.5px solid #e0e0e0;border-radius:6px;margin-bottom:.6rem;background:#fafafa;">
        <div class="room-card-header" onclick="toggleAdminCard(this)"
             style="display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;cursor:pointer;user-select:none;border-radius:6px 6px 0 0;">
            <span class="room-card-chevron" style="color:#6B7280;font-size:.9rem;transition:transform 200ms;transform:rotate(90deg);">&#9654;</span>
            <strong class="room-card-label" style="color:#007B8A;flex:1;font-size:.9rem;">New Space</strong>
            <button type="button" onclick="event.stopPropagation(); var b=this; window.appConfirm('Remove this space from the survey? This cannot be undone.', { title:'Remove space?', confirmLabel:'Remove', danger:true }).then(function(ok){ if(ok) b.closest('.room-card').remove(); });"
                    style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1rem;padding:0 .25rem;line-height:1;">&#10005;</button>
        </div>
        <div class="room-card-body" style="padding:0 1rem 1rem;">
            <div class="form-grid-2" style="margin-bottom:.75rem;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Space / Survey Type</label>
                    <select name="${n('space_type')}" class="form-control" onchange="onSpaceTypeChange(this)">
                        <option value="general"        ${type==='general'        ? 'selected':''}>General AV / Meeting Room</option>
                        <option value="pa_system"      ${type==='pa_system'      ? 'selected':''}>PA / Background Music</option>
                        <option value="infrastructure" ${type==='infrastructure' ? 'selected':''}>Infrastructure / Cable Route</option>
                        <option value="signage"        ${type==='signage'        ? 'selected':''}>Digital Signage</option>
                        <option value="upgrade"        ${type==='upgrade'        ? 'selected':''}>Upgrade / Strip-out</option>
                        <option value="mixed"          ${type==='mixed'          ? 'selected':''}>Mixed (all sections)</option>
                    </select>
                </div>
                <div class="area-type-group form-group" style="margin-bottom:0;${showAreaType?'':'display:none'}">
                    <label class="form-label">Area Classification</label>
                    <select name="${n('area_type')}" class="form-control">
                        <option value="">— Select —</option>
                        <option value="room">Meeting Room</option><option value="open_plan">Open Plan Area</option>
                        <option value="lobby">Lobby / Reception</option><option value="auditorium">Auditorium / Theatre</option>
                        <option value="outdoor_area">Outdoor Area</option><option value="zone">PA Zone / Coverage Area</option>
                        <option value="rack_location">Rack / Equipment Room</option><option value="cable_route">Cable Route / Riser</option>
                        <option value="display_position">Display Position</option><option value="stairwell">Stairwell / Corridor</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Space Name <span class="req">*</span></label>
                    <input type="text" name="${n('room_name')}" class="form-control" required maxlength="150"
                           placeholder="e.g. Boardroom, Reception, Zone A" oninput="updateCardLabel(this)">
                </div>
                <div class="form-group">
                    <label class="form-label">Qty <span style="font-weight:400;color:#6B7280;">(creates multiple rooms)</span></label>
                    <input type="number" name="${n('qty')}" class="form-control" value="1" min="1" max="99" step="1">
                </div>
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
                    ${num('Width (m)', n('room_width_m'))} ${num('Depth (m)', n('room_depth_m'))}
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
            <div class="type-panel type-panel--pa" style="${showPa?'':'display:none'}">
                <div class="type-panel-heading">PA System Details</div>
                <div class="form-grid-2">
                    ${num('Number of Speakers', n('speaker_count'), 0, 999, true)}
                    ${sel('Speaker Type', n('speaker_type'), {'':'— Select —','ceiling':'Ceiling (flush)','pendant':'Pendant','surface':'Surface mount','column':'Column array','horn':'Horn / outdoor','sub':'Subwoofer','line_array':'Line array','other':'Other'})}
                    ${sel('Speaker Mounting', n('speaker_mounting'), {'':'— Select —','ceiling_recessed':'Ceiling — recessed','ceiling_surface':'Ceiling — surface','pendant':'Pendant drop','wall':'Wall mount','bracket':'Bracket / truss','floor_stand':'Floor stand','other':'Other'})}
                    ${num('Background Noise (dB)', n('bg_noise_db'), 0, 120, true)}
                </div>
            </div>
            <div class="type-panel type-panel--signage" style="${showSignage?'':'display:none'}">
                <div class="type-panel-heading">Digital Signage Details</div>
                <div class="form-grid-2">
                    ${num('Display Size (inches)', n('display_size_in'), 0, 999, false, '0.1')}
                    ${sel('Orientation', n('display_orient'), {'':'— Select —','landscape':'Landscape','portrait':'Portrait'})}
                    ${sel('Mounting Type', n('display_mounting'), {'':'— Select —','wall_flush':'Wall — flush / fixed','wall_tilt':'Wall — tilt / articulating','ceiling':'Ceiling drop mount','floor_stand':'Floor stand / totem','desk_stand':'Desk / counter stand','other':'Other'})}
                </div>
            </div>
            <div class="type-panel type-panel--upgrade" style="${showUpgrade?'':'display:none'}">
                <div class="type-panel-heading">Upgrade / Strip-out Details</div>
                ${ta('Existing Equipment Condition', n('existing_condition'), 3000, 'Describe condition of existing AV kit…')}
                ${ta('Items to Remove / Strip Out', n('items_to_remove'), 3000, 'List equipment to be decommissioned…')}
                ${ta('Items to Retain / Reuse', n('items_to_retain'), 3000, 'List equipment to keep and integrate…')}
            </div>
            <div class="form-group" style="margin-bottom:0;margin-top:.5rem;">
                <label class="form-label">Other Notes</label>
                <textarea name="${n('notes')}" class="form-control" rows="2" maxlength="500"></textarea>
            </div>
        </div>
    </div>`;
}

// ── HTML helpers ──────────────────────────────────────────────────────────────
function fld(labelHtml, name, type, ph, req, max) {
    return `<div class="form-group"><label class="form-label">${labelHtml}</label>`
        + `<input type="${type}" name="${name}" class="form-control"${req?' required':''}`
        + `${ph?` placeholder="${ph}"`:''} maxlength="${max}"></div>`;
}
function num(label, name, min=0, max=999, isInt=false, step=null) {
    const s = step||(isInt?'1':'0.01');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<input type="number" name="${name}" class="form-control" min="${min}" max="${max}" step="${s}"></div>`;
}
function sel(label, name, opts) {
    const options = Object.entries(opts).map(([v,t])=>`<option value="${v}">${t}</option>`).join('');
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<select name="${name}" class="form-control">${options}</select></div>`;
}
function ta(label, name, max, ph='') {
    return `<div class="form-group"><label class="form-label">${label}</label>`
        + `<textarea name="${name}" class="form-control" rows="2" maxlength="${max}"${ph?` placeholder="${ph}"`:''} ></textarea></div>`;
}
function chk(name, label) {
    return `<label class="check-item" style="cursor:pointer;">`
        + `<input type="hidden" name="${name}" value="0">`
        + `<input type="checkbox" name="${name}" value="1"> <span>${label}</span></label>`;
}
</script>
@endpush

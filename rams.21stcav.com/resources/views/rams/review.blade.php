@extends('layouts.app')

@section('title', 'Review RAMS — ' . $rams->project_name)

@section('content')

@php
    $project = $rams->generated_data['project'] ?? [];
    $hazards = $rams->generated_data['hazards'] ?? [];
    $ms      = $rams->generated_data['method_statement'] ?? null;
    $ppe     = $rams->generated_data['ppe'] ?? [];
    $persons = $rams->generated_data['persons_at_risk'] ?? [];
    // New fields from reviewed_data
    $rd          = $rams->reviewed_data ?? [];
    $prog        = $rd['programme']        ?? [];
    $permitsRd   = $rd['permits_required'] ?? [];
    $matHandling = $rd['material_handling'] ?? [];
    $cdmRows     = $rd['cdm']              ?? [];
    $permitTypes = ['Hot Works', 'Working at Height', 'PASMA', 'IPAF', 'Confined Space', 'Electrical Isolation', 'Asbestos Awareness', 'Other'];
    // New sub-keys for traceability and commissioning sections
    $scopeTraceability  = $rd['scope_traceability']              ?? [];
    $clientRespExp      = $rd['client_responsibilities_expanded'] ?? [];
    $exclusionsList     = $rd['exclusions']                       ?? [];
    $decommData         = $rd['decommissioning']                  ?? [];
    $commCriteria       = $rd['commissioning_criteria']           ?? [];
@endphp

    <div class="page-header">
        <h1 class="page-title">Review &amp; Download RAMS</h1>
        @if ($rams->project_id && $rams->project)
            <a href="{{ route('projects.show', $rams->project_id) }}" class="btn btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Back to Projects</a>
        @endif
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="alert alert-info">
        Review the AI-generated details below. You can edit project fields before downloading.
        The hazard register and method statement are shown read-only — edit the Word document after download if needed.
    </div>

    {{-- ── Edit & Download form ─────────────────────────────────────────────── --}}
    <div class="card">
        <h2 class="section-heading">Project Details</h2>
        <form method="POST" action="{{ route('rams.update-and-download', $rams) }}">
            @csrf
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="project_name">Project Name <span class="req">*</span></label>
                    <input id="project_name" name="project_name" type="text"
                           class="form-control @error('project_name') is-invalid @enderror"
                           value="{{ old('project_name', $project['name'] ?? $rams->project_name) }}" required>
                    @error('project_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label" for="project_ref">Project Ref</label>
                    <input id="project_ref" name="project_ref" type="text"
                           class="form-control"
                           value="{{ old('project_ref', $project['ref'] ?? $rams->project_ref) }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="client_name">Client <span class="req">*</span></label>
                    <input id="client_name" name="client_name" type="text"
                           class="form-control @error('client_name') is-invalid @enderror"
                           value="{{ old('client_name', $project['client'] ?? $rams->client_name) }}" required>
                    @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label" for="document_status">Document Status</label>
                    <select id="document_status" name="document_status" class="form-control">
                        @foreach(['For Construction', 'For Review', 'For Approval', 'For Information'] as $docStatus)
                            <option value="{{ $docStatus }}"
                                {{ ($project['document_status'] ?? 'For Construction') === $docStatus ? 'selected' : '' }}>
                                {{ $docStatus }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="site_address">Site Address <span class="req">*</span></label>
                    <input id="site_address" name="site_address" type="text"
                           class="form-control @error('site_address') is-invalid @enderror"
                           value="{{ old('site_address', $project['site_address'] ?? $rams->site_address) }}" required>
                    @error('site_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="site_contact">Site Contact</label>
                    <input id="site_contact" name="site_contact" type="text"
                           class="form-control @error('site_contact') is-invalid @enderror"
                           value="{{ old('site_contact', $project['site_contact'] ?? '') }}">
                    @error('site_contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="subtitle">Subtitle <small style="font-weight:400;color:#666;">(one-liner shown on cover, e.g. "Site | Client | AV Installation")</small></label>
                    <input id="subtitle" name="subtitle" type="text"
                           class="form-control"
                           value="{{ old('subtitle', $project['subtitle'] ?? '') }}">
                </div>
            </div>

            <h3 class="section-heading" style="margin-top:1rem;">Operations Info</h3>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="project_manager">Project Manager</label>
                    <input id="project_manager" name="project_manager" type="text"
                           class="form-control"
                           value="{{ old('project_manager', $project['project_manager'] ?? '') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="lead_engineer">Lead Engineer</label>
                    <input id="lead_engineer" name="lead_engineer" type="text"
                           class="form-control"
                           value="{{ old('lead_engineer', $project['lead_engineer'] ?? '') }}">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="additional_engineers">Additional Engineer(s)</label>
                    <input id="additional_engineers" name="additional_engineers" type="text"
                           class="form-control"
                           value="{{ old('additional_engineers', $project['additional_engineers'] ?? '') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="programmer">Programmer</label>
                    <input id="programmer" name="programmer" type="text"
                           class="form-control"
                           value="{{ old('programmer', $project['programmer'] ?? '') }}">
                </div>
            </div>

            {{-- ── Programme: Dates & Times ─────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Programme</h3>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="planned_start_date">Planned Start Date</label>
                    <input id="planned_start_date" name="planned_start_date" type="date"
                           class="form-control"
                           value="{{ old('planned_start_date', $project['planned_start_date'] ?? $prog['planned_start_date'] ?? '') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="planned_start_time">Start Time</label>
                    <input id="planned_start_time" name="planned_start_time" type="time"
                           class="form-control"
                           value="{{ old('planned_start_time', $project['planned_start_time'] ?? $prog['planned_start_time'] ?? '08:00') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="planned_end_date">Planned End Date</label>
                    <input id="planned_end_date" name="planned_end_date" type="date"
                           class="form-control"
                           value="{{ old('planned_end_date', $project['planned_end_date'] ?? $prog['planned_end_date'] ?? '') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="planned_end_time">End Time</label>
                    <input id="planned_end_time" name="planned_end_time" type="time"
                           class="form-control"
                           value="{{ old('planned_end_time', $project['planned_end_time'] ?? $prog['planned_end_time'] ?? '17:30') }}">
                </div>
            </div>

            {{-- ── Waste Removal ────────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Waste Removal</h3>
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label">Waste removed by</label>
                <div style="display:flex; gap:1.5rem; margin-top:.35rem;">
                    @foreach(['client' => 'Client', '21cav' => '21CAV', 'other' => 'Other'] as $wrVal => $wrLabel)
                    <label style="display:flex; align-items:center; gap:.4rem; font-size:.9rem; cursor:pointer;">
                        <input type="radio" name="waste_removal_party" value="{{ $wrVal }}"
                               {{ old('waste_removal_party', $prog['waste_removal_party'] ?? '') === $wrVal ? 'checked' : '' }}>
                        {{ $wrLabel }}
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="waste_removal_notes">Waste Removal Notes</label>
                <textarea id="waste_removal_notes" name="waste_removal_notes" class="form-control" rows="2"
                          placeholder="e.g. All packaging and old equipment removed by 21CAV to skip on site"
                >{{ old('waste_removal_notes', $prog['waste_removal_notes'] ?? '') }}</textarea>
            </div>

            {{-- ── Permits Required ─────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Permits Required</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Check each permit type that applies. Add notes where relevant.</p>
            @php
                $existingPermits = [];
                foreach ($permitsRd as $pr) {
                    $existingPermits[$pr['type'] ?? ''] = $pr;
                }
            @endphp
            @foreach($permitTypes as $ptIdx => $pt)
            @php $existingPt = $existingPermits[$pt] ?? []; @endphp
            <div style="display:flex; align-items:flex-start; gap:.75rem; margin-bottom:.5rem; border-bottom:1px solid #f0f0f0; padding-bottom:.5rem;">
                <label style="display:flex; align-items:center; gap:.4rem; min-width:200px; font-size:.875rem; cursor:pointer; padding-top:.15rem;">
                    <input type="checkbox"
                           name="permits_required[{{ $ptIdx }}][required]"
                           value="1"
                           {{ old("permits_required.{$ptIdx}.required", $existingPt['required'] ?? false) ? 'checked' : '' }}>
                    <input type="hidden" name="permits_required[{{ $ptIdx }}][type]" value="{{ $pt }}">
                    {{ $pt }}
                </label>
                <input type="text"
                       name="permits_required[{{ $ptIdx }}][notes]"
                       class="form-control"
                       style="font-size:.875rem; padding:.3rem .5rem;"
                       placeholder="Notes (optional)"
                       value="{{ old("permits_required.{$ptIdx}.notes", $existingPt['notes'] ?? '') }}">
            </div>
            @endforeach

            {{-- ── Material Handling ────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Material Handling</h3>
            <div class="form-group" style="margin-bottom:.5rem;">
                <label style="display:flex; align-items:center; gap:.5rem; font-size:.9rem; cursor:pointer;">
                    <input type="checkbox" name="material_handling_has_large_items" value="1"
                           id="mh_toggle"
                           {{ old('material_handling_has_large_items', $matHandling['has_large_items'] ?? false) ? 'checked' : '' }}>
                    Large / heavy items requiring manual handling assessment
                </label>
            </div>
            <div id="mh_items_section" style="{{ old('material_handling_has_large_items', $matHandling['has_large_items'] ?? false) ? '' : 'display:none;' }}">
                <table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
                    <thead>
                        <tr>
                            <th>Item Description</th>
                            <th style="width:100px;">Weight (kg)</th>
                            <th>Handling Method</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="mh_tbody">
                    @php $mhItemsList = old('material_handling_items', $matHandling['large_items'] ?? []); @endphp
                    @forelse($mhItemsList as $miIdx => $mi)
                        @php $mi = is_array($mi) ? $mi : []; @endphp
                        <tr class="mh-row">
                            <td><input type="text" name="material_handling_items[{{ $miIdx }}][item]" class="form-control" style="font-size:.85rem;" value="{{ $mi['item'] ?? '' }}"></td>
                            <td><input type="text" name="material_handling_items[{{ $miIdx }}][weight_kg]" class="form-control" style="font-size:.85rem;" value="{{ $mi['weight_kg'] ?? '' }}"></td>
                            <td><input type="text" name="material_handling_items[{{ $miIdx }}][handling_method]" class="form-control" style="font-size:.85rem;" value="{{ $mi['handling_method'] ?? '' }}"></td>
                            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00; background:none; border:none; cursor:pointer; font-size:1rem;">&#x2715;</button></td>
                        </tr>
                    @empty
                        <tr class="mh-row">
                            <td><input type="text" name="material_handling_items[0][item]" class="form-control" style="font-size:.85rem;" placeholder="e.g. 100&quot; display"></td>
                            <td><input type="text" name="material_handling_items[0][weight_kg]" class="form-control" style="font-size:.85rem;" placeholder="kg"></td>
                            <td><input type="text" name="material_handling_items[0][handling_method]" class="form-control" style="font-size:.85rem;" placeholder="e.g. 2-person lift, trolley"></td>
                            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00; background:none; border:none; cursor:pointer; font-size:1rem;">&#x2715;</button></td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
                <button type="button" onclick="addMhRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add item</button>
            </div>
            <div class="form-group" style="margin-top:.65rem;">
                <label class="form-label" for="material_handling_handling_notes">Handling Notes</label>
                <textarea id="material_handling_handling_notes" name="material_handling_handling_notes" class="form-control" rows="2"
                          placeholder="Any general manual handling notes or risk controls"
                >{{ old('material_handling_handling_notes', $matHandling['handling_notes'] ?? '') }}</textarea>
            </div>
            <script>
                document.getElementById('mh_toggle').addEventListener('change', function() {
                    document.getElementById('mh_items_section').style.display = this.checked ? '' : 'none';
                });
                var mhRowIndex = {{ count(old('material_handling_items', $matHandling['large_items'] ?? [])) ?: 1 }};
                function addMhRow() {
                    var tbody = document.getElementById('mh_tbody');
                    var tr = document.createElement('tr');
                    tr.className = 'mh-row';
                    tr.innerHTML = '<td><input type="text" name="material_handling_items['+mhRowIndex+'][item]" class="form-control" style="font-size:.85rem;"></td>'
                        + '<td><input type="text" name="material_handling_items['+mhRowIndex+'][weight_kg]" class="form-control" style="font-size:.85rem;" placeholder="kg"></td>'
                        + '<td><input type="text" name="material_handling_items['+mhRowIndex+'][handling_method]" class="form-control" style="font-size:.85rem;"></td>'
                        + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
                    tbody.appendChild(tr);
                    mhRowIndex++;
                }
            </script>

            {{-- ── CDM 2015 Duty Holders + Welfare ─────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">CDM 2015 Duty Holders</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Leave blank if role does not apply. Sub-contractor defaults to 21CAV.</p>
            @php
                $cdmRoles    = ['Client', 'Principal Designer', 'Principal Contractor', 'Sub-contractor'];
                $cdmDefaults = ['Sub-contractor' => ['organisation' => '21st Century AV Ltd']];
                $cdmLookup   = [];
                foreach ($cdmRows as $cr) { $cdmLookup[$cr['role'] ?? ''] = $cr; }
            @endphp
            <table class="data-table" style="font-size:.85rem; margin-bottom:.75rem;">
                <thead>
                    <tr><th>Role</th><th>Organisation</th><th>Name</th><th>Contact</th></tr>
                </thead>
                <tbody>
                @foreach($cdmRoles as $ri => $role)
                @php
                    $cdmRow = $cdmLookup[$role] ?? ($cdmDefaults[$role] ?? []);
                @endphp
                <tr>
                    <td>
                        <input type="hidden" name="cdm[{{ $ri }}][role]" value="{{ $role }}">
                        <strong>{{ $role }}</strong>
                    </td>
                    <td><input type="text" name="cdm[{{ $ri }}][organisation]" class="form-control" style="font-size:.85rem;"
                               value="{{ old("cdm.{$ri}.organisation", $cdmRow['organisation'] ?? '') }}"></td>
                    <td><input type="text" name="cdm[{{ $ri }}][name]" class="form-control" style="font-size:.85rem;"
                               value="{{ old("cdm.{$ri}.name", $cdmRow['name'] ?? '') }}"></td>
                    <td><input type="text" name="cdm[{{ $ri }}][contact]" class="form-control" style="font-size:.85rem;"
                               value="{{ old("cdm.{$ri}.contact", $cdmRow['contact'] ?? '') }}"></td>
                </tr>
                @endforeach
                </tbody>
            </table>

            <div class="form-group">
                <label class="form-label" for="welfare_notes">Welfare Arrangements — Site-Specific Notes</label>
                <textarea id="welfare_notes" name="welfare_notes" class="form-control" rows="2"
                          placeholder="e.g. Welfare facilities in Building B, Level 1. First aider: John Smith (07700 000000)"
                >{{ old('welfare_notes', $prog['welfare_notes'] ?? '') }}</textarea>
            </div>

            {{-- ── Scope Traceability ──────────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Scope Traceability</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Map each quoted item to its RAMS installation activity. Pre-filled from quote where available.</p>
            <table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
                <thead>
                    <tr>
                        <th>Quote Item / Description</th>
                        <th>RAMS Activity</th>
                        <th style="width:130px;">Room / Area</th>
                        <th>Notes</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="st_tbody">
                @php $stList = old('scope_traceability', $scopeTraceability); @endphp
                @forelse($stList as $stIdx => $stRow)
                    @php $stRow = is_array($stRow) ? $stRow : []; @endphp
                    <tr class="st-row">
                        <td><input type="text" name="scope_traceability[{{ $stIdx }}][quote_item]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['quote_item'] ?? '' }}"></td>
                        <td><input type="text" name="scope_traceability[{{ $stIdx }}][rams_activity]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['rams_activity'] ?? '' }}"></td>
                        <td><input type="text" name="scope_traceability[{{ $stIdx }}][room]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['room'] ?? '' }}"></td>
                        <td><input type="text" name="scope_traceability[{{ $stIdx }}][notes]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['notes'] ?? '' }}"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @empty
                    <tr class="st-row">
                        <td><input type="text" name="scope_traceability[0][quote_item]" class="form-control" style="font-size:.85rem;" placeholder="e.g. 100&quot; Display"></td>
                        <td><input type="text" name="scope_traceability[0][rams_activity]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Wall mount and cable"></td>
                        <td><input type="text" name="scope_traceability[0][room]" class="form-control" style="font-size:.85rem;" placeholder="Room"></td>
                        <td><input type="text" name="scope_traceability[0][notes]" class="form-control" style="font-size:.85rem;"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <button type="button" onclick="addStRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add row</button>
            <script>
            var stRowIndex = {{ count(old('scope_traceability', $scopeTraceability)) ?: 1 }};
            function addStRow() {
                var tbody = document.getElementById('st_tbody');
                var tr = document.createElement('tr');
                tr.className = 'st-row';
                tr.innerHTML = '<td><input type="text" name="scope_traceability['+stRowIndex+'][quote_item]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="scope_traceability['+stRowIndex+'][rams_activity]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="scope_traceability['+stRowIndex+'][room]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="scope_traceability['+stRowIndex+'][notes]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
                tbody.appendChild(tr); stRowIndex++;
            }
            </script>

            {{-- ── Client Responsibilities (Expanded) ─────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Client Responsibilities (Expanded)</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Check items the client is required to provide. Add notes where relevant.</p>
            @php
                $crItems = [
                    'network_readiness' => 'Network / LAN readiness (active drops at device locations)',
                    'licences'          => 'Software licences / subscriptions (Teams Rooms, Zoom, etc.)',
                    'access'            => 'Site access and room availability on installation day(s)',
                    'power_validation'  => 'Mains power validation (sockets live and tested)',
                ];
            @endphp
            @foreach($crItems as $crKey => $crLabel)
            @php
                $crItem = $clientRespExp[$crKey] ?? [];
                $crReq  = old("client_resp_{$crKey}_required", $crItem['required'] ?? false);
                $crNote = old("client_resp_{$crKey}_notes",    $crItem['notes']    ?? '');
            @endphp
            <div style="display:flex; align-items:flex-start; gap:.75rem; margin-bottom:.5rem; border-bottom:1px solid #f0f0f0; padding-bottom:.5rem;">
                <label style="display:flex; align-items:center; gap:.4rem; min-width:320px; font-size:.875rem; cursor:pointer; padding-top:.15rem;">
                    <input type="checkbox" name="client_resp_{{ $crKey }}_required" value="1" {{ $crReq ? 'checked' : '' }}>
                    {{ $crLabel }}
                </label>
                <input type="text" name="client_resp_{{ $crKey }}_notes" class="form-control" style="font-size:.875rem; padding:.3rem .5rem;"
                       placeholder="Notes (optional)" value="{{ $crNote }}">
            </div>
            @endforeach

            <p style="font-size:.85rem; color:#666; margin-top:.5rem; margin-bottom:.35rem;">Additional client responsibilities:</p>
            <table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
                <thead><tr><th>Item</th><th>Notes</th><th style="width:40px;"></th></tr></thead>
                <tbody id="cr_tbody">
                @php $crAdditionalList = old('client_resp_additional', $clientRespExp['additional'] ?? []); @endphp
                @forelse($crAdditionalList as $craIdx => $craRow)
                    @php $craRow = is_array($craRow) ? $craRow : []; @endphp
                    <tr>
                        <td><input type="text" name="client_resp_additional[{{ $craIdx }}][item]" class="form-control" style="font-size:.85rem;" value="{{ $craRow['item'] ?? '' }}"></td>
                        <td><input type="text" name="client_resp_additional[{{ $craIdx }}][notes]" class="form-control" style="font-size:.85rem;" value="{{ $craRow['notes'] ?? '' }}"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @empty
                    <tr>
                        <td><input type="text" name="client_resp_additional[0][item]" class="form-control" style="font-size:.85rem;" placeholder="Additional item"></td>
                        <td><input type="text" name="client_resp_additional[0][notes]" class="form-control" style="font-size:.85rem;"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <button type="button" onclick="addCrRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add item</button>
            <script>
            var crRowIndex = {{ count(old('client_resp_additional', $clientRespExp['additional'] ?? [])) ?: 1 }};
            function addCrRow() {
                var tbody = document.getElementById('cr_tbody');
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><input type="text" name="client_resp_additional['+crRowIndex+'][item]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="client_resp_additional['+crRowIndex+'][notes]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
                tbody.appendChild(tr); crRowIndex++;
            }
            </script>

            {{-- ── Exclusions ──────────────────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Exclusions</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Items explicitly excluded from scope. Remove or add as appropriate.</p>
            <div id="excl_list">
            @php $exclList = old('exclusions', $exclusionsList); @endphp
            @forelse($exclList as $exIdx => $exItem)
            <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="excl-row">
                <input type="text" name="exclusions[{{ $exIdx }}]" class="form-control" style="font-size:.875rem;"
                       value="{{ is_string($exItem) ? $exItem : '' }}">
                <button type="button" onclick="this.closest('.excl-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
            </div>
            @empty
            <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="excl-row">
                <input type="text" name="exclusions[0]" class="form-control" style="font-size:.875rem;" placeholder="Exclusion item">
                <button type="button" onclick="this.closest('.excl-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
            </div>
            @endforelse
            </div>
            <button type="button" onclick="addExclRow()" class="btn btn-outline btn-sm" style="font-size:.8rem; margin-top:.35rem;">+ Add exclusion</button>
            <script>
            var exclIndex = {{ count(old('exclusions', $exclusionsList)) ?: 1 }};
            function addExclRow() {
                var container = document.getElementById('excl_list');
                var div = document.createElement('div');
                div.className = 'excl-row';
                div.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;';
                div.innerHTML = '<input type="text" name="exclusions['+exclIndex+']" class="form-control" style="font-size:.875rem;">'
                    + '<button type="button" onclick="this.closest(\'.excl-row\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>';
                container.appendChild(div); exclIndex++;
            }
            </script>

            {{-- ── Decommissioning Procedure ───────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Decommissioning Procedure</h3>
            @php
                $decomEnabled  = old('decommissioning_enabled',             $decommData['enabled']                  ?? false);
                $decomLabel    = old('decommissioning_labelling_procedure', $decommData['labelling_procedure']       ?? '');
                $decomStorage  = old('decommissioning_storage_location',    $decommData['storage_location']          ?? '');
                $decomSignOff  = old('decommissioning_client_sign_off',     $decommData['client_sign_off_required']  ?? false);
                $decomDisposal = old('decommissioning_disposal_method',     $decommData['disposal_method']           ?? '');
                $decomSteps    = old('decommissioning_steps',               $decommData['steps']                     ?? []);
            @endphp
            <div class="form-group" style="margin-bottom:.5rem;">
                <label style="display:flex; align-items:center; gap:.5rem; font-size:.9rem; cursor:pointer;">
                    <input type="checkbox" name="decommissioning_enabled" value="1" id="decomm_toggle"
                           {{ $decomEnabled ? 'checked' : '' }}>
                    This project includes decommissioning / removal of existing equipment
                </label>
            </div>
            <div id="decomm_section" style="{{ $decomEnabled ? '' : 'display:none;' }}">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Labelling Procedure</label>
                        <input type="text" name="decommissioning_labelling_procedure" class="form-control"
                               style="font-size:.875rem;" value="{{ $decomLabel }}" placeholder="e.g. Label all cables before disconnection">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Storage Location</label>
                        <input type="text" name="decommissioning_storage_location" class="form-control"
                               style="font-size:.875rem;" value="{{ $decomStorage }}" placeholder="e.g. Client stores in Plant Room B">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Disposal Method</label>
                        <input type="text" name="decommissioning_disposal_method" class="form-control"
                               style="font-size:.875rem;" value="{{ $decomDisposal }}" placeholder="e.g. WEEE registered carrier, 21CAV to arrange">
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; padding-top:1.5rem;">
                        <label style="display:flex; align-items:center; gap:.5rem; font-size:.875rem; cursor:pointer;">
                            <input type="checkbox" name="decommissioning_client_sign_off" value="1"
                                   {{ $decomSignOff ? 'checked' : '' }}>
                            Client sign-off required before removal
                        </label>
                    </div>
                </div>
                <label class="form-label" style="margin-top:.5rem;">Decommissioning Steps (ordered)</label>
                <div id="decomm_steps_list">
                @forelse($decomSteps as $dsIdx => $dsStep)
                    <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="decomm-step-row">
                        <span style="font-size:.8rem; color:#888; min-width:22px;">{{ $dsIdx + 1 }}.</span>
                        <input type="text" name="decommissioning_steps[{{ $dsIdx }}]" class="form-control"
                               style="font-size:.875rem;" value="{{ is_string($dsStep) ? $dsStep : '' }}">
                        <button type="button" onclick="this.closest('.decomm-step-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
                    </div>
                @empty
                    <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="decomm-step-row">
                        <span style="font-size:.8rem; color:#888; min-width:22px;">1.</span>
                        <input type="text" name="decommissioning_steps[0]" class="form-control"
                               style="font-size:.875rem;" placeholder="e.g. Power down and isolate existing equipment">
                        <button type="button" onclick="this.closest('.decomm-step-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
                    </div>
                @endforelse
                </div>
                <button type="button" onclick="addDecommStep()" class="btn btn-outline btn-sm" style="font-size:.8rem; margin-top:.35rem;">+ Add step</button>
            </div>
            <script>
                document.getElementById('decomm_toggle').addEventListener('change', function() {
                    document.getElementById('decomm_section').style.display = this.checked ? '' : 'none';
                });
                var decommStepIndex = {{ count($decomSteps) ?: 1 }};
                function addDecommStep() {
                    var container = document.getElementById('decomm_steps_list');
                    var rowNum = container.querySelectorAll('.decomm-step-row').length + 1;
                    var div = document.createElement('div');
                    div.className = 'decomm-step-row';
                    div.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;';
                    div.innerHTML = '<span style="font-size:.8rem;color:#888;min-width:22px;">'+rowNum+'.</span>'
                        + '<input type="text" name="decommissioning_steps['+decommStepIndex+']" class="form-control" style="font-size:.875rem;">'
                        + '<button type="button" onclick="this.closest(\'.decomm-step-row\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>';
                    container.appendChild(div); decommStepIndex++;
                }
            </script>

            {{-- ── Commissioning Criteria ──────────────────────────────────────────── --}}
            <h3 class="section-heading" style="margin-top:1rem;">Commissioning Criteria</h3>
            <p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Define pass criteria for each system or installation activity. Rendered as a sign-off table in the PDF.</p>
            <table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
                <thead>
                    <tr>
                        <th style="width:18%;">System</th>
                        <th>Criterion</th>
                        <th style="width:22%;">Verification Method</th>
                        <th style="width:20%;">Pass Condition</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="cc_tbody">
                @php $ccList = old('commissioning_criteria', $commCriteria); @endphp
                @forelse($ccList as $ccIdx => $ccRow)
                    @php $ccRow = is_array($ccRow) ? $ccRow : []; @endphp
                    <tr class="cc-row">
                        <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][system]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['system'] ?? '' }}"></td>
                        <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][criterion]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['criterion'] ?? '' }}"></td>
                        <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][verification_method]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['verification_method'] ?? '' }}"></td>
                        <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][pass_condition]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['pass_condition'] ?? '' }}"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @empty
                    <tr class="cc-row">
                        <td><input type="text" name="commissioning_criteria[0][system]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Display"></td>
                        <td><input type="text" name="commissioning_criteria[0][criterion]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Image displayed on all inputs"></td>
                        <td><input type="text" name="commissioning_criteria[0][verification_method]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Test each source"></td>
                        <td><input type="text" name="commissioning_criteria[0][pass_condition]" class="form-control" style="font-size:.85rem;" placeholder="e.g. No artefacts, full screen"></td>
                        <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <button type="button" onclick="addCcRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add criterion</button>
            <script>
            var ccRowIndex = {{ count(old('commissioning_criteria', $commCriteria)) ?: 1 }};
            function addCcRow() {
                var tbody = document.getElementById('cc_tbody');
                var tr = document.createElement('tr');
                tr.className = 'cc-row';
                tr.innerHTML = '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][system]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][criterion]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][verification_method]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][pass_condition]" class="form-control" style="font-size:.85rem;"></td>'
                    + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
                tbody.appendChild(tr); ccRowIndex++;
            }
            </script>

            <div style="display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem;">
                <button type="submit" class="btn btn-teal">
                    ↓ Save &amp; Download .docx
                </button>
                <a href="{{ route('rams.download-pdf', $rams) }}" class="btn btn-outline"
                   onclick="triggerFileDownload(this.href); return false;">
                    ↓ Download PDF
                </a>
                <a href="{{ route('rams.download', $rams) }}" class="btn btn-outline">
                    ↓ Download current .docx
                </a>
            </div>
        </form>
    </div>

    {{-- ── AI Summary (read-only) ───────────────────────────────────────────── --}}
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem;">

        {{-- Hazard summary --}}
        <div class="card card-sm">
            <h2 class="section-heading">Hazard Register <span style="font-weight:400; font-size:.85rem; color:#666;">({{ count($hazards) }} hazards)</span></h2>
            @if (empty($hazards))
                <p style="color:#666; font-size:.875rem;">No hazards generated.</p>
            @else
                <table class="data-table" style="font-size:.8rem;">
                    <thead><tr><th>Hazard</th><th style="width:55px;">Pre</th><th style="width:55px;">Post</th></tr></thead>
                    <tbody>
                    @foreach ($hazards as $h)
                        @php
                            $pre  = (int)($h['pre_likelihood']  ?? 1) * (int)($h['pre_severity']  ?? 1);
                            $post = (int)($h['post_likelihood'] ?? 1) * (int)($h['post_severity'] ?? 1);
                        @endphp
                        <tr>
                            <td>{{ $h['hazard'] ?? '—' }}</td>
                            <td style="text-align:center;">
                                <span class="badge" style="background:{{ $pre <= 6 ? '#D4EDDA' : ($pre <= 9 ? '#FFF3CD' : ($pre <= 14 ? '#FFD0A0' : '#FFDEDE')) }}; color:#333;">
                                    {{ $pre }}
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge" style="background:{{ $post <= 6 ? '#D4EDDA' : ($post <= 9 ? '#FFF3CD' : ($post <= 14 ? '#FFD0A0' : '#FFDEDE')) }}; color:#333;">
                                    {{ $post }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Method statement summary + PPE + Persons --}}
        <div>
            @if ($ms)
            <div class="card card-sm" style="margin-bottom:1.25rem;">
                <h2 class="section-heading">Method Statement</h2>
                <p style="font-size:.875rem; color:#444; margin-bottom:.75rem;">{{ $ms['introduction'] ?? '' }}</p>
                @if (!empty($ms['phases']))
                    <strong style="font-size:.8rem; color:#666;">Phases:</strong>
                    <ul style="margin:.35rem 0 0 1.1rem; font-size:.85rem;">
                        @foreach ($ms['phases'] as $phase)
                            <li>{{ $phase['name'] ?? '' }} <span style="color:#888;">({{ count($phase['procedures'] ?? []) }} steps)</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @endif

            <div class="card card-sm">
                <h2 class="section-heading" style="font-size:.9rem;">PPE &amp; Persons at Risk</h2>
                @if (!empty($ppe))
                    <p style="font-size:.8rem; color:#444; margin-bottom:.5rem;"><strong>PPE:</strong> {{ implode(', ', $ppe) }}</p>
                @endif
                @if (!empty($persons))
                    <p style="font-size:.8rem; color:#444;"><strong>Persons at risk:</strong> {{ implode(', ', $persons) }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Regenerate ────────────────────────────────────────────────────────── --}}
    <div class="card card-sm">
        <h2 class="section-heading" style="font-size:.9rem;">↺ Regenerate</h2>
        <p style="font-size:.875rem; color:#666; margin-bottom:.75rem;">Re-run the AI to produce a fresh RAMS document using the same source data.</p>
        <form method="POST" action="{{ route('rams.regenerate', $rams) }}">
            @csrf
            <button type="submit" class="btn btn-outline btn-sm"
                    onclick="return confirm('Regenerate this RAMS document? The current version will be replaced.')">
                ↺ Regenerate
            </button>
        </form>
    </div>

    {{-- ── Email form ───────────────────────────────────────────────────────── --}}
    <div class="card card-sm">
        <h2 class="section-heading" style="font-size:.9rem;">Email this RAMS</h2>
        <form method="POST" action="{{ route('rams.email', $rams) }}" style="display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap;">
            @csrf
            <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                <label class="form-label" for="recipient_email">Recipient Email</label>
                <input id="recipient_email" name="recipient_email" type="email"
                       class="form-control" placeholder="client@example.com">
            </div>
            <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
                <label class="form-label" for="recipient_name">Recipient Name <small style="font-weight:400;">(optional)</small></label>
                <input id="recipient_name" name="recipient_name" type="text"
                       class="form-control" placeholder="John Smith">
            </div>
            <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:.05rem;">Send</button>
        </form>
    </div>

@endsection

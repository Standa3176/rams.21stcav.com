@extends('layouts.app')

@section('title', 'Edit Cable Schedule')

@section('content')

<div class="page-header">
    <h1 class="page-title">{{ $schedule->project_name }}</h1>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        {{-- Cable-schedule re-audit — the download endpoint existed
             but neither the edit page nor the index linked to it, so
             users had no way to actually pull the generated file. --}}
        @if ($schedule->source_filename)
            <a href="{{ route('cable-schedules.download', $schedule) }}"
               class="btn btn-primary btn-sm"
               title="Download the generated {{ pathinfo($schedule->source_filename, PATHINFO_EXTENSION) === 'csv' ? 'CSV' : 'XLSX' }}">
                ↓ Download {{ strtoupper(pathinfo($schedule->source_filename, PATHINFO_EXTENSION)) ?: 'File' }}
            </a>
        @endif
        <a href="{{ route('documents.revisions.view', ['type' => 'cable', 'id' => $schedule->id]) }}" class="btn btn-outline btn-sm">↻ History</a>
        {{-- 260712-ip3: surface the buried retry-generation route as a
             first-class button. Reuses the existing
             cable-schedules.retry-generation POST + policy check —
             appConfirm modal fires via data-confirm before submit. --}}
        @if(auth()->user()?->can('update', $schedule))
            <form method="POST"
                  action="{{ route('cable-schedules.retry-generation', $schedule) }}"
                  style="display:inline;margin:0;">
                @csrf
                <button type="submit"
                        class="btn btn-outline btn-sm"
                        data-confirm="Regenerate this cable schedule? Existing rows will be replaced."
                        data-confirm-label="Regenerate"
                        @if($schedule->status === \App\Models\CableSchedule::STATUS_GENERATING)
                            disabled
                            style="opacity:.5;pointer-events:none;cursor:not-allowed;"
                            title="Generation already in progress"
                        @else
                            title="Regenerate cable rows from current project data"
                        @endif
                >
                    ↻ Regenerate
                </button>
            </form>
        @endif
        <x-document-edit-drawer
            type="cable"
            :id="$schedule->id"
            label="Cable Schedule"
            :visible="in_array($schedule->status, [\App\Models\CableSchedule::STATUS_DRAFT, \App\Models\CableSchedule::STATUS_FINAL])" />
        @if(auth()->user()?->isAdmin())
            <a href="{{ route('cable-schedules.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
        @endif
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <strong>Please fix the following:</strong>
        <ul style="margin:.25rem 0 0 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('cable-schedules.update', $schedule) }}" id="edit-form">
    @csrf
    @method('PUT')

    {{-- Status + meta --}}
    <div class="section-block" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="margin-bottom:.25rem;">Status</label>
            <select name="status" class="form-control" style="width:auto;">
                <option value="draft" {{ $schedule->status==='draft'  ? 'selected':'' }}>Draft</option>
                <option value="final" {{ $schedule->status==='final'  ? 'selected':'' }}>Final</option>
            </select>
        </div>
        <div style="flex:1;font-size:.875rem;color:#666;">
            @if ($schedule->source_filename)
                Source: <strong>{{ $schedule->source_filename }}</strong> ·
            @endif
            {{ $schedule->items->count() }} cable(s) ·
            Created {{ $schedule->created_at->format('d M Y') }}
        </div>
        <button type="submit" class="btn btn-teal">Save Changes</button>
    </div>

    {{-- Cable table — 9 columns (Phase 22 D-03: chain-link icon column
         inserted between From and To). Audit D-09 (2026-07-08) — the
         outer .card had overflow:hidden which clipped the table below
         1100px viewports. Wrapped in overflow-x:auto so tablet users
         can scroll horizontally to reach rack/port columns. --}}
    <div class="card" style="padding:0;overflow-x:auto;">
        <table class="data-table" id="cables-table" style="min-width:960px;">
            <thead>
                <tr>
                    {{-- Cable-schedule re-audit — Cable ID column widened
                         120→90px was clipping the standard "CAB-001" 7-char
                         label at ~5 visible chars because .form-control's
                         width:100% + 24px padding + 15px body font left
                         only ~5 chars in the 90px cell. --}}
                    <th style="width:120px;">Cable ID</th>
                    <th>From</th>
                    <th style="width:44px;text-align:center;" aria-label="Pick ports">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;color:var(--muted);" aria-hidden="true">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                        </svg>
                    </th>
                    <th>To</th>
                    <th>Type</th>
                    <th style="width:70px;">Cores</th>
                    <th style="width:90px;">Length&nbsp;(m)</th>
                    <th>Notes</th>
                    <th style="width:44px;"></th>
                </tr>
            </thead>
            <tbody id="cables-body">
                @foreach ($schedule->items as $i => $item)
                <tr>
                    <td><input type="text" name="items[{{ $i }}][cable_id]"  value="{{ $item->cable_id }}"        class="form-control" style="min-width:0;" maxlength="50"></td>
                    <td><input type="text" name="items[{{ $i }}][from_location]" value="{{ $item->from_location }}" class="form-control" style="min-width:0;" maxlength="200"></td>
                    {{-- Phase 22 D-03 chain-link icon trigger column --}}
                    <td style="text-align:center;padding:0 2px;">
                        <input type="hidden" name="items[{{ $i }}][source_device_id]"        value="{{ $item->source_device_id }}"        data-fk="source_device_id">
                        <input type="hidden" name="items[{{ $i }}][source_port_id]"          value="{{ $item->source_port_id }}"          data-fk="source_port_id">
                        <input type="hidden" name="items[{{ $i }}][dest_device_id]"          value="{{ $item->dest_device_id }}"          data-fk="dest_device_id">
                        <input type="hidden" name="items[{{ $i }}][dest_port_id]"            value="{{ $item->dest_port_id }}"            data-fk="dest_port_id">
                        <input type="hidden" name="items[{{ $i }}][connector_override_note]" value="{{ $item->connector_override_note }}" data-fk="connector_override_note">
                        <button type="button"
                                class="picker-trigger"
                                data-row-index="{{ $i }}"
                                aria-label="{{ $item->source_device_id ? 'Edit port mapping' : 'Pick ports for this cable' }}"
                                title="Pick ports for this cable"
                                onclick="openPortPickerForRow(this)"
                                style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:.25rem;line-height:1;color:{{ $item->source_device_id ? 'var(--accent-600)' : 'var(--ink-400)' }};">
                            🔗
                        </button>
                    </td>
                    <td><input type="text" name="items[{{ $i }}][to_location]"   value="{{ $item->to_location }}"   class="form-control" style="min-width:0;" maxlength="200"></td>
                    <td><input type="text" name="items[{{ $i }}][cable_type]"    value="{{ $item->cable_type }}"    class="form-control" style="min-width:0;" maxlength="100"></td>
                    <td><input type="text" name="items[{{ $i }}][cores]"         value="{{ $item->cores }}"         class="form-control" style="min-width:0;" maxlength="50"></td>
                    <td><input type="number" name="items[{{ $i }}][approx_length_m]" value="{{ $item->approx_length_m }}" class="form-control" style="min-width:0;" step="0.1" min="0"></td>
                    <td><input type="text" name="items[{{ $i }}][notes]"         value="{{ $item->notes }}"         class="form-control" style="min-width:0;" maxlength="500"></td>
                    <td><button type="button" onclick="this.closest('tr').remove()"
                                aria-label="Remove cable row" title="Remove cable"
                                style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:1.1rem;">✕</button></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:.75rem;display:flex;gap:.75rem;">
        <button type="button" class="btn btn-outline btn-sm" onclick="addRow()">+ Add Row</button>
        <button type="submit" class="btn btn-teal">Save Changes</button>
    </div>

    {{-- Phase 22 port picker modal — single instance per page (D-01, A5) --}}
    @include('cable-schedule._port-picker-modal', ['devicesWithPorts' => $devicesWithPorts ?? []])

</form>

@endsection

@push('scripts')
<script>
let rowIndex = {{ $schedule->items->count() }};

function addRow() {
    const i = rowIndex++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="items[${i}][cable_id]"          class="form-control" style="min-width:0;" maxlength="50" placeholder="CAB-${String(i+1).padStart(3,'0')}"></td>
        <td><input type="text" name="items[${i}][from_location]"     class="form-control" style="min-width:0;" maxlength="200"></td>
        <td style="text-align:center;padding:0 2px;">
            <input type="hidden" name="items[${i}][source_device_id]"        value="" data-fk="source_device_id">
            <input type="hidden" name="items[${i}][source_port_id]"          value="" data-fk="source_port_id">
            <input type="hidden" name="items[${i}][dest_device_id]"          value="" data-fk="dest_device_id">
            <input type="hidden" name="items[${i}][dest_port_id]"            value="" data-fk="dest_port_id">
            <input type="hidden" name="items[${i}][connector_override_note]" value="" data-fk="connector_override_note">
            <button type="button" class="picker-trigger" data-row-index="${i}" title="Pick ports for this cable"
                    onclick="openPortPickerForRow(this)"
                    style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:.25rem;line-height:1;color:var(--ink-400);">🔗</button>
        </td>
        <td><input type="text" name="items[${i}][to_location]"       class="form-control" style="min-width:0;" maxlength="200"></td>
        <td><input type="text" name="items[${i}][cable_type]"        class="form-control" style="min-width:0;" maxlength="100"></td>
        <td><input type="text" name="items[${i}][cores]"             class="form-control" style="min-width:0;" maxlength="50"></td>
        <td><input type="number" name="items[${i}][approx_length_m]" class="form-control" style="min-width:0;" step="0.1" min="0"></td>
        <td><input type="text" name="items[${i}][notes]"             class="form-control" style="min-width:0;" maxlength="500"></td>
        <td><button type="button" onclick="this.closest('tr').remove()"
                    aria-label="Remove cable row" title="Remove cable"
                    style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;">✕</button></td>
    `;
    document.getElementById('cables-body').appendChild(tr);
    tr.querySelector('input').focus();
}

// ── Phase 22 port picker event coordination ───────────────────────────────────
// The picker modal is rendered ONCE per page (D-01, A5 assumption). Each row's
// chain-link button dispatches a `port-picker:open` window event with the row's
// current FK values; the modal handleOpen() initialises from that. On Apply,
// the modal dispatches `port-picker:applied` which this handler resolves to the
// correct row by data-row-index, then writes the 5 hidden inputs + overwrites
// the from/to text inputs (D-04) + flips the icon colour to teal.

function openPortPickerForRow(btn) {
    const i = parseInt(btn.dataset.rowIndex, 10);
    const tr = btn.closest('tr');
    const get = (fk) => {
        const el = tr.querySelector(`input[data-fk="${fk}"]`);
        return el ? (el.value || null) : null;
    };
    const toInt = (v) => v ? parseInt(v, 10) : null;
    window.dispatchEvent(new CustomEvent('port-picker:open', { detail: {
        rowIndex: i,
        current: {
            sourceDeviceId: toInt(get('source_device_id')),
            sourcePortId:   toInt(get('source_port_id')),
            destDeviceId:   toInt(get('dest_device_id')),
            destPortId:     toInt(get('dest_port_id')),
            overrideNote:   get('connector_override_note') || '',
        },
    }}));
}

window.addEventListener('port-picker:applied', function (e) {
    const d = e.detail;
    const trigger = document.querySelector(`button.picker-trigger[data-row-index="${d.rowIndex}"]`);
    if (!trigger) return;
    const tr = trigger.closest('tr');

    const setHidden = (fk, v) => {
        const el = tr.querySelector(`input[data-fk="${fk}"]`);
        if (el) el.value = (v == null ? '' : v);
    };

    setHidden('source_device_id', d.sourceDeviceId);
    setHidden('source_port_id',   d.sourcePortId);
    setHidden('dest_device_id',   d.destDeviceId);
    setHidden('dest_port_id',     d.destPortId);
    setHidden('connector_override_note', d.overrideNote);

    // D-04 — overwrite From/To text with canonical labels on Apply.
    // On Clear, leave From/To text as engineer last typed it.
    if (!d.cleared && d.sourceLabel) {
        const fromEl = tr.querySelector(`input[name="items[${d.rowIndex}][from_location]"]`);
        if (fromEl) fromEl.value = d.sourceLabel;
    }
    if (!d.cleared && d.destLabel) {
        const toEl = tr.querySelector(`input[name="items[${d.rowIndex}][to_location]"]`);
        if (toEl) toEl.value = d.destLabel;
    }

    // D-03 icon state: faded outline when unset, teal when set.
    // Cable-schedule re-audit — was hardcoded teal/grey. Now reads the
    // Jetbuilt accent-600 vs ink-400 via CSS custom properties so the
    // link icon flips consistently with the rest of the app.
    var rootStyle = getComputedStyle(document.documentElement);
    var setColor  = rootStyle.getPropertyValue('--accent-600').trim() || '#2E7BFF';
    var unsetColor = rootStyle.getPropertyValue('--ink-400').trim() || '#94A3B8';
    trigger.style.color = (d.sourceDeviceId ? setColor : unsetColor);
});
</script>
@endpush

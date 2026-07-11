@extends('layouts.app')

@section('title', 'Edit Device')

@push('styles')
<style>
/*
 * Device edit — Tier 4 admin surface (quick task 260711-q7q).
 * Signal-role pill palette matches the index-page badge palette so
 * classified state reads consistently across the two screens.
 */

.dv-form-card { padding: 20px 24px; margin-bottom: 16px; }
.dv-form-card + .dv-form-card { margin-top: 0; }
.dv-form-card h2 {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-900);
    letter-spacing: -0.005em;
    margin: 0 0 4px 0;
}
.dv-form-card .dv-card-hint {
    font-size: 12px;
    color: var(--text-muted);
    margin: 0 0 16px 0;
}

.dv-readonly-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px 20px;
}
.dv-readonly-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 3px;
}
.dv-readonly-value {
    font-size: 13px;
    color: var(--ink-900);
    font-weight: 500;
}

.dv-form-row { margin-bottom: 16px; }
.dv-form-row:last-child { margin-bottom: 0; }
.dv-form-row label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--body);
    margin-bottom: 5px;
    letter-spacing: -0.005em;
}
.dv-form-row input[type=text],
.dv-form-row input[type=number] {
    width: 100%;
    max-width: 320px;
    padding: 8px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    color: var(--ink-900);
    background: var(--surface);
    transition: border-color 120ms, box-shadow 120ms;
}
.dv-form-row input:focus {
    outline: none;
    border-color: var(--teal-500);
    box-shadow: var(--shadow-focus);
}
.dv-form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

/* Signal-role radio-pill group */
.dv-pill-group {
    display: inline-flex;
    gap: 8px;
    flex-wrap: wrap;
}
.dv-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 999px;
    white-space: nowrap;
    letter-spacing: -0.005em;
    cursor: pointer;
    background: var(--surface-soft);
    color: var(--text-muted);
    border: 1px solid var(--border);
    transition: background 120ms, color 120ms, border-color 120ms;
}
.dv-pill:hover { border-color: var(--border-strong); }
.dv-pill input { position: absolute; opacity: 0; pointer-events: none; }

.dv-pill.dv-pill-active-source        { background: var(--accent-600);   color: #fff; border-color: var(--accent-600); }
.dv-pill.dv-pill-active-destination   { background: var(--success);      color: #fff; border-color: var(--success); }
.dv-pill.dv-pill-active-processor     { background: #7C3AED;             color: #fff; border-color: #7C3AED; }
.dv-pill.dv-pill-active-unclassified  { background: var(--ink-900);      color: #fff; border-color: var(--ink-900); }

.dv-check-row {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-weight: 500;
    color: var(--ink-900);
}

.dv-poe-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px 20px;
    max-width: 640px;
}

.dv-footer-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 8px;
}
</style>
@endpush

@section('content')
<x-edit-action-bar
    :form-id="'device-edit-form'"
    :cancel-url="route('admin.devices.index', ['project_id' => $device->project_id])"
    save-label="Save Device">
    <x-slot name="title">
        Edit: {{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: 'Device #'.$device->id }}
    </x-slot>
</x-edit-action-bar>

@if ($errors->any())
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST"
      action="{{ route('admin.devices.update', $device) }}"
      id="device-edit-form">
    @csrf
    @method('PUT')

    {{-- ── A · Identity (read-only + room name) ─────────────────────────── --}}
    <div class="card dv-form-card">
        <h2>Identity</h2>
        <p class="dv-card-hint">Read-only except for room name — device rows are created by the label-photo capture flow and quote imports.</p>

        <div class="dv-readonly-grid" style="margin-bottom: 16px;">
            <div>
                <div class="dv-readonly-label">Project</div>
                <div class="dv-readonly-value">{{ $device->project_id ? '#'.$device->project_id : '—' }}</div>
            </div>
            <div>
                <div class="dv-readonly-label">Manufacturer</div>
                <div class="dv-readonly-value">{{ $device->manufacturer ?: '—' }}</div>
            </div>
            <div>
                <div class="dv-readonly-label">Model</div>
                <div class="dv-readonly-value">{{ $device->model ?: '—' }}</div>
            </div>
            <div>
                <div class="dv-readonly-label">Part No.</div>
                <div class="dv-readonly-value">{{ $device->part_no ?: '—' }}</div>
            </div>
        </div>

        <div class="dv-form-row">
            <label for="room_name">Room name</label>
            <input type="text" id="room_name" name="room_name"
                   value="{{ old('room_name', $device->room_name) }}"
                   maxlength="120" autocomplete="off">
            <p class="dv-form-hint">The room the device physically sits in. Empty = unassigned (rows fall back to "Unknown Room" in DAG output).</p>
            @error('room_name') <p class="dv-form-hint" style="color:var(--danger)">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- ── B · Signal Role ──────────────────────────────────────────────── --}}
    <div class="card dv-form-card">
        <h2>Signal Role</h2>
        <p class="dv-card-hint">Drives the cable-schedule DAG. Sources originate signals, destinations sink them, processors sit in between (matrix / DSP / codec). Unclassified devices fall through to the flat row-per-device path.</p>

        @php
            $currentRole = old('signal_role', $device->signal_role) ?: 'unclassified';
            $roles = [
                'source'       => 'Source',
                'destination'  => 'Destination',
                'processor'    => 'Processor',
                'unclassified' => 'Unclassified',
            ];
        @endphp
        <div class="dv-pill-group">
            @foreach ($roles as $roleValue => $roleLabel)
                @php $isActive = $currentRole === $roleValue; @endphp
                <label class="dv-pill {{ $isActive ? 'dv-pill-active-'.$roleValue : '' }}">
                    <input type="radio" name="signal_role" value="{{ $roleValue }}" {{ $isActive ? 'checked' : '' }}>
                    {{ $roleLabel }}
                </label>
            @endforeach
        </div>
        @error('signal_role') <p class="dv-form-hint" style="color:var(--danger)">{{ $message }}</p> @enderror
    </div>

    {{-- ── C · Redundancy ───────────────────────────────────────────────── --}}
    <div class="card dv-form-card">
        <h2>Redundancy</h2>
        <p class="dv-card-hint">Emit a paired backup cable row (-R) for this device when it acts as a processor. Only classified processors trigger the redundant row emission.</p>

        {{-- Hidden input carries "0" when the checkbox is unchecked (defence
             in depth alongside prepareForValidation()'s boolean coercion). --}}
        <input type="hidden" name="is_critical" value="0">
        <label class="dv-check-row">
            <input type="checkbox" name="is_critical" value="1"
                   {{ old('is_critical', $device->is_critical) ? 'checked' : '' }}>
            <span>Critical processor</span>
        </label>
        @error('is_critical') <p class="dv-form-hint" style="color:var(--danger)">{{ $message }}</p> @enderror
    </div>

    {{-- ── D · PoE budgeting ────────────────────────────────────────────── --}}
    <div class="card dv-form-card">
        <h2>PoE Budgeting</h2>
        <p class="dv-card-hint">Powers the checkPoeBudgets solver. Both fields are optional — leave blank for non-PoE devices.</p>

        <div class="dv-poe-grid">
            <div class="dv-form-row">
                <label for="pse_budget_w">PSE budget (W)</label>
                <input type="number" id="pse_budget_w" name="pse_budget_w"
                       value="{{ old('pse_budget_w', $device->pse_budget_w) }}"
                       step="0.5" min="0" max="9999" autocomplete="off">
                <p class="dv-form-hint">For network switches: total PoE watts the PSU can deliver. Leave blank if not a PSE device.</p>
                @error('pse_budget_w') <p class="dv-form-hint" style="color:var(--danger)">{{ $message }}</p> @enderror
            </div>

            <div class="dv-form-row">
                <label for="pd_load_w">PD load (W)</label>
                <input type="number" id="pd_load_w" name="pd_load_w"
                       value="{{ old('pd_load_w', $device->pd_load_w) }}"
                       step="0.5" min="0" max="9999" autocomplete="off">
                <p class="dv-form-hint">For PoE-powered endpoints (cameras / codecs / panels): watts drawn over the PoE cable. Leave blank if not a PoE PD.</p>
                @error('pd_load_w') <p class="dv-form-hint" style="color:var(--danger)">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- ── E · Footer actions ───────────────────────────────────────────── --}}
    <div class="dv-footer-actions">
        <button type="submit" class="btn btn-teal">Save Device</button>
        <a href="{{ route('admin.devices.index', ['project_id' => $device->project_id]) }}" class="btn btn-outline btn-sm">Cancel</a>
    </div>
</form>
@endsection

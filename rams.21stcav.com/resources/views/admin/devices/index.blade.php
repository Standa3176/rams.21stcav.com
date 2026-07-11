@extends('layouts.app')

@section('title', 'Devices')

@push('styles')
<style>
/*
 * Devices admin — Tier 4 admin surface (quick task 260711-q7q).
 * Reuses the shared .page-header + .card primitives; screen-scoped
 * .dv-* classes for the compact table + signal-role badge palette.
 */

.dv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dv-table th {
    background: var(--surface-soft);
    text-align: left;
    padding: 10px 16px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.dv-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--rule);
    vertical-align: middle;
    color: var(--body);
}
.dv-table tbody tr:last-child td { border-bottom: none; }
.dv-table tbody tr:hover td { background: color-mix(in oklab, var(--teal-100) 22%, transparent); }

.dv-project     { color: var(--text-muted); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.dv-room        { color: var(--ink-900); font-weight: 600; letter-spacing: -0.005em; }
.dv-muted       { color: var(--text-muted); font-size: 12px; }
.dv-equipment   { color: var(--ink-900); font-weight: 600; letter-spacing: -0.005em; }
.dv-partno      { font-size: 11px; color: var(--text-muted); font-family: var(--font-mono); margin-top: 1px; }

/* Signal-role badge — solid fill for classified, hairline muted for null */
.dv-badge-role {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
    letter-spacing: -0.005em;
}
.dv-badge-role-source        { background: var(--accent-600);            color: #fff; }
.dv-badge-role-destination   { background: var(--success);               color: #fff; }
.dv-badge-role-processor     { background: #7C3AED;                      color: #fff; }
.dv-badge-role-unclassified  { background: var(--surface-soft);          color: var(--text-muted); border: 1px solid var(--border); }

.dv-critical { color: var(--danger); font-size: 15px; text-align: center; }

.dv-poe-line { font-size: 11px; color: var(--text-muted); font-variant-numeric: tabular-nums; line-height: 1.35; }

.dv-filter-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 12px;
    padding: 12px 14px;
    background: var(--surface-soft);
    border: 1px solid var(--border);
    border-radius: 6px;
}
.dv-filter-row input,
.dv-filter-row select {
    padding: 6px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    color: var(--ink-900);
    background: var(--surface);
    min-width: 180px;
}
.dv-filter-row label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
}
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Devices</h1>
        <div class="page-subtitle">
            Tier 4 asset register — flip signal role, critical flag, PoE budget metadata, and room assignment.
            @if ($project) &middot; filtered to <strong>{{ $project->name }}</strong> @endif
        </div>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<form method="GET" action="{{ route('admin.devices.index') }}" class="dv-filter-row">
    <label for="dv-q">Search</label>
    <input type="text" id="dv-q" name="q" value="{{ $q }}" placeholder="manufacturer / model / part no…" autocomplete="off">

    <label for="dv-project">Project</label>
    <select id="dv-project" name="project_id">
        <option value="">All projects</option>
        @foreach ($projects as $p)
            <option value="{{ $p->id }}" {{ (int) $projectId === (int) $p->id ? 'selected' : '' }}>
                {{ $p->name }}
            </option>
        @endforeach
    </select>

    <button type="submit" class="btn btn-teal btn-sm">Apply</button>
    @if ($q !== '' || $projectId > 0)
        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline btn-sm">Clear</a>
    @endif
</form>

<div class="card" style="padding: 0; overflow: hidden;">
    <table class="dv-table">
        <thead>
            <tr>
                <th>Project</th>
                <th>Room</th>
                <th>Equipment</th>
                <th>Signal Role</th>
                <th style="text-align:center;">Critical</th>
                <th>PoE</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($devices as $device)
                <tr>
                    <td>
                        @if ($device->project_id)
                            <a href="{{ route('projects.show', $device->project_id) }}" class="dv-project">#{{ $device->project_id }}</a>
                        @else
                            <span class="dv-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if ($device->room_name)
                            <span class="dv-room">{{ $device->room_name }}</span>
                        @else
                            <span class="dv-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="dv-equipment">
                            {{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: '—' }}
                        </div>
                        @if ($device->part_no)
                            <div class="dv-partno">{{ $device->part_no }}</div>
                        @endif
                    </td>
                    <td>
                        @php
                            $role = $device->signal_role ?: 'unclassified';
                        @endphp
                        <span class="dv-badge-role dv-badge-role-{{ $role }}">{{ ucfirst($role) }}</span>
                    </td>
                    <td class="dv-critical">
                        @if ($device->is_critical === true)
                            <span title="Critical processor — emits paired redundant cable rows">&#9888;</span>
                        @endif
                    </td>
                    <td>
                        @if ($device->pse_budget_w !== null)
                            <div class="dv-poe-line">PSE {{ rtrim(rtrim(number_format((float) $device->pse_budget_w, 2, '.', ''), '0'), '.') }}W</div>
                        @endif
                        @if ($device->pd_load_w !== null)
                            <div class="dv-poe-line">PD {{ rtrim(rtrim(number_format((float) $device->pd_load_w, 2, '.', ''), '0'), '.') }}W</div>
                        @endif
                        @if ($device->pse_budget_w === null && $device->pd_load_w === null)
                            <span class="dv-muted">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-outline btn-sm">Edit</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--text-muted);padding:32px;font-size:13px;">
                        No devices match your filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($devices->hasPages())
    <div style="margin-top:16px;">
        {{ $devices->appends(request()->query())->links() }}
    </div>
@endif
@endsection

@extends('layouts.app')

@section('title', 'Device Stencils')

@push('styles')
<style>
/*
 * Device Stencils admin — Phase 24 Plan 03 (DRAW-50) curation queue.
 * .stc-filter-row is a byte-identical sibling of admin/devices' .dv-filter-row
 * (12px 14px padding — UI-SPEC's deliberate reuse-as-is stance, do not
 * "correct" to a clean 8pt multiple). .stc-table overrides only the header
 * cell weight/case on top of the existing shared .data-table primitive.
 */

.stc-filter-row {
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
.stc-filter-row input,
.stc-filter-row select {
    padding: 6px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: var(--fs-small);
    color: var(--ink-900);
    background: var(--surface);
    min-width: 170px;
}
.stc-filter-row input#stc-q {
    font-family: var(--font-mono);
    min-width: 200px;
}
.stc-filter-row label {
    font-size: var(--fs-small);
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
}

/* Header eyebrow: 13px/--fs-small/600 uppercase tracked — NOT --fs-micro,
   NOT a hardcoded 10/11px value (UI-SPEC Typography). */
.stc-table th {
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.stc-partno       { font-family: var(--font-mono); color: var(--ink-900); }
.stc-manufacturer { color: var(--ink-900); font-weight: 600; letter-spacing: -0.005em; }
.stc-model-sub    { font-size: var(--fs-small); color: var(--text-muted); font-family: var(--font-mono); margin-top: 1px; }
.stc-ports-count  { font-variant-numeric: tabular-nums; }
.stc-logo-thumb {
    display: block;
    width: 28px;
    height: 28px;
    object-fit: contain;
    border: 1px solid var(--ink-200);
    border-radius: var(--radius-sm);
    background: var(--surface);
}
.stc-muted { color: var(--text-muted); }

.stc-empty {
    padding: 32px;
    text-align: center;
    color: var(--text-muted);
    font-size: var(--fs-small);
}
.stc-empty-heading {
    font-size: var(--fs-body);
    font-weight: 600;
    color: var(--ink-900);
    margin-bottom: 4px;
}
</style>
@endpush

@section('content')
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Device Stencils</h1>
        <div class="page-subtitle">
            Review queue for auto-generated stubs (Phase 24 DRAW-50). Filter by source, review status, or
            manufacturer — search by part number.
        </div>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@php
    $stcFiltersActive = $source !== '' || $needsReview !== '' || $manufacturer !== '' || $q !== '';
@endphp

<form method="GET" action="{{ route('admin.device-stencils.index') }}" class="stc-filter-row">
    <label for="stc-q">Search</label>
    <input type="text" id="stc-q" name="q" value="{{ $q }}" placeholder="part number…" autocomplete="off">

    <label for="stc-source">Source</label>
    <select id="stc-source" name="source">
        <option value="">All</option>
        <option value="{{ \App\Models\DeviceStencil::SOURCE_AUTO_GENERATED }}" {{ $source === \App\Models\DeviceStencil::SOURCE_AUTO_GENERATED ? 'selected' : '' }}>Auto-generated</option>
        <option value="{{ \App\Models\DeviceStencil::SOURCE_ENGINEER_CURATED }}" {{ $source === \App\Models\DeviceStencil::SOURCE_ENGINEER_CURATED ? 'selected' : '' }}>Engineer-curated</option>
        <option value="{{ \App\Models\DeviceStencil::SOURCE_AI_EXTRACTED }}" {{ $source === \App\Models\DeviceStencil::SOURCE_AI_EXTRACTED ? 'selected' : '' }}>AI-extracted</option>
    </select>

    <label for="stc-needs-review">Needs review</label>
    <select id="stc-needs-review" name="needs_review">
        <option value="">All</option>
        <option value="1" {{ (string) $needsReview === '1' ? 'selected' : '' }}>Yes</option>
        <option value="0" {{ (string) $needsReview === '0' ? 'selected' : '' }}>No</option>
    </select>

    <label for="stc-manufacturer">Manufacturer</label>
    <select id="stc-manufacturer" name="manufacturer">
        <option value="">All</option>
        @foreach ($manufacturers as $m)
            <option value="{{ $m }}" {{ $manufacturer === $m ? 'selected' : '' }}>{{ $m }}</option>
        @endforeach
    </select>

    <button type="submit" class="btn btn-teal btn-sm">Apply</button>
    @if ($stcFiltersActive)
        <a href="{{ route('admin.device-stencils.index') }}" class="btn btn-outline btn-sm">Clear</a>
    @endif
</form>

<div class="card" style="padding: 0; overflow: hidden;">
    <table class="data-table stc-table">
        <thead>
            <tr>
                <th>Part Number</th>
                <th>Manufacturer / Model</th>
                <th>Source</th>
                <th>Ports</th>
                <th>Logo</th>
                <th>Updated</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stencils as $stencil)
                <tr>
                    <td>
                        <span class="stc-partno">{{ $stencil->part_number }}</span>
                        @if ($stencil->needs_review)
                            <span class="badge badge-yellow" style="margin-left:6px;">Needs review</span>
                        @endif
                    </td>
                    <td>
                        <div class="stc-manufacturer">
                            {{ trim(($stencil->manufacturer ?? '') . ' ' . ($stencil->model ?? '')) ?: ($stencil->display_name ?: '—') }}
                        </div>
                        <div class="stc-model-sub">{{ $stencil->part_number }}</div>
                    </td>
                    <td>
                        @if ($stencil->source === \App\Models\DeviceStencil::SOURCE_ENGINEER_CURATED)
                            <span class="badge badge-green">Engineer-curated</span>
                        @elseif ($stencil->source === \App\Models\DeviceStencil::SOURCE_AI_EXTRACTED)
                            <span class="badge badge-blue">AI-extracted</span>
                        @else
                            <span class="badge badge-grey">Auto-generated</span>
                        @endif
                    </td>
                    <td class="stc-ports-count">{{ $stencil->ports_count }}</td>
                    <td>
                        @if ($stencil->logo_path)
                            <img src="{{ asset($stencil->logo_path) }}" alt="" class="stc-logo-thumb">
                        @else
                            <span class="stc-muted">—</span>
                        @endif
                    </td>
                    <td class="stc-muted">{{ $stencil->updated_at?->diffForHumans() }}</td>
                    <td style="text-align:right;">
                        @if (Route::has('admin.device-stencils.edit'))
                            <a href="{{ route('admin.device-stencils.edit', $stencil) }}" class="btn btn-outline btn-sm">Edit</a>
                        @else
                            {{-- Edit action ships in Plan 24-04, same controller class --}}
                            <span class="stc-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="stc-empty">
                        @if ($stcFiltersActive)
                            <div class="stc-empty-heading">No stencils match your filters.</div>
                            <a href="{{ route('admin.device-stencils.index') }}" style="color:var(--teal-700, var(--accent-600));font-weight:600;">Clear filters</a>
                        @else
                            <div class="stc-empty-heading">No stencils awaiting curation.</div>
                            <div>Every auto-generated stub has been promoted. New stubs appear here automatically when a quote import references an uncatalogued part number.</div>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($stencils->hasPages())
    <div style="margin-top:16px;">
        {{ $stencils->appends(request()->query())->links() }}
    </div>
@endif
@endsection

@extends('layouts.app')

@section('title', 'Worksheet: ' . $worksheet->project_name)

@push('styles')
<style>
/* ── Room cards (copied verbatim from site-survey/show.blade.php) ─── */
.survey-room-card {
    background:#fff;
    border:1.5px solid #e5e7eb;
    border-radius:8px;
    margin-bottom:.75rem;
    overflow:hidden;
    box-shadow:var(--shadow-sm);
}
.survey-room-card--complete {
    border-color:#6EE7B7;
}
.room-view-hdr {
    display:flex;
    align-items:center;
    gap:.75rem;
    padding:.9rem 1.1rem;
    cursor:pointer;
    user-select:none;
}
.room-view-hdr--complete   { background:#D1FAE5; }
.room-view-hdr--empty      { background:#F9FAFB; }
.room-view-name {
    flex:1;
    font-weight:700;
    font-size:.975rem;
    color:#0B3C45;
}
.room-view-badge {
    font-size:.7rem;
    font-weight:700;
    padding:.15rem .55rem;
    border-radius:20px;
    white-space:nowrap;
}
.room-view-badge--complete { background:#A7F3D0; color:#065F46; }
.room-view-badge--empty    { background:#E5E7EB; color:#6B7280; }
.room-view-chevron {
    color:#9CA3AF;
    font-size:.85rem;
    transition:transform 200ms;
}
.room-view-chevron.open { transform:rotate(90deg); }
.room-view-body {
    padding:0 1.1rem 1rem;
    display:none;
}
.room-view-body.open { display:block; }

/* ── Field table ─────────────────────────────────────────────────── */
.field-table {
    width:100%;
    border-collapse:collapse;
    font-size:.875rem;
    margin-bottom:1rem;
}
.field-table th {
    background:#F3F6F7;
    font-size:.7rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:var(--text-muted);
    padding:.5rem .75rem;
    text-align:left;
    border-bottom:1px solid var(--border);
}
.field-table td {
    padding:.45rem .75rem;
    border-bottom:1px solid #f5f5f5;
    vertical-align:top;
    color:#374151;
}
.field-table tr:last-child td { border-bottom:none; }
.field-table td:first-child {
    width:34%;
    font-weight:600;
    color:#4B5563;
    font-size:.82rem;
}
.field-table td:last-child {
    white-space:pre-wrap;
}

/* ── Section heading inside room ─────────────────────────────────── */
.room-section-hdr {
    font-size:.7rem;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--teal);
    border-top:1px solid #f0f0f0;
    padding-top:.75rem;
    margin:.75rem 0 .5rem;
}
</style>
@endpush

@section('content')

{{-- Breadcrumb --}}
<nav style="font-size:.875rem;margin-bottom:1rem;">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    @if($worksheet->project)
        &rsaquo;
        <a href="{{ route('projects.show', $worksheet->project) }}" style="color:var(--teal);text-decoration:none;">{{ $worksheet->project->name }}</a>
    @endif
    &rsaquo;
    <span style="color:var(--text-muted);">Worksheet</span>
</nav>

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Worksheet: {{ $worksheet->project_name }}</h1>
        <p class="page-subtitle" style="color:var(--text-muted);margin-top:.25rem;font-size:.875rem;">
            {{ $worksheet->client_name }}
            @if($worksheet->site_address) · {{ $worksheet->site_address }} @endif
            @if($worksheet->project_ref) · Ref: {{ $worksheet->project_ref }} @endif
        </p>
    </div>
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        @if(in_array($worksheet->status, ['draft', 'final']))
            <a href="{{ route('worksheets.download', $worksheet) }}"
               class="btn-teal"
               target="_blank"
               aria-label="Download Worksheet DOCX">↓ Download</a>
        @endif
        @if($worksheet->project)
            <a href="{{ route('projects.show', $worksheet->project) }}" class="btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('worksheets.index') }}" class="btn-outline btn-sm">← All Worksheets</a>
        @endif
        <a href="{{ route('documents.revisions.view', ['type' => 'worksheet', 'id' => $worksheet->id]) }}" class="btn-outline btn-sm">↻ History</a>
        <x-document-edit-drawer
            type="worksheet"
            :id="$worksheet->id"
            label="Worksheet"
            :visible="in_array($worksheet->status, ['draft', 'final'])" />
    </div>
</div>

{{-- Status bar --}}
<div class="card card-sm" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;">
    <div>
        <x-dashboard.status-badge :status="$worksheet->status" />
    </div>
    <div style="font-size:.875rem;color:var(--text-muted);">
        @if(in_array($worksheet->status, ['pending', 'generating']))
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:8px;height:8px;border-radius:50%;background:#D97706;display:inline-block;"></span>
                Generating…
            </span>
        @else
            Generated {{ $worksheet->updated_at->diffForHumans() }}
        @endif
    </div>
</div>

{{-- Error alert --}}
@if($worksheet->status === 'failed' && $worksheet->error_message)
    <div class="alert alert-error" style="margin-bottom:1.25rem;">
        Generation failed: {{ $worksheet->error_message }}. Click Retry Generation to try again.
    </div>
@endif

{{-- Client Sign-Off Link --}}
<div class="card card-sm" style="margin-bottom:1.25rem;" x-data="{ url: '{{ $worksheet->publicUrl() }}', copied: false }">
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:.4rem;">
        Client Sign-Off Link
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="text" :value="url" readonly
               style="flex:1;min-width:260px;font-size:.82rem;padding:.45rem .65rem;border:1px solid var(--border);border-radius:6px;background:#fafbfc;"
               @click="$event.target.select()">
        <button type="button" class="btn-outline btn-sm"
                @click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 1500);"
                x-text="copied ? '✓ Copied' : 'Copy'"></button>
        <a :href="url" target="_blank" class="btn-outline btn-sm">Open ↗</a>
    </div>
    @if($worksheet->isSigned())
        @php $sig = $worksheet->latestSignoff(); @endphp
        <div style="margin-top:.5rem;font-size:.78rem;color:#065F46;">
            ✓ Signed by {{ $sig->client_name }} on {{ $sig->signed_at->format('d M Y H:i') }}
            @if($sig->signed_with_comments) <span style="color:#92400E;font-weight:600;">(signed with comments)</span>@endif
        </div>
    @endif
</div>

{{-- Room accordion --}}
@php
    $rooms = $worksheet->generated_data['rooms'] ?? [];
@endphp

@if(empty($rooms))
    <div class="card card-sm" style="color:var(--text-muted);font-size:.875rem;text-align:center;padding:2rem;">
        @if(in_array($worksheet->status, ['pending', 'generating']))
            Worksheet is being generated. This page will update when complete.
        @else
            No room data available.
        @endif
    </div>
@else
    @foreach($rooms as $room)
        @php
            $isSurveyed = $room['is_surveyed'] ?? false;
            $cardClass  = $isSurveyed ? 'survey-room-card survey-room-card--complete' : 'survey-room-card';
            $hdrClass   = $isSurveyed ? 'room-view-hdr room-view-hdr--complete' : 'room-view-hdr room-view-hdr--empty';
            $badgeClass = $isSurveyed ? 'room-view-badge room-view-badge--complete' : 'room-view-badge room-view-badge--empty';
            $badgeText  = $isSurveyed ? 'Surveyed' : 'Not surveyed';
        @endphp

        <div class="{{ $cardClass }}" x-data="{ open: false }">

            {{-- Room header --}}
            <div class="{{ $hdrClass }}"
                 role="button"
                 @click="open = !open"
                 :aria-expanded="open ? 'true' : 'false'">
                <span class="room-view-name">{{ $room['name'] ?? 'Unknown Room' }}</span>
                <span class="{{ $badgeClass }}">{{ $badgeText }}</span>
                <span class="room-view-chevron" :class="{ open: open }">▶</span>
            </div>

            {{-- Room body --}}
            <div class="room-view-body" x-show="open" x-cloak :class="{ open: open }">

                {{-- Section A: Equipment --}}
                <div class="room-section-hdr">Equipment</div>
                @php $equipment = $room['equipment'] ?? []; @endphp
                @if(empty($equipment))
                    <p style="color:var(--text-muted);font-size:.875rem;">No equipment listed for this room.</p>
                @else
                    <table class="field-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style="width:15%;">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($equipment as $item)
                                <tr>
                                    <td>{{ $item['name'] ?? $item['description'] ?? '—' }}</td>
                                    <td>{{ $item['quantity'] ?? 1 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Section B: Install Steps --}}
                <div class="room-section-hdr">Install Steps</div>
                @if(! empty($room['install_steps']))
                    <div style="font-size:.875rem;line-height:1.6;color:var(--text);white-space:pre-wrap;">{{ $room['install_steps'] }}</div>
                @else
                    <div style="display:inline-flex;align-items:center;gap:.4rem;background:#FEF3C7;color:#92400E;padding:.3rem .85rem;border-radius:20px;font-size:.78rem;font-weight:700;">
                        Install steps being generated…
                    </div>
                @endif

                {{-- Section C: Cable Routes --}}
                <div class="room-section-hdr">Cable Routes</div>
                @if(! empty($room['cable_route_desc']))
                    <p style="font-size:.875rem;color:var(--text);">{{ $room['cable_route_desc'] }}</p>
                @else
                    <p style="color:var(--text-muted);font-size:.875rem;">Not surveyed</p>
                @endif

                {{-- Section D: Power & Network --}}
                <div class="room-section-hdr">Power & Network</div>
                <table class="field-table">
                    <tbody>
                        <tr>
                            <td>Power outlets</td>
                            <td>
                                @if(isset($room['power_outlet_count']) && $room['power_outlet_count'] !== null)
                                    {{ $room['power_outlet_count'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Additional power required</td>
                            <td>
                                @if(isset($room['requires_additional_power']) && $room['requires_additional_power'] !== null)
                                    {{ $room['requires_additional_power'] ? 'Yes' : 'No' }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Network ports</td>
                            <td>
                                @if(isset($room['network_port_count']) && $room['network_port_count'] !== null)
                                    {{ $room['network_port_count'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Existing cabling</td>
                            <td>
                                @if(isset($room['existing_cabling']) && $room['existing_cabling'] !== null)
                                    {{ $room['existing_cabling'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div>{{-- /.room-view-body --}}
        </div>{{-- /.survey-room-card --}}
    @endforeach
@endif

{{-- Footer action row --}}
<div class="card card-sm" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-top:1.25rem;">
    <div>
        @if(in_array($worksheet->status, ['draft', 'final']))
            <a href="{{ route('worksheets.download', $worksheet) }}"
               class="btn-teal"
               target="_blank"
               aria-label="Download Worksheet DOCX">Download DOCX</a>
        @else
            <span style="font-size:.875rem;color:var(--text-muted);">DOCX available once generation is complete.</span>
        @endif
    </div>
    <div>
        @if($worksheet->project)
            <a href="{{ route('projects.show', $worksheet->project) }}" class="btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('worksheets.index') }}" class="btn-outline btn-sm">← All Worksheets</a>
        @endif
        <a href="{{ route('documents.revisions.view', ['type' => 'worksheet', 'id' => $worksheet->id]) }}" class="btn-outline btn-sm">↻ History</a>
    </div>
</div>


@endsection

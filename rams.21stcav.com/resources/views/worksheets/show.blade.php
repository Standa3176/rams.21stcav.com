@extends('layouts.app')

@section('title', 'Worksheet: ' . $worksheet->project_name)

@push('styles')
<style>
/* ── Room cards — clean modern dashboard ─────────────────── */
.survey-room-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: .75rem;
    overflow: hidden;
    box-shadow: var(--shadow-xs);
}
.survey-room-card--complete { border-color: var(--success); }
.room-view-hdr {
    display: flex; align-items: center; gap: .75rem;
    padding: .9rem 1.1rem; cursor: pointer; user-select: none;
}
.room-view-hdr--complete   { background: var(--success-light); }
.room-view-hdr--empty      { background: var(--surface-soft); }
.room-view-name {
    flex: 1;
    font-weight: 600;
    font-size: .975rem;
    color: var(--text);
}
.room-view-badge {
    font-size: .7rem; font-weight: 600;
    padding: .15rem .55rem; border-radius: 999px;
    white-space: nowrap;
}
.room-view-badge--complete { background: #BBF7D0; color: #14532D; }
.room-view-badge--empty    { background: var(--surface-deep); color: var(--text-muted); }
.room-view-chevron {
    color: var(--text-muted); font-size: .85rem;
    transition: transform var(--transition);
}
.room-view-chevron.open { transform: rotate(90deg); }
.room-view-body { padding: 0 1.1rem 1rem; display: none; }
.room-view-body.open { display: block; }

/* ── Field table ─────────────────────────────────────────── */
.field-table {
    width: 100%; border-collapse: collapse;
    font-size: .875rem; margin-bottom: 1rem;
}
.field-table th {
    background: var(--surface-soft);
    font-size: .7rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted);
    padding: .5rem .75rem; text-align: left;
    border-bottom: 1px solid var(--border);
}
.field-table td {
    padding: .45rem .75rem;
    border-bottom: 1px solid var(--surface-deep);
    vertical-align: top;
    color: var(--text);
}
.field-table tr:last-child td { border-bottom: none; }
.field-table td:first-child {
    width: 34%;
    font-weight: 600;
    color: var(--text-muted);
    font-size: .82rem;
}
.field-table td:last-child { white-space: pre-wrap; }

/* ── Section heading inside room ────────────────────────── */
.room-section-hdr {
    font-size: .7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--teal);
    border-top: 1px solid var(--border);
    padding-top: .75rem;
    margin: .75rem 0 .5rem;
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
        @if(in_array($worksheet->status, ['draft', 'final', 'failed']))
            <form method="POST"
                  action="{{ route('worksheets.retry-generation', $worksheet) }}"
                  data-confirm="Regenerate this worksheet? The current DOCX will be replaced."
                  data-confirm-label="Regenerate"
                  style="display:inline;">
                @csrf
                <button type="submit"
                        class="btn-outline btn-sm"
                        aria-label="Regenerate Worksheet DOCX">
                    ↻ Regenerate
                </button>
            </form>
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

{{-- Stale-data banner (260602-o2a) — renders only when project.latestPackage
     has been edited after the worksheet snapshot was generated. --}}
@include('worksheets._stale-banner', ['worksheet' => $worksheet, 'variant' => 'admin'])

{{-- Sign-Off Status section — wraps status bar + client sign-off link card --}}
<div class="form-section">
    <div class="form-section__header">
        <h2 class="section-heading">Sign-Off Status</h2>
    </div>
    <div class="form-section__body">

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

{{-- Client Sign-Off Link — guarded for legacy worksheets that pre-date the
     access_token migration. publicUrl() throws when the token is null. --}}
@if($worksheet->access_token)
@php $worksheetPublicUrl = $worksheet->publicUrl(); @endphp
{{-- Client Sign-Off Link — Alpine state dropped, copy interaction now uses
     the standardised <x-copy-link-button> so it matches the rest of the
     app (260507 housekeeping). Input + Open link still inline-rendered. --}}
<div class="card card-sm" style="margin-bottom:1.25rem;">
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:.4rem;">
        Client Sign-Off Link
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="text" value="{{ $worksheetPublicUrl }}" readonly data-optional
               style="flex:1;min-width:260px;font-size:.82rem;padding:.45rem .65rem;border:1px solid var(--border);border-radius:6px;background:#fafbfc;"
               onclick="this.select()">
        <x-copy-link-button :url="$worksheetPublicUrl" label="Copy" />
        <a href="{{ $worksheetPublicUrl }}" target="_blank" class="btn-outline btn-sm">Open ↗</a>
    </div>
    @if($worksheet->isSigned())
        @php $sig = $worksheet->latestSignoff(); @endphp
        <div style="margin-top:.5rem;font-size:.78rem;color:#065F46;">
            ✓ Signed by {{ $sig->client_name }} on {{ $sig->signed_at->format('d M Y H:i') }}
            @if($sig->signed_with_comments) <span style="color:#92400E;font-weight:600;">(signed with comments)</span>@endif
        </div>
    @endif
</div>
@endif

    </div>
</div>{{-- /Sign-Off Status section --}}

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

                {{-- Section A2: Engineer Work Summary — F-WS-02 parity fix
                     (audit 2026-05-17). Renders works_summary_bullets when
                     available, else falls back to the prose paragraph in
                     room_works_description. Mirrors WorksheetDocxService
                     lines 278-279 so the web view matches the DOCX a user
                     downloads. --}}
                @php
                    $worksBullets = (array) ($room['works_summary_bullets'] ?? []);
                    $worksBullets = array_values(array_filter(array_map(
                        fn ($b) => trim((string) $b),
                        $worksBullets
                    ), fn ($b) => $b !== ''));
                    $worksDescription = trim((string) ($room['room_works_description'] ?? ''));
                @endphp
                @if(! empty($worksBullets) || $worksDescription !== '')
                    <div class="room-section-hdr">Engineer Work Summary</div>
                    @if(! empty($worksBullets))
                        <ul style="margin:0 0 1rem 1.25rem;padding:0;font-size:.875rem;line-height:1.6;color:var(--text);">
                            @foreach($worksBullets as $bullet)
                                <li style="margin-bottom:.25rem;">{{ $bullet }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p style="font-size:.875rem;line-height:1.6;color:var(--text);white-space:pre-wrap;margin-bottom:1rem;">{{ $worksDescription }}</p>
                    @endif
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

                {{-- Engineer-captured equipment label photos for this room.
                     Labels were photographed on-site → AI OCR'd → confirmed →
                     wrote serial / MAC / part values into the asset register. --}}
                @php
                    $labelPhotos = \App\Models\DeviceLabelPhoto::where('worksheet_id', $worksheet->id)
                        ->where('room_name', $room['name'] ?? '')
                        ->with('device')
                        ->orderBy('created_at')
                        ->get();
                    // 260508 — pre-compute label photo set for the lightbox cycler.
                    // Caption uses device description + part for context when cycling.
                    $labelPhotosLb = $labelPhotos->values()->map(function ($lp) {
                        $ai      = $lp->ai_extracted ?? [];
                        $caption = $lp->device?->description
                            ?: ($ai['part_number'] ?? 'Equipment label');
                        return [
                            'url'     => \Illuminate\Support\Facades\Storage::url($lp->photo_path),
                            'caption' => $caption,
                        ];
                    })->all();
                @endphp
                @if($labelPhotos->isNotEmpty())
                    <div class="room-section-hdr">Equipment Labels Captured ({{ $labelPhotos->count() }})</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.7rem;margin-bottom:1rem;">
                        @foreach($labelPhotos as $lp)
                            @php $ai = $lp->ai_extracted ?? []; @endphp
                            <div style="border:1px solid var(--border);border-radius:8px;padding:.65rem;background:var(--surface-soft);font-size:.78rem;line-height:1.4;">
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}"
                                   target="_blank"
                                   onclick="event.preventDefault(); openPhotoLightbox(@js($labelPhotosLb), {{ $loop->index }});"
                                   style="display:block;width:100%;height:120px;border-radius:6px;overflow:hidden;background:#F3F4F6;margin-bottom:.5rem;">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}"
                                         alt="Equipment label" loading="lazy"
                                         style="width:100%;height:100%;object-fit:cover;">
                                </a>
                                @if($lp->device)
                                    <div style="font-weight:600;color:var(--text);margin-bottom:.3rem;">{{ $lp->device->description }}</div>
                                @endif
                                <div><strong>Part:</strong> {{ $lp->device->part_no ?? ($ai['part_number'] ?? '—') }}</div>
                                <div><strong>Serial:</strong> {{ $lp->device->serial_number ?? ($ai['serial_number'] ?? '—') }}</div>
                                <div><strong>MAC:</strong> {{ $lp->device->mac_address ?? ($ai['mac_address'] ?? '—') }}</div>
                                <div style="margin-top:.4rem;">
                                    @if($lp->confirmed)
                                        <span style="display:inline-block;padding:1px 6px;border-radius:9999px;background:#DCFCE7;color:#166534;font-weight:600;font-size:.7rem;">✓ Confirmed</span>
                                    @else
                                        <span style="display:inline-block;padding:1px 6px;border-radius:9999px;background:#FEF3C7;color:#92400E;font-weight:600;font-size:.7rem;">Awaiting review</span>
                                    @endif
                                    <span style="color:var(--text-faint);font-size:.7rem;margin-left:.4rem;">
                                        {{ $lp->captured_at?->format('d M H:i') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

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
        @if(in_array($worksheet->status, ['draft', 'final', 'failed']))
            <form method="POST"
                  action="{{ route('worksheets.retry-generation', $worksheet) }}"
                  data-confirm="Regenerate this worksheet? The current DOCX will be replaced."
                  data-confirm-label="Regenerate"
                  style="display:inline;">
                @csrf
                <button type="submit"
                        class="btn-outline btn-sm"
                        aria-label="Regenerate Worksheet DOCX">
                    ↻ Regenerate
                </button>
            </form>
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

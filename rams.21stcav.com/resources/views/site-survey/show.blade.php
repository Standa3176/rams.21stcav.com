@extends('layouts.app')

@section('title', 'Survey — ' . $survey->project_name)

@push('styles')
<style>
/* ── Progress strip ───────────────────────────────── */
.survey-progress {
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:1rem 1.25rem;
    margin-bottom:1.25rem;
    display:flex;
    align-items:center;
    gap:1.25rem;
    flex-wrap:wrap;
    box-shadow:var(--shadow-sm);
}
.survey-progress__stat {
    text-align:center;
    min-width:80px;
}
.survey-progress__num {
    font-size:1.75rem;
    font-weight:800;
    color:var(--teal);
    line-height:1;
}
.survey-progress__label {
    font-size:.72rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:var(--text-muted);
    margin-top:.2rem;
}
.survey-progress__bar-wrap {
    flex:1;
    min-width:160px;
}
.survey-progress__bar-track {
    background:#e5e7eb;
    border-radius:6px;
    height:10px;
    overflow:hidden;
    margin-bottom:.35rem;
}
.survey-progress__bar-fill {
    background: linear-gradient(90deg,#059669,#34d399);
    height:100%;
    border-radius:6px;
    transition:width 400ms;
}
.survey-progress__bar-label {
    font-size:.8rem;
    color:var(--text-muted);
}

/* ── Tier 1 readiness strip (mirrors survey-progress styling) ─── */
.tier-one-strip {
    background:#F0FDF4;
    border:1px solid #A7F3D0;
    border-radius:var(--radius);
    padding:.9rem 1.1rem;
    margin-bottom:1.25rem;
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.75rem 1.5rem;
}
.tier-one-strip__title {
    font-size:.75rem;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:#065F46;
    flex:0 0 auto;
}
.tier-one-strip__stat {
    display:flex;
    flex-direction:column;
    line-height:1.15;
}
.tier-one-strip__num {
    font-size:1.35rem;
    font-weight:700;
    color:#047857;
}
.tier-one-strip__num--muted  { color:#6B7280; }
.tier-one-strip__num--flag   { color:#B45309; }
.tier-one-strip__label {
    font-size:.72rem;
    color:#4B5563;
    margin-top:.1rem;
    letter-spacing:.02em;
}
/* Per-room Tier-1 badge placed inside the existing room header row */
.tier-one-room-badge {
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    font-size:.72rem;
    font-weight:700;
    border-radius:999px;
    padding:.15rem .55rem;
    letter-spacing:.02em;
    margin-left:.4rem;
}
.tier-one-room-badge--ready    { background:#D1FAE5; color:#065F46; }
.tier-one-room-badge--partial  { background:#FEF3C7; color:#92400E; }

/* ── Survey link banner ───────────────────────────── */
.survey-link-banner {
    background:#EBF8FA;
    border:1px solid #94C4C9;
    border-radius:var(--radius);
    padding:.75rem 1rem;
    margin-bottom:1.25rem;
    display:flex;
    align-items:center;
    gap:.75rem;
    flex-wrap:wrap;
    font-size:.875rem;
}
.survey-link-url {
    flex:1;
    font-family:monospace;
    font-size:.8rem;
    color:#0B3C45;
    word-break:break-all;
}
.survey-link-copy {
    background:#178A95;
    color:#fff;
    border:none;
    border-radius:5px;
    padding:.35rem .85rem;
    font-size:.8rem;
    font-weight:600;
    cursor:pointer;
    white-space:nowrap;
}
.survey-link-copy:hover { background:#157B85; }

/* ── Room cards ───────────────────────────────────── */
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
.survey-room-card--inprogress {
    border-color:#FCD34D;
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
.room-view-hdr--inprogress { background:#FEF3C7; }
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
.room-view-badge--complete   { background:#A7F3D0; color:#065F46; }
.room-view-badge--inprogress { background:#FDE68A; color:#92400E; }
.room-view-badge--empty      { background:#E5E7EB; color:#6B7280; }
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

/* ── Field table ──────────────────────────────────── */
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

/* ── Section heading inside room ─────────────────── */
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

/* ── Photo grid ───────────────────────────────────── */
.photo-grid-pm {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));
    gap:.75rem;
    margin-bottom:.75rem;
}
.photo-pm {
    position:relative;
    border-radius:6px;
    overflow:hidden;
    border:1px solid #e5e7eb;
    aspect-ratio:4/3;
    background:#f9fafb;
}
.photo-pm a { display:block; height:100%; }
.photo-pm img {
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
    transition:transform 200ms;
}
.photo-pm:hover img { transform:scale(1.03); }
.photo-pm__caption {
    position:absolute;
    bottom:0;
    left:0;
    right:0;
    background:rgba(0,0,0,.5);
    color:#fff;
    font-size:.7rem;
    padding:.25rem .5rem;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.photo-pm__del {
    position:absolute;
    top:5px;
    right:5px;
    background:rgba(0,0,0,.55);
    border:none;
    color:#fff;
    border-radius:50%;
    width:22px;
    height:22px;
    font-size:.7rem;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    opacity:0;
    transition:opacity 150ms;
}
.photo-pm:hover .photo-pm__del { opacity:1; }

/* ── Upload drop zone ─────────────────────────────── */
.photo-dropzone {
    border:2px dashed #d1d5db;
    border-radius:6px;
    padding:.75rem 1rem;
    display:flex;
    align-items:center;
    gap:.75rem;
    flex-wrap:wrap;
    cursor:pointer;
    transition:border-color 150ms;
    font-size:.875rem;
    color:#6B7280;
}
.photo-dropzone:hover { border-color:var(--teal); color:var(--teal); }
.photo-dropzone span { color:var(--teal); font-weight:600; }

/* ── Status pill ──────────────────────────────────── */
.status-pill {
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    padding:.3rem .85rem;
    border-radius:20px;
    font-size:.78rem;
    font-weight:700;
}
.status-pill--completed  { background:#D1FAE5; color:#065F46; }
.status-pill--submitted  { background:#DBEAFE; color:#1E40AF; }
.status-pill--inprogress { background:#FEF3C7; color:#92400E; }
.status-pill--draft      { background:#F3F4F6; color:#6B7280; }

/* ── KIT reference strip ─────────────────────────── */
.kit-ref {
    background:#EBF8FA;
    border:1px solid #94C4C9;
    border-radius:5px;
    padding:.45rem .75rem;
    margin-bottom:.75rem;
    font-size:.8rem;
    color:#0B5860;
}
.kit-ref table { width:100%; border-collapse:collapse; }
.kit-ref td { padding:.15rem .4rem; vertical-align:top; }
.kit-ref .kqty  { font-weight:700; color:#178A95; width:2rem; text-align:right; }
.kit-ref .kpart { font-family:monospace; background:#d9f2f5; border-radius:3px; padding:.05rem .3rem; font-size:.75rem; }

/* ── Utility ──────────────────────────────────────── */
.text-muted { color:var(--text-muted); font-style:italic; }
</style>
@endpush

@section('content')

@php
    $rooms         = $survey->rooms->sortBy('sort_order');
    $totalRooms    = $rooms->count();
    $completeRooms = $rooms->where('is_completed', true)->count();
    $progressPct   = $totalRooms > 0 ? round($completeRooms / $totalRooms * 100) : 0;
    $surveyUrl     = route('survey.show', $survey->access_token);
@endphp

{{-- Page header --}}
<div class="page-header">
    <h1 class="page-title">{{ $survey->project_name }}</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">

        @php
            $statusLabel = match($survey->status) {
                'completed' => ['Completed', 'completed'],
                'submitted' => ['Submitted', 'submitted'],
                'in_progress' => ['In Progress', 'inprogress'],
                default => ['Draft', 'draft'],
            };
        @endphp
        <span class="status-pill status-pill--{{ $statusLabel[1] }}">
            ● {{ $statusLabel[0] }}
        </span>

        @if($survey->isDraft())
            <form method="POST" action="{{ route('site-surveys.complete', $survey) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-teal btn-sm"
                        onclick="return confirm('Mark this survey as completed?')">&#10003; Mark Complete</button>
            </form>
        @endif

        <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">&#9998; Edit Survey</a>
        <a href="{{ route('site-surveys.pdf', $survey) }}" class="btn btn-outline btn-sm" target="_blank">&#128438; Download PDF</a>
        <a href="{{ route('documents.revisions.view', ['type' => 'survey', 'id' => $survey->id]) }}" class="btn btn-outline btn-sm">&#8634; History</a>
        <x-document-edit-drawer
            type="survey"
            :id="$survey->id"
            label="Site Survey" />
        <a href="{{ route('site-surveys.index') }}" class="btn btn-outline btn-sm">&#8592; All Surveys</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

{{-- Engineer link banner --}}
@if($survey->access_token && !$survey->isSubmitted())
<div class="survey-link-banner">
    <span style="font-weight:700;color:#0B3C45;white-space:nowrap;">📱 Engineer Link:</span>
    <span class="survey-link-url" id="survey-link-text">{{ $surveyUrl }}</span>
    <button class="survey-link-copy" onclick="copyLink(this)">Copy Link</button>
</div>
@endif

{{-- Progress strip --}}
<div class="survey-progress">
    <div class="survey-progress__stat">
        <div class="survey-progress__num">{{ $completeRooms }}</div>
        <div class="survey-progress__label">Rooms Done</div>
    </div>
    <div class="survey-progress__stat">
        <div class="survey-progress__num" style="color:#6B7280;">{{ $totalRooms - $completeRooms }}</div>
        <div class="survey-progress__label">Remaining</div>
    </div>
    <div class="survey-progress__stat">
        <div class="survey-progress__num">{{ $totalRooms }}</div>
        <div class="survey-progress__label">Total Rooms</div>
    </div>
    <div class="survey-progress__bar-wrap">
        <div class="survey-progress__bar-track">
            <div class="survey-progress__bar-fill" style="width:{{ $progressPct }}%"></div>
        </div>
        <div class="survey-progress__bar-label">
            {{ $progressPct }}% complete
            @if($survey->submitted_at)
                · Submitted {{ $survey->submitted_at->format('d M Y H:i') }}
            @elseif($survey->updated_at)
                · Last updated {{ $survey->updated_at->format('d M Y H:i') }}
            @endif
        </div>
    </div>
</div>

{{-- Tier 1 readiness strip (mirrors progress strip, powered by SiteSurveyTierOneReadinessService) --}}
@if(isset($tierOne) && ($tierOne['summary']['total_rooms'] ?? 0) > 0)
    @php $t1 = $tierOne['summary']; @endphp
    <div class="tier-one-strip" aria-label="Tier 1 Readiness">
        <div class="tier-one-strip__title">Tier 1 Readiness</div>
        <div class="tier-one-strip__stat">
            <div class="tier-one-strip__num">{{ $t1['overall_percent'] }}%</div>
            <div class="tier-one-strip__label">Overall</div>
        </div>
        <div class="tier-one-strip__stat">
            <div class="tier-one-strip__num">{{ $t1['ready_rooms'] }} / {{ $t1['total_rooms'] }}</div>
            <div class="tier-one-strip__label">Rooms Ready</div>
        </div>
        <div class="tier-one-strip__stat">
            <div class="tier-one-strip__num {{ $t1['missing_items_total'] > 0 ? 'tier-one-strip__num--flag' : 'tier-one-strip__num--muted' }}">{{ $t1['missing_items_total'] }}</div>
            <div class="tier-one-strip__label">Missing Items</div>
        </div>
    </div>
@endif

{{-- Summary info card --}}
<div class="section-block" style="margin-bottom:1.25rem;">
    <h2 class="section-heading">Survey Details</h2>
    <div class="form-grid-2" style="margin-bottom:.75rem;">
        <div>
            <div class="meta-label">Client</div>
            <div class="meta-value">{{ $survey->client_name ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Reference</div>
            <div class="meta-value">{{ $survey->project_ref ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Site Address</div>
            <div class="meta-value">{{ $survey->site_address ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Survey Date</div>
            <div class="meta-value">{{ $survey->survey_date?->format('d M Y') ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Surveyor</div>
            <div class="meta-value">{{ $survey->surveyor_name ?? '—' }}</div>
        </div>
        @if($survey->project)
        <div>
            <div class="meta-label">Project</div>
            <div class="meta-value">
                <a href="{{ route('projects.show', $survey->project) }}" style="color:var(--teal);">
                    {{ $survey->project->name }}
                </a>
            </div>
        </div>
        @endif
    </div>
    @if($survey->general_notes)
    <div style="padding-top:.75rem;border-top:1px solid #f0f0f0;">
        <div class="meta-label" style="margin-bottom:.35rem;">General Site Notes</div>
        <p style="margin:0;white-space:pre-wrap;font-size:.9rem;color:#374151;">{{ $survey->general_notes }}</p>
    </div>
    @endif
</div>

{{-- Site Conditions --}}
@if($survey->site_risks || $survey->access_constraints || $survey->h_and_s_notes)
<div class="section-block" style="margin-bottom:1.25rem;">
    <h2 class="section-heading">Site Conditions</h2>
    <div class="form-grid-2">
        <div>
            <div class="meta-label">Site Risks</div>
            <div class="meta-value">
                {!! $survey->site_risks ? e($survey->site_risks) : '<span class="text-muted">Not provided</span>' !!}
            </div>
        </div>
        <div>
            <div class="meta-label">Access Constraints</div>
            <div class="meta-value">
                {!! $survey->access_constraints ? e($survey->access_constraints) : '<span class="text-muted">Not provided</span>' !!}
            </div>
        </div>
        <div>
            <div class="meta-label">Health &amp; Safety Notes</div>
            <div class="meta-value">
                {!! $survey->h_and_s_notes ? e($survey->h_and_s_notes) : '<span class="text-muted">Not provided</span>' !!}
            </div>
        </div>
    </div>
</div>
@endif

{{-- Rooms --}}
@if($rooms->isEmpty())
<div class="section-block" style="text-align:center;padding:2rem;color:#888;">
    No rooms recorded yet.
    <a href="{{ route('site-surveys.edit', $survey) }}" style="color:var(--teal);margin-left:.4rem;">Add rooms →</a>
</div>
@else
<div style="margin-bottom:.4rem;display:flex;align-items:center;justify-content:space-between;">
    <h2 class="section-heading" style="margin:0;">Rooms / Spaces</h2>
    <button type="button" class="btn btn-outline btn-sm" onclick="expandAll()">Expand All</button>
</div>

@foreach ($rooms as $room)
@php
    $isComplete = !empty($room->is_completed);
    $hasData    = $room->av_requirements || $room->notes || $room->room_width_m || $room->ceiling_type;
    $hdrClass   = $isComplete ? 'room-view-hdr--complete' : ($hasData ? 'room-view-hdr--inprogress' : 'room-view-hdr--empty');
    $cardBorder = $isComplete ? 'survey-room-card--complete' : ($hasData ? 'survey-room-card--inprogress' : '');
    $badgeClass = $isComplete ? 'room-view-badge--complete' : ($hasData ? 'room-view-badge--inprogress' : 'room-view-badge--empty');
    $badgeText  = $isComplete ? '✓ Complete' : ($hasData ? 'In Progress' : 'Not Started');
    $roomKit    = $kitByArea[$room->room_name] ?? [];

    $spaceLabels = [
        'general' => 'General AV',
        'pa_system' => 'PA System',
        'infrastructure' => 'Infrastructure',
        'signage' => 'Digital Signage',
        'upgrade' => 'Upgrade / Strip-out',
        'mixed' => 'Mixed',
    ];
    $spaceLabel = $spaceLabels[$room->space_type ?? 'general'] ?? ucfirst($room->space_type ?? 'general');
@endphp

<div class="survey-room-card {{ $cardBorder }}" id="pm-room-{{ $room->id }}">

    {{-- Collapsible header --}}
    <div class="room-view-hdr {{ $hdrClass }}" onclick="toggleViewRoom({{ $room->id }})">
        <span class="room-view-chevron" id="vchev-{{ $room->id }}">▶</span>
        <span class="room-view-name">
            {{ $room->room_name }}
            @if($room->floor)
                <span style="font-weight:400;color:#6B7280;font-size:.82rem;"> · {{ $room->floor }}</span>
            @endif
            <span style="font-weight:400;color:#6B7280;font-size:.75rem;margin-left:.5rem;">{{ $spaceLabel }}</span>
            @if($room->is_rack_room)
                <span style="font-size:.68rem;font-weight:800;background:#0B3C45;color:#fff;padding:.15rem .5rem;border-radius:10px;margin-left:.4rem;letter-spacing:.04em;vertical-align:middle;">RACK ROOM</span>
            @endif
        </span>
        @if($isComplete && $room->completed_at)
            <span style="font-size:.72rem;color:#065F46;font-weight:400;">{{ $room->completed_at->format('d M H:i') }}</span>
        @endif
        <span class="room-view-badge {{ $badgeClass }}">{{ $badgeText }}</span>
        @if($room->photos->count() > 0)
            <span style="font-size:.72rem;color:#6B7280;">📷 {{ $room->photos->count() }}</span>
        @endif
        @php $t1Room = $tierOne['rooms'][$room->id] ?? null; @endphp
        @if($t1Room)
            @if($t1Room['ready'])
                <span class="tier-one-room-badge tier-one-room-badge--ready"
                      title="Tier 1 ready — all required checks passed">
                    T1 · Ready
                </span>
            @else
                <span class="tier-one-room-badge tier-one-room-badge--partial"
                      title="Tier 1 progress — {{ count($t1Room['missing']) }} item(s) missing">
                    T1 · {{ $t1Room['percent'] }}% · {{ count($t1Room['missing']) }} missing
                </span>
            @endif
        @endif
    </div>

    <div class="room-view-body" id="vbody-{{ $room->id }}">

        {{-- KIT reference (read-only, from project quote) --}}
        @if(count($roomKit) > 0)
        <div class="kit-ref" style="margin-top:.75rem;">
            <div style="font-size:.72rem;font-weight:700;color:#0B3C45;margin-bottom:.3rem;letter-spacing:.04em;">
                📦 QUOTE KIT — {{ count($roomKit) }} item(s)
            </div>
            <table>
            @foreach($roomKit as $ki)
            @php
                $kQty  = $ki['quantity'] ?? $ki['qty'] ?? 1;
                $kPart = trim((string)($ki['part_number'] ?? $ki['part_no'] ?? ''));
                $kName = $ki['name'] ?? $ki['description'] ?? '';
            @endphp
            <tr>
                <td class="kqty">{{ $kQty }}×</td>
                <td>@if($kPart)<span class="kpart">{{ $kPart }}</span> @endif{{ $kName }}</td>
            </tr>
            @endforeach
            </table>
        </div>
        @endif

        {{-- ── Space / identification ──────────────────────── --}}
        @if($room->room_ref || $room->area_type)
        <div class="room-section-hdr">Space Info</div>
        <table class="field-table">
            @if($room->room_ref)
            <tr><td>Room Ref</td><td>{{ $room->room_ref }}</td></tr>
            @endif
            @if($room->area_type)
            <tr><td>Area Type</td><td>{{ ucwords(str_replace('_', ' ', $room->area_type)) }}</td></tr>
            @endif
        </table>
        @endif

        {{-- ── AV Scope ─────────────────────────────────────── --}}
        @if($room->av_requirements || $room->av_equipment_list)
        <div class="room-section-hdr" style="color:#3730A3;">📺 AV Scope</div>
        <table class="field-table">
            @if($room->av_requirements)
            <tr><td>AV Requirements</td><td>{{ $room->av_requirements }}</td></tr>
            @endif
            @if($room->av_equipment_list)
            <tr><td>Existing AV Equipment</td><td>{{ $room->av_equipment_list }}</td></tr>
            @endif
        </table>
        @endif

        {{-- ── Site conditions ─────────────────────────────── --}}
        @php
            $hasDims = $room->room_width_m || $room->room_depth_m || $room->room_height_m
                       || $room->ceiling_type || $room->wall_material || $room->floor_type;
            $hasServices = $room->has_power !== null || $room->has_network !== null
                           || $room->existing_cabling || $room->access_notes;
        @endphp
        @if($hasDims || $hasServices)
        <div class="room-section-hdr" style="color:#7C2D12;">🔌 Site Conditions</div>
        <table class="field-table">
            @if($room->room_width_m || $room->room_depth_m || $room->room_height_m)
            <tr>
                <td>Dimensions (W×D×H)</td>
                <td>
                    {{ $room->room_width_m ? $room->room_width_m . 'm' : '—' }} ×
                    {{ $room->room_depth_m ? $room->room_depth_m . 'm' : '—' }} ×
                    {{ $room->room_height_m ? $room->room_height_m . 'm' : '—' }}
                </td>
            </tr>
            @endif
            @if($room->ceiling_type)
            <tr>
                <td>Ceiling</td>
                <td>{{ ucfirst($room->ceiling_type) }}{{ $room->ceiling_height_m ? ' — ' . $room->ceiling_height_m . 'm' : '' }}</td>
            </tr>
            @endif
            @if($room->wall_material)
            <tr><td>Wall Material</td><td>{{ ucfirst($room->wall_material) }}</td></tr>
            @endif
            @if($room->floor_type)
            <tr><td>Floor Type</td><td>{{ ucfirst($room->floor_type) }}</td></tr>
            @endif
            <tr>
                <td>Power</td>
                <td>
                    @if($room->has_power)
                        <span style="color:#065F46;font-weight:600;">✓ Present</span>
                        @if($room->power_outlet_count) — {{ $room->power_outlet_count }} outlets @endif
                    @else
                        <span style="color:#991B1B;">✗ Not present</span>
                    @endif
                    @if($room->requires_additional_power)
                        <span style="background:#FEF3C7;color:#92400E;padding:.1rem .4rem;border-radius:3px;font-size:.75rem;margin-left:.4rem;">Additional needed</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Network</td>
                <td>
                    @if($room->has_network)
                        <span style="color:#065F46;font-weight:600;">✓ Present</span>
                        @if($room->network_port_count) — {{ $room->network_port_count }} ports @endif
                    @else
                        <span style="color:#991B1B;">✗ Not present</span>
                    @endif
                </td>
            </tr>
            @if($room->network_ssid || $room->network_vlan || $room->network_switch_port)
            <tr>
                <td>Network Details</td>
                <td>
                    @if($room->network_ssid)<span style="background:#EBF8FA;padding:.1rem .4rem;border-radius:3px;font-size:.82rem;">SSID: {{ $room->network_ssid }}</span> @endif
                    @if($room->network_vlan)<span style="background:#EBF8FA;padding:.1rem .4rem;border-radius:3px;font-size:.82rem;margin-left:.3rem;">VLAN: {{ $room->network_vlan }}</span> @endif
                    @if($room->network_switch_port)<span style="background:#EBF8FA;padding:.1rem .4rem;border-radius:3px;font-size:.82rem;margin-left:.3rem;">Port: {{ $room->network_switch_port }}</span> @endif
                </td>
            </tr>
            @endif
            @if($room->existing_cabling)
            <tr><td>Existing Cabling</td><td>{{ $room->existing_cabling }}</td></tr>
            @endif
            @if($room->access_notes)
            <tr><td>Access / Hazard Notes</td><td>{{ $room->access_notes }}</td></tr>
            @endif
        </table>
        @endif

        {{-- ── PA System ─────────────────────────────────── --}}
        @if(in_array($room->space_type, ['pa_system', 'mixed']) && ($room->speaker_count || $room->speaker_type))
        <div class="room-section-hdr" style="color:#065F46;">🔊 PA System</div>
        <table class="field-table">
            @if($room->speaker_count !== null)<tr><td>Speaker Count</td><td>{{ $room->speaker_count }}</td></tr>@endif
            @if($room->speaker_type)<tr><td>Speaker Type</td><td>{{ ucfirst($room->speaker_type) }}</td></tr>@endif
            @if($room->speaker_mounting)<tr><td>Mounting</td><td>{{ ucfirst(str_replace('_', ' ', $room->speaker_mounting)) }}</td></tr>@endif
            @if($room->bg_noise_db !== null)<tr><td>Background Noise</td><td>{{ $room->bg_noise_db }} dB(A)</td></tr>@endif
        </table>
        @endif

        {{-- ── Digital Signage ───────────────────────────── --}}
        @if(in_array($room->space_type, ['signage', 'mixed']) && ($room->display_size_in || $room->display_orient))
        <div class="room-section-hdr" style="color:#6D28D9;">🖥 Digital Signage</div>
        <table class="field-table">
            @if($room->display_size_in)<tr><td>Display Size</td><td>{{ $room->display_size_in }}"</td></tr>@endif
            @if($room->display_orient)<tr><td>Orientation</td><td>{{ ucfirst($room->display_orient) }}</td></tr>@endif
            @if($room->display_mounting)<tr><td>Mounting</td><td>{{ ucfirst(str_replace('_', ' ', $room->display_mounting)) }}</td></tr>@endif
        </table>
        @endif

        {{-- ── Upgrade / Strip-out ───────────────────────── --}}
        @if(in_array($room->space_type, ['upgrade', 'mixed']) && ($room->existing_condition || $room->items_to_remove))
        <div class="room-section-hdr" style="color:#9D174D;">🔧 Upgrade / Strip-out</div>
        <table class="field-table">
            @if($room->existing_condition)<tr><td>Existing Condition</td><td>{{ $room->existing_condition }}</td></tr>@endif
            @if($room->items_to_remove)<tr><td>Items to Remove</td><td>{{ $room->items_to_remove }}</td></tr>@endif
            @if($room->items_to_retain)<tr><td>Items to Retain</td><td>{{ $room->items_to_retain }}</td></tr>@endif
        </table>
        @endif

        {{-- ── Infrastructure ────────────────────────────── --}}
        @php
            $hasInfraData = $room->rack_unit_space || $room->cable_route_desc || $room->cable_route_from
                            || $room->cable_route_to || $room->is_rack_room || $room->projection_throw_m
                            || $room->viewing_distance_m;
        @endphp
        @if($hasInfraData)
        <div class="room-section-hdr">🏗 Infrastructure</div>
        <table class="field-table">
            @if($room->is_rack_room)
            <tr>
                <td>Rack Room</td>
                <td><span style="background:#0B3C45;color:#fff;padding:.1rem .5rem;border-radius:10px;font-size:.75rem;font-weight:700;letter-spacing:.04em;">RACK ROOM</span></td>
            </tr>
            @endif
            @if($room->rack_unit_space)<tr><td>Rack Space</td><td>{{ $room->rack_unit_space }}U</td></tr>@endif
            @if($room->cable_route_desc)<tr><td>Cable Route</td><td>{{ $room->cable_route_desc }}</td></tr>@endif
            @if($room->cable_route_from)<tr><td>Route From</td><td>{{ $room->cable_route_from }}</td></tr>@endif
            @if($room->cable_route_to)<tr><td>Route To</td><td>{{ $room->cable_route_to }}</td></tr>@endif
            @if($room->projection_throw_m)<tr><td>Projection Throw</td><td>{{ $room->projection_throw_m }}m</td></tr>@endif
            @if($room->viewing_distance_m)<tr><td>Viewing Distance</td><td>{{ $room->viewing_distance_m }}m</td></tr>@endif
        </table>
        @endif

        {{-- ── Engineer Sign-off ─────────────────────────── --}}
        @if($room->engineer_confirmed !== null || $room->engineer_signature_name)
        <div class="room-section-hdr" style="color:#14532D;">✅ Engineer Sign-off</div>
        <table class="field-table">
            <tr>
                <td>Confirmed</td>
                <td>
                    @if($room->engineer_confirmed)
                        <span style="color:#065F46;font-weight:600;">✓ Confirmed</span>
                    @else
                        <span style="color:#6B7280;">Not confirmed</span>
                    @endif
                </td>
            </tr>
            @if($room->engineer_signature_name)
            <tr><td>Engineer Name</td><td>{{ $room->engineer_signature_name }}</td></tr>
            @endif
        </table>
        @endif

        {{-- ── Notes ─────────────────────────────────────── --}}
        @if($room->notes)
        <div class="room-section-hdr">📝 Notes</div>
        <p style="font-size:.875rem;color:#374151;white-space:pre-wrap;margin:0 0 .75rem;">{{ $room->notes }}</p>
        @endif

        {{-- ── Photos ─────────────────────────────────────── --}}
        <div class="room-section-hdr">📷 Photos ({{ $room->photos->count() }})</div>

        @if($room->photos->isNotEmpty())
        <div class="photo-grid-pm">
            @foreach($room->photos->sortBy('sort_order') as $photo)
            <div class="photo-pm" id="photo-{{ $photo->id }}">
                <a href="{{ route('site-surveys.photos.serve', $photo) }}" target="_blank">
                    <img src="{{ route('site-surveys.photos.serve', $photo) }}"
                         alt="{{ $photo->caption ?? $photo->original_name }}"
                         loading="lazy">
                </a>
                @if($photo->caption)
                    <div class="photo-pm__caption">{{ $photo->caption }}</div>
                @endif
                <button class="photo-pm__del" onclick="deletePhoto({{ $photo->id }}, this)" title="Delete photo">✕</button>
            </div>
            @endforeach
        </div>
        @else
        <p style="font-size:.82rem;color:#9CA3AF;margin:.25rem 0 .75rem;">No photos uploaded for this room.</p>
        @endif

        {{-- Upload area --}}
        <div class="photo-dropzone"
             data-room-id="{{ $room->id }}"
             onclick="document.getElementById('photo-input-{{ $room->id }}').click()">
            <span>+ Add Photo</span>
            <span style="font-weight:400;">Click or drop image (max 10 MB)</span>
            <input type="file" id="photo-input-{{ $room->id }}"
                   accept="image/*" style="display:none;"
                   data-room-id="{{ $room->id }}"
                   onchange="uploadPhoto({{ $room->id }}, this)">
        </div>
        <input type="text" id="caption-{{ $room->id }}"
               placeholder="Optional caption for next photo…"
               class="form-control" style="margin-top:.5rem;font-size:.875rem;" maxlength="200">

    </div>{{-- /room-view-body --}}
</div>{{-- /survey-room-card --}}
@endforeach
@endif

{{-- Delete --}}
<div style="margin-top:2rem;padding-top:1rem;border-top:1px solid #eee;display:flex;align-items:center;gap:1rem;">
    <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
          onsubmit="return confirm('Permanently delete this survey and all its photos?');" style="margin:0;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger-outline btn-sm">Delete Survey</button>
    </form>
</div>

@endsection

@push('scripts')
<script>
const CSRF      = '{{ csrf_token() }}';
const SURVEY_ID = {{ $survey->id }};

// ── Collapsible room cards ─────────────────────────────────────────────────
function toggleViewRoom(id) {
    const body  = document.getElementById('vbody-' + id);
    const chev  = document.getElementById('vchev-' + id);
    const open  = body.classList.toggle('open');
    chev.classList.toggle('open', open);
}

function expandAll() {
    document.querySelectorAll('.room-view-body').forEach(b => b.classList.add('open'));
    document.querySelectorAll('.room-view-chevron').forEach(c => c.classList.add('open'));
}

// Auto-expand incomplete rooms on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.survey-room-card').forEach(card => {
        const hdr = card.querySelector('.room-view-hdr');
        if (hdr && !hdr.classList.contains('room-view-hdr--complete')) {
            const id = card.id.replace('pm-room-', '');
            const body = document.getElementById('vbody-' + id);
            const chev = document.getElementById('vchev-' + id);
            if (body) body.classList.add('open');
            if (chev) chev.classList.add('open');
        }
    });
});

// ── Copy engineer link ─────────────────────────────────────────────────────
function copyLink(btn) {
    const text = document.getElementById('survey-link-text')?.textContent?.trim();
    if (!text) return;

    const showSuccess = () => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.background = '#059669';
        setTimeout(() => { btn.textContent = orig; btn.style.background = ''; }, 2500);
    };

    // navigator.clipboard requires HTTPS — fallback for HTTP / older browsers
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(showSuccess).catch(() => {
            fallbackCopy(text);
            showSuccess();
        });
    } else {
        fallbackCopy(text);
        showSuccess();
    }
}

function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { prompt('Copy this link:', text); }
    document.body.removeChild(ta);
}

// ── Photo upload ───────────────────────────────────────────────────────────
function uploadPhoto(roomId, input) {
    if (!input.files.length) return;
    const file    = input.files[0];
    const caption = document.getElementById('caption-' + roomId)?.value || '';
    const url     = '/site-surveys/' + SURVEY_ID + '/rooms/' + roomId + '/photos';
    const drop    = input.closest('.photo-dropzone');

    const fd = new FormData();
    fd.append('photo', file);
    if (caption) fd.append('caption', caption);
    fd.append('_token', CSRF);

    if (drop) { drop.style.opacity = '.5'; drop.style.pointerEvents = 'none'; }

    fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.id) {
                appendPhoto(roomId, data);
                const capEl = document.getElementById('caption-' + roomId);
                if (capEl) capEl.value = '';
            } else {
                alert('Upload failed: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(e => alert('Upload failed: ' + e.message))
        .finally(() => {
            if (drop) { drop.style.opacity = '1'; drop.style.pointerEvents = ''; }
            input.value = '';
        });
}

function appendPhoto(roomId, data) {
    // Find the photo grid for this room — add before the upload dropzone
    const body  = document.getElementById('vbody-' + roomId);
    if (!body) return;
    let grid = body.querySelector('.photo-grid-pm');
    if (!grid) {
        // Create grid if it doesn't exist (room had no photos)
        grid = document.createElement('div');
        grid.className = 'photo-grid-pm';
        const drop = body.querySelector('.photo-dropzone');
        if (drop) drop.before(grid);
        // Remove "no photos" message
        body.querySelectorAll('p').forEach(p => {
            if (p.textContent.includes('No photos')) p.remove();
        });
    }

    const div = document.createElement('div');
    div.className = 'photo-pm';
    div.id = 'photo-' + data.id;
    div.innerHTML = `
        <a href="${data.url}" target="_blank">
            <img src="${data.url}" alt="${data.caption || data.original_name}" loading="lazy">
        </a>
        ${data.caption ? `<div class="photo-pm__caption">${data.caption}</div>` : ''}
        <button class="photo-pm__del" onclick="deletePhoto(${data.id}, this)" title="Delete">✕</button>`;
    grid.appendChild(div);

    // Update the Photos section header count
    const hdrEl = Array.from(body.querySelectorAll('.room-section-hdr')).find(h => h.textContent.includes('Photos'));
    if (hdrEl) {
        const count = grid.querySelectorAll('.photo-pm').length;
        hdrEl.textContent = `📷 Photos (${count})`;
    }
    // Update header badge photo count
    const card = document.getElementById('pm-room-' + roomId);
    const photoBadge = card?.querySelector('[title]');
}

function deletePhoto(photoId, btn) {
    if (!confirm('Delete this photo? This cannot be undone.')) return;
    btn.disabled = true;

    fetch('/site-surveys/photos/' + photoId, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.deleted) {
            const el = document.getElementById('photo-' + photoId);
            if (el) el.remove();
        }
    })
    .catch(e => { alert('Delete failed: ' + e.message); btn.disabled = false; });
}

// ── Drag-and-drop photo upload ─────────────────────────────────────────────
document.querySelectorAll('.photo-dropzone').forEach(area => {
    area.addEventListener('dragover',  e => { e.preventDefault(); area.style.borderColor = 'var(--teal)'; });
    area.addEventListener('dragleave', ()  => { area.style.borderColor = ''; });
    area.addEventListener('drop', e => {
        e.preventDefault();
        area.style.borderColor = '';
        const file = e.dataTransfer.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const roomId = area.dataset.roomId;
        const input  = document.getElementById('photo-input-' + roomId);
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
    });
});
</script>
@endpush

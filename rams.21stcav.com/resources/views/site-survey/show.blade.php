@extends('layouts.app')

@section('title', 'Survey — ' . $survey->project_name)

@push('styles')
<style>
/* ── Progress strip ───────────────────────────────── */
.survey-progress {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
    box-shadow: var(--shadow-xs);
}
.survey-progress__stat { text-align: center; min-width: 80px; }
.survey-progress__num {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--teal);
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.survey-progress__label {
    font-size: .72rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted);
    margin-top: .2rem;
}
.survey-progress__bar-wrap { flex: 1; min-width: 160px; }
.survey-progress__bar-track {
    background: var(--surface-deep);
    border-radius: 6px;
    height: 8px;
    overflow: hidden;
    margin-bottom: .35rem;
}
.survey-progress__bar-fill {
    background: var(--teal);
    height: 100%; border-radius: 6px;
    transition: width 400ms ease;
}
.survey-progress__bar-label { font-size: .8rem; color: var(--text-muted); }

/* ── Tier 1 readiness strip ──────────────────────── */
.tier-one-strip {
    background: var(--success-light);
    border: 1px solid #BBF7D0;
    border-radius: var(--radius);
    padding: .9rem 1.1rem;
    margin-bottom: 1.25rem;
    display: flex; flex-wrap: wrap;
    align-items: center;
    gap: .75rem 1.5rem;
}
.tier-one-strip__title {
    font-size: .75rem; font-weight: 700;
    letter-spacing: .04em; text-transform: uppercase;
    color: #166534; flex: 0 0 auto;
}
.tier-one-strip__stat { display: flex; flex-direction: column; line-height: 1.15; }
.tier-one-strip__num {
    font-size: 1.35rem; font-weight: 700;
    color: var(--success);
    font-variant-numeric: tabular-nums;
}
.tier-one-strip__num--muted  { color: var(--text-muted); }
.tier-one-strip__num--flag   { color: var(--warning); }
.tier-one-strip__label {
    font-size: .72rem; color: var(--text-muted);
    margin-top: .1rem; letter-spacing: .02em;
}
.tier-one-room-badge {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .72rem; font-weight: 700;
    border-radius: 999px; padding: .15rem .55rem;
    letter-spacing: .02em; margin-left: .4rem;
}
.tier-one-room-badge--ready    { background: var(--success-light); color: #166534; }
.tier-one-room-badge--partial  { background: var(--warning-light); color: #92400E; }

/* ── Survey link banner ───────────────────────────── */
/* Tier-1 Screen 02 v1 — engineer link hero (matches worksheet screen 05
   sign-off hero pattern). Legacy .survey-link-banner class kept below so
   any inline references stay valid but is no longer rendered on this page. */
/* Audit UI-03 (2026-07-08) — was SCC v2 dark-teal gradient (#123326 →
   #0F3E36) with gold #E6B849 label + cream #EDE9D9 body. Retuned to the
   indigo brand family so the "primary shareable URL" hero pattern reads
   consistently with the worksheet hero + the app-shell brand mark. */
/* .survey-link-hero styles retired 2026-07-09 — the panel now uses the
   shared x-link-hero component which ships its own stylesheet via a
   Blade once-directive guard (do NOT prefix "once" with an @-sign
   inside CSS comments — Blade compiles directive tokens EVERYWHERE,
   including CSS comments, and would emit an unclosed if block).
   .survey-link-banner (below) is a separate legacy fallback panel not
   currently rendered on this page; kept as-is until proven dead. */
.survey-link-banner {
    background: var(--teal-light);
    border: 1px solid var(--teal-mid);
    border-radius: var(--radius);
    padding: .75rem 1rem;
    margin-bottom: 1.25rem;
    display: flex; align-items: center;
    gap: .75rem; flex-wrap: wrap;
    font-size: .875rem;
}
.survey-link-url {
    flex: 1;
    font-family: var(--font-mono);
    font-size: .8rem;
    color: var(--teal-deep);
    word-break: break-all;
}
.survey-link-copy {
    background: var(--teal); color: #fff;
    border: none; border-radius: 5px;
    padding: .35rem .85rem;
    font-size: .8rem; font-weight: 600;
    cursor: pointer; white-space: nowrap;
    transition: background var(--transition);
}
.survey-link-copy:hover { background: var(--teal-hover); }

/* ── Room cards ───────────────────────────────────── */
.survey-room-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: .75rem;
    overflow: hidden;
    box-shadow: var(--shadow-xs);
}
.survey-room-card--complete  { border-color: var(--success); }
.survey-room-card--inprogress{ border-color: var(--warning); }
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
    font-weight:600;
    font-size: var(--fs-body);
    color: var(--ink-900);
    letter-spacing: -0.005em;
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
    background: transparent;
    font-size: var(--fs-small);
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
    color: var(--ink-500);
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--ink-200);
}
.field-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--ink-100);
    vertical-align: top;
    color: var(--ink-900);
    font-size: var(--fs-body);
}
.field-table tr:last-child td { border-bottom: none; }
.field-table td:first-child {
    width: 34%;
    font-weight: 500;
    color: var(--ink-500);
    font-size: var(--fs-small);
}
.field-table td:last-child {
    white-space:pre-wrap;
}

/* ── Section heading inside room ─────────────────── */
.room-section-hdr {
    font-size: var(--fs-small);
    font-weight: 600;
    text-transform: none;
    letter-spacing: -0.005em;
    color: var(--ink-900);
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
            <form method="POST" action="{{ route('site-surveys.complete', $survey) }}"
                  data-confirm="Mark this survey as completed?"
                  data-confirm-label="Mark Complete"
                  style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-teal btn-sm">&#10003; Mark Complete</button>
            </form>
        @endif

        {{-- Tier-1 Screen 02 v2 — visible primary actions only. Everything
             secondary (Download Internal, Variations CSV, History, Edit via
             chat, Back to Project) folds into a single Actions ▾ menu so
             the toolbar reads as "here's what to do next" not "here's every
             button". Edit Survey stays visible because it's the highest-frequency
             non-primary action for drafts. --}}
        <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">&#9998; Edit Survey</a>
        <a href="{{ route('site-surveys.pdf', $survey) }}?internal=0" class="btn btn-teal btn-sm" target="_blank">&#128196; Download for Client</a>

        @php $variationCount = $survey->variations->count(); @endphp
        <x-row-actions-menu label="More survey actions">
            {{-- Internal (engineer) PDF — same route, ?internal=1 flips the
                 template flag between the client-polished cover and the
                 engineer-findings mode. --}}
            <a href="{{ route('site-surveys.pdf', $survey) }}?internal=1" class="row-actions-item" target="_blank" title="Engineer PDF with site conditions + pre-install checks">
                <span class="row-actions-item__icon" aria-hidden="true">🛠</span>
                <span>Download internal PDF</span>
            </a>
            @if ($variationCount > 0)
                <a href="{{ route('site-surveys.variations.csv', $survey) }}" class="row-actions-item" title="Variations CSV">
                    <span class="row-actions-item__icon" aria-hidden="true">📊</span>
                    <span>Variations CSV ({{ $variationCount }})</span>
                </a>
            @endif
            <a href="{{ route('documents.revisions.view', ['type' => 'survey', 'id' => $survey->id]) }}" class="row-actions-item" title="Revision history">
                <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                <span>Revision history</span>
            </a>
            <a href="{{ route('site-surveys.show', $survey) }}?chat=1" class="row-actions-item" title="Edit via AI chat">
                <span class="row-actions-item__icon" aria-hidden="true">✎</span>
                <span>Edit via AI chat</span>
            </a>
            {{-- Default back-link is the parent project. Falls back to the
                 admin-only Surveys index only when the survey isn't tied to
                 a project AND the viewer is an admin. --}}
            @if($survey->project)
                <a href="{{ route('projects.show', $survey->project) }}" class="row-actions-item" title="Back to project">
                    <span class="row-actions-item__icon" aria-hidden="true">←</span>
                    <span>Back to project</span>
                </a>
            @elseif(auth()->user()?->isAdmin())
                <a href="{{ route('site-surveys.index') }}" class="row-actions-item" title="All surveys">
                    <span class="row-actions-item__icon" aria-hidden="true">←</span>
                    <span>All surveys</span>
                </a>
            @endif
        </x-row-actions-menu>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     Tier-1 Screen 02 v1 — engineer link hero.

     The engineer link is the artefact the office shares with the on-site
     surveyor — it opens the mobile-friendly capture form. Promoted to
     the top of the page (above flash + tiered strips) so office staff
     can copy it without scrolling. Hides once the survey is submitted
     (line-parity with the previous banner behaviour).
     ══════════════════════════════════════════════════════════════════════ --}}
@if($survey->access_token && !$survey->isSubmitted())
    <x-link-hero
        :url="$surveyUrl"
        label="Engineer link · share with the surveyor"
        icon="📱"
        regionLabel="Engineer link"
        urlAriaLabel="Engineer survey URL — click to select">
        <x-slot name="hint">Opens the mobile-friendly capture form. No login required for the on-site engineer.</x-slot>
        <x-slot name="actions">
            <x-copy-link-button :url="$surveyUrl" label="Copy link" />
            <a href="{{ $surveyUrl }}" target="_blank" class="btn btn-sm">Open ↗</a>
        </x-slot>
    </x-link-hero>
@endif

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
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

{{-- Batch 11 UX-08 — office-notification confirmation. When SurveyService
     managed to send the SurveySubmittedMail on submission, we stamped
     submitted_notification_sent_at. Surface that here so the engineer +
     the office both know the loop closed. When null on a submitted
     row, show the failed / not-sent state so the operator can chase. --}}
@if($survey->isSubmitted())
    @php
        $notifyStyle = $survey->submitted_notification_sent_at
            ? 'background: var(--success-light); border:1px solid color-mix(in oklab, var(--success) 30%, transparent); color: var(--success);'
            : 'background: var(--warning-light); border:1px solid color-mix(in oklab, var(--warning) 30%, transparent); color:#92400E;';
    @endphp
    <div class="survey-notify" role="status" style="{{ $notifyStyle }}">
        @if($survey->submitted_notification_sent_at)
            <strong style="font-weight:600;">✓ Office notified</strong>
            {{ $survey->submitted_notification_sent_at->diffForHumans() }}
            ({{ $survey->submitted_notification_sent_at->format('d M Y H:i') }})
        @else
            <strong style="font-weight:600;">⚠ Office notification not sent</strong>
            <span style="font-weight:400;">— check the mail log or notify the PM directly.</span>
        @endif
    </div>
    <style>
        .survey-notify {
            display: flex;
            gap: 8px;
            align-items: baseline;
            flex-wrap: wrap;
            padding: 10px 14px;
            font-size: var(--fs-small);
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            line-height: 1.5;
        }
    </style>
@endif

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
    // Wizard payload for this room — captured via the public survey wizard.
    // Stored as JSON on the parent survey under survey_data.rooms[idx]; we
    // align by the loop index because createFromProject persists rooms in
    // the same order in both stores.
    $wizard = $survey->survey_data['rooms'][$loop->index] ?? [];
    $wizardInfra = $wizard['infrastructure'] ?? [];
    $wizardPower = $wizardInfra['power'] ?? [];
    $wizardNet   = $wizardInfra['network'] ?? [];
    $wizardCable = $wizardInfra['cable_routes'] ?? [];
    $wizardRisks = $wizard['risks'] ?? [];
    $wizardEqui  = $wizard['equipment'] ?? [];
    $wizardUi    = $wizard['ui_state'] ?? [];
    $wizardCons  = $wizardUi['constraints'] ?? [];
    $wizardSign  = $wizard['signoff'] ?? [];
    $wizardNotes = $wizard['notes'] ?? '';
    $wizardVoice = $wizardUi['voice_note'] ?? '';
    $wizardItems = $wizard['additional_items'] ?? [];
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
        @php
            // Per-room install-action bullets sourced from the project package's
            // room_overviews[i].works_summary. Replaces the project-wide lump
            // bullets block that used to live above Site Conditions.
            $roomBullets = [];
            $package2 = $survey->project?->latestPackage;
            $rd2 = (array) ($package2?->reviewed_data  ?? []);
            $ed2 = (array) ($package2?->extracted_data ?? []);
            $roSource2 = ! empty($rd2['room_overviews']) ? (array) $rd2['room_overviews'] : (array) ($ed2['room_overviews'] ?? []);
            foreach ($roSource2 as $ro2) {
                if (! is_array($ro2)) continue;
                $rname = trim((string) ($ro2['room'] ?? $ro2['room_name'] ?? $ro2['name'] ?? ''));
                if (strcasecmp($rname, (string) $room->room_name) !== 0) continue;
                $bulletText2 = trim((string) ($ro2['works_summary'] ?? ''));
                if ($bulletText2 !== '') {
                    $roomBullets = array_values(array_filter(
                        array_map(fn ($l) => preg_replace('/^[-•]\s*/', '', trim($l)),
                                  preg_split('/\r?\n/', $bulletText2)),
                        fn ($l) => $l !== ''
                    ));
                }
                break;
            }
        @endphp
        @if($room->av_requirements || $room->av_equipment_list || count($roomBullets) > 0)
        <div class="room-section-hdr">📺 AV Scope</div>
        @if(count($roomBullets) > 0)
        <ul style="padding-left:1.25rem;margin:.25rem 0 .75rem;font-size:.875rem;color:#374151;line-height:1.5;">
            @foreach($roomBullets as $b)
                <li style="margin-bottom:.2rem;">{{ $b }}</li>
            @endforeach
        </ul>
        @endif
        <table class="field-table">
            @if($room->av_requirements && count($roomBullets) === 0)
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
            @php
                // Power/network availability now lives in the wizard ui_state
                // (boolean toggles on Step 2). Wizard answer wins when present
                // because the legacy DB column defaults to false on creation
                // and never gets touched by the public-link flow.
                $powerOn = array_key_exists('power_available',   $wizardUi)
                    ? (bool) $wizardUi['power_available']
                    : $room->has_power;
                $netOn   = array_key_exists('network_available', $wizardUi)
                    ? (bool) $wizardUi['network_available']
                    : $room->has_network;
            @endphp
            <tr>
                <td>Power</td>
                <td>
                    @if($powerOn)
                        <span style="display:inline-flex;align-items:center;gap:.25rem;background:#DDEBE1;color:#204F3D;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600;letter-spacing:-.005em;">✓ Present</span>
                        @if($room->power_outlet_count) — {{ $room->power_outlet_count }} outlets @endif
                    @elseif($powerOn === false)
                        <span style="display:inline-flex;align-items:center;gap:.25rem;background:#F1D9D2;color:#7E2E22;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600;letter-spacing:-.005em;">✗ Not present</span>
                    @else
                        <span style="color:#9CA3AF;">— not captured</span>
                    @endif
                    @if($room->requires_additional_power)
                        <span style="background:#FEF3C7;color:#92400E;padding:.1rem .4rem;border-radius:3px;font-size:.75rem;margin-left:.4rem;">Additional needed</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Network</td>
                <td>
                    @if($netOn)
                        <span style="display:inline-flex;align-items:center;gap:.25rem;background:#DDEBE1;color:#204F3D;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600;letter-spacing:-.005em;">✓ Present</span>
                        @if($room->network_port_count) — {{ $room->network_port_count }} ports @endif
                    @elseif($netOn === false)
                        <span style="display:inline-flex;align-items:center;gap:.25rem;background:#F1D9D2;color:#7E2E22;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600;letter-spacing:-.005em;">✗ Not present</span>
                    @else
                        <span style="color:#9CA3AF;">— not captured</span>
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
        @php $combinedNotes = trim((string) ($room->notes ?? '')) ?: trim((string) $wizardNotes); @endphp
        @if($combinedNotes)
        <div class="room-section-hdr">📝 Notes</div>
        <p style="font-size:.875rem;color:#374151;white-space:pre-wrap;margin:0 0 .75rem;">{{ $combinedNotes }}</p>
        @endif

        {{-- ── Voice / dictation note ────────────────────── --}}
        @if(trim((string) $wizardVoice) !== '')
        <div class="room-section-hdr">🎙 Voice Note</div>
        <p style="font-size:.875rem;color:#374151;white-space:pre-wrap;margin:0 0 .75rem;">{{ $wizardVoice }}</p>
        @endif

        {{-- ── Additional items requested by engineer ─────── --}}
        @if(is_array($wizardItems) && count($wizardItems) > 0)
        <div class="room-section-hdr">🛒 Additional Items Needed ({{ count($wizardItems) }})</div>
        <table>
            <tr>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;width:14%;">Qty</td>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;width:46%;">Item</td>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Note</td>
            </tr>
            @foreach($wizardItems as $item)
                @php
                    $qty  = is_array($item) ? ($item['qty'] ?? '') : '';
                    $desc = is_array($item) ? trim((string) ($item['description'] ?? '')) : trim((string) $item);
                    $note = is_array($item) ? trim((string) ($item['note'] ?? '')) : '';
                @endphp
                @if($desc !== '')
                <tr>
                    <td>{{ $qty !== '' ? $qty : '—' }}</td>
                    <td>{{ $desc }}</td>
                    <td style="white-space:pre-wrap;">{{ $note ?: '—' }}</td>
                </tr>
                @endif
            @endforeach
        </table>
        @endif

        {{-- ── Pre-install Checks (engineer answers) ──────── --}}
        @if($room->questions->isNotEmpty())
        @php
            $answeredCount = $room->questions->whereNotNull('answer')->count();
            $totalCount    = $room->questions->count();
        @endphp
        <div class="room-section-hdr">✅ Pre-install Checks ({{ $answeredCount }}/{{ $totalCount }} answered)</div>
        <table>
            @foreach($room->questions->sortBy('sort_order') as $q)
                @php
                    $a = strtolower((string) $q->answer);
                    $bg = $a === 'yes' ? '#10B981' : ($a === 'no' ? '#EF4444' : ($a === 'other' ? '#F59E0B' : '#9CA3AF'));
                    $label = $a !== '' ? strtoupper($a) : 'UNANSWERED';
                @endphp
                <tr>
                    <td style="width:65%;font-size:.84rem;line-height:1.35;">{{ $q->question }}</td>
                    <td style="width:35%;">
                        <span style="background:{{ $bg }};color:#fff;padding:.18rem .55rem;border-radius:10px;
                                     font-size:.7rem;font-weight:700;letter-spacing:.04em;">{{ $label }}</span>
                        @if($a === 'other' && trim((string) $q->other_text) !== '')
                            <div style="margin-top:.35rem;font-size:.78rem;color:#374151;white-space:pre-wrap;">{{ $q->other_text }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @endif

        {{-- ── Captured infrastructure (wizard) ───────────── --}}
        @php
            $hasInfra = !empty($wizardPower['socket_locations']) || isset($wizardPower['spare_capacity'])
                     || !empty($wizardPower['distance_to_screen']) || !empty($wizardNet['ports_available'])
                     || !empty($wizardNet['switch_location']) || isset($wizardNet['vlan_required'])
                     || !empty($wizardCable['route_type']) || !empty($wizardCable['estimated_distance']);
        @endphp
        @if($hasInfra)
        <div class="room-section-hdr">⚡ Captured Infrastructure</div>
        <table>
            @if(!empty($wizardPower['socket_locations']))
                <tr><td>Power · Socket locations</td><td>{{ $wizardPower['socket_locations'] }}</td></tr>
            @endif
            @if(!empty($wizardPower['distance_to_screen']))
                <tr><td>Power · Distance to screen</td><td>{{ $wizardPower['distance_to_screen'] }} m</td></tr>
            @endif
            @if(array_key_exists('spare_capacity', $wizardPower))
                <tr><td>Power · Spare capacity</td><td>{{ $wizardPower['spare_capacity'] ? 'Yes' : 'No' }}</td></tr>
            @endif
            @if(!empty($wizardNet['ports_available']))
                <tr><td>Network · Ports available</td><td>{{ $wizardNet['ports_available'] }}</td></tr>
            @endif
            @if(!empty($wizardNet['switch_location']))
                <tr><td>Network · Switch location</td><td>{{ $wizardNet['switch_location'] }}</td></tr>
            @endif
            @if(array_key_exists('vlan_required', $wizardNet))
                <tr><td>Network · VLAN required</td><td>{{ $wizardNet['vlan_required'] ? 'Yes' : 'No' }}</td></tr>
            @endif
            @if(!empty($wizardCable['route_type']))
                <tr><td>Cable · Route type</td><td>{{ $wizardCable['route_type'] }}</td></tr>
            @endif
            @if(!empty($wizardCable['estimated_distance']))
                <tr><td>Cable · Estimated distance</td><td>{{ $wizardCable['estimated_distance'] }} m</td></tr>
            @endif
        </table>
        @endif

        {{-- ── Captured equipment (wizard) ───────────────── --}}
        @if(is_array($wizardEqui) && count($wizardEqui) > 0)
        <div class="room-section-hdr">🎛 Captured Equipment ({{ count($wizardEqui) }})</div>
        <table>
            <tr>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Type</td>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Status</td>
                <td style="font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Location</td>
            </tr>
            @foreach($wizardEqui as $item)
                <tr>
                    <td>{{ $item['type'] ?? '—' }}</td>
                    <td>{{ $item['status'] ?? '—' }}</td>
                    <td>{{ $item['location'] ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
        @endif

        {{-- ── Risks captured by engineer ─────────────────── --}}
        @php
            $risk = is_array($wizardRisks) && isset($wizardRisks[0]) && is_array($wizardRisks[0]) ? $wizardRisks[0] : [];
            $hasRisk = !empty($risk['working_height']) || !empty($risk['access_equipment'])
                    || !empty($risk['out_of_hours']) || !empty($risk['permits_required'])
                    || !empty($risk['manual_handling_risk']);
        @endphp
        @if($hasRisk)
        <div class="room-section-hdr">⚠️ Risks</div>
        <table>
            @if(!empty($risk['working_height']))
                <tr><td>Working height</td><td>{{ $risk['working_height'] }} m</td></tr>
            @endif
            @if(!empty($risk['access_equipment']))
                <tr><td>Access equipment</td><td>{{ $risk['access_equipment'] }}</td></tr>
            @endif
            @if(!empty($risk['out_of_hours']))
                <tr><td>Out of hours</td><td>Yes</td></tr>
            @endif
            @if(!empty($risk['permits_required']))
                <tr><td>Permits required</td><td>Yes</td></tr>
            @endif
            @if(!empty($risk['manual_handling_risk']))
                <tr><td>Manual handling risk</td><td>Yes</td></tr>
            @endif
        </table>
        @endif

        {{-- ── Constraints (UI-only capture) ──────────────── --}}
        @php
            $consHas = collect(['obstructions','noise_restrictions','client_constraints','programme_constraints'])
                ->contains(fn($k) => trim((string) ($wizardCons[$k] ?? '')) !== '');
        @endphp
        @if($consHas)
        <div class="room-section-hdr">🚧 Constraints</div>
        <table>
            @foreach([
                'obstructions'          => 'Obstructions',
                'noise_restrictions'    => 'Noise restrictions',
                'client_constraints'    => 'Client constraints',
                'programme_constraints' => 'Programme constraints',
            ] as $k => $label)
                @if(trim((string) ($wizardCons[$k] ?? '')) !== '')
                    <tr><td>{{ $label }}</td><td style="white-space:pre-wrap;">{{ $wizardCons[$k] }}</td></tr>
                @endif
            @endforeach
        </table>
        @endif

        {{-- ── Signoff ─────────────────────────────────────── --}}
        @if(!empty($wizardSign['engineer_name']) || !empty($wizardSign['engineer_confirmed']))
        <div class="room-section-hdr">✍️ Signoff</div>
        <table>
            @if(!empty($wizardSign['engineer_name']))
                <tr><td>Engineer</td><td>{{ $wizardSign['engineer_name'] }}</td></tr>
            @endif
            @if(!empty($wizardSign['engineer_confirmed']))
                <tr><td>Confirmed</td><td>Yes</td></tr>
            @endif
            @if(!empty($wizardSign['signed_at']))
                <tr><td>Signed at</td><td>{{ \Carbon\Carbon::parse($wizardSign['signed_at'])->format('d M Y H:i') }}</td></tr>
            @endif
        </table>
        @endif

        {{-- ── Photos ─────────────────────────────────────── --}}
        <div class="room-section-hdr">📷 Photos ({{ $room->photos->count() }})</div>

        @if($room->photos->isNotEmpty())
        @php
            // 260508 — pre-compute the room photo set for the lightbox cycler.
            // Sort matches the foreach below so loop index aligns with array index.
            // (Do NOT prefix "foreach" with @ — Blade compiles directive tokens
            // even inside PHP comments and would emit a stray endforeach.)
            $roomPhotosLb = $room->photos->sortBy('sort_order')->values()->map(fn ($photo) => [
                'url'     => route('site-surveys.photos.serve', $photo),
                'caption' => $photo->caption ?? $photo->original_name ?? '',
            ])->all();
        @endphp
        <div class="photo-grid-pm">
            @foreach($room->photos->sortBy('sort_order') as $photo)
            <div class="photo-pm" id="photo-{{ $photo->id }}">
                <a href="{{ route('site-surveys.photos.serve', $photo) }}"
                   target="_blank"
                   onclick="event.preventDefault(); openPhotoLightbox(@js($roomPhotosLb), {{ $loop->index }});">
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
          data-confirm="Permanently delete this survey and all its photos?"
          data-confirm-label="Delete Survey"
          data-confirm-danger="1" style="margin:0;">
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

// Tier-1 Screen 02 v2 — auto-expand-incomplete-rooms behaviour removed.
// v1 rendered incomplete rooms open on load, complete rooms collapsed —
// on a 6-room survey this typically meant ~3,000px of open detail before
// the user could scroll to their target room. All rooms now start
// collapsed. Users can still `Expand All` (top-right button) or click
// individual room headers to open a specific one.

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

async function deletePhoto(photoId, btn) {
    if (!(await window.appConfirm('Delete this photo? This cannot be undone.', { title:'Delete photo?', confirmLabel:'Delete', danger:true }))) return;
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

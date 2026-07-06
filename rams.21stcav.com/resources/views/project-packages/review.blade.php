@extends('layouts.app')

@section('title', 'Edit Project Data — ' . ($package->project_name ?? 'Project'))

@push('styles')
<style>
/* ── Review page specifics ─────────────────────────────────────────── */
.review-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}
.review-section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.review-section-header {
    background: var(--teal-light);
    border-bottom-color: var(--teal-mid);
}
.review-section-header h2 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--teal);
    margin: 0;
}
.review-section-body {
    padding: 1.25rem;
}
.review-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 640px) {
    .review-grid-2 { grid-template-columns: 1fr; }
}

/* ── Repeater tables ───────────────────────────────────────────────── */
.repeater-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .875rem;
}
.repeater-table th {
    background: var(--sidebar-bg);
    color: #fff;
    font-weight: 600;
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    padding: .5rem .75rem;
    text-align: left;
    border-bottom: 1px solid var(--teal-mid);
}
.repeater-table td {
    padding: .45rem .5rem;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
}
.repeater-table tr:last-child td { border-bottom: none; }
.repeater-table input[type="text"],
.repeater-table input[type="number"],
.repeater-table select,
.repeater-table textarea {
    width: 100%;
    padding: .35rem .5rem;
    border: 1px solid #d1d5db;
    border-radius: 5px;
    font-size: .875rem;
    font-family: inherit;
    background: #fff;
    transition: border-color var(--transition);
}
.repeater-table input:focus,
.repeater-table select:focus,
.repeater-table textarea:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 2px rgba(23,138,149,.12);
}
.repeater-table textarea { resize: vertical; min-height: 60px; }

/* ── Equipment part-number + description (auto-growing single-line → textarea) ── */
.repeater-table textarea.equip-input {
    resize: none;                /* we auto-grow via JS; user can't drag */
    min-height: 0;
    height: auto;
    overflow: hidden;            /* hide scrollbar while typing — scrollHeight drives the grow */
    line-height: 1.35;
    padding: .35rem .5rem;
}
.repeater-table textarea.equip-input.pn {
    font-family: monospace;
    font-size: .82rem;
    text-transform: uppercase;
}

/* ── Invalid-field highlight (scoped to repeater inputs — the main form uses .form-control.is-invalid) ── */
.repeater-table .is-invalid,
.repeater-table input.is-invalid,
.repeater-table textarea.is-invalid,
.repeater-table select.is-invalid {
    border-color: var(--danger, #dc2626) !important;
    background: #fef2f2;
    box-shadow: 0 0 0 3px rgba(220,38,38,.12);
}
.repeater-table .form-error {
    color: var(--danger, #dc2626);
    font-size: .75rem;
    margin: .2rem 0 0;
}
/* AV Works Summary textarea — monospace so the field:value block aligns cleanly */
.av-works-summary-textarea {
    font-family: 'Courier New', Courier, monospace !important;
    font-size: .8rem !important;
    line-height: 1.6 !important;
    min-height: 120px !important;
    background: var(--bg) !important;
}
.col-qty   { width: 70px; }
.col-area  { width: 150px; }
.col-risk  { width: 110px; }
.col-act   { width: 140px; }
.col-del   { width: 40px; text-align: center; }

/* ── PPE checkboxes ────────────────────────────────────────────────── */
.ppe-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: .5rem;
}
.ppe-check {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .875rem;
    padding: .35rem .5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition), border-color var(--transition);
}
.ppe-check:hover { background: var(--teal-light); border-color: var(--teal-mid); }
.ppe-check input[type="checkbox"] { cursor: pointer; }

/* ── Access checkboxes ─────────────────────────────────────────────── */
.access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: .5rem;
}

/* ── Confidence badge ──────────────────────────────────────────────── */
.confidence-alert {
    background: #fffbeb;
    border: 1px solid #f59e0b;
    border-radius: var(--radius-sm);
    padding: .75rem 1rem;
    font-size: .875rem;
    color: #92400e;
    display: flex;
    gap: .625rem;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}
.confidence-icon { font-size: 1.1rem; flex-shrink: 0; }

/* ── Action bar ────────────────────────────────────────────────────── */
.action-bar {
    position: sticky;
    bottom: 0;
    background: var(--surface);
    border-top: 1px solid var(--border);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    justify-content: flex-end;
    z-index: 50;
    box-shadow: 0 -2px 8px rgba(0,0,0,.07);
}

/* ── Remove button ─────────────────────────────────────────────────── */
.btn-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: .25rem;
    border-radius: 3px;
    transition: background var(--transition);
}
.btn-remove:hover { background: #fee2e2; }

/* ── Tabs ──────────────────────────────────────────────────────── */
.review-tabs {
    display: flex;
    gap: .375rem;
    background: var(--teal-light);
    border: 2px solid var(--teal-mid);
    border-radius: var(--radius);
    padding: .3rem;
    margin-bottom: 1.75rem;
}
.review-tab-btn {
    flex: 1;
    padding: .7rem 1.5rem;
    font-size: .9375rem;
    font-weight: 700;
    color: var(--teal);
    background: transparent;
    border: 2px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    text-align: center;
    letter-spacing: -.01em;
    transition: background .15s, color .15s, border-color .15s, box-shadow .15s;
}
.review-tab-btn:hover {
    background: rgba(23,138,149,.12);
    border-color: var(--teal-mid);
}
.review-tab-btn.active {
    background: var(--teal);
    color: #fff;
    border-color: var(--teal);
    box-shadow: var(--shadow-sm);
}
.review-tab-btn.active:hover { background: var(--teal-hover); border-color: var(--teal-hover); }
.review-tab-panel { display: none; }
.review-tab-panel.active { display: block; }

/* ── Status badge overrides for pipeline statuses ──────────────────── */
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #f59e0b; }
.badge-green   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.badge-red     { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

/* ── Tier-1 sidebar rail (Screen 03 v1) ────────────────────────────── */
.pkg-layout {
    display: grid;
    grid-template-columns: 232px 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 900px) {
    .pkg-layout { grid-template-columns: 1fr; }
    .pkg-rail { position: static !important; max-height: none !important; }
}
.pkg-rail {
    position: sticky;
    top: 4.5rem;
    background: var(--surface, #fff);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .5rem;
    max-height: calc(100vh - 5.5rem);
    overflow-y: auto;
}
.pkg-rail-h {
    font-size: .62rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
    padding: .55rem .55rem .25rem;
}
.pkg-rail-h.first { padding-top: .25rem; }
.pkg-rail-item {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .55rem;
    border-radius: 4px;
    font-size: .78rem;
    color: var(--text);
    text-decoration: none;
    font-weight: 500;
    line-height: 1.3;
    cursor: pointer;
}
.pkg-rail-item:hover { background: var(--bg-muted, #faf7ee); }
.pkg-rail-item.active {
    background: var(--accent, #0f3e36);
    color: #e6b849;
    font-weight: 600;
}
.pkg-rail-item .n {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .68rem;
    color: var(--text-muted);
    font-weight: 700;
    min-width: 1rem;
    flex-shrink: 0;
}
.pkg-rail-item.active .n { color: rgba(230, 184, 73, .7); }
.pkg-rail-item .name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.review-section { scroll-margin-top: 5rem; }

/* ── Tier-1 Screen 03 v3 — compact equipment table ─────────────────── */
/* Scoped to #s-equipment so the RAMS-tab tables (activities/hazards)
   keep their existing padding + row heights. Zero behaviour change —
   pure visual compaction. */
#s-equipment .repeater-table td { padding: .3rem .5rem; }
#s-equipment .repeater-table th { padding: .4rem .6rem; font-size: .72rem; }
#s-equipment .repeater-table input,
#s-equipment .repeater-table select,
#s-equipment .repeater-table textarea { padding: .28rem .45rem; font-size: .82rem; }
#s-equipment .repeater-table textarea.equip-input { line-height: 1.3; }
/* Small-caps category dropdown so its lower visual weight matches the
   row-scanning task better than the full-size text version. */
#s-equipment .repeater-table select[data-equip-category] {
    font-size: .76rem;
    color: var(--text-muted);
    letter-spacing: -.005em;
}
/* Category-header (per group "Hardware" strip + Add button). Currently
   1rem/1.25rem padding; tightened so the room-name sub-rows read as
   the primary organiser, not the category banner. */
#s-equipment .review-section-body > div[style*="padding:1rem 1.25rem"] {
    padding: .6rem 1rem !important;
    font-size: .82rem;
}
#s-equipment .review-section-body > div[style*="padding:1rem 1.25rem"] strong {
    font-size: .85rem;
}
/* Delete button fades until the row is hovered — cuts a lot of visual
   noise on tables with 30+ rows. Keyboard focus still surfaces it. */
#s-equipment .repeater-table tr[data-equip-row] .btn-remove {
    opacity: .28;
    transition: opacity .12s;
}
#s-equipment .repeater-table tr[data-equip-row]:hover .btn-remove,
#s-equipment .repeater-table tr[data-equip-row]:focus-within .btn-remove {
    opacity: 1;
}
/* Subtle row separator instead of the fixed 1px --border on every row —
   makes long tables scan more like a spreadsheet than a stack of cards. */
#s-equipment .repeater-table tr[data-equip-row] td {
    border-bottom-color: rgba(0, 0, 0, .04);
}
#s-equipment .repeater-table tr[data-equip-row]:hover td {
    background: rgba(15, 62, 54, .02);
}
/* Room-group sub-header (the "Meeting Room 1" band before its rows) */
#s-equipment .repeater-table tr[data-room-row] > td {
    padding: .3rem .75rem;
    font-size: .8rem;
}
#s-equipment .repeater-table tr[data-room-row] .eq-area-label { font-size: .82rem; }
</style>
@endpush

@section('content')

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Project Data</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.25rem;">
            Review and correct the data extracted from your quote PDF. This data is used for all project documents.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <span class="badge badge-grey" style="font-size:.75rem;">{{ ucfirst($package->status) }}</span>
        <form method="POST" action="{{ route('quote-import.reextract', $package) }}" style="margin:0;"
              data-confirm="Re-extract this package from the stored PDF? This will refresh all data including Room Overviews. Any unsaved edits will be lost."
              data-confirm-label="Re-extract"
              data-confirm-danger="1"
              x-data="{ pending: false }"
              @submit="if (! $el.hasAttribute('data-confirm')) pending = true">
            @csrf
            <button type="submit"
                    :disabled="pending"
                    class="btn btn-sm"
                    :class="pending ? '' : 'btn-outline'"
                    :style="pending
                        ? 'background:#d97706;color:#fff;border:1px solid #b45309;cursor:not-allowed;pointer-events:none;opacity:1;padding-right:.9rem;'
                        : ''"
                    title="Re-run the parser against the stored PDF to refresh extracted data">
                <span x-show="! pending">🔄 Re-extract PDF</span>
                <span x-show="pending" x-cloak style="display:inline-flex;align-items:center;gap:.45rem;">
                    <span style="display:inline-block;width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:reextract-spin .8s linear infinite;"></span>
                    <span>Extracting… please wait (30–60s, do not refresh)</span>
                </span>
            </button>
            <style>
                @keyframes reextract-spin { to { transform: rotate(360deg); } }
            </style>
        </form>
        @if ($package->project_id)
        <a href="{{ route('projects.show', $package->project_id) }}" class="btn btn-outline btn-sm">← Back to Project</a>
        @endif
    </div>
</div>

{{-- Flash messages --}}
@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- Validation errors --}}
@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please fix the following errors:</strong>
        <ul style="margin:.5rem 0 0 1.25rem;padding:0;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Low confidence warning --}}
@php
    $confidence = $reviewPayload['meta']['parser_confidence'] ?? null;
    $isLowConfidence = $confidence !== null && $confidence < 0.5;
@endphp
@if ($isLowConfidence)
    <div class="confidence-alert">
        <span class="confidence-icon">⚠️</span>
        <div>
            <strong>Low parser confidence ({{ round($confidence * 100) }}%)</strong> — the system had difficulty
            reading this PDF. Please review all extracted data carefully, correct any errors, and ensure
            all equipment, activities, and hazards are accurate before approving.
        </div>
    </div>
@elseif ($confidence !== null)
    <div style="background:var(--teal-light);border:1px solid var(--teal-mid);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:.8125rem;color:#0f5460;margin-bottom:1.25rem;display:flex;gap:.5rem;align-items:center;">
        <span>✓</span>
        <span>Parser confidence: <strong>{{ round($confidence * 100) }}%</strong> — data looks good. Review below and approve when ready.</span>
    </div>
@endif

{{-- ================================================================== --}}
{{-- MAIN FORM                                                           --}}
{{-- ================================================================== --}}
{{-- Single form. The "Save Review" button posts to the save endpoint.  --}}
{{-- The "Approve & Generate" button posts to the approve endpoint.     --}}
{{-- JavaScript sets the action dynamically based on which button was   --}}
{{-- clicked — no Livewire or Alpine required.                          --}}
{{-- ================================================================== --}}

{{-- ═══════════════════════════════════════════════════════════════════
     Sidebar rail (Tier-1 v1) — sticky per-section anchors + tab groups.
     Clicks first switch to the target section's tab, then scroll. The
     rail is nav-only; the form + submit buttons stay inside the form
     below.
     ═══════════════════════════════════════════════════════════════════ --}}
<div class="pkg-layout">
    <aside class="pkg-rail" aria-label="Section navigation">
        <div class="pkg-rail-h first">📋 Project info tab</div>
        <a class="pkg-rail-item" data-tab="project-info" data-target="s-project-details" onclick="pkgRailJump(event, 'project-info', 's-project-details')">
            <span class="n">1</span><span class="name">Project details</span>
        </a>
        <a class="pkg-rail-item" data-tab="project-info" data-target="s-room-overviews" onclick="pkgRailJump(event, 'project-info', 's-room-overviews')">
            <span class="n">2</span><span class="name">Rooms / spaces</span>
        </a>
        <a class="pkg-rail-item" data-tab="project-info" data-target="s-equipment" onclick="pkgRailJump(event, 'project-info', 's-equipment')">
            <span class="n">3</span><span class="name">Equipment</span>
        </a>

        <div class="pkg-rail-h">⚠️ RAMS info tab</div>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-programme" onclick="pkgRailJump(event, 'rams-info', 's-programme')">
            <span class="n">1</span><span class="name">Programme &amp; personnel</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-site-logistics" onclick="pkgRailJump(event, 'rams-info', 's-site-logistics')">
            <span class="n">2</span><span class="name">Site logistics</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-method-notes" onclick="pkgRailJump(event, 'rams-info', 's-method-notes')">
            <span class="n">3</span><span class="name">Method statement notes</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-activities" onclick="pkgRailJump(event, 'rams-info', 's-activities')">
            <span class="n">4</span><span class="name">Work activities</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-hazards" onclick="pkgRailJump(event, 'rams-info', 's-hazards')">
            <span class="n">5</span><span class="name">Hazards</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-ppe" onclick="pkgRailJump(event, 'rams-info', 's-ppe')">
            <span class="n">6</span><span class="name">PPE required</span>
        </a>
        <a class="pkg-rail-item" data-tab="rams-info" data-target="s-access" onclick="pkgRailJump(event, 'rams-info', 's-access')">
            <span class="n">7</span><span class="name">Access &amp; constraints</span>
        </a>
    </aside>

    <div class="pkg-main">

<form id="review-form" method="POST" action="{{ route('project-packages.review.approve', $package) }}" novalidate>
    @csrf

    {{-- ── Tab navigation ──────────────────────────────────────────── --}}
    <div class="review-tabs">
        <button type="button" class="review-tab-btn active" onclick="switchTab('project-info', this)">📋&nbsp; Project Info</button>
        <button type="button" class="review-tab-btn" onclick="switchTab('rams-info', this)">⚠️&nbsp; RAMS Info</button>
    </div>

    {{-- ── Tab 1: Project Info ───────────────────────────────────────── --}}
    <div id="tab-project-info" class="review-tab-panel active">

    {{-- ── 1. Project Details ──────────────────────────────────────── --}}
    <div class="review-section" id="s-project-details">
        <div class="review-section-header">
            <h2>1. Project Details</h2>
        </div>
        <div class="review-section-body">
            <div class="review-grid-2">
                <div class="form-group">
                    <label class="form-label">
                        Project Name <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="text"
                           name="project[project_name]"
                           class="form-control @error('project.project_name') is-invalid @enderror"
                           value="{{ old('project.project_name', $reviewPayload['project']['project_name']) }}"
                           maxlength="255"
                           required>
                    @error('project.project_name')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Quote / Project Ref</label>
                    <input type="text"
                           name="project[quote_ref]"
                           class="form-control"
                           value="{{ old('project.quote_ref', $reviewPayload['project']['quote_ref']) }}"
                           maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Client Name</label>
                    <input type="text"
                           name="project[client_name]"
                           class="form-control"
                           value="{{ old('project.client_name', $reviewPayload['project']['client_name']) }}"
                           maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label">Site Name</label>
                    <input type="text"
                           name="project[site_name]"
                           class="form-control"
                           value="{{ old('project.site_name', $reviewPayload['project']['site_name']) }}"
                           maxlength="255">
                </div>
                <div class="form-group" style="grid-column:span 2;">
                    <label class="form-label">Site Address</label>
                    <input type="text"
                           name="project[site_address]"
                           class="form-control"
                           value="{{ old('project.site_address', $reviewPayload['project']['site_address']) }}"
                           maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-label">Prepared By</label>
                    <input type="text"
                           name="project[prepared_by]"
                           class="form-control"
                           value="{{ old('project.prepared_by', $reviewPayload['project']['prepared_by']) }}"
                           maxlength="255">
                </div>
            </div>

            {{-- Scope of Works --}}
            <div class="form-group" style="margin-top:1rem;margin-bottom:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
                    <label class="form-label" style="margin:0;">
                        Scope of Works
                        <span style="color:var(--text-muted);font-weight:400;">(used in RAMS Section 1 — AI generated from room overviews)</span>
                    </label>
                    <button type="button"
                            id="btn-gen-scope"
                            class="btn btn-outline btn-sm"
                            onclick="generateScopeOfWorks()"
                            title="Generate from room overviews">
                        ✨ Generate
                    </button>
                </div>
                <textarea id="scope-of-works-field"
                          name="scope_of_works"
                          class="form-control"
                          rows="5"
                          maxlength="5000"
                          placeholder="Click ✨ Generate to auto-create a professional scope paragraph from your room overviews, or type it manually here.">{{ old('scope_of_works', $reviewPayload['scope_of_works'] ?? '') }}</textarea>
            </div>
            {{-- Works Overview (D-04, D-18) --}}
            <div class="form-group" style="margin-top:1rem;margin-bottom:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
                    <label class="form-label" for="works-overview-field" style="margin:0;">
                        Works Overview
                        <span style="color:var(--text-muted);font-weight:400;font-size:.8em;">(2–3 sentence executive summary — worksheet covers &amp; O&amp;M header)</span>
                    </label>
                    <button type="button"
                            id="btn-gen-overview"
                            class="btn btn-outline btn-sm"
                            onclick="generateWorksOverviewFromScope()"
                            title="Generate from room overviews (uses same AI call as Scope of Works)">
                        ✨ Generate
                    </button>
                </div>
                <textarea id="works-overview-field"
                          name="works_overview"
                          class="form-control"
                          rows="3"
                          maxlength="2000"
                          placeholder="Click ✨ Generate or type a short executive summary here.">{{ old('works_overview', $reviewPayload['works_overview'] ?? '') }}</textarea>
            </div>

            {{-- Phase 22.1 D-04: the standalone project-wide bullets textarea
                 was removed here in Plan 22.1-04. The survey "Planned AV Works"
                 drawer now derives per-room bullets from the union of
                 room_overviews[*].works_summary below. --}}
        </div>
    </div>

    {{-- ── 2. Room / Space Overviews ──────────────────────────────── --}}
    @php
        $roomOverviews  = $reviewPayload['room_overviews'] ?? [];
        $solutionTypes  = \App\Models\SolutionType::active()->ordered()->get();
    @endphp
    <div class="review-section" id="s-room-overviews">
        <div class="review-section-header">
            <h2>2. Room / Space Overviews</h2>
            <div style="display:flex;gap:.5rem;align-items:center;">
                <span style="font-size:.78rem;color:var(--text-muted);">Used in RAMS, O&amp;M, Worksheets &amp; Site Survey</span>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRoomOverviewRow()">+ Add Space</button>
            </div>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Write a client-facing narrative and a concise AV works summary for each room or space.
                Click <strong>✨ Generate</strong> to auto-write the AV Works Summary from your phrased overview.
            </p>
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th style="width:22%;">Room / Space</th>
                        <th style="width:140px;">Solution Type</th>
                        <th>Phrased Overview
                            <span style="font-weight:400;font-size:.75rem;color:var(--text-muted);"> — narrative, client-facing prose</span>
                        </th>
                        <th>AV Works Summary
                            <span style="font-weight:400;font-size:.75rem;color:var(--text-muted);"> — concise scope for docs</span>
                        </th>
                    </tr>
                </thead>
                <tbody id="room-overviews-tbody">
                    @forelse ($roomOverviews as $ri => $ro)
                    <tr data-room-overview-row="1">
                        <td style="vertical-align:top;padding-top:.6rem;">
                            <input type="text"
                                   name="room_overviews[{{ $ri }}][room]"
                                   value="{{ old("room_overviews.{$ri}.room", $ro['room']) }}"
                                   data-original-room="{{ old("room_overviews.{$ri}.room", $ro['room']) }}"
                                   placeholder="e.g. Meeting Room 1"
                                   maxlength="150"
                                   style="font-weight:600;color:#0f5460;"
                                   oninput="syncRoomNameToEquipment(this)">
                            <button type="button" class="btn-remove"
                                    onclick="removeRow(this)"
                                    title="Remove space"
                                    style="margin-top:.4rem;display:flex;align-items:center;gap:.3rem;font-size:.75rem;">
                                ✕ Remove
                            </button>
                        </td>
                        <td style="vertical-align:top;padding-top:.6rem;">
                            <select name="room_overviews[{{ $ri }}][solution_type_id]"
                                    class="solution-type-select"
                                    style="width:100%;font-size:.8rem;">
                                <option value="">— Select —</option>
                                @foreach($solutionTypes as $st)
                                <option value="{{ $st->id }}"
                                    {{ old("room_overviews.{$ri}.solution_type_id", $ro['solution_type_id'] ?? '') == $st->id ? 'selected' : '' }}>
                                    {{ $st->name }}
                                </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <textarea name="room_overviews[{{ $ri }}][overview]"
                                      rows="4"
                                      placeholder="e.g. Works in this space include the supply and installation of a new 86&quot; interactive display and fully integrated video conferencing system…">{{ old("room_overviews.{$ri}.overview", $ro['overview']) }}</textarea>
                        </td>
                        <td>
                            <textarea name="room_overviews[{{ $ri }}][works_summary]"
                                      rows="7"
                                      class="av-works-summary-textarea"
                                      placeholder="Room Type: Small Meeting Room (4–6 Persons)&#10;Display: 65&quot; Samsung Interactive (Wall Mounted)&#10;VC System: ClickShare Bar PRO (Under Display)&#10;Connectivity: Wireless + USB-C&#10;Power: 2x Socket&#10;Data: 2x Cat6">{{ old("room_overviews.{$ri}.works_summary", $ro['works_summary']) }}</textarea>
                            <button type="button"
                                    class="btn btn-outline btn-sm"
                                    onclick="generateRoomSummary(this)"
                                    style="margin-top:.4rem;font-size:.75rem;">
                                ✨ Generate
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr data-empty-room-row="1">
                        <td colspan="3" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                            No spaces detected. Click <strong>+ Add Space</strong> to add one manually.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 3. Equipment ────────────────────────────────────────────── --}}
    @php
        // Qty mismatch check — compare current equipment totals vs original snapshot
        $originalTotals  = $package->extracted_data['_original_totals'] ?? [];
        $currentTotals   = [];
        foreach (($reviewPayload['equipment'] ?? []) as $item) {
            $part = trim((string)($item['part_number'] ?? ''));
            $qty  = max(1, (int)($item['quantity'] ?? 1));
            if ($part !== '') {
                $currentTotals[$part] = ($currentTotals[$part] ?? 0) + $qty;
            }
        }
        $qtyMismatches = [];
        foreach ($originalTotals as $part => $origQty) {
            $currQty = $currentTotals[$part] ?? 0;
            if ($currQty !== $origQty) {
                $qtyMismatches[] = ['part' => $part, 'original' => $origQty, 'current' => $currQty];
            }
        }
    @endphp
    <div class="review-section" id="s-equipment">
        <div class="review-section-header">
            <h2>3. Equipment</h2>
            <span style="font-size:.78rem;color:var(--text-muted);">
                Categorised lists — only Hardware feeds RAMS &amp; O&amp;M.
            </span>
            <button type="button"
                    id="cleanup-lines-btn"
                    onclick="cleanupEquipmentLines(this)"
                    class="btn btn-outline btn-sm"
                    style="margin-left:auto;font-size:.78rem;white-space:nowrap;"
                    title="Run AI to normalise part numbers and shorten descriptions across every line">
                ✨ Tidy lines (AI)
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            @if(count($qtyMismatches) > 0)
            <div style="background:#FEF3C7;border-bottom:1px solid #FCD34D;padding:.65rem 1.25rem;font-size:.8125rem;color:#92400E;display:flex;gap:.75rem;align-items:flex-start;flex-wrap:wrap;">
                <span style="font-weight:700;white-space:nowrap;">⚠ Qty mismatch vs original quote:</span>
                <span>
                @foreach($qtyMismatches as $mm)
                    <strong>{{ $mm['part'] }}</strong>: was {{ $mm['original'] }}, now {{ $mm['current'] }}{{ ! $loop->last ? ' · ' : '' }}
                @endforeach
                </span>
            </div>
            @endif
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Categorise each line item. Only items marked <strong>Hardware</strong> will appear in RAMS &amp; O&amp;M lists.
            </p>
            @error('equipment')
                <p class="form-error" style="padding:.75rem 1.25rem;">{{ $message }}</p>
            @enderror
            @php
                $categoryOptions = [
                    'hardware'          => 'Hardware',
                    'cables'            => 'Cables',
                    'consumables'       => 'Consumables',
                    'services'          => 'Services / Professional',
                    'service_contracts' => 'Service Contracts',
                    'customer_supplied' => 'Customer Supplied',
                    'option'            => 'Option (Optional Items)',
                ];

                // ── Tier-1 Screen 03 v2 — area picker source list ───────────
                // Rooms defined in section 2 (Room / Space Overviews) are the
                // canonical list of "areas" equipment can be assigned to.
                // Pulled once here, deduped, and rendered as a <datalist>
                // below so every equipment row's area input becomes a typing
                // + suggestion field. syncRoomNameToEquipment() keeps the
                // datalist in sync when a room is renamed.
                $roomNamesForPicker = collect($roomOverviews ?? [])
                    ->pluck('room')
                    ->map(fn ($r) => trim((string) $r))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            @endphp

            {{-- Shared datalist consumed by every equipment row's area input.
                 Kept adjacent to the equipment tables so it lands inside the
                 same section — no cross-page bleed. --}}
            <datalist id="__proj_rooms">
                @foreach ($roomNamesForPicker as $r)
                    <option value="{{ $r }}"></option>
                @endforeach
            </datalist>
            @php
                // Legacy shape for the rest of the section — kept as-is so
                // downstream code sees the same variables it saw before.
                $rawEquipment = session()->hasOldInput()
                    ? (old('equipment', []) ?? [])
                    : ($reviewPayload['equipment'] ?? []);

                $equipmentRows = [];
                foreach ($rawEquipment as $i => $item) {
                    $equipmentRows[] = [
                        'idx'  => $i,
                        'item' => $item,
                    ];
                }

                $equipmentByCategory = [
                    'hardware'          => [],
                    'cables'            => [],
                    'consumables'       => [],
                    'services'          => [],
                    'service_contracts' => [],
                    'customer_supplied' => [],
                    'option'            => [],
                ];

                foreach ($equipmentRows as $row) {
                    $item = $row['item'];
                    $cat  = strtolower((string) ($item['category'] ?? 'hardware'));
                    if (! array_key_exists($cat, $equipmentByCategory)) {
                        $cat = 'hardware';
                    }
                    $equipmentByCategory[$cat][] = $row;
                }
            @endphp

            @foreach ($categoryOptions as $catKey => $catLabel)
                <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:var(--bg); display:flex; align-items:center; justify-content:space-between;">
                    <strong style="color:#0f5460;">{{ $catLabel }}</strong>
                    <button type="button" class="btn btn-outline btn-sm"
                            onclick="addRow('equipment-tbody-{{ $catKey }}', equipmentRowTemplate, '{{ $catKey }}')">
                        + Add {{ $catLabel }}
                    </button>
                </div>
                <table class="repeater-table">
                    <thead>
                        <tr>
                            <th class="col-qty">Qty</th>
                            <th style="width:140px;">Part Number</th>
                            <th>Equipment / Item Description</th>
                            <th style="width:150px;">Category</th>
                            {{-- Phase 23 Plan 06 — DRAW-46 D-03 zone column (additive). --}}
                            <th style="width:160px;">Zone</th>
                            <th class="col-area">Title / Section</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="equipment-tbody-{{ $catKey }}">
                        @php
                            $rowsForCat = $equipmentByCategory[$catKey] ?? [];
                            $rowsByRoom = [];
                            foreach ($rowsForCat as $row) {
                                $item = $row['item'];
                                $room = trim((string) ($item['area'] ?? ''));
                                if ($room === '') { $room = 'General'; }
                                $rowsByRoom[$room][] = $row;
                            }
                            ksort($rowsByRoom, SORT_NATURAL | SORT_FLAG_CASE);
                        @endphp
                        @forelse ($rowsByRoom as $roomName => $roomRows)
                            <tr data-room-row="1" style="background:var(--bg);">
                                <td colspan="7" style="font-weight:600;color:#0f5460;padding:.4rem .75rem;border-bottom:1px solid var(--border);">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
                                        <span class="eq-area-label">{{ $roomName }}</span>
                                        @if($catKey === 'hardware')
                                        <div style="display:flex;align-items:center;gap:.4rem;font-weight:400;flex-wrap:wrap;">
                                            <label style="font-size:.78rem;color:#6B7280;white-space:nowrap;">Total Rooms:</label>
                                            <input type="number"
                                                   name="room_qtys[{{ $roomName }}]"
                                                   value="{{ $reviewPayload['room_qtys'][$roomName] ?? 1 }}"
                                                   min="1" max="99"
                                                   class="room-gen-qty"
                                                   style="width:55px;padding:.25rem .35rem;border:1px solid #d1d5db;border-radius:4px;font-size:.82rem;font-weight:400;">
                                            <input type="text"
                                                   class="room-gen-names"
                                                   placeholder="Or names: Nutmeg, Project Room, Cardamon"
                                                   title="Optional. Comma-separated room names; overrides Total Rooms when provided so each generated room gets the right name (e.g. Nutmeg, Project Room, Cardamon) instead of numbered duplicates."
                                                   style="min-width:280px;flex:1;padding:.25rem .5rem;border:1px solid #d1d5db;border-radius:4px;font-size:.82rem;font-weight:400;">
                                            <button type="button"
                                                    class="btn btn-teal btn-sm"
                                                    onclick="generateSurveyRooms('{{ addslashes($roomName) }}', this)"
                                                    style="font-size:.78rem;padding:.25rem .65rem;white-space:nowrap;">
                                                Generate Rooms
                                            </button>
                                        </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @foreach ($roomRows as $row)
                                @php
                                    $i    = $row['idx'];
                                    $item = $row['item'];
                                    $selectedCategory = old("equipment.{$i}.category", $item['category'] ?? $catKey);
                                @endphp
                                <tr data-equip-row="1">
                                <td class="col-qty">
                                    <input type="number"
                                           name="equipment[{{ $i }}][quantity]"
                                           value="{{ old("equipment.{$i}.quantity", $item['quantity'] ?? 1) }}"
                                           min="1" max="999">
                                </td>
                                <td style="width:140px;">
                                    <textarea
                                           name="equipment[{{ $i }}][part_number]"
                                           class="equip-input pn @error("equipment.{$i}.part_number") is-invalid @enderror"
                                           rows="1"
                                           placeholder="e.g. YEA-MVC-S90"
                                           maxlength="100"
                                           oninput="equipAutoGrow(this); this.value=this.value.toUpperCase();">{{ old("equipment.{$i}.part_number", $item['part_number'] ?? '') }}</textarea>
                                    @error("equipment.{$i}.part_number")
                                        <p class="form-error">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td>
                                    <textarea
                                           name="equipment[{{ $i }}][name]"
                                           class="equip-input @error("equipment.{$i}.name") is-invalid @enderror"
                                           rows="1"
                                           placeholder="e.g. 55&quot; Samsung Display"
                                           maxlength="1000"
                                           oninput="equipAutoGrow(this)">{{ old("equipment.{$i}.name", $item['name'] ?? '') }}</textarea>
                                    @error("equipment.{$i}.name")
                                        <p class="form-error">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td style="width:150px;">
                                <select name="equipment[{{ $i }}][category]" data-equip-category>
                                        @foreach ($categoryOptions as $value => $label)
                                            <option value="{{ $value }}" {{ $selectedCategory === $value ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                {{-- Phase 23 Plan 06 — DRAW-46 D-03 zone dropdown column (additive). --}}
                                @php
                                    $zoneVocab    = config('drawings.zone_vocab', []);
                                    $currentZone  = old("equipment.{$i}.zone", $item['zone'] ?? '');
                                    $isVocabValue = $currentZone !== '' && in_array($currentZone, $zoneVocab, true);
                                    $isFreeText   = $currentZone !== '' && ! $isVocabValue;
                                @endphp
                                <td style="width:160px;">
                                    <div x-data="zonePicker(@js($currentZone), @js($zoneVocab), {{ $isFreeText ? 'true' : 'false' }})"
                                         class="zone-picker">
                                        <select x-show="!isFreeText"
                                                x-model="selected"
                                                @change="onChange"
                                                :name="isFreeText ? '' : 'equipment[{{ $i }}][zone]'"
                                                style="font-size:.82rem;width:100%;">
                                            <option value="">— default by category —</option>
                                            <template x-for="z in vocab" :key="z">
                                                <option :value="z" x-text="z"></option>
                                            </template>
                                            <option value="__other__">Other (free text)…</option>
                                        </select>
                                        <input x-show="isFreeText"
                                               type="text"
                                               x-model="freeText"
                                               :name="isFreeText ? 'equipment[{{ $i }}][zone]' : ''"
                                               maxlength="50"
                                               pattern="^[\p{L}\p{N} _\-]+$"
                                               placeholder="e.g. Server Cabinet"
                                               style="font-size:.82rem;width:100%;" />
                                        <button type="button"
                                                x-show="isFreeText"
                                                @click="cancelFreeText"
                                                style="font-size:.7rem;color:#777;background:none;border:0;padding:2px 4px;cursor:pointer;">
                                            ↩ use dropdown
                                        </button>
                                        <small class="form-hint" style="display:block;font-size:.65rem;color:#666;margin-top:2px;line-height:1.2;">
                                            Free text creates a separate group on the diagram — use the dropdown for consistency.
                                        </small>
                                    </div>
                                    @error("equipment.{$i}.zone")
                                        <p class="form-error">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="col-area">
                                    {{-- Tier-1 Screen 03 v2 — `list="__proj_rooms"` turns the
                                         free-text area input into a typed-autocomplete input
                                         sourced from the Room / Space Overviews defined in
                                         section 2. Native <datalist> — zero JS, zero risk to
                                         the save contract. --}}
                                    <input type="text"
                                           name="equipment[{{ $i }}][area]"
                                           value="{{ old("equipment.{$i}.area", $item['area'] ?? '') }}"
                                           list="__proj_rooms"
                                           placeholder="Type or pick a room…"
                                           maxlength="150"
                                           style="font-size:.82rem;">
                                </td>
                                <td class="col-del">
                                    <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                                </td>
                                </tr>
                            @endforeach
                            <tr data-room-row="1"><td colspan="7" style="height:6px;border:0;"></td></tr>
                        @empty
                            <tr data-empty-row="1">
                                <td colspan="7" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                    No items in this category yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endforeach
        </div>
    </div>

    </div>{{-- /tab-project-info --}}

    {{-- ── Tab 2: RAMS Info ────────────────────────────────────────── --}}
    <div id="tab-rams-info" class="review-tab-panel">

    {{-- ── 1. Programme & Personnel ─────────────────────────────── --}}
    <div class="review-section" id="s-programme">
        <div class="review-section-header">
            <h2>1. Programme &amp; Personnel</h2>
        </div>
        <div class="review-section-body">

            {{-- Personnel grid --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Project Manager</label>
                    <input type="text" name="programme[project_manager_name]" class="form-control"
                           value="{{ old('programme.project_manager_name', $reviewPayload['programme']['project_manager_name'] ?? '') }}"
                           placeholder="Full name" maxlength="255">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">PM Phone</label>
                    <input type="text" name="programme[project_manager_phone]" class="form-control"
                           value="{{ old('programme.project_manager_phone', $reviewPayload['programme']['project_manager_phone'] ?? '') }}"
                           placeholder="+44 7xxx xxxxxx" maxlength="50">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">PM Email</label>
                    <input type="email" name="programme[project_manager_email]" class="form-control"
                           value="{{ old('programme.project_manager_email', $reviewPayload['programme']['project_manager_email'] ?? '') }}"
                           placeholder="pm@company.com" maxlength="255">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Lead Engineer</label>
                    <input type="text" name="programme[lead_engineer_name]" class="form-control"
                           value="{{ old('programme.lead_engineer_name', $reviewPayload['programme']['lead_engineer_name'] ?? '') }}"
                           placeholder="Full name" maxlength="255">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Lead Engineer Phone</label>
                    <input type="text" name="programme[lead_engineer_phone]" class="form-control"
                           value="{{ old('programme.lead_engineer_phone', $reviewPayload['programme']['lead_engineer_phone'] ?? '') }}"
                           placeholder="+44 7xxx xxxxxx" maxlength="50">
                </div>
                {{-- Spacer to keep grid balanced --}}
                <div></div>
            </div>

            {{-- Programme dates --}}
            @php
                $isOngoing = old('programme.ongoing', $reviewPayload['programme']['ongoing'] ?? false);
            @endphp
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem;align-items:end;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Planned Start Date</label>
                    <input type="date" name="programme[planned_start_date]" class="form-control"
                           value="{{ old('programme.planned_start_date', $reviewPayload['programme']['planned_start_date'] ?? '') }}">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Planned End Date</label>
                    <input type="date" name="programme[planned_end_date]" class="form-control" id="planned-end-date"
                           value="{{ old('programme.planned_end_date', $reviewPayload['programme']['planned_end_date'] ?? '') }}"
                           {{ $isOngoing ? 'disabled' : '' }}
                           style="{{ $isOngoing ? 'opacity:.4;' : '' }}">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="opacity:0;pointer-events:none;">x</label>
                    <label class="ppe-check" style="cursor:pointer;">
                        <input type="checkbox" name="programme[ongoing]" value="1"
                               id="ongoing-checkbox"
                               {{ $isOngoing ? 'checked' : '' }}
                               onchange="toggleOngoing(this)">
                        Ongoing / No Fixed End Date
                    </label>
                </div>
            </div>

            {{-- Working hours + start time --}}
            <div style="margin-bottom:1.25rem;">
                <label class="form-label" style="margin-bottom:.5rem;">Working Hours</label>
                <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;">
                    @php
                        $workingHours = old('programme.working_hours', $reviewPayload['programme']['working_hours'] ?? 'in_hours');
                    @endphp
                    <label class="ppe-check" style="cursor:pointer;gap:.5rem;">
                        <input type="radio" name="programme[working_hours]" value="in_hours"
                               {{ $workingHours === 'in_hours' ? 'checked' : '' }}>
                        In Hours (Standard)
                    </label>
                    <label class="ppe-check" style="cursor:pointer;gap:.5rem;">
                        <input type="radio" name="programme[working_hours]" value="out_of_hours"
                               {{ $workingHours === 'out_of_hours' ? 'checked' : '' }}>
                        Out of Hours
                    </label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <label class="form-label" style="margin:0;white-space:nowrap;font-weight:500;">Start Time:</label>
                        <input type="time" name="programme[planned_start_time]" class="form-control"
                               value="{{ old('programme.planned_start_time', $reviewPayload['programme']['planned_start_time'] ?? '08:00') }}"
                               style="width:130px;">
                    </div>
                </div>
            </div>

            {{-- Additional engineers repeater --}}
            <div style="margin-bottom:1.25rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <label class="form-label" style="margin:0;">Additional Engineers</label>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addEngineerRow()">+ Add Engineer</button>
                </div>
                <table class="repeater-table" style="max-width:400px;">
                    <thead>
                        <tr>
                            <th>Engineer Name</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="engineers-tbody">
                        @php
                            $engineers = old('programme.additional_engineers',
                                $reviewPayload['programme']['additional_engineers'] ?? []);
                        @endphp
                        @forelse ($engineers as $ei => $eng)
                        <tr data-engineer-row="1">
                            <td>
                                <input type="text"
                                       name="programme[additional_engineers][{{ $ei }}]"
                                       value="{{ is_array($eng) ? ($eng['name'] ?? $eng[0] ?? '') : $eng }}"
                                       placeholder="Full name" maxlength="255">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                        @empty
                        <tr data-empty-engineer-row="1">
                            <td colspan="2" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                No additional engineers added yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Programmers repeater --}}
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <label class="form-label" style="margin:0;">Programmers</label>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addProgrammerRow()">+ Add Programmer</button>
                </div>
                <table class="repeater-table" style="max-width:400px;">
                    <thead>
                        <tr>
                            <th>Programmer Name</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="programmers-tbody">
                        @php
                            $programmers = old('programme.programmers',
                                $reviewPayload['programme']['programmers'] ?? []);
                        @endphp
                        @forelse ($programmers as $pi => $prog)
                        <tr data-programmer-row="1">
                            <td>
                                <input type="text"
                                       name="programme[programmers][{{ $pi }}]"
                                       value="{{ is_array($prog) ? ($prog['name'] ?? $prog[0] ?? '') : $prog }}"
                                       placeholder="Full name" maxlength="255">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                        @empty
                        <tr data-empty-programmer-row="1">
                            <td colspan="2" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                No programmers added yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Site Vehicles & Registrations — required for site security
                 / parking permits (e.g. power stations, MOD sites). One entry
                 per row. Format: "REG ABC123 - Crew van" (notes after " - "). --}}
            <div class="form-group" style="margin-top:1.25rem;">
                <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:.5rem;">
                    <div>
                        <label class="form-label" style="margin-bottom:.15rem;">Site Vehicles &amp; Registrations</label>
                        <p style="font-size:.78rem;color:#666;margin:0;">
                            One vehicle per line. Format <code>REG - Notes</code> (e.g. <code>AB12 CDE - Crew van</code>).
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addVehicleRow()">+ Add Vehicle</button>
                </div>
                <table class="programme-table">
                    <thead>
                        <tr>
                            <th>Vehicle Reg / Notes</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="vehicles-tbody">
                        @php
                            $siteVehicles = old('programme.site_vehicles',
                                $reviewPayload['programme']['site_vehicles'] ?? []);
                        @endphp
                        @forelse ($siteVehicles as $vi => $veh)
                        <tr data-vehicle-row="1">
                            <td>
                                <input type="text"
                                       name="programme[site_vehicles][{{ $vi }}]"
                                       value="{{ is_array($veh) ? ($veh[0] ?? '') : $veh }}"
                                       placeholder="AB12 CDE - Crew van" maxlength="120">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                        @empty
                        <tr data-empty-vehicle-row="1">
                            <td colspan="2" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                No vehicles added yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    {{-- ── 2. Site Logistics ───────────────────────────────────── --}}
    <div class="review-section" id="s-site-logistics">
        <div class="review-section-header">
            <h2>2. Site Logistics</h2>
        </div>
        <div class="review-section-body">

            {{-- Site contact --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Site Contact Name</label>
                    <input type="text" name="site_logistics[contact_name]" class="form-control"
                           value="{{ old('site_logistics.contact_name', $reviewPayload['site_logistics']['contact_name'] ?? '') }}"
                           placeholder="Contact full name" maxlength="255">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Site Contact Phone</label>
                    <input type="text" name="site_logistics[contact_phone]" class="form-control"
                           value="{{ old('site_logistics.contact_phone', $reviewPayload['site_logistics']['contact_phone'] ?? '') }}"
                           placeholder="+44 7xxx xxxxxx" maxlength="50">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Site Contact Email</label>
                    <input type="email" name="site_logistics[contact_email]" class="form-control"
                           value="{{ old('site_logistics.contact_email', $reviewPayload['site_logistics']['contact_email'] ?? '') }}"
                           placeholder="contact@client.com" maxlength="255">
                </div>
            </div>

            {{-- Parking --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Parking Available on Site?</label>
                    @php $parkingVal = old('site_logistics.parking', $reviewPayload['site_logistics']['parking'] ?? ''); @endphp
                    <div style="display:flex;gap:1rem;margin-top:.3rem;">
                        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;">
                            <input type="radio" name="site_logistics[parking]" value="yes" {{ $parkingVal === 'yes' ? 'checked' : '' }}> Yes
                        </label>
                        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;">
                            <input type="radio" name="site_logistics[parking]" value="no" {{ $parkingVal === 'no' ? 'checked' : '' }}> No
                        </label>
                        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;">
                            <input type="radio" name="site_logistics[parking]" value="" {{ $parkingVal === '' ? 'checked' : '' }}> Unknown
                        </label>
                    </div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Parking Notes</label>
                    <input type="text" name="site_logistics[parking_notes]" class="form-control"
                           value="{{ old('site_logistics.parking_notes', $reviewPayload['site_logistics']['parking_notes'] ?? '') }}"
                           placeholder="e.g. Street parking available, 30 min limit / loading bay at rear">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Installation Floor</label>
                    <input type="text" name="site_logistics[install_floor]" class="form-control"
                           value="{{ old('site_logistics.install_floor', $reviewPayload['site_logistics']['install_floor'] ?? '') }}"
                           placeholder="e.g. Ground Floor, 3rd Floor, Multiple floors">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Delivery &amp; Staging Area</label>
                    <input type="text" name="site_logistics[delivery_area]" class="form-control"
                           value="{{ old('site_logistics.delivery_area', $reviewPayload['site_logistics']['delivery_area'] ?? '') }}"
                           placeholder="e.g. Reception, Ground floor comms room">
                </div>
            </div>

            {{-- Access type --}}
            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label">Site Access Requirements</label>
                @php $accessType = old('site_logistics.access_type', $reviewPayload['site_logistics']['access_type'] ?? ''); @endphp
                <div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:.3rem;">
                    @foreach([
                        'no_special' => 'No special requirements',
                        'induction'  => 'Site induction required',
                        'reception'  => 'Report to reception',
                        'security'   => 'Report to security',
                        'other'      => 'Other',
                    ] as $val => $lbl)
                    <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;">
                        <input type="radio" name="site_logistics[access_type]" value="{{ $val }}"
                               {{ $accessType === $val ? 'checked' : '' }}>
                        {{ $lbl }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Access Notes</label>
                    <textarea name="site_logistics[access_notes]" class="form-control" rows="3"
                              placeholder="e.g. All engineers to sign in at main reception, visitor badges required. Induction takes approx 20 min…">{{ old('site_logistics.access_notes', $reviewPayload['site_logistics']['access_notes'] ?? '') }}</textarea>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Site Restrictions</label>
                    <textarea name="site_logistics[restrictions]" class="form-control" rows="3"
                              placeholder="e.g. No works during school hours. Hard hat zone in atrium…">{{ old('site_logistics.restrictions', $reviewPayload['site_logistics']['restrictions'] ?? '') }}</textarea>
                </div>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">Commissioning &amp; Testing Notes</label>
                <textarea name="site_logistics[commissioning_notes]" class="form-control" rows="3"
                          placeholder="e.g. Full system test and client sign-off required per room. Snagging period 5 working days after practical completion…">{{ old('site_logistics.commissioning_notes', $reviewPayload['site_logistics']['commissioning_notes'] ?? '') }}</textarea>
            </div>

        </div>
    </div>

    {{-- ── 3. Method Statement Notes ───────────────────────────────── --}}
    <div class="review-section" id="s-method-notes">
        <div class="review-section-header">
            <h2>3. Method Statement Notes</h2>
        </div>
        <div class="review-section-body">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="margin-bottom:.5rem;">
                    Additional scope notes or instructions for the AI method statement generator
                    <span style="color:var(--text-muted);font-weight:400;">(optional)</span>
                </label>
                <textarea name="method_statement_notes"
                          class="form-control"
                          rows="4"
                          maxlength="5000"
                          placeholder="e.g. All works to be carried out during school holiday period. Ceiling works in main hall require MEWP access…">{{ old('method_statement_notes', $reviewPayload['method_statement_notes']) }}</textarea>
            </div>
        </div>
    </div>

    {{-- ── 4. Activities ───────────────────────────────────────────── --}}
    <div class="review-section" id="s-activities">
        <div class="review-section-header">
            <h2>4. Work Activities</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('activities-tbody', activityRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            @error('activities')
                <p class="form-error" style="padding:.75rem 1.25rem;">{{ $message }}</p>
            @enderror
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th style="width:200px;">Activity Key</th>
                        <th>Activity Label / Description</th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="activities-tbody">
                    @foreach ($reviewPayload['activities'] as $i => $activity)
                        <tr>
                            <td>
                                <input type="text"
                                       name="activities[{{ $i }}][key]"
                                       value="{{ old("activities.{$i}.key", $activity['key']) }}"
                                       placeholder="e.g. display_installation"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.8rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="activities[{{ $i }}][label]"
                                       value="{{ old("activities.{$i}.label", $activity['label']) }}"
                                       placeholder="e.g. Display & Screen Installation"
                                       maxlength="255">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 5. Hazards ──────────────────────────────────────────────── --}}
    <div class="review-section" id="s-hazards">
        <div class="review-section-header">
            <h2>5. Hazards</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('hazards-tbody', hazardRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Enter one control measure per line in the Control Measures column.
            </p>
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th class="col-act">Activity</th>
                        <th>Hazard</th>
                        <th class="col-risk">Risk Level</th>
                        <th>Control Measures <span style="font-weight:400;font-size:.75rem;">(one per line)</span></th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="hazards-tbody">
                    @foreach ($reviewPayload['hazards'] as $i => $hazard)
                        <tr>
                            <td class="col-act">
                                <input type="text"
                                       name="hazards[{{ $i }}][activity_key]"
                                       value="{{ old("hazards.{$i}.activity_key", $hazard['activity_key']) }}"
                                       placeholder="optional"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.78rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="hazards[{{ $i }}][hazard]"
                                       value="{{ old("hazards.{$i}.hazard", $hazard['hazard']) }}"
                                       placeholder="e.g. Working at Height"
                                       maxlength="500">
                            </td>
                            <td class="col-risk">
                                <select name="hazards[{{ $i }}][risk]">
                                    @foreach (['Low', 'Medium', 'High'] as $level)
                                        <option value="{{ $level }}"
                                                {{ old("hazards.{$i}.risk", $hazard['risk']) === $level ? 'selected' : '' }}>
                                            {{ $level }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <textarea name="hazards[{{ $i }}][control_measures]"
                                          rows="3"
                                          placeholder="Enter each control measure on a new line…">{{ old("hazards.{$i}.control_measures", implode("\n", $hazard['control_measures'])) }}</textarea>
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 6. PPE ───────────────────────────────────────────────────── --}}
    <div class="review-section" id="s-ppe">
        <div class="review-section-header">
            <h2>6. PPE Required</h2>
        </div>
        <div class="review-section-body">
            @error('ppe')
                <p class="form-error" style="margin-bottom:.75rem;">{{ $message }}</p>
            @enderror
            <div class="ppe-grid">
                @foreach ($ppeOptions as $ppeItem)
                    @php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? in_array($ppeItem, old('ppe', []), true)
                            : in_array($ppeItem, $reviewPayload['ppe'], true);
                    @endphp
                    <label class="ppe-check">
                        <input type="checkbox" name="ppe[]" value="{{ $ppeItem }}" {{ $checked ? 'checked' : '' }}>
                        {{ $ppeItem }}
                    </label>
                @endforeach
            </div>

            {{-- Preserve any PPE items from extracted data that are not in the standard list --}}
            @foreach ($reviewPayload['ppe'] as $ppeItem)
                @if (! in_array($ppeItem, $ppeOptions, true))
                    <div style="margin-top:.75rem;display:flex;align-items:center;gap:.5rem;">
                        <label class="ppe-check" style="flex:1;">
                            <input type="checkbox" name="ppe[]" value="{{ $ppeItem }}"
                                   {{ (session()->hasOldInput() ? in_array($ppeItem, old('ppe', []), true) : in_array($ppeItem, $reviewPayload['ppe'], true)) ? 'checked' : '' }}>
                            {{ $ppeItem }} <em style="font-size:.75rem;color:var(--text-muted);">(custom)</em>
                        </label>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- ── 7. Access / Site Constraints ───────────────────────────── --}}
    <div class="review-section" id="s-access">
        <div class="review-section-header">
            <h2>7. Access &amp; Site Constraints</h2>
        </div>
        <div class="review-section-body">
            <div class="access-grid">
                @php
                    $accessFields = [
                        'ladders'          => 'Podium Steps / Ladders required',
                        'tower'            => 'Access Tower required',
                        'scissor_lift'     => 'Scissor Lift / MEWP required',
                        'out_of_hours'     => 'Out-of-hours working',
                        'live_environment' => 'Live / occupied environment',
                    ];
                @endphp
                @foreach ($accessFields as $fieldKey => $fieldLabel)
                    @php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? !empty(old("access.{$fieldKey}"))
                            : (bool) ($reviewPayload['access'][$fieldKey] ?? false);
                    @endphp
                    <label class="ppe-check">
                        <input type="checkbox"
                               name="access[{{ $fieldKey }}]"
                               value="1"
                               {{ $checked ? 'checked' : '' }}>
                        {{ $fieldLabel }}
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    </div>{{-- /tab-rams-info --}}

    {{-- ── 11. Action bar ───────────────────────────────────────────── --}}
    <div class="action-bar">
        <a href="{{ route('projects.show', $package->project_id) }}" class="btn btn-ghost btn-sm">Cancel</a>

        {{-- Save Review: posts to the save/update endpoint --}}
        <button type="button"
                id="btn-save-review"
                class="btn btn-outline">
            💾 Save Project Data
        </button>

        {{-- Approve: posts to the approve endpoint (generation triggered from project page) --}}
        <button type="button"
                id="btn-approve"
                class="btn btn-teal"
                onclick="confirmApprove().then(function(ok){ if(ok){ var f = document.getElementById('btn-approve').closest('form'); if (f) f.submit(); } });">
            ✓ Save &amp; Return
        </button>
    </div>

</form>

    </div>{{-- /pkg-main --}}
</div>{{-- /pkg-layout --}}

{{-- Hidden save form — shares the same data but posts to the save endpoint --}}
<form id="save-form" method="POST" action="{{ route('project-packages.review.update', $package) }}" style="display:none;">
    @csrf
</form>

@endsection

@push('scripts')
<script>
// ─── Row counter (used for unique indices when adding new rows) ───────────────
let equipmentCount  = {{ count($reviewPayload['equipment']) }};
let activityCount   = {{ count($reviewPayload['activities']) }};
let hazardCount     = {{ count($reviewPayload['hazards']) }};

// ─── Phase 23 Plan 06 — zone vocab + Alpine zonePicker() factory ───────────────
// DRAW-46 D-03/D-04: dropdown + "Other (free text)" escape hatch per equipment
// row. Published to window so the addRow() JS row template can reuse the same
// vocab without re-rendering Blade.
window.__zoneVocab = @js(config('drawings.zone_vocab', []));

function zonePicker(initial, vocab, isFreeTextInitial) {
    const initialIsVocab = !!initial && Array.isArray(vocab) && vocab.includes(initial);
    return {
        vocab: Array.isArray(vocab) ? vocab : [],
        // When initial is a vocab value, show it selected; when it's free text,
        // freeze the select on "__other__" so re-entering free-text mode preserves it.
        selected: initial === '' ? '' : (initialIsVocab ? initial : '__other__'),
        freeText: initialIsVocab ? '' : (initial || ''),
        isFreeText: !!isFreeTextInitial,
        onChange() {
            if (this.selected === '__other__') {
                this.isFreeText = true;
            } else {
                this.isFreeText = false;
                this.freeText = '';
            }
        },
        cancelFreeText() {
            this.isFreeText = false;
            this.freeText = '';
            this.selected = '';
        },
    };
}

// ─── Row templates ────────────────────────────────────────────────────────────
function equipmentRowTemplate(idx, category) {
    return `<tr data-equip-row="1">
        <td class="col-qty">
            <input type="number" name="equipment[${idx}][quantity]" value="1" min="1" max="999">
        </td>
        <td style="width:140px;">
            <textarea name="equipment[${idx}][part_number]" class="equip-input pn" rows="1"
                      placeholder="e.g. YEA-MVC-S90" maxlength="100"
                      oninput="equipAutoGrow(this); this.value=this.value.toUpperCase();"></textarea>
        </td>
        <td>
            <textarea name="equipment[${idx}][name]" class="equip-input" rows="1"
                      placeholder="e.g. 55&quot; Display" maxlength="1000"
                      oninput="equipAutoGrow(this)"></textarea>
        </td>
        <td style="width:150px;">
            <select name="equipment[${idx}][category]" data-equip-category>
                <option value="hardware" ${category === 'hardware' ? 'selected' : ''}>Hardware</option>
                <option value="cables" ${category === 'cables' ? 'selected' : ''}>Cables</option>
                <option value="consumables" ${category === 'consumables' ? 'selected' : ''}>Consumables</option>
                <option value="services" ${category === 'services' ? 'selected' : ''}>Services / Professional</option>
                <option value="service_contracts" ${category === 'service_contracts' ? 'selected' : ''}>Service Contracts</option>
                <option value="customer_supplied" ${category === 'customer_supplied' ? 'selected' : ''}>Customer Supplied</option>
                <option value="option" ${category === 'option' ? 'selected' : ''}>Option (Optional Items)</option>
            </select>
        </td>
        <td style="width:160px;">
            <div x-data="zonePicker('', window.__zoneVocab || [], false)" class="zone-picker">
                <select x-show="!isFreeText" x-model="selected" @change="onChange"
                        :name="isFreeText ? '' : 'equipment[${idx}][zone]'"
                        style="font-size:.82rem;width:100%;">
                    <option value="">— default by category —</option>
                    <template x-for="z in vocab" :key="z">
                        <option :value="z" x-text="z"></option>
                    </template>
                    <option value="__other__">Other (free text)…</option>
                </select>
                <input x-show="isFreeText" type="text" x-model="freeText"
                       :name="isFreeText ? 'equipment[${idx}][zone]' : ''"
                       maxlength="50" pattern="^[\\p{L}\\p{N} _\\-]+$"
                       placeholder="e.g. Server Cabinet"
                       style="font-size:.82rem;width:100%;" />
                <button type="button" x-show="isFreeText" @click="cancelFreeText"
                        style="font-size:.7rem;color:#777;background:none;border:0;padding:2px 4px;cursor:pointer;">↩ use dropdown</button>
                <small class="form-hint" style="display:block;font-size:.65rem;color:#666;margin-top:2px;line-height:1.2;">Free text creates a separate group on the diagram — use the dropdown for consistency.</small>
            </div>
        </td>
        <td class="col-area">
            <input type="text" name="equipment[${idx}][area]" placeholder="Type or pick a room…"
                   list="__proj_rooms" maxlength="150" style="font-size:.82rem;">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function activityRowTemplate(idx) {
    return `<tr>
        <td>
            <input type="text" name="activities[${idx}][key]" placeholder="e.g. display_installation" maxlength="100" style="font-family:monospace;font-size:.8rem;">
        </td>
        <td>
            <input type="text" name="activities[${idx}][label]" placeholder="e.g. Display &amp; Screen Installation" maxlength="255">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function hazardRowTemplate(idx) {
    return `<tr>
        <td class="col-act">
            <input type="text" name="hazards[${idx}][activity_key]" placeholder="optional" maxlength="100" style="font-family:monospace;font-size:.78rem;">
        </td>
        <td>
            <input type="text" name="hazards[${idx}][hazard]" placeholder="e.g. Working at Height" maxlength="500">
        </td>
        <td class="col-risk">
            <select name="hazards[${idx}][risk]">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
            </select>
        </td>
        <td>
            <textarea name="hazards[${idx}][control_measures]" rows="3" placeholder="Enter each control measure on a new line…"></textarea>
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

// ─── Generic add / remove ─────────────────────────────────────────────────────
function addRow(tbodyId, templateFn, category) {
    const tbody = document.getElementById(tbodyId);
    // Use a timestamp-based index to guarantee uniqueness without caring about gaps.
    const idx = Date.now();
    const div = document.createElement('tbody');
    div.innerHTML = templateFn(idx, category || 'hardware');
    const row = div.firstElementChild;
    tbody.appendChild(row);
    ensureEquipmentEmptyState(tbody);
    // Focus first input in the new row
    const first = row.querySelector('input, textarea, select');
    if (first) first.focus();
}

async function removeRow(btn) {
    if (!(await window.appConfirm('Remove this row? This cannot be undone.', { title:'Remove row?', confirmLabel:'Remove', danger:true }))) return;
    const row = btn.closest('tr');
    if (row) {
        const tbody = row.closest('tbody');
        row.remove();
        if (tbody) ensureEquipmentEmptyState(tbody);
    }
}

// ─── Equipment textarea auto-grow (part_number + name) ───────────────────────
// Called on every `oninput` + once per row on DOM ready. Resets height to 0
// then uses scrollHeight so the textarea shrinks when text is deleted too.
function equipAutoGrow(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight + 2) + 'px';
}

// ─── AI line cleanup — normalises part numbers + shortens descriptions ───────
async function cleanupEquipmentLines(btn) {
    if (!btn) return;
    if (!(await window.appConfirm('Run AI to tidy every line item? This rewrites part numbers (e.g. all-caps + dashes) and shortens product descriptions across the whole equipment list. Unsaved edits will be overwritten by the saved (server-side) data, so save first if you have uncommitted changes.', { title:'Tidy line items?', confirmLabel:'Tidy' }))) return;
    const original = btn.textContent;
    btn.disabled    = true;
    btn.textContent = 'Tidying…';
    try {
        const resp = await fetch('{{ route('project-packages.cleanup-lines', $package) }}', {
            method:  'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept':       'application/json',
            },
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert(err.error ?? 'Cleanup failed. Please try again.');
            return;
        }
        const data = await resp.json();
        // Patch each row in place — find inputs by name="equipment[ID][...]".
        for (const row of (data.rows ?? [])) {
            const partEl = document.querySelector('textarea[name="equipment[' + row.id + '][part_number]"]');
            const nameEl = document.querySelector('textarea[name="equipment[' + row.id + '][name]"]');
            if (partEl) { partEl.value = row.part_number; equipAutoGrow(partEl); }
            if (nameEl) { nameEl.value = row.name;        equipAutoGrow(nameEl); }
        }
        btn.textContent = '✓ Tidied ' + (data.updated ?? 0) + ' lines';
        setTimeout(() => { btn.textContent = original; btn.disabled = false; }, 2000);
    } catch (e) {
        alert('Network error during cleanup. Please try again.');
        btn.textContent = original;
        btn.disabled    = false;
    }
}

// Initialise sizes + attach a safety listener to keep existing browsers happy
// if someone clones a row and forgets the inline oninput.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('textarea.equip-input').forEach(equipAutoGrow);
});
document.addEventListener('input', function (e) {
    if (e.target && e.target.matches && e.target.matches('textarea.equip-input')) {
        equipAutoGrow(e.target);
    }
});

// ─── Dynamic Title/Section reorder ───────────────────────────────────────────
// When the engineer changes a line's area input, slide the row under the
// matching room-group header within the same category — no page refresh.
document.addEventListener('change', function (e) {
    const t = e.target;
    if (!t || !t.matches || !t.matches('input[name^="equipment["][name$="[area]"]')) return;
    moveEquipRowToArea(t);
});

function moveEquipRowToArea(areaInput) {
    const row = areaInput.closest('tr[data-equip-row]');
    if (!row) return;
    const tbody = row.parentElement;
    if (!tbody) return;
    const newArea = (areaInput.value || '').trim() || 'General';

    // Find or create the room-group header inside this tbody.
    const headers = tbody.querySelectorAll('tr[data-room-row] td .eq-area-label');
    let targetHeaderRow = null;
    headers.forEach(span => {
        if ((span.textContent || '').trim() === newArea) {
            targetHeaderRow = span.closest('tr[data-room-row]');
        }
    });

    // Drop any data-empty-row placeholder once we are about to populate the tbody.
    const empty = tbody.querySelector('tr[data-empty-row]');
    if (empty) empty.remove();

    if (!targetHeaderRow) {
        // Build a new room-header row that matches the existing markup so it
        // looks identical to what Blade renders on first load.
        targetHeaderRow = document.createElement('tr');
        targetHeaderRow.setAttribute('data-room-row', '1');
        targetHeaderRow.style.background = 'var(--bg)';
        const td = document.createElement('td');
        td.colSpan = 6;
        td.style.cssText = 'font-weight:600;color:#0f5460;padding:.4rem .75rem;border-bottom:1px solid var(--border);';
        const label = document.createElement('span');
        label.className   = 'eq-area-label';
        label.textContent = newArea;
        td.appendChild(label);
        targetHeaderRow.appendChild(td);
        tbody.appendChild(targetHeaderRow);
    }

    // Insert the moved row immediately after the target header — and any
    // already-grouped sibling rows that share the area, so the row sits at
    // the bottom of its new group rather than mid-group.
    let insertAfter = targetHeaderRow;
    let next = targetHeaderRow.nextElementSibling;
    while (next && next.matches('tr[data-equip-row]')) {
        const nextArea = (next.querySelector('input[name$="[area]"]')?.value ?? '').trim() || 'General';
        if (nextArea !== newArea) break;
        insertAfter = next;
        next = next.nextElementSibling;
    }
    if (row !== insertAfter) {
        insertAfter.after(row);
    }

    // Clean up: any header row that no longer has an equipment row beneath it
    // (before the next header / spacer) should be removed so empty groups
    // don't pile up after repeated moves.
    tbody.querySelectorAll('tr[data-room-row]').forEach(h => {
        let n = h.nextElementSibling;
        let hasRow = false;
        while (n && !n.matches('tr[data-room-row]')) {
            if (n.matches('tr[data-equip-row]')) { hasRow = true; break; }
            n = n.nextElementSibling;
        }
        if (!hasRow) h.remove();
    });
}

// ─── Save Review ──────────────────────────────────────────────────────────────
// Serialise the main form and re-submit it via the hidden save form so we
// can POST to a different URL without duplicating all the field markup.
document.getElementById('btn-save-review').addEventListener('click', function () {
    const reviewForm = document.getElementById('review-form');
    const saveForm   = document.getElementById('save-form');

    // Copy all inputs from the review form into the save form as hidden fields.
    // Remove any previously cloned fields first.
    saveForm.querySelectorAll('[data-cloned]').forEach(el => el.remove());

    const data = new FormData(reviewForm);
    for (const [key, value] of data.entries()) {
        if (key === '_token') continue; // save-form already has its own CSRF token
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = key;
        hidden.value = value;
        hidden.setAttribute('data-cloned', '1');
        saveForm.appendChild(hidden);
    }

    saveForm.submit();
});

// ─── Move row when category changes ──────────────────────────────────────────
document.addEventListener('change', function (e) {
    if (!e.target.matches('select[data-equip-category]')) return;
    const select   = e.target;
    const row      = select.closest('tr');
    const category = select.value || 'hardware';
    const tbody    = document.getElementById('equipment-tbody-' + category);
    const prevTbody = row ? row.closest('tbody') : null;

    if (!row || !tbody) return;

    // Find or create a "General" room-header row in the destination tbody.
    // Equipment rows are always grouped under a room header — we need one
    // in the target section or the row will appear without a section label.
    let destRoomRow = tbody.querySelector('tr[data-room-row]');
    if (!destRoomRow) {
        destRoomRow = document.createElement('tr');
        destRoomRow.setAttribute('data-room-row', '1');
        destRoomRow.style.background = 'var(--bg)';
        destRoomRow.innerHTML = `<td colspan="6" style="font-weight:600;color:#0f5460;padding:.4rem .75rem;border-bottom:1px solid var(--border);">
            <span class="eq-area-label">General</span>
        </td>`;
        tbody.appendChild(destRoomRow);
    }

    // Move the equipment row after the last equip-row in the destination
    // (or append to end of tbody if none yet).
    tbody.appendChild(row);

    // Clean up orphaned room-header rows in the source tbody (headers that
    // have no following equip-row sibling belong to sections now empty).
    if (prevTbody && prevTbody !== tbody) {
        prevTbody.querySelectorAll('tr[data-room-row]').forEach(function (headerRow) {
            // Walk siblings after this header — if none are equip-rows, remove header.
            let sibling = headerRow.nextElementSibling;
            let hasEquip = false;
            while (sibling && !sibling.matches('tr[data-room-row]')) {
                if (sibling.matches('tr[data-equip-row]')) { hasEquip = true; break; }
                sibling = sibling.nextElementSibling;
            }
            if (!hasEquip) headerRow.remove();
        });
        ensureEquipmentEmptyState(prevTbody);
    }

    ensureEquipmentEmptyState(tbody);
});

// ─── Empty state handling for equipment categories ───────────────────────────
function ensureEquipmentEmptyState(tbody) {
    if (!tbody) return;
    const hasRows = tbody.querySelectorAll('tr[data-equip-row]').length > 0;
    let emptyRow = tbody.querySelector('tr[data-empty-row]');
    if (hasRows && emptyRow) {
        emptyRow.remove();
        return;
    }
    if (!hasRows && !emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.setAttribute('data-empty-row', '1');
        emptyRow.innerHTML = `<td colspan="6" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
            No items in this category yet.
        </td>`;
        tbody.appendChild(emptyRow);
    }
}

// Initialise empty state on load (handles categories with zero rows)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tbody[id^="equipment-tbody-"]').forEach(ensureEquipmentEmptyState);
});

// ─── Approve confirmation ─────────────────────────────────────────────────────
// Returns a Promise<boolean> — callers must use .then() / await.
function confirmApprove() {
    return window.appConfirm(
        'Save this reviewed project data? This data will be used for all document generation. You can still edit and re-save at any time.',
        { title:'Save reviewed data?', confirmLabel:'Save' }
    );
}

// ─── Room Overview rows ───────────────────────────────────────────────────────
let roomOverviewCount = {{ count($reviewPayload['room_overviews'] ?? []) }};

// Solution type options for JS-generated rows
const SOLUTION_TYPE_OPTIONS = [
    '<option value="">— Select —</option>',
    @foreach($solutionTypes as $st)
    '<option value="{{ $st->id }}">{{ addslashes($st->name) }}</option>',
    @endforeach
].join('');

function addRoomOverviewRow() {
    const tbody = document.getElementById('room-overviews-tbody');
    // Remove empty-state row if present
    const emptyRow = tbody.querySelector('tr[data-empty-room-row]');
    if (emptyRow) emptyRow.remove();

    const idx = Date.now();
    const tr  = document.createElement('tr');
    tr.setAttribute('data-room-overview-row', '1');
    tr.innerHTML = `
        <td style="vertical-align:top;padding-top:.6rem;">
            <input type="text" name="room_overviews[${idx}][room]"
                   placeholder="e.g. Meeting Room 1" maxlength="150"
                   style="font-weight:600;color:#0f5460;"
                   oninput="syncRoomNameToEquipment(this)"
                   data-original-room="">
            <button type="button" class="btn-remove" onclick="removeRow(this)"
                    title="Remove space"
                    style="margin-top:.4rem;display:flex;align-items:center;gap:.3rem;font-size:.75rem;">
                ✕ Remove
            </button>
        </td>
        <td style="vertical-align:top;padding-top:.6rem;">
            <select name="room_overviews[${idx}][solution_type_id]"
                    class="solution-type-select"
                    style="width:100%;font-size:.8rem;">
                ${SOLUTION_TYPE_OPTIONS}
            </select>
        </td>
        <td>
            <textarea name="room_overviews[${idx}][overview]" rows="4"
                      placeholder="e.g. Works in this space include the supply and installation of a new 86&quot; interactive display…"></textarea>
        </td>
        <td>
            <textarea name="room_overviews[${idx}][works_summary]" rows="7"
                      class="av-works-summary-textarea"
                      placeholder="Room Type: ...\nDisplay: ...\nVC System: ...\nConnectivity: ...\nPower: ...\nData: ..."></textarea>
            <button type="button" class="btn btn-outline btn-sm"
                    onclick="generateRoomSummary(this)"
                    style="margin-top:.4rem;font-size:.75rem;">
                ✨ Generate
            </button>
        </td>`;
    tbody.appendChild(tr);
    tr.querySelector('input[type="text"]')?.focus();
    roomOverviewCount++;
}

// ─── AI generate AV Works Summary ────────────────────────────────────────────
function generateRoomSummary(btn) {
    const row             = btn.closest('tr');
    const overviewEl      = row.querySelector('textarea[name*="[overview]"]');
    const summaryEl       = row.querySelector('textarea[name*="[works_summary]"]');
    const roomEl          = row.querySelector('input[name*="[room]"]');
    const solutionTypeEl  = row.querySelector('select[name*="[solution_type_id]"]');

    const overview = overviewEl ? overviewEl.value.trim() : '';
    if (! overview) {
        alert('Please write a Phrased Overview first, then click Generate.');
        return;
    }

    btn.disabled    = true;
    btn.textContent = 'Generating…';

    fetch('{{ route("project-packages.room-summary", $package) }}', {
        method:  'POST',
        headers: {
            'Content-Type':  'application/json',
            'X-CSRF-TOKEN':  '{{ csrf_token() }}',
            'Accept':        'application/json',
        },
        body: JSON.stringify({
            room:             roomEl         ? roomEl.value.trim()         : '',
            overview:         overview,
            solution_type_id: solutionTypeEl ? solutionTypeEl.value : '',
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.works_summary && summaryEl) {
            summaryEl.value = data.works_summary;
        } else if (data.error) {
            alert(data.error);
        }
    })
    .catch(() => alert('Generation failed. Please try again.'))
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = '✨ Generate';
    });
}

// ─── AI generate Scope of Works ──────────────────────────────────────────────
function generateScopeOfWorks() {
    const btn   = document.getElementById('btn-gen-scope');
    const field = document.getElementById('scope-of-works-field');

    btn.disabled    = true;
    btn.textContent = 'Generating…';

    fetch('{{ route("project-packages.scope-of-works", $package) }}', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept':       'application/json',
        },
        body: JSON.stringify({}),
    })
    .then(r => r.json())
    .then(data => {
        if (data.scope_of_works) {
            field.value = data.scope_of_works;
        } else if (data.error) {
            alert(data.error);
        }
        // Also populate works_overview if returned (D-11, D-18)
        if (data.works_overview) {
            const overviewField = document.getElementById('works-overview-field');
            if (overviewField) {
                overviewField.value = data.works_overview;
            }
        }
    })
    .catch(() => alert('Generation failed. Please try again.'))
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = '✨ Generate';
    });
}

// ─── AI generate Works Overview only (reuses scope-of-works endpoint) ─────────
// Phase 22.1 D-04: the install-action "Convert to bullets" generator (and the
// textarea it targeted) were removed by Plan 22.1-04. The survey "Planned AV
// Works" drawer now derives bullets from room_overviews per-room summaries.

function generateWorksOverviewFromScope() {
    const btn   = document.getElementById('btn-gen-overview');
    const field = document.getElementById('works-overview-field');
    if (!btn || !field) return;

    const originalText = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = 'Generating&hellip;';

    fetch('{{ route("project-packages.scope-of-works", $package) }}', {
        method:  'POST',
        headers: {
            'Content-Type':  'application/json',
            'X-CSRF-TOKEN':  '{{ csrf_token() }}',
            'Accept':        'application/json',
        },
        body: JSON.stringify({}),
    })
    .then(r => r.json())
    .then(data => {
        if (data.works_overview) {
            field.value = data.works_overview;
        } else if (data.error) {
            alert('Could not generate works overview: ' + data.error);
        }
    })
    .catch(() => alert('Request failed. Please try again.'))
    .finally(() => {
        btn.disabled  = false;
        btn.innerHTML = originalText;
    });
}

// ─── Tab switching ────────────────────────────────────────────────────────────
function switchTab(tabId, btn) {
    document.querySelectorAll('.review-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.review-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
    if (btn) btn.classList.add('active');
}

// ─── Sidebar rail (Tier-1 Screen 03 v1) ───────────────────────────────────────
// Click handler: switch to the target's tab, then scroll to the section.
// The scroll waits one frame so the tab-panel display flip has painted first
// (otherwise scrollIntoView measures a display:none element and no-ops).
window.pkgRailJump = function (event, tab, target) {
    event.preventDefault();

    var btns = document.querySelectorAll('.review-tab-btn');
    var wantBtn = null;
    var wantLabel = tab === 'project-info' ? 'project info' : 'rams info';
    btns.forEach(function (b) {
        if ((b.textContent || '').toLowerCase().indexOf(wantLabel) !== -1) {
            wantBtn = b;
        }
    });
    if (wantBtn) { switchTab(tab, wantBtn); }

    requestAnimationFrame(function () {
        var el = document.getElementById(target);
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
};

// Active-highlight the rail item for the section currently in view.
// Runs after Alpine boots so we don't fight it for the initial layout.
(function () {
    if (!('IntersectionObserver' in window)) return;
    document.addEventListener('DOMContentLoaded', function () {
        var rail = document.querySelector('.pkg-rail');
        if (!rail) return;
        var items = Array.from(rail.querySelectorAll('.pkg-rail-item'));
        if (!items.length) return;
        var targets = items
            .map(function (a) { return document.getElementById(a.getAttribute('data-target')); })
            .filter(Boolean);
        if (!targets.length) return;
        var setActive = function (id) {
            items.forEach(function (a) {
                a.classList.toggle('active', a.getAttribute('data-target') === id);
            });
        };
        var obs = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (e) { return e.isIntersecting; })
                .sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
            if (visible.length) setActive(visible[0].target.id);
        }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
        targets.forEach(function (t) { obs.observe(t); });
    });
})();

// ─── Auto-switch to tab that contains validation errors ───────────────────────
(function () {
    const ramsTabBtn = document.querySelector('.review-tab-btn:last-child');

    @if ($errors->any())
        // Detect which tab owns the errored fields
        const errorKeys  = @json($errors->keys());
        const ramsPrefix = ['programme', 'site_logistics', 'activities', 'hazards', 'ppe', 'access'];
        const hasRamsErr = errorKeys.some(k =>
            ramsPrefix.some(p => k === p || k.startsWith(p + '.') || k.startsWith(p + '['))
        );
        const hasProjErr = errorKeys.some(k =>
            !ramsPrefix.some(p => k === p || k.startsWith(p + '.') || k.startsWith(p + '['))
        );
        // Switch to RAMS tab only when all errors live there (no project errors)
        if (hasRamsErr && !hasProjErr && ramsTabBtn) {
            switchTab('rams-info', ramsTabBtn);
        }
    @elseif (session('error'))
        // Flash error from RAMS generation — the problem is always in RAMS fields
        if (ramsTabBtn) { switchTab('rams-info', ramsTabBtn); }
    @endif
})();

// ─── Ongoing / no fixed end date toggle ──────────────────────────────────────
function toggleOngoing(checkbox) {
    const endDate = document.getElementById('planned-end-date');
    if (!endDate) return;
    if (checkbox.checked) {
        endDate.disabled = true;
        endDate.style.opacity = '.4';
    } else {
        endDate.disabled = false;
        endDate.style.opacity = '1';
    }
}

// ─── Additional engineer rows ─────────────────────────────────────────────────
let engineerCount = {{ count($reviewPayload['programme']['additional_engineers'] ?? []) }};

function addEngineerRow() {
    const tbody = document.getElementById('engineers-tbody');
    const emptyRow = tbody.querySelector('tr[data-empty-engineer-row]');
    if (emptyRow) emptyRow.remove();
    const idx = engineerCount++;
    const tr = document.createElement('tr');
    tr.setAttribute('data-engineer-row', '1');
    tr.innerHTML = `
        <td><input type="text" name="programme[additional_engineers][${idx}]" placeholder="Full name" maxlength="255"></td>
        <td class="col-del"><button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

// ─── Room name sync: rename in overview → update all equipment area fields ────
function syncRoomNameToEquipment(input) {
    const oldName = (input.dataset.originalRoom || '').trim();
    const newName = input.value.trim();

    // Tier-1 Screen 03 v2 — datalist sync runs on every keystroke so newly
    // added rooms + renamed rooms both propagate to the shared area picker
    // suggestion list. Kept separate from the equipment-value rewrite below
    // because a first-time room entry (oldName === '') has no equipment to
    // rename yet — but its suggestion should still appear immediately.
    updateAreaDatalist(oldName, newName);

    if (!oldName || oldName === newName) return;

    // Update every equipment area input that still has the old name
    document.querySelectorAll('input[name*="[area]"]').forEach(areaInput => {
        if (areaInput.value.trim() === oldName) {
            areaInput.value = newName;
        }
    });

    // Update the area header spans inside the hardware category rows
    document.querySelectorAll('tr[data-room-row] span.eq-area-label').forEach(span => {
        if (span.textContent.trim() === oldName) span.textContent = newName;
    });

    // Update the room-gen-qty input name attribute so the qty saves under the new name
    document.querySelectorAll(`input.room-gen-qty`).forEach(qtyInput => {
        const oldAttr = `room_qtys[${oldName}]`;
        if (qtyInput.name === oldAttr) {
            qtyInput.name = `room_qtys[${newName}]`;
        }
    });

    // Store new name as the reference for the next change
    input.dataset.originalRoom = newName;
}

// Tier-1 Screen 03 v2 — keep the shared area <datalist id="__proj_rooms"> in
// sync when a room in section 2 is added or renamed. Every equipment row's
// area <input list="__proj_rooms"> reads its suggestion list from this
// datalist, so an out-of-date datalist immediately reads as "your new room
// doesn't autocomplete" — which is the exact bug the picker was meant to
// close.
function updateAreaDatalist(oldName, newName) {
    var datalist = document.getElementById('__proj_rooms');
    if (!datalist) return;

    var existingOpt = null;
    if (oldName !== '') {
        Array.from(datalist.querySelectorAll('option')).forEach(function (opt) {
            if (opt.value === oldName) existingOpt = opt;
        });
    }

    if (existingOpt) {
        // Rename or removal path.
        if (newName === '') {
            existingOpt.remove();
        } else if (newName !== oldName) {
            existingOpt.value = newName;
        }
        return;
    }

    // New room path — only add when it's not already present.
    if (newName === '') return;
    var alreadyPresent = Array.from(datalist.querySelectorAll('option'))
        .some(function (opt) { return opt.value === newName; });
    if (alreadyPresent) return;

    var freshOpt = document.createElement('option');
    freshOpt.value = newName;
    datalist.appendChild(freshOpt);
}

// ─── Generate survey rooms for a hardware area ────────────────────────────────
async function generateSurveyRooms(area, btn) {
    const row       = btn.closest('tr');
    const input     = row ? row.querySelector('.room-gen-qty')   : null;
    const namesInp  = row ? row.querySelector('.room-gen-names') : null;
    const namesRaw  = (namesInp ? namesInp.value : '').trim();
    // Names list (when given) overrides qty so each room gets a real name.
    const namesList = namesRaw === ''
        ? []
        : Array.from(new Set(namesRaw.split(/[,\n]+/).map(s => s.trim()).filter(s => s.length > 0)));
    const qty       = namesList.length > 0
        ? namesList.length
        : parseInt((input ? input.value : null) || 1, 10);

    if (isNaN(qty) || qty < 1) {
        alert('Please enter a valid room count (1 or more) or a names list.');
        return;
    }

    const previewLabel = namesList.length > 0
        ? `${qty} room(s): ${namesList.join(', ')}`
        : `${qty} room(s)`;
    if (!(await window.appConfirm(
        `This will replace any existing "${area}" rooms in the linked survey with ${previewLabel}. Continue?`,
        { title:'Replace survey rooms?', confirmLabel:'Replace' }
    ))) {
        return;
    }

    // Read current form values for this area from the room-overviews section
    // so the overview/solution type are captured even if the form hasn't been saved yet.
    let currentOverview = '';
    let currentWorksSummary = '';
    let currentSolutionTypeId = '';
    document.querySelectorAll('tr[data-room-overview-row]').forEach(tr => {
        const roomInput = tr.querySelector('input[name*="[room]"]');
        if (roomInput && roomInput.value.trim() === area) {
            const ov  = tr.querySelector('textarea[name*="[overview]"]');
            const ws  = tr.querySelector('textarea[name*="[works_summary]"]');
            const stl = tr.querySelector('select[name*="[solution_type_id]"]');
            if (ov)  currentOverview         = ov.value.trim();
            if (ws)  currentWorksSummary     = ws.value.trim();
            if (stl) currentSolutionTypeId   = stl.value;
        }
    });

    const origText   = btn.textContent;
    btn.disabled     = true;
    btn.textContent  = 'Generating…';

    fetch('{{ route("project-packages.generate-survey-rooms", $package) }}', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept':       'application/json',
        },
        body: JSON.stringify({
            area,
            qty,
            names:                    namesList.join(','),
            current_overview:         currentOverview,
            current_works_summary:    currentWorksSummary,
            current_solution_type_id: currentSolutionTypeId ? parseInt(currentSolutionTypeId) : null,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
            btn.disabled    = false;
            btn.textContent = origText;
        } else {
            btn.textContent              = '✓ ' + data.created + ' room(s) created — reloading…';
            btn.style.background         = '#d1fae5';
            btn.style.borderColor        = '#6ee7b7';
            btn.style.color              = '#065f46';
            // Reload the page so Section 2 and the equipment list both show the
            // updated state from extracted_data. Without a reload the form would
            // overwrite the server changes if the user clicked Save.
            setTimeout(() => { location.reload(); }, 1200);
        }
    })
    .catch(() => {
        alert('Request failed. Please try again.');
        btn.disabled    = false;
        btn.textContent = origText;
    });
}

// ─── Programmer rows ──────────────────────────────────────────────────────────
let programmerCount = {{ count($reviewPayload['programme']['programmers'] ?? []) }};

function addProgrammerRow() {
    const tbody = document.getElementById('programmers-tbody');
    const emptyRow = tbody.querySelector('tr[data-empty-programmer-row]');
    if (emptyRow) emptyRow.remove();
    const idx = programmerCount++;
    const tr = document.createElement('tr');
    tr.setAttribute('data-programmer-row', '1');
    tr.innerHTML = `
        <td><input type="text" name="programme[programmers][${idx}]" placeholder="Full name" maxlength="255"></td>
        <td class="col-del"><button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

// ─── Site vehicle rows ────────────────────────────────────────────────────────
let vehicleCount = {{ count($reviewPayload['programme']['site_vehicles'] ?? []) }};

function addVehicleRow() {
    const tbody = document.getElementById('vehicles-tbody');
    const emptyRow = tbody.querySelector('tr[data-empty-vehicle-row]');
    if (emptyRow) emptyRow.remove();
    const idx = vehicleCount++;
    const tr = document.createElement('tr');
    tr.setAttribute('data-vehicle-row', '1');
    tr.innerHTML = `
        <td><input type="text" name="programme[site_vehicles][${idx}]" placeholder="AB12 CDE - Crew van" maxlength="120"></td>
        <td class="col-del"><button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}
</script>
@endpush

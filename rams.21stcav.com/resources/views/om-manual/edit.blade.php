@extends('layouts.app')

@section('title', 'Review O&M — ' . ($manual->project_name ?? 'O&M Manual'))

@push('styles')
<style>
    /*
     * O&M edit rail — tier-one polish (2026-07-08).
     * Was: SCC v2 dark-teal + gold rail chrome with warm-cream fallbacks
     * that never routed through the palette retune of task 2. Now every
     * colour flows through the semantic tokens in :root — indigo active
     * rail with an indigo left-marker, hairline dividers, semantic
     * status pills. Same layout, tighter tokens.
     */

    .om-edit-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        color: var(--ink-900);
        letter-spacing: -.02em;
        line-height: 1.2;
    }
    .om-edit-title em {
        font-style: normal;
        font-weight: 500;
        color: var(--text-muted);
    }
    .om-edit-eyebrow {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .om-layout {
        display: grid;
        grid-template-columns: 240px 1fr;
        gap: 24px;
        max-width: 1200px;
        margin: 0 auto;
        align-items: start;
    }
    @media (max-width: 900px) {
        .om-layout { grid-template-columns: 1fr; }
        .om-rail { position: static !important; max-height: none !important; }
    }
    .om-rail {
        position: sticky;
        top: 5rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 8px;
        max-height: calc(100vh - 6rem);
        overflow-y: auto;
    }
    .om-rail-h {
        font-size: 10px;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 600;
        padding: 10px 10px 4px;
    }
    .om-rail-h.first { padding-top: 4px; }

    .om-rail-item {
        position: relative;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 12px;
        color: var(--body);
        text-decoration: none;
        font-weight: 500;
        line-height: 1.35;
        transition: background 120ms, color 120ms;
    }
    .om-rail-item:hover {
        background: color-mix(in oklab, var(--teal-100) 50%, transparent);
        color: var(--ink-900);
    }
    .om-rail-item.active {
        background: var(--teal-100);
        color: var(--teal-700);
        font-weight: 600;
    }
    /* 2px indigo left-rail marker on active — same signature as the app
       sidebar so the pattern is consistent between the global nav and
       every in-page section nav. */
    .om-rail-item.active::before {
        content: "";
        position: absolute;
        left: -8px;
        top: 4px;
        bottom: 4px;
        width: 2px;
        background: var(--teal-700);
        border-radius: 0 2px 2px 0;
    }

    .om-rail-item .om-rail-num {
        font-family: var(--font-mono);
        font-size: 10px;
        color: var(--text-muted);
        font-weight: 600;
        min-width: 20px;
        font-variant-numeric: tabular-nums;
    }
    .om-rail-item.active .om-rail-num { color: var(--teal-700); }

    .om-rail-item .om-rail-status {
        margin-left: auto;
        font-size: 9px;
        padding: 1px 6px;
        border-radius: 999px;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        font-family: inherit;
    }
    .om-rail-status.done   { background: var(--success-light); color: var(--success); }
    .om-rail-status.tbc    { background: var(--warning-light); color: #92400E; }
    .om-rail-status.empty  { background: var(--surface-soft);  color: var(--text-muted);
                             border: 1px solid var(--border); }
    .om-rail-status.ai     { background: #F5F3FF;              color: #7C3AED; }
    .om-rail-status.ext    { background: var(--surface-soft);  color: var(--text-muted);
                             border: 1px solid var(--border); }
    .om-rail-item.active .om-rail-status {
        background: color-mix(in oklab, var(--teal-700) 12%, transparent);
        color: var(--teal-700);
        border-color: transparent;
    }

    .om-rail-item .om-rail-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .om-section {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 24px 28px;
        margin-bottom: 16px;
        scroll-margin-top: 5rem;
    }
    .om-section h2 {
        font-size: 17px;
        font-weight: 700;
        margin: 0 0 4px;
        color: var(--ink-900);
        letter-spacing: -.015em;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .om-section h2 .num {
        font-family: var(--font-mono);
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 600;
        margin-right: 4px;
        font-variant-numeric: tabular-nums;
    }
    .om-badge {
        font-size: 10px;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 999px;
        font-weight: 600;
        border: 1px solid transparent;
    }
    .om-badge.ai     { background: #F5F3FF;              color: #7C3AED;
                       border-color: color-mix(in oklab, #8B5CF6 25%, transparent); }
    .om-badge.done   { background: var(--success-light); color: var(--success);
                       border-color: color-mix(in oklab, var(--success) 30%, transparent); }
    .om-badge.tbc    { background: var(--warning-light); color: #92400E;
                       border-color: color-mix(in oklab, var(--warning) 30%, transparent); }
    .om-badge.edited { background: var(--surface-soft);  color: var(--text-muted);
                       border-color: var(--border); }
    .om-badge.gen    { background: #F5F3FF;              color: #7C3AED;
                       border-color: color-mix(in oklab, #8B5CF6 25%, transparent); }
    .om-badge.ext    { background: var(--surface-soft);  color: var(--text-muted);
                       border-color: var(--border); }
    .om-section p.desc {
        color: var(--text-muted);
        font-size: 13px;
        line-height: 1.5;
        margin: 0 0 16px;
    }

    .om-meta-strip {
        display: flex;
        gap: 20px;
        padding: 12px 16px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        margin-bottom: 16px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    .om-meta-strip strong {
        color: var(--muted);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .06em;
        font-size: 10px;
        margin-right: 4px;
    }

    .om-room {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 20px 24px;
        margin-bottom: 16px;
        scroll-margin-top: 5rem;
    }
    .om-room-h {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }
    .om-room-h .om-room-num {
        width: 28px; height: 28px;
        background: var(--teal-100);
        color: var(--teal-700);
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-family: var(--font-mono);
        font-weight: 700;
        font-size: 12px;
        font-variant-numeric: tabular-nums;
    }
    .om-room-h .om-room-title {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
        flex: 1;
        letter-spacing: -.01em;
        color: var(--ink-900);
    }
    .om-room-fields {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: .5rem .75rem;
        margin-bottom: 1rem;
    }
    .om-room-narr { margin-bottom: 1rem; }
    .om-room-narr label {
        display: block;
        font-size: .75rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: .3rem;
    }
    .om-room-narr .om-room-narr-note {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: .3rem;
    }

    .om-eq-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
    .om-eq-tbl th {
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--text-muted);
        font-weight: 600;
        padding: 8px 10px;
        background: var(--surface-soft);
        border-bottom: 1px solid var(--border);
    }
    .om-eq-tbl td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--rule);
        vertical-align: middle;
    }
    .om-eq-tbl tbody tr:last-child td { border-bottom: none; }
    .om-eq-tbl input, .om-eq-tbl textarea {
        width: 100%;
        border: 1px solid transparent;
        background: transparent;
        padding: 4px 6px;
        border-radius: 4px;
        font-size: 13px;
        font-family: inherit;
        color: var(--ink-900);
    }
    .om-eq-tbl input:focus, .om-eq-tbl textarea:focus {
        background: var(--surface);
        border-color: var(--teal-500);
        outline: none;
        box-shadow: var(--shadow-focus);
    }
    .om-eq-tbl input.qty {
        width: 56px;
        text-align: center;
        font-family: var(--font-mono);
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }
    .om-eq-tbl input.part {
        font-family: var(--font-mono);
        font-size: 12px;
    }
    .om-eq-tbl .del {
        background: transparent;
        border: 0;
        color: var(--text-muted);
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 14px;
        transition: color 120ms, background 120ms;
    }
    .om-eq-tbl .del:hover { color: var(--danger); background: var(--danger-light); }

    /* Undo-toast strip — retuned from warm cream to amber tokens so the
       "row deleted, click to restore" callout reads as a soft warning
       rather than SCC v2 mustard. */
    .om-undo-strip {
        margin-top: 8px;
        display: grid;
        gap: 4px;
    }
    .om-undo-row {
        display: grid;
        grid-template-columns: 22px 1fr auto auto;
        gap: 10px;
        align-items: center;
        background: var(--warning-light);
        border: 1px solid color-mix(in oklab, var(--warning) 30%, transparent);
        border-radius: 6px;
        padding: 8px 12px;
        font-size: 12px;
        color: #78350F;
    }
    .om-undo-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: color-mix(in oklab, var(--warning) 18%, transparent);
        color: #78350F;
        font-weight: 700;
        font-size: 13px;
    }
    .om-undo-body strong { color: #78350F; font-weight: 600; }
    .om-undo-hint {
        opacity: .70;
        font-size: 11px;
        margin-left: 4px;
    }
    .om-undo-btn {
        background: var(--warning);
        color: #fff;
        border: 0;
        padding: 4px 10px;
        border-radius: 4px;
        font-family: inherit;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        letter-spacing: -.005em;
        transition: background 120ms;
    }
    .om-undo-btn:hover { background: #B45309; }
    .om-undo-x {
        background: transparent;
        border: 0;
        color: #78350F;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        padding: 3px 6px;
        border-radius: 4px;
        opacity: .55;
        transition: opacity 120ms, background 120ms;
    }
    .om-undo-x:hover { opacity: 1; background: color-mix(in oklab, var(--warning) 12%, transparent); }

    .om-repeater-add, .om-eq-add {
        background: transparent;
        border: 1px dashed var(--border-strong);
        padding: 6px 12px;
        border-radius: 6px;
        font-family: inherit;
        font-size: 12px;
        color: var(--text-muted);
        cursor: pointer;
        margin-top: 8px;
        font-weight: 500;
        transition: border-color 120ms, color 120ms, background 120ms;
    }
    .om-repeater-add:hover, .om-eq-add:hover {
        border-color: var(--teal-500);
        color: var(--teal-700);
        background: color-mix(in oklab, var(--teal-100) 40%, transparent);
    }

    .om-info-card {
        display: grid;
        grid-template-columns: 32px 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 12px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        background: var(--surface-soft);
        font-size: 13px;
        margin-top: 8px;
    }
    .om-info-card .kind {
        width: 32px; height: 32px;
        border-radius: 6px;
        background: var(--surface);
        display: flex; align-items: center; justify-content: center;
        font-size: 15px;
        border: 1px solid var(--border);
    }
    .om-info-card .body { min-width: 0; }
    .om-info-card .body .t { font-weight: 600; color: var(--text); }
    .om-info-card .body .s { font-size: .78rem; color: var(--text-muted); line-height: 1.4; }

    .om-save-bar {
        position: sticky; bottom: 0;
        background: linear-gradient(180deg, transparent 0%, var(--surface) 30%);
        padding: 1rem 0 0; margin-top: 1rem;
        display: flex; gap: .5rem; align-items: center;
        border-top: 1px solid var(--border);
    }
    .om-save-info { font-size: .78rem; color: var(--text-muted); display: flex; align-items: center; gap: .4rem; }
    .om-save-info .dot { width: 6px; height: 6px; border-radius: 50%; background: #b57e24; display: inline-block; }

    /* Tier-1 O&M v2b — Auto-generated content accordion. Groups the 7 pure
       AI-generated sections behind one collapsible so the page stops
       spending vertical real estate on content the user can only read. */
    .om-autogen { padding: 0; }
    .om-autogen summary.om-autogen-summary {
        cursor: pointer;
        list-style: none;
        display: grid;
        grid-template-columns: 20px 1fr;
        gap: 1rem;
        padding: 1.4rem 1.75rem;
        border-radius: var(--radius-sm);
    }
    .om-autogen summary.om-autogen-summary::-webkit-details-marker { display: none; }
    .om-autogen[open] summary.om-autogen-summary {
        border-bottom: 1px solid var(--border);
    }
    .om-autogen-caret {
        color: var(--text-muted);
        font-size: 1rem;
        transition: transform .15s;
        margin-top: .3rem;
    }
    .om-autogen[open] .om-autogen-caret { transform: rotate(90deg); }
    .om-autogen-titles h2 { font-size: 1.15rem; }
    .om-autogen-count {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .72rem;
        color: var(--text-muted);
        font-weight: 500;
        letter-spacing: -.005em;
        margin-left: .35rem;
    }
    .om-autogen-body {
        padding: 1.25rem 1.75rem;
        display: grid;
        gap: 1rem;
    }
    .om-autogen-item {
        padding: .9rem 1.1rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--bg-muted, #FBF8EF);
    }
    .om-autogen-item h3 {
        font-size: .95rem;
        font-weight: 700;
        margin: 0 0 .3rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .om-autogen-item h3 .num {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .72rem;
        color: var(--text-muted);
        font-weight: 700;
        min-width: 2rem;
    }
    .om-autogen-item p {
        font-size: .82rem;
        color: var(--text-muted);
        line-height: 1.5;
        margin: 0 0 .5rem;
    }

    details.om-advanced summary {
        cursor: pointer; font-weight: 600; color: var(--text-muted);
        padding: .5rem 0; list-style: none;
        display: inline-flex; align-items: center; gap: .4rem; font-size: .85rem;
    }
    details.om-advanced summary::-webkit-details-marker { display: none; }
    details.om-advanced summary::before {
        content: "▸"; display: inline-block; transition: transform .15s; font-size: .7rem;
    }
    details.om-advanced[open] summary::before { transform: rotate(90deg); }
    .om-advanced-warning {
        background: #F4E7CE; border: 1px solid #E8D5B0; border-radius: 4px;
        padding: .55rem .75rem; font-size: .78rem; color: #7E5717; margin: .75rem 0;
    }

    /* Row for distribution / revision / attendees repeaters */
    .om-row-tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .om-row-tbl th {
        text-align: left; font-size: .65rem; text-transform: uppercase; letter-spacing: .08em;
        color: var(--text-muted); font-weight: 700; padding: .35rem .5rem;
        background: var(--bg-muted, #FBF8EF); border-bottom: 1px solid var(--border);
    }
    .om-row-tbl td { padding: .35rem .5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .om-row-tbl input, .om-row-tbl textarea {
        width: 100%; border: 1px solid transparent; background: transparent;
        padding: .3rem .4rem; border-radius: 3px; font-size: .82rem;
        font-family: inherit; color: var(--text);
    }
    .om-row-tbl input:focus, .om-row-tbl textarea:focus {
        background: white; border-color: var(--border); outline: none;
    }
    .om-row-tbl .del {
        background: transparent; border: 0; color: var(--text-muted);
        cursor: pointer; padding: .3rem .5rem; border-radius: 3px; font-size: .9rem;
    }
    .om-row-tbl .del:hover { color: #c0392b; background: #F1D9D2; }

    .om-composite-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem; }
</style>
@endpush

@section('content')
<x-edit-action-bar :form-id="'om-manual-edit-form'" :cancel-url="route('om-manuals.index')">
    <x-slot:title>{{ $manual->project_name ?? 'O&M Manual' }}</x-slot:title>
</x-edit-action-bar>

{{-- Batch 11 UX-09 — surface a "regenerate" nudge when the source
     ProjectPackage has been updated after this manual snapshot. Renders
     nothing when the manual is fresh. --}}
<x-stale-banner :doc="$manual"
                label="O&amp;M manual"
                :regenUrl="route('om-manuals.retry-generation', $manual)" />

@php
    $data       = is_array($manual->extracted_data) ? $manual->extracted_data : [];
    $project    = is_array($data['project'] ?? null) ? $data['project'] : [];
    $projName   = $data['project_name']   ?? $project['name']   ?? $manual->project_name;
    $projRef    = $data['project_ref']    ?? $project['ref']    ?? $manual->project_ref;
    $projClient = $data['client_name']    ?? $project['client'] ?? $manual->client_name;
    $projSite   = $data['site_address']   ?? $project['site']   ?? $manual->site_address;
    $handover   = (string) ($data['handover_date'] ?? '');
    $notes      = (string) ($data['notes'] ?? '');
    $scope      = (string) ($data['scope_of_works'] ?? '');
    $rooms      = is_array($data['rooms'] ?? null) ? array_values($data['rooms']) : [];

    $distribution = is_array($data['distribution_list'] ?? null) ? array_values($data['distribution_list']) : [];
    $revisions    = is_array($data['revision_history']  ?? null) ? array_values($data['revision_history'])  : [];

    $handoverBlock = is_array($data['training_handover'] ?? null) ? $data['training_handover'] : [];
    $hoDate        = (string) ($handoverBlock['date']        ?? $handover);
    $hoCompetency  = (string) ($handoverBlock['competency']  ?? '');
    $hoAttendees   = is_array($handoverBlock['attendees'] ?? null) ? array_values($handoverBlock['attendees']) : [];

    $docControl   = is_array($data['document_control'] ?? null) ? $data['document_control'] : [];
    $dcRevision   = (string) ($docControl['revision']    ?? '1.0');
    $dcStatus     = (string) ($docControl['status']      ?? 'Draft');
    $dcPreparedBy = (string) ($docControl['prepared_by'] ?? auth()->user()->name ?? '');

    $mfgSupport   = is_array($data['manufacturer_support_overrides'] ?? null) ? array_values($data['manufacturer_support_overrides']) : [];
    $escalation   = is_array($data['service_escalation'] ?? null) ? $data['service_escalation'] : [];
    $escContact   = (string) ($escalation['contact_name'] ?? '21st Century AV Support');
    $escPhone     = (string) ($escalation['phone']        ?? '');
    $escEmail     = (string) ($escalation['email']        ?? '');
    $escHours     = (string) ($escalation['hours']        ?? 'Mon–Fri 09:00–17:30');
    $escMatrix    = (string) ($escalation['matrix']       ?? '');

    // ── Status helpers ──────────────────────────────────────────────────────
    $sectionStatus = function (string $value): array {
        $t = trim($value);
        if ($t === '')                       return ['label' => '○',   'class' => 'empty'];
        if (str_contains($t, '[TBC]'))       return ['label' => 'TBC', 'class' => 'tbc'];
        return ['label' => '✓', 'class' => 'done'];
    };
    $countStatus = function (int $n): array {
        return $n > 0
            ? ['label' => (string) $n, 'class' => 'done']
            : ['label' => '○',        'class' => 'empty'];
    };

    $sProject = ($projName && $projClient && $projSite) ? ['label' => '✓', 'class' => 'done'] : ['label' => '○', 'class' => 'empty'];
    $sDist    = $countStatus(count($distribution));
    $sRev     = $countStatus(count($revisions));
    $sScope   = $sectionStatus($scope);
    $sNotes   = $sectionStatus($notes);
    $sHo      = $hoDate !== '' ? $sectionStatus($hoDate) : ['label' => '○', 'class' => 'empty'];
    $sMfg     = $countStatus(count($mfgSupport));
    $sEsc     = ($escPhone || $escEmail) ? ['label' => '✓', 'class' => 'done'] : ['label' => 'AI', 'class' => 'ai'];
    $sDoc     = ($dcRevision && $dcStatus) ? ['label' => '✓', 'class' => 'done'] : ['label' => '○', 'class' => 'empty'];
@endphp

<div class="om-layout" style="padding: 1rem 0 4rem;">

    {{-- ══════════════════════════════════════════════════════════════════════
         SIDEBAR RAIL — 15-section PDF-parity structure
         ══════════════════════════════════════════════════════════════════════ --}}
    <aside class="om-rail" aria-label="O&M sections">

        <div class="om-rail-h first">Front matter</div>
        <a href="#s-project" class="om-rail-item">
            <span class="om-rail-name">Project details</span>
            <span class="om-rail-status {{ $sProject['class'] }}">{{ $sProject['label'] }}</span>
        </a>
        <a href="#s-distribution" class="om-rail-item">
            <span class="om-rail-name">Distribution list</span>
            <span class="om-rail-status {{ $sDist['class'] }}">{{ $sDist['label'] }}</span>
        </a>
        <a href="#s-revision" class="om-rail-item">
            <span class="om-rail-name">Revision history</span>
            <span class="om-rail-status {{ $sRev['class'] }}">{{ $sRev['label'] }}</span>
        </a>

        <div class="om-rail-h">Body sections</div>
        <a href="#s-scope" class="om-rail-item">
            <span class="om-rail-num">§1</span>
            <span class="om-rail-name">Executive summary</span>
            <span class="om-rail-status {{ $sScope['class'] }}">{{ $sScope['label'] }}</span>
        </a>
        {{-- v2b — §2, §5, §6, §7, §8, §13, §14 fold into one rail entry. --}}
        <a href="#s-asset-register" class="om-rail-item">
            <span class="om-rail-num">§3</span>
            <span class="om-rail-name">Asset register</span>
            <span class="om-rail-status ext">↗</span>
        </a>
        <a href="#s-drawings" class="om-rail-item">
            <span class="om-rail-num">§4</span>
            <span class="om-rail-name">Drawings register</span>
            <span class="om-rail-status ext">↗</span>
        </a>
        <a href="#s-network" class="om-rail-item">
            <span class="om-rail-num">§9</span>
            <span class="om-rail-name">Network &amp; IP</span>
            <span class="om-rail-status ext">↗</span>
        </a>
        <a href="#s-mfg-support" class="om-rail-item">
            <span class="om-rail-num">§10</span>
            <span class="om-rail-name">Manufacturer support</span>
            <span class="om-rail-status {{ $sMfg['class'] }}">{{ $sMfg['label'] }}</span>
        </a>
        <a href="#s-escalation" class="om-rail-item">
            <span class="om-rail-num">§11</span>
            <span class="om-rail-name">Service &amp; escalation</span>
            <span class="om-rail-status {{ $sEsc['class'] }}">{{ $sEsc['label'] }}</span>
        </a>
        <a href="#s-handover" class="om-rail-item">
            <span class="om-rail-num">§12</span>
            <span class="om-rail-name">Training &amp; handover</span>
            <span class="om-rail-status {{ $sHo['class'] }}">{{ $sHo['label'] }}</span>
        </a>
        <a href="#s-doc-control" class="om-rail-item">
            <span class="om-rail-num">§15</span>
            <span class="om-rail-name">Document control</span>
            <span class="om-rail-status {{ $sDoc['class'] }}">{{ $sDoc['label'] }}</span>
        </a>
        <a href="#s-auto-generated" class="om-rail-item"
           onclick="var d = document.querySelector('.om-autogen'); if (d) d.open = true;">
            <span class="om-rail-name">Auto-generated (7)</span>
            <span class="om-rail-status ai">AI</span>
        </a>

        <div class="om-rail-h">Rooms &amp; equipment</div>
        @forelse ($rooms as $i => $room)
            @php
                $rname = trim((string) ($room['name'] ?? 'Room ' . ($i + 1)));
                $rnarr = trim((string) ($room['narrative'] ?? $room['description'] ?? ''));
                $rst   = $sectionStatus($rnarr);
                $eqN   = is_array($room['equipment'] ?? null) ? count($room['equipment']) : 0;
            @endphp
            <a href="#s-room-{{ $i }}" class="om-rail-item">
                <span class="om-rail-name">{{ $rname !== '' ? $rname : 'Room ' . ($i + 1) }}</span>
                <span class="om-rail-status {{ $rst['class'] === 'done' ? 'done' : $rst['class'] }}">
                    {{ $rst['class'] === 'done' ? $eqN : $rst['label'] }}
                </span>
            </a>
        @empty
            <div style="padding: .5rem .55rem; font-size: .72rem; color: var(--text-muted); font-style: italic;">
                No rooms yet
            </div>
        @endforelse

        <div class="om-rail-h">Extras</div>
        <a href="#s-notes" class="om-rail-item">
            <span class="om-rail-name">Special notes</span>
            <span class="om-rail-status {{ $sNotes['class'] }}">{{ $sNotes['label'] }}</span>
        </a>
        <a href="#s-advanced" class="om-rail-item">
            <span class="om-rail-name">Advanced (JSON)</span>
            <span class="om-rail-status empty">⋯</span>
        </a>
    </aside>

    {{-- ══════════════════════════════════════════════════════════════════════
         MAIN COLUMN
         ══════════════════════════════════════════════════════════════════════ --}}
    <div>
        <div style="margin-bottom: 1rem;">
            <div class="om-edit-eyebrow">Operations &amp; Maintenance Manual</div>
            <h1 class="om-edit-title">
                Review &amp; edit
                @if ($manual->project_name)<em>— {{ $manual->project_name }}</em>@endif
            </h1>
        </div>

        <div style="display: flex; gap: .5rem; margin-bottom: 1rem; flex-wrap: wrap;">
            @if ($manual->project_id)
                <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="btn btn-outline btn-sm">📋 Asset data</a>
            @endif
            <a href="{{ route('documents.revisions.view', ['type' => 'om', 'id' => $manual->id]) }}" class="btn btn-outline btn-sm">↻ History</a>
            <x-document-edit-drawer
                type="om" :id="$manual->id" label="O&M Manual"
                :visible="in_array($manual->status, [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])" />
            <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success" style="margin-bottom: 1rem;">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error" style="margin-bottom: 1rem;">{{ session('error') }}</div>
        @endif

        <div class="om-meta-strip">
            <div><strong>Status</strong><span class="badge {{ $manual->statusBadgeClass() }}">{{ $manual->statusLabel() }}</span></div>
            <div><strong>Client</strong>{{ $manual->client_name ?? '—' }}</div>
            <div><strong>Site</strong>{{ $manual->site_address ?? '—' }}</div>
            @if ($manual->project_ref)
                <div><strong>Ref</strong>{{ $manual->project_ref }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('om-manuals.update', $manual) }}" id="om-manual-edit-form">
            @csrf
            @method('PATCH')

            {{-- ═══ FRONT MATTER ═══════════════════════════════════════════ --}}

            <section class="om-section" id="s-project">
                <h2>Project details <span class="om-badge {{ $sProject['class'] }}">{{ $sProject['class'] === 'done' ? 'Complete' : 'Needs data' }}</span></h2>
                <p class="desc">Renders on the cover sheet + page 1 of the PDF. Auto-populated from the linked project.</p>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="om_project_name">Project name</label>
                        <input id="om_project_name" name="project_name" type="text" class="form-control" value="{{ old('project_name', $projName) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_project_ref">Project ref</label>
                        <input id="om_project_ref" name="project_ref" type="text" class="form-control" value="{{ old('project_ref', $projRef) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_client_name">Client</label>
                        <input id="om_client_name" name="client_name" type="text" class="form-control" value="{{ old('client_name', $projClient) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_site_address">Site address</label>
                        <input id="om_site_address" name="site_address" type="text" class="form-control" value="{{ old('site_address', $projSite) }}" />
                    </div>
                </div>
            </section>

            <section class="om-section" id="s-distribution">
                <h2>Distribution list <span class="om-badge {{ $sDist['class'] }}">{{ count($distribution) }} {{ Str::plural('recipient', count($distribution)) }}</span></h2>
                <p class="desc">Who receives a copy of the O&amp;M on handover. Renders on the front-matter distribution page.</p>
                <div x-data="{ items: {{ json_encode(array_map(fn($r) => [
                            'name'  => (string) ($r['name']  ?? ''),
                            'role'  => (string) ($r['role']  ?? ''),
                            'email' => (string) ($r['email'] ?? ''),
                        ], $distribution)) }} }">
                    <table class="om-row-tbl">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Email</th>
                                <th style="width: 2.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr>
                                    <td><input type="text" data-optional :name="`distribution_list[${idx}][name]`" x-model="item.name" placeholder="e.g. Jane Smith" /></td>
                                    <td><input type="text" data-optional :name="`distribution_list[${idx}][role]`" x-model="item.role" placeholder="e.g. Facilities Manager" /></td>
                                    <td><input type="email" data-optional :name="`distribution_list[${idx}][email]`" x-model="item.email" placeholder="jane@client.com" /></td>
                                    <td><button type="button" class="del" @click="items.splice(idx, 1)" aria-label="Remove">×</button></td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0">
                                <td colspan="4" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">
                                    No recipients yet — add the client contact + your PM.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="om-repeater-add" @click="items.push({ name: '', role: '', email: '' })">+ Add recipient</button>
                </div>
            </section>

            <section class="om-section" id="s-revision">
                <h2>Revision history <span class="om-badge {{ $sRev['class'] }}">{{ count($revisions) }} {{ Str::plural('entry', count($revisions)) }}</span></h2>
                <p class="desc">Chronological log of who changed what. Renders on the revision-history page of the PDF.</p>
                <div x-data="{ items: {{ json_encode(array_map(fn($r) => [
                            'date'    => (string) ($r['date']    ?? ''),
                            'rev'     => (string) ($r['rev']     ?? ''),
                            'author'  => (string) ($r['author']  ?? ''),
                            'changes' => (string) ($r['changes'] ?? ''),
                        ], $revisions)) }} }">
                    <table class="om-row-tbl">
                        <thead>
                            <tr>
                                <th style="width: 7rem;">Date</th>
                                <th style="width: 5rem;">Rev</th>
                                <th style="width: 9rem;">Author</th>
                                <th>Changes</th>
                                <th style="width: 2.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr>
                                    <td><input type="text" data-optional :name="`revision_history[${idx}][date]`" x-model="item.date" placeholder="15 Aug 2026" /></td>
                                    <td><input type="text" data-optional :name="`revision_history[${idx}][rev]`" x-model="item.rev" placeholder="1.0" /></td>
                                    <td><input type="text" data-optional :name="`revision_history[${idx}][author]`" x-model="item.author" placeholder="Sonny Tanda" /></td>
                                    <td><input type="text" data-optional :name="`revision_history[${idx}][changes]`" x-model="item.changes" placeholder="Initial release" /></td>
                                    <td><button type="button" class="del" @click="items.splice(idx, 1)" aria-label="Remove">×</button></td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0">
                                <td colspan="5" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">
                                    No revisions yet — first one is usually "1.0 · Initial release".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="om-repeater-add" @click="items.push({ date: '', rev: '', author: '', changes: '' })">+ Add revision</button>
                </div>
            </section>

            {{-- ═══ SECTION 1 ═══════════════════════════════════════════════ --}}

            <section class="om-section" id="s-scope">
                <h2><span class="num">§1</span>Executive summary <span class="om-badge ai">AI drafted</span>@if (str_contains($scope, '[TBC]'))<span class="om-badge tbc">[TBC]</span>@endif</h2>
                <p class="desc">Renders on page 3 of the client-facing PDF. Written by AI on generation, edit here to override.</p>
                <textarea name="scope_of_works" id="om_scope" rows="12" data-optional
                          class="form-control" style="font-size: .88rem; line-height: 1.55; width: 100%;"
                          placeholder="The works detailed within this document relate to project reference…">{{ old('scope_of_works', $scope) }}</textarea>
            </section>

            {{-- ═══════════════════════════════════════════════════════════
                 Tier-1 O&M v2b — auto-generated sections collapsed together.

                 §2, §5, §6, §7, §8, §13, §14 are all pure AI-generated at
                 regenerate-time. Nothing on this page edits them; each was
                 shipped as a standalone card with a heading, a description
                 and (in some cases) an info panel — combined ~1,000px of
                 vertical scroll for content the user can only read, never
                 edit here.

                 Consolidated into one <details> block. Open the accordion
                 to see the previews per section; keep it closed to shave
                 the height of the page dramatically.

                 The rail now has ONE entry (Auto-generated sections) that
                 anchors to the accordion header — cleaner scan, one
                 discovery point.
                 ═══════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-auto-generated" style="padding: 0;">
                <details class="om-autogen">
                    <summary class="om-autogen-summary">
                        <span class="om-autogen-caret">▸</span>
                        <div class="om-autogen-titles">
                            <h2 style="display: inline-flex; align-items: center; gap: .6rem; margin: 0;">
                                Auto-generated content
                                <span class="om-badge gen">Regen to refresh</span>
                                <span class="om-autogen-count">§2 · §5 · §6 · §7 · §8 · §13 · §14</span>
                            </h2>
                            <p class="desc" style="margin: .3rem 0 0;">
                                Seven sections drafted automatically at regenerate time —
                                system architecture, user guides, config &amp; backups,
                                maintenance, fault finding, testing, and glossary.
                                Click to preview what each contains.
                            </p>
                        </div>
                    </summary>

                    <div class="om-autogen-body">
                        <div class="om-autogen-item">
                            <h3><span class="num">§2</span> System architecture &amp; signal flow</h3>
                            <p>Signal-flow diagrams + per-room block diagrams from the port-catalog schematic engine.</p>
                            @if ($manual->project_id)
                                <a href="{{ route('projects.show', $manual->project_id) }}" class="btn btn-outline btn-sm">Open project workspace ↗</a>
                            @endif
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§5</span> System operation — user guides</h3>
                            <p>Per-room quick-start guides drafted from each room's narrative + equipment context. Edit the room narratives below to shape what appears here.</p>
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§6</span> System configuration &amp; backups</h3>
                            <p>Configuration files, backup locations, restore procedures. Drafted from the linked project's device data.</p>
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§7</span> Routine maintenance schedule</h3>
                            <p>Recommended PPM intervals per device category. AI drafts from a maintenance-interval catalogue keyed on category + manufacturer.</p>
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§8</span> Fault finding guide</h3>
                            <p>Common issues + step-by-step resolutions per subsystem (audio, video, control, network). AI drafts from a standard fault-tree template.</p>
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§13</span> Testing &amp; acceptance</h3>
                            <p>Commissioning tests, acceptance sign-off criteria. Drafted from a standard AV commissioning checklist.</p>
                        </div>
                        <div class="om-autogen-item">
                            <h3><span class="num">§14</span> Glossary</h3>
                            <p>AV acronyms &amp; terms specific to this system. Auto-derived from equipment categories.</p>
                        </div>
                    </div>
                </details>
            </section>

            {{-- ═══ SECTION 3 · Asset register (opens elsewhere) ══════════ --}}
            <section class="om-section" id="s-asset-register">
                <h2><span class="num">§3</span>Asset register <span class="om-badge ext">Edits elsewhere</span></h2>
                <p class="desc">Per-device serial / IP / VLAN / port / firmware / asset-tag entries. Each device from the linked project's <span class="ref">devices</span> table appears here in the PDF.</p>
                @if ($manual->project_id)
                    <div class="om-info-card">
                        <div class="kind">📋</div>
                        <div class="body">
                            <div class="t">Asset data lives on the per-device editor</div>
                            <div class="s">Serial numbers, IP addresses, VLANs, ports, MAC and firmware are all edited on the Asset Data page — one row per device.</div>
                        </div>
                        <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="btn btn-teal btn-sm">Open asset editor</a>
                    </div>
                @else
                    <div class="om-info-card">
                        <div class="kind">⚠</div>
                        <div class="body">
                            <div class="t">Legacy manual — not linked to a project</div>
                            <div class="s">Asset data can only be edited on project-linked manuals. Use raw JSON below if needed.</div>
                        </div>
                    </div>
                @endif
            </section>

            {{-- ═══ SECTION 4 · Drawings register (opens elsewhere) ════════ --}}
            <section class="om-section" id="s-drawings">
                <h2><span class="num">§4</span>Drawings register <span class="om-badge ext">Edits elsewhere</span></h2>
                <p class="desc">Every drawing linked to the project appears in the register + is embedded into Appendix A. Add or remove drawings on the drawings page.</p>
                @if ($manual->project_id)
                    <div class="om-info-card">
                        <div class="kind">📐</div>
                        <div class="body">
                            <div class="t">Drawings live on the project drawings page</div>
                            <div class="s">Schematics, floor plans, rack elevations, cable schedules. Add / remove there, then regenerate this O&amp;M to include them.</div>
                        </div>
                        <a href="{{ route('projects.show', $manual->project_id) }}#drawings" class="btn btn-teal btn-sm">Open drawings</a>
                    </div>
                @else
                    <div class="om-info-card">
                        <div class="kind">⚠</div>
                        <div class="body">
                            <div class="t">Legacy manual — not linked to a project</div>
                            <div class="s">Drawings can only be attached on project-linked manuals.</div>
                        </div>
                    </div>
                @endif
            </section>

            {{-- §5, §6, §7, §8 all folded into the "Auto-generated content"
                 accordion above (Tier-1 O&M v2b). Individual sections were
                 pure AI-generated placeholders with no editable content —
                 collapsing them together removed ~600px of empty scroll. --}}

            {{-- ═══ SECTION 9 · Network & IP (opens elsewhere) ══════════════ --}}
            <section class="om-section" id="s-network">
                <h2><span class="num">§9</span>Network &amp; IP configuration <span class="om-badge ext">Edits elsewhere</span></h2>
                <p class="desc">IP addresses, VLANs, port assignments, network security recommendations. Sourced from the same device data as the asset register.</p>
                @if ($manual->project_id)
                    <div class="om-info-card">
                        <div class="kind">🌐</div>
                        <div class="body">
                            <div class="t">Edit IP / VLAN / port on the asset editor</div>
                            <div class="s">Same page as §3 asset register — every device's network fields are the source of this section.</div>
                        </div>
                        <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="btn btn-outline btn-sm">Open asset editor</a>
                    </div>
                @endif
            </section>

            {{-- ═══ SECTION 10 · Manufacturer support (overrideable) ═══════ --}}
            <section class="om-section" id="s-mfg-support">
                <h2><span class="num">§10</span>Manufacturer support &amp; warranty <span class="om-badge {{ $sMfg['class'] }}">{{ count($mfgSupport) }} {{ Str::plural('override', count($mfgSupport)) }}</span></h2>
                <p class="desc">Per-brand support contact + warranty terms. AI resolves each detected brand automatically; overrides here take priority on next regen.</p>
                <div x-data="{ items: {{ json_encode(array_map(fn($m) => [
                            'brand'    => (string) ($m['brand']    ?? ''),
                            'phone'    => (string) ($m['phone']    ?? $m['uk_phone']       ?? ''),
                            'email'    => (string) ($m['email']    ?? $m['support_email'] ?? ''),
                            'portal'   => (string) ($m['portal']   ?? $m['support_portal']?? $m['support_url'] ?? ''),
                            'warranty' => (string) ($m['warranty'] ?? ''),
                        ], $mfgSupport)) }} }">
                    <table class="om-row-tbl">
                        <thead>
                            <tr>
                                <th style="width: 8rem;">Brand</th>
                                <th style="width: 8rem;">Phone</th>
                                <th>Email</th>
                                <th>Portal</th>
                                <th style="width: 8rem;">Warranty</th>
                                <th style="width: 2.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr>
                                    <td><input type="text" data-optional :name="`manufacturer_support_overrides[${idx}][brand]`" x-model="item.brand" placeholder="Crestron" /></td>
                                    <td><input type="text" data-optional :name="`manufacturer_support_overrides[${idx}][phone]`" x-model="item.phone" placeholder="+44…" /></td>
                                    <td><input type="text" data-optional :name="`manufacturer_support_overrides[${idx}][email]`" x-model="item.email" placeholder="support@…" /></td>
                                    <td><input type="text" data-optional :name="`manufacturer_support_overrides[${idx}][portal]`" x-model="item.portal" placeholder="portal.brand.com" /></td>
                                    <td><input type="text" data-optional :name="`manufacturer_support_overrides[${idx}][warranty]`" x-model="item.warranty" placeholder="3 yrs onsite" /></td>
                                    <td><button type="button" class="del" @click="items.splice(idx, 1)" aria-label="Remove">×</button></td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0">
                                <td colspan="6" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">
                                    No overrides. AI resolves brands automatically on generate.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="om-repeater-add" @click="items.push({ brand: '', phone: '', email: '', portal: '', warranty: '' })">+ Add brand override</button>
                </div>
            </section>

            {{-- ═══ SECTION 11 · Service & escalation (editable) ═══════════ --}}
            <section class="om-section" id="s-escalation">
                <h2><span class="num">§11</span>Service &amp; escalation <span class="om-badge {{ $sEsc['class'] }}">{{ $sEsc['class'] === 'done' ? 'Overridden' : 'AI drafted' }}</span></h2>
                <p class="desc">21st Century AV support contact + escalation matrix. Renders on the "when logging a fault" page.</p>
                <div class="om-composite-grid">
                    <div class="form-group">
                        <label class="form-label" for="esc_contact">Contact name</label>
                        <input id="esc_contact" name="service_escalation[contact_name]" type="text" class="form-control" value="{{ old('service_escalation.contact_name', $escContact) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="esc_phone">Phone</label>
                        <input id="esc_phone" name="service_escalation[phone]" type="text" class="form-control" value="{{ old('service_escalation.phone', $escPhone) }}" placeholder="+44…" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="esc_email">Email</label>
                        <input id="esc_email" name="service_escalation[email]" type="email" class="form-control" value="{{ old('service_escalation.email', $escEmail) }}" placeholder="support@21stcenturyav.com" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="esc_hours">Support hours</label>
                        <input id="esc_hours" name="service_escalation[hours]" type="text" class="form-control" value="{{ old('service_escalation.hours', $escHours) }}" />
                    </div>
                </div>
                <div class="form-group" style="margin-top: .75rem;">
                    <label class="form-label" for="esc_matrix">Escalation matrix</label>
                    <textarea id="esc_matrix" name="service_escalation[matrix]" rows="4" data-optional class="form-control"
                              placeholder="e.g. L1 helpdesk → L2 lead engineer → L3 project manager">{{ old('service_escalation.matrix', $escMatrix) }}</textarea>
                </div>
            </section>

            {{-- ═══ SECTION 12 · Training & Handover (editable) ══════════ --}}
            <section class="om-section" id="s-handover">
                <h2><span class="num">§12</span>Training &amp; handover <span class="om-badge {{ $sHo['class'] }}">{{ $sHo['label'] }}</span></h2>
                <p class="desc">Handover date, attendees, and the user-competency statement rendered on section 12 of the PDF.</p>
                <div class="om-composite-grid">
                    <div class="form-group">
                        <label class="form-label" for="ho_date">Handover date (or [TBC])</label>
                        <input id="ho_date" name="handover_date" type="text" class="form-control" value="{{ old('handover_date', $hoDate) }}" placeholder="e.g. 15 Aug 2026" />
                    </div>
                </div>
                <div class="form-group" style="margin-top: .75rem;">
                    <label class="form-label" for="ho_competency">User competency statement</label>
                    <textarea id="ho_competency" name="training_handover[competency]" rows="3" data-optional class="form-control"
                              placeholder="e.g. Client staff attended the handover briefing on…">{{ old('training_handover.competency', $hoCompetency) }}</textarea>
                </div>
                <h5 style="font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); font-weight: 700; margin-top: 1rem; margin-bottom: .35rem;">Handover attendees</h5>
                <div x-data="{ items: {{ json_encode(array_map(fn($a) => [
                            'name' => (string) ($a['name'] ?? ''),
                            'role' => (string) ($a['role'] ?? ''),
                        ], $hoAttendees)) }} }">
                    <table class="om-row-tbl">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th style="width: 2.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr>
                                    <td><input type="text" data-optional :name="`training_handover[attendees][${idx}][name]`" x-model="item.name" placeholder="Jane Smith" /></td>
                                    <td><input type="text" data-optional :name="`training_handover[attendees][${idx}][role]`" x-model="item.role" placeholder="IT Manager" /></td>
                                    <td><button type="button" class="del" @click="items.splice(idx, 1)" aria-label="Remove">×</button></td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0">
                                <td colspan="3" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">
                                    No attendees yet — add client-side representatives who received training.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="om-repeater-add" @click="items.push({ name: '', role: '' })">+ Add attendee</button>
                </div>
            </section>

            {{-- §13 + §14 folded into the "Auto-generated content" accordion
                 above (Tier-1 O&M v2b). --}}

            {{-- ═══ SECTION 15 · Document control (editable) ══════════════ --}}
            <section class="om-section" id="s-doc-control">
                <h2><span class="num">§15</span>Document control <span class="om-badge {{ $sDoc['class'] }}">{{ $sDoc['class'] === 'done' ? 'Complete' : 'Needs data' }}</span></h2>
                <p class="desc">Revision number, document status, and prepared-by. Renders at the tail of the PDF as the doc-control page.</p>
                <div class="om-composite-grid">
                    <div class="form-group">
                        <label class="form-label" for="dc_rev">Revision</label>
                        <input id="dc_rev" name="document_control[revision]" type="text" class="form-control" value="{{ old('document_control.revision', $dcRevision) }}" placeholder="1.0" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="dc_status">Document status</label>
                        <select id="dc_status" name="document_control[status]" class="form-control" data-optional>
                            @foreach (['Draft', 'For Review', 'For Approval', 'For Issue', 'Superseded'] as $option)
                                <option value="{{ $option }}" @selected($dcStatus === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="dc_prepared">Prepared by</label>
                        <input id="dc_prepared" name="document_control[prepared_by]" type="text" class="form-control" value="{{ old('document_control.prepared_by', $dcPreparedBy) }}" />
                    </div>
                </div>
            </section>

            {{-- ═══ ROOMS & EQUIPMENT ═════════════════════════════════════ --}}
            <div style="padding: .5rem 0 .25rem; margin-top: 1rem;">
                <h2 style="font-size: 1.05rem; font-weight: 700; margin: 0 0 .3rem; color: var(--text);">Rooms &amp; equipment</h2>
                <p style="font-size: .82rem; color: var(--text-muted); margin: 0 0 .75rem;">
                    Auto-populated from the linked project's survey + quote data. Each room's narrative feeds §5 User Guides on regenerate.
                </p>
            </div>

            @foreach ($rooms as $i => $room)
                @php
                    $rname       = trim((string) ($room['name']        ?? ''));
                    $rfloor      = trim((string) ($room['floor']       ?? ''));
                    $rdrawing    = trim((string) ($room['drawing_ref'] ?? ''));
                    $rnarr       = trim((string) ($room['narrative']   ?? $room['description'] ?? ''));
                    $rIsTbc      = str_contains($rnarr, '[TBC]');
                    $rEquipment  = is_array($room['equipment'] ?? null) ? array_values($room['equipment']) : [];
                @endphp
                <section class="om-room" id="s-room-{{ $i }}">
                    <div class="om-room-h">
                        <div class="om-room-num">{{ $i + 1 }}</div>
                        <h3 class="om-room-title">{{ $rname !== '' ? $rname : 'Room ' . ($i + 1) }}</h3>
                        @if ($rIsTbc)<span class="om-badge tbc">[TBC] narrative</span>
                        @elseif ($rnarr !== '')<span class="om-badge ai">AI drafted</span>@endif
                    </div>
                    <div class="om-room-fields">
                        <div class="form-group">
                            <label class="form-label" for="room_name_{{ $i }}">Room name</label>
                            <input id="room_name_{{ $i }}" name="rooms[{{ $i }}][name]" type="text" class="form-control" value="{{ old("rooms.$i.name", $rname) }}" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="room_floor_{{ $i }}">Floor</label>
                            <input id="room_floor_{{ $i }}" name="rooms[{{ $i }}][floor]" type="text" class="form-control" value="{{ old("rooms.$i.floor", $rfloor) }}" placeholder="Ground / 1st" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="room_drawing_{{ $i }}">Drawing ref</label>
                            <input id="room_drawing_{{ $i }}" name="rooms[{{ $i }}][drawing_ref]" type="text" class="form-control" value="{{ old("rooms.$i.drawing_ref", $rdrawing) }}" placeholder="A-01" />
                        </div>
                    </div>
                    <div class="om-room-narr">
                        <label for="room_narr_{{ $i }}">Narrative</label>
                        <textarea id="room_narr_{{ $i }}" name="rooms[{{ $i }}][narrative]" rows="4" data-optional class="form-control"
                                  style="font-size: .85rem; line-height: 1.5; width: 100%;">{{ old("rooms.$i.narrative", $rnarr) }}</textarea>
                        <p class="om-room-narr-note">Regenerating overwrites this with a fresh AI draft — save edits before regenerating if you want to keep them.</p>
                    </div>
                    {{-- Undo-toast state (v2c). Alpine holds an `undo` stack — each entry
                         remembers the removed item + its original index + its timeout id.
                         Row × calls remove(idx), which splices the row AND stashes the
                         removal. The undo toast at the bottom re-inserts on click. After
                         6 seconds the entry is auto-dismissed and the delete becomes final
                         (on next Save). Wired only for per-room equipment tables since
                         that's where users most often mis-click. --}}
                    <div x-data="{
                        items: {{ json_encode(array_map(fn($eq) => [
                                'qty'          => (int) ($eq['qty'] ?? $eq['quantity'] ?? 1),
                                'part_number'  => (string) ($eq['part_number'] ?? $eq['part_no'] ?? ''),
                                'description'  => (string) ($eq['description'] ?? $eq['name']    ?? $eq['item'] ?? ''),
                                'manufacturer' => (string) ($eq['manufacturer'] ?? $eq['make']   ?? ''),
                            ], $rEquipment)) }},
                        undo: [],
                        remove(idx) {
                            const removed = { ...this.items[idx] };
                            this.items.splice(idx, 1);
                            const stamp = Date.now() + Math.random();
                            const timer = setTimeout(() => {
                                this.undo = this.undo.filter(e => e.stamp !== stamp);
                            }, 6000);
                            this.undo.push({ stamp, item: removed, at: idx, timer });
                        },
                        restore(stamp) {
                            const entry = this.undo.find(e => e.stamp === stamp);
                            if (!entry) return;
                            clearTimeout(entry.timer);
                            const target = Math.min(entry.at, this.items.length);
                            this.items.splice(target, 0, entry.item);
                            this.undo = this.undo.filter(e => e.stamp !== stamp);
                        },
                        dismiss(stamp) {
                            const entry = this.undo.find(e => e.stamp === stamp);
                            if (!entry) return;
                            clearTimeout(entry.timer);
                            this.undo = this.undo.filter(e => e.stamp !== stamp);
                        },
                        labelFor(item) {
                            return item.description || item.part_number || 'equipment row';
                        }
                    }">
                        <table class="om-eq-tbl">
                            <thead>
                                <tr>
                                    <th style="width: 4rem;">Qty</th>
                                    <th style="width: 9rem;">Part number</th>
                                    <th>Description</th>
                                    <th style="width: 9rem;">Manufacturer</th>
                                    <th style="width: 2.5rem;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, idx) in items" :key="idx">
                                    <tr>
                                        <td><input type="number" min="1" step="1" class="qty" data-optional :name="`rooms[{{ $i }}][equipment][${idx}][qty]`" x-model="item.qty" /></td>
                                        <td><input type="text" class="part" data-optional :name="`rooms[{{ $i }}][equipment][${idx}][part_number]`" x-model="item.part_number" placeholder="UC-MMX30-Z" /></td>
                                        <td><input type="text" data-optional :name="`rooms[{{ $i }}][equipment][${idx}][description]`" x-model="item.description" /></td>
                                        <td><input type="text" data-optional :name="`rooms[{{ $i }}][equipment][${idx}][manufacturer]`" x-model="item.manufacturer" placeholder="Crestron" /></td>
                                        <td><button type="button" class="del" @click="remove(idx)" aria-label="Remove">×</button></td>
                                    </tr>
                                </template>
                                <tr x-show="items.length === 0 && undo.length === 0">
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">No equipment yet — add the first row.</td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" class="om-eq-add" @click="items.push({ qty: 1, part_number: '', description: '', manufacturer: '' })">+ Add equipment row</button>

                        {{-- Undo-toast strip. Sits below the equipment table so it doesn't
                             float over other content. Each removal gets its own row so
                             quick-fire deletions can all be undone individually. --}}
                        <div class="om-undo-strip" x-show="undo.length > 0" x-cloak x-transition>
                            <template x-for="entry in undo" :key="entry.stamp">
                                <div class="om-undo-row" role="status" aria-live="polite">
                                    <span class="om-undo-icon" aria-hidden="true">↩</span>
                                    <span class="om-undo-body">
                                        Removed <strong x-text="labelFor(entry.item)"></strong>.
                                        <span class="om-undo-hint">Auto-dismisses in 6s.</span>
                                    </span>
                                    <button type="button" class="om-undo-btn" @click="restore(entry.stamp)">Undo</button>
                                    <button type="button" class="om-undo-x" @click="dismiss(entry.stamp)" aria-label="Dismiss">×</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>
            @endforeach

            @if (empty($rooms))
                <div class="om-section" style="text-align: center; padding: 2rem 1rem;">
                    <p style="color: var(--text-muted); font-size: .85rem; margin: 0;">
                        No rooms yet.
                        @if ($manual->project_id)
                            Rooms auto-populate from the linked project's survey + quote data.
                            <br><a href="{{ route('projects.show', $manual->project_id) }}" style="color: var(--accent); text-decoration: underline;">Open the project workspace</a>
                            to add rooms via the survey, then regenerate.
                        @endif
                    </p>
                </div>
            @endif

            {{-- ═══ SPECIAL NOTES (editable) ══════════════════════════════ --}}
            <section class="om-section" id="s-notes">
                <h2>Special notes <span class="om-badge {{ $sNotes['class'] }}">
                    @if ($sNotes['class'] === 'done')Populated
                    @elseif ($sNotes['class'] === 'tbc')[TBC]
                    @else Optional @endif
                </span></h2>
                <p class="desc">Access codes, comms-room hand-offs, oddities the engineer flagged — anything the numbered sections don't cover.</p>
                <textarea name="notes" id="om_notes" rows="5" data-optional class="form-control"
                          style="font-size: .88rem; line-height: 1.5; width: 100%;"
                          placeholder="e.g. Comms room accessed via reception — engineer needs to sign in with security…">{{ old('notes', $notes) }}</textarea>
            </section>

            {{-- ═══ ADVANCED ══════════════════════════════════════════════ --}}
            <section class="om-section" id="s-advanced">
                <details class="om-advanced">
                    <summary>Advanced — edit raw JSON payload</summary>
                    <div style="padding-top: .5rem;">
                        <div class="om-advanced-warning">
                            <strong>⚠ Advanced.</strong> Editing raw JSON bypasses all typed fields above.
                            A missing comma or bracket will fail validation and reject your save.
                        </div>
                        <label style="display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; font-size: .8rem; color: var(--text-muted);">
                            <input type="checkbox" name="use_raw_json" value="1" />
                            Use the raw JSON below instead of the structured fields
                        </label>
                        <textarea id="extracted_json" name="extracted_json" rows="16" data-optional class="form-control"
                                  style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .76rem;"
                        >{{ json_encode($manual->extracted_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                        @error('extracted_json')
                            <p style="color: #c0392b; font-size: .8rem; margin-top: .35rem;">{{ $message }}</p>
                        @enderror
                    </div>
                </details>
            </section>

            <div class="om-save-bar">
                <span class="om-save-info"><span class="dot"></span>Structured fields save on click. Raw JSON path requires the checkbox above.</span>
                <div style="margin-left: auto; display: flex; gap: .5rem;">
                    <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-teal btn-sm">Save changes</button>
                </div>
            </div>
        </form>

        {{-- Generate O&M --}}
        @if ($manual->status !== \App\Models\OmManual::STATUS_GENERATING)
            <div class="card" style="padding: 1.25rem; margin-top: 1.5rem;">
                <h2 style="font-size: 1rem; font-weight: 700; margin-bottom: .5rem;">Generate O&amp;M manual</h2>
                <p style="font-size: .82rem; color: var(--text-muted); margin-bottom: .75rem;">
                    Kicks off a queued build. AI regenerates the scope + per-room narrative + AI sections from your saved data.
                </p>
                <form method="POST" action="{{ route('om-manuals.generate', $manual) }}">
                    @csrf
                    <button type="submit" class="btn btn-teal">Generate document</button>
                </form>
            </div>
        @else
            <div class="card" style="padding: 1.25rem; margin-top: 1.5rem;">
                <p style="color: #888; font-style: italic; margin: 0;">Generation in progress — please wait…</p>
            </div>
        @endif
    </div>
</div>

<script>
(function () {
    const rail = document.querySelector('.om-rail');
    if (!rail) return;
    const railLinks = Array.from(rail.querySelectorAll('.om-rail-item'));
    if (!railLinks.length) return;
    const targets = railLinks.map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    if (!targets.length) return;
    const setActive = (id) => {
        railLinks.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
    };
    const obs = new IntersectionObserver((entries) => {
        const visible = entries.filter(e => e.isIntersecting)
            .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length) setActive(visible[0].target.id);
    }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
    targets.forEach(t => obs.observe(t));
})();
</script>
@endsection

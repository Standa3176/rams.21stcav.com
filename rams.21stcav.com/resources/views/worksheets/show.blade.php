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

/* .ws-signoff-hero styles retired 2026-07-09 — the panel now uses the
   shared x-link-hero component which ships its own stylesheet via a
   Blade once-directive (do NOT prefix "once" with an @-sign here —
   Blade compiles directive tokens even inside CSS comments). */
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
        {{-- Engineer Report PDF button (260602-rcd) — same content as this page
             rendered to a print-optimised PDF. Disabled with a tooltip when the
             engineer hasn't captured anything yet (avoids emitting an empty PDF
             that looks like a bug). --}}
        @if($worksheet->hasEngineerActivity())
            <a href="{{ route('worksheets.engineer-report-pdf', $worksheet) }}"
               class="btn btn-outline btn-sm"
               target="_blank"
               aria-label="Download Engineer Report PDF">📄 Engineer Report PDF</a>
        @else
            <button type="button"
                    class="btn btn-outline btn-sm"
                    disabled
                    title="No engineer activity yet"
                    aria-label="Engineer Report PDF (no activity)">📄 Engineer Report PDF</button>
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
            {{-- Phase 46.4 Plan 03 Task 3 — the office should not have to know
                 a URL to read the serials this worksheet's engineers captured.
                 The two install-capture artefacts (kit list, asset register)
                 are reachable from the same page. --}}
            <a href="{{ route('projects.asset-list', $worksheet->project) }}" class="btn-outline btn-sm">Asset list</a>
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

{{-- ══════════════════════════════════════════════════════════════════════
     Tier-1 Screen 05 v1 — sign-off link hero.

     The client sign-off URL is the one artefact engineers share externally,
     so promote it above every other block. Renders only when the worksheet
     has an access token (legacy pre-token worksheets fall through to no
     hero and continue to work exactly as before).

     Old sign-off card lower down was removed to avoid duplicating the URL.
     ══════════════════════════════════════════════════════════════════════ --}}
{{-- Batch 11 UX-07 — ready-for-signoff cue. Renders above the sign-off
     link hero when the worksheet has engineer activity but no signature
     yet, so the PM immediately knows to send the link to the client. --}}
@if ($worksheet->isReadyForSignoff())
    <div class="ws-ready" role="status">
        <span class="ws-ready__ico" aria-hidden="true">✓</span>
        <div class="ws-ready__body">
            <strong>Ready for client sign-off.</strong>
            Engineer work is captured and the sign-off link below is live —
            send it to the client to complete this worksheet.
        </div>
    </div>
    <style>
        .ws-ready {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 16px;
            margin-bottom: 12px;
            background: var(--success-light);
            border: 1px solid color-mix(in oklab, var(--success) 30%, transparent);
            color: #065F46;
            border-radius: var(--radius-lg);
            font-size: var(--fs-small);
            line-height: 1.55;
        }
        .ws-ready__ico {
            flex-shrink: 0;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: var(--success);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 12px;
            margin-top: 2px;
        }
        .ws-ready__body { min-width: 0; }
        .ws-ready strong { font-weight: 600; color: #065F46; margin-right: 4px; }
    </style>
@endif

@if($worksheet->access_token)
    @php $worksheetPublicUrl = $worksheet->publicUrl(); @endphp
    <x-link-hero
        :url="$worksheetPublicUrl"
        label="Client sign-off link"
        icon="🔗"
        regionLabel="Client sign-off link"
        urlAriaLabel="Sign-off URL — click to select">
        @if($worksheet->isSigned())
            @php $sig = $worksheet->latestSignoff(); @endphp
            <x-slot name="hint">
                ✓ Signed by <strong>{{ $sig->client_name }}</strong> on
                {{ $sig->signed_at->format('d M Y H:i') }}
                @if($sig->signed_with_comments)
                    · signed with comments
                @endif
                {{-- Quick task 260726-fx4 Task 3 — office notification pill.
                     Green when the WorksheetSignedMail actually left the
                     mailer without throwing; amber when null (mail failure
                     or no project owner resolved). --}}
                @if($worksheet->signed_notification_sent_at)
                    <span style="display:inline-block;background:#D1FAE5;color:#065F46;padding:1px 8px;border-radius:12px;font-size:11px;font-weight:600;margin-left:.5rem;">
                        Office notified {{ $worksheet->signed_notification_sent_at->diffForHumans() }}
                    </span>
                @else
                    <span style="display:inline-block;background:#FEE9CC;color:#7C2D12;padding:1px 8px;border-radius:12px;font-size:11px;font-weight:600;margin-left:.5rem;" title="No office notification email was sent — either the project has no owner or the mailer failed">
                        Office not notified
                    </span>
                @endif
            </x-slot>
        @endif

        <x-slot name="actions">
            <x-copy-link-button :url="$worksheetPublicUrl" label="Copy" />
            <a href="{{ $worksheetPublicUrl }}" target="_blank" class="btn btn-sm">Open ↗</a>
            {{-- Audit M-05 — revoke the current token so any leaked copy of
                 the URL becomes inert. A fresh UUID replaces this one and
                 the page reloads showing the new link. --}}
            <form method="POST"
                  action="{{ route('worksheets.revoke-token', $worksheet) }}"
                  onsubmit="return confirm('Revoke the current sign-off link? The client will need the new link before signing.');"
                  style="display:inline;">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger" aria-label="Revoke and regenerate the sign-off link">
                    Revoke &amp; regenerate
                </button>
            </form>
        </x-slot>
    </x-link-hero>
@endif

{{-- Stale-data banner (260602-o2a) — renders only when project.latestPackage
     has been edited after the worksheet snapshot was generated. --}}
@include('worksheets._stale-banner', ['worksheet' => $worksheet, 'variant' => 'admin'])

{{-- Tier-1 Screen 05 v2 — the Sign-Off Status wrapper card was doing very
     little (status badge + generated timestamp) but occupied a full
     "section-header + card" layer of visual chrome. Merged into a slim
     meta strip below the hero. Error alert stays inline. --}}
<div style="display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:0 .25rem 1.1rem; font-size:.85rem; color:var(--text-muted); flex-wrap:wrap;">
    <div style="display:inline-flex; align-items:center; gap:.6rem;">
        <x-status-badge :status="$worksheet->status" />
        @if(in_array($worksheet->status, ['pending', 'generating']))
            <span style="display:inline-flex; align-items:center; gap:.4rem;">
                <span style="width:8px; height:8px; border-radius:50%; background:#D97706; display:inline-block;"></span>
                Generating…
            </span>
        @else
            <span>Generated {{ $worksheet->updated_at->diffForHumans() }}</span>
        @endif
    </div>
</div>

{{-- Error alert --}}
@if($worksheet->status === 'failed' && $worksheet->error_message)
    <div class="alert alert-error" style="margin-bottom:1.25rem;">
        Generation failed: {{ $worksheet->error_message }}. Click Retry Generation to try again.
    </div>
@endif

{{-- Outstanding Items aggregate (260602-rcd) — flat list of every snag from
     every "signed_with_comments" sign-off. Hidden when empty so the page
     doesn't carry an "Outstanding Items (0)" header on clean projects. --}}
@php $outstandingItems = $context['outstanding_items'] ?? []; @endphp
@if(! empty($outstandingItems))
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Outstanding Items ({{ count($outstandingItems) }})</h2>
        </div>
        <div class="form-section__body">
            <div class="card card-sm" style="border-left:3px solid #C07000;background:#FFFBEB;">
                <ul style="margin:0;padding-left:1.25rem;font-size:.875rem;line-height:1.6;color:var(--text);">
                    @foreach($outstandingItems as $item)
                        <li style="margin-bottom:.25rem;">{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif

{{-- ── Additional Kit (Phase 46.4 Plan 03 Task 2 — D-06 / D-08 / IC-05) ──────

     THE ACCEPTANCE TEST OF THE PHASE, in the user's words: extra kit must be
     "presented to the office admin as a clean list rather than free text."
     Before this, the only place it could land was the max:5000 sign-off
     comments textarea, or a phone call.

     ONE TABLE. A marked row is flagged IN PLACE, never moved to a second
     table and never dropped — the office reconciles ONE list against ONE
     quote, and a second table is a second place to forget to look.

     Row status branches on plan 01's isMarked() / isAmended() helpers and
     NEVER re-derives them from marked_for_deletion_at or count($amendments):
     plan 05 enforces the same states server-side and the two must not drift.

     NEVER RENDERED HERE: created_by_actor, marked_by_actor, amendments[].actor
     (all hold `ip:…|actor:<sha256 slice>`), and never an engineer's email or
     phone — the name arrives through the constrained `:id,name` eager load in
     WorksheetController::show(). Every echo is `{{ }}`; part_description AND
     deletion_reason are engineer free text off an unauthenticated link, and
     plan 05 renders these same rows on a page a CLIENT signs. --}}
@php $kitRows = $worksheet->additionalKit; @endphp
<div class="form-section">
    <div class="form-section__header">
        <h2 class="section-heading">Additional Kit ({{ $kitRows->count() }})</h2>
    </div>
    <div class="form-section__body">
        @if($kitRows->isEmpty())
            {{-- A NAMED empty state: "none used" must be distinguishable from
                 "this screen is broken". --}}
            <div class="card card-sm" style="color:var(--text-muted);font-size:.875rem;padding:1.25rem;text-align:center;">
                No additional kit has been recorded on this worksheet.
            </div>
        @else
            <p style="margin:0 0 .6rem;font-size:.8rem;color:var(--text-muted);line-height:1.5;">
                Extra kit the engineers used on site, as separate lines to reconcile against the quote.
                Marking a line reconciled records that the office has actioned it — after that
                the engineer can no longer amend or mark it.
            </p>

            @if(session('success'))
                <div class="alert alert-success" style="margin-bottom:.6rem;">{{ session('success') }}</div>
            @endif

            <div style="overflow-x:auto;">
                <table class="data-table" data-kit-table style="font-size:.82rem;">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Engineer</th>
                            <th>Qty</th>
                            <th>Part description</th>
                            <th>Captured</th>
                            <th>Status</th>
                            <th>Office</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $renderedRoom = null; @endphp
                        @foreach($kitRows as $kitRow)
                            @php
                                // Plan 01's order is room_name, sort_order, id — so a
                                // room heading is emitted the first time the room
                                // changes, inside the SAME table.
                                $kitRoom      = trim((string) $kitRow->room_name) !== ''
                                    ? (string) $kitRow->room_name
                                    : 'Room not recorded';
                                $showRoomHead = $kitRoom !== $renderedRoom;
                                $renderedRoom = $kitRoom;
                            @endphp
                            @if($showRoomHead)
                                <tr>
                                    <td colspan="7" style="background:var(--surface-soft);font-weight:700;font-size:.75rem;
                                                           text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">
                                        {{ $kitRoom }}
                                    </td>
                                </tr>
                            @endif
                            <tr @if($kitRow->isMarked()) style="background:#FFF7F7;" @endif>
                                <td>{{ $kitRow->room_name }}</td>
                                <td>
                                    @if($kitRow->labourResource)
                                        {{ $kitRow->labourResource->name }}
                                    @else
                                        {{-- D-02 fallback: a NAMED gap, never a blank cell and
                                             never a typed name. --}}
                                        <span style="color:var(--text-faint);">Unassigned — no engineer allocated to this visit</span>
                                    @endif
                                </td>
                                <td class="tabular" style="font-weight:600;">{{ $kitRow->qty }}</td>
                                <td>
                                    <div style="color:var(--text);">{{ $kitRow->part_description }}</div>

                                    @if($kitRow->isAmended())
                                        {{-- D-08: the CURRENT values above, and what changed
                                             below. Showing only the latest values silently is
                                             the exact failure this trail exists to prevent. --}}
                                        <span style="display:inline-block;margin-top:.3rem;padding:1px 6px;border-radius:9999px;
                                                     background:#FEF3C7;color:#92400E;font-weight:600;font-size:.7rem;">Amended</span>
                                        <details style="margin-top:.3rem;">
                                            <summary style="cursor:pointer;font-size:.75rem;color:var(--teal);">
                                                What changed ({{ count($kitRow->amendments) }})
                                            </summary>
                                            <div style="margin-top:.3rem;font-size:.75rem;color:var(--text-muted);line-height:1.5;">
                                                @foreach($kitRow->amendments as $entry)
                                                    @php
                                                        // Oldest first — the trail is append-only.
                                                        // The entry's `actor` key is NEVER read here.
                                                        $entryAt = rescue(
                                                            fn () => \Illuminate\Support\Carbon::parse($entry['at'] ?? null)->format('d M Y H:i'),
                                                            null,
                                                            false,
                                                        );
                                                        $entryChanges = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
                                                    @endphp
                                                    <div style="margin-bottom:.25rem;">
                                                        <span style="color:var(--text-faint);">{{ $entryAt ?? 'Date not recorded' }}</span>
                                                        @foreach($entryChanges as $field => $change)
                                                            @php
                                                                $from = is_scalar($change['from'] ?? null) ? (string) $change['from'] : '—';
                                                                $to   = is_scalar($change['to'] ?? null)   ? (string) $change['to']   : '—';
                                                            @endphp
                                                            <div>{{ $field }}: {{ $from }} → {{ $to }}</div>
                                                        @endforeach
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;color:var(--text-muted);">
                                    {{ $kitRow->created_at?->format('d M H:i') }}
                                </td>
                                <td>
                                    @if($kitRow->isMarked())
                                        <span style="display:inline-block;padding:1px 6px;border-radius:9999px;
                                                     background:#FEE2E2;color:#991B1B;font-weight:600;font-size:.7rem;">Marked for deletion</span>
                                        <div style="margin-top:.3rem;font-size:.75rem;color:var(--text);line-height:1.4;">
                                            {{-- Engineer free text, and exactly as dangerous as the
                                                 part description. A legacy row with no reason says
                                                 so rather than rendering a silent blank. --}}
                                            Reason: {{ $kitRow->deletion_reason ?: 'No reason recorded' }}
                                        </div>
                                    @else
                                        <span style="color:var(--text-muted);">Open</span>
                                    @endif
                                </td>
                                <td style="white-space:nowrap;">
                                    @if($kitRow->reconciled_at)
                                        <span style="display:inline-block;padding:1px 6px;border-radius:9999px;
                                                     background:#DCFCE7;color:#166534;font-weight:600;font-size:.7rem;">✓ Reconciled</span>
                                        <div style="font-size:.72rem;color:var(--text-faint);margin-top:.2rem;">
                                            {{ $kitRow->reconciled_at->format('d M H:i') }}
                                        </div>
                                    @else
                                        {{-- Works on EVERY row state, marked included — see
                                             WorksheetController::reconcileAdditionalKit(). --}}
                                        <form method="POST"
                                              action="{{ route('worksheets.additional-kit.reconcile', ['worksheet' => $worksheet->id, 'row' => $kitRow->id]) }}"
                                              style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn-outline btn-sm">Mark reconciled</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
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
                {{-- Completed-Work Photos per room (260602-rcd) — engineer
                     uploads completed-install evidence via the public worksheet
                     link; this surfaces those photos on the admin view.
                     Reuses the same openPhotoLightbox cycler the labels section
                     uses below, so the interaction is identical.
                     Photos are served via the public-worksheet.photos.serve
                     route — there's no admin-only photo-serve endpoint, and
                     the worksheet's access_token is known to the admin viewing
                     the page already (it's printed on the Client Sign-Off Link
                     card above), so reusing the token-gated serve is safe. --}}
                @php
                    $roomKey = strtolower(trim($room['name'] ?? ''));
                    $completedPhotosForRoom = collect();
                    foreach ($context['rooms'] ?? [] as $ctxRoom) {
                        if (strtolower(trim($ctxRoom['name'])) === $roomKey) {
                            $completedPhotosForRoom = $ctxRoom['completed_photos'];
                            break;
                        }
                    }
                    $completedPhotosLb = $completedPhotosForRoom->values()->map(function ($p) use ($worksheet) {
                        return [
                            'url'     => route('public-worksheet.photos.serve', ['token' => $worksheet->access_token, 'photo' => $p->id]),
                            'caption' => $p->caption ?: $p->original_name,
                        ];
                    })->all();
                @endphp
                @if($completedPhotosForRoom->isNotEmpty())
                    <div class="room-section-hdr">Completed-Work Photos ({{ $completedPhotosForRoom->count() }})</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.7rem;margin-bottom:1rem;">
                        @foreach($completedPhotosForRoom as $cp)
                            @php $cpUrl = route('public-worksheet.photos.serve', ['token' => $worksheet->access_token, 'photo' => $cp->id]); @endphp
                            <a href="{{ $cpUrl }}"
                               target="_blank"
                               onclick="event.preventDefault(); openPhotoLightbox(@js($completedPhotosLb), {{ $loop->index }});"
                               style="display:block;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#F3F4F6;text-decoration:none;">
                                <img src="{{ $cpUrl }}"
                                     alt="{{ $cp->caption ?: 'Completed work photo' }}"
                                     loading="lazy"
                                     style="display:block;width:100%;height:140px;object-fit:cover;">
                                @if($cp->caption)
                                    <div style="padding:.4rem .55rem;font-size:.75rem;color:var(--text);background:var(--surface);line-height:1.3;">{{ $cp->caption }}</div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif

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

{{-- Tier-1 Screen 05 v1 — footer action row removed.
     The four buttons here (Download DOCX / Regenerate / Back to Project /
     History) all duplicated the header toolbar. When the page could be as
     little as one collapsed room, the duplicated footer created a
     "surely I should scroll for more" feeling with nothing behind it. All
     actions still available in the top toolbar.

     When status is pending/generating (no DOCX yet), the top toolbar hides
     the Download button; a short caption below covers that case so users
     landing on a generating worksheet aren't confused. --}}
@if(! in_array($worksheet->status, ['draft', 'final']))
    <p style="margin-top:1.25rem;font-size:.85rem;color:var(--text-muted);text-align:center;">
        DOCX available once generation is complete.
    </p>
@endif

@endsection

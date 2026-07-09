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

/* Sign-off hero — Jetbuilt-clean (2026-07-09). Flat navy panel with
   accent-tinted URL chip, no gradient, no shadow. Reads as one
   consistent hero surface across worksheets + surveys. */
.ws-signoff-hero {
    background: var(--nav-800);
    color: #E0E7FF;
    border-radius: var(--radius-lg);
    padding: 18px 22px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: 26px 1fr auto;
    gap: 16px;
    align-items: center;
    box-shadow: none;
}
.ws-signoff-hero .icon {
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    color: var(--accent-500);
}
.ws-signoff-hero .body { min-width: 0; }
.ws-signoff-hero .label {
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--accent-500);
    margin-bottom: 4px;
}
.ws-signoff-hero .url {
    font-family: var(--font-mono);
    font-size: 12px;
    color: #F1F5F9;
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    background: rgba(255, 255, 255, 0.06);
    padding: 6px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid rgba(255, 255, 255, 0.10);
    cursor: text;
}
.ws-signoff-hero .actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
}
.ws-signoff-hero .actions .btn {
    background: rgba(255, 255, 255, 0.10);
    color: #EEF2FF;
    border-color: rgba(255, 255, 255, 0.16);
    font-size: 12px;
    padding: 5px 12px;
}
.ws-signoff-hero .actions .btn:hover {
    background: rgba(255, 255, 255, 0.18);
}
.ws-signoff-hero .signed-note {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.65);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.ws-signoff-hero .signed-note.comments { color: #F4E7CE; }
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
@if($worksheet->access_token)
    @php $worksheetPublicUrl = $worksheet->publicUrl(); @endphp
    <div class="ws-signoff-hero" role="region" aria-label="Client sign-off link">
        <div class="icon" aria-hidden="true">🔗</div>
        <div class="body">
            <div class="label">Client sign-off link</div>
            <input type="text" value="{{ $worksheetPublicUrl }}" readonly data-optional
                   class="url"
                   onclick="this.select()"
                   aria-label="Sign-off URL — click to select">
            @if($worksheet->isSigned())
                @php $sig = $worksheet->latestSignoff(); @endphp
                <div class="signed-note {{ $sig->signed_with_comments ? 'comments' : '' }}">
                    ✓ Signed by <strong>{{ $sig->client_name }}</strong> on
                    {{ $sig->signed_at->format('d M Y H:i') }}
                    @if($sig->signed_with_comments)
                        · signed with comments
                    @endif
                </div>
            @endif
        </div>
        <div class="actions">
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
        </div>
    </div>
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

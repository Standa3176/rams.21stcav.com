@extends('layouts.app')

@section('title', 'Review O&M — ' . ($manual->project_name ?? 'O&M Manual'))

@push('styles')
<style>
    .om-edit-title { font-size: 1.4rem; font-weight: 700; margin: 0; color: var(--text); letter-spacing: -.015em; line-height: 1.2; }
    .om-edit-title em { font-style: normal; font-weight: 500; color: var(--text-muted); }
    .om-edit-eyebrow { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: .25rem; }

    /* ─── Two-column layout: sticky rail + main ─────────────────────────── */
    .om-layout {
        display: grid;
        grid-template-columns: 220px 1fr;
        gap: 1.5rem;
        max-width: 1160px;
        margin: 0 auto;
        align-items: start;
    }
    @media (max-width: 900px) {
        .om-layout { grid-template-columns: 1fr; }
        .om-rail { position: static !important; }
    }
    .om-rail {
        position: sticky;
        top: 5rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .5rem;
        max-height: calc(100vh - 6rem);
        overflow-y: auto;
    }
    .om-rail-h {
        font-size: .65rem;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 700;
        padding: .5rem .6rem .35rem;
    }
    .om-rail-item {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .45rem .6rem;
        border-radius: 4px;
        font-size: .8rem;
        color: var(--text);
        text-decoration: none;
        font-weight: 500;
        line-height: 1.3;
    }
    .om-rail-item:hover { background: var(--bg-muted, #faf7ee); }
    .om-rail-item.active {
        background: var(--accent, #0F3E36);
        color: #E6B849;
        font-weight: 600;
    }
    .om-rail-item .om-rail-status {
        margin-left: auto;
        font-size: .65rem;
        padding: 1px 6px;
        border-radius: 8px;
        font-weight: 700;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .om-rail-item .om-rail-status.done { background: #DDEBE1; color: #204F3D; }
    .om-rail-item .om-rail-status.tbc  { background: #F4E7CE; color: #7E5717; }
    .om-rail-item .om-rail-status.empty { background: var(--bg-muted, #EDE7D5); color: var(--text-muted); }
    .om-rail-item.active .om-rail-status.done  { background: rgba(255,255,255,.15); color: #E6B849; }
    .om-rail-item.active .om-rail-status.tbc   { background: rgba(255,255,255,.15); color: #E6B849; }
    .om-rail-item.active .om-rail-status.empty { background: rgba(255,255,255,.15); color: #E6B849; }
    .om-rail-item .om-rail-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* ─── Section cards ──────────────────────────────────────────────────── */
    .om-section {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1rem;
        scroll-margin-top: 5rem;
    }
    .om-section h2 {
        font-size: 1.2rem;
        font-weight: 700;
        margin: 0 0 .3rem;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .6rem;
    }
    .om-section h2 .om-badge {
        font-size: .58rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 700;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .om-badge.ai   { background: #E8DEEF; color: #45305A; }
    .om-badge.done { background: #DDEBE1; color: #204F3D; }
    .om-badge.tbc  { background: #F4E7CE; color: #7E5717; }
    .om-badge.edited { background: #FBF8EF; color: var(--text-muted); border: 1px solid var(--border); }
    .om-section p.desc {
        color: var(--text-muted);
        font-size: .82rem;
        line-height: 1.5;
        margin: 0 0 1rem;
    }

    /* ─── Meta strip ─────────────────────────────────────────────────────── */
    .om-meta-strip {
        display: flex;
        gap: 1.25rem;
        padding: .8rem 1rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        margin-bottom: 1rem;
        font-size: .85rem;
        flex-wrap: wrap;
    }
    .om-meta-strip strong { color: var(--text-muted); font-weight: 600; margin-right: .3rem; }

    /* ─── Room card (editable) ──────────────────────────────────────────── */
    .om-room {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 1.25rem 1.5rem;
        margin-bottom: 1rem;
        scroll-margin-top: 5rem;
    }
    .om-room-h {
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: .75rem;
    }
    .om-room-h .om-room-num {
        width: 28px; height: 28px;
        background: var(--bg-muted, #EDE7D5);
        color: var(--text);
        border-radius: 5px;
        display: flex; align-items: center; justify-content: center;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 700;
        font-size: .78rem;
    }
    .om-room-h .om-room-title {
        font-size: 1.02rem;
        font-weight: 700;
        margin: 0;
        flex: 1;
    }
    .om-room-fields {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: .5rem .75rem;
        margin-bottom: 1rem;
    }
    .om-room-narr {
        margin-bottom: 1rem;
    }
    .om-room-narr label {
        display: block;
        font-size: .75rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: .3rem;
        letter-spacing: -.005em;
    }
    .om-room-narr .om-room-narr-note {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: .3rem;
    }
    .om-room-narr .om-tbc-marker {
        font-size: .68rem;
        color: #7E5717;
        font-weight: 700;
        letter-spacing: .05em;
        margin-left: .5rem;
    }

    /* ─── Equipment table (per-room) ────────────────────────────────────── */
    .om-eq-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
    }
    .om-eq-tbl th {
        text-align: left;
        font-size: .65rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--text-muted);
        font-weight: 700;
        padding: .35rem .5rem;
        background: var(--bg-muted, #FBF8EF);
        border-bottom: 1px solid var(--border);
    }
    .om-eq-tbl td {
        padding: .35rem .5rem;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .om-eq-tbl input, .om-eq-tbl textarea {
        width: 100%;
        border: 1px solid transparent;
        background: transparent;
        padding: .3rem .4rem;
        border-radius: 3px;
        font-size: .82rem;
        font-family: inherit;
        color: var(--text);
    }
    .om-eq-tbl input:focus, .om-eq-tbl textarea:focus {
        background: white;
        border-color: var(--border);
        outline: none;
    }
    .om-eq-tbl input.qty {
        width: 3.5rem;
        text-align: center;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 700;
    }
    .om-eq-tbl input.part {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .78rem;
    }
    .om-eq-tbl .del {
        background: transparent;
        border: 0;
        color: var(--text-muted);
        cursor: pointer;
        padding: .3rem .5rem;
        border-radius: 3px;
        font-size: .9rem;
    }
    .om-eq-tbl .del:hover { color: #c0392b; background: #F1D9D2; }
    .om-eq-add {
        background: transparent;
        border: 1px dashed var(--border);
        padding: .4rem .8rem;
        border-radius: 4px;
        font-size: .78rem;
        color: var(--text-muted);
        cursor: pointer;
        margin-top: .5rem;
        font-weight: 500;
    }
    .om-eq-add:hover { border-color: var(--accent, #0F3E36); color: var(--accent, #0F3E36); }

    /* ─── Sticky save footer ────────────────────────────────────────────── */
    .om-save-bar {
        position: sticky;
        bottom: 0;
        background: linear-gradient(180deg, transparent 0%, var(--surface) 30%);
        padding: 1rem 0 0;
        margin-top: 1rem;
        display: flex;
        gap: .5rem;
        align-items: center;
        border-top: 1px solid var(--border);
    }
    .om-save-info {
        font-size: .78rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: .4rem;
    }
    .om-save-info .dot { width: 6px; height: 6px; border-radius: 50%; background: #b57e24; display: inline-block; }

    /* ─── Advanced disclosure ───────────────────────────────────────────── */
    details.om-advanced { padding: 0; }
    details.om-advanced summary {
        cursor: pointer;
        font-weight: 600;
        color: var(--text-muted);
        padding: .5rem 0;
        list-style: none;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: .85rem;
    }
    details.om-advanced summary::-webkit-details-marker { display: none; }
    details.om-advanced summary::before {
        content: "▸";
        display: inline-block;
        transition: transform .15s;
        font-size: .7rem;
    }
    details.om-advanced[open] summary::before { transform: rotate(90deg); }
    .om-advanced-warning {
        background: #F4E7CE;
        border: 1px solid #E8D5B0;
        border-radius: 4px;
        padding: .55rem .75rem;
        font-size: .78rem;
        color: #7E5717;
        margin: .75rem 0;
    }
</style>
@endpush

@section('content')
<x-edit-action-bar :form-id="'om-manual-edit-form'" :cancel-url="route('om-manuals.index')">
    <x-slot:title>{{ $manual->project_name ?? 'O&M Manual' }}</x-slot:title>
</x-edit-action-bar>

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

    // Section status helpers — populated (✓), TBC placeholder (!), or empty (○)
    $sectionStatus = function (string $value): array {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['label' => '○', 'class' => 'empty'];
        }
        if (str_contains($trimmed, '[TBC]')) {
            return ['label' => 'TBC', 'class' => 'tbc'];
        }
        return ['label' => '✓', 'class' => 'done'];
    };

    $projectDetailsStatus = ($projName && $projRef && $projClient && $projSite)
        ? ['label' => '✓', 'class' => 'done']
        : ['label' => '○', 'class' => 'empty'];
    $handoverStatus = $sectionStatus($handover);
    $scopeStatus    = $sectionStatus($scope);
    $notesStatus    = $sectionStatus($notes);
@endphp

<div class="om-layout" style="padding: 1rem 0 4rem;">

    {{-- ══════════════════════════════════════════════════════════════════════
         SIDEBAR RAIL — sticky, jumps to sections
         ══════════════════════════════════════════════════════════════════════ --}}
    <aside class="om-rail" aria-label="O&M sections">
        <div class="om-rail-h">Handover doc</div>
        <a href="#s-project" class="om-rail-item">
            <span class="om-rail-name">Project details</span>
            <span class="om-rail-status {{ $projectDetailsStatus['class'] }}">{{ $projectDetailsStatus['label'] }}</span>
        </a>
        <a href="#s-handover" class="om-rail-item">
            <span class="om-rail-name">Handover date</span>
            <span class="om-rail-status {{ $handoverStatus['class'] }}">{{ $handoverStatus['label'] }}</span>
        </a>
        <a href="#s-scope" class="om-rail-item">
            <span class="om-rail-name">Scope of works</span>
            <span class="om-rail-status {{ $scopeStatus['class'] }}">{{ $scopeStatus['label'] }}</span>
        </a>
        <a href="#s-notes" class="om-rail-item">
            <span class="om-rail-name">Site &amp; handover notes</span>
            <span class="om-rail-status {{ $notesStatus['class'] }}">{{ $notesStatus['label'] }}</span>
        </a>

        <div class="om-rail-h" style="padding-top: .75rem;">Rooms &amp; equipment</div>
        @forelse ($rooms as $i => $room)
            @php
                $rname    = trim((string) ($room['name'] ?? "Room " . ($i + 1)));
                $rnarr    = trim((string) ($room['narrative'] ?? $room['description'] ?? ''));
                $rstatus  = $sectionStatus($rnarr);
                $eqCount  = is_array($room['equipment'] ?? null) ? count($room['equipment']) : 0;
            @endphp
            <a href="#s-room-{{ $i }}" class="om-rail-item">
                <span class="om-rail-name">{{ $rname }}</span>
                <span class="om-rail-status {{ $rstatus['class'] }}">
                    @if ($rstatus['class'] === 'done')
                        {{ $eqCount }}
                    @else
                        {{ $rstatus['label'] }}
                    @endif
                </span>
            </a>
        @empty
            <div style="padding: .5rem .6rem; font-size: .75rem; color: var(--text-muted); font-style: italic;">
                No rooms yet
            </div>
        @endforelse

        <div class="om-rail-h" style="padding-top: .75rem;">Advanced</div>
        <a href="#s-advanced" class="om-rail-item">
            <span class="om-rail-name">Raw JSON</span>
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
                type="om"
                :id="$manual->id"
                label="O&M Manual"
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

            {{-- ══════════════════════════════════════════════════════════════
                 Project details
                 ══════════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-project">
                <h2>
                    Project details
                    <span class="om-badge {{ $projectDetailsStatus['class'] }}">{{ $projectDetailsStatus['class'] === 'done' ? 'Complete' : 'Needs data' }}</span>
                </h2>
                <p class="desc">Renders on the cover sheet + page 1 of the PDF. Auto-populated from the linked project; overwrite here if the client needs a different name.</p>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="om_project_name">Project name</label>
                        <input id="om_project_name" name="project_name" type="text" class="form-control"
                               value="{{ old('project_name', $projName) }}"
                               placeholder="e.g. 21CQ29531-05-OPS — Tilda" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_project_ref">Project ref</label>
                        <input id="om_project_ref" name="project_ref" type="text" class="form-control"
                               value="{{ old('project_ref', $projRef) }}" placeholder="e.g. 21CQ29531-05-OPS" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_client_name">Client</label>
                        <input id="om_client_name" name="client_name" type="text" class="form-control"
                               value="{{ old('client_name', $projClient) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_site_address">Site address</label>
                        <input id="om_site_address" name="site_address" type="text" class="form-control"
                               value="{{ old('site_address', $projSite) }}" />
                    </div>
                </div>
            </section>

            {{-- ══════════════════════════════════════════════════════════════
                 Handover date
                 ══════════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-handover">
                <h2>
                    Handover date
                    <span class="om-badge {{ $handoverStatus['class'] }}">
                        @if ($handoverStatus['class'] === 'done')Set
                        @elseif ($handoverStatus['class'] === 'tbc')[TBC]
                        @else Missing @endif
                    </span>
                </h2>
                <p class="desc">Gate for O&amp;M generation. If unknown, leave the [TBC] placeholder and it'll render as a placeholder in the PDF.</p>
                <div class="form-group" style="max-width: 20rem;">
                    <label class="form-label" for="om_handover">Date (or [TBC] placeholder)</label>
                    <input id="om_handover" name="handover_date" type="text" class="form-control"
                           value="{{ old('handover_date', $handover) }}"
                           placeholder="e.g. 15 Aug 2026" />
                </div>
            </section>

            {{-- ══════════════════════════════════════════════════════════════
                 Scope of works (AI prose, editable)
                 ══════════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-scope">
                <h2>
                    Scope of works
                    <span class="om-badge ai">AI drafted</span>
                    @if ($scopeStatus['class'] === 'tbc')<span class="om-badge tbc">[TBC]</span>@endif
                </h2>
                <p class="desc">Renders on page 3 of the client-facing PDF. Written by AI on generation, edited by you here. <strong>[TBC]</strong> marks awaiting client sign-off.</p>
                <textarea name="scope_of_works" id="om_scope" rows="16" data-optional
                          class="form-control" style="font-size: .88rem; line-height: 1.55; width: 100%;"
                          placeholder="The works detailed within this document relate to project reference…">{{ old('scope_of_works', $scope) }}</textarea>
            </section>

            {{-- ══════════════════════════════════════════════════════════════
                 Site & handover notes
                 ══════════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-notes">
                <h2>
                    Site &amp; handover notes
                    <span class="om-badge {{ $notesStatus['class'] }}">
                        @if ($notesStatus['class'] === 'done')Populated
                        @elseif ($notesStatus['class'] === 'tbc')[TBC]
                        @else Optional @endif
                    </span>
                </h2>
                <p class="desc">Free-form notes appended to the manual. Access codes, contact hand-offs, oddities the engineer flagged — anything the standard sections don't cover.</p>
                <textarea name="notes" id="om_notes" rows="5" data-optional
                          class="form-control" style="font-size: .88rem; line-height: 1.5; width: 100%;"
                          placeholder="e.g. Comms room accessed via reception — engineer needs to sign in with security…">{{ old('notes', $notes) }}</textarea>
            </section>

            {{-- ══════════════════════════════════════════════════════════════
                 Rooms — each editable with narrative + equipment
                 ══════════════════════════════════════════════════════════════ --}}
            <div style="padding: .5rem 0 .25rem;">
                <h2 style="font-size: 1.05rem; font-weight: 700; margin: 0 0 .3rem; color: var(--text);">Rooms &amp; equipment</h2>
                <p style="font-size: .82rem; color: var(--text-muted); margin: 0 0 .75rem;">
                    Auto-populated from the linked project's survey + quote data.
                    Each room's narrative is AI-drafted; you can overwrite it here.
                    Equipment rows are the definitive list for this room in the O&amp;M — add / remove / edit.
                </p>
            </div>

            @foreach ($rooms as $i => $room)
                @php
                    $rname       = trim((string) ($room['name'] ?? ''));
                    $rfloor      = trim((string) ($room['floor'] ?? ''));
                    $rdrawing    = trim((string) ($room['drawing_ref'] ?? ''));
                    $rnarr       = trim((string) ($room['narrative'] ?? $room['description'] ?? ''));
                    $rIsTbc      = str_contains($rnarr, '[TBC]');
                    $rEquipment  = is_array($room['equipment'] ?? null) ? array_values($room['equipment']) : [];
                @endphp
                <section class="om-room" id="s-room-{{ $i }}">
                    <div class="om-room-h">
                        <div class="om-room-num">{{ $i + 1 }}</div>
                        <h3 class="om-room-title">
                            {{ $rname !== '' ? $rname : 'Room ' . ($i + 1) }}
                        </h3>
                        @if ($rIsTbc)
                            <span class="om-badge tbc">[TBC] narrative</span>
                        @elseif ($rnarr !== '')
                            <span class="om-badge ai">AI drafted</span>
                        @endif
                    </div>

                    <div class="om-room-fields">
                        <div class="form-group">
                            <label class="form-label" for="room_name_{{ $i }}">Room name</label>
                            <input id="room_name_{{ $i }}" name="rooms[{{ $i }}][name]" type="text" class="form-control"
                                   value="{{ old("rooms.$i.name", $rname) }}" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="room_floor_{{ $i }}">Floor</label>
                            <input id="room_floor_{{ $i }}" name="rooms[{{ $i }}][floor]" type="text" class="form-control"
                                   value="{{ old("rooms.$i.floor", $rfloor) }}"
                                   placeholder="e.g. Ground, 1st" />
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="room_drawing_{{ $i }}">Drawing ref</label>
                            <input id="room_drawing_{{ $i }}" name="rooms[{{ $i }}][drawing_ref]" type="text" class="form-control"
                                   value="{{ old("rooms.$i.drawing_ref", $rdrawing) }}"
                                   placeholder="e.g. Appendix A-01" />
                        </div>
                    </div>

                    <div class="om-room-narr">
                        <label for="room_narr_{{ $i }}">
                            Narrative
                            @if ($rIsTbc)<span class="om-tbc-marker">[TBC]</span>@endif
                        </label>
                        <textarea id="room_narr_{{ $i }}" name="rooms[{{ $i }}][narrative]" rows="4" data-optional
                                  class="form-control" style="font-size: .85rem; line-height: 1.5; width: 100%;"
                                  placeholder="Describe the room's AV installation — auto-drafted by AI on regenerate…">{{ old("rooms.$i.narrative", $rnarr) }}</textarea>
                        <p class="om-room-narr-note">
                            Renders in the O&amp;M as this room's introduction. Regenerating overwrites this with a fresh AI draft — save edits before regenerating if you want to keep them.
                        </p>
                    </div>

                    <div x-data="{ items: {{ json_encode(array_map(fn($eq) => [
                                'qty'          => (int) ($eq['qty'] ?? $eq['quantity'] ?? 1),
                                'part_number'  => (string) ($eq['part_number'] ?? $eq['part_no'] ?? ''),
                                'description'  => (string) ($eq['description'] ?? $eq['name'] ?? $eq['item'] ?? ''),
                                'manufacturer' => (string) ($eq['manufacturer'] ?? $eq['make'] ?? ''),
                            ], $rEquipment)) }} }">
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
                                        <td>
                                            <input type="number" min="1" step="1" class="qty" data-optional
                                                   :name="`rooms[{{ $i }}][equipment][${idx}][qty]`"
                                                   x-model="item.qty" />
                                        </td>
                                        <td>
                                            <input type="text" class="part" data-optional
                                                   :name="`rooms[{{ $i }}][equipment][${idx}][part_number]`"
                                                   x-model="item.part_number" placeholder="e.g. UC-MMX30-Z" />
                                        </td>
                                        <td>
                                            <input type="text" data-optional
                                                   :name="`rooms[{{ $i }}][equipment][${idx}][description]`"
                                                   x-model="item.description"
                                                   placeholder="e.g. Crestron Small Room System" />
                                        </td>
                                        <td>
                                            <input type="text" data-optional
                                                   :name="`rooms[{{ $i }}][equipment][${idx}][manufacturer]`"
                                                   x-model="item.manufacturer" placeholder="e.g. Crestron" />
                                        </td>
                                        <td>
                                            <button type="button" class="del" @click="items.splice(idx, 1)"
                                                    aria-label="Remove item">×</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="items.length === 0">
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 1rem;">
                                        No equipment yet — add the first row.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" class="om-eq-add"
                                @click="items.push({ qty: 1, part_number: '', description: '', manufacturer: '' })">
                            + Add equipment row
                        </button>
                    </div>
                </section>
            @endforeach

            @if (empty($rooms))
                <div class="om-section" style="text-align: center; padding: 2rem 1rem;">
                    <p style="color: var(--text-muted); font-size: .85rem; margin: 0;">
                        No rooms have been added to this O&amp;M yet.
                        @if ($manual->project_id)
                            Rooms are auto-populated from the linked project's survey + quote data.
                            <br><a href="{{ route('projects.show', $manual->project_id) }}" style="color: var(--accent); text-decoration: underline;">Open the project workspace</a>
                            to add rooms via the survey, then regenerate the manual.
                        @endif
                    </p>
                </div>
            @endif

            {{-- ══════════════════════════════════════════════════════════════
                 Advanced — raw JSON escape hatch
                 ══════════════════════════════════════════════════════════════ --}}
            <section class="om-section" id="s-advanced">
                <details class="om-advanced">
                    <summary>Advanced — edit raw JSON payload</summary>
                    <div style="padding-top: .5rem;">
                        <div class="om-advanced-warning">
                            <strong>⚠ Advanced.</strong> Editing raw JSON bypasses all typed fields above.
                            A missing comma or bracket will fail validation and reject your save.
                            Only use this if the structured fields don't cover what you need.
                        </div>
                        <label style="display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; font-size: .8rem; color: var(--text-muted);">
                            <input type="checkbox" name="use_raw_json" value="1" />
                            Use the raw JSON below instead of the structured fields
                        </label>
                        <textarea id="extracted_json" name="extracted_json" rows="16" data-optional
                                  class="form-control" style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .76rem;"
                        >{{ json_encode($manual->extracted_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                        @error('extracted_json')
                            <p style="color: #c0392b; font-size: .8rem; margin-top: .35rem;">{{ $message }}</p>
                        @enderror
                    </div>
                </details>
            </section>

            <div class="om-save-bar">
                <span class="om-save-info"><span class="dot"></span>Changes to structured fields save on click; raw JSON path requires the checkbox above.</span>
                <div style="margin-left: auto; display: flex; gap: .5rem;">
                    <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-teal btn-sm">Save changes</button>
                </div>
            </div>
        </form>

        {{-- ══════════════════════════════════════════════════════════════════
             Generate O&M
             ══════════════════════════════════════════════════════════════════ --}}
        @if ($manual->status !== \App\Models\OmManual::STATUS_GENERATING)
            <div class="card" style="padding: 1.25rem; margin-top: 1.5rem;">
                <h2 style="font-size: 1rem; font-weight: 700; margin-bottom: .5rem;">Generate O&amp;M manual</h2>
                <p style="font-size: .82rem; color: var(--text-muted); margin-bottom: .75rem;">
                    Kicks off a queued build. AI regenerates the scope + per-room narrative from your saved data.
                    You'll receive an email when the DOCX is ready.
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
// Highlight the active section in the sidebar rail as you scroll.
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
        // Find the highest-visible section (closest to top of viewport).
        const visible = entries.filter(e => e.isIntersecting)
            .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length) setActive(visible[0].target.id);
    }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });

    targets.forEach(t => obs.observe(t));
})();
</script>
@endsection

@extends('layouts.app')

@section('title', 'Review O&M — ' . ($manual->project_name ?? 'O&M Manual'))

@push('styles')
<style>
    .om-edit-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        color: var(--text);
        letter-spacing: -.015em;
        line-height: 1.2;
    }
    .om-edit-title em {
        font-style: normal;
        font-weight: 500;
        color: var(--text-muted);
    }
    .om-edit-eyebrow {
        font-size: .7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: var(--text-muted);
        margin-bottom: .25rem;
    }
    .om-meta-row {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1rem;
        font-size: .9rem;
        flex-wrap: wrap;
    }
    .om-meta-row strong { color: var(--text-muted); font-weight: 600; margin-right: .35rem; }
    .om-room-card {
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--surface);
        padding: 1rem 1.1rem;
        margin-bottom: .6rem;
    }
    .om-room-card h4 {
        margin: 0 0 .3rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 1rem;
    }
    .om-room-card h4 .om-room-count {
        font-size: .75rem;
        font-weight: 500;
        color: var(--text-muted);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        letter-spacing: -.005em;
    }
    .om-room-card .om-room-narr {
        font-size: .85rem;
        color: var(--text-muted);
        line-height: 1.5;
        margin: 0 0 .5rem;
    }
    .om-room-card .om-room-narr.tbc { color: #b57e24; font-style: italic; }
    .om-room-eq {
        list-style: none;
        padding: 0;
        margin: .5rem 0 0;
        border-top: 1px dashed var(--border);
    }
    .om-room-eq li {
        padding: .35rem 0;
        display: grid;
        grid-template-columns: 28px 1fr auto;
        gap: .75rem;
        font-size: .82rem;
        border-bottom: 1px dashed var(--border);
    }
    .om-room-eq li:last-child { border-bottom: 0; }
    .om-room-eq .qty {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 700;
        color: var(--text);
    }
    .om-room-eq .desc { color: var(--text); }
    .om-room-eq .mfg {
        color: var(--text-muted);
        font-size: .78rem;
        white-space: nowrap;
    }
    .om-advanced-summary {
        cursor: pointer;
        font-weight: 600;
        color: var(--text-muted);
        padding: .5rem 0;
        list-style: none;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: .88rem;
    }
    .om-advanced-summary::-webkit-details-marker { display: none; }
    .om-advanced-summary::before {
        content: "▸";
        display: inline-block;
        transition: transform .15s;
        font-size: .7rem;
    }
    details[open] .om-advanced-summary::before {
        transform: rotate(90deg);
    }
    .om-advanced-warning {
        background: #f4e7ce;
        border: 1px solid #e8d5b0;
        border-radius: var(--radius-sm);
        padding: .55rem .75rem;
        font-size: .8rem;
        color: #7e5717;
        margin-bottom: .75rem;
    }
    .om-note {
        font-size: .78rem;
        color: var(--text-muted);
        line-height: 1.45;
    }
</style>
@endpush

@section('content')
<x-edit-action-bar :form-id="'om-manual-edit-form'" :cancel-url="route('om-manuals.index')">
    <x-slot:title>Edit O&amp;M — {{ $manual->project_name ?? $manual->title ?? 'Untitled' }}</x-slot:title>
</x-edit-action-bar>

<div class="container" style="max-width: 960px; margin: 0 auto;">
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap;">
        <div>
            <div class="om-edit-eyebrow">Operations &amp; Maintenance Manual</div>
            <h1 class="om-edit-title">
                Review O&amp;M
                @if ($manual->project_name)<em>— {{ $manual->project_name }}</em>@endif
            </h1>
        </div>
        <div style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;">
            @if ($manual->project_id)
                <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="btn btn-teal btn-sm">📋 Manage asset data</a>
            @endif
            <a href="{{ route('documents.revisions.view', ['type' => 'om', 'id' => $manual->id]) }}" class="btn btn-outline btn-sm">↻ History</a>
            <x-document-edit-drawer
                type="om"
                :id="$manual->id"
                label="O&M Manual"
                :visible="in_array($manual->status, [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])" />
            <a href="{{ route('om-manuals.index') }}" class="btn btn-outline btn-sm">← Back</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success" style="margin-bottom: 1rem;">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" style="margin-bottom: 1rem;">{{ session('error') }}</div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════════════
         Meta strip — status, client, site, ref
         ══════════════════════════════════════════════════════════════════════ --}}
    <div class="card" style="padding: 1.1rem 1.25rem; margin-bottom: 1.25rem;">
        <div class="om-meta-row" style="margin-bottom: 0;">
            <div><strong>Status</strong><span class="badge {{ $manual->statusBadgeClass() }}">{{ $manual->statusLabel() }}</span></div>
            <div><strong>Client</strong>{{ $manual->client_name ?? '—' }}</div>
            <div><strong>Site</strong>{{ $manual->site_address ?? '—' }}</div>
            @if ($manual->project_ref)
                <div><strong>Ref</strong>{{ $manual->project_ref }}</div>
            @endif
        </div>
    </div>

    @php
        $data       = is_array($manual->extracted_data) ? $manual->extracted_data : [];
        $project    = is_array($data['project'] ?? null) ? $data['project'] : [];
        $projName   = $data['project_name']   ?? $project['name']   ?? $manual->project_name;
        $projRef    = $data['project_ref']    ?? $project['ref']    ?? $manual->project_ref;
        $projClient = $data['client_name']    ?? $project['client'] ?? $manual->client_name;
        $projSite   = $data['site_address']   ?? $project['site']   ?? $manual->site_address;
        $notes      = (string) ($data['notes'] ?? '');
        $scope      = (string) ($data['scope_of_works'] ?? '');
        $rooms      = is_array($data['rooms'] ?? null) ? $data['rooms'] : [];
    @endphp

    <form method="POST" action="{{ route('om-manuals.update', $manual) }}" id="om-manual-edit-form">
        @csrf
        @method('PATCH')

        {{-- ══════════════════════════════════════════════════════════════════
             Project details — typed fields
             ══════════════════════════════════════════════════════════════════ --}}
        <div class="form-section">
            <div class="form-section__header">
                <h2 class="section-heading">Project details</h2>
            </div>
            <div class="form-section__body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="om_project_name">Project name</label>
                        <input id="om_project_name" name="project_name" type="text"
                               class="form-control" value="{{ old('project_name', $projName) }}"
                               placeholder="e.g. 21CQ29531-05-OPS — Tilda" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_project_ref">Project ref</label>
                        <input id="om_project_ref" name="project_ref" type="text"
                               class="form-control" value="{{ old('project_ref', $projRef) }}"
                               placeholder="e.g. 21CQ29531-05-OPS" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_client_name">Client</label>
                        <input id="om_client_name" name="client_name" type="text"
                               class="form-control" value="{{ old('client_name', $projClient) }}" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="om_site_address">Site address</label>
                        <input id="om_site_address" name="site_address" type="text"
                               class="form-control" value="{{ old('site_address', $projSite) }}" />
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════
             Scope of works — AI-drafted prose, human-editable
             ══════════════════════════════════════════════════════════════════ --}}
        <div class="form-section">
            <div class="form-section__header">
                <h2 class="section-heading">Scope of works</h2>
            </div>
            <div class="form-section__body">
                <p class="om-note" style="margin-bottom: .5rem;">
                    Renders on page 3 of the client-facing PDF. Written by AI, edited by you.
                    <strong>[TBC]</strong> marks awaiting client sign-off.
                </p>
                <textarea name="scope_of_works" id="om_scope" rows="10" data-optional
                          class="form-control" style="font-size: .88rem; line-height: 1.55; width: 100%;"
                          placeholder="The works detailed within this document relate to project reference…">{{ old('scope_of_works', $scope) }}</textarea>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════
             Site notes — free-form for anything the AI hasn't captured
             ══════════════════════════════════════════════════════════════════ --}}
        <div class="form-section">
            <div class="form-section__header">
                <h2 class="section-heading">Site &amp; handover notes</h2>
            </div>
            <div class="form-section__body">
                <p class="om-note" style="margin-bottom: .5rem;">
                    Free-form notes appended to the manual. Access codes, contact hand-offs, oddities
                    the engineer flagged — anything the standard sections don't cover.
                </p>
                <textarea name="notes" id="om_notes" rows="4" data-optional
                          class="form-control" style="font-size: .88rem; line-height: 1.5; width: 100%;"
                          placeholder="e.g. Comms room accessed via reception — engineer needs to sign in with security…">{{ old('notes', $notes) }}</textarea>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════
             Rooms preview — read-only summary of what's in the manual.
             Per-room editing lives in the survey / project-data flow, not here.
             ══════════════════════════════════════════════════════════════════ --}}
        <div class="form-section">
            <div class="form-section__header">
                <h2 class="section-heading">Rooms &amp; equipment</h2>
            </div>
            <div class="form-section__body">
                @if (empty($rooms))
                    <p class="om-note" style="padding: 1rem; background: var(--bg-muted, #faf7ee); border-radius: var(--radius-sm);">
                        No rooms extracted yet. Rooms are populated by the O&amp;M generator from the
                        linked project's survey + quote data — not edited directly here.
                    </p>
                @else
                    <p class="om-note" style="margin-bottom: .75rem;">
                        {{ count($rooms) }} {{ Str::plural('room', count($rooms)) }} · pulled from the linked project's survey +
                        quote data. Room and equipment editing lives in the
                        @if ($manual->project_id)
                            <a href="{{ route('projects.show', $manual->project_id) }}" style="color: var(--accent); text-decoration: underline;">project workspace</a>
                        @else
                            project workspace
                        @endif
                        — regenerate the manual after changes to pull them in.
                    </p>

                    @foreach ($rooms as $room)
                        @php
                            $roomName    = trim((string) ($room['name'] ?? 'Unnamed room'));
                            $narrative   = trim((string) ($room['narrative'] ?? $room['description'] ?? ''));
                            $isTbc       = str_contains($narrative, '[TBC]');
                            $equipment   = is_array($room['equipment'] ?? null) ? $room['equipment'] : [];
                            $eqCount     = count($equipment);
                        @endphp
                        <div class="om-room-card">
                            <h4>
                                <span>{{ $roomName }}</span>
                                <span class="om-room-count">{{ $eqCount }} {{ Str::plural('item', $eqCount) }}</span>
                            </h4>
                            @if ($narrative !== '')
                                <p class="om-room-narr {{ $isTbc ? 'tbc' : '' }}">
                                    {{ Str::limit($narrative, 320) }}
                                </p>
                            @endif
                            @if ($eqCount > 0)
                                <ul class="om-room-eq">
                                    @foreach (array_slice($equipment, 0, 6) as $eq)
                                        @php
                                            $qty  = $eq['quantity'] ?? $eq['qty'] ?? 1;
                                            $desc = trim((string) ($eq['description'] ?? $eq['item'] ?? $eq['name'] ?? ''));
                                            $mfg  = trim((string) ($eq['manufacturer'] ?? $eq['make'] ?? ''));
                                        @endphp
                                        <li>
                                            <span class="qty">{{ $qty }}×</span>
                                            <span class="desc">{{ $desc !== '' ? Str::limit($desc, 120) : '—' }}</span>
                                            <span class="mfg">{{ $mfg }}</span>
                                        </li>
                                    @endforeach
                                    @if ($eqCount > 6)
                                        <li style="border-bottom: 0; color: var(--text-muted); font-size: .78rem;">
                                            <span></span>
                                            <span>+ {{ $eqCount - 6 }} more — see raw JSON below</span>
                                            <span></span>
                                        </li>
                                    @endif
                                </ul>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════════
             Advanced — raw JSON escape hatch. Off by default. If the user
             toggles it and edits, controller uses the raw JSON instead of
             the typed fields (see extracted_json_override checkbox).
             ══════════════════════════════════════════════════════════════════ --}}
        <div class="form-section">
            <details>
                <summary class="om-advanced-summary">
                    Advanced — edit raw JSON payload
                </summary>
                <div style="padding: 1rem 1.25rem 1.25rem;">
                    <div class="om-advanced-warning">
                        <strong>⚠ Advanced.</strong> Editing raw JSON bypasses the typed fields above.
                        A missing comma or bracket will fail validation and reject your save. Only use
                        this if the structured fields don't cover what you need.
                    </div>
                    <label style="display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; font-size: .82rem; color: var(--text-muted);">
                        <input type="checkbox" name="use_raw_json" value="1" />
                        Use the raw JSON below instead of the typed fields
                    </label>
                    <textarea id="extracted_json" name="extracted_json" rows="16" data-optional
                              class="form-control" style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .78rem;"
                    >{{ json_encode($manual->extracted_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                    @error('extracted_json')
                        <p style="color: #c0392b; font-size: .82rem; margin-top: .35rem;">{{ $message }}</p>
                    @enderror
                </div>
            </details>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
            <button type="submit" class="btn btn-teal">Save changes</button>
        </div>
    </form>

    {{-- Generate O&M --}}
    @if ($manual->status !== \App\Models\OmManual::STATUS_GENERATING)
        <div class="card" style="padding: 1.25rem; margin-top: 1.25rem;">
            <h2 style="font-size: 1rem; font-weight: 700; margin-bottom: .75rem;">Generate O&amp;M manual</h2>
            <p class="om-note" style="margin-bottom: .75rem;">
                Kicks off the queued build. You'll receive an email when the DOCX is ready.
            </p>
            <form method="POST" action="{{ route('om-manuals.generate', $manual) }}">
                @csrf
                <button type="submit" class="btn btn-teal">Generate document</button>
            </form>
        </div>
    @else
        <div class="card" style="padding: 1.25rem; margin-top: 1.25rem;">
            <p style="color: #888; font-style: italic; margin: 0;">Generation in progress — please wait…</p>
        </div>
    @endif
</div>
@endsection

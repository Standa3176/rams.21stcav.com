{{--
    The Returned tab (Phase 46.1, Plan 46.1-03, D-01) — what the engineer
    actually sent back from site.

    Built because a PM could accept a visit without having seen a single thing
    that came back: on 2026-09-21 the cockpit resolved none of the six kinds of
    evidence this app captures. This is criterion 1 of the phase and the reason
    it exists.

    ── WHERE IT APPEARS ────────────────────────────────────────────────────

    The presence rule is enforced ONE level up, in panel.blade.php, and stated
    there: the tab renders iff the open drawer holds at least one visit whose
    `source_type` is set. This file therefore never asks whether it should be
    on screen — it is handed only the SOURCED visits and draws them.

    ── THE ORDER, MIRRORING SCC'S PMV REVIEW PANEL ─────────────────────────

    state line → hand-off slot → per-room answers → contact sheet → serials →
    client sign-off → review-controls slot.

    BOTH SLOTS WERE DELIBERATELY EMPTY WHEN 46.1-03 SHIPPED THIS FILE. The
    hand-off link was Plan 46.1-04's and the four review controls are Plan
    46.1-05's. Each is a NAMED HOLE so that neither later plan has to
    restructure this file to fill it.

    PLAN 46.1-04 FILLED THE FIRST: one anchor to the per-visit photo archive,
    rendered only for a visit that has photos, and sitting OUTSIDE `.cav-visit`
    so it does not spend one of VL-11's four in-row controls. The reasoning
    lives at the render site, not here.

    PLAN 46.1-05 HAS NOW FILLED THE SECOND, and it is the reason this phase
    exists (D-02). The four review acts — Accept, Send back, Add note and Raise
    a snag — no longer render on Overview at all. They render HERE, at the foot
    of the card, BENEATH the photos, the room answers, the serials and the
    client's signature, because A PM SHOULD NOT BE ABLE TO ACCEPT A VISIT
    WITHOUT HAVING LOOKED AT IT. Pressing Accept now means having scrolled past
    what came back from site.

    This file is the ONLY caller that passes `controls`, and `visit-row`
    defaults it to false.

    ── "SIMPLE" IS AN ACCEPTANCE CRITERION (RV-08), NOT A PREFERENCE ────────

    The user rejected an earlier design with "I want to make it look simple and
    less scary", and repeated it for this phase. A gallery plus four controls
    is exactly how a calm page becomes a control panel, so this file draws
    NOTHING PER PHOTO: no lightbox, no pager, no delete, no caption editor, no
    reorder, no per-photo metadata.

    The gallery is a CONTACT SHEET — one uniform grid per bucket, every
    thumbnail a single plain anchor to the full image, opened in a new tab.
    `loading` and `decoding` are plain HTML attributes and are not among the
    fence's nine banned handler attributes; they were checked against that list
    before use.

    THERE IS NO THUMBNAIL CAP. Hiding evidence is a worse failure than a long
    page, and the "scary" complaint was about CONTROLS, not about content.

    Unanswered survey questions are omitted and carried as "{answered} of
    {total}" — two integers rather than twenty empty rows. That omission is
    made in `VisitEvidence`, not here.

    NO NEW CHIP AND NO NEW COLOUR. This reuses `.cav-panel__card`,
    `.cav-panel__card-head` and the `.cav-pnote` shape so it reads as the same
    panel a PM was already looking at, not as a new screen.

    ── READ-ONLY, AND NO JAVASCRIPT ────────────────────────────────────────

    D-04: everything here is read from the engineer's own record at request
    time. It edits nothing, copies nothing and writes nothing — a GET row-count
    test over eleven tables says so. The fence's script-tag ban and all nine
    handler attributes stand; nothing on this tab needed one.

    ── THREE COLUMNS THAT ARE NEVER RENDERED (RV-03) ───────────────────────

    `device_label_photos.captured_by` is NOT a person's name. It is an audit
    field holding a capture IP plus an actor hash, and it has a leak history:
    `2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php` nulled
    every legacy value because the upload controller had been writing the first
    eight hex characters of the worksheet UUID TOKEN into it. On the same
    grounds `worksheet_signoffs.ip_address` and `.user_agent` never render
    either — the sign-off surface is the client's NAME and SIGNATURE IMAGE
    only. None of the three is even a key of what this file receives, because
    `VisitEvidence` excludes them, and nothing here reaches past the presenter
    to a model to get them back.

    ── ESCAPED OUTPUT ONLY ─────────────────────────────────────────────────

    Room names, captions, question text, notes, client names and comments are
    all engineer- or client-entered on an UNAUTHENTICATED token page. Every one
    of them is escaped; there is no unescaped-echo directive anywhere in this
    file, and CockpitPanelTest greps every cockpit view for one.
--}}
@props([
    'project',
    'module',
    'visits'   => null,
    'evidence' => [],
    // Plan 46.1-05 — the disclosure state the four review controls read.
    // COMPARED, never looked up: `action` is resolved by membership in
    // ProjectCockpitController::ACTIONS and `actionVisitId` is cast to int and
    // compared against a row already on this page.
    'action'        => null,
    'actionVisitId' => null,
])

@php
    $visits = $visits ?? collect();

    // The buckets spelled in ENGLISH. `before` / `after` / `label` are ZIP
    // folder names (Plan 46.1-02), never page copy — the two must not drift,
    // so the mapping is written once, here, against VisitEvidence's constants.
    $bucketLabels = [
        \App\Support\Cockpit\VisitEvidence::BUCKET_BEFORE => 'Before (survey)',
        \App\Support\Cockpit\VisitEvidence::BUCKET_AFTER  => 'After (install)',
        \App\Support\Cockpit\VisitEvidence::BUCKET_LABEL  => 'Equipment labels',
    ];
@endphp

@forelse ($visits as $visit)
    @php
        $payload       = $evidence[$visit->id] ?? null;
        $sourceMissing = (bool) ($payload['source_missing'] ?? true);
        $hasAnything   = (bool) ($payload['has_anything'] ?? false);
        $returnedOn    = $payload['returned_at'] ?? null;

        $visitTitle = filled($visit->title) ? $visit->title : 'Visit';
        $visitDate  = $visit->scheduled_date;

        $isFromWorksheet = $visit->source_type === \App\Models\Visit::SOURCE_WORKSHEET;

        // The state line, in words. A visit whose source is gone is not the
        // same sentence as one nobody has touched yet, and a PM has to be able
        // to tell those apart without opening anything.
        $stateLine = match (true) {
            $sourceMissing       => null,
            $returnedOn !== null => 'Returned '.$returnedOn->format('d M Y'),
            $hasAnything         => 'Awaiting the engineer',
            default              => null,
        };
    @endphp

    <div class="cav-panel__card">
        <span class="cav-panel__card-head">{{ $visitTitle }}{{ $visitDate ? ' · '.$visitDate->format('d M Y') : '' }}</span>

        @if ($stateLine !== null)
            <span class="cav-returned__state">{{ $stateLine }}</span>
        @endif

        {{-- THE HAND-OFF (Plan 46.1-04, RV-04, D-03) — the slot 46.1-03 left
             for it, now holding ONE anchor to the per-visit photo archive.
             This is the plan that lifted the fence's `Download` entry, by
             name; every other deferred affordance is still banned.

             IT SITS OUTSIDE `.cav-visit`, AND THAT IS DELIBERATE.
             CockpitVisitActionsTest::countControls() counts `<button` and
             `<a ` INSIDE a visit row, and VL-11's cap of four is measured
             there. A hand-off is not a review control — it is none of D-02's
             four acts — so putting it in the row would spend a quarter of that
             cap on something that does not belong to it. A later tidy-up must
             not move it in.

             `Route::has()` guards the call, on the rule CockpitPanelPresenter
             already follows for every document route: a renamed route degrades
             to NO LINK rather than to a RouteNotFoundException that would blank
             the whole page for a PM who only wanted to read it.

             NOT RENDERED WHEN THE VISIT HAS NO PHOTOS. Offering an archive that
             would contain only a README is a worse answer than the sentence
             "Nothing has come back from site yet." below.

             No `position` on the anchor (the stretched-link trap), no new
             colour, and no new control style — this reuses the existing
             `.cav-visit__control--quiet` language because it is the same
             panel. --}}
        @if ((int) ($payload['photo_count'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('projects.cockpit.visits.photos-zip'))
            <div class="cav-returned__handoff">
                <a class="cav-visit__control cav-visit__control--quiet"
                   href="{{ route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $visit->id]) }}"
                   target="_blank"
                   rel="noopener">Download all photos (ZIP)</a>

                {{-- A PM about to drop this into Bitrix should know the shape
                     of the archive before they open it. --}}
                <span class="cav-returned__meta">Grouped by room, into before / after / label folders.</span>
            </div>
        @endif

        @if ($sourceMissing)
            <p class="cav-returned__empty">The visit is recorded; the evidence behind it could not be read.</p>
        @elseif (! $hasAnything)
            <p class="cav-returned__empty">Nothing has come back from site yet.</p>
        @else
            {{-- PER-ROOM ANSWERS AND NOTES. Rendered per ROOM, not as one flat
                 list: a survey's whole value is that a fact belongs to a room,
                 and flattening throws exactly that away. --}}
            @foreach ($payload['rooms'] as $room)
                <div class="cav-returned__room">
                    <span class="cav-returned__room-name">{{ $room['name'] }}</span>

                    @if ($room['answers'] !== [])
                        <dl class="cav-returned__answers">
                            @foreach ($room['answers'] as $answer)
                                @php
                                    // `answer` is an enum of yes/no/other. When
                                    // it reads `other` the engineer's own words
                                    // are in `other_text`, and those words are
                                    // the answer — printing the token beside
                                    // them says nothing a PM can use.
                                    $value = ($answer['answer'] === 'other' && filled($answer['other_text'] ?? null))
                                        ? $answer['other_text']
                                        : ucfirst($answer['answer']);
                                @endphp

                                <dt class="cav-returned__q">{{ $answer['question'] }}</dt>
                                <dd class="cav-returned__a">{{ $value }}</dd>
                            @endforeach
                        </dl>
                    @endif

                    @if ($room['notes'] !== null)
                        <p class="cav-returned__note">{{ $room['notes'] }}</p>
                    @endif

                    @if ($room['access_notes'] !== null)
                        <p class="cav-returned__note">Access: {{ $room['access_notes'] }}</p>
                    @endif

                    {{-- Two integers instead of twenty empty rows. --}}
                    <span class="cav-returned__meta">{{ $room['answered'] }} of {{ $room['total'] }} questions answered</span>
                </div>
            @endforeach

            {{-- THE CONTACT SHEET. One uniform grid per bucket. Each thumbnail
                 is ONE plain anchor to the full image and nothing else. --}}
            @foreach ($payload['photos_by_bucket'] as $bucket => $photos)
                <span class="cav-returned__head">{{ $bucketLabels[$bucket] ?? $bucket }}</span>

                <div class="cav-returned__sheet">
                    @foreach ($photos as $photo)
                        @php
                            $alt = filled($photo['caption'])
                                ? $photo['caption']
                                : trim(($photo['room'] !== '' ? $photo['room'].' — ' : '').($bucketLabels[$bucket] ?? $bucket));
                        @endphp

                        <a class="cav-returned__thumb" href="{{ $photo['url'] }}" target="_blank" rel="noopener">
                            <img src="{{ $photo['url'] }}" alt="{{ $alt }}" loading="lazy" decoding="async">
                        </a>
                    @endforeach
                </div>
            @endforeach

            {{-- SERIALS ARE A LIST, NOT THUMBNAILS (D-05). They are the thing
                 most likely wanted in Bitrix and the office cannot see them
                 anywhere else today, so they get their own line each rather
                 than being buried in the gallery above.

                 `captured_by` IS NOT RENDERED and is not even a key here —
                 see the RV-03 block in this file's docblock. Do not restore
                 it. --}}
            @if ($payload['serials'] !== [])
                <span class="cav-returned__head">Serials captured</span>

                <ul class="cav-returned__serials">
                    @foreach ($payload['serials'] as $serial)
                        <li class="cav-returned__serial">
                            <span class="cav-returned__serial-where">{{ $serial['room'] }}@if ($serial['device'] !== '') · {{ $serial['device'] }}@endif</span>

                            {{-- A serial still unread says so, rather than
                                 rendering an empty cell a PM has to interpret. --}}
                            <span class="cav-returned__serial-value">{{ $serial['serial'] ?? 'Serial not read yet' }}</span>

                            @if ($serial['captured_at'] !== null)
                                <span class="cav-returned__meta">{{ $serial['captured_at']->format('d M Y') }}</span>
                            @endif

                            <a class="cav-returned__serial-link" href="{{ $serial['url'] }}" target="_blank" rel="noopener">Label photo</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- CLIENT SIGN-OFF — the NAME and the SIGNATURE IMAGE. Never an
                 address of origin and never a client agent. --}}
            @if ($payload['signoff'] !== null)
                <span class="cav-returned__head">Client sign-off</span>

                <div class="cav-returned__signoff">
                    <span class="cav-returned__client">{{ $payload['signoff']['client_name'] }}</span>

                    @if ($payload['signoff']['signature_data_uri'] !== null)
                        <img class="cav-returned__signature"
                             src="{{ $payload['signoff']['signature_data_uri'] }}"
                             alt="Signature captured on site for {{ $payload['signoff']['client_name'] }}">
                    @endif

                    @if (filled($payload['signoff']['comments']))
                        <p class="cav-returned__note">{{ $payload['signoff']['comments'] }}</p>
                    @endif

                    @if ($payload['signoff']['signed_at'] !== null)
                        <span class="cav-returned__meta">Signed {{ $payload['signoff']['signed_at']->format('d M Y') }}</span>
                    @endif
                </div>
            @elseif ($isFromWorksheet)
                <p class="cav-returned__empty">The engineer returned this visit without a client sign-off.</p>
            @endif
        @endif

        {{-- THE REVIEW CONTROLS (Plan 46.1-05, RV-05/06/07, D-02) — the slot
             46.1-03 left, now holding the four acts MOVED out of Overview.

             THE COMPONENT IS UNCHANGED IN WHO IT OFFERS WHAT. Every gate it
             already had still decides: a reconstructed visit offers ZERO even
             though its derived state reads RETURNED (D-06 — 24 backfilled rows
             on live, and none of them is asking a PM to ratify a guess), an
             accepted visit offers only Add note, a sent-back visit offers no
             second send-back, and VL-11's cap of four still binds on the row.
             `controls` changed WHERE they render, not WHO gets them.

             It is the LAST block in the card on purpose: the offer sits after
             the evidence, never above it. --}}
        <x-cockpit.visit-row
            :visit="$visit"
            :project="$project"
            :module="$module"
            :controls="true"
            {{-- Plan 46.1-06 — this component IS the Returned tab, so every
                 act it offers was initiated from it and must come back to it.
                 The value is re-checked against ProjectCockpitController::TABS
                 in visit-row and again in the action controller. --}}
            tab="returned"
            :action="$action"
            :action-visit-id="$actionVisitId" />
    </div>
@empty
    <x-cockpit.hint>{{ $module['title'] }} has no visit with an engineer record behind it.</x-cockpit.hint>
@endforelse

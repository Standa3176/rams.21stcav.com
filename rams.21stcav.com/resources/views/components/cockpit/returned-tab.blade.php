{{--
    The Returned tab body — Phase 47, Plan 47-03 (D-04). Re-surfaces the
    returned-evidence review Phase 46.1 built and Phase 46.2 D-02 unsurfaced,
    against the SAME CSS Phase 46.1 wrote (resources/css/cockpit.css's
    "The Returned tab" block, `.cav-returned__*`) — that block was never
    deleted alongside the Blade, so this file ships NO NEW CSS.

    ── THE CALM REFERENCE ORDER, AND IT IS A CONTRACT ───────────────────────

    Per visit, in this exact order:
      1. a one-line review-state sentence — the SAME copy `visit-row.blade.php`
         already renders for the same fact ("Accepted by … on …", "Sent back
         … — awaiting the engineer", "Awaiting the engineer"). Omitted for a
         reconstructed visit, on the same gate `visit-row.blade.php` uses.
      2. the hand-off link, ONLY when `photo_count > 0` — an empty archive is
         a valid build (VisitPhotoZipBuilder) but offering the link for zero
         photos invites a PM to click something pointless.
      3. per-room cards (survey-sourced only) — name, notes, access notes,
         "N of M questions answered", then each answered question.
      4. the photo gallery, grouped by `VisitEvidence::BUCKETS` order
         (before/after/label) — a plain `<a>` per photo to the inline photo
         route, opened in a new tab. NO lightbox, NO pager, NO per-photo
         caption edit.
      5. the serials list (worksheet-sourced only) — room, device, serial,
         confirmed, captured date, in a plain list, never thumbnails.
      6. the sign-off block (worksheet-sourced only) — client name, signed
         date, comments, the signature image. Read-only.

    A visit with `has_anything: false` renders ONE sentence and stops — no
    empty room cards, no empty gallery heading. `source_missing: true` renders
    ONE sentence saying the record behind the visit could not be read,
    matching `visit-row.blade.php`'s own treatment of a force-deleted source.

    ── RV-03, THE LOAD-BEARING RULE ON THIS FILE SPECIFICALLY ───────────────

    `device_label_photos.captured_by`, `worksheet_signoffs.ip_address` and
    `worksheet_signoffs.user_agent` are NOT keys of anything
    `CockpitEvidencePresenter::evidence()` returns (see that class's own
    docblock). This file reads NOTHING off a raw model — every value below
    comes out of the presenter's array, which cannot carry a key it never
    produced. Do not reach past this array for "just one more field".

    THE CONTROLS NOW RENDER HERE (Plan 47-04, D-03) — beneath every visit's
    evidence, via `<x-cockpit.visit-row controls="true" tab="returned">`. This
    file still draws its OWN read-only sentences above the evidence (the
    review-state line, the chips elsewhere are Overview's) rather than relying
    on `visit-row` for them, because `visit-row` draws the WHOLE row — title,
    date, chips, lock sentence AND the action area — and `controls` is
    deliberately an AND on top of `visit-row`'s own eight gates, never a
    replacement for them (see that component's own docblock). The duplication
    between this file's review-state sentence and `visit-row`'s own is a known
    cosmetic overlap, not a gate difference: both read the SAME `Visit::state()`
    and `isBackfilled()` and can never disagree about WHETHER a control
    renders, only about how many times the same fact is printed. Nothing in
    `visit-row.blade.php`'s internal gates was touched to accommodate this —
    per 47-04's own scope fence, a gate that looks wrong once reachable here is
    a finding to report, not a silent edit.

    EVERY VALUE IS ESCAPED. Nothing here uses the unescaped-output directive.
    No `<select`, no `<script`, none of the nine banned handler attributes.
    NOTHING HERE TAKES ITS OWN `position` — the stretched-link trap is
    prohibited on this tab, same ruling as the CSS block this file renders
    into.
--}}
@props([
    'visits',
    'evidence',
    'project',
    'module',
    // THE TWO VALUES visit-row's ACTION AREA NEEDS (Plan 47-04, D-03). Passed
    // straight through from the controller via panel.blade.php — this file
    // decides no disclosure of its own, exactly as it decides no evidence
    // shape of its own.
    'action'        => null,
    'actionVisitId' => null,
])

@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Visit> $visits */
    /** @var array<int, array<string, mixed>> $evidence */

    // Filtered to visits carrying a non-null entry, newest first — the
    // richest trip to site is the first thing a PM reads.
    $returnedVisits = $visits
        ->filter(fn ($visit): bool => array_key_exists($visit->id, $evidence))
        ->sortByDesc(fn ($visit) => $visit->scheduled_date ?? $visit->created_at)
        ->values();

    $bucketLabels = [
        \App\Support\Cockpit\VisitEvidence::BUCKET_BEFORE => 'Before (survey)',
        \App\Support\Cockpit\VisitEvidence::BUCKET_AFTER  => 'After (install)',
        \App\Support\Cockpit\VisitEvidence::BUCKET_LABEL  => 'Equipment labels',
    ];
@endphp

@if ($returnedVisits->isEmpty())
    <x-cockpit.hint>Nothing has come back from site yet.</x-cockpit.hint>
@endif

@foreach ($returnedVisits as $visit)
    @php
        $item            = $evidence[$visit->id];
        $isReconstructed = $visit->isBackfilled();
        $title           = filled($visit->title) ? $visit->title : 'Visit';
        $acceptedByName  = $visit->accepted_at === null ? null : optional($visit->acceptedBy)->name;
    @endphp

    <div class="cav-panel__card">
        <span class="cav-panel__card-head">{{ $title }}</span>

        {{-- 1. THE REVIEW-STATE SENTENCE — same copy visit-row.blade.php
             already renders for the same fact. Omitted for a reconstructed
             visit, on the same gate that row uses, because nobody performed
             this review. --}}
        @if (! $isReconstructed)
            @if ($visit->accepted_at !== null)
                <span class="cav-returned__state">Accepted by {{ $acceptedByName ?? 'the office' }} on {{ $visit->accepted_at->format('d M Y') }}</span>
            @elseif ($visit->state() === \App\Models\Visit::STATE_SENT_BACK)
                <span class="cav-returned__state">Sent back {{ optional($visit->sent_back_at)->format('d M Y') }} — awaiting the engineer</span>
            @elseif ($visit->isAwaitingReturn())
                <span class="cav-returned__state">Awaiting the engineer</span>
            @endif
        @endif

        @if ($item['source_missing'])
            {{-- Matches visit-row.blade.php's own treatment of a
                 force-deleted source — the visit still happened, there is
                 simply nothing left to show. --}}
            <p class="cav-returned__empty">The visit is recorded; the evidence behind it could not be read.</p>
        @elseif (! $item['has_anything'])
            <p class="cav-returned__empty">Nothing has come back from site yet.</p>
        @else
            {{-- 2. THE HAND-OFF LINK — only when there is something to zip. --}}
            @if ($item['photo_count'] > 0)
                <p class="cav-returned__handoff">
                    <a href="{{ route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $visit]) }}">Download all photos (ZIP)</a>
                    <span class="cav-returned__meta">{{ $item['photo_count'] }} {{ $item['photo_count'] === 1 ? 'photo' : 'photos' }}</span>
                </p>
            @endif

            {{-- 3. PER-ROOM CARDS (survey-sourced only). --}}
            @foreach ($item['rooms'] as $room)
                <div class="cav-returned__room">
                    <span class="cav-returned__room-name">{{ $room['name'] }}</span>

                    @if ($room['notes'] !== null)
                        <p class="cav-returned__note">{{ $room['notes'] }}</p>
                    @endif

                    @if ($room['access_notes'] !== null)
                        <p class="cav-returned__note">{{ $room['access_notes'] }}</p>
                    @endif

                    <span class="cav-returned__meta">{{ $room['answered'] }} of {{ $room['total'] }} questions answered</span>

                    <div class="cav-returned__answers">
                        @foreach ($room['answers'] as $answer)
                            <p class="cav-returned__q">{{ $answer['question'] }}</p>
                            <p class="cav-returned__a">{{ $answer['answer'] === 'other' ? ($answer['other_text'] ?? '') : ucfirst($answer['answer']) }}</p>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- 4. THE PHOTO CONTACT SHEET, grouped by VisitEvidence::BUCKETS
                 order. Each thumbnail is a plain <a> to the inline photo
                 route, opened in a new tab — no lightbox, no pager, no
                 caption edit. The <img> reuses the SAME route rather than a
                 second thumbnailer, so there is one authorisation surface
                 for both the thumbnail and the full image. --}}
            @foreach ($item['photos_by_bucket'] as $bucket => $photos)
                <span class="cav-returned__head">{{ $bucketLabels[$bucket] ?? ucfirst($bucket) }}</span>

                <div class="cav-returned__sheet">
                    @foreach ($photos as $photo)
                        @php
                            $photoUrl = route('projects.cockpit.visits.photo', [
                                'project' => $project,
                                'visit'   => $visit,
                                'kind'    => $photo['kind'],
                                'photo'   => $photo['id'],
                            ]);
                            $photoAlt = trim(($photo['room'] !== '' ? $photo['room'] : 'Photo').($photo['caption'] !== null ? ' — '.$photo['caption'] : ''));
                        @endphp
                        <a class="cav-returned__thumb" href="{{ $photoUrl }}" target="_blank" rel="noopener">
                            <img src="{{ $photoUrl }}" alt="{{ $photoAlt }}" loading="lazy">
                        </a>
                    @endforeach
                </div>
            @endforeach

            {{-- 5. THE SERIALS LIST (worksheet-sourced only). A plain list,
                 never thumbnails. No `captured_by` key exists on this array —
                 see VisitEvidence's own docblock (RV-03). --}}
            @if (! empty($item['serials']))
                <span class="cav-returned__head">Captured serials</span>

                <ul class="cav-returned__serials">
                    @foreach ($item['serials'] as $serial)
                        <li class="cav-returned__serial">
                            <span class="cav-returned__serial-where">{{ $serial['room'] }} — {{ $serial['device'] }}</span>
                            <span class="cav-returned__serial-value">{{ $serial['serial'] ?? 'Serial not read yet' }}</span>
                            <span class="cav-returned__meta">
                                {{ $serial['confirmed'] ? 'Confirmed' : 'Not confirmed' }}
                                @if ($serial['captured_at'] !== null)
                                    · {{ $serial['captured_at']->format('d M Y') }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- 6. THE SIGN-OFF BLOCK (worksheet-sourced only). Name, date,
                 comments, the signature image — read-only. No `ip_address`
                 or `user_agent` key exists on this array — RV-03. --}}
            @if ($item['signoff'] !== null)
                <span class="cav-returned__head">Client sign-off</span>

                <div class="cav-returned__signoff">
                    <span class="cav-returned__client">
                        {{ $item['signoff']['client_name'] }}
                        @if ($item['signoff']['signed_at'] !== null)
                            · {{ $item['signoff']['signed_at']->format('d M Y') }}
                        @endif
                    </span>

                    @if ($item['signoff']['signed_with_comments'] && filled($item['signoff']['comments']))
                        <p class="cav-returned__note">{{ $item['signoff']['comments'] }}</p>
                    @endif

                    @if (filled($item['signoff']['signature_data_uri']))
                        <img class="cav-returned__signature" src="{{ $item['signoff']['signature_data_uri'] }}" alt="Client signature">
                    @endif
                </div>
            @endif
        @endif

        {{-- 7. THE CONTROLS, BENEATH THE EVIDENCE (Plan 47-04, D-03). Rendered
             for EVERY visit in `$returnedVisits` — including one whose
             `source_missing` or `has_anything: false` sentence above is the
             only other thing this card shows — because `visit-row`'s own
             eight gates, not this file, decide whether anything in its action
             area actually draws. A reconstructed visit renders this call and
             draws nothing (`$hasContext`/`$canAct` are both false for it); the
             cap of four and the `controls` AND are entirely `visit-row`'s own
             contract, unedited here. --}}
        <x-cockpit.visit-row
            :visit="$visit"
            :project="$project"
            :module="$module"
            :action="$action"
            :action-visit-id="$actionVisitId"
            controls="true"
            tab="returned" />
    </div>
@endforeach

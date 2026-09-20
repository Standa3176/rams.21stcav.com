{{--
    Visit row — [52px date] [title] [sub-line] [chips] [status].

    ROWS ARE STATIC. No cursor:pointer, no hover colour shift, no tabindex, no
    onclick. Rows become openable in Phase 46; until then they must not
    advertise it, because a row that looks clickable and is not is worse than
    one that looks inert. No pip either — pips live on drawer summaries only.

    RECONSTRUCTED (D-02) — all four treatments, none optional:
      1. the Reconstructed chip, FIRST in the chip strip
      2. a 2px DASHED left edge (.cav-visit--reconstructed)
      3. the verbatim sub-line, different for worksheet- and survey-derived
      4. worksheet-derived only: the inferred type word is dotted-underlined
         and aria-describedby points at that sub-line. A survey-derived
         visit's TYPE is not in doubt, only its provenance.
    DASHED MEANS INFERRED, SOLID MEANS RECORDED — a line style, not a hue, so
    it survives greyscale. No gold (not actionable) and no teal (not
    confirmed): reconstructed rows sit in the neutral tier.

    SUPERSEDED (D-04):
      - position UNCHANGED, in date order, never hidden and never behind a
        toggle: superseding the paperwork does not un-happen the trip to site
      - the Superseded chip, SOLID border
      - line-through scoped to the DELIVERABLE NAME only. Striking the whole
        row would obscure the very fact D-04 exists to preserve
      - NO OPACITY on this row: the meta text is --cav-light, and 0.7 would
        take it to 3.03:1 and fail AA
    A row may legitimately carry BOTH chips, Reconstructed first.

    Neither qualifier is ever gold and neither is counted as attention.

    Source force-deleted: the visit still renders, with the Record unavailable
    chip. A broken source must never remove a visit from the spine.
--}}
@props(['visit'])

@php
    $isReconstructed = $visit->isBackfilled();
    $isSuperseded    = $visit->isSuperseded();

    $source        = $visit->source();
    $sourceMissing = $visit->source_id !== null && $source === null;

    $fromWorksheet = $visit->source_type === \App\Models\Visit::SOURCE_WORKSHEET;

    $typeLabels = [
        \App\Models\Visit::TYPE_SITE_SURVEY   => 'Site survey',
        \App\Models\Visit::TYPE_FIRST_FIX     => 'First fix',
        \App\Models\Visit::TYPE_INSTALL       => 'Install',
        \App\Models\Visit::TYPE_PROGRAMMING   => 'Programming',
        \App\Models\Visit::TYPE_SNAG          => 'Snagging',
        \App\Models\Visit::TYPE_COMMISSIONING => 'Commissioning',
    ];

    $typeLabel = $typeLabels[$visit->type] ?? 'Visit';
    $title     = filled($visit->title) ? $visit->title : $typeLabel . ' visit';

    $subId = 'cav-visit-' . $visit->id . '-inferred';

    $supersededOn = null;

    if ($source !== null) {
        $supersededOn = $source->getAttribute('superseded_at') ?? $source->getAttribute('deleted_at');
    }

    // isClosed() (Phase 46) rather than a literal status comparison, for the
    // same reason the progress ring uses it: an accepted visit is finished.
    $statusLabel = $visit->isClosed() ? 'Completed' : 'Planned';

    // ── The lifecycle, said in words (Phase 46, Plan 46-06) ──────────────
    //
    // A RECONSTRUCTED VISIT IS EXCLUDED FROM ALL OF IT. `Visit::state()`
    // reads RETURNED for a backfilled visit whose worksheet carries a
    // sign-off (46-01's recorded finding), and there are 24 such rows on
    // live. Gating on `isClosed()` as well as on `isBackfilled()` is what
    // stops two dozen years-old trips to site reading as work awaiting a PM's
    // attention on the day this ships.
    $isReviewable = ! $isReconstructed && ! $visit->isClosed();
    $state        = $visit->state();

    // The lock is a SENTENCE, never a greyed-out control. D-06: there is no
    // edit affordance to disable, because a PM who needs a change sends the
    // visit back. A disabled control that never explains itself is how a PM
    // concludes the page is broken.
    $lockedOn  = $isReconstructed ? null : $visit->returnedAt();
    $acceptedBy = $visit->accepted_at === null ? null : optional($visit->acceptedBy)->name;
@endphp

<div class="cav-visit{{ $isReconstructed ? ' cav-visit--reconstructed' : '' }}">
    <span class="cav-visit__date">{{ optional($visit->scheduled_date)->format('d M') }}</span>

    <span class="cav-visit__main">
        <span class="{{ $isSuperseded ? 'cav-superseded-name' : 'cav-visit__name' }}">{{ $title }}</span>

        @if ($isReconstructed && $fromWorksheet)
            <span class="cav-inferred-type" aria-describedby="{{ $subId }}">{{ $typeLabel }}</span>
        @endif

        @if ($isReconstructed)
            <span class="cav-visit__sub" id="{{ $subId }}">
                @if ($fromWorksheet)
                    Type inferred from a signed worksheet — the work actually done was not recorded.
                @else
                    Built from the existing survey record, not captured as a visit.
                @endif
            </span>
        @endif

        @if ($isSuperseded)
            <span class="cav-visit__sub">
                Superseded {{ optional($supersededOn)->format('d M Y') }} — the visit still happened.
            </span>
        @endif

        @if ($sourceMissing)
            <span class="cav-visit__sub">
                The visit is recorded; the document behind it could not be read.
            </span>
        @endif

        @if ($isReconstructed || $isSuperseded || $sourceMissing)
            <span class="cav-chips">
                @if ($isReconstructed)
                    <x-cockpit.chip variant="reconstructed" />
                @endif

                @if ($isSuperseded)
                    <x-cockpit.chip variant="superseded" />
                @endif

                @if ($sourceMissing)
                    <x-cockpit.chip variant="record-unavailable" />
                @endif
            </span>
        @endif

        @if ($visit->accepted_at !== null && ! $isReconstructed)
            {{-- WHO and WHEN, because an acceptance with no accountable actor
                 is repudiable (T-46-06-03). A deleted staff login reads NULL
                 at the database, so the actor degrades to "the office" rather
                 than silently becoming somebody else. --}}
            <span class="cav-visit__state">
                Accepted by {{ $acceptedBy ?? 'the office' }} on {{ $visit->accepted_at->format('d M Y') }}
            </span>
        @elseif ($state === \App\Models\Visit::STATE_SENT_BACK && ! $isReconstructed)
            <span class="cav-visit__state">
                Sent back {{ optional($visit->sent_back_at)->format('d M Y') }} — awaiting the engineer
            </span>
        @elseif ($visit->isAwaitingReturn() && ! $isReconstructed)
            <span class="cav-visit__state">Awaiting the engineer</span>
        @endif

        @if ($lockedOn !== null)
            {{-- Scope locks ON RETURN, not on acceptance (Visit::isLocked()).
                 The sentence is the whole affordance. --}}
            <span class="cav-visit__lock">Scope locked — returned {{ $lockedOn->format('d M Y') }}</span>
        @endif
    </span>

    <span class="cav-visit__status">{{ $statusLabel }}</span>
</div>

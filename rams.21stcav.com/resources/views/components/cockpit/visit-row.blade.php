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

    $statusLabel = $visit->status === \App\Models\Visit::STATUS_COMPLETED ? 'Completed' : 'Planned';
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
    </span>

    <span class="cav-visit__status">{{ $statusLabel }}</span>
</div>

{{--
    Angled masthead — 45-UI-SPEC.md § Layout Contract 1.

    Fill is --cav-teal-dark, NOT --cav-teal: white on the lighter teal is
    4.18:1 and fails AA at this size; white on teal-dark is 5.91:1 (override,
    Contrast Ledger). The sub-line is white, not the sketch's pale-teal tint,
    which is 4.48:1 at 13px and also fails (override).

    The bevel lives in cockpit.css (clip-path). Sub-line reads
    {quote code} · {stage} · {site}; empty parts are dropped rather than
    leaving a stray separator.
--}}
@props(['project'])

@php
    $subParts = array_values(array_filter([
        $project->quote_reference,
        \App\Models\Project::STATUS_LABELS[$project->status] ?? $project->status,
        $project->site_address,
    ], fn ($part) => filled($part)));
@endphp

<div class="cav-mast">
    <div class="cav-in">
        <h1 class="cav-mast__name">{{ $project->name }}</h1>

        @if ($subParts !== [])
            <div class="cav-mast__sub">{{ implode(' · ', $subParts) }}</div>
        @endif
    </div>
</div>

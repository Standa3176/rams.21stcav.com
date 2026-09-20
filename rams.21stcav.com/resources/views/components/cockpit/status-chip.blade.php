{{--
    Module status chip — sketch 004, D-10.

    THREE states and only three: Not started / In progress / On file. They are
    the design's vocabulary, and `CockpitModulePresenter` produces them as a
    pure translation of the section pip it already derived — so nothing here
    decides state, it only draws it.

    DELIBERATELY NOT `x-cockpit.chip`. That component carries the QUALIFIER
    vocabulary (Reconstructed / Superseded / Not required / Record
    unavailable) — facts about a record's provenance. These are facts about a
    module's progress. Sharing one component would mean one variant list where
    a Phase 46 edit to a progress state could silently restyle a D-02
    provenance chip. The class prefix differs too (`cav-schip`, not
    `cav-chip`) so the two can never collide in CSS.

    COLOUR IS NEVER THE ONLY CHANNEL. Each variant carries a distinct glyph
    SHAPE as well as its colour and its text: a hollow ring for not started, a
    filled dot for in progress, a filled square for on file. A greyscale
    screenshot still distinguishes all three, and so does a colour-blind
    reader. The glyph is aria-hidden because the chip's own text already says
    the state — announcing it twice would be noise.

    An unknown variant falls back to "Not started" rather than rendering a raw
    key on screen.
--}}
@props(['variant' => 'not-started'])

@php
    $variants = [
        'not-started' => ['cav-schip--wait', 'Not started'],
        'in-progress' => ['cav-schip--live', 'In progress'],
        'on-file'     => ['cav-schip--file', 'On file'],
    ];

    [$chipClass, $chipLabel] = $variants[$variant] ?? $variants['not-started'];
@endphp

<span {{ $attributes->merge(['class' => 'cav-schip '.$chipClass]) }}>
    <span class="cav-schip__glyph" aria-hidden="true"></span>{{ $chipLabel }}
</span>

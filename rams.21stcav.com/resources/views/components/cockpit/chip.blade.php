{{--
    Qualifier chip — 45-UI-SPEC.md § Reconstructed / § Superseded.

    Four variants, all TEXT not symbols, so no legend is needed:
      Reconstructed      · 1px DASHED --cav-inferred border — an inference
                           about the visit
      Superseded         · 1px SOLID --cav-border — a recorded fact about the
                           paperwork
      Not required       · solid, neutral
      Record unavailable · solid, neutral

    Dashed vs solid is the whole distinction, and it is a line style rather
    than a hue, so it survives greyscale and colour-blindness. Colour is not
    used to qualify: never gold (a qualification is not actionable) and never
    teal (it is not confirmed).

    Text is --cav-mid on --cav-chip-bg (6.72:1); --cav-light would be 4.09:1
    and fail AA, and these chips carry the most load-bearing copy on the page.
--}}
@props(['variant' => 'reconstructed'])

@php
    $variants = [
        'reconstructed'      => ['cav-chip--reconstructed', 'Reconstructed'],
        'superseded'         => ['', 'Superseded'],
        'not-required'       => ['', 'Not required'],
        'record-unavailable' => ['', 'Record unavailable'],
    ];

    [$chipClass, $chipLabel] = $variants[$variant] ?? $variants['reconstructed'];
@endphp

<span {{ $attributes->merge(['class' => trim('cav-chip ' . $chipClass)]) }}>{{ trim($slot) !== '' ? $slot : $chipLabel }}</span>

{{--
    Section group — 45-UI-SPEC.md § Layout Contract 3 + § Typography.

    A real <h2> eyebrow plus a slot for the drawers Plan 45-07 fills in. The
    eyebrow is marked up as a heading (not a styled <div>) so the page is
    navigable by heading alone: h1 project name -> h2 attention -> h2 per
    group.

    --cav-mid, not --cav-light: eyebrows sit on the page canvas where
    --cav-light drops to ~4.4:1 and fails AA. 13px is a floor, not a
    preference. Both rules live in cockpit.css (.cav-sec).
--}}
@props(['title'])

<section {{ $attributes->merge(['class' => 'cav-group']) }}>
    <h2 class="cav-sec">{{ $title }}</h2>

    {{ $slot }}
</section>

{{--
    Document row — name on the left, its current value on the right.

    The sketch's O&M full-versus-mini <select> is rendered HERE as STATIC TEXT
    of the current selection, e.g. "Full O&M manual — 42 pages". There is no
    <select> and no form control of any kind on this page: Phase 45 is
    read-only, and an inert control that looks live is worse than no control.
--}}
@props(['name', 'value' => ''])

<div {{ $attributes->merge(['class' => 'cav-doc']) }}>
    <span class="cav-doc__name">{{ $name }}</span>

    <span class="cav-doc__value">{{ $value }}</span>
</div>

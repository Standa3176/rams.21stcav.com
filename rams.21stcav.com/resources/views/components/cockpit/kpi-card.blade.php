{{--
    KPI card — sketch 004, D-12. Three of these sit above the module list:
    Overall status (+ stage), Next visit (+ type), Documents n of m (+ bar and
    percentage).

    THE BAR IS A <div>, NOT A <progress>. `<progress>` is a form-associated
    element: it would read as a control to the read-only fence and to a
    screen-reader user, on a page whose whole contract is that it offers
    nothing to operate. The fill is a plain span whose width is the percentage,
    and the whole bar carries role="img" with the same figure the text beside
    it states — so the number is never conveyed by length alone.

    `sub` and `percent` are both optional and both OMITTED rather than zeroed
    when their source has nothing to say. A card with no sub-line is a card
    about a fact with no second half; a 0% bar drawn in place of no bar would
    claim a measurement that was never taken.
--}}
@props([
    'label',
    'value',
    'sub'     => null,
    'icon'    => null,
    'percent' => null,
])

<div {{ $attributes->merge(['class' => 'cav-kpi']) }}>
    @if (filled($icon))
        <span class="cav-kpi__icon" aria-hidden="true"><x-cockpit.icon :name="$icon" /></span>
    @endif

    <span class="cav-kpi__body">
        <span class="cav-kpi__label">{{ $label }}</span>
        <span class="cav-kpi__value">{{ $value }}</span>

        @if (filled($sub))
            <span class="cav-kpi__sub">{{ $sub }}</span>
        @endif

        @if ($percent !== null)
            <span class="cav-kpi__meter">
                <span class="cav-kpi__bar" role="img" aria-label="{{ $percent }}% complete">
                    <span class="cav-kpi__bar-fill" style="width: {{ (int) $percent }}%"></span>
                </span>
                <span class="cav-kpi__pct">{{ (int) $percent }}%</span>
            </span>
        @endif
    </span>
</div>

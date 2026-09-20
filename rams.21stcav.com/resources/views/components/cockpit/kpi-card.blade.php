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

    THE TILE HUE IS A KEY, AND IT BORROWS THE MODULE PALETTE (Plan 45-15).
    Sketch 004 tints these three tiles green, blue and blue. Rather than
    declaring a parallel token set for three cards, `hue` takes one of the
    semantic aliases cockpit.css maps onto the existing module tokens —
    `ok` / `date` / `doc`. No colour is named here and none is named in the
    page that calls it; both pass a key.

    The hue is DECORATION. "Overall status" is stated in words in the card's
    own value ("On track" / "Needs attention" / "At risk"), and the glyph
    differs too (a ticked circle when green, a flag otherwise), so the card
    never depends on its tint to be understood.
--}}
@props([
    'label',
    'value',
    'sub'     => null,
    'icon'    => null,
    'hue'     => null,
    'percent' => null,
])

<div {{ $attributes->merge(['class' => 'cav-kpi']) }}>
    @if (filled($icon))
        <x-cockpit.icon :name="$icon" :tile="$hue ?? 'survey'" class="cav-kpi__icon" />
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

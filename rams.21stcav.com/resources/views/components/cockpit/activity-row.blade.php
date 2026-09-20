{{--
    One entry in the Recent activity feed — sketch 004, D-14.

    Who did what, and when, from rows `ProjectActivityLog` already holds. The
    feed is PROJECT-WIDE: that table has no module column, and guessing a
    module from `metadata` keys would silently drop every entry that carries
    none. The full reasoning lives in CockpitPanelPresenter's docblock, and
    both a unit test and a feature test pin it so it reads as a decision.

    THE AVATAR IS INITIALS, AND IT IS aria-hidden. `User` has no avatar
    column, and initials are what the design draws inside the circle anyway.
    The actor's own name is printed immediately beside it, so announcing the
    initials as well would read the same person out twice.

    WHAT IS NOT HERE: the design puts a "…" overflow control on each row. That
    is a menu trigger, which means writes — Phase 46 and 48 — so it is not
    rendered, not even disabled. A disabled control is still an offer.

    THE AVATAR'S COLOUR IS AN INDEX, NOT A COLOUR (Plan 45-15). `hue` is a
    slot number from `CockpitPanelPresenter`, derived from the actor's user id
    so a person keeps the same colour on every render and every project. This
    file renders `cav-av--{n}`; cockpit.css owns the six fills. White on every
    one of them clears 4.5:1, because the initials are small TEXT and the
    3:1 mark floor does not apply to them. A missing hue falls back to slot 0
    rather than to an untinted circle.
--}}
@props(['initials', 'actor', 'phrase', 'at' => null, 'hue' => 0])

<li {{ $attributes->merge(['class' => 'cav-act']) }}>
    <span class="cav-act__avatar cav-av--{{ (int) $hue }}" aria-hidden="true">{{ $initials }}</span>

    <span class="cav-act__body">
        <span class="cav-act__line"><span class="cav-act__actor">{{ $actor }}</span> {{ $phrase }}</span>

        {{-- "14 Aug 2026, 16:11" — the design's own format. --}}
        <span class="cav-act__at">{{ $at?->format('d M Y, H:i') ?? 'Time not recorded' }}</span>
    </span>
</li>

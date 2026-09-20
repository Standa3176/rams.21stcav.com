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
--}}
@props(['initials', 'actor', 'phrase', 'at' => null])

<li {{ $attributes->merge(['class' => 'cav-act']) }}>
    <span class="cav-act__avatar" aria-hidden="true">{{ $initials }}</span>

    <span class="cav-act__body">
        <span class="cav-act__line"><span class="cav-act__actor">{{ $actor }}</span> {{ $phrase }}</span>

        {{-- "14 Aug 2026, 16:11" — the design's own format. --}}
        <span class="cav-act__at">{{ $at?->format('d M Y, H:i') ?? 'Time not recorded' }}</span>
    </span>
</li>

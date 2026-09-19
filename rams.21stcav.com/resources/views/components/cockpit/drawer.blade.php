{{--
    Section drawer — 45-UI-SPEC.md § Layout Contract 4 + 5.

    Native <details>/<summary>. ZERO JavaScript and no Alpine: the element is
    focusable and operable with Enter/Space on its own, and it announces its
    own open/closed state, so do NOT hand-write aria-expanded, and never add
    tabindex="-1" or pointer-events tricks.

    NO DRAWER EVER SHIPS THE `open` ATTRIBUTE — including Install, which the
    sketch shows open purely to demonstrate its contents. Simplicity at rest
    is the brief, and open state is not persisted (there is no JS to persist
    it with). Because everything is closed at rest, the COUNT slot is the only
    place a reconstructed or superseded visit is disclosed to a PM who opens
    nothing; that string is built by CockpitSectionPresenter.

    A summary is one line at rest: [pip] [title] [count] [status] [arrow].
    The arrow rotates 90 degrees on open and is aria-hidden — its meaning is
    carried natively by <details>.

    A section with no derivation (programming) carries a BOX rather than a
    light, and Phase 45 renders that box UNTICKED only.
--}}
@props([
    'title',
    'pip'         => null,
    'ticked'      => false,
    'count'       => '',
    'status'      => '',
    'attention'   => false,
    'notRequired' => false,
])

<details {{ $attributes->merge(['class' => 'cav-drawer' . ($notRequired ? ' cav-drawer--na' : '')]) }}>
    <summary>
        @if ($pip === null)
            <x-cockpit.tick-box :ticked="$ticked" />
        @else
            <x-cockpit.pip :state="$pip" />
        @endif

        <span class="cav-title">{{ $title }}</span>

        <span class="cav-count">{{ $count }}</span>

        <span class="cav-status{{ $attention ? ' cav-status--attn' : '' }}">{{ $status }}</span>

        <span class="cav-arrow" aria-hidden="true">&rsaquo;</span>
    </summary>

    <div class="cav-drawer__body">
        {{ $slot }}
    </div>
</details>

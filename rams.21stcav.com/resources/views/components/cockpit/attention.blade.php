{{--
    The attention line — 45-UI-SPEC.md § Layout Contract 2 + § Copywriting.

    One <h2> and ONE paragraph. Not a panel, not a list, not a card, and it
    never duplicates the spine below it. The waiting item is named inline in
    <em>, which carries a 2px --cav-gold bottom border — one of exactly three
    permitted gold uses in Phase 45.

    Four variants:
      unavailable · health could not be derived; the spine is still accurate
      none        · "Nothing needs you on this job" — NO gold rule is rendered
      one         · "One thing needs you" (the word, never the numeral)
      many        · "{n} things need you"

    Source of truth is ProjectHealthService, reused UNCHANGED — no new
    derivation source, no new capture, no writes. It returns one reason per
    project, so the plural branch is not reachable from Phase 45's own caller;
    it is implemented rather than guessed at so a later phase with several
    items inherits settled copy.

    The sentence is assembled in PHP and echoed with {!! !!} because the item
    names are wrapped in <em>. Every item is passed through e() first, so the
    only unescaped markup is the <em> tags this component itself writes.
--}}
@props(['items' => [], 'unavailable' => false])

@php
    $waiting = array_values(array_filter((array) $items, fn ($item) => filled($item)));
    $count   = count($waiting);

    $named    = array_map(fn ($item) => '<em>' . e($item) . '</em>', $waiting);
    $lastName = array_pop($named);
    $sentence = $named === [] ? (string) $lastName : implode(', ', $named) . ' and ' . $lastName;
@endphp

<div class="cav-attn">
    @if ($unavailable)
        <h2 class="cav-attn__head">This job's summary could not be read just now</h2>
        <p>The sections below are still accurate.</p>
    @elseif ($count === 0)
        <h2 class="cav-attn__head">Nothing needs you on this job</h2>
        <p>Every section below is either complete or not yet due.</p>
    @elseif ($count === 1)
        <h2 class="cav-attn__head">One thing needs you</h2>
        <p>This job is waiting on {!! $sentence !!}.</p>
    @else
        <h2 class="cav-attn__head">{{ $count }} things need you</h2>
        <p>This job is waiting on {!! $sentence !!}.</p>
    @endif
</div>

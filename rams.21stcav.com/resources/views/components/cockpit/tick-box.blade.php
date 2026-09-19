{{--
    The hand-ticked box — 45-UI-SPEC.md § State Vocabulary.

    A 16px rounded SQUARE, categorically not a light. A circle says the system
    knows; a square says a human asserted it. Nothing in this codebase can
    evidence programming (ProjectHealthService.php:105-107), so a light there
    would claim a certainty that does not exist. Do not unify the shapes.

    PHASE 45 RENDERS THE UNTICKED STATE ONLY. The tick needs somewhere to
    store who ticked it and when, and ROADMAP criterion 5 forbids new writes,
    so no data in this phase can ever set $ticked to true. The ticked branch
    below is therefore UNREACHABLE in Phase 45 and ships only so Phase 46
    inherits a settled contract — do not wire anything to it here.

    Presentational only: no query, no form control (this is a <span>, never an
    <input type="checkbox">, which would be a write affordance).
--}}
@props(['ticked' => false])

<span {{ $attributes->merge(['class' => 'cav-tick' . ($ticked ? ' cav-tick--on' : '')]) }}
      role="img"
      aria-label="{{ $ticked ? 'Marked done by hand' : 'Not marked' }}">
    @if ($ticked)
        <span aria-hidden="true">&check;</span>
    @endif
</span>

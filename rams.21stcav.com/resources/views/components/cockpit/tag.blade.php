{{--
    Teal-soft tag — "RAMS issued", "Worksheet signed".
    45-UI-SPEC.md § Component Inventory.

    --cav-teal-dark text on --cav-teal-soft is 5.26:1 and passes AA. Never
    --cav-teal for text: the lighter teal on white is 4.18:1 and fails.

    A tag states a recorded fact about a visit. It is not a qualifier (that is
    <x-cockpit.chip>) and it is not an action — Phase 45 has no actions.
--}}
<span {{ $attributes->merge(['class' => 'cav-tag']) }}>{{ $slot }}</span>

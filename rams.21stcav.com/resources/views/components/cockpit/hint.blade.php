{{--
    The per-drawer explainer sentence — 45-UI-SPEC.md § Read-only Fence.

    MANDATORY in every drawer body in Phase 45. With every write affordance
    stripped by the read-only fence, drawer bodies get thin, and an open
    drawer must never be empty. One plain sentence saying what the section is
    and where its data comes from.
--}}
<p {{ $attributes->merge(['class' => 'cav-hint']) }}>{{ $slot }}</p>

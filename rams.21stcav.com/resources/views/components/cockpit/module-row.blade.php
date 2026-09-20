{{--
    Module row — sketch 004, D-10 / D-11 / D-16. One row per module returned by
    CockpitModulePresenter, in its order. The list is NEVER hardcoded here and
    neither is its length: a ninth row exists because the presenter returns
    nine, and the documents denominator above counts what this loop rendered.

    THE OPEN AFFORDANCE IS AN ANCHOR. `?module={key}` is a GET that re-renders
    the page with the side panel open. It is not a <button>, not an
    x-on:click, not a <details>. The ruling and its cost are recorded in
    resources/views/components/cockpit/panel.blade.php; in short, the panel's
    state is URL state because the read-only fence bans handler attributes
    inside this region and weakening a fence to fit a design is the failure the
    fence exists to catch.

    THE WHOLE ROW IS THE TARGET, AND STILL WITHOUT JAVASCRIPT (quick task
    260920). The user asked for the row itself to open the panel. That is
    done with the STRETCHED-LINK pattern and nothing else: the row below is
    `position: relative` and the anchor carries a `::after { inset: 0 }` that
    covers it, so a click anywhere on the row activates the one anchor that
    was already there. No handler, no tabindex, no second interactive
    element, and the markup is unchanged in kind — exactly one <a> per row,
    exactly one GET.

    Three shapes this could have taken, and why each is refused:

      * Wrapping the row in an <a>. The row holds a chip and a count; nesting
        them inside a link is invalid HTML and flattens the row into one
        unreadable link text.
      * An onclick / x-on:click. Banned inside this region and asserted
        absent by CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES.
      * A <button>. Banned by the same fence, and opening the panel is a GET.

    The earlier ruling that the row must NOT look clickable is retired here
    on purpose: it existed because the row was not clickable. It now is, so
    cursor:pointer and a hover tint are the honest signals rather than the
    lie they would have been. The VISIT row keeps the old ban — it really is
    static, and cockpit.css says so at its own rule.

    THE TILE'S HUE IS A KEY FROM THE PRESENTER (Plan 45-15). `$module['hue']`
    is read, never chosen: this file holds no module list and no colour, so a
    module added without a hue fails CockpitVisualTest rather than quietly
    rendering an untinted tile. The hue is IDENTITY, not state — progress is
    the chip's job, and the chip keeps its own glyph shape and its state in
    words so a greyscale render still reads.

    The count phrase is rendered only when the presenter produced one.
    Programming produces the empty string on purpose: it has no model,
    generator or storage type, so "0 files" would claim a file store exists.
--}}
@props(['project', 'module', 'active' => false])

<div {{ $attributes->merge(['class' => 'cav-module'.($active ? ' cav-module--active' : '')]) }}>
    <x-cockpit.icon :name="$module['icon']" :tile="$module['hue']" class="cav-module__icon" />

    <span class="cav-module__text">
        <span class="cav-module__title">{{ $module['title'] }}</span>
        <span class="cav-module__desc">{{ $module['description'] }}</span>
    </span>

    <x-cockpit.status-chip :variant="$module['chip']" />

    @if (filled($module['count']))
        <span class="cav-module__count">{{ $module['count'] }}</span>
    @endif

    {{-- THE ANCHOR NOW HAS NO VISIBLE TEXT, so the aria-label is its ONLY
         accessible name and is not optional. It names the module because
         nine links all reading the same word would be indistinguishable in
         a screen reader's link list.

         The copy dropped from "Open drawer: {title}" to "Open {title}" when
         the visible "Open drawer" text was replaced by the glyph: "drawer"
         was a reference to on-screen copy that no longer exists, so it had
         become jargon naming nothing. The module title stays either way.

         The glyph is decorative and x-cockpit.icon always renders it
         aria-hidden — it must stay that way, or the link would announce
         itself twice. --}}
    <a class="cav-module__open"
       aria-label="Open {{ $module['title'] }}"
       href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key']]) }}"><x-cockpit.icon name="arrow" class="cav-icon cav-icon--sm" /></a>
</div>

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

    {{-- THE ROW IS A TOGGLE (quick task 260927-tgl). The user opened Site
         Survey on the live cockpit and then could not shut it again: "i
         cannot click it again to close it (to see the other options again".

         The cause was one href serving two states. Every row pointed at
         `?module={its own key}`, so while a row was OPEN its anchor pointed
         at the URL the browser was already on — the stretched link fired,
         navigated to the current page, and re-rendered something identical.
         Nothing was broken; the anchor was aimed at the wrong place in one
         of its two states.

         So the OPEN row's anchor now drops `module=` and points at the bare
         cockpit URL, exactly as the drawer's own two close controls do.
         CLOSED rows are unchanged. Still URL state, still no JavaScript, so
         the back button and a bookmark keep agreeing with the page — and
         still exactly one anchor per row, which is what the stretched link
         and the read-only fence both require.

         ALL THREE CHANNELS MOVE TOGETHER, or the row would lie about what a
         click does:

           * the DESTINATION, above;
           * the GLYPH — the existing `close` mark, the same one the drawer
             header already shows for the same destination. A right-pointing
             arrow on a control that closes is the wrong picture, and the
             picture is all most people read. It is swapped, never rotated:
             a `transform` belongs to no rule on this anchor, because
             transforming it would make it a containing block and collapse
             the full-row overlay onto the 28px glyph, silently;
           * the ACCESSIBLE NAME — "Close {title}" while open. The glyph is
             aria-hidden, so this label is the link's ONLY name, and a name
             reading "Open" on a control that closes is the same lie in the
             screen-reader channel.

         NO `aria-expanded`, considered and refused. There is no id on the
         drawer to pair one with through `aria-controls`, and this anchor
         NAVIGATES rather than toggling anything in place; announcing a
         disclosure widget the page does not implement would be a worse
         claim than the name, which already states the state in words.

         `cav-module--active` keeps the meaning 46.3 wave 2 gave it — "this
         row owns the drawer beneath it" — and neither the tint nor the spine
         moves here. The drawer's `Back to all modules` link and its header
         close BOTH stay: this is a third way out, not a replacement for
         either. --}}
    @php
        $isOpenState = (bool) $active;
        $openHref    = $isOpenState
            ? route('projects.cockpit', $project)
            : route('projects.cockpit', ['project' => $project, 'module' => $module['key']]);
    @endphp

    <a class="cav-module__open"
       aria-label="{{ $isOpenState ? 'Close' : 'Open' }} {{ $module['title'] }}"
       href="{{ $openHref }}"><x-cockpit.icon :name="$isOpenState ? 'close' : 'arrow'" class="cav-icon cav-icon--sm" /></a>
</div>

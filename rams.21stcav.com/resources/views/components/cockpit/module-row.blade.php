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

    THE ROW ITSELF IS STILL STATIC. Only the link is operable — no
    cursor:pointer on the row, no tabindex, no whole-row hover shift. A row
    that looks clickable but is not is worse than one that looks inert.

    The count phrase is rendered only when the presenter produced one.
    Programming produces the empty string on purpose: it has no model,
    generator or storage type, so "0 files" would claim a file store exists.
--}}
@props(['project', 'module', 'active' => false])

<div {{ $attributes->merge(['class' => 'cav-module'.($active ? ' cav-module--active' : '')]) }}>
    <span class="cav-module__icon" aria-hidden="true"><x-cockpit.icon :name="$module['icon']" /></span>

    <span class="cav-module__text">
        <span class="cav-module__title">{{ $module['title'] }}</span>
        <span class="cav-module__desc">{{ $module['description'] }}</span>
    </span>

    <x-cockpit.status-chip :variant="$module['chip']" />

    @if (filled($module['count']))
        <span class="cav-module__count">{{ $module['count'] }}</span>
    @endif

    {{-- The accessible name names the module: nine links all reading "Open
         drawer" would be indistinguishable in a screen reader's link list. --}}
    <a class="cav-module__open"
       aria-label="Open drawer: {{ $module['title'] }}"
       href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key']]) }}">Open drawer<x-cockpit.icon name="arrow" class="cav-icon cav-icon--sm" /></a>
</div>

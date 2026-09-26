{{--
    THE PROJECT-LEVEL RECENT ACTIVITY PANEL — Phase 46.3, D-03.

    Project-wide on purpose: the log has no module column, so the same feed
    renders under every module rather than a filtered one that would silently
    drop every entry carrying no metadata hint.

    THAT COMMENT TRAVELLED WITH THE FEED FROM panel.blade.php, AND IT IS MORE
    RELEVANT HERE, NOT LESS. D-03 moved the block out of the module panel and
    into its own right-hand panel precisely because the reasoning above had
    always been true: the feed showed the same entries whichever module was
    open, so its position inside one module was telling the reader something
    the data never said. The panel's position now matches what the data is.
    It also fills the column the inline drawer vacated in Plan 46.3-02 (D-01).

    THE FEED IS MOVED, NOT REBUILT. Same `$activity` collection off the same
    `CockpitPanelPresenter::activity()`, same `x-cockpit.activity-row` with
    the same five attributes, same empty sentence. No new query, no new
    presenter method, and nothing here decides what an entry says.

    THE ENTRY COUNT IS THE PRESENTER'S, NOT THIS FILE'S. `activity()` already
    caps at six. A second cap in Blade would be a second opinion about the
    same number, and the two would drift.

    IT RENDERS UNCONDITIONALLY, AND IT RENDERS WHEN THERE IS NOTHING TO SHOW.
    An empty project gets the hint sentence rather than a missing panel — on
    the same reasoning the not-required module row uses: a panel that
    disappears when it has no rows reads as a page fault, and a PM cannot tell
    "nothing has happened yet" from "this page is broken".

    ITS CLASSES ARE ITS OWN. It does NOT borrow `.cav-panel__card` /
    `.cav-panel__card-head` from the module panel, although it looks like one
    today — the same refusal `cav-pnote` made over `cav-note`. Two unrelated
    things sharing a class is how a later edit aimed at the drawer's cards
    reaches the activity feed. Only `cav-acts` and `x-cockpit.activity-row`
    are shared, and those are the feed's own, moved with it.

    NO CONTROL OF ANY KIND. The design puts a "View all" link under this feed
    and a "…" overflow on each row. There is no project-activity GET route,
    and the overflow is a menu trigger, which means writes — Phase 46 and 48.
    Neither is rendered, not even disabled: a disabled control is still an
    offer. Nothing here is a form control, a script or an Alpine directive.
--}}
@props(['activity' => null])

@php
    $activity = $activity ?? collect();
@endphp

<aside {{ $attributes->merge(['class' => 'cav-activity']) }} aria-labelledby="cav-activity-head">
    <h2 class="cav-activity__head" id="cav-activity-head">Recent activity</h2>

    @if ($activity->isNotEmpty())
        <ul class="cav-acts">
            @foreach ($activity as $entry)
                <x-cockpit.activity-row
                    :initials="$entry['initials']"
                    :actor="$entry['actor']"
                    :phrase="$entry['phrase']"
                    :hue="$entry['hue'] ?? 0"
                    :at="$entry['at']" />
            @endforeach
        </ul>
    @else
        <x-cockpit.hint>Nothing has been recorded against this project yet.</x-cockpit.hint>
    @endif
</aside>

{{--
    The tab a cockpit write was initiated FROM (Phase 46.1, Plan 46.1-06).

    WHY IT EXISTS. Every one of D-02's four acts redirected to the module's
    tabless URL, so a PM who read the evidence on the Returned tab and pressed
    Accept landed on Overview — thrown out of the very surface this phase
    exists to create. There is no JavaScript on this page, so the tab travels
    the only way a form can carry it: a hidden field.

    IT IS VALIDATED HERE AS WELL AS AT THE CONTROLLER. A submitted `tab` is
    membership-resolved against ProjectCockpitController::TABS on the way IN
    (ProjectCockpitActionController::tabFor()) and against the same constant
    here on the way OUT, so a value that is not a real tab is never rendered
    into the page at all — not even escaped. An unknown tab renders NOTHING
    and the redirect falls back to the tabless behaviour this phase inherited.

    ONE DEFINITION, FOUR FORMS. Accept, Send back, Add note and Raise a snag
    all carry it, and they carry the same field, so the four cannot drift.
--}}
@props(['tab' => null])

@if (in_array($tab, \App\Http\Controllers\ProjectCockpitController::TABS, true))
    <input type="hidden" name="tab" value="{{ $tab }}">
@endif

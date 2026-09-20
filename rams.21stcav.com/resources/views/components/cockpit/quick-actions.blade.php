{{--
    Quick actions — sketch 004, D-15. Phase 46, Plan 46-04.

    THE PANEL'S ONLY WRITE SURFACE, AND IT IS CAPPED. At most ONE control per
    module, and never more than four controls in this block at any visit state
    — asserted, not asserted-about, by
    CockpitCreateVisitTest::test_no_module_panel_ever_renders_more_than_four_quick_actions().
    VL-11 is the executable form of the user's own brief ("simple to use", and
    earlier "I want to make it look simple and less scary"). If a later plan
    needs a fifth control, it removes something.

    WHO OFFERS WHAT, and why the split is not arbitrary:

      Site survey, First fix and install  →  Create visit
          The only two modules WITH AN ENGINEER LINK (VisitLinkIssuer::VISIT_MODULES).
          A visit on a module with no link would be a record with nothing behind it.

      RAMS, O&M manual, Cable schedule    →  Generate document
          D-04: the generators that ALREADY EXIST. Sending to the client and
          confirming sent are Phase 48, so this block does not grow a second half.

      Programme, Drawings, Programming, Snagging  →  NOTHING
          Not an empty heading, not a disabled button. A disabled control is
          still an offer. Snagging's own `Book a visit` is Phase 47 and stays a
          banned affordance.

    NO JAVASCRIPT. The form is disclosed by `&action=create-visit` on the
    module's own URL — the same query-string mechanism the panel already uses —
    and closed by an anchor back. Bookmarkable, back-button-correct, works with
    JavaScript off in a plant room. All nine of the fence's banned handler
    attributes stay banned: Phase 46 CONSIDERED retiring them and declined.

    NO <select>. Rooms and people are checkboxes and the visit type is two
    radios, so the fence still bans `<select` by name.

    COPY DISCIPLINE, because the fence bans neighbouring strings on purpose:
    this control reads `Create visit` (D-15's wording) — never `Book a visit`,
    `Prepare a visit` or `Book another survey`. The document control reads
    `Generate document` — never `Add document`, `Download`, `Issue to client`
    or `Upload files`.

    THE STRETCHED-LINK TRAP: `.cav-module` is `position: relative` and
    `.cav-module__open::after` is `inset: 0`. Nothing here takes its own
    `position`, and Quick actions live inside the PANEL, never inside a row.

    Labour resources render NAME ONLY. The cockpit is staff-auth so contact
    details would be sanctioned (LR-04), but this control needs only names and
    there is no reason to render more.
--}}
@props([
    'project',
    'module',
    'action' => null,
    'rooms'  => [],
    'people' => [],
])

@php
    /** The visit types THIS module may create — [] for the seven that may not. */
    $createTypes = \App\Support\Visits\VisitLinkIssuer::typesFor($module['key']);

    /**
     * The existing generator route for the three document modules. Guarded
     * with Route::has() exactly as 45-12's document links are, so a renamed
     * route degrades to NO control rather than a 500 on a PM's screen.
     */
    $generatorRoutes = [
        \App\Models\ProjectDeliverable::KEY_RAMS           => 'rams.from-project',
        \App\Models\ProjectDeliverable::KEY_OM             => 'om-manuals.generate-from-project',
        \App\Models\ProjectDeliverable::KEY_CABLE_SCHEDULE => 'cable-schedules.generate-from-project',
    ];

    $generator   = $generatorRoutes[$module['key']] ?? null;
    $canGenerate = $generator !== null && \Illuminate\Support\Facades\Route::has($generator);

    $moduleUrl = route('projects.cockpit', ['project' => $project, 'module' => $module['key']]);
    $openUrl   = route('projects.cockpit', ['project' => $project, 'module' => $module['key'], 'action' => 'create-visit']);

    $isOpen = $action === 'create-visit' && $createTypes !== [];

    $typeLabels = [
        \App\Models\Visit::TYPE_FIRST_FIX => 'First fix',
        \App\Models\Visit::TYPE_INSTALL   => 'Install',
    ];
@endphp

@if ($createTypes !== [] || $canGenerate)
    <div class="cav-qa">
        <span class="cav-panel__card-head">Quick actions</span>

        @if ($createTypes !== [])
            @if (! $isOpen)
                {{-- ONE control. The form is a URL away, not a widget away. --}}
                <a class="cav-qa__control" href="{{ $openUrl }}">Create visit</a>
            @else
                <form class="cav-qa__form" method="POST" action="{{ route('projects.cockpit.visits.store', $project) }}">
                    @csrf

                    {{-- The module is carried in the payload so the write lands
                         back on the drawer the PM had open. It is validated by
                         Rule::in the issuer's own map, never trusted. --}}
                    <input type="hidden" name="module" value="{{ $module['key'] }}">

                    @if ($errors->any())
                        <ul class="cav-qa__errors">
                            @foreach ($errors->all() as $message)
                                {{-- Escaped, always. A submitted value is never
                                     reflected raw (T-46-04-07). --}}
                                <li class="cav-qa__error">{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if (count($createTypes) === 1)
                        {{-- Site survey has exactly one type, so it asks no
                             question it already knows the answer to. --}}
                        <input type="hidden" name="visit_type" value="{{ $createTypes[0] }}">
                    @else
                        <fieldset class="cav-qa__set">
                            <legend class="cav-qa__legend">Visit type</legend>

                            @foreach ($createTypes as $type)
                                <label class="cav-qa__opt">
                                    <input type="radio" name="visit_type" value="{{ $type }}"
                                           @checked(old('visit_type', $createTypes[0]) === $type)>
                                    <span>{{ $typeLabels[$type] ?? $type }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    <label class="cav-qa__field">
                        <span class="cav-qa__label">Date</span>
                        <input class="cav-qa__input" type="date" name="scheduled_date" value="{{ old('scheduled_date') }}">
                    </label>

                    @if (count($rooms) > 0)
                        <fieldset class="cav-qa__set">
                            {{-- D-05: captured on the visit and rendered on the
                                 engineer's link. The generators stay
                                 project-wide — VL-12 is NOT DELIVERED. --}}
                            <legend class="cav-qa__legend">Rooms in scope</legend>

                            @foreach ($rooms as $room)
                                <label class="cav-qa__opt">
                                    <input type="checkbox" name="rooms[]" value="{{ $room }}">
                                    <span>{{ $room }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @if (count($people) > 0)
                        <fieldset class="cav-qa__set">
                            <legend class="cav-qa__legend">Who is going</legend>

                            @foreach ($people as $person)
                                <label class="cav-qa__opt">
                                    <input type="checkbox" name="labour_resource_ids[]" value="{{ $person['id'] }}">
                                    <span>{{ $person['name'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    <div class="cav-qa__row">
                        <button class="cav-qa__control" type="submit">Create visit</button>
                        {{-- Closing is an anchor back, so the browser's back
                             button and this control agree about "closed". --}}
                        <a class="cav-qa__cancel" href="{{ $moduleUrl }}">Cancel</a>
                    </div>
                </form>
            @endif
        @elseif ($canGenerate)
            <form class="cav-qa__form" method="POST" action="{{ route($generator, $project) }}">
                @csrf
                <button class="cav-qa__control" type="submit">Generate document</button>
            </form>
        @endif
    </div>
@endif

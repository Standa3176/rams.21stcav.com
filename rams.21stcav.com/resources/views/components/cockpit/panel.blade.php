{{--
    The side panel — sketch 004, D-09. Header, a three-anchor tab strip
    (Overview / Files / Notes), a close control, and the Overview body.

    ── THE PANEL'S STATE IS URL STATE, AND THERE IS NO JAVASCRIPT ───────────

    Opening a module is `?module={key}`, switching tab is `&tab={tab}`, and
    closing is an anchor back to the bare cockpit URL. Alpine IS loaded
    globally by this application's layout, so it was available — it is ruled
    out because CockpitReadOnlyFenceTest bans handler attributes inside the
    `cav-cockpit` region, and weakening a fence to fit a design is precisely
    the failure that fence exists to catch. Each open is a GET that writes
    nothing.

    The cost, stated plainly: a full page load every time a panel opens or a
    tab changes. That was accepted at plan time for a read-only page. What it
    buys is that every panel state is bookmarkable and shareable, the browser
    back button works, and the page degrades to nothing when JavaScript is
    off. A CSS-only disclosure was considered and rejected — the checkbox
    form needs an <input>, which the fence bans outright, and the :target
    form puts state in a fragment the server never sees, which cannot drive
    the Files tab's document list.

    When Phase 46 introduces writes it may progressively enhance this with
    Alpine. At that point the fence is being retired deliberately, by someone
    who owns that decision. Until then: no x-data, no x-show, no x-init, no
    x-if, no x-text, no x-on:, no @click, no wire:.

    ── WHAT IS NOT HERE ─────────────────────────────────────────────────────

    The design image draws a Quick actions block (Create visit · Add note ·
    Upload files) and a "…" overflow on each activity row. Those are WRITES,
    owned by Phase 46 and Phase 48, and none of them is rendered — not even
    disabled, because a disabled control is still an offer.

    The Files and Notes tabs carry one honest line in this plan. Plan 45-12
    fills them, along with the Recent activity feed.
--}}
@props(['project', 'module', 'tab' => 'overview', 'progress' => null])

@php
    $tabs = [
        'overview' => 'Overview',
        'files'    => 'Files',
        'notes'    => 'Notes',
    ];

    $heading = $project->ref ?? $project->name;

    /** @var \Illuminate\Support\Collection $visits */
    $visits = $module['section']['visits'] ?? collect();

    // The ring's geometry. r=26 on a 64x64 box; the dash array is the arc
    // length so the stroke draws exactly `percent` of the circumference.
    $ringRadius        = 26;
    $ringCircumference = 2 * M_PI * $ringRadius;
    $ringArc           = $progress === null ? 0.0 : $ringCircumference * ($progress['percent'] / 100);

    $ringSentence = $progress === null
        ? null
        : $progress['completed'].' of '.$progress['total'].' '.($progress['total'] === 1 ? 'visit' : 'visits').' completed';
@endphp

<aside class="cav-panel" aria-label="{{ $module['title'] }} details">
    <div class="cav-panel__head">
        <span class="cav-panel__icon" aria-hidden="true"><x-cockpit.icon :name="$module['icon']" /></span>

        <span class="cav-panel__ident">
            <h2 class="cav-panel__title">{{ $module['title'] }}</h2>
            <span class="cav-panel__ref">{{ $heading }}</span>
        </span>

        {{-- Close is an ANCHOR back to the bare cockpit URL, so the browser's
             back button and this control agree about what "closed" means. --}}
        <a class="cav-panel__close" href="{{ route('projects.cockpit', $project) }}" aria-label="Close the {{ $module['title'] }} panel">
            <x-cockpit.icon name="close" class="cav-icon cav-icon--sm" />
        </a>
    </div>

    <p class="cav-panel__purpose">{{ $module['description'] }}</p>

    {{-- Three anchors. Each carries the CURRENT module forward, so a tab
         switch never closes the panel. aria-current marks the active one;
         there is no aria-expanded, because none of this is a disclosure
         widget — the tab strip is navigation between three URLs. --}}
    <nav class="cav-panel__tabs" aria-label="{{ $module['title'] }} sections">
        @foreach ($tabs as $key => $label)
            <a class="cav-panel__tab{{ $tab === $key ? ' cav-panel__tab--on' : '' }}"
               href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key'], 'tab' => $key]) }}"
               @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="cav-panel__body">
        @if ($tab === 'overview')
            @if ($progress !== null)
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Progress</span>

                    <div class="cav-ring-row">
                        {{-- An <svg> is neither a form control nor a script,
                             so the fence is untroubled. role="img" plus an
                             aria-label carrying the SAME sentence printed
                             beside it means the figure is never conveyed by
                             shape alone. --}}
                        <svg class="cav-ring" viewBox="0 0 64 64" role="img" aria-label="{{ $ringSentence }}">
                            <circle class="cav-ring__track" cx="32" cy="32" r="{{ $ringRadius }}" fill="none" stroke-width="7" />
                            <circle class="cav-ring__arc" cx="32" cy="32" r="{{ $ringRadius }}" fill="none" stroke-width="7"
                                    stroke-linecap="round"
                                    stroke-dasharray="{{ round($ringArc, 2) }} {{ round($ringCircumference, 2) }}"
                                    transform="rotate(-90 32 32)" />
                        </svg>

                        <span class="cav-ring-row__text">
                            <span class="cav-ring-row__pct">{{ $progress['percent'] }}%</span>
                            <span class="cav-ring-row__sub">{{ $ringSentence }}</span>
                        </span>
                    </div>
                </div>
            @endif

            @if ($visits->isNotEmpty())
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Visits</span>

                    {{-- The existing component, so the D-02 reconstructed and
                         D-04 superseded treatments reach the panel unchanged
                         rather than being re-implemented here. --}}
                    @foreach ($visits as $visit)
                        <x-cockpit.visit-row :visit="$visit" />
                    @endforeach
                </div>
            @else
                <x-cockpit.hint>{{ $module['title'] }} has nothing recorded against it yet.</x-cockpit.hint>
            @endif
        @else
            {{-- One honest line, and no "coming soon" furniture around it. --}}
            <x-cockpit.hint>Files and notes arrive in the next plan.</x-cockpit.hint>
        @endif
    </div>
</aside>

@extends('layouts.app')

@section('title', $project->name . ' — Cockpit')

{{--
    The delivery cockpit — Phase 45 (VIS-04 / VIS-06 / VIS-10), sketch 004.

    This is the page a project is delivered from: masthead, three KPI cards,
    one stage chip, the module rows, and — since Phase 46.3 (D-01) — an INLINE
    DRAWER that opens directly beneath the row that was clicked.

    THE DRAWER IS INLINE AND THE OTHER ROWS COLLAPSE AWAY (46.3 D-01 / D-02).
    The panel used to be a sibling of .cav-modules in a two-column grid. It is
    now rendered INSIDE the loop, immediately after its own row, and every
    other row is skipped while a module is open — the user asked to "see only
    the one you're working in". Sketch 004's side panel is NARROWED by that
    ruling; its tokens, row design and query-string state all survive.

    Consequence, designed rather than discovered: with the other rows gone the
    drawer's close control is the ONLY route back to the list. panel.blade.php
    therefore carries a NAMED anchor with visible text ("Back to all modules")
    above its tab strip, alongside the header close control. Two ways out.

    THE :active FLAG IS NOW ALWAYS TRUE WHILE A DRAWER IS OPEN, because the
    only row rendered is the open one. `cav-module--active` is KEPT anyway, and
    deliberately: its job has changed from "this is the open one OF FOUR" to
    "this row owns the drawer beneath it". The 4px spine and tint are the only
    thing visually joining a full-width drawer to the row above it, so without
    them the pair reads as two unrelated cards. Dropping the modifier would
    also have meant editing the `.cav-module--active` rules that
    CockpitVisualTest's stretched-link scan covers, for no gain.

    READ-ONLY. ROADMAP criteria 3 and 5: this page renders records the app
    already holds and adds no new capture and no new writes. The design image
    draws an "Actions ▾" menu, a "…" overflow and three Quick action tiles
    (Create visit / Add note / Upload files). NONE of them is rendered here,
    not even disabled — a disabled control is still an offer, and those are
    Phase 46 and Phase 48 writes. Nothing in the `cav-brand cav-cockpit`
    subtree is a form control, a <script> or an Alpine directive.

    THE SIDE PANEL'S STATE IS THE QUERY STRING, AND THERE IS NO JAVASCRIPT.
    `?module=` opens, `?tab=` switches, and the close control is an anchor back
    to the bare URL. The full ruling lives in the panel component's docblock.

    THREE THINGS RENDERED HONESTLY RATHER THAN AS DRAWN — each settled in Plan
    45-10 and each pinned by a test, so none of them is an oversight to be
    "fixed" later:
      - ONE stage chip, not two: a project has exactly one status.
      - No site-contact email: `SiteSurvey` holds a name and a phone only.
      - "Planned start", never "Proposed install date": a different fact.

    STYLING — the three constraints that are the point of this phase:

    1. layouts/app.blade.php is NOT touched. It is 2,154 lines and every
       authenticated page extends it, so a token added to its :root at :33
       would retone the whole app and break criterion 4. That file,
       resources/css/app.css and tailwind.config.js are sha256-locked by
       FlagOffBehaviourUnchangedTest. A mismatch there is a STOP, not a hash
       to refresh.
    2. --teal-* already means BLUE here (the layout aliases the whole family
       onto the navy/accent palette at :65-71), and tailwind.config.js maps
       brand.teal to a blue too. So: only --cav-* tokens, and NO Tailwind
       colour/type/spacing utilities anywhere in this subtree.
    3. The stylesheet loads through the layout's @stack('styles') seam at
       :1322 — the last thing in <head>, after the app's own :root, so it wins
       on source order. cockpit.css @imports cav-tokens.css, so the token file
       is NOT registered separately.

    Every class is cav-prefixed: .btn has four existing rules in the layout
    and .card two, and the sketch's generic names would fight them.
--}}

@push('styles')
    @vite('resources/css/cockpit.css')
@endpush

@section('content')
    <div class="cav-brand cav-cockpit">
        <div class="cav-page">
            <x-cockpit.masthead :project="$project" :masthead="$masthead" />

            {{-- D-12 — three cards, in the design's order. Every value comes
                 from CockpitHeaderPresenter; the Blade layer only chooses the
                 word for a status key it was given. --}}
            @php
                $overall      = $kpis['overall'];
                $nextVisit    = $kpis['next_visit'];
                $documents    = $kpis['documents'];
                $statusLabels = ['green' => 'On track', 'amber' => 'Needs attention', 'red' => 'At risk'];
                $statusValue  = $overall['status'] === null ? 'Not available' : ($statusLabels[$overall['status']] ?? 'Not available');
                $statusIcon   = $overall['status'] === 'green' ? 'check' : 'flag';
                // A HUE KEY, not a colour — cockpit.css maps the three aliases
                // onto the existing token pairs. Green when the project is on
                // track, amber otherwise, which is DECORATION: the card states
                // its status in words and changes its glyph shape too, so the
                // tint is never the only channel.
                $statusHue    = $overall['status'] === 'green' ? 'ok' : 'warn';
                $stageSub     = $overall['status'] === null
                    ? $overall['reason']
                    : ($overall['stage'] === null ? null : 'Stage: ' . $overall['stage']);
            @endphp

            <div class="cav-kpis">
                <x-cockpit.kpi-card
                    label="Overall status"
                    :value="$statusValue"
                    :sub="$stageSub"
                    :hue="$statusHue"
                    :icon="$statusIcon" />

                <x-cockpit.kpi-card
                    label="Next visit"
                    :value="$nextVisit['label']"
                    :sub="$nextVisit['type_label'] ?? null"
                    hue="date"
                    icon="calendar" />

                <x-cockpit.kpi-card
                    label="Documents"
                    :value="$documents['complete'] . ' of ' . $documents['total'] . ' complete'"
                    :percent="$documents['percent']"
                    hue="doc"
                    icon="document" />
            </div>

            {{-- Exactly one chip. The design's second chip has no source.

                 The chip is TINTED BY ITS KEY — the project status the
                 presenter already returned — so the page matches the design's
                 coloured pills rather than a grey one. The key is echoed as a
                 modifier class and cockpit.css owns every value; no colour is
                 named here. An unmapped status simply gets the default tint,
                 never a missing chip. The label still says the stage in
                 words, so the hue adds nothing the text does not. --}}
            @if ($stageChips !== [])
                <div class="cav-stage">
                    @foreach ($stageChips as $chip)
                        <span class="cav-stage__chip cav-stage__chip--{{ $chip['key'] }}">{{ $chip['label'] }}</span>
                    @endforeach
                </div>
            @endif

            <div class="cav-layout">
                <div class="cav-modules">
                    <h2 class="cav-modules__head">Project modules</h2>
                    <p class="cav-modules__sub">Plan and deliver the AV installation</p>

                    @if ($isEmpty)
                        <p class="cav-note">
                            Nothing has been recorded on this job yet. This cockpit reads records the app
                            already holds — when a site survey is submitted or a worksheet is signed on
                            site, the visit appears here.
                        </p>
                    @endif

                    {{-- As many rows as the presenter returns — four since
                         46.2 D-01 reversed D-16's nine. This file states no
                         number precisely so that a reversal like that one is a
                         map edit and nothing else: never a hardcoded list and
                         never a hardcoded count. --}}
                    @foreach ($modules as $module)
                        @php
                            // The open row, and — while one is open — the ONLY
                            // row. 46.3 D-02: "collapse away so you see only
                            // the one you're working in." Derived per row from
                            // the presenter's own key, so this file still
                            // states no number and names no module.
                            $isOpenRow = $openModule !== null && $openModule['key'] === $module['key'];
                        @endphp

                        @if ($openModule !== null && ! $isOpenRow)
                            @continue
                        @endif

                        <x-cockpit.module-row
                            :project="$project"
                            :module="$module"
                            :active="$isOpenRow" />

                        {{-- D-01 — THE DRAWER IS HERE, INSIDE THE LIST AND
                             IMMEDIATELY AFTER ITS OWN ROW, not a sibling of
                             .cav-modules in a second column. Closed at rest
                             still means ABSENT (D-09): a hidden-but-present
                             panel would need CSS or JS to hide it and would
                             still be read out by a screen reader. --}}
                        @if ($isOpenRow)
                            <x-cockpit.panel
                                :project="$project"
                                :module="$openModule"
                                :tab="$tab"
                                :progress="$progress"
                                :files="$panelFiles"
                                :notes="$panelNotes"
                                :activity="$activity"
                                {{-- SEVEN ATTRIBUTES ADDED BY PLAN 46.2-05 — the
                                     document form's URL state and its data. All
                                     seven are READS, derived in
                                     ProjectCockpitController from
                                     CockpitDocumentFormPresenter. THEY MOVED WITH
                                     THE PANEL UNCHANGED in 46.3-02: not one prop
                                     was added, removed or re-derived. --}}
                                :action="$action"
                                :doc-fields="$docFields"
                                :doc-readiness="$docReadiness"
                                :doc-formats="$docFormats"
                                :doc-intro="$docIntro"
                                :doc-values="$docValues"
                                :doc-resources="$docResources" />
                            {{-- FIVE ATTRIBUTES REMOVED BY 46.2 D-02 (Plan 46.2-03):
                                 `evidence`, `action`, `action-visit-id`, `rooms` and
                                 `people`. They fed the Create visit form and the
                                 Returned tab, neither of which this page surfaces any
                                 more. The capability is unsurfaced, NOT deleted — see
                                 the routes named in panel.blade.php's docblock. --}}
                        @endif
                    @endforeach
                </div>
            </div>

            <p class="cav-note">
                <a class="cav-link" href="{{ route('projects.show', $project) }}">Open full project</a>
            </p>
        </div>
    </div>
@endsection

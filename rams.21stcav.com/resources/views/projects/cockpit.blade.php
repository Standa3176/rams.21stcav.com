@extends('layouts.app')

@section('title', $project->name . ' — Cockpit')

{{--
    The read-only project cockpit — Phase 45 (VIS-04 / VIS-06 / VIS-10).

    READ-ONLY. ROADMAP criteria 3 and 5: this page renders records the app
    already holds and adds no new capture and no new writes. Nothing in the
    `cav-brand cav-cockpit` subtree below is a form control or a <script>, and
    none of the fifteen deferred affordances in 45-UI-SPEC.md § Read-only
    Fence appears. The one permitted navigation affordance is the text link at
    the foot of the page, which is a GET to a page that already exists.

    STYLING — the three constraints that are the point of this phase:

    1. layouts/app.blade.php is NOT touched. It is 2,154 lines and every
       authenticated page extends it, so a token added to its :root at :33
       would retone the whole app and break criterion 4. Plan 45-08 asserts
       that file is byte-identical to its pre-phase state.
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
        <x-cockpit.masthead :project="$project" />

        <div class="cav-page">
            <x-cockpit.attention
                :unavailable="$health === null"
                :items="$health !== null && $health->status !== 'green' ? [$health->reason] : []" />

            @if ($isEmpty)
                <h2 class="cav-attn__head">Nothing has been recorded on this job yet</h2>

                <p class="cav-note">
                    This cockpit reads records the app already holds. When a site survey is
                    submitted or a worksheet is signed on site, the visit appears here.
                </p>
            @endif

            {{-- The spine. Nine drawers, every one closed at rest — so the
                 count slot on each summary is the only place a reconstructed
                 or superseded visit is disclosed to a PM who opens nothing.
                 The three groups and their order were fixed by Plan 45-06. --}}
            <x-cockpit.section-group title="Visits — someone goes to site">
                @foreach ($sections->where('group', \App\Support\Cockpit\CockpitSectionPresenter::GROUP_VISITS) as $section)
                    @include('projects._cockpit-drawer', ['section' => $section])
                @endforeach
            </x-cockpit.section-group>

            <x-cockpit.section-group title="Documents — produced in the office">
                @foreach ($sections->where('group', \App\Support\Cockpit\CockpitSectionPresenter::GROUP_DOCUMENTS) as $section)
                    @include('projects._cockpit-drawer', ['section' => $section])
                @endforeach
            </x-cockpit.section-group>

            <x-cockpit.section-group title="Reference">
                @foreach ($sections->where('group', \App\Support\Cockpit\CockpitSectionPresenter::GROUP_REFERENCE) as $section)
                    @include('projects._cockpit-drawer', ['section' => $section])
                @endforeach
            </x-cockpit.section-group>

            <p class="cav-note">
                Visits group by type, so a three-day install is one line until you open it. Each
                section is also the deliverable — there is no second list saying the same thing
                twice.
            </p>

            <p class="cav-note">
                <a class="cav-link" href="{{ route('projects.show', $project) }}">Open the full project page</a>
            </p>
        </div>
    </div>
@endsection

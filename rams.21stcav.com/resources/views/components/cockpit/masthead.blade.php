{{--
    Cockpit masthead — sketch 004. Breadcrumb, the project's identifier as the
    page's single <h1>, the site address, then the facts the app actually
    holds.

    THE ANGLED TEAL BANNER IS GONE. D-08 reversed D-07: the cockpit renders in
    the application's own palette, inside the application's own chrome, so the
    masthead is page content rather than a coloured slab.

    EVERY FACT IS RENDERED ONLY IF ITS KEY IS PRESENT. `CockpitHeaderPresenter`
    OMITS a key whose source is absent rather than nulling it, so a project
    with no survey shows no contact line at all instead of an empty one.

    THREE THINGS THE DESIGN DRAWS THAT ARE NOT RENDERED, each settled in Plan
    45-10 and each pinned by a test:
      - a site-contact EMAIL. `SiteSurvey` has a name and a phone only;
        `pm_email` is the project manager's address and under a "Site contact"
        label it would be a lie.
      - "Proposed install date". No such field exists. The nearest value is
        `InstallProgramme::planned_start_date` — a different fact, set by a
        different act — and it appears here as "Planned start" or not at all.
      - an "Actions" dropdown. It is a Phase 46/48 write surface.

    The heading falls back to the project NAME when the project has no `ref`.
    Both are the project's own identity, so neither is an invention; a blank
    <h1> on a project that predates refs would be.
--}}
@props(['project', 'masthead'])

@php
    $heading = $masthead['ref'] ?? $project->name;

    $contactParts = array_values(array_filter([
        $masthead['contact_name'] ?? null,
        $masthead['contact_phone'] ?? null,
    ], fn ($part) => filled($part)));
@endphp

<header class="cav-mast">
    <nav class="cav-crumbs" aria-label="Breadcrumb">
        <a class="cav-link" href="{{ route('projects.index') }}">Projects</a>
        <span class="cav-crumbs__sep" aria-hidden="true">&rsaquo;</span>
        <span class="cav-crumbs__here">{{ $heading }}</span>
    </nav>

    <h1 class="cav-mast__name">{{ $heading }}</h1>

    @if (isset($masthead['site_address']))
        <p class="cav-mast__site">
            <x-cockpit.icon name="pin" class="cav-icon cav-icon--sm" />{{ $masthead['site_address'] }}
        </p>
    @endif

    @if ($contactParts !== [] || isset($masthead['planned_start']))
        <div class="cav-facts">
            @if ($contactParts !== [])
                <span class="cav-fact">
                    <x-cockpit.icon name="person" class="cav-icon cav-icon--sm" />Site contact: {{ implode(' · ', $contactParts) }}
                </span>
            @endif

            @if (isset($masthead['planned_start']))
                <span class="cav-fact">
                    <x-cockpit.icon name="calendar" class="cav-icon cav-icon--sm" />Planned start: {{ $masthead['planned_start']->format('j M Y') }}
                </span>
            @endif
        </div>
    @endif
</header>

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
    Upload files) and a "…" overflow on each activity row. PHASE 46 SHIPS THE
    FIRST OF THOSE and nothing else: `x-cockpit.quick-actions` renders at the
    bottom of the OVERVIEW body, on five module keys, one control each. Upload
    files stays Phase 48 and the "…" overflow is still nobody's — neither is
    rendered, not even disabled, because a disabled control is still an offer.

    The Quick actions block is a plain form POST, so the paragraph above about
    handler attributes is UNCHANGED: Phase 46 retired `<form`, `<input`,
    `<button` and `<textarea` from the fence's forbidden markup, and retired
    NONE of the nine banned handler attributes.

    The Files and Notes tabs and the Recent activity feed were filled by Plan
    45-12 from records the app already holds. Nothing in them writes, and the
    only link copy they use is "View".

    ── THE RETURNED TAB'S PRESENCE RULE (Phase 46.1, Plan 46.1-03, D-01) ────

    A fourth tab, `Returned`, sits between Overview and Files — but only where
    there is something to review. THE RULE FOLLOWS THE DATA: the tab renders
    if and only if this drawer holds at least one visit whose `source_type` is
    set, i.e. at least one visit backed by an engineer record that could have
    returned something.

    Why not on every module: `Visit::returnedAt()` reads the SOURCE, so a visit
    with no source can never be RETURNED, SENT BACK or ACCEPTED — and the four
    document modules (RAMS, Drawings, O&M, Cable schedule) hold no visits at
    all. They would carry a permanently empty fourth tab, which is noise on
    seven of nine drawers.

    Why not "Site survey and First fix only": that hardcodes two module keys.
    It would strip the review surface from any commissioning, programming or
    snagging visit that ever does carry a source, and it needs a hand-kept list
    that drifts from the rows actually rendered — the same failure the module
    whitelist in ProjectCockpitController avoids by reading the presenter's own
    keys.

    `?tab=returned` on a drawer that does not offer it is a STALE BOOKMARK, not
    an error: `$tab` is coerced back to `overview` ONCE, at the top of the PHP
    block below (a literal directive name is deliberately not written inside
    this comment — Blade compiles statements BEFORE it strips comments, so a
    directive mentioned in prose is still compiled), and the strip and the body
    therefore cannot disagree about which tab is open.
    The page is still 200 and the submitted string is never echoed — exactly
    the treatment `?module=` has had since 45-11.

    The tab strip is still anchors only, still carries `aria-current="page"` on
    the active tab and still carries no `aria-expanded`. It is navigation
    between URLs, not a disclosure widget, and a fourth URL does not change
    that.
--}}
@props([
    'project',
    'module',
    'tab'      => 'overview',
    'progress' => null,
    'files'    => null,
    'notes'    => null,
    'activity' => null,
    // Phase 46 — Quick actions. `action` is the panel's THIRD piece of URL
    // state, resolved by membership in ProjectCockpitController exactly as
    // `module` and `tab` are, so an unknown value discloses nothing.
    'action'   => null,
    // Plan 46-06 — the visit row the `send-back` disclosure names. Compared,
    // never looked up.
    'actionVisitId' => null,
    'rooms'    => [],
    'people'   => [],
    // Plan 46.1-03 — the Returned tab's payload, keyed by visit id and derived
    // in ProjectCockpitController. Empty when no module is open.
    'evidence' => [],
])

@php
    $heading = $project->ref ?? $project->name;

    /** @var \Illuminate\Support\Collection $visits */
    $visits = $module['section']['visits'] ?? collect();

    // THE PRESENCE RULE — see the docblock above. Read from the visits already
    // on this panel, so no module key is named anywhere.
    $offersReturned = $visits->contains(fn ($visit) => $visit->source_type !== null);

    // ONE COERCION, ONE VARIABLE. A stale `?tab=returned` bookmark falls back
    // to Overview here rather than in two branches that could disagree about
    // which tab the strip marks and which body renders.
    if ($tab === 'returned' && ! $offersReturned) {
        $tab = 'overview';
    }

    $tabs = ['overview' => 'Overview'];

    if ($offersReturned) {
        $tabs['returned'] = 'Returned';
    }

    $tabs['files'] = 'Files';
    $tabs['notes'] = 'Notes';

    // Only the sourced visits reach the Returned tab. A sourceless visit has
    // nothing to review and Overview already lists it.
    $returnedVisits = $visits->filter(fn ($visit) => $visit->source_type !== null)->values();

    // The ring's geometry. r=26 on a 64x64 box; the dash array is the arc
    // length so the stroke draws exactly `percent` of the circumference.
    $ringRadius        = 26;
    $ringCircumference = 2 * M_PI * $ringRadius;
    $ringArc           = $progress === null ? 0.0 : $ringCircumference * ($progress['percent'] / 100);

    $files    = $files ?? collect();
    $notes    = $notes ?? collect();
    $activity = $activity ?? collect();

    // Whether this module HAS a document library at all, read from the
    // presenter's own key list rather than a second copy of it here. Three
    // modules (install programme, programming, snagging) have no document
    // relation anywhere in this codebase, and "holds no documents" is a
    // different sentence from "none produced yet" — the first is permanent.
    $hasLibrary = in_array($module['key'], \App\Support\Cockpit\CockpitPanelPresenter::documentModules(), true);

    $ringSentence = $progress === null
        ? null
        : $progress['completed'].' of '.$progress['total'].' '.($progress['total'] === 1 ? 'visit' : 'visits').' completed';
@endphp

<aside class="cav-panel" aria-label="{{ $module['title'] }} details">
    <div class="cav-panel__head">
        {{-- The same tinted tile the module row draws, in the same hue, so
             the panel is visibly the row the PM just opened. The key comes
             from the presenter; this file names no colour. --}}
        <x-cockpit.icon :name="$module['icon']" :tile="$module['hue']" class="cav-panel__icon" />

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

    {{-- Three anchors, or four where the drawer holds a sourced visit. Each
         carries the CURRENT module forward, so a tab switch never closes the
         panel. aria-current marks the active one;
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
                        {{-- The percentage sits INSIDE the ring, as the design
                             draws it. It is real text centred over the svg by
                             CSS, not an <svg><text>, so it inherits the page's
                             font and scales with the user's text size. --}}
                        <span class="cav-ring-wrap">
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

                            <span class="cav-ring-row__pct">{{ $progress['percent'] }}%</span>
                        </span>

                        <span class="cav-ring-row__text">
                            <span class="cav-ring-row__sub">{{ $ringSentence }}</span>

                            {{-- The same bar the documents KPI card draws, on
                                 the same terms: a <span>, never a <progress>,
                                 which is form-associated and would read as a
                                 control on a page that offers none. It carries
                                 the same figure the sentence above states, so
                                 nothing is conveyed by length alone. --}}
                            <span class="cav-ring-row__meter" role="img" aria-label="{{ $progress['percent'] }}% complete">
                                <span class="cav-ring-row__meter-fill" style="width: {{ (int) $progress['percent'] }}%"></span>
                            </span>
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
                        <x-cockpit.visit-row
                            :visit="$visit"
                            :project="$project"
                            :module="$module"
                            :action="$action"
                            :action-visit-id="$actionVisitId" />
                    @endforeach
                </div>
            @else
                <x-cockpit.hint>{{ $module['title'] }} has nothing recorded against it yet.</x-cockpit.hint>
            @endif

            {{-- Recent activity (D-14). Project-wide on purpose: the log has
                 no module column, so the same feed renders under every
                 module rather than a filtered one that would silently drop
                 every entry carrying no metadata hint. --}}
            <div class="cav-panel__card">
                <span class="cav-panel__card-head">Recent activity</span>

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
            </div>

            {{-- Quick actions sit at the BOTTOM of Overview, where sketch 004
                 draws them (D-15). The Files and Notes tabs carry none — one
                 place to act, not three. --}}
            <x-cockpit.quick-actions
                :project="$project"
                :module="$module"
                :action="$action"
                :rooms="$rooms"
                :people="$people" />
        @elseif ($tab === 'returned')
            {{-- D-01 / RV-01 — what the engineer actually sent back. Read live
                 and read-only; the tab writes nothing and edits nothing. --}}
            <x-cockpit.returned-tab
                :project="$project"
                :module="$module"
                :visits="$returnedVisits"
                :evidence="$evidence" />
        @elseif ($tab === 'files')
            {{-- D-13 — the project's document library for this module. Every
                 document it holds, in one place. A document whose type has no
                 resolvable GET route is listed WITHOUT a link rather than
                 omitted: a missing row is a worse failure than a plain one. --}}
            @if ($files->isNotEmpty())
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Documents</span>

                    @foreach ($files as $file)
                        <x-cockpit.file-row
                            :name="$file['name']"
                            :produced="$file['produced_at']"
                            :status="$file['status']"
                            :route="$file['route']" />
                    @endforeach
                </div>
            @elseif ($hasLibrary)
                <x-cockpit.hint>No {{ $module['title'] }} documents have been produced yet.</x-cockpit.hint>
            @else
                <x-cockpit.hint>{{ $module['title'] }} holds no documents.</x-cockpit.hint>
            @endif
        @else
            {{-- Notes the module itself recorded, plus the project's logged
                 notes. Never Project::notes, which is a project-level field
                 and would print the same paragraph under all nine modules. --}}
            @if ($notes->isNotEmpty())
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Notes</span>

                    @foreach ($notes as $note)
                        {{-- `cav-pnote`, not `cav-note`: that class is already
                             taken by the page footnote that carries the
                             "Open full project" link, and two unrelated
                             things sharing a class is how a later styling
                             edit reaches something it was not aimed at. --}}
                        @php($isOffice = (bool) ($note['office'] ?? false))

                        <div class="cav-pnote{{ $isOffice ? ' cav-pnote--office' : '' }}">
                            {{-- D-02: an office note SITS ALONGSIDE the
                                 engineer's record, so it has to READ as the
                                 office's. The label is words, not a colour —
                                 a hue alone would say nothing in greyscale
                                 and nothing to a screen reader. --}}
                            @if ($isOffice)
                                <span class="cav-pnote__tag">Office note</span>
                            @endif

                            {{-- ESCAPED OUTPUT ONLY. PM free text is escaped
                                 here and everywhere; unescaped output is
                                 forbidden in every cockpit component and
                                 CockpitPanelTest greps for it (T-46-07-05). --}}
                            <p class="cav-pnote__text">{{ $note['text'] }}</p>

                            {{-- An office note carries the TIME as well as the
                                 date: two notes on one return, minutes apart,
                                 must be readable in the order they were
                                 written. --}}
                            <span class="cav-pnote__meta">{{ $note['source'] }}{{ $note['at'] ? ' · '.$note['at']->format($isOffice ? 'd M Y, H:i' : 'd M Y') : '' }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-cockpit.hint>{{ $module['title'] }} has no notes recorded.</x-cockpit.hint>
            @endif
        @endif
    </div>
</aside>

{{--
    The module drawer — sketch 004, D-09, NARROWED BY 46.3 D-01. A way back,
    a header, a three-anchor tab strip (Overview / Files / Notes), a close
    control, and the Overview body.

    IT IS NO LONGER A SIDE PANEL. Since Phase 46.3 it renders INSIDE the module
    list, immediately after the row that was clicked, at the full width of that
    list — and the other rows are not rendered at all while it is open (D-02).
    The class name `cav-panel` is kept: renaming it would have churned every
    assertion in four test files for a word, and this file says what it is.

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

    THE COCKPIT CARRIES NO VISIT CONTROL (Phase 46.2, Plan 46.2-03, D-02).

    There is no Create visit, no Accept, no Send back, no Add note and no
    Raise a snag on this page. **THE RETURNED TAB AND ITS HAND-OFF LINK ARE
    BACK (Phase 47, Plan 47-03, D-04)** — conditionally, and with no control
    beneath the evidence yet (that is Plan 47-04's job, deliberately
    sequenced after this one). That remains a SURFACING change and nothing
    else: **the visit workflow was not deleted, it was unsurfaced.** Every act
    below still exists, still validates, still logs and still passes its own
    tests — at its own route:

      Create visit ......... projects.cockpit.visits.store
      Accept ............... projects.cockpit.visits.accept
      Send back ............ projects.cockpit.visits.send-back
      Add note ............. projects.cockpit.visits.notes
      Raise a snag ......... projects.cockpit.visits.snags
      Download all photos .. projects.cockpit.visits.photos-zip
      One photo ............ projects.cockpit.visits.photo

    If you arrived here looking for the review surface, it is RE-WIRED, not
    gone: read `.planning/phases/47-cockpit-links-and-returns/47-CONTEXT.md`
    (D-04) and `.planning/phases/46.2-doc-creation-cockpit/
    46.2-RETIREMENT-LEDGER.md` for the history. `App\Support\Cockpit\
    CockpitEvidencePresenter` and `App\Support\Cockpit\VisitEvidence` are
    re-injected by `ProjectCockpitController` (Plan 47-03) and are no longer
    zero-caller. That sat dormant deliberately, on this repo's own
    precedent — `SiteSurveyDocxService` sat written and tested with no caller
    until Plan 46.2-02 wired it up — and this is the plan that ends it. DO NOT
    delete any of them; they were never dead code.

    What the cockpit region DOES carry from 46.2 on is a DOCUMENT FORM (Plan
    46.2-05): the fields each of the four documents needs, POSTing to the
    generate route. So the paragraph above about handler attributes is
    UNCHANGED and still load-bearing — the form's disclosure is `?action=`
    query-string state, not JavaScript. Phase 46 retired `<form`, `<input`,
    `<button` and `<textarea` from the fence's forbidden markup; `<select` and
    `<script` are still banned and all nine handler attributes are still
    banned. If a document field genuinely needs a `<select`, lift the entry
    BY NAME in the commit that ships the control. Never by deletion.

    ── THREE TABS, UNCONDITIONALLY, AND A FOURTH THAT IS NOT ───────────────

    `overview`, `files`, `notes` — always. `returned` IS BACK (Phase 47, Plan
    47-03, D-04), and the presence rule that governs it is the ORIGINAL one
    (Phase 46.1, Plan 46.1-03, D-01 — "the tab renders iff this drawer holds a
    visit with a resolvable source"), rebuilt rather than guessed at a new
    shape: `$offersReturned`, passed in as a prop, derived in the controller
    from `evidenceFor()`'s own map rather than from a second call to the
    presenter here.

    THERE IS ONLY ONE `?tab=` COERCION, AND IT IS NOT HERE. A stale
    `?tab=returned` bookmark on a module that does not offer the tab falls
    back to Overview inside `ProjectCockpitController::resolveTab()`, which
    now takes `$offersReturned` as a second argument for exactly this reason —
    so the strip built below and the body `@elseif`s further down can never
    disagree about which tab is open. This file adds NO second fallback: the
    `$tabs` array below only ever contains a `returned` key when
    `$offersReturned` is true, and `$tab` arriving here already resolved
    against that same boolean.

    The Files and Notes tabs were filled by Plan 45-12 from records the app
    already holds. Nothing in them writes, and the only link copy they use is
    "View". The Recent activity feed was filled by the same plan and LEFT THIS
    COMPONENT in 46.3 D-03 — it is project-level and now renders at page level
    as `x-cockpit.activity-panel`. See the comment where its card stood.

    The tab strip is still anchors only, still carries `aria-current="page"` on
    the active tab and still carries no `aria-expanded`. It is navigation
    between URLs, not a disclosure widget.

    ── WHAT STAYS, AND WHY IT IS NOT A CONTRADICTION (46.2 D-06) ────────────

    The read-only VISIT LIST on the Overview tab stays, as does the module
    row's "N visits" count phrase. The rows stop OFFERING visit actions; they
    do not stop REPORTING. A PM generating a worksheet is helped, not confused,
    by seeing that two visits exist — and removing the phrase would mean
    redesigning the module row, which sketch 004's visual design explicitly
    survives.
--}}
@props([
    'project',
    'module',
    'tab'      => 'overview',
    'progress' => null,
    'files'    => null,
    'notes'    => null,
    // `activity` WAS HERE AND WAS DROPPED BY 46.3 D-03, with the card it fed.
    // The feed is project-level and now renders at page level from
    // `x-cockpit.activity-panel`; a prop left wired to a block that no longer
    // exists is exactly the wiring-that-can-never-fire this file's own
    // comments warn about.
    // FIVE PROPS REMOVED BY 46.2 D-02 (Plan 46.2-03), unsurfaced not deleted:
    //   'action', 'actionVisitId' — the visit disclosures' URL state
    //   'rooms', 'people'         — the Create visit form's option lists
    //   'evidence'                — the Returned tab's payload
    //
    // `action` IS BACK (Plan 46.2-05) FOR THE DOCUMENT FORM'S `?action=generate`
    // DISCLOSURE, and THREE MORE VALUES JOINED IT (Plan 47-04, D-03):
    // `send-back`, `note` and `snag`, resolved by the same membership rule
    // against the same (now four-entry) `ACTIONS` constant. `rooms` and
    // `people` do NOT come back — they fed Create visit's option lists, and
    // that act is still out of scope (47-04's own scope fence).
    'action'         => null,
    // THE VISIT-SCOPED DISCLOSURE'S ROW ID (Plan 47-04, D-03). Resolved in
    // ProjectCockpitController from `?visit=`, carried straight through to
    // `returned-tab.blade.php` and from there into `visit-row`'s own
    // `actionVisitId` prop — the SAME comparison-never-lookup value that prop
    // has always expected. null whenever `$action` names no visit (null or
    // `generate`).
    'actionVisitId'  => null,
    // The document form's data, all of it derived in ProjectCockpitController
    // from CockpitDocumentFormPresenter — this file decides no field and no
    // format of its own.
    'docFields'    => [],
    'docReadiness' => [],
    'docFormats'   => [],
    'docIntro'     => null,
    'docValues'    => [],
    'docResources' => [],
    // THE WIZARD'S STEP STATE (Plan 46.5-04). Passed straight through: this
    // file decides no step of its own, exactly as it decides no field and no
    // format of its own.
    'docStep'      => 1,
    'docSteps'     => [],
    'docStepTitle' => null,
    // THE ENGINEER LINK AND ITS STATE (Plan 47-01, D-01 remainder / D-02).
    // Derived in ProjectCockpitController from CockpitLinkPresenter — this
    // file decides no URL and no state sentence of its own, exactly as it
    // decides no field and no step of its own. null for rams/om and for a
    // module with no document yet; `link-card.blade.php` renders nothing
    // for either.
    'link'         => null,
    // THE RETURNED TAB'S PAYLOAD AND ITS OFFER (Plan 47-03, D-04). Derived in
    // ProjectCockpitController from CockpitEvidencePresenter via evidenceFor()
    // — this file decides no evidence shape and no offer rule of its own,
    // exactly as it decides no field and no step of its own. `evidence` is
    // keyed by visit id and carries ONLY the visits that have one;
    // `offersReturned` is `evidence !== []`, derived in the controller rather
    // than recomputed here so the strip and the body cannot disagree.
    'evidence'       => [],
    'offersReturned' => false,
])

@php
    $heading = $project->ref ?? $project->name;

    /** @var \Illuminate\Support\Collection $visits */
    $visits = $module['section']['visits'] ?? collect();

    // THREE TABS, ALWAYS, AND A FOURTH ONLY WHEN `$offersReturned` SAYS SO
    // (Plan 47-03, D-04). The controller's own `$offersReturned` is the ONLY
    // thing this array consults — no re-derivation, no second call to the
    // presenter. The fallback for a stale `?tab=returned` bookmark lives in
    // ProjectCockpitController::resolveTab(), which now takes this same
    // boolean, so this file adds no coercion of its own.
    $tabs = [
        'overview' => 'Overview',
        'files'    => 'Files',
        'notes'    => 'Notes',
    ];

    if ($offersReturned) {
        $tabs['returned'] = 'Returned';
    }

    // The ring's geometry. r=26 on a 64x64 box; the dash array is the arc
    // length so the stroke draws exactly `percent` of the circumference.
    $ringRadius        = 26;
    $ringCircumference = 2 * M_PI * $ringRadius;
    $ringArc           = $progress === null ? 0.0 : $ringCircumference * ($progress['percent'] / 100);

    $files    = $files ?? collect();
    $notes    = $notes ?? collect();
    // `$activity = $activity ?? collect();` went with the prop (46.3 D-03).

    // Whether this module HAS a document library at all, read from the
    // presenter's own key list rather than a second copy of it here. Three
    // modules (install programme, programming, snagging) have no document
    // relation anywhere in this codebase, and "holds no documents" is a
    // different sentence from "none produced yet" — the first is permanent.
    $hasLibrary = in_array($module['key'], \App\Support\Cockpit\CockpitPanelPresenter::documentModules(), true);

    // ── WHAT THIS MODULE REPORTS ON OVERVIEW (quick task 260928-dq2) ────────
    //
    // THE DEFECT THIS FIXES, IN THE USER'S WORDS: *"iN OVERVIEW , TXT SAYS
    // RAMS has nothing recorded against it yet. EVEN THOUGH THERE ARE 2
    // VERSIONS OF RAMS UNDER FILES"*.
    //
    // The empty sentence below used to be the `@else` of `@if ($visits->
    // isNotEmpty())`, so it measured VISITS AND ONLY VISITS. RAMS and the O&M
    // have no visits BY DESIGN — `CockpitModulePresenter::MODULE_MAP` gives
    // both `'visit_types' => []`, and `VisitLinkIssuer`'s docblock (:21-27)
    // states the rule and says it is not arbitrary: the site survey and the
    // worksheet are the only two modules with an engineer link, so they are
    // the only two that can hold a visit. That made "nothing recorded" a
    // PERMANENT sentence on two of the four rows, however many documents the
    // project held.
    //
    // THE DISCRIMINATOR IS A BOOLEAN, NOT A DOCUMENT KEY. `has_visits` is
    // derived in the presenter from `visit_types`. There is no `@if` on
    // `$module['key']` here and there must never be one — this component
    // switches on TYPE and names no document, and that property has survived
    // six reshapes.
    $hasVisitTypes = (bool) ($module['has_visits'] ?? false);

    // THE VISITS CARD IS NOW GATED ON THE TYPE, not merely on the collection
    // being non-empty. A module with no visit types can never render a visits
    // card even if a row appeared against it by some other route, which is the
    // structural half of this fix: the card and the sentence now agree about
    // what kind of thing this module holds.
    $reportsVisits = $hasVisitTypes && $visits->isNotEmpty();

    // DOCUMENTS ARE REPORTED BY EVERY MODULE THAT HAS ANY. `$files` is the SAME
    // per-module collection the Files tab renders, already derived by
    // `CockpitPanelPresenter::files()` and already passed to this panel on
    // every tab by the controller — so nothing new is queried or wired, and the
    // two tabs cannot disagree about what exists.
    //
    // NOT gated on `! $hasVisitTypes`, deliberately. A worksheet with two
    // worksheets on file and no visit booked yet is ALSO not "nothing
    // recorded", and gating this card on the module type would have left that
    // second false sentence in place for the next person to find. The Files tab
    // stays what it is — it asks "what does the project hold?" and answers
    // "View"; Overview asks "what do I do with it?" and answers with the
    // module's own mapped action and the finished artefact.
    $documents = $files;

    // AND THE EMPTY SENTENCE MEASURES BOTH KINDS, for all four modules. It
    // appears only when this module genuinely holds nothing at all.
    $reportsNothing = ! $reportsVisits && $documents->isEmpty();

    $ringSentence = $progress === null
        ? null
        : $progress['completed'].' of '.$progress['total'].' '.($progress['total'] === 1 ? 'visit' : 'visits').' completed';
@endphp

<aside class="cav-panel" aria-label="{{ $module['title'] }} details">
    {{-- THE WAY BACK (46.3 D-02, and it is a requirement rather than styling).

         The drawer is now INLINE and the other module rows COLLAPSE AWAY while
         it is open, so this anchor and the header close control are the only
         routes back to the list. A glyph-only close would be a trap: a PM who
         opened the wrong module would have no visible way out.

         So: a NAMED anchor with VISIBLE TEXT, first thing in the drawer and
         above the tab strip, naming the destination in the user's words rather
         than the app's — "Back to all modules", never "Close". It drops the
         module from the query string entirely, so it is a plain GET and works
         with JavaScript off, exactly like every other control on this page.

         The copy was checked as a substring against all 21
         CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES keys and both
         FORBIDDEN_MARKUP entries before use. It collides with none.

         The chevron is the existing `arrow` glyph turned round in CSS — there
         is no left-pointing glyph in the icon map and adding one is not this
         plan's business. It is aria-hidden, so the link's accessible name is
         its visible text and the two cannot disagree. --}}
    <a class="cav-panel__back" href="{{ route('projects.cockpit', $project) }}">
        <x-cockpit.icon name="arrow" class="cav-icon cav-icon--sm cav-panel__back-icon" />
        <span class="cav-panel__back-text">Back to all modules</span>
    </a>

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

    {{-- Three anchors, ALWAYS, plus a conditional fourth (Plan 47-03, D-04 —
         the Returned tab's presence rule, rebuilt). Each carries the CURRENT
         module forward, so a tab switch never closes the panel. aria-current
         marks the active one; there is no aria-expanded, because none of
         this is a disclosure widget — the tab strip is navigation between
         URLs, three or four of them depending on $offersReturned. --}}
    <nav class="cav-panel__tabs" aria-label="{{ $module['title'] }} sections">
        @foreach ($tabs as $key => $label)
            <a class="cav-panel__tab{{ $tab === $key ? ' cav-panel__tab--on' : '' }}"
               href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['key'], 'tab' => $key]) }}"
               @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="cav-panel__body">
        @if ($tab === 'overview')
            {{-- THE ENGINEER LINK, WHENEVER ONE EXISTS (Plan 47-01, D-01
                 remainder / D-02). Rendered ABOVE the Visits card and
                 BEFORE the Progress ring, so the thing a PM most often
                 wants on an ordinary day — "copy the link and send it" —
                 is the first thing this tab shows, not something found only
                 after a creation collided. `link-card.blade.php` renders
                 nothing when `$link` is null (rams/om, or a module with no
                 document yet). --}}
            <x-cockpit.link-card :link="$link" />

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

            @if ($reportsVisits)
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Visits</span>

                    {{-- The existing component, so the D-02 reconstructed and
                         D-04 superseded treatments reach the panel unchanged
                         rather than being re-implemented here.

                         NO `controls` HERE, AND NO `action` EITHER (Plan
                         46.1-05, D-02). Overview still says where every visit
                         stands — accepted by whom, sent back when, awaiting
                         the engineer, scope locked, snags raised — and it
                         offers nothing. 46.1-05 moved the four acts beneath the
                         evidence on the Returned tab; 46.2 D-02 then took the
                         Returned tab off this page entirely, so THE READ-ONLY
                         LIST IS NOW THE ONLY VISIT SURFACE HERE — kept
                         deliberately by 46.2 D-06, because a row that reports
                         is not a row that offers. The four acts are still live
                         at their own routes (see the docblock).
                         With no controls there is no form to disclose, so the
                         two disclosure props are DROPPED rather than left as
                         wiring that can never fire. --}}
                    @foreach ($visits as $visit)
                        <x-cockpit.visit-row
                            :visit="$visit"
                            :project="$project"
                            :module="$module" />
                    @endforeach
                </div>
            @endif

            {{-- WHAT THIS MODULE HAS ACTUALLY PRODUCED (quick task 260928-dq2).

                 This is the card whose absence was the defect: the drawer had
                 no way to report a document at all on Overview, so two of the
                 four rows could only ever say "nothing recorded".

                 It is a SECOND surface for the same records, and that is the
                 point rather than a duplication: Overview is where the PM
                 decides what to do next, so each row carries the module's own
                 mapped action — `Edit` for the RAMS and the O&M, whose routes
                 land on a form, `View` for the rest — and the finished Word and
                 PDF. The word is read from the presenter, not written here.

                 Every value is escaped. `formats` defaults to `[]` so a caller
                 that passes an older row shape renders a plain row rather than
                 throwing. --}}
            @if ($documents->isNotEmpty())
                <div class="cav-panel__card">
                    <span class="cav-panel__card-head">Documents</span>

                    @foreach ($documents as $document)
                        <x-cockpit.document-row
                            :name="$document['name']"
                            :produced="$document['produced_at']"
                            :status="$document['status']"
                            :route="$document['route']"
                            :action="$document['action'] ?? 'View'"
                            :formats="$document['formats'] ?? []" />
                    @endforeach
                </div>
            @endif

            @if ($reportsNothing)
                <x-cockpit.hint>{{ $module['title'] }} has nothing recorded against it yet.</x-cockpit.hint>
            @endif

            {{-- THE RECENT ACTIVITY CARD WAS HERE (sketch 004 D-14), AND IT
                 LEFT BY 46.3 D-03 — the same treatment the quick-actions
                 block and the Returned tab got below.

                 It is now `x-cockpit.activity-panel`, rendered at PAGE level
                 from cockpit.blade.php as a sibling of `.cav-modules`. The
                 reason is the one this card's own comment always gave:
                 `ProjectActivityLog` has no module column, so the feed showed
                 the same entries whichever module was open. It is
                 project-level data and a module's panel was never its place.

                 NOTHING ABOUT THE FEED CHANGED — same collection, same
                 `x-cockpit.activity-row`, same empty sentence. Only its
                 position. D-14's wording is kept verbatim in the sketch with
                 a narrowing note above it. --}}

            {{-- THE QUICK ACTIONS BLOCK AND THE RETURNED TAB WERE HERE
                 (46.2 D-02, Plan 46.2-03). `x-cockpit.quick-actions` rendered
                 the Create visit form at the bottom of Overview (D-15) and
                 `x-cockpit.returned-tab` rendered the engineer's returned
                 evidence with Accept / Send back / Add note / Raise a snag
                 beneath it. Both Blade components were deleted from disk on
                 this repo's own established rule — CockpitPageTest's ruling on
                 the superseded accordion: "a dead drawer component left on
                 disk is an invitation for a later agent to render one beside
                 the new design."

                 THE CAPABILITY WAS NOT DELETED. Every one of those five acts
                 and the photo ZIP still lives at its route, listed in this
                 file's docblock. THE DOCUMENT FORM IS RENDERED IN THIS POSITION
                 INSTEAD (Plan 46.2-05), and it is what gives this page its
                 purpose back: between 46.2-03 and 46.2-05 the cockpit could
                 generate nothing at all. --}}

            {{-- D-05's TICK, AND IT IS A ROUTE RATHER THAN A GATE (Plan
                 46.5-05). When the project's RAMS deliverable is ticked
                 required, an install's panel says so in ONE ESCAPED SENTENCE
                 and links to the RAMS module's own wizard at step 1. That is
                 all "as part of an install" means here: the SAME RAMS document,
                 reached from the worksheet. There is no second RAMS creation
                 path, and the sentence gates nothing — the form below renders
                 in every deliverable state.

                 The presenter decides whether there is a prompt and what it
                 says; this file decides no deliverable and no copy of its own,
                 exactly as it decides no field and no step of its own. The enum
                 never reaches here.

                 NO NEW CSS. `.cav-hint` is the drawer's existing explainer
                 paragraph and the anchor is a bare `<a>` inheriting the page's
                 link styling, so `resources/css/cockpit.css` — a Vite entry —
                 is untouched and no `npm run build` is needed. The markup is
                 written out rather than passed to `x-cockpit.hint`, whose slot
                 is `{{ $slot }}` and would escape the anchor into text. --}}
            @if (($module['prompt'] ?? null) !== null)
                <p class="cav-hint">
                    {{ $module['prompt']['text'] }}
                    <a href="{{ route('projects.cockpit', ['project' => $project, 'module' => $module['prompt']['module'], 'tab' => 'overview', 'action' => 'generate', 'step' => 1]) }}">{{ $module['prompt']['label'] }}</a>
                </p>
            @endif

            {{-- ONE COMPONENT, EVERY DOCUMENT. It renders whatever
                 `CockpitDocumentFormPresenter::DOCUMENT_FIELD_MAP` gives it and
                 branches on FIELD TYPE, never on which document this is. Overview
                 only, on the same reasoning the Quick actions block used: the
                 Files tab is where documents are READ and this is where one is
                 ASKED FOR. --}}
            <x-cockpit.doc-form
                :project="$project"
                :module="$module"
                :tab="$tab"
                :action="$action"
                :fields="$docFields"
                :readiness="$docReadiness"
                :formats="$docFormats"
                :intro="$docIntro"
                :values="$docValues"
                :resources="$docResources"
                {{-- WHAT THE MODULE ALREADY HOLDS, so the closed control can
                     say "Regenerate" instead of "Create document" once a
                     document exists (quick task 260928-dq2, the user's
                     *"ONCE A DOC HAS BEEN CREATED , CAN THE BUTTON UNDER
                     OVERVIEW CHANGES TO REGENERATE"*). The SAME collection
                     the Documents card above renders — one derivation, so the
                     card and the control cannot disagree about whether this
                     module has anything. Passed for all four modules: a
                     worksheet that already exists is regenerated too. --}}
                :documents="$files"
                :steps="$docSteps"
                :step="$docStep"
                :step-title="$docStepTitle" />
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
        @elseif ($tab === 'notes')
            {{-- Notes the module itself recorded, plus the project's logged
                 notes. Never Project::notes, which is a project-level field
                 and would print the same paragraph under every module (all four
                 of them since 46.2 D-01; it was nine when this was written). --}}
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
        @elseif ($tab === 'returned')
            {{-- THE RETURNED TAB (Plan 47-03, D-04). Only reachable when
                 `$offersReturned` is true — `resolveTab()` coerces every
                 other case to Overview before this file ever sees `$tab`.
                 `$visits` is the SAME collection the Visits card above
                 renders; `returned-tab.blade.php` filters it to the ones
                 carrying a non-null entry in `$evidence` and draws the
                 calm reference order: state → hand-off link → rooms →
                 gallery → sign-off → controls.

                 `:action` AND `:action-visit-id` ARE NEW (Plan 47-04, D-03) —
                 the same two values the document form already uses, threaded
                 through to the visit-management controls `returned-tab`
                 now renders beneath each visit's evidence. --}}
            <x-cockpit.returned-tab
                :visits="$visits"
                :evidence="$evidence"
                :project="$project"
                :module="$module"
                :action="$action"
                :action-visit-id="$actionVisitId" />
        @endif
    </div>
</aside>

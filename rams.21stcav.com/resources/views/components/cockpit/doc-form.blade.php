{{--
    THE DOCUMENT FORM (Phase 46.2, Plan 46.2-05; 46.2 D-03/D-04/D-05).

    One component that renders ANY document's field map. This is the control the
    cockpit lost when Plan 46.2-03 deleted `quick-actions.blade.php`, rebuilt one
    plan later in the PANEL and driven by data instead of by markup.

    ── IT MUST NEVER BRANCH PER DOCUMENT ────────────────────────────────────

    There is no `@if` on a document key anywhere in this file, and
    `CockpitDocumentFormTest::test_the_component_names_no_document_and_switches_on_type_instead()`
    asserts it by grep. The file iterates `groups` and switches on `type`, of
    which there are EIGHT and the set is closed
    (`CockpitDocumentFormPresenter::TYPE_*`). SEVEN BECAME EIGHT IN PLAN
    46.5-06: `TYPE_SPACE_LIST` is the project's own spaces, confirmed on the
    site survey's step 3 with every one ticked (46.5 D-02). The branch is on
    the TYPE, so this file still names no document.

    The reason is this page's own history: its CONTENTS have changed four times
    (sketch 002 -> sketch 004 -> PMV-style review -> document creation) while its
    visual design held. The fifth change must be A ROW IN A MAP, not a rewrite of
    this file. So: to add a field, edit
    `CockpitDocumentFormPresenter::DOCUMENT_FIELD_MAP`. To add a document, add one
    row there and one in `CockpitModulePresenter::MODULE_MAP`. Nothing here needs
    touching for either.

    THE FIELDS ARE NOT THIS FILE'S OPINION. The map's grep gate proves every
    field is read by a named generator, so a field that reaches this component is
    a field the document actually renders. "A field the generator ignores is a
    field that teaches a PM to fill in noise" (46.2-CONTEXT D-03).

    ── NO JAVASCRIPT. THE DISCLOSURE IS URL STATE ──────────────────────────

    Closed is the bare module URL and open is `?action=generate`, resolved by
    membership against `ProjectCockpitController::ACTIONS`. Cancel is an ANCHOR
    back, so the browser's back button and the control agree about "closed".

    Alpine IS loaded globally by this application's layout, so it was available
    and was ruled out for a fifth time: `CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES`
    bans all nine handler attributes inside the `cav-cockpit` region, and
    weakening a fence to fit a design is the failure that fence exists to catch.
    No x-data, no x-show, no x-init, no x-if, no x-text, no x-on:, no @click, no
    wire:, no onclick.

    ── THE FENCE ENTRIES THIS FILE MUST RESPECT ────────────────────────────

      `<select`  STILL BANNED (FORBIDDEN_MARKUP, re-taken by 46.2-03 and NOT
                 lifted here). It was genuinely up for retirement on the most
                 input-heavy surface this page has carried, and it survived
                 because the map resolves to text / date / time / textarea /
                 checkbox / 2-to-4-option radio / resource-list only. Not one
                 field needs a dropdown. If one ever does, LIFT THE ENTRY BY NAME
                 in the commit that ships the control — never by deletion.
      `<script`  STILL BANNED.
      The 21 DEFERRED_AFFORDANCES. `Generate document` — still the SUBMIT
      button's copy — is on none of them and was checked against the list before
      use; every neighbouring string a designer might reach for — `Add document`,
      `Upload files`, `Issue to client`, `Mark as sent`, `Download`, `Export CSV`,
      `Open register` — IS banned. That is why the copy is the retired
      quick-actions' own word.
      RE-CHECKED ON 2026-09-27 FOR THE STRINGS THIS CHANGE ADDED:
      `Generating creates the engineer link, Word and PDF.`, `Parking onsite` /
      `No parking` / `Unknown` (the parking radios, which live in the MAP) and
      `Survey Engineer`. None is a substring of any of the 21 entries and none
      contains `<select` or `<script`. NOTHING WAS LIFTED — the list stays 21
      and FORBIDDEN_MARKUP stays 2.
      RE-CHECKED BY PLAN 46.3-01 FOR THE CLOSED CONTROL'S NEW COPY (D-05):
      `Create document — Word or PDF` / `Create document — Word` collides with no
      entry as a substring, and with neither FORBIDDEN_MARKUP entry. Nothing was
      lifted — a fence entry is never lifted for a label. The list stays 21 and
      the check is now an assertion in CockpitDocumentFormTest, so the next copy
      edit cannot collide quietly.

    ── ESCAPED OUTPUT ONLY (T-46.2-17) ────────────────────────────────────

    `{{ }}` everywhere, including every validation message. A submitted value is
    never reflected raw. Unescaped output is forbidden in every cockpit component
    and `CockpitPanelTest` greps this directory for it.

    ── A FIELD THE PROJECT ALREADY ANSWERS IS TEXT, NOT AN INPUT (DC-11) ───

    The map gives those fields `rules => ['prohibited']` and `readonly => true`.
    They are rendered as VALUES, because a `readonly` input still SUBMITS, and a
    submitted value would then trip `prohibited` and turn an ordinary submission
    into a validation failure the PM cannot fix. The prohibition is the server's
    guard against a hand-crafted POST; the page simply does not ask.

    ── LABOUR RESOURCES SUPPLY NAMES, NOTHING ELSE (LR-04) ────────────────

    `resource-list` options are NAMES, resolved by the controller with a query
    that selects `name` and nothing else. Never an email, never a phone, never an
    id: the RAMS stores engineers as free text, so the NAME is the value.

    A list-valued field (`array` in its own rules) renders CHECKBOXES; a
    single-valued one renders RADIOS. That multiplicity is read off the field's
    rules, so it stays a map decision and never becomes a per-document branch.

    ── FORMATS, AND THE ONE GAP (46.2 D-05, DC-07) ────────────────────────

    THE FORMAT RADIOS ARE GONE (2026-09-27, the user's item 8) AND THE MAP'S
    `formats` ARE UNTOUCHED. The radios asked which file the PM wanted; the
    answer travelled no further than a flash word, because ONE creation already
    produces the document, the visit and the engineer link together and both
    files are served by routes that exist. A question whose answer changes
    nothing is a leftover. What stands in its place NAMES the outputs — engineer
    link, Word, PDF — and is built from `$offered`, so it can promise only what
    the map holds. NO FORMAT WAS INVENTED.

    `formats` still mirrors `46.2-FORMAT-INVENTORY.md` exactly and a NULL cell
    is still SAID, not hidden, because a control that 404s is worse than a
    sentence that explains. The worksheet has no PDF: there is no worksheet PDF
    Blade, and `worksheets.engineer-report-pdf` is a different document that
    404s for exactly the PM this panel serves. Recorded NOT DELIVERED;
    `DocumentFormatInventoryTest` asserts the missing set is exactly
    `worksheet -> pdf`, and it is GREEN across this change.

    `format` itself still exists on the request, is still `required` on a
    creation and is still membership-checked against THIS document's offered
    keys. An ABSENT one now defaults to the first offered format in
    `CockpitDocumentRequest::prepareForValidation()`; a PRESENT but unoffered
    one is still a validation error, so `format=pdf` on the worksheet still
    fails exactly as it did.

    ── ONE STEP AT A TIME (Phase 46.5, Plan 46.5-04; 46.5 D-02, GCW-02) ────

    A 17-field wall was called "scary" and the user asked for three short
    steps in their own words. So this file now renders ONE STEP AT A TIME —
    and it does so WITHOUT a single branch on a document key, because the
    step is read off each GROUP's own `step` key, which Plan 46.5-01 put on
    the map. The slice is `($group['step'] ?? null) === $step`. That is the
    sixth change to this page's contents and it is still a map edit.

    THE COMPATIBILITY PATH IS FIRST AND IT IS ABSOLUTE: `steps === []` means
    this document has no wizard, and it renders EXACTLY as it did before this
    plan — every group, the outcome line, the one submit. The O&M relies on
    that permanently and RAMS relies on it until Plan 46.5-05 mints its steps.

    A group carrying `step => null` is shown by NO step. That is how the
    Comms room group left this office form (46.5 D-03, "dont need comms
    room") WITHOUT leaving the product: it is still captured on site through
    the engineer link, still renders in the site-survey Word document and
    still rides the survey->install carry-forward, where an installing
    engineer reads the surveyor's access notes. Removing it from THOSE would
    be a safety regression. Read the map's own comment beside that `null`.

    ── CARRY-FORWARD IS HIDDEN INPUTS, AND THEY ARE NOT TRUSTED ────────────

    Every field belonging to another INTEGER step, and not `readonly`, rides
    along as a hidden input so a `next` from step 2 still submits step 1's
    answers. A hidden input is exactly as attacker-controlled as a visible
    one, so the server RE-VALIDATES all of them on the final submit —
    `CockpitDocumentRequest::groupsToValidate()` adds every step's rules when
    `intent=create`, never only the claimed step's (T-46.5-04-02/03).

    A `readonly` field is NEVER carried. It holds `rules => ['prohibited']`,
    so submitting it would turn an ordinary submission into a validation
    failure the PM cannot fix — the same reason it is printed as a value
    rather than as a `readonly` input a few lines further down.

    ── THE WIZARD'S COPY, CHECKED BEFORE USE ──────────────────────────────

    `Next`, `Back` and `Step 1 of 3` (and every `step_titles` entry the map
    holds) were each checked AS A SUBSTRING against all 21
    DEFERRED_AFFORDANCES keys and both FORBIDDEN_MARKUP entries before use.
    Not one collides, and nothing was lifted — A FENCE ENTRY IS NEVER LIFTED
    FOR A LABEL. The list stays 21, FORBIDDEN_MARKUP stays 2, and the check
    is an assertion in CockpitDocumentFormTest so a later copy edit cannot
    collide quietly.

    `<select` IS STILL BANNED AND WAS NOT NEEDED. Every control the wizard
    renders was already in the map's closed type set.

    ── NO CSS WAS ADDED FOR THE WIZARD, ON PURPOSE ────────────────────────

    The progress line reuses `.cav-qa__intro` (the existing one-line guidance
    style) and carries `.cav-qa__step` as a test hook with no rule behind it;
    Back reuses `.cav-qa__control`. `resources/css/cockpit.css` is a Vite
    entry, so touching it would make the deploy need `npm run build` — and
    the stretched-link trap lives in that file. Reusing what is there costs
    nothing and risks nothing.

    ── NO COLOUR, AND NO `position` ───────────────────────────────────────

    Not one hex value in this file (`CockpitPageTest` greps for it) — every
    class is `cav-`-prefixed and reuses the existing `.cav-qa__*` family.
    Nothing here carries `position` OR `transform`, because this block sits
    inside `.cav-module`'s panel and either property on an anchor there
    collapses the row's stretched click target onto a 28px glyph with nothing
    failing.

    THE GREEN GENERATE CONTROL (2026-09-27, item 9) IS A MODIFIER CLASS,
    `.cav-qa__go`, and its colour is the `--cav-go` / `--cav-go-dark`
    token pair on `.cav-brand` in `cav-tokens.css`. The rule added to
    `cockpit.css` sets `background` and `border-color` and nothing else — no
    `position`, no `transform`. It is on the two controls that GENERATE and on
    neither Next nor Back, so the colour keeps meaning one thing.
--}}
@props([
    'project',
    'module',
    'tab'       => 'overview',
    'action'    => null,
    'fields'    => [],
    'readiness' => [],
    'formats'   => [],
    'intro'     => null,
    'values'    => [],
    'resources' => [],
    // WHAT THIS MODULE ALREADY HOLDS (quick task 260928-dq2) — the same
    // per-module collection `CockpitPanelPresenter::files()` gives the panel.
    // Read ONLY for its emptiness; not one field of a document is rendered
    // here, so this file still shows no document CONTENT and needs no
    // `patchRamsForDisplay()` (see the closed control below).
    'documents' => [],
    // THE WIZARD'S THREE (Plan 46.5-04). All three are derived in
    // ProjectCockpitController from CockpitWizardPresenter, which slices the
    // field map. `steps === []` is "this document has no wizard" and is the
    // compatibility path.
    'steps'     => [],
    'step'      => 1,
    'stepTitle' => null,
])

@php
    /** The type constants, so this file switches on the map's own closed set. */
    $types = \App\Support\Cockpit\CockpitDocumentFormPresenter::class;

    $moduleUrl = route('projects.cockpit', ['project' => $project, 'module' => $module['key']]);
    $openUrl   = route('projects.cockpit', ['project' => $project, 'module' => $module['key'], 'action' => 'generate']);

    $isOpen = $action === 'generate';

    // OFFERED vs MISSING, both read from the map. The missing set is what the
    // panel SAYS; it is never silently dropped.
    $offered = array_filter($formats, static fn (?string $routeName): bool => $routeName !== null);
    $missing = array_keys(array_filter($formats, static fn (?string $routeName): bool => $routeName === null));

    // The only two format words this page uses. Keyed by the map's own keys, so
    // a third format would render its key rather than silently vanish.
    $formatLabels = ['word' => 'Word', 'pdf' => 'PDF'];

    // THE CLOSED CONTROL NAMES WHAT IS BEHIND IT (D-05, requirement DL-05).
    // The Word/PDF radios have existed since 46.2-05 and render per document,
    // but they sat behind a control reading "Generate document" — which reads
    // like it PRODUCES A FILE when it opens a form — so the user asked "can
    // output be word and pdf" while looking at a page that already did both.
    // A capability that cannot be discovered reads as absent.
    //
    // NOTHING ABOUT THE FORMATS CHANGES. The copy is built from `$offered`
    // above — the presenter's own map — so it can never promise a format this
    // module does not have. The Worksheet has no PDF path (DC-07) and this
    // control must not imply one; `$missing` still SAYS so on the open form.
    //
    // The joining word comes from the COUNT, not from an assumption of two: a
    // third format added to the map would read correctly without an edit here.
    $offeredWords = array_values(array_map(
        static fn (string $key): string => $formatLabels[$key] ?? $key,
        array_keys($offered),
    ));

    $offeredPhrase = match (true) {
        $offeredWords === []      => '',
        count($offeredWords) === 1 => $offeredWords[0],
        default                    => implode(', ', array_slice($offeredWords, 0, -1))
                                      .' or '.$offeredWords[count($offeredWords) - 1],
    };

    // CHECKED AS A SUBSTRING AGAINST ALL 21 `DEFERRED_AFFORDANCES` KEYS AND
    // BOTH `FORBIDDEN_MARKUP` ENTRIES BEFORE USE, the same check 46.2-05 made
    // for "Generate document". It collides with none — and `Add document`,
    // `Upload files`, `Issue to client`, `Open register`, `Export CSV` and
    // `Download`, every neighbouring string a designer might reach for, are all
    // banned. A fence entry is NEVER lifted for a label. The check is itself
    // asserted by CockpitDocumentFormTest so a later copy edit cannot collide
    // quietly.
    // ── THE VERB FOLLOWS THE FACTS (quick task 260928-dq2) ─────────────────
    //
    // The user: *"ONCE A DOC HAS BEEN CREATED , CAN THE BUTTON UNDER OVERVIEW
    // CHANGES TO REGENERATE AND EDIT"*. "Create document" on a module that
    // already holds two documents is the same class of defect D-05 fixed
    // above — a control whose copy does not describe what is behind it.
    //
    // IT IS THE SAME CONTROL AND THE SAME ROUTE. The form still posts to
    // `projects.cockpit.documents.store`, which already produces a NEW
    // document every time, so "Regenerate" is a TRUE description of what this
    // does rather than a new capability. No route is added.
    //
    // `RamsController::regenerate` is DELIBERATELY NOT CALLED HERE. It exists
    // (~:949) and it does the supersede-and-rebuild dance properly, but it is
    // a RAMS-ONLY POST — wiring it would put a document key back into a file
    // whose whole discipline is that it holds none. The cockpit's own
    // generation path is document-agnostic and already regenerates.
    //
    // EDIT IS NOT HERE, AND THAT IS ON PURPOSE. It belongs to a DOCUMENT, not
    // to the module, so it is an anchor on each `x-cockpit.document-row`
    // above. Putting it here would also break
    // `CockpitDocumentFormTest`'s ruling that the closed form carries EXACTLY
    // ONE `cav-qa__control`, and that ruling is kept rather than weakened.
    //
    // THE COPY WAS CHECKED AS A SUBSTRING against all 21
    // `DEFERRED_AFFORDANCES` keys and both `FORBIDDEN_MARKUP` entries before
    // use, the same check 46.2-05 and 46.3-01 made. "Regenerate" collides with
    // none. It is ALSO deliberately not "Regenerate document": the closed
    // control must never read as the open form's submit, whose copy is the
    // one that genuinely generates.
    $holdsDocument = collect($documents)->isNotEmpty();

    $createVerb = $holdsDocument ? 'Regenerate' : 'Create document';

    $openLabel = $offeredPhrase === ''
        ? $createVerb
        : $createVerb.' — '.$offeredPhrase;

    // THE OUTCOME, IN PLACE OF THE FORMAT QUESTION (2026-09-27, item 8).
    // Every part is DERIVED: the formats from `$offeredPhrase` above, the
    // engineer link from `CockpitCombinedCreator::handles()`, which is the same
    // predicate the controller branches on. Read, never re-decided here.
    $issuesLink = \App\Support\Cockpit\CockpitCombinedCreator::handles($module['key']);

    // The user's own three words, in their own order: "engineer link / word /
    // pdf". The format words come from the map, so a document without a PDF
    // names only what it has.
    $outcomeParts = array_values(array_filter(array_merge(
        [$issuesLink ? 'the engineer link' : null],
        $offeredWords,
    )));

    $outcomeList = match (true) {
        $outcomeParts === []       => '',
        count($outcomeParts) === 1 => $outcomeParts[0],
        default                    => implode(', ', array_slice($outcomeParts, 0, -1))
                                      .' and '.$outcomeParts[count($outcomeParts) - 1],
    };

    $outcomeSentence = $outcomeList === ''
        ? 'Generating creates this document.'
        : 'Generating creates '.$outcomeList.'.';

    $textLike = [$types::TYPE_TEXT, $types::TYPE_DATE, $types::TYPE_TIME];

    // ── THE WIZARD (Plan 46.5-04) ──────────────────────────────────────────
    //
    // THE COMPATIBILITY PATH FIRST, because it is the one a reader must not
    // have to hunt for: a document with no steps keeps every group and every
    // control it had before this plan existed.
    $stepped = $steps !== [];

    $index      = $stepped ? array_search($step, $steps, true) : false;
    $index      = $index === false ? 0 : $index;
    $stepTotal  = count($steps);
    $stepNumber = $index + 1;
    $isFirst    = ! $stepped || $index === 0;
    $isLast     = ! $stepped || $index === $stepTotal - 1;

    // Sliced by the GROUP's own `step`, so this file still names no document.
    // A `step => null` group belongs to no step and is therefore shown by
    // none — that is the comms-room ruling (46.5 D-03), enforced by a data
    // comparison rather than by a name.
    $shown = $stepped
        ? array_values(array_filter(
            $fields,
            static fn (array $group): bool => ($group['step'] ?? null) === $step,
        ))
        : $fields;

    // CARRY-FORWARD: another integer step's own fields, never a `readonly`
    // one (it is `prohibited`, so carrying it would fail a submission the PM
    // cannot fix) and never a `step => null` one (it is off the wizard, and
    // submitting an empty value for it would overwrite what the engineer
    // captures on site).
    $carried = [];

    if ($stepped) {
        foreach ($fields as $group) {
            $groupStep = $group['step'] ?? null;

            if (! is_int($groupStep) || $groupStep === $step) {
                continue;
            }

            foreach ($group['fields'] as $field) {
                if (($field['readonly'] ?? false) === true) {
                    continue;
                }

                $carried[] = $field;
            }
        }
    }
@endphp

<div class="cav-qa">
    <span class="cav-panel__card-head">Generate</span>

    @if (! $isOpen)
        {{-- ONE control per module. The form is a URL away, not a widget away.
             Its copy is DERIVED from $offered (D-05) — built in the props block
             above; do not write the word for the directive here, Blade compiles
             it even inside a comment and leaves the PHP block unterminated. The
             submit button below keeps "Generate document", because that control
             genuinely does generate. --}}
        <a class="cav-qa__control cav-qa__go" href="{{ $openUrl }}">{{ $openLabel }}</a>
    @else
        @if ($intro !== null)
            <p class="cav-qa__intro">{{ $intro }}</p>
        @endif

        @if (count($readiness) > 0)
            {{-- THE READINESS LIST IS NEVER EDITABLE. These are things a PM must
                 go and fix elsewhere — rooms, equipment, drawings — and the list
                 is the generator's OWN missing-field output, read off the
                 exception it already throws. The panel forms no second opinion,
                 so it and the generator cannot disagree. --}}
            <div class="cav-qa__ready">
                <span class="cav-qa__label">Not ready yet</span>

                <ul class="cav-qa__ready-list">
                    @foreach ($readiness as $item)
                        <li class="cav-qa__ready-item">{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="cav-qa__form" method="POST" action="{{ route('projects.cockpit.documents.store', $project) }}">
            @csrf

            {{-- The document is carried in the payload so the write lands back on
                 the drawer the PM had open. It is validated with Rule::in the
                 map's own keys BEFORE any lookup, never trusted (T-46.2-12). --}}
            <input type="hidden" name="module" value="{{ $module['key'] }}">

            {{-- The tab this generation was initiated FROM, on the same mechanism
                 and the same component the four visit acts used — one definition,
                 so they cannot drift. Validated against TABS on the way out here
                 and again on the way in. --}}
            <x-cockpit.tab-field :tab="$tab" />

            @if ($stepped)
                {{-- WHICH STEP THIS SUBMISSION IS LEAVING. Membership-resolved
                     on the way in against the document's own step list, so an
                     edited value discloses step 1 rather than being rejected —
                     the identical treatment `?step=` gets on the GET. --}}
                <input type="hidden" name="step" value="{{ $step }}">

                {{-- CARRY-FORWARD. See this file's header: a hidden input is as
                     attacker-controlled as a visible one, so every value here
                     is RE-VALIDATED on the final submit. A `readonly` field is
                     never among them — it carries `rules => ['prohibited']`,
                     and submitting one would turn an ordinary submission into a
                     validation failure the PM has no way to fix. --}}
                @foreach ($carried as $field)
                    @php
                        $carryKey   = $field['key'];
                        $carryList  = in_array('array', $field['rules'], true);
                        $carryValue = old($carryKey, $values[$carryKey] ?? '');
                    @endphp

                    @if ($carryList)
                        @foreach (is_array($carryValue) ? $carryValue : [] as $one)
                            <input type="hidden" name="{{ $carryKey }}[]" value="{{ $one }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $carryKey }}" value="{{ is_array($carryValue) ? '' : $carryValue }}">
                    @endif
                @endforeach

                {{-- WHERE THE PM IS, IN THE NUMBERS THEY CAN SEE ON THE PAGE.
                     Both figures come from the document's own step list, so a
                     fourth step reads correctly without an edit here. The title
                     is the map's `step_titles` entry — named once, per
                     document, never invented twice. --}}
                <p class="cav-qa__intro cav-qa__step">Step {{ $stepNumber }} of {{ $stepTotal }}{{ $stepTitle === null ? '' : ' · '.$stepTitle }}</p>
            @endif

            @if ($errors->any())
                <ul class="cav-qa__errors">
                    @foreach ($errors->all() as $message)
                        {{-- Escaped, always (T-46.2-17). --}}
                        <li class="cav-qa__error">{{ $message }}</li>
                    @endforeach
                </ul>
            @endif

            @foreach ($shown as $group)
                <fieldset class="cav-qa__group">
                    <legend class="cav-qa__legend">{{ $group['legend'] }}</legend>

                    @foreach ($group['fields'] as $field)
                        @php
                            $key      = $field['key'];
                            $isList   = in_array('array', $field['rules'], true);
                            $readonly = ($field['readonly'] ?? false) === true;
                            $current  = old($key, $values[$key] ?? '');
                            $chosen   = is_array($current) ? $current : [$current];

                            // THE SPACES, AND THE DEFAULT-ALL RULE (Plan
                            // 46.5-06; 46.5 D-02 "default all"). The options
                            // come from the bag the controller filled, keyed by
                            // the map's own named source - no query here, and
                            // no per-document branch.
                            //
                            // DEFAULT ALL IS A RENDER DECISION AND NOTHING
                            // ELSE. `old()` ABSENT means the PM has not
                            // answered yet, so every space is shown ticked. A
                            // STORED default would mean persisting something
                            // before the final step, which GCW-03 forbids.
                            //
                            // THE COST, STATED: an unticked-everything answer
                            // submits no key at all, so a Back-then-Next or a
                            // validation bounce re-ticks all of them. Step 3 is
                            // the LAST step, so the only way back through here
                            // is deliberate, and re-confirming is a smaller
                            // harm than a hidden companion input that would put
                            // a second control name on the form.
                            $spaces      = $resources[$field['prefill'] ?? ''] ?? [];
                            $spaceTicked = old($key) === null
                                ? $spaces
                                : (is_array(old($key)) ? old($key) : []);
                        @endphp

                        @if ($readonly)
                            {{-- Already answered by the project: shown, not asked. --}}
                            <div class="cav-qa__field">
                                <span class="cav-qa__label">{{ $field['label'] }}</span>
                                <span class="cav-qa__value">{{ $current === '' ? 'Not recorded' : $current }}</span>
                            </div>
                        @elseif (in_array($field['type'], $textLike, true))
                            <label class="cav-qa__field">
                                <span class="cav-qa__label">{{ $field['label'] }}</span>
                                <input class="cav-qa__input"
                                       type="{{ $field['type'] }}"
                                       name="{{ $key }}"
                                       value="{{ $current }}">
                            </label>
                        @elseif ($field['type'] === $types::TYPE_TEXTAREA)
                            <label class="cav-qa__field cav-qa__field--wide">
                                <span class="cav-qa__label">{{ $field['label'] }}</span>
                                <textarea class="cav-qa__input cav-qa__area"
                                          name="{{ $key }}"
                                          rows="3">{{ $current }}</textarea>
                            </label>
                        @elseif ($field['type'] === $types::TYPE_CHECKBOX)
                            <label class="cav-qa__opt">
                                <input type="checkbox" name="{{ $key }}" value="1" @checked((bool) $current)>
                                <span>{{ $field['label'] }}</span>
                            </label>
                        @elseif ($field['type'] === $types::TYPE_RADIO)
                            <fieldset class="cav-qa__set">
                                <legend class="cav-qa__legend">{{ $field['label'] }}</legend>

                                @foreach ($field['options'] ?? [] as $value => $label)
                                    <label class="cav-qa__opt">
                                        <input type="radio"
                                               name="{{ $key }}"
                                               value="{{ $value }}"
                                               @checked((string) $current === (string) $value)>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @elseif ($field['type'] === $types::TYPE_RESOURCE_LIST)
                            <fieldset class="cav-qa__set">
                                <legend class="cav-qa__legend">{{ $field['label'] }}</legend>

                                @forelse ($resources[$field['prefill'] ?? ''] ?? [] as $name)
                                    {{-- NAME ONLY (LR-04). The value IS the name,
                                         because that is what the document stores. --}}
                                    <label class="cav-qa__opt">
                                        <input type="{{ $isList ? 'checkbox' : 'radio' }}"
                                               name="{{ $key }}{{ $isList ? '[]' : '' }}"
                                               value="{{ $name }}"
                                               @checked(in_array($name, $chosen, true))>
                                        <span>{{ $name }}</span>
                                    </label>
                                @empty
                                    {{-- THE CONTROL'S OWN EMPTY STATE, NOT A
                                         PARAGRAPH AFTER IT. It used to carry
                                         `.cav-qa__value` — 13px in `--cav-ink`,
                                         byte-for-byte the styling of an ANSWER
                                         — inside a wrapping flex row, so it
                                         read as stray prose at the foot of the
                                         group rather than as this fieldset's
                                         reply. `.cav-qa__empty` is muted and
                                         claims its own full row directly under
                                         the legend it belongs to.

                                         IT IS NOT HIDDEN AND THE CONTROL IS NOT
                                         HIDDEN EITHER. There are genuinely no
                                         active LabourResource rows on live;
                                         saying so, with the fix, is the honest
                                         answer. A hidden control would read as
                                         "engineers cannot be chosen here". --}}
                                    <span class="cav-qa__empty">Nobody active is on file for this. Add people under Labour resources.</span>
                                @endforelse
                            </fieldset>
                        @elseif ($field['type'] === $types::TYPE_SPACE_LIST)
                            <fieldset class="cav-qa__set">
                                <legend class="cav-qa__legend">{{ $field['label'] }}</legend>

                                @forelse ($spaces as $space)
                                    {{-- A CHECKBOX, NOT A DROPDOWN. `<select` is
                                         still banned and was not needed, so
                                         FORBIDDEN_MARKUP stays at 2. The value
                                         IS the space name, because that is what
                                         the visit's `rooms_in_scope` stores. --}}
                                    <label class="cav-qa__opt">
                                        <input type="checkbox"
                                               name="{{ $key }}[]"
                                               value="{{ $space }}"
                                               @checked(in_array($space, $spaceTicked, true))>
                                        <span>{{ $space }}</span>
                                    </label>
                                @empty
                                    {{-- SAID, NOT LEFT BLANK. An empty fieldset
                                         reads as "none selected", which is a
                                         different and wrong answer. --}}
                                    <span class="cav-qa__empty">No spaces are on file for this project yet.</span>
                                @endforelse

                                {{-- ── THE SELECT-ALL, WITHOUT JAVASCRIPT (260930-sv2) ──────
                                     Two more submits on the `intent` name the
                                     form ALREADY carries for Next and Back.
                                     `spaces-none` flashes an empty
                                     `visit_rooms` and re-renders this step;
                                     `spaces-all` drops the key so D-02's
                                     default-all takes over again. The cockpit's
                                     ban on `<script` and on the nine handler
                                     attributes is untouched, `<select` is still
                                     not used, and NO NEW CONTROL NAME appears —
                                     which is the exact objection that killed the
                                     companion-checkbox idea at 46.5-06.

                                     ONLY WHEN THERE IS SOMETHING TO BULK-CHANGE.
                                     One space needs no select-all, and zero
                                     spaces has the empty state above instead; a
                                     pair of buttons acting on nothing would be
                                     two dead controls.

                                     COPY CHECKED AGAINST THE FENCE before use:
                                     neither label contains any of the 21
                                     `DEFERRED_AFFORDANCES` keys or either
                                     `FORBIDDEN_MARKUP` entry. --}}
                                @if (count($spaces) > 1)
                                    <p class="cav-qa__note">
                                        {{ count($spaceTicked) }} of {{ count($spaces) }} ticked.
                                    </p>
                                    <div class="cav-qa__row">
                                        <button class="cav-qa__control" type="submit" name="intent" value="spaces-all">Tick all {{ count($spaces) }}</button>
                                        <button class="cav-qa__control" type="submit" name="intent" value="spaces-none">Tick none</button>
                                    </div>
                                @endif
                            </fieldset>
                        @endif
                        {{-- NO FALLBACK ARM ON PURPOSE. The EIGHT types are a
                             CLOSED set; a NINTH added to the map without a
                             branch here renders NO control, and
                             CockpitDocumentFormTest's "exactly the controls the
                             map implies" assertion goes red. A silent fallback
                             input would instead ship a field nothing reads.
                             SEVEN BECAME EIGHT IN PLAN 46.5-06 with
                             TYPE_SPACE_LIST, and the branch shipped in the same
                             commit as the constant. --}}
                    @endforeach
                </fieldset>
            @endforeach

            {{-- THE OUTPUT CHOICE IS ON THE LAST STEP ONLY, because that is the
                 step that produces something. Asking a PM to pick a file format
                 on step 1 of 3 is asking a question three screens before its
                 answer matters. A stepless document has one step by definition,
                 so this renders for it exactly as it always did. --}}
            @if ($isLast)
                {{-- WHAT THIS PRODUCES, STATED — NOT A QUESTION ABOUT IT.
                     The Format radios stood here until 2026-09-27. They were a
                     leftover: one creation already produces the document, the
                     visit and the engineer link together, and the chosen
                     format only ever changed a flash word. Nothing read it.

                     The sentence is BUILT FROM `$offered` — the map's own
                     `formats` — so no format is invented and the worksheet,
                     which has no PDF, cannot be promised one. The engineer-link
                     half is read from the creator's own `handles()`, the single
                     source of truth for which modules issue a link, so this
                     line and the creation cannot disagree.

                     No affordance is added: the outputs are named, not offered
                     as controls. `Download` is FORBIDDEN copy (a fence entry is
                     never lifted for a label) and the link is surfaced by the
                     visit row that already exists. --}}
                <p class="cav-qa__note">
                    {{ $outcomeSentence }}
                </p>

                @foreach ($missing as $format)
                    {{-- SAID, NOT HIDDEN (46.2 D-05). Recorded NOT DELIVERED rather
                         than papered over with a control that fails, or with a
                         different document wearing this one's name. --}}
                    <p class="cav-qa__note">{{ $formatLabels[$format] ?? $format }} is not available for this document (DC-07).</p>
                @endforeach
            @endif

            {{-- THE PRIMARY ACTION IS FIRST, as it already was, and that order
                 is load-bearing rather than cosmetic: pressing Enter in a text
                 field submits with the FIRST submit button, so a PM who types a
                 date and hits Enter goes FORWARD, never back.

                 `intent` travels as the BUTTON's own name/value, so which
                 control was pressed is the payload and there is no second
                 hidden field that could disagree with it. `create` is named
                 explicitly even on a stepless document, so one definition
                 serves both paths — it is the same value an absent `intent`
                 already defaults to. --}}
            <div class="cav-qa__row">
                @if ($isLast)
                    {{-- GREEN IS "THIS ONE PRODUCES SOMETHING" (item 9). The
                         modifier is on the two GENERATE controls only — this
                         submit and the closed opener above. Next, Back and
                         Cancel are navigation and stay as they were, so the
                         colour keeps meaning one thing. The colour itself is a
                         `--cav-go*` token pair on `.cav-brand`; no hex reaches
                         this file. --}}
                    <button class="cav-qa__control cav-qa__go" type="submit" name="intent" value="create">Generate document</button>
                @else
                    <button class="cav-qa__control" type="submit" name="intent" value="next">Next</button>
                @endif

                @if (! $isFirst)
                    <button class="cav-qa__control" type="submit" name="intent" value="back">Back</button>
                @endif

                <a class="cav-qa__cancel" href="{{ $moduleUrl }}">Cancel</a>
            </div>
        </form>
    @endif
</div>

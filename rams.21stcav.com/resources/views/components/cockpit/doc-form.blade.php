{{--
    THE DOCUMENT FORM (Phase 46.2, Plan 46.2-05; 46.2 D-03/D-04/D-05).

    One component that renders ANY document's field map. This is the control the
    cockpit lost when Plan 46.2-03 deleted `quick-actions.blade.php`, rebuilt one
    plan later in the PANEL and driven by data instead of by markup.

    ── IT MUST NEVER BRANCH PER DOCUMENT ────────────────────────────────────

    There is no `@if` on a document key anywhere in this file, and
    `CockpitDocumentFormTest::test_the_component_names_no_document_and_switches_on_type_instead()`
    asserts it by grep. The file iterates `groups` and switches on `type`, of
    which there are SEVEN and the set is closed
    (`CockpitDocumentFormPresenter::TYPE_*`).

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

    The two radios come from the map's `formats`, which mirrors
    `46.2-FORMAT-INVENTORY.md` exactly. A NULL cell is not hidden — it is SAID,
    because a control that 404s is worse than a sentence that explains. The
    worksheet has no PDF: there is no worksheet PDF Blade, and
    `worksheets.engineer-report-pdf` is a different document that 404s for
    exactly the PM this panel serves. Recorded NOT DELIVERED;
    `DocumentFormatInventoryTest` asserts the missing set is exactly
    `worksheet -> pdf`.

    ── NO COLOUR, AND NO `position` ───────────────────────────────────────

    Not one hex value in this file (`CockpitPageTest` greps for it) and no new
    colour in `cockpit.css` — every class is `cav-`-prefixed and reuses the
    existing `.cav-qa__*` family. Nothing here carries `position`, because this
    block sits inside `.cav-module`'s panel.
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
    $openLabel = $offeredPhrase === ''
        ? 'Create document'
        : 'Create document — '.$offeredPhrase;

    $textLike = [$types::TYPE_TEXT, $types::TYPE_DATE, $types::TYPE_TIME];
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
        <a class="cav-qa__control" href="{{ $openUrl }}">{{ $openLabel }}</a>
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

            @if ($errors->any())
                <ul class="cav-qa__errors">
                    @foreach ($errors->all() as $message)
                        {{-- Escaped, always (T-46.2-17). --}}
                        <li class="cav-qa__error">{{ $message }}</li>
                    @endforeach
                </ul>
            @endif

            @foreach ($fields as $group)
                <fieldset class="cav-qa__group">
                    <legend class="cav-qa__legend">{{ $group['legend'] }}</legend>

                    @foreach ($group['fields'] as $field)
                        @php
                            $key      = $field['key'];
                            $isList   = in_array('array', $field['rules'], true);
                            $readonly = ($field['readonly'] ?? false) === true;
                            $current  = old($key, $values[$key] ?? '');
                            $chosen   = is_array($current) ? $current : [$current];
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
                                    <span class="cav-qa__value">Nobody active is on file for this. Add people under Labour resources.</span>
                                @endforelse
                            </fieldset>
                        @endif
                        {{-- NO `@else` ARM ON PURPOSE. The seven types are a
                             CLOSED set; an eighth added to the map without a
                             branch here renders NO control, and
                             CockpitDocumentFormTest's "exactly the controls the
                             map implies" assertion goes red. A silent fallback
                             input would instead ship a field nothing reads. --}}
                    @endforeach
                </fieldset>
            @endforeach

            <fieldset class="cav-qa__set">
                <legend class="cav-qa__legend">Format</legend>

                @foreach ($offered as $format => $routeName)
                    <label class="cav-qa__opt">
                        <input type="radio"
                               name="format"
                               value="{{ $format }}"
                               @checked(old('format', (string) array_key_first($offered)) === $format)>
                        <span>{{ $formatLabels[$format] ?? $format }}</span>
                    </label>
                @endforeach
            </fieldset>

            @foreach ($missing as $format)
                {{-- SAID, NOT HIDDEN (46.2 D-05). Recorded NOT DELIVERED rather
                     than papered over with a control that fails, or with a
                     different document wearing this one's name. --}}
                <p class="cav-qa__note">{{ $formatLabels[$format] ?? $format }} is not available for this document (DC-07).</p>
            @endforeach

            <div class="cav-qa__row">
                <button class="cav-qa__control" type="submit">Generate document</button>
                <a class="cav-qa__cancel" href="{{ $moduleUrl }}">Cancel</a>
            </div>
        </form>
    @endif
</div>

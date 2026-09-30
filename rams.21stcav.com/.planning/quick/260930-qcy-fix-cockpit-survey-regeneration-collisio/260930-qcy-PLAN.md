---
quick_id: 260930-qcy
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Support/Cockpit/CockpitCombinedCreator.php
  - app/Http/Controllers/ProjectCockpitDocumentController.php
  - app/Http/Requests/CockpitDocumentRequest.php
  - resources/views/components/cockpit/doc-form.blade.php
  - tests/Feature/Cockpit/CockpitCombinedCreationTest.php
  - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  - tests/Feature/Cockpit/CockpitDocumentFormTest.php
autonomous: true
requirements: []

must_haves:
  truths:
    - "A PM who retries a survey creation on a project where a visit already claims the live survey gets a clear, actionable message instead of a 500/SQL integrity error — and the existing engineer link is visible on the page so they can copy or open it."
    - "The refusal message never says 'rolled back' or 'try again' when nothing was attempted — it states the true, permanent reason: a visit already claims this survey."
    - "A PM can reach the existing `site-surveys.supersede-from-project` action from the cockpit to start a genuinely fresh survey, without the plan reimplementing superseding."
    - "A PM can regenerate the survey Word/PDF (persist edited fields) without a new visit or engineer link ever being attempted."
    - "The worksheet path (`VisitLinkIssuer::worksheetFor()`, no adoption) is untouched by this plan."
    - "The read-only fence's pinned counts (2 FORBIDDEN_MARKUP / 21 DEFERRED_AFFORDANCES / 9 BANNED_HANDLER_ATTRIBUTES / 13 WRITE_SURFACE_TABLES, and the CSRF form count) are updated BY NAME in the same commit that ships the new control, never left stale and never weakened."
    - "CockpitCombinedCreator's docblock no longer claims the adopted-survey retry case is unconditionally safe; it names the visit-collision case this plan fixes."
  artifacts:
    - path: "app/Support/Cockpit/CockpitCombinedCreator.php"
      provides: "visitAlreadyClaimsSurvey() and liveSurvey() pre-transaction guards, called before create()"
    - path: "app/Http/Controllers/ProjectCockpitDocumentController.php"
      provides: "pre-transaction refusal with existing-link flash; regenerateDocumentOnly() intent handler"
    - path: "app/Http/Requests/CockpitDocumentRequest.php"
      provides: "INTENT_REGENERATE added to the closed INTENTS set"
    - path: "resources/views/components/cockpit/doc-form.blade.php"
      provides: "existing-link hint, supersede form, and the document-only regenerate submit — all for the site_survey module only"
    - path: "tests/Feature/Cockpit/CockpitCombinedCreationTest.php"
      provides: "regression test reproducing the live collision shape, plus regenerate-only coverage"
  key_links:
    - from: "ProjectCockpitDocumentController::createCombined()"
      to: "CockpitCombinedCreator::visitAlreadyClaimsSurvey()"
      via: "called BEFORE $this->creator->create(), mirroring the existing documentPreExists() pattern"
      pattern: "visitAlreadyClaimsSurvey"
    - from: "resources/views/components/cockpit/doc-form.blade.php"
      to: "site-surveys.supersede-from-project"
      via: "a dedicated <form method=POST> with @csrf, site_survey module only"
      pattern: "supersede-from-project"
    - from: "CockpitDocumentRequest::INTENT_REGENERATE"
      to: "ProjectCockpitDocumentController::regenerateDocumentOnly()"
      via: "store() branches on intent() before the advance()/createCombined() branches"
      pattern: "regenerateDocumentOnly"
---

<objective>
Fix the permanent, reproducible SQLSTATE 23000 collision on `visits_source_unique`
(`site_survey-33`, `site_survey-34` on live, project 99, 2026-09-30) caused by
`CockpitCombinedCreator::create()` always attempting to mint a NEW visit even when
`VisitLinkIssuer::surveyFor()` adopts an EXISTING live survey that an EARLIER visit
already claims via `(source_type, source_id)`.

The fix has three parts, all required:

1. Detect the collision BEFORE the transaction opens and refuse with a true,
   permanent message (not a rollback sentence) — and show the PM the existing
   engineer link so they can copy/open it (47-CONTEXT.md D-01, delivered early).
2. Surface the existing `site-surveys.supersede-from-project` route in the cockpit
   so "I want a genuinely fresh survey" has a real, reachable path.
3. Give "regenerate the document" its own no-visit, no-link action, because that
   is what the user was actually trying to do when they hit the collision twice.

Purpose: stop the 500s, give the PM a way forward in every case, and correct the
one documented claim (`CockpitCombinedCreator`'s own docblock) that the
adopted-survey retry case is unconditionally safe — it is not, when a visit
already wraps that survey.

Output: no migration. `visits_source_unique` is untouched. The worksheet path
(`VisitLinkIssuer::worksheetFor()`) is untouched — this plan is scoped to the
`site_survey` module only, per the live evidence (both collisions were
`site_survey-33`/`34`, never a worksheet) and per the asymmetry the codebase
already documents as a trap. `worksheets.retry-generation` already IS the
worksheet's own document-only, no-new-visit regenerate action — it is not
duplicated here.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@app/Support/Cockpit/CockpitCombinedCreator.php
@app/Support/Cockpit/CockpitCreationOutcome.php
@app/Support/Visits/VisitLinkIssuer.php
@app/Http/Controllers/ProjectCockpitDocumentController.php
@app/Http/Requests/CockpitDocumentRequest.php
@resources/views/components/cockpit/doc-form.blade.php
@resources/views/components/cockpit/panel.blade.php
@database/migrations/2026_09_19_140000_create_visits_table.php
@tests/Feature/Cockpit/CockpitCombinedCreationTest.php
@tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
@tests/Feature/Cockpit/CockpitDocumentFormTest.php
@.planning/phases/47-cockpit-links-and-returns/47-CONTEXT.md

<interfaces>
<!-- Confirmed by direct reading during planning — executor should not need to
     re-derive these. -->

`app/Support/Visits/VisitLinkIssuer.php`:
```php
public function liveSurveyFor(Project $project): ?SiteSurvey;   // the 4-clause "live" predicate
private function surveyFor(Project $project, User $user): SiteSurvey; // ADOPTS liveSurveyFor() or creates
```
`Visit` model constants used below: `Visit::SOURCE_SITE_SURVEY`, `Visit::TYPE_SITE_SURVEY`.

`app/Http/Controllers/SiteSurveyController.php` — CONFIRMED during planning:
`supersedeFromProject(Project $project): RedirectResponse` (route
`site-surveys.supersede-from-project`, POST, `{project}` only) has **NO
confirmation step of its own** — it directly archives the live survey
(`superseded_at`) and creates a fresh one, then redirects to
`site-surveys.confirm-rooms`. There is no separate confirm page to preserve or
link through; a plain `<form method="POST">` to this route IS the whole act.
`app/Http/Controllers/WorksheetController.php::revokeToken()` has no site-survey
equivalent — confirmed by grep; D-02's "check first" is answered: none exists,
none is invented here.

`app/Http/Controllers/WorksheetController.php::retryGeneration(Worksheet $worksheet)`
(route `worksheets.retry-generation`) is ALREADY the worksheet's document-only,
no-new-visit regenerate: it re-dispatches `BuildWorksheetJob` against the
EXISTING worksheet row and touches no `Visit`. This is why Part 3 below is
scoped to `site_survey` only — the worksheet already has its answer and this
plan does not duplicate it or touch `VisitLinkIssuer::worksheetFor()`.

`app/Http/Requests/CockpitDocumentRequest.php` — the closed set to extend:
```php
public const INTENTS = ['next', 'back', 'create', 'spaces-all', 'spaces-none'];
public const INTENT_CREATE = 'create';
```
`rules()` already does `Rule::in(self::INTENTS)` — adding a new intent constant
to this array is enough for validation. `format` is `required` only when
`intent() === INTENT_CREATE` (line ~205's `$isCreate`) — a new intent is
`nullable` by the same line, no edit needed there. `prepareForValidation()`
defaults an ABSENT `intent` to `INTENT_CREATE` — a new intent is only ever
reached when explicitly submitted by name, so this default is unaffected.

⚠ ONE MORE CHANGE IS REQUIRED, CONFIRMED DURING REVISION — `rules()`'s
`$isCreate` (line 205) ALSO drives `groupsToValidate($module, $isCreate)`
(lines 280-290), which on anything other than a TRUE `$isCreate` narrows to
`$wizard->groupsForStep($module, $this->step())` — the CURRENT step's fields
ONLY. `FormRequest::validated()` returns only fields that had a rule, so
submitting `intent=regenerate-document` from the LAST step (where the "Update
document only" button lives) would add rules for the last step's fields ONLY
and silently drop every earlier step's field — e.g. `general_notes`, which
lives on step 2 (`CockpitDocumentFormPresenter.php:397`). A value edited on an
earlier step would never reach `$validated` and `persist()`'s
`array_key_exists($field['key'], $validated)` guard would skip it, meaning
the regenerate action would silently fail to save the very edits it exists to
save. See Task 3 for the required fix (a second boolean driving
`groupsToValidate()`'s call site, kept separate from `$isCreate`'s existing
`format`-requirement use).

`app/Http/Controllers/ProjectCockpitDocumentController.php::store()` currently
branches:
```php
if ($request->intent() !== CockpitDocumentRequest::INTENT_CREATE) {
    return $this->advance($request, $project, $module);
}
```
A new intent branch MUST be inserted BEFORE this line (never inside
`advance()`, which is documented to write nothing at all and must stay that
way) — see Task 3.

`resources/views/components/cockpit/doc-form.blade.php` — the exact fence
sensitivities an executor MUST re-verify before adding any markup or copy:
  - CLOSED render must keep exactly ONE `cav-qa__control` substring and ZERO
    `<form` occurrences (`CockpitDocumentFormTest::
    test_every_module_closed_offers_exactly_one_control_that_reads_as_opening_a_form`).
    Any new form/button added by this plan MUST render only inside the
    `$isOpen` branch.
  - `test_only_the_generate_controls_are_green` counts the EXACT literal
    `class="cav-qa__control cav-qa__go" type="submit" name="intent" value="create"`
    — a new button must use a different class/name/value pair so it does not
    collide with or duplicate that string.
  - `CockpitReadOnlyFenceTest::test_every_form_in_the_region_carries_a_csrf_token`
    pins `assertSame(4, $checked)` (one `<form>` per module's `?action=generate`
    render). Adding the supersede `<form>` (Task 2) moves this to 5 and MUST be
    changed BY NAME with an inline comment in the same commit, mirroring the
    file's own history (`>= 5 -> 0 -> 4 -> 5`), never loosened to a floor. Task
    3's regenerate control is a second SUBMIT BUTTON inside the EXISTING
    `<form class="cav-qa__form">` (never a second `<form>`), so it does not
    move this count again.
  - `FORBIDDEN_MARKUP` (2), `DEFERRED_AFFORDANCES` (21, keys reproduced in
    `CockpitReadOnlyFenceTest.php`), `BANNED_HANDLER_ATTRIBUTES` (9),
    `WRITE_SURFACE_TABLES` (13) are all re-asserted by
    `CockpitReadOnlyFenceTest::test_the_fence_enumerates_the_whole_deferred_set()`.
    Every new copy string this plan introduces MUST be checked as a SUBSTRING
    against all 21 `DEFERRED_AFFORDANCES` keys and both `FORBIDDEN_MARKUP`
    entries BEFORE it is used — the same discipline every prior cockpit plan
    followed. `WRITE_SURFACE_TABLES` does not need a new entry: the supersede
    form and the regenerate action both only touch `site_surveys` and
    `project_activity_logs` (via `SurveyService`'s own `$this->projects->log()`
    call), both already on the list of 13.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Refuse the collision before the transaction opens, and show the existing link</name>
  <files>app/Support/Cockpit/CockpitCombinedCreator.php, app/Http/Controllers/ProjectCockpitDocumentController.php, resources/views/components/cockpit/doc-form.blade.php, tests/Feature/Cockpit/CockpitCombinedCreationTest.php</files>
  <behavior>
    - Test A (the live shape, exactly): a project has a live survey (created
      directly via `SurveyService::createFromProject`, NOT through the
      wizard) AND a `Visit` row already exists with
      `source_type = Visit::SOURCE_SITE_SURVEY`, `source_id = $survey->id`
      (i.e. a prior SUCCESSFUL cockpit creation, exactly the live shape).
      Submitting the survey wizard's `surveyPayload()` a second time must:
        * NOT throw / NOT 500 (no `SQLSTATE[23000]` reaches the HTTP layer)
        * create NO second `Visit` row (`Visit::where('project_id', ...)->count()` stays 1)
        * leave the pre-existing `Visit` and `SiteSurvey` rows completely
          untouched (same ids, same `access_token`)
        * flash an errors bag on `module` whose message does NOT contain
          the substring `"rolled back"` and does NOT say `"try again"`
          (this is a PERMANENT state, not a transient failure)
        * flash `session('cockpit_existing_link')` equal to
          `$survey->publicUrl()`
    - Test B: the SAME scenario, but assert the redirect target is `back()`
      (re-opens the wizard on the tab the PM was on), and that
      `session('_old_input')` still carries the PM's submitted free-text
      fields (same treatment `test_a_link_failure_leaves_five_named_tables_exactly_as_they_were`
      already proves for the transactional rollback case — this is the
      NON-transactional case and must give the PM back their input too).
    - Test C (non-regression): the EXISTING `test_a_project_with_a_live_survey_adopts_it_and_mints_no_second_token`
      scenario (live survey exists, NO visit claims it yet) must still
      succeed exactly as it does today — this guard must only fire when a
      VISIT already claims the survey, never merely because a survey exists.
  </behavior>
  <action>
    In `CockpitCombinedCreator`, add two small methods beside the existing
    `documentPreExists()` — same "asked BEFORE the transaction opens" pattern,
    same reasoning in the docblock:

      - `liveSurvey(Project $project): ?SiteSurvey` — a thin passthrough to
        `$this->issuer->liveSurveyFor($project)`. Do not duplicate the
        four-clause predicate; call the issuer's own public method (it is
        already public and untouched).
      - `visitAlreadyClaimsSurvey(Project $project): ?Visit` — returns null
        when `liveSurvey($project)` is null; otherwise looks up
        `Visit::where('source_type', Visit::SOURCE_SITE_SURVEY)->where('source_id', $survey->id)->first()`.
        This is the exact predicate the unique index on
        `(source_type, source_id)` enforces at the DB layer — read it back
        here so the app layer can refuse BEFORE hitting the constraint.

    In `ProjectCockpitDocumentController::createCombined()`, before the
    existing `$preExisting = $this->creator->documentPreExists(...)` line,
    add (module-gated exactly as `documentPreExists()` itself is):

      - if `$module === ProjectDeliverable::KEY_SITE_SURVEY`, call
        `$this->creator->visitAlreadyClaimsSurvey($project)`. If it returns a
        `Visit` (non-null), do NOT enter `$this->creator->create(...)` at
        all. Instead: `session()->flash('cockpit_existing_link', $this->creator->liveSurvey($project)?->publicUrl());`
        then `return back()->withInput()->withErrors(['module' => '<message>']);`
        The `<message>` must state the permanent fact — this project already
        has a survey visit and an engineer link — and must point at the two
        ways forward this plan ships (regenerate the document only, or
        supersede for a fresh survey). Check the exact wording as a substring
        against all 21 `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES` keys
        and both `FORBIDDEN_MARKUP` entries before committing to it — do not
        skip this check, it is how every prior cockpit plan avoided a
        silent fence collision.
      - No exception text, no submitted value, and no stack trace may reach
        this message (same rule `createCombined()` already follows for the
        rollback path).

    In `doc-form.blade.php`, inside the `$isOpen` branch (near the existing
    `@if ($errors->any())` block), add a rendering of
    `session('cockpit_existing_link')` when present: print the URL as
    PLAIN VISIBLE, SELECTABLE TEXT (there is no JS and no copy button, per
    this plan's constraints) inside an `<a href="...">` whose link text IS
    the URL — e.g. `<p class="cav-qa__note">Existing engineer link: <a href="{{ session('cockpit_existing_link') }}">{{ session('cockpit_existing_link') }}</a></p>`.
    Escape via `{{ }}` only (T-46.2-17) — never `{!! !!}`. Check this copy
    ("Existing engineer link:") against the fence lists the same way as the
    refusal message above.

    Correct the stale claim in `CockpitCombinedCreator`'s own docblock (the
    "── site_survey" section, lines ~43-47): it currently says a retry on a
    project with an existing live survey unconditionally "ADOPTS it... survey
    count stays 1 and the token is the one that was already there" with no
    mention of the visit. Add a sentence naming the case this plan fixes: the
    adoption is safe for the SURVEY but was NOT safe for the VISIT when an
    earlier visit already claims that survey's `(source_type, source_id)` —
    that case is refused before the transaction opens by
    `visitAlreadyClaimsSurvey()`, never attempted and rolled back.
  </action>
  <verify>
    <automated>powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitCombinedCreationTest.php</automated>
  </verify>
  <done>
    All three behaviors above are proven by real, non-vacuous tests (Test A
    reproduces the live shape exactly: pre-existing survey AND pre-existing
    claiming visit, created independently of the wizard). The regression test
    fails on the pre-fix code (reproduces the SQL integrity violation or an
    uncaught exception) and passes after. No migration was written. The
    `visits_source_unique` index is untouched.
  </done>
</task>

<task type="auto">
  <name>Task 2: Surface the existing supersede action in the cockpit</name>
  <files>resources/views/components/cockpit/doc-form.blade.php, tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php, tests/Feature/Cockpit/CockpitDocumentFormTest.php</files>
  <action>
    Add ONE new `<form method="POST" action="{{ route('site-surveys.supersede-from-project', $project) }}">`
    with `@csrf` and a single submit button, rendered ONLY inside the
    `$isOpen` branch of `doc-form.blade.php`, ONLY for
    `$module['key'] === \App\Models\ProjectDeliverable::KEY_SITE_SURVEY`, and
    ONLY when `$holdsDocument` is true. NOTE WHAT THIS PREDICATE ACTUALLY
    MEANS, CORRECTED DURING REVISION: `$holdsDocument` (doc-form.blade.php:306)
    is `collect($documents)->isNotEmpty()`, and for `site_survey` `$documents`
    comes from `CockpitPanelPresenter::files()` reading the WHOLE `siteSurveys`
    relation (`CockpitPanelPresenter.php:96`) — it is true whenever the
    project has EVER had a survey, not scoped to `superseded_at IS NULL`. It
    is the SAME predicate that already flips the closed control's label to
    "Regenerate", so using it here is consistent with existing behaviour, but
    it is NOT "a live survey exists". A project whose only surveys are all
    superseded would still render this form — harmlessly, since
    `supersedeFromProject()`'s `$existing === null` path just creates a fresh
    survey in that case, but state the predicate accurately rather than
    claiming it means something it does not. Do NOT render it on the closed
    panel — that would break
    `CockpitDocumentFormTest::test_every_module_closed_offers_exactly_one_control_that_reads_as_opening_a_form`'s
    `assertSame(0, substr_count($block, '<form'))` on the closed state.

    This form calls the EXISTING, already-tested `SiteSurveyController::supersedeFromProject()`
    directly — confirmed during planning to have NO confirmation step of its
    own (see this plan's `<interfaces>` block). Do NOT reimplement
    superseding, do NOT add a second confirmation screen the controller does
    not have, and do NOT touch `SiteSurveyController` or `SurveyService`.

    Choose button/intro copy that makes the destructive, permanent nature of
    the action clear in words (there is no JS `confirm()` dialog available,
    and the existing controller route has none either — parity, not a
    regression). Before committing to any copy string, check it as a
    substring against all 21 `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES`
    keys and both `FORBIDDEN_MARKUP` entries — do this for real, not from
    memory of this plan's suggestions. `Book another survey` (owner: "Phase
    46") is the nearest neighbour on that list — the chosen copy must not
    collide with it as a substring.

    GOVERN THIS BUTTON WITH THE SAME CARE AS TASK 3'S (added during
    revision, so the two are not held to different standards): use class
    `cav-qa__control` and NOT `cav-qa__go` (this action does not "generate" a
    document in the sense that copy/colour means elsewhere on the page — it
    archives one and starts a fresh one), and do not let its literal
    attribute string duplicate `class="cav-qa__control cav-qa__go" type="submit" name="intent" value="create"`
    or any other existing control's exact string, so
    `CockpitDocumentFormTest::test_only_the_generate_controls_are_green` stays
    unaffected. This form POSTs to `site-surveys.supersede-from-project`
    directly (its own route, not `projects.cockpit.documents.store`), so its
    submit button correctly carries no `name="intent"` at all — record that
    as a deliberate difference from Task 3's button, not an inconsistency.

    Update `CockpitReadOnlyFenceTest::test_every_form_in_the_region_carries_a_csrf_token`:
    move `assertSame(4, $checked, ...)` to `assertSame(5, $checked, ...)`,
    BY NAME, with an inline comment continuing the file's own numbered
    history (`>= 5 -> 0 -> 4 -> 5`) naming this quick task and what changed
    (one new form, site_survey module only, when a document already exists).
    Update the neighbouring count-history comment block in
    `test_the_fence_enumerates_the_whole_deferred_set()` if it references the
    form count; leave `FORBIDDEN_MARKUP` (2), `DEFERRED_AFFORDANCES` (21),
    `BANNED_HANDLER_ATTRIBUTES` (9) and `WRITE_SURFACE_TABLES` (13) exactly
    where they are — this task adds no banned markup, no new deferred
    affordance and no new write-surface table (the supersede action already
    writes only to `site_surveys` and `project_activity_logs`, both already
    on the 13-table list). Also verify `CockpitDocumentFormTest::
    test_every_module_closed_offers_exactly_one_control_that_reads_as_opening_a_form`
    and `test_only_the_generate_controls_are_green` still pass unmodified —
    both are scoped to the closed render / a specific literal string, neither
    of which this task's addition touches; if either goes red, the new form
    leaked into the wrong branch or reused a colliding class string.

    Add (or extend an existing) assertion in `CockpitDocumentFormTest` that
    drives the supersede form for real: submit it against a project with a
    live survey, assert the response redirects to
    `site-surveys.confirm-rooms`, the original survey's `superseded_at` is
    now non-null, and a NEW `SiteSurvey` row exists for the project. This is
    the non-vacuity proof that the form really reaches the route it claims
    to, not merely that the markup renders.
  </action>
  <verify>
    <automated>powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php</automated>
  </verify>
  <done>
    A PM viewing the open site-survey wizard when a live survey already
    exists sees a form that reaches `site-surveys.supersede-from-project` for
    real (proven by a driven-submission test, not by markup inspection). The
    fence's form count is 5, named and commented as such. No other fence
    count moved. `CockpitDocumentFormTest`'s closed-render assertions still
    pass unmodified.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: A document-only regenerate for the site survey — no visit, no link</name>
  <files>app/Http/Requests/CockpitDocumentRequest.php, app/Http/Controllers/ProjectCockpitDocumentController.php, resources/views/components/cockpit/doc-form.blade.php, tests/Feature/Cockpit/CockpitCombinedCreationTest.php</files>
  <behavior>
    - Test A: on a project with an existing live survey, submitting the
      wizard's LAST step (step 3, "Spaces and output" — the step the
      "Update document only" button actually lives on) with
      `intent=regenerate-document`, carrying an edited value for a field from
      an EARLIER step, MUST:
        * persist that EARLIER-STEP field onto the EXISTING `SiteSurvey` row
          — specifically `general_notes`, which `CockpitDocumentFormPresenter.php:397`
          places on step 2, submitted as a hidden carry-forward input while
          the form is open on step 3. THIS IS THE NON-VACUITY PROOF: a field
          from the SUBMITTING step alone would pass even with the
          `groupsToValidate()` gap the revision found, because that gap only
          drops OTHER steps' fields. Asserting only a step-3 field (e.g.
          `visit_rooms`) would NOT prove the fix — the test must read the
          value back off the SAME row and assert it changed.
        * ALSO prove the narrow end of the wizard in the same request, from
          the other side. ⚠ CORRECTED after plan-check iteration 2: there is
          NO non-readonly, `survey.*`-targeted field on step 3 for this
          module, so "persist a step-3 field" is UNSATISFIABLE and an earlier
          draft of this plan asked for something that cannot exist. The two
          step-3 groups are "Spaces being surveyed" (`visit_rooms`, target
          `visit.rooms_in_scope`) and "From the project" (four fields, all
          `readonly` with `RULES_DISPLAY_ONLY` i.e. `prohibited`, never
          submittable). So assert instead that `visit_rooms` is ACCEPTED
          WITHOUT A VALIDATION ERROR, appears in NO survey column, and
          creates NO `Visit` — which closes the same non-vacuity gap and is
          additionally the correct proof that `persist()`'s deliberate
          `'visit' => null` no-op still holds under the widened validation
          path. Do NOT invent a persistence assertion for a column that has
          no field behind it.
        * create NO `Visit` row at all (count stays at whatever it was before
          the submission — assert both the "no visit yet" and "a visit
          already claims it" starting states, since this action must work in
          both)
        * call NEITHER `VisitLinkIssuer::issue()` NOR
          `VisitLinkIssuer::surveyFor()` — assert this by NOT touching
          `access_token` (it must be byte-identical before and after) rather
          than by mocking a final class
        * flash a success message that does not mention a visit or an
          engineer link being created
        * redirect to the Files tab (`panelUrl($project, $module, false, ...)`,
          same target the successful `createCombined()` path uses)
    - Test B (no survey yet): submitting `intent=regenerate-document` on a
      project with NO live survey must fail gracefully (a validation-style
      error naming that there is nothing to regenerate yet) rather than
      creating one — this action is document-only in both directions, never
      a hidden "create" fallback.
    - Test C (non-regression): every existing `intent=create` test in
      `CockpitCombinedCreationTest` still passes unmodified — this task adds
      a new branch and must not alter the `create` path at all.
  </behavior>
  <action>
    In `CockpitDocumentRequest`, add `public const INTENT_REGENERATE = 'regenerate-document';`
    and add it to the `INTENTS` array (`['next', 'back', 'create', 'spaces-all', 'spaces-none', 'regenerate-document']`).

    ⚠ THIS CLASS NEEDS ONE MORE CHANGE, FOUND DURING REVISION — do NOT skip
    it, Test A in this task's `<behavior>` is written specifically to catch
    its absence. In `rules()`, `$isCreate` (line ~205) is used for TWO
    different things: (a) whether `format` is `required`, and (b) whether
    `groupsToValidate($module, $isCreate)` returns EVERY group or only the
    current step's. Those two uses must now DIVERGE for
    `regenerate-document`: `format` should stay `nullable` for it exactly as
    for any non-`create` intent (no change needed there — `$isCreate` staying
    a strict `=== INTENT_CREATE` check is correct for this use), but
    `groupsToValidate()`'s call site must treat `regenerate-document` the
    SAME as `create` — every group's rules, not just the submitting step's —
    because the regenerate action can carry edits from ANY earlier step as
    hidden inputs and must re-validate (and thereby actually receive) all of
    them, not only the last step's.

    Concretely: introduce a second local boolean at the `groupsToValidate()`
    call site, e.g. `$wantsEveryGroup = $isCreate || $this->intent() === self::INTENT_REGENERATE;`,
    and call `$this->groupsToValidate($module, $wantsEveryGroup)` instead of
    `$this->groupsToValidate($module, $isCreate)`. Leave every OTHER use of
    `$isCreate` (both `format` rule assignments) untouched. Update
    `groupsToValidate()`'s own docblock, which currently says "ON `create` —
    EVERY group" — broaden that sentence to name `regenerate-document`
    alongside `create` so the next reader is not misled by the parameter's
    name (`$isCreate`) once it is passed a value that means something
    slightly wider than its name. Do not rename the parameter itself unless
    it costs nothing — a rename is optional polish, the behavioural fix is
    not.

    In `ProjectCockpitDocumentController::store()`, insert a new branch
    BEFORE the existing `if ($request->intent() !== CockpitDocumentRequest::INTENT_CREATE) { return $this->advance(...); }`
    line:

      - if `$request->intent() === CockpitDocumentRequest::INTENT_REGENERATE`,
        call a new private method `regenerateDocumentOnly($request, $project, $module, $validated)`.

    `regenerateDocumentOnly()`:
      - `abort_unless($module === ProjectDeliverable::KEY_RAMS === false && $module === ProjectDeliverable::KEY_SITE_SURVEY, 404);`
        — simpler: guard explicitly with
        `abort_unless($module === ProjectDeliverable::KEY_SITE_SURVEY, 404);`
        and a short comment naming that the worksheet already has its own
        no-visit regenerate at `worksheets.retry-generation` and RAMS/OM are
        already document-only via the untouched `delegate()` path, so this
        method is deliberately SITE-SURVEY-ONLY.
      - Resolve the existing survey via the SAME `activeSurvey($project)`
        private method `createCombined()` already uses (do not write a
        second predicate).
      - If `activeSurvey($project)` is null, `return back()->withInput()->withErrors(['module' => '<no-survey-yet message, checked against the fence>']);`
        — never create one from this path.
      - Otherwise call the EXISTING `$this->persist($project, $module, $validated);`
        (the same bucketing method `createCombined()` uses for `$persistFields`)
        and nothing else — no `Visit`, no `VisitLinkIssuer`, no
        `ProjectService::log()` call for a visit (a plain activity log entry
        for the document edit is optional and not required by this plan).
      - Flash a success message that names what happened (the document was
        updated) and does NOT claim a visit or link changed. Redirect via
        the existing `panelUrl($project, $module, false, $request->input('tab'))`.

    In `doc-form.blade.php`, inside the `@if ($isLast)` block, ONLY for
    `$module['key'] === \App\Models\ProjectDeliverable::KEY_SITE_SURVEY` AND
    `$holdsDocument` (reuse the variable already computed for the closed
    control's "Regenerate" label), add a SECOND submit button inside the
    SAME `<form class="cav-qa__form">` — never a new `<form>` tag:
    `<button class="cav-qa__control" type="submit" name="intent" value="regenerate-document">Update document only</button>`
    (exact copy to be checked against the fence before use, same discipline
    as Tasks 1 and 2). Use a class that is NOT `cav-qa__go` and a literal
    string that does NOT duplicate
    `class="cav-qa__control cav-qa__go" type="submit" name="intent" value="create"`,
    so `CockpitDocumentFormTest::test_only_the_generate_controls_are_green`
    is unaffected. Place it so it does not become the FIRST submit button in
    the form (Enter-to-submit in a text field must still trigger "Generate
    document"/`create`, per the form's existing, load-bearing button order —
    read the comment above `<div class="cav-qa__row">` before changing
    anything there).
  </action>
  <verify>
    <automated>powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitCombinedCreationTest.php</automated>
  </verify>
  <done>
    A PM can update the survey's answered fields and regenerate its Word/PDF
    without a new `Visit` or engineer link ever being attempted, proven for
    both starting states (no visit yet, and a visit already claiming the
    survey — exactly Part 1's collision case, now with a real way forward
    instead of only a refusal). The `create` path is unmodified. `<form>`
    count on the page is unchanged from Task 2's new total.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| PM browser → `projects.cockpit.documents.store` | Authenticated session POST; `module`, `intent`, `step`, `format` and every document field are untrusted input, already validated by `CockpitDocumentRequest` against closed sets read from `documentFieldMap()`. |
| PM browser → `site-surveys.supersede-from-project` | Authenticated session POST; no body params at all — the route takes only the route-bound `{project}`, so there is no new field-level attack surface introduced by Task 2. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-qcy-01 | Denial of Service | `visits_source_unique` collision | mitigate | Task 1's pre-transaction `visitAlreadyClaimsSurvey()` check turns an uncaught `SQLSTATE[23000]` (a 500, previously reproducible on demand by any PM on an affected project) into a handled, user-facing refusal. |
| T-qcy-02 | Tampering | `intent=regenerate-document` submitted for a module other than `site_survey` | mitigate | `regenerateDocumentOnly()` hard-`abort_unless`s the module is `site_survey`; a hand-crafted POST with `module=worksheet&intent=regenerate-document` gets a 404, never a silent no-op that could be mistaken for success. |
| T-qcy-03 | Information Disclosure | the flashed `cockpit_existing_link` (a live public survey access-token URL) | accept | The link is only ever flashed back to the SAME authenticated session that already has cockpit access to this project (existing auth boundary, unchanged); the token itself is never minted, rotated or logged by this plan (T-46-04-04's rule, upheld). |
| T-qcy-04 | Elevation of Privilege | new `<form>` to `site-surveys.supersede-from-project` | accept | The route already enforces `abort_unless(auth()->check(), 403)` and is a pre-existing, already-tested action; this plan adds no new authorization logic and widens no permission — it only adds a second path TO an existing, equally-authenticated action. |
| T-qcy-SC | Tampering | package installs | n/a | This plan adds no new Composer/npm/pip dependency. |

</threat_model>

<verification>
Run each affected suite in the foreground, redirected (never piped, never
backgrounded), one file per invocation, per this repo's PHP-not-on-PATH /
ANSI-output rules:

```
powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitCombinedCreationTest.php  > .tmp-gate-1.txt 2>&1
powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php      > .tmp-gate-2.txt 2>&1
powershell -File gate-46.ps1 -File tests/Feature/Cockpit/CockpitDocumentFormTest.php       > .tmp-gate-3.txt 2>&1
```

Strip ANSI escape codes before grepping `Tests:`/`Failures:` in each output
file. The D-06 baseline gate is `>= 159 passed AND 0 failed` — NEVER equality
against 161, and never trust a `| tail` pipeline (PHP is not on the Bash
tool's PATH; a silently-exited pipe reports success for nothing run).
</verification>

<success_criteria>
- The live collision (retrying a survey creation when a visit already claims
  the live survey) is reproduced by a test that fails before the fix and
  passes after it — proven, not asserted.
- No `SQLSTATE[23000]` reaches the HTTP layer in any test in this plan.
- `visits_source_unique` and its migration are untouched; no new migration
  was written.
- `VisitLinkIssuer.php` and `WorksheetController.php`'s worksheet regenerate
  path (`retryGeneration`) are untouched.
- The read-only fence's four pinned counts are each either unchanged (with
  the count re-verified, not assumed) or moved exactly once, by name, with an
  inline comment, in the commit that ships the control responsible.
- `CockpitCombinedCreator`'s docblock no longer overstates the adopted-survey
  retry case as unconditionally safe.
- All three CockpitFeature/Cockpit test suites listed in `<verification>`
  pass in the foreground with `>= 159 passed AND 0 failed` against the D-06
  baseline.
- No push, no deploy. Work ends at a local commit plus this SUMMARY.
</success_criteria>

<output>
Create `.planning/quick/260930-qcy-fix-cockpit-survey-regeneration-collisio/260930-qcy-SUMMARY.md` when done,
stating the ACTUAL foreground test totals measured for each of the three
suites (never inferred), the final fence counts (form / FORBIDDEN_MARKUP /
DEFERRED_AFFORDANCES / BANNED_HANDLER_ATTRIBUTES / WRITE_SURFACE_TABLES) and
confirmation that no migration was written and no push/deploy occurred.
</output>

---
phase: 261008-gxt
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Models/SiteSurvey.php
  - app/Http/Controllers/SurveyController.php
  - app/Http/Controllers/PublicSurveyController.php
  - app/Support/SurveyPdfHelpers.php
  - resources/views/surveys/show.blade.php
  - resources/views/projects/cockpit.blade.php
  - tests/Feature/Surveys/SurveySupersededLockTest.php
  - tests/Feature/Surveys/SurveyPlannedActionsBulletsTest.php
  - tests/Feature/Cockpit/CockpitAvWorksSummaryLinkTest.php
autonomous: true
requirements: []
must_haves:
  truths:
    - "An engineer holding a bookmarked link to a SUPERSEDED survey sees the wizard read-only, with a branded message telling them it was replaced and to contact the office — they cannot submit it or fire the office notification."
    - "Every write endpoint on a superseded survey (step-save, save, submit, room complete/uncomplete, question answer, photo upload, photo caption update) refuses with 403 — not just the GET page."
    - "A LIVE (not superseded) survey is completely unaffected: fully editable, no banner, every write endpoint still succeeds exactly as before."
    - "The office 'Send back' action can never reopen a superseded survey — superseding is an unconditional lock, independent of VisitReworkState's reopened comparison."
    - "A room whose office-authored works_summary is empty now shows the same 'Install actions' bullet list (derived from whatever prose text is available) instead of a single paragraph — and a room WITH office bullets is rendered completely unchanged."
    - "The office 'AV Works Summary' editor (project-packages review, Room/Space Overviews section) is reachable from the cockpit page via a real rendered link, not merely a registered route."
  artifacts:
    - path: "app/Models/SiteSurvey.php"
      provides: "isSuperseded() and engineerLockMessage() helpers; isLockedForEngineer() folds superseded in as an unconditional short-circuit"
    - path: "app/Support/SurveyPdfHelpers.php"
      provides: "textToBullets(string $roomName, string $text): array — the single shared implementation, moved out of PublicSurveyController's dead show()"
    - path: "tests/Feature/Surveys/SurveySupersededLockTest.php"
      provides: "RED-then-GREEN proof of read-only render + all write-endpoint 403s for a superseded survey AND full editability for a live one"
    - path: "tests/Feature/Surveys/SurveyPlannedActionsBulletsTest.php"
      provides: "Proof a room with office bullets is unchanged and a room without them now renders derived bullets with a distinguishing label"
    - path: "tests/Feature/Cockpit/CockpitAvWorksSummaryLinkTest.php"
      provides: "Proof the cockpit page RENDERS a link to project-packages.review.show, asserted on response content not Route::has()"
  key_links:
    - from: "app/Models/SiteSurvey.php::isLockedForEngineer()"
      to: "SurveyController::stepSave / PublicSurveyController::save,submit,completeRoom,uncompleteRoom,answerQuestion,uploadPhoto,updatePhoto"
      via: "abort_if($survey->isLockedForEngineer(), 403, $survey->engineerLockMessage())"
      pattern: "isLockedForEngineer\\(\\)"
    - from: "resources/views/surveys/show.blade.php"
      to: "SurveyController::show()"
      via: "'readonly' and 'superseded' passed into the view"
      pattern: "superseded"
    - from: "app/Http/Controllers/SurveyController.php::buildAlpineRooms()"
      to: "app/Support/SurveyPdfHelpers.php::textToBullets()"
      via: "fallback bullet derivation when office works_summary is empty"
      pattern: "SurveyPdfHelpers::textToBullets"
    - from: "resources/views/projects/cockpit.blade.php"
      to: "project-packages.review.show"
      via: "route($project->latestPackage)"
      pattern: "project-packages\\.review\\.show"
---

<objective>
Three fixes found by a 2026-10-08 read-only audit of the engineer survey link, all in this one plan:

1. **PRIORITY** — a superseded survey's engineer link stays live and fully editable (the office's "Send back" reopen path must never apply to it). Fix as read-only, not a hard 410, and block every write endpoint, not just the GET page.
2. The "Planned AV works" prose fallback never renders as bullets, even though the bullet mechanism (`_ctx.planned_actions`) and the text-splitting helper (`textToBullets()`, currently dead code) both already exist.
3. The "AV Works Summary" editor (project-packages review page) is unreachable from the cockpit, which is why it is empty everywhere.

Purpose: Close three concrete audit findings without scope reduction, reusing what already exists (`isLockedForEngineer()`'s gates, `textToBullets()`, the "Open full project" link pattern) rather than building parallel mechanisms — keeping the diff minimal.
Output: Superseded links go read-only everywhere a live survey can be mutated; every room's install-actions panel always shows bullets when any planned-works text exists; the cockpit links to the AV Works Summary editor.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@app/Models/SiteSurvey.php
@app/Support/Visits/VisitReworkState.php
@app/Core/Modules/Survey/SurveyService.php
@app/Http/Controllers/SurveyController.php
@app/Http/Controllers/PublicSurveyController.php
@app/Support/SurveyPdfHelpers.php
@resources/views/surveys/show.blade.php
@resources/views/projects/cockpit.blade.php
@routes/web.php
</context>

<interfaces>
<!-- Confirmed by reading the live files — no further exploration needed. -->

`app/Models/SiteSurvey.php` ($fillable already contains `superseded_at`, cast to `datetime`; does NOT contain `access_token` or `submitted_notification_sent_at` — do not add either):
```php
public function isSubmitted(): bool { return $this->submitted_at !== null; }
public function isLockedForEngineer(): bool {
    if (! $this->isSubmitted()) return false;
    return ! \App\Support\Visits\VisitReworkState::isReopened($this);
}
public function isTokenExpired(): bool { ... }
public function publicUrl(): string { ... }
```
Sibling convention already in this codebase (`app/Models/ProjectDrawing.php:136`, `app/Models/Visit.php:311`):
```php
public function isSuperseded(): bool { return ! is_null($this->superseded_by_id); } // ProjectDrawing
public function isSuperseded(): bool { return $source->getAttribute('superseded_at') !== null || ...; } // Visit (reads through source())
```

`app/Http/Controllers/SurveyController.php` — `resolveSurvey()` (:1030) checks only unknown-token 404 and expired-token/trashed-project 410; superseded is never checked there (deliberately — a superseded survey must render read-only, not 410). `show()` (:91-102) already passes `'readonly' => $survey->isLockedForEngineer()` into `surveys.show`. `stepSave()` (:118-120) already gates on `isLockedForEngineer()`.

`app/Http/Controllers/PublicSurveyController.php` — SEVEN live call sites already gate on `$survey->isLockedForEngineer()`, each with the literal string `'This survey has already been submitted.'`: `save()` :227, `submit()` :259, `completeRoom()` :305, `uncompleteRoom()` :385, `answerQuestion()` :414, `uploadPhoto()` :468, `updatePhoto()` :509. `PublicSurveyController::show()` (~:45-186) is DEAD — `routes/web.php:87` sends `GET /survey/{token}` to `SurveyController::show`, never to this method — but only `show()` itself (and the `textToBullets()` private method defined inside its block, ~:181-197) is dead; its sibling write methods ARE the live routes. Signature:
```php
private function textToBullets(string $roomName, string $text): array
```
Logic: strips `**bold**` markers, drops a leading line that echoes the room name, splits on newlines (falls back to sentence-splitting for a single unstructured paragraph), strips leading bullet markers per line, drops lines under 3 chars, caps at 12.

`app/Core/Modules/Survey/SurveyService.php::supersedeSurvey()` (:1018-1026) is the ONLY writer of `superseded_at`: `$survey->update(['superseded_at' => now()])`. Called from `create()` and `createFromProject()` against existing rows matched by `whereIn('status', ['draft', 'completed'])` — note this does NOT imply `submitted_at` is set, so a never-submitted draft survey can be superseded.

`app/Support/Visits/VisitReworkState::isReopened(SiteSurvey|Worksheet|null $record): bool` — returns `false` when no `Visit` wraps the record (pre-46-05 behaviour), otherwise compares `sent_back_at` against the record's last submission. This is the "Send back" reopen path that must never override a superseded lock.

`resources/views/surveys/show.blade.php` — the readonly banner at :459-464:
```blade
@if ($readonly)
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3 mb-4
                text-amber-800 text-sm font-medium text-center">
        This survey has been submitted and is read-only.
    </div>
@endif
```
The bullets/prose panel at :575-604 reads a per-room `_ctx` block built in `SurveyController.php::buildAlpineRooms()` (~:480-512):
```php
'_ctx' => [
    'av_requirements' => $plannedWorks,            // prose fallback text
    'planned_actions' => array_values(array_filter( // office-authored bullets, split on \n
        array_map('trim', preg_split('/\r?\n/', (string) ($packageRoomBullets[strtolower((string) $dbRoom->room_name)] ?? ''))),
        fn ($l) => $l !== ''
    )),
    ...
],
```
Blade renders `planned_actions` as a `<ul>` headed "Install actions" (:575-592) when non-empty, else `av_requirements` as a `<p>` (:594-604) when non-empty.

`routes/web.php:271` — `project-packages.review.show` takes `{package}` (a `ProjectPackage` model). `resources/views/project-packages/review.blade.php:797` has `<div ... id="s-room-overviews">` wrapping the "2. Room / Space Overviews" section containing the AV Works Summary textarea.

`resources/views/projects/cockpit.blade.php:276` — the ONLY existing outbound link pattern on this page:
```blade
<p class="cav-note">
    <a class="cav-link" href="{{ route('projects.show', $project) }}">Open full project</a>
</p>
```
`$project->latestPackage` (a lazy relation, `app/Models/Project.php:163`) is already dereferenced directly in Blade/controllers elsewhere (e.g. `SurveyController.php:343`) without prior eager-load — same pattern is safe to reuse here.

`CockpitReadOnlyFenceTest::FORBIDDEN_MARKUP` bans `<select` and `<script` only; `<a>` tags are unguarded (the existing "Open full project" link proves this). `DEFERRED_AFFORDANCES` is a separate, unrelated enumeration of WRITE affordances — a navigation-only `<a>` is not one and this task adds none.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1 (PRIORITY): Superseded survey link goes read-only everywhere, never reopenable</name>
  <files>app/Models/SiteSurvey.php, app/Http/Controllers/SurveyController.php, app/Http/Controllers/PublicSurveyController.php, resources/views/surveys/show.blade.php, tests/Feature/Surveys/SurveySupersededLockTest.php</files>
  <behavior>
    - Test A (RED first): a survey with `superseded_at` set but `submitted_at` NULL (a superseded draft — the case the current code gets wrong) renders `GET survey/{token}` as 200, read-only mode, with a visible "replaced" message naming the office as the contact.
    - Test B: the same superseded survey — `POST step-save`, `POST save`, `POST submit`, `POST rooms/{room}/complete`, `POST rooms/{room}/uncomplete`, `POST rooms/{room}/questions/{q}`, `POST rooms/{room}/photos`, `PATCH photos/{photo}` ALL return 403.
    - Test C (non-vacuity — the exact shape of the 2026-09-30 regression): a NORMAL, NOT-superseded, NOT-submitted survey renders fully editable (`readonly` false, no "replaced" banner), and `POST step-save` / `POST save` / `POST rooms/{room}/complete` all succeed (200/302, not 403).
    - Test D: a superseded survey that ALSO has a `Visit` wrapping it with `sent_back_at` newer than its (null or past) submission — the one path that would make `VisitReworkState::isReopened()` return true — still reports `isLockedForEngineer() === true`. This proves "Send back" cannot reopen a superseded survey.
  </behavior>
  <action>
    On `SiteSurvey` (app/Models/SiteSurvey.php), add `public function isSuperseded(): bool { return $this->superseded_at !== null; }` immediately below `isSubmitted()`, matching the naming convention already used by `ProjectDrawing::isSuperseded()` and `Visit::isSuperseded()`. Add `public function engineerLockMessage(): string` returning `'This survey has been replaced by a newer survey. Please contact the office.'` when `isSuperseded()`, else the existing literal `'This survey has already been submitted.'` — the single source of truth for 403 copy so the two states are never confused in a response body. Change `isLockedForEngineer()` to check `isSuperseded()` FIRST and return `true` unconditionally before the `isSubmitted()` check — document inline, in the same docblock style this method already uses, that this ordering is deliberate: a superseded survey need not be submitted to be locked (`supersedeSurvey()` matches rows by status, not by `submitted_at`), and placing the check ahead of `VisitReworkState::isReopened()` is what makes superseding immune to the office "Send back" reopen path — `isReopened()` is never consulted once `isSuperseded()` is true. Do NOT touch `isSubmitted()` itself (other callers — the PDF generator, the notification path — must keep meaning "was submitted", per the existing docblock's own warning not to retarget it).

    In `SurveyController::show()` (app/Http/Controllers/SurveyController.php:91-102), add `'superseded' => $survey->isSuperseded()` to the returned view data, alongside the existing `'readonly'` key — `readonly` needs no code change (it already calls the now-updated `isLockedForEngineer()`), but the Blade needs the extra flag to choose banner copy. Change the one `response()->json(['error' => 'Survey already submitted.'], 403)` in `stepSave()` (:119) to `response()->json(['error' => $survey->engineerLockMessage()], 403)`.

    In `PublicSurveyController.php`, replace the literal string `'This survey has already been submitted.'` with `$survey->engineerLockMessage()` at all seven live `abort_if($survey->isLockedForEngineer(), 403, ...)` call sites (`save` :227, `submit` :259, `completeRoom` :305, `uncompleteRoom` :385, `answerQuestion` :414, `uploadPhoto` :468, `updatePhoto` :509). Do not touch `PublicSurveyController::show()` (dead, unreachable by any route) beyond leaving it exactly as-is — nothing in that method is deleted or duplicated.

    In `resources/views/surveys/show.blade.php`, change the `@if ($readonly)` banner block at :459-464 to branch on the new `$superseded` flag: when true, show "This survey has been replaced by a newer survey. Please contact the office — anything you capture here is saved but will not be used." using the SAME `bg-amber-50 border-amber-200 text-amber-800` classes already in use (plain Tailwind defaults, not among `SurveyLinkBrandingTest::SUPERSEDED`'s banned hexes); otherwise keep the existing "This survey has been submitted and is read-only." copy unchanged. Introduce no new color token and name no banned hex in a comment near this edit.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveySupersededLockTest > "$env:TEMP/g1.txt" 2>&1; Get-Content "$env:TEMP/g1.txt" -Tail 8</automated>
  </verify>
  <done>SurveySupersededLockTest passes with 0 failures, covering all four behaviors above (superseded-draft read-only render, all eight write endpoints 403 on a superseded survey, full editability preserved on a live survey, and superseded immune to Send-back reopening). Quote the RED failure from before the fix and the GREEN pass after, in the plan SUMMARY.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Planned-works prose fallback renders as bullets</name>
  <files>app/Support/SurveyPdfHelpers.php, app/Http/Controllers/PublicSurveyController.php, app/Http/Controllers/SurveyController.php, resources/views/surveys/show.blade.php, tests/Feature/Surveys/SurveyPlannedActionsBulletsTest.php</files>
  <behavior>
    - Test A (RED first): a survey room whose project package has NO per-room `works_summary` but DOES have prose `av_requirements`/project-level scope text renders that room's `_ctx.planned_actions` as a non-empty array (derived from the prose) — the page shows the "Install actions" bullet panel, not the single-paragraph "Planned AV works" panel.
    - Test B (non-vacuity — the office-wins case): a sibling room in the SAME survey whose project package DOES have a `works_summary` — its `_ctx.planned_actions` is EXACTLY the office-authored lines, byte-for-byte unchanged from today's behavior, and the rendered panel still reads plain "Install actions" with no derived-source marker.
  </behavior>
  <action>
    Add `public static function textToBullets(string $roomName, string $text): array` to `app/Support/SurveyPdfHelpers.php` (a static-helper class in `App\Support`, the established home for survey text-cleansing logic per its own docblock), carrying the EXACT logic currently in `PublicSurveyController::textToBullets()` (~:181-197). In `PublicSurveyController.php`, replace the body of the now-redundant private `textToBullets()` with a one-line delegation to `\App\Support\SurveyPdfHelpers::textToBullets($roomName, $text)` — the method itself stays (dead but not deleted, per the task's own constraint), it just stops duplicating logic that now lives in one place.

    In `SurveyController::buildAlpineRooms()` (app/Http/Controllers/SurveyController.php, inside the per-room loop around :480-512), change how `_ctx.planned_actions` is computed: first resolve the office-authored bullets text exactly as today (`$packageRoomBullets[strtolower($dbRoom->room_name)] ?? ''`); if non-empty, keep the EXISTING newline-split behavior unchanged (office wins, byte-for-byte) and set a new `_ctx.planned_actions_source = 'office'`; if it is empty AND `$plannedWorks` (the existing prose variable, already resolved a few lines above from `av_requirements`/package scope/project scope) is non-empty, derive the bullets via `\App\Support\SurveyPdfHelpers::textToBullets((string) ($dbRoom->room_name ?? ''), $plannedWorks)` and set `_ctx.planned_actions_source = 'derived'`; otherwise leave `planned_actions` empty and `planned_actions_source = ''`. Add `'planned_actions_source'` to the `_ctx` array alongside the existing `'planned_actions'` key.

    In `resources/views/surveys/show.blade.php`, inside the "INSTALL ACTIONS" `<summary>` block (~:575-583), change the static `<span>Install actions</span>` to an `x-text` binding that appends a short qualifier only when derived, e.g. `x-text="rooms[{{ $idx }}]._ctx.planned_actions_source === 'derived' ? 'Install actions (from scope notes)' : 'Install actions'"` — this is the "heading must make clear which the engineer is looking at" requirement; the office-authored case's heading and badge count stay pixel-identical to today. The existing prose fallback block (~:594-604) is left in place untouched as the ultimate fallback for the now-rare case where even `$plannedWorks` is empty — do not remove it.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveyPlannedActionsBulletsTest > "$env:TEMP/g2.txt" 2>&1; Get-Content "$env:TEMP/g2.txt" -Tail 8</automated>
  </verify>
  <done>SurveyPlannedActionsBulletsTest passes with 0 failures: a room with no office works_summary now shows derived bullets (labelled "from scope notes"), and a room with office works_summary renders unchanged. Quote the RED failure from before the fix and the GREEN pass after.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Surface the AV Works Summary editor from the cockpit</name>
  <files>resources/views/projects/cockpit.blade.php, tests/Feature/Cockpit/CockpitAvWorksSummaryLinkTest.php</files>
  <behavior>
    - Test A (RED first): visiting the cockpit page (`GET` the route `ProjectCockpitController::show` resolves to) for a project that HAS a `latestPackage` — assert the RENDERED response body contains an `<a href="...">` whose `href` resolves via `route('project-packages.review.show', $package)` (built from the fixture's own package id, not `Route::has()`) and whose link text names the AV Works Summary / Room Overviews editor.
    - Test B (non-vacuity — the orphan-link trap this task's own framing warns about): the SAME test also asserts the href targets the `#s-room-overviews` section and that the project WITHOUT any package renders the cockpit without that link (no broken `route()` call, no exception) — proving the conditional guard rather than an unconditional render that would 500 on a package-less project.
  </behavior>
  <action>
    In `resources/views/projects/cockpit.blade.php`, immediately beside the existing "Open full project" link at :276 (same `<p class="cav-note">` block, or a sibling line directly after it), add a second link, guarded by `@if ($project->latestPackage)`, pointing at `route('project-packages.review.show', $project->latestPackage) . '#s-room-overviews'` with link text "Edit AV Works Summary" (or equivalent naming the field), using the same `cav-link` class as the existing link so it matches the page's established style with zero new CSS. This is a plain `<a>` navigation link — `<a>` is not in `CockpitReadOnlyFenceTest::FORBIDDEN_MARKUP` and this adds no write affordance, so the fence's `DEFERRED_AFFORDANCES` / `WRITE_SURFACE_TABLES` counts are unaffected and need no update. Do not eager-load `latestPackage` in the controller — the existing lazy-relation pattern used elsewhere in this codebase (e.g. `SurveyController.php:343`) is sufficient and keeps this a one-file Blade-only change.
  </action>
  <verify>
    <automated>powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter CockpitAvWorksSummaryLinkTest > "$env:TEMP/g3.txt" 2>&1; Get-Content "$env:TEMP/g3.txt" -Tail 8</automated>
  </verify>
  <done>CockpitAvWorksSummaryLinkTest passes with 0 failures: the cockpit page for a project with a package renders a real link to project-packages.review.show#s-room-overviews (asserted against rendered HTML, not Route::has()), and a project without a package renders the page without that link and without error.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Engineer (unauthenticated, token-gated) → `SurveyController`/`PublicSurveyController` | Anyone holding the UUID access token can GET/POST; the token is the only credential |
| Office (authenticated cockpit user) → `ProjectCockpitController` | Already behind `auth()->check()`, unaffected by this plan |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-gxt-01 | Tampering | `SiteSurvey::isLockedForEngineer()` | mitigate | Fold `isSuperseded()` in as an unconditional short-circuit so a stale/bookmarked token can never mutate a replaced survey's data via any of the 8 write endpoints — this IS the fix for Task 1 |
| T-gxt-02 | Repudiation | `VisitReworkState::isReopened()` vs superseded | mitigate | Superseded check runs before the reopened comparison so a legitimate "Send back" on an unrelated newer survey can never be read as reopening the superseded one; Test D in Task 1 proves this directly |
| T-gxt-03 | Information Disclosure | `engineerLockMessage()` 403 body | accept | The message text ("replaced by a newer survey" / "already submitted") discloses only survey lifecycle state already visible on the GET page to the same token-holder — no new information crosses the trust boundary |
| T-gxt-04 | Tampering | `SurveyPdfHelpers::textToBullets()` input | accept | Input is office-authored `works_summary`/`av_requirements` text already rendered unescaped-safe via Blade's default `{{ }}`/`x-text` escaping on this zero-unescaped-echo page; no new user input path is introduced |
| T-gxt-05 | Elevation of Privilege | New cockpit link to `project-packages.review.show` | accept | The link is gated behind the SAME `auth()->check()` the cockpit page already requires; it grants no access an authenticated office user didn't already have via "Open full project" → Projects → Packages |
</threat_model>

<verification>
Run each task's own `<verify>` command in sequence (foreground, each redirected to its own temp file, never piped — per the project's gate-form constraint). Then run the full relevant suites to confirm no regression, measuring foreground, not inheriting prior figures:

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Path tests/Feature/Surveys > "$env:TEMP/gsurveys.txt" 2>&1; Get-Content "$env:TEMP/gsurveys.txt" -Tail 8
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Path tests/Feature/Cockpit > "$env:TEMP/gcockpit.txt" 2>&1; Get-Content "$env:TEMP/gcockpit.txt" -Tail 8
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Baseline > "$env:TEMP/gbaseline.txt" 2>&1; Get-Content "$env:TEMP/gbaseline.txt" -Tail 8
```

D-06 baseline gate is `>= 159 passed AND 0 failed` — never equality against 161. Re-measure `tests/Feature/Surveys` and `tests/Feature/Cockpit` totals from these foreground runs; do not inherit the 75/0 and 460/0 figures cited in STATE.md without re-running.
</verification>

<success_criteria>
- Task 1's test proves (a) a superseded draft survey renders read-only with a branded "replaced" message, (b) all eight write endpoints 403 on it, (c) a live survey is completely unaffected, (d) superseding cannot be reopened via Send-back.
- Task 2's test proves a room with no office works_summary now renders derived bullets, and a room with office works_summary is byte-for-byte unchanged.
- Task 3's test proves the cockpit RENDERS a reachable link to the AV Works Summary editor, verified against rendered HTML content, and degrades safely (no link, no error) for a package-less project.
- `tests/Feature/Surveys`, `tests/Feature/Cockpit`, and the D-06 baseline all pass with 0 failures when re-measured in the foreground after this plan.
- Three protected files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) remain byte-identical to `4abd2b24` — untouched by this plan, so no hash check is even expected to move.
- No migration was written; no row in `site_surveys` was deleted; `access_token`/`submitted_notification_sent_at` remain out of `$fillable`.
</success_criteria>

<output>
Create `.planning/quick/261008-gxt-superseded-survey-link-read-only-bullet-/261008-gxt-SUMMARY.md` when done, quoting each task's RED failure and GREEN pass, and the foreground suite counts actually measured.
</output>

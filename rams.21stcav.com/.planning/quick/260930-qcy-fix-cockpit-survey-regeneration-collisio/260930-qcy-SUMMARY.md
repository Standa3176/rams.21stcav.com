---
quick_id: 260930-qcy
type: execute
completed: 2026-09-30
commits:
  - 1129ab91: "fix(cockpit): refuse survey-visit collision before the transaction opens"
  - 3a906f48: "feat(cockpit): surface the existing supersede action for a live survey"
  - d90508cf: "feat(cockpit): a document-only regenerate for the site survey"
files_modified:
  - app/Support/Cockpit/CockpitCombinedCreator.php
  - app/Http/Controllers/ProjectCockpitDocumentController.php
  - app/Http/Requests/CockpitDocumentRequest.php
  - resources/views/components/cockpit/doc-form.blade.php
  - tests/Feature/Cockpit/CockpitCombinedCreationTest.php
  - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  - tests/Feature/Cockpit/CockpitDocumentFormTest.php
---

# Fix cockpit survey regeneration collision — Summary

**One-liner:** A pre-transaction `visitAlreadyClaimsSurvey()` guard refuses the permanent `visits_source_unique` collision with a true message and the existing engineer link, a second form surfaces the existing supersede route for a genuinely fresh survey, and a new `intent=regenerate-document` action updates the survey's fields and rebuilds its document with no visit and no link ever attempted.

## What was built

**Task 1 — refuse the collision before the transaction opens.** `CockpitCombinedCreator::liveSurvey()` (a thin passthrough to `VisitLinkIssuer::liveSurveyFor()`) and `visitAlreadyClaimsSurvey()` (reads back the exact `(source_type, source_id)` predicate `visits_source_unique` enforces) are asked in `ProjectCockpitDocumentController::createCombined()` before `$this->creator->create()` is ever called for the `site_survey` module. When a visit already claims the live survey, the request never reaches the transaction: it flashes `session('cockpit_existing_link')` with the survey's `publicUrl()` and returns `back()->withInput()->withErrors(['module' => ...])` with a message that states the true, permanent reason — no "rolled back", no "try again". `doc-form.blade.php` renders the flashed link as plain, escaped, selectable text inside an `<a>` whose link text is the URL. `CockpitCombinedCreator`'s docblock is corrected: the adopted-survey retry is safe for the SURVEY, not unconditionally safe for the VISIT.

**Task 2 — surface the existing supersede action.** A second `<form>` in the open panel (site_survey module only, only when `$holdsDocument`) posts directly to the existing `site-surveys.supersede-from-project` route with no new controller logic and no second confirmation screen. The fence's `test_every_form_in_the_region_carries_a_csrf_token` count moved 4 → 5, by name, with an inline comment.

**Task 3 — a document-only regenerate.** `CockpitDocumentRequest::INTENT_REGENERATE = 'regenerate-document'` joins the closed `INTENTS` set. `groupsToValidate()`'s call site now widens to every group for `regenerate-document` as well as `create` (the gap the plan-check found: narrowing to the submitting step would have silently dropped earlier-step edits from `validated()`). `ProjectCockpitDocumentController::regenerateDocumentOnly()` is site-survey-only (`abort_unless` 404s any other module), resolves the existing survey via the same `activeSurvey()` predicate `createCombined()` uses, calls only `persist()`, and never touches `CockpitCombinedCreator` or `VisitLinkIssuer`. A second submit button (`Update document only`) sits inside the SAME `<form>` on the last step, after the primary `create` button, so Enter-to-submit in a text field is unaffected.

## Test figures (foreground runs, PHP via Herd binary, never piped)

| Suite | Result |
|---|---|
| `tests/Feature/Cockpit/CockpitCombinedCreationTest.php` | **24 passed (176 assertions)** |
| `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` | **13 passed (1546 assertions)** |
| `tests/Feature/Cockpit/CockpitDocumentFormTest.php` | **38 passed (789 assertions)** |

All three figures above are from real foreground `gate-46.ps1` runs against the post-fix code, captured in `.tmp-gate-task3.txt`, `.tmp-final-2.txt`, `.tmp-final-3.txt` respectively (not committed — local scratch files). No suite was skipped.

## Task 1's regression test observed failing before the fix

`test_a_retry_when_a_visit_already_claims_the_live_survey_is_refused_not_rolled_back` was run against the UNFIXED code (`.tmp-gate-pre1.txt`) and genuinely failed:

```
FAILED  Tests\Feature\Cockpit\CockpitCombinedCreationTest > a retry when a visit already claims the live survey i…
Expected: This project's existing survey was used and left exactly as it was. The visit and the engineer link were NOT created. Nothing else was saved, so you can safely try again.
Not to contain: try again
at tests\Feature\Cockpit\CockpitCombinedCreationTest.php:749
```

What this proved: the unfixed code did NOT 500 (the generic `catch (Throwable $e)` in `createCombined()` already caught the `SQLSTATE[23000]` thrown by `Visit::save()`), but it mislabelled a PERMANENT collision as a transient one — "you can safely try again" — and never flashed the existing engineer link. The fix (a pre-transaction guard) turns this into a true, permanent message and hands back the link, without ever letting the collision reach the database at all.

Task 3's regression tests (`regenerate_document_only_*`, four tests) were also run against the pre-Task-3 code (`.tmp-gate-pre3.txt`) and failed with 8 assertions across 4 tests (validation errors on the unrecognised `regenerate-document` intent, since it was not yet in `INTENTS`), confirming the RED gate before the GREEN implementation.

## Fence counts (final)

| Fence | Count | Moved? |
|---|---|---|
| `<form>` per region (`test_every_form_in_the_region_carries_a_csrf_token`) | **5** | Moved 4 → 5, by name (Task 2's supersede form) |
| `FORBIDDEN_MARKUP` | 2 | Unchanged |
| `DEFERRED_AFFORDANCES` | 21 | Unchanged |
| `BANNED_HANDLER_ATTRIBUTES` | 9 | Unchanged |
| `WRITE_SURFACE_TABLES` | 13 | Unchanged |

To make the form-count move real rather than vacuous, `CockpitReadOnlyFenceTest::populatedProject()` was extended with a live `SiteSurvey` row (and the existing `backfilledFromSurvey()` visit now references it) — without a real survey on that fixture, the new supersede form would never have rendered and the 4→5 move would have been unearned.

## Fence copy checks

Every new copy string was checked as a substring against all 21 `DEFERRED_AFFORDANCES` keys and both `FORBIDDEN_MARKUP` entries before use:
- "This project already has a survey visit and an engineer link. You can regenerate the document without a new visit, or start a genuinely fresh survey." — OK
- "Existing engineer link:" — OK
- "Start a fresh survey" — OK
- "Superseding archives the current survey and starts a new one — this cannot be undone." — OK
- "Update document only" — OK
- "The document was updated." — OK
- "There is no survey yet for this project — generate one first." — OK

None collided.

## cockpit.css touched?

**No.** `resources/css/cockpit.css` was not touched by this quick task — no new CSS class, no Vite rebuild is required on deploy. All new markup reuses `.cav-qa__control`, `.cav-qa__note` and the existing `.cav-qa__form` class.

## Three protected files' hash verification

Verified with `Get-FileHash` against the working tree (never `git hash-object`, per this repo's CRLF trap) both before any edit and again after the final commit:

| File | Result |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` — matches baseline |
| `resources/css/app.css` | `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` — matches baseline |
| `tailwind.config.js` | `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` — matches baseline |

All three byte-identical throughout. No mismatch occurred; none of these three files was ever edited.

## Migration / schema

**None written.** `visits_source_unique` is untouched, confirmed by reading the migration and by every test above passing with the index still in place — the fix is entirely a pre-transaction application-layer read of the same predicate the index enforces.

## D-06 baseline gate

Run after all three tasks: **159 passed, 0 failed, 2 skipped (396 assertions)** — meets the `>= 159 passed AND 0 failed` gate (the 2 skips are the known missing-`ext-imagick` self-skips, not defects).

## Two incidental breaks found and fixed, out of the plan's named scope but caused directly by this plan's edits (Rule 1)

A full run of `tests/Feature/Cockpit` (412 tests) was made after the three named tasks to check for collateral damage the plan's three listed suites would not catch on their own. It found two, both fixed in the same commit as this SUMMARY:

1. **`CockpitPanelTest::test_no_cockpit_view_uses_unescaped_output`** — my own Task 1 comment in `doc-form.blade.php` literally wrote the phrase `never {!! !!}` to explain the escaping rule, and the test's `assertStringNotContainsString('{!!', ...)` matched that comment text, not real unescaped output. Fixed by rephrasing the comment to describe the rule without spelling out the banned token literally.
2. **`CockpitSpaceSelectAllTest::test_the_intent_set_is_exactly_five_and_rubbish_is_still_refused`** — pinned `CockpitDocumentRequest::INTENTS` at exactly five entries. Task 3 legitimately grows that closed set to six (`regenerate-document`). Renamed to `test_the_intent_set_is_exactly_six_and_rubbish_is_still_refused` and the expected array updated, by name, with an inline comment — never loosened to a floor.

After both fixes, the full `tests/Feature/Cockpit` directory (412 tests) passes: **412 passed (7974 assertions)**.

## No push, no deploy

Work ends at three local commits (`1129ab91`, `3a906f48`, `d90508cf`) on `feat/worksheet-classifier-universal`, plus this SUMMARY. Nothing was pushed and nothing was deployed.

## Deviations from Plan

**None** — the plan executed as written, including the two corrections the plan itself had already made during revision (the `groupsToValidate()` widening in Task 3, and the "no non-readonly step-3 survey field exists" correction in Task 3's Test A, which was followed exactly: `visit_rooms` is asserted accepted-without-error and persisting-nowhere, not against an invented column).

One executor-level addition beyond the plan's literal text: `CockpitReadOnlyFenceTest::populatedProject()` needed a real `SiteSurvey` row added (with the pre-existing `backfilledFromSurvey()` visit pointed at it) so that Task 2's fence-count move from 4 to 5 was earned by evidence rather than passing vacuously on a fixture with no document. This is documented here as the plan's own non-vacuity discipline required, not silently.

## Self-Check

- `app/Support/Cockpit/CockpitCombinedCreator.php` — FOUND, contains `liveSurvey()` and `visitAlreadyClaimsSurvey()`.
- `app/Http/Controllers/ProjectCockpitDocumentController.php` — FOUND, contains `regenerateDocumentOnly()`.
- `app/Http/Requests/CockpitDocumentRequest.php` — FOUND, contains `INTENT_REGENERATE`.
- `resources/views/components/cockpit/doc-form.blade.php` — FOUND, contains the existing-link render, the supersede form, and the regenerate button.
- Commits `1129ab91`, `3a906f48`, `d90508cf` — all present in `git log --oneline`.

## Self-Check: PASSED

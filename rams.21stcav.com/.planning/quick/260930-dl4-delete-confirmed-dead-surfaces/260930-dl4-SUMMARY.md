---
phase: quick
plan: 260930-dl4
type: chore
subsystem: consolidation
status: complete
commit: 2f936919
---

# Quick Task 260930-dl4 Summary

**Eight genuinely dead files deleted (3,920 lines). Group 2 and group 3 of the
inventory's delete list were REFUSED: every one of the five items in them has a
live caller the inventory missed, including the 2,214-line "old SSV" view.**

## The headline

D-04's largest item — `resources/views/public-survey/show.blade.php`, the old
survey engineer link — **was not deleted, and must not be.** It is unreachable
over HTTP, which is what the inventory measured, but it is *exercised* by three
tests, one of which renders it through a synthetic route specifically because the
live route table no longer reaches it. The inventory conflated "no route" with
"no caller".

## Deleted (8 files, 3,920 lines)

| File | Lines | Evidence it was dead |
|---|---|---|
| `resources/views/pdf/rams.blade - keep boarder.php` | 546 | Only repo hit was its own name inside `FfpTwoBannedFromSourceTest::EXCLUDED_FILES` — a *whitelist*, not an assertion. Blade cannot resolve the name. |
| `resources/views/pdf/rams.blade-keep-borders.php` | 546 | Same. |
| `resources/views/rams/quote-review.blade2903.php` | 874 | Zero hits on `blade2903` outside the file itself. The live view is `rams/quote-review.blade.php` (`RamsReviewController:78`). |
| `resources/views/pdf/om-manual/create.blade2703.php` | 285 | Zero `pdf.om-manual.<segment>` hits repo-wide. Named only in a test's path list. |
| `resources/views/pdf/om-manual/create.blade.php` | 315 | Same. `OmManualController:75` renders `om-manual.create` → `resources/views/om-manual/create.blade.php` (a different, present file; `diff` confirms they are NOT copies). |
| `resources/views/pdf/om-manual/edit.blade.php` | 450 | Same (`OmManualController:405` → `om-manual.edit`). |
| `resources/views/pdf/om-manual/index.blade.php` | 250 | Same (`OmManualController:44,50` → `om-manual.index`). |
| `app/scripts/generate_rams_docx.js` | 654 | Self-references only. Absent from `package.json` (`scripts` = build/dev). No `shell_exec`/`proc_open`/`exec` anywhere targets `app/scripts`. Its docblock names a caller, `RamsDocxBuilderService`, **that does not exist**. |

⚠️ `resources/views/pdf/om-manual.blade.php` (1,593 lines) — the LIVE O&M PDF,
rendered by `PdfService:95` as `pdf.om-manual` — **survives untouched.** It is the
sibling FILE, not the deleted directory.

Both now-empty directories (`resources/views/pdf/om-manual/`, `app/scripts/`) are
gone with their last file.

## Test lists repaired in the same commit

| File | Change | Why it is not a weakening |
|---|---|---|
| `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` | Removed the 4 `pdf/om-manual/*` entries from `CLIENT_FACING_PATHS`. | `test_every_enumerated_path_exists()` **asserts each path exists** — leaving them would have broken the suite. The files no longer exist to be scanned, so no coverage is lost. Comment records why. |
| `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` | Removed the 2 `keep border(s)` entries from `EXCLUDED_FILES`, and corrected the docblock. | This is a **tightening**: nothing under `resources/views` is whitelisted for the banned `FFP2` token any more. |

## REFUSED — group 2, the old survey engineer link

| Item | Live caller found |
|---|---|
| `resources/views/public-survey/show.blade.php` | `tests/Feature/SiteSurveyTierOneReadinessViewTest.php:38-42` registers `/__test/public-survey/{token}` → `PublicSurveyController@show` **for the express purpose of rendering this blade**, because the live route table no longer reaches it. Also `LabourResourceClientSurfacePrivacyTest:143` (existence-asserted) and `:224` (reads it from disk as the guard's own **non-vacuity proof**). |
| route `survey.save` (`routes/web.php:89`) | `tests/Feature/Visits/SendBackReopensEngineerLinkTest.php:141` POSTs `/survey/{t}/save` as one of **eight enumerated write gates**; `tests/Feature/Cockpit/CockpitSurveyFeedbackFieldsTest.php:214` POSTs `route('survey.save', …)` — inside the 392-test cockpit gate. |
| route `survey.room.uncomplete` (`:93`) | Same eight-gate list, `SendBackReopensEngineerLinkTest.php:149`. |
| `PublicSurveyController::save` / `::uncompleteRoom` | Same. Removing them would silently shrink the enforced 403 gate surface from 8 to 6 — the exact vacuity hazard the brief warned about. |

`PublicSurveyController` itself is untouched; `submit`, `confirmation`,
`uploadPhoto`, `servePhoto`, `updatePhoto`, `answerQuestion`, `completeRoom`,
`uncompleteRoom` and `save` all remain routed at `routes/web.php:85-99`.

## REFUSED — group 3, the "orphan" routes

| Item | Live caller the inventory missed |
|---|---|
| `site-surveys.supersede-from-project` (`:650`) | `ProjectController:191` passes the route NAME as data: `'regenerate_route_name' => 'site-surveys.supersede-from-project'` inside `$linkedRecords`, resolved **dynamically** by the project page. A literal-string Blade grep cannot see this. |
| `site-surveys.project-data` (`:651`) | Called by **hardcoded URL**, not route name: `resources/views/site-survey/create.blade.php:203` and `edit.blade.php:422` both `fetch(\`/site-surveys/project-data/${id}\`)`. |
| `admin.drawings.draw-io-spike.*` (`:516-524`) + `DrawIoSpikeController` | `tests/Feature/Drawings/V13SurfacesUntouchedTest.php:121` — a guard whose entire purpose is asserting this surface stays untouched — reflects on `DrawIoSpikeController`'s constructor; `DrawIoBuilderServiceTest.php:209` does the same; `SvgSanitizerService` and `AutoGenericStencilGenerator` docblock against `::exportSvg` / the builder shim. |

## Gates — all green, after the deletions

| Gate | Result |
|---|---|
| `-Path tests/Feature/Cockpit` | `Tests:    392 passed (7856 assertions)` — 228.45s |
| `-Path tests/Unit/Cockpit` | `Tests:    114 passed (1203 assertions)` — 14.91s |
| `-Path tests/Feature/Worksheets` | `Tests:    263 passed (2317 assertions)` — 65.77s |
| `-Path tests/Feature/Documents` | `Tests:    18 passed (142 assertions)` — 6.19s |
| `-Filter Rams` | `Tests:    2 deprecated, 910 passed (3710 assertions)` — 148.78s |
| `-Filter Survey` | `Tests:    240 passed (1107 assertions)` — 58.47s |
| `-Baseline` (D-06) | `Tests:    2 skipped, 159 passed (396 assertions)` — 38.97s → ≥159 passed AND 0 failed |
| `-Hashes` | all three match `45-BASELINE.md` |

Cockpit total 392 + 114 = **506 / 0**.

Two suites run explicitly because no gate filter reliably covers them:
`tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php`
(`14 passed`) and `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php`
(`1 passed`).

## What D-04 still contains, unspent

Groups 2 and 3 are **not** closed — they are re-classified from "dead" to
"unreachable over HTTP but under test". If they are to go, the tests covering
them have to be retired deliberately, as their own decision, not as collateral
of a deletion.

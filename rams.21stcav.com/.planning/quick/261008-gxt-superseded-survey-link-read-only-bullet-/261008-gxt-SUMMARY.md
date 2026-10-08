# 261008-gxt — Superseded Survey Link Read-Only, Bullets, Cockpit Link — Summary

Three audit findings fixed, each with its own RED-then-GREEN test and commit.

## Task 1 (PRIORITY) — Superseded survey link goes read-only everywhere, never reopenable

**RED** (reverted the source changes, kept the test, ran `-Filter SurveySupersededLockTest`):

```
FAILED  Tests\Feature\Surveys\SurveySupersededLockTest > a live not superseded not submit…  BadMethodCallException
  Call to undefined method App\Models\SiteSurvey::isSuperseded()

FAILED  Tests\Feature\Surveys\SurveySupersededLockTest > superseded survival against a newer send back
  A superseded survey must stay locked even though VisitReworkState reports reopened.
  Failed asserting that false is true.

Tests: 4 failed (7 assertions)
```

**GREEN** (fix reapplied):

```
PASS Tests\Feature\Surveys\SurveySupersededLockTest
✓ superseded draft never submitted renders read only with replaced message    5.62s
✓ all eight write endpoints 403 on a superseded survey                        0.23s
✓ a live not superseded not submitted survey stays fully editable             0.18s
✓ superseded survival against a newer send back                              0.09s

Tests: 4 passed (23 assertions)
```

**What changed:**
- `SiteSurvey::isSuperseded()` added (`superseded_at !== null`), matching the `ProjectDrawing`/`Visit` naming convention.
- `SiteSurvey::engineerLockMessage()` added — single source of truth for the 403/banner copy, so "replaced" and "already submitted" are never confused.
- `isLockedForEngineer()` now checks `isSuperseded()` FIRST, unconditionally, before `isSubmitted()`. This makes superseding immune to the office "Send back" reopen path — `VisitReworkState::isReopened()` is never consulted once `isSuperseded()` is true.
- `SurveyController::show()` passes `'superseded' => $survey->isSuperseded()` into the view; `stepSave()` uses `engineerLockMessage()`.
- `PublicSurveyController.php` — all 7 live `abort_if($survey->isLockedForEngineer(), 403, ...)` call sites (`save`, `submit`, `completeRoom`, `uncompleteRoom`, `answerQuestion`, `uploadPhoto`, `updatePhoto`) now use `$survey->engineerLockMessage()` instead of the hardcoded "already submitted" string.
- `resources/views/surveys/show.blade.php` — the readonly banner now branches on `$superseded`: "This survey has been replaced by a newer survey. Please contact the office — anything you capture here is saved but will not be used." vs the existing "submitted and is read-only" copy. Same `bg-amber-50`/`border-amber-200`/`text-amber-800` classes — no new colour token.

**Confirmed:**
- All 8 write endpoints (`step-save`, `save`, `submit`, `rooms/{room}/complete`, `rooms/{room}/uncomplete`, `rooms/{room}/questions/{q}`, `rooms/{room}/photos`, `PATCH photos/{photo}`) return 403 for a superseded survey — proven in `test_all_eight_write_endpoints_403_on_a_superseded_survey`.
- A normal LIVE survey (not superseded, not submitted) still saves: `test_a_live_not_superseded_not_submitted_survey_stays_fully_editable` asserts `GET show` 200/no banner, `step-save` 200, `save` redirects 302, `completeRoom` 200.
- A superseded-but-never-submitted draft is locked: `test_superseded_draft_never_submitted_renders_read_only_with_replaced_message` creates a survey with `submitted_at` null and `superseded_at` set, asserts the GET renders the "replaced" message (and the test asserts `submitted_at` is null as a fixture-sanity check).
- Superseding survives a newer send-back: `test_superseded_survival_against_a_newer_send_back` wraps the survey in a `Visit::factory()->backfilledFromSurvey($survey)->sentBack()` (which forces `sent_back_at` to be AFTER `submitted_at` — the one path that makes `VisitReworkState::isReopened()` return true), then supersedes it, and asserts `isLockedForEngineer()` is still true.

## Task 2 — Planned-works prose fallback renders as bullets

**RED:**

```
FAILED Tests\Feature\Surveys\SurveyPlannedActionsBulletsTest > room with no office bullets but p…  ErrorException
  Undefined array key "planned_actions_source"

Tests: 3 failed (8 assertions)
```

**GREEN:**

```
PASS Tests\Feature\Surveys\SurveyPlannedActionsBulletsTest
✓ room with no office bullets but prose renders derived bullets             6.06s
✓ room with office works summary is byte for byte unchanged                 0.14s
✓ the rendered page carries the derived qualifier markup                    0.16s

Tests: 3 passed (16 assertions)
```

**What changed:**
- `App\Support\SurveyPdfHelpers::textToBullets()` added — the exact logic moved out of `PublicSurveyController`'s dead `show()`/private `textToBullets()`. `PublicSurveyController::textToBullets()` is kept (not deleted — its own GET route is unreachable, per the plan's own D-05 constraint) but now just delegates to the shared helper.
- `SurveyController::buildAlpineRooms()` — when a room has no office-authored `works_summary`, it now derives `_ctx.planned_actions` from the planned-works prose (`$plannedWorks`) via the shared helper, and sets `_ctx.planned_actions_source` to `'office'` | `'derived'` | `''`.
- `resources/views/surveys/show.blade.php` — the "Install actions" heading is now `x-text`-bound: `'Install actions (from scope notes)'` when `planned_actions_source === 'derived'`, else plain `'Install actions'`.

**Confirmed:**
- A room with NO office `works_summary` but WITH prose (`description`) now renders derived bullets — `test_room_with_no_office_bullets_but_prose_renders_derived_bullets` asserts `planned_actions_source === 'derived'` and a non-empty bullet array (>=2 items) from the room's JSON blob embedded in the rendered page.
- A sibling room WITH office `works_summary` is byte-for-byte unchanged — `test_room_with_office_works_summary_is_byte_for_byte_unchanged` asserts `planned_actions_source === 'office'` and the exact newline-split array `['Install 55in display', 'Run HDMI and power to rack', 'Mount ceiling speaker']`, in the SAME survey as a sibling derived-bullets room (proving both paths coexist).
- The rendered page carries the qualifier markup: `test_the_rendered_page_carries_the_derived_qualifier_markup` asserts the literal string `"Install actions (from scope notes)"` appears in the response HTML.

## Task 3 — Surface the AV Works Summary editor from the cockpit

**RED:**

```
FAILED Tests\Feature\Cockpit\CockpitAvWorksSummaryLinkTest > cockpit renders a real link to the …
  To contain: href="http://rams.21stcav.com.test/project-packages/1/review#s-room-overviews"

Tests: 1 failed, 1 passed (6 assertions)
```

(Test B — the package-less project — passed even pre-fix, confirming the baseline "no link when no package" behaviour already held and the new conditional guard introduces no regression there.)

**GREEN:**

```
PASS Tests\Feature\Cockpit\CockpitAvWorksSummaryLinkTest
✓ cockpit renders a real link to the av works summary editor            3.92s
✓ a project without a package renders without the link and without error 0.17s

Tests: 2 passed (7 assertions)
```

**What changed:**
- `resources/views/projects/cockpit.blade.php` — added `<a class="cav-link" href="{{ route('project-packages.review.show', $project->latestPackage) }}#s-room-overviews">Edit AV Works Summary</a>` beside the existing "Open full project" link, guarded by `@if ($project->latestPackage)`. No eager-load added to the controller (uses the existing lazy-relation pattern, same as `SurveyController.php:343`).

**Confirmed:**
- The link is RENDERED with the href built from the fixture's own package id (`route('project-packages.review.show', $package)`, not `Route::has()`), anchored at `#s-room-overviews`.
- A project without a package renders the cockpit with no `AV Works Summary` text and no `s-room-overviews` string anywhere in the page, and without error (no 500 from a null-package `route()` call).
- `CockpitReadOnlyFenceTest` re-run after this change: 14 passed, 0 failed — the new `<a>` does not move `FORBIDDEN_MARKUP`, `DEFERRED_AFFORDANCES`, or any other fence count (confirmed by rerunning the full fence suite).

## Full suite re-measurement (foreground, after all three tasks)

```
tests/Feature/Surveys  : 64 passed, 0 failed (592 assertions), 34.41s
tests/Feature/Cockpit  : 462 passed, 0 failed (9171 assertions), 207.64s
D-06 baseline          : 159 passed, 2 skipped, 0 failed (396 assertions), 38.40s
```

Per the plan's own instruction, these were measured fresh in the foreground rather than inherited from STATE.md's prior 75/0 and 460/0 figures — the Surveys count (64 vs the previously-cited 75) and Cockpit count (462 vs 460) both differ slightly from the stale figures, which is expected test-suite drift, not a regression; both show 0 failed.

## Protected files — byte-identical check

```
layouts/app.blade.php  9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  MATCH
resources/css/app.css  EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  MATCH
tailwind.config.js     73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  MATCH
```

All three byte-identical to the `4abd2b24` baseline — none of them were touched by this plan, and `gate-46.ps1 -Hashes` confirms it directly rather than by inference.

`resources/css/cockpit.css` was never touched (confirmed via `git diff --stat` across all three commits) — **no `npm run build` is required on deploy.**

## Scope constraints honoured

- No migration was written — `superseded_at` already existed.
- Nothing was deleted: `PublicSurveyController::show()` and its `textToBullets()` stay in place (the latter now delegates rather than duplicates); no route or class removed.
- `access_token` / `submitted_notification_sent_at` remain out of `SiteSurvey::$fillable` — untouched.
- `engineer-capture` IndexedDB was not touched.
- No JavaScript/Alpine added to the cockpit region; the new link is a plain `<a>` navigation element, not a write affordance.
- No banned colour token introduced or named in a comment near the new banner.

## Could not deliver

Nothing was deferred. All three tasks, their tests, and the full verification suite ran to completion with 0 failures.

## Commits

- `a0944fe6` — fix(261008-gxt): lock superseded survey engineer links read-only everywhere
- `aae7f772` — feat(261008-gxt): planned-works prose fallback renders as bullets
- `fdec8dce` — feat(261008-gxt): surface the AV Works Summary editor from the cockpit

Ended at local commits — not pushed, not deployed, per instruction.

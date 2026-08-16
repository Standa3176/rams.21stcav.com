---
quick_id: 260816-ru4
slug: survey-token-test-repair
date: 2026-08-16
status: complete
---

# Quick Task 260816-ru4 — Repair 11 Site Survey tests broken by the access_token fillable guard

## What happened

The 2026-07-09 security batch removed `access_token` (and `access_token_expires_at` on
`Worksheet`) from `$fillable` to close a mass-assignment vector. That change was correct
and deliberate. The re-audit's repair note says `forceFill` was applied to "the 2 suites
that seeded controlled tokens" — but two Site Survey suites were missed:

- `tests/Feature/PublicSurveyControllerTest.php`
- `tests/Feature/PublicSurveyQuestionAnswerTest.php`

Both still called `SiteSurvey::create([... 'access_token' => $token ...])`. Because
`access_token` is guarded, Laravel silently dropped it from the mass-assignment payload;
`SiteSurvey::boot()`'s `creating` hook then generated its own random UUID (since the
attribute was empty at that point), not the token the test held in `$token`. Every
`{token}`-bound route in the test then 404'd instead of returning the expected
200/403/422 — an 11-test failure signature entirely in the test layer, not the product.

## What changed (test-only)

In every `SiteSurvey::create([...])` call in the two files that previously included
`access_token` in the payload:

1. Removed `access_token` from the `create()` payload.
2. Immediately followed with `$survey->forceFill(['access_token' => $token])->save();` —
   the exact pattern already established in
   `tests/Feature/Worksheet/WorksheetTokenExpiryTest.php` and
   `tests/Feature/SiteSurveyTierOneReadinessViewTest.php`.
3. Added `$this->assertNotNull($survey->access_token)` immediately after each seed, so a
   future fillable regression fails loudly at the seed line instead of surfacing as a
   confusing downstream 404.

Four call sites touched across the two files:
- `PublicSurveyControllerTest::makeSurveyWithRoom()` (helper used by 4 tests)
- `PublicSurveyControllerTest::test_survey_submission_dispatches_SurveySubmittedMail_to_owner()`
- `PublicSurveyQuestionAnswerTest::setUp()` (used by all 6 tests in the class)
- `PublicSurveyQuestionAnswerTest::test_post_to_question_belonging_to_different_room_returns_403()`'s
  second (`$otherSurvey`) seed

`SiteSurvey::$fillable` was NOT touched. No production code was touched. `git diff`
confirms only the two test files changed.

## Deviations from Plan

None — plan executed exactly as written. The fix matched the plan's prescribed pattern
and scope precisely; no unplanned files were touched.

## Verification

- Lint: `php -l` clean on both files.
- Targeted: `php artisan test --filter="PublicSurveyControllerTest|PublicSurveyQuestionAnswerTest"`
  → **11 passed (34 assertions)**, 0 failed.
- Full surface: `php artisan test --filter="Survey|Worksheet|OmManual|MiniOm"`
  → **Before: 399 passed, 11 failed. After: 410 passed, 0 failed (1614 assertions).**

## 🚨 Files to upload to live

**None — test-only change, nothing to deploy.**

## Known follow-up (not fixed in this task)

11 further test files still mass-assign `access_token` without `forceFill`. Their tests
currently pass because none of them route-bind by token yet, so the silent-drop bug is
latent, not observable. Any future test in these files that hits a `{token}` route will
404 confusingly, with the same root cause diagnosed here:

- `Feature/DocumentEdits/SurveyEditAdapterTest`
- `Feature/Jobs/GenerateSurveyQuestionsJobTest`
- `Feature/ProjectReferenceFiles/EndToEndTest`
- `Feature/ProjectReferenceFiles/PublicSurveyDownloadTest`
- `Feature/SiteSurvey/SurveyPdfModesTest`
- `Feature/StaleDocsAfterSurveySubmitTest`
- `Feature/SurveyDownloadFormTest`
- `Unit/Models/SurveyRoomQuestionModelTest`
- `Unit/Services/SiteConditionsBuilderTest`
- `Unit/Services/Survey/SiteSurveyTierOneReadinessServiceTest`
- `Unit/SurveyServiceTest`

## Known unrelated pre-existing failures (out of scope)

2 `DrawIoSpikeController` constructor-arity tests fail outside this filter — not a
regression, tracked separately in `deferred-items.md`.

## Self-Check: PASSED

- `tests/Feature/PublicSurveyControllerTest.php` — FOUND, modified as described.
- `tests/Feature/PublicSurveyQuestionAnswerTest.php` — FOUND, modified as described.
- Commit `6f409b4` — FOUND in `git log`.

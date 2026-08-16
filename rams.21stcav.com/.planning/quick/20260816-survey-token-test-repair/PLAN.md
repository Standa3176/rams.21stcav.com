---
quick_id: 260816-ru4
slug: survey-token-test-repair
date: 2026-08-16
status: planned
---

# Quick Task 260816-ru4 — Repair 11 Site Survey tests broken by the access_token fillable guard

## Why

`php artisan test --filter="Survey|Worksheet|OmManual|MiniOm"` → **399 passed, 11 failed**. All 11 failures are Site Survey, all share one root cause, and all are pre-existing (not from any recent work).

**This is a test defect, not a product defect.** The production behaviour is correct and deliberately hardened.

## Root cause

The 2026-07-09 security batch removed `access_token` and `access_token_expires_at` from `SiteSurvey::$fillable`, so a token can no longer be mass-assigned. That is the intended fix and must NOT be reverted.

These suites still seed the token by mass assignment:

```php
SiteSurvey::create([... 'access_token' => $this->token ...]);
```

Laravel silently drops the guarded key, the survey saves with **no token**, and every route bound by `{token}` then 404s — which is exactly the observed failure signature (`Expected response status code [403] but received 404`).

The same batch's repair note records that `forceFill` was applied to "the 2 suites that seeded controlled tokens". These two were missed.

## Established pattern to copy

`tests/Feature/Worksheet/WorksheetTokenExpiryTest.php:35-59` — splits the payload, mass-assigns the unguarded fields, then applies guarded fields via `forceFill(...)->save()`:

```php
$guarded        = ['access_token', 'access_token_expires_at'];
$fillOverrides  = array_diff_key($overrides, array_flip($guarded));
$forceOverrides = array_intersect_key($overrides, array_flip($guarded));
// … create with $fillOverrides …
if ($forceOverrides !== []) { $worksheet->forceFill($forceOverrides)->save(); }
```

`tests/Feature/SiteSurveyTierOneReadinessViewTest.php` is a second reference.

## Scope — exactly two files

The 11 failures map precisely:

| File | Failing tests |
|---|---|
| `tests/Feature/PublicSurveyControllerTest.php` | 5 — 4 × "complete room …", 1 × "survey submission dispatches survey submitted mail to owner" |
| `tests/Feature/PublicSurveyQuestionAnswerTest.php` | 6 — the "post with answer …" / "post to …" set |

## Tasks

### Task 1 — Seed the token via forceFill in both suites

**Files:** the two above.

**Action:** In every place these suites create a `SiteSurvey` with an `access_token` (and `access_token_expires_at` where present), create the record without the guarded keys, then apply them with `forceFill([...])->save()`. Follow `WorksheetTokenExpiryTest`'s shape. Note `PublicSurveyQuestionAnswerTest` seeds a **second** survey around line 137 (`$otherSurvey`, used by the cross-room 403 test) — that one needs the same treatment.

**Do NOT:**
- add `access_token` back to `SiteSurvey::$fillable` — that would re-open the mass-assignment vector the security batch closed
- change any production code; this task touches `tests/` only
- weaken assertions to make tests pass — the expected 200/403/422 statuses are correct and must stay

**Acceptance criteria:**
- All 11 previously-failing tests pass
- `php artisan test --filter="PublicSurveyControllerTest|PublicSurveyQuestionAnswerTest"` is fully green
- `SiteSurvey::$fillable` is unchanged — verify with git diff that no file outside `tests/` is modified
- Each seeded survey has a non-null `access_token` after creation (assert it once, so a future fillable change fails loudly here rather than as a confusing 404)

### Task 2 — Full-surface re-run

**Action:** Re-run `php artisan test --filter="Survey|Worksheet|OmManual|MiniOm"` and confirm **410 passed, 0 failed** (399 + the 11 repaired). Report the before/after numbers.

**Acceptance criteria:** zero failures across the three surfaces.

## Note in the SUMMARY, do not fix

11 further test files mass-assign `access_token` without `forceFill`. Their tests currently pass because they never route-bind by token, so they are **latent**, not broken — the same silent drop is happening, it just isn't observable yet. Any future test in these files that hits a `{token}` route will 404 confusingly.

List them in the SUMMARY as a known follow-up:
`Feature/DocumentEdits/SurveyEditAdapterTest`, `Feature/Jobs/GenerateSurveyQuestionsJobTest`, `Feature/ProjectReferenceFiles/EndToEndTest`, `Feature/ProjectReferenceFiles/PublicSurveyDownloadTest`, `Feature/SiteSurvey/SurveyPdfModesTest`, `Feature/StaleDocsAfterSurveySubmitTest`, `Feature/SurveyDownloadFormTest`, `Unit/Models/SurveyRoomQuestionModelTest`, `Unit/Services/SiteConditionsBuilderTest`, `Unit/Services/Survey/SiteSurveyTierOneReadinessServiceTest`, `Unit/SurveyServiceTest`.

## Constraints

- `tests/` only. No production code, no migration, no new packages.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Nothing to deploy — the SUMMARY's "🚨 Files to upload to live" section should say **none; test-only change**.
- Known unrelated pre-existing failures: 2 `DrawIoSpikeController` constructor-arity tests in `deferred-items.md`. They are outside this filter and are not regressions.

## Why this matters beyond a green suite

While these 11 fail, the **public survey flow has no passing coverage**: answering questions, room completion, the 403 guards on submitted surveys and cross-room access, and the submission notification are all unverified. Repairing them restores the safety net on a client-facing flow.

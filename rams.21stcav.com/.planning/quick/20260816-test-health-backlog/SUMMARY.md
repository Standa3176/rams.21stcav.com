---
quick_id: 260816-t5c
slug: test-health-backlog
date: 2026-08-16
status: complete
---

# Quick Task 260816-t5c — Clear the test-health backlog + record the partial-scope decision — Summary

Three items from the Survey/Worksheet/O&M QA pass. All test-side or documentation only — no production behaviour changed.

## 🚨 Files to upload to live

none — test/docs-only change.

## What was done

### Item 1 — Two stale `DrawIoSpikeController` lock-tests

`DrawIoSpikeController`'s constructor legitimately gained a third dependency (`SvgSanitizerService`, security batch `9a6837c`), which broke two lock-tests asserting the constructor had exactly 2 parameters. Phase 21 D-08's real rule was that `DrawingService` survives the constructor — an arity count was always a bad proxy for that (a 2-parameter constructor could still be broken).

Rewrote both tests to assert by dependency **type**, not parameter count:
- `DrawIoBuilderServiceTest::d08_spike_controller_constructor_has_two_parameters` → renamed `test_d08_spike_controller_still_injects_drawing_service`
- `V13SurfacesUntouchedTest::draw_io_spike_controller_constructor_has_two_parameters` → renamed `test_draw_io_spike_controller_still_injects_builder_and_drawing_service`

Both now reflect the constructor's parameter types and assert `DrawIoBuilderService` and `DrawingService` are both present, regardless of arity or position. `DrawIoSpikeController.php` was **not modified**.

**Verified the new tests actually detect the regression they lock** (not just pass by coincidence): temporarily removed `DrawingService` from the controller's constructor, ran both tests, confirmed both **failed** with the expected assertion message ("Constructor must STILL inject DrawingService"). Restored the controller and confirmed `git diff` against the pre-edit copy was empty (byte-identical restore). Re-ran both tests — both **passed** again.

### Item 2 — 11 latent `access_token` mass-assignment sites

`access_token` is guarded on `SiteSurvey` (2026-07-09 security batch, `SiteSurvey::$fillable`). `SiteSurvey::boot()`'s `creating` hook auto-generates a UUID whenever `access_token` is empty — so any `SiteSurvey::create(['access_token' => ...])` call was already a **silent no-op**: the passed-in value is discarded and a random one substituted. All 11 files below only passed because none of them route-bind against a hardcoded/expected token value.

Applied the same repair as quick task 260816-ru4:

**Force-filled (test reads `->access_token` back afterward — for route generation):**
- `tests/Feature/ProjectReferenceFiles/EndToEndTest.php`
- `tests/Feature/ProjectReferenceFiles/PublicSurveyDownloadTest.php` (`makeSurvey()` helper — used by every test in the file)
- `tests/Feature/SurveyDownloadFormTest.php` (`makeSurveyWithRoom()` helper, which also accepts `$overrides` — kept the array_merge for other fillable overrides like `expires_at`, only `access_token` needed the split)

**Key simply dropped (test never reads the token value back):**
- `tests/Feature/DocumentEdits/SurveyEditAdapterTest.php`
- `tests/Feature/Jobs/GenerateSurveyQuestionsJobTest.php` (2 occurrences)
- `tests/Feature/SiteSurvey/SurveyPdfModesTest.php`
- `tests/Feature/StaleDocsAfterSurveySubmitTest.php` (3 occurrences)
- `tests/Unit/Models/SurveyRoomQuestionModelTest.php`
- `tests/Unit/Services/SiteConditionsBuilderTest.php`
- `tests/Unit/Services/Survey/SiteSurveyTierOneReadinessServiceTest.php`
- `tests/Unit/SurveyServiceTest.php` (2 occurrences)

Removed now-unused `use Illuminate\Support\Str;` imports from files where the drop left no other `Str::` usage.

`SiteSurvey::$fillable` was **not modified** — `access_token` stays guarded.

### Item 3 — Recorded the partial-scope product decision

Created `.planning/notes/tier1-gating-decision.md`. Records: the O&M-only "NO TBC POLICY" blocking gate (`OmManualValidationService`) vs Worksheet's coarse binary gate (`BuildWorksheetJob:87`, zero-rooms-or-zero-content only) vs Site Survey's advisory-only `SiteSurveyTierOneReadinessService` (computes per-room readiness, never throws/blocks). User decision (2026-08-16): partial scope is legitimate — a worksheet with 7/8 empty rooms is a valid document, not a defect. No blocking gate is to be added to Worksheet or Site Survey. States plainly that `SiteSurveyTierOneReadinessService` being advisory-not-blocking is intentional, and a future contributor should not "fix" the missing gates by porting the O&M pattern over.

No existing `.planning/notes/` conventions/decisions file was found to append to, so a new dedicated file was created per the plan's fallback instruction.

## Verification

- **Lint:** `php84 -l` clean on all 13 touched PHP test files (2 drawings + 11 survey).
- **Item 1 regression-detection proof:** documented above — new tests fail when `DrawingService` is removed, pass when restored.
- **Item 2 grep-verifiable:** `grep -rn "'access_token'" tests/` shows zero remaining `SiteSurvey::create(['access_token' => ...])` mass-assignment sites — all remaining hits are `forceFill(...)->save()` calls or the `WorksheetTokenExpiryTest` guarded-field-name array.
- **Full surface:** `php artisan test --filter="Survey|Worksheet|OmManual|MiniOm"` — **410 passed, 0 failed** (1614 assertions, 77.74s). Same count before and after this task's changes (both Item 1 and Item 2 test files fall inside this filter).
- **Drawings filter:** `php artisan test --filter="DrawIoBuilderServiceTest|V13SurfacesUntouchedTest"` — 14/14 passed (163 assertions), both green before and after the constructor-removal regression proof.
- `git status --short` at completion shows changes in `tests/` and `.planning/` only.

## Deviations from Plan

None — plan executed exactly as written. The plan's own escape hatch ("acceptable to simply drop the key... but say which you did and why") was exercised for 8 of the 11 Item 2 files; documented per-file above and inline as code comments at each edit site.

## Commits

- `test(drawings)`: replace stale arity lock-tests with type-based D-08 checks (includes `deferred-items.md` resolution note for Phase 24)
- `test(survey)`: close latent access_token mass-assignment sites in 11 test files
- `docs(planning)`: record the tier1-gating-decision.md product decision

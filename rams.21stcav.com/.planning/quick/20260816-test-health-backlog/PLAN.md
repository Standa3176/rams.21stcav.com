---
quick_id: 260816-t5c
slug: test-health-backlog
date: 2026-08-16
status: planned
---

# Quick Task 260816-t5c — Clear the test-health backlog + record the partial-scope decision

Three items surfaced during the Survey/Worksheet/O&M QA pass. All are **test-side or documentation** — no production behaviour changes.

Both test defects share one shape: **a security fix made a test assertion obsolete, and the test was never updated.** Worth noting, because it is now the third instance in this codebase (the survey `access_token` repair, 260816-ru4, was the second).

---

## Item 1 — Two stale DrawIoSpikeController lock-tests

### Evidence

Logged as pre-existing in `.planning/phases/24-stencil-curation-ui-quote-import-auto-stub/deferred-items.md`, and confirmed:

- `Tests\Feature\Drawings\DrawIoBuilderServiceTest::d08_spike_controller_constructor_has_two_parameters`
- `Tests\Feature\Drawings\V13SurfacesUntouchedTest::draw_io_spike_controller_constructor_has_two_parameters`

Both assert the constructor has exactly **2** parameters. It has **3**:

```php
public function __construct(
    private readonly DrawIoBuilderService $builder,
    private readonly DrawingService $drawings,
    private readonly SvgSanitizerService $svgSanitizer,   // added by 9a6837c (WR-03/4/5)
) {}
```

The third was added legitimately by the SVG-sanitiser security batch.

### The production code is correct — do not "fix" it

Phase 21 D-08's actual rule was: *"Plan 21-03 MUST preserve the `DrawingService $drawings` parameter (used by `saveXml` + `exportSvg`). Any executor that drops the `DrawingService` parameter breaks `saveSpikeXml` and `saveSpikeSvg`."*

That rule is **satisfied** — `DrawingService` is still there. The tests encoded it as an arity count, which is a brittle proxy: it fails on any legitimate new dependency while still not actually proving `DrawingService` survived. A constructor could have 2 parameters and be broken.

### Task 1

**Files:** `tests/Feature/Drawings/DrawIoBuilderServiceTest.php`, `tests/Feature/Drawings/V13SurfacesUntouchedTest.php`

**Action:** Replace the arity assertion in both with a **type-based** assertion that expresses D-08's real intent — that the constructor still injects both `DrawIoBuilderService` and `DrawingService`. Use reflection on the parameter types rather than counting. Rename the test methods so the name states the actual guarantee (e.g. `d08_spike_controller_still_injects_drawing_service`) instead of a parameter count.

Add a short comment citing D-08 and explaining that an arity check was replaced because a legitimate third dependency (`SvgSanitizerService`, security batch `9a6837c`) broke it while the underlying rule was never violated.

**Acceptance criteria:**
- Both tests pass against the current 3-parameter constructor
- Both **fail** if `DrawingService` is removed from the constructor — verify by temporarily removing it, observing the failure, then restoring
- Neither test asserts a parameter count
- `DrawIoSpikeController.php` is NOT modified
- After this, `.planning/phases/24-.../deferred-items.md` no longer describes an open issue — update that entry to record it as resolved by this task

---

## Item 2 — 11 latent `access_token` mass-assignment sites

### Evidence

`access_token` is guarded on `SiteSurvey` (2026-07-09 security batch). Quick task 260816-ru4 repaired the two suites where this caused visible 404s. Eleven further test files still mass-assign it:

`Feature/DocumentEdits/SurveyEditAdapterTest`, `Feature/Jobs/GenerateSurveyQuestionsJobTest`, `Feature/ProjectReferenceFiles/EndToEndTest`, `Feature/ProjectReferenceFiles/PublicSurveyDownloadTest`, `Feature/SiteSurvey/SurveyPdfModesTest`, `Feature/StaleDocsAfterSurveySubmitTest`, `Feature/SurveyDownloadFormTest`, `Unit/Models/SurveyRoomQuestionModelTest`, `Unit/Services/SiteConditionsBuilderTest`, `Unit/Services/Survey/SiteSurveyTierOneReadinessServiceTest`, `Unit/SurveyServiceTest`.

They pass **today** only because they never route-bind by token. `SiteSurvey::boot()`'s `creating` hook substitutes a random UUID, so the seeded value is silently replaced. The next test added to any of these that hits a `{token}` route will 404 for a reason that takes a while to find — as it just did.

### Task 2

**Files:** the 11 above.

**Action:** Apply the same repair 260816-ru4 used — create without the guarded keys, then `forceFill(['access_token' => ...])->save()`. Cover `access_token_expires_at` too wherever it is mass-assigned. Pattern reference: `tests/Feature/Worksheet/WorksheetTokenExpiryTest.php:35-59`.

**Do NOT** add `access_token` back to `SiteSurvey::$fillable` — that reopens the mass-assignment vector the security batch closed.

Where a file seeds a token but genuinely does not care about its value, it is acceptable to simply drop the key rather than force-fill it — but say which you did and why in the SUMMARY, and do not drop it anywhere the test later references that token.

**Acceptance criteria:**
- No test file mass-assigns `access_token` to `SiteSurvey` (grep-verifiable)
- Every affected suite still passes — this is a refactor, not a behaviour change
- `SiteSurvey::$fillable` unchanged
- Full run `php artisan test --filter="Survey|Worksheet|OmManual|MiniOm"` still shows **410 passed, 0 failed**

---

## Item 3 — Record the partial-scope product decision

### Decision (user, 2026-08-16)

The QA pass found the Tier 1 "NO TBC POLICY" gate is enforced for **O&M only**: `OmManualValidationException` aborts before AI/render. Worksheet has a coarse binary gate (`BuildWorksheetJob:87` — throws only if there are zero rooms or no room has any content). Site Survey has none at the render path.

Asked whether Worksheet and Survey should enforce the same blocking bar, the user's answer was: **partial scope is legitimate.**

So a worksheet covering 8 rooms with 7 empty is a *valid* document, not a defect. **No blocking gate is to be added to Worksheet or Site Survey.**

### Task 3

**File:** create `.planning/notes/tier1-gating-decision.md` (or append to an existing conventions/decisions note if the project has one — check `.planning/notes/` first).

**Action:** Record the decision, its date, the asymmetry it explains, and — importantly — the *reason it is not a bug*. Note that `SiteSurveyTierOneReadinessService` already computes per-room readiness and is deliberately **advisory, not blocking**. State plainly that a future contributor should not "fix" the missing gates.

**Acceptance criteria:** a future reader can tell that the O&M-only gate is a decision, not an oversight.

---

## Constraints

- **`tests/` and `.planning/` only.** No production code. No migration. No new packages.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file.
- Nothing to deploy — the SUMMARY's "🚨 Files to upload to live" section should say **none; test/docs-only change**.
- Do not attempt to repair project 21CQ30698's stored data — that is a separate user-side data fix.

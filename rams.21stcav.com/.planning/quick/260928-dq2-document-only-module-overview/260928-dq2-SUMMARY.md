---
phase: quick
plan: 260928-dq2
type: defect
subsystem: cockpit
status: complete
commit: e95349ef
---

# Quick Task 260928-dq2 Summary

**A document-only module's Overview measured visits, so RAMS said "nothing
recorded" forever. It now reports what the module holds, offers Regenerate and
Edit once something exists, reaches the finished Word and PDF, and a manual-form
RAMS finally reaches `completed`.**

## What was wrong

`resources/views/components/cockpit/panel.blade.php` put its empty sentence in
the `@else` of `@if ($visits->isNotEmpty())`. That measures VISITS AND ONLY
VISITS. `CockpitModulePresenter::MODULE_MAP` gives `rams` and `om`
`'visit_types' => []` — and `VisitLinkIssuer`'s docblock (`:21-27`) says why
that is not arbitrary: the site survey and the worksheet are the only two
modules with an engineer link, so they are the only two that can hold a visit.

So the sentence was PERMANENT on two of the four rows. It was not a glitch.
Nothing had ever rendered a document-only module with documents in it, which is
why 489 green cockpit tests missed it.

A second false sentence was found while fixing the first: a WORKSHEET with a
worksheet on file and no visit booked also said "nothing recorded". Both are
fixed by the same rule.

## What changed

| File | Change |
|---|---|
| `app/Support/Cockpit/CockpitModulePresenter.php` | `modules()` derives `has_visits` from the map's own `visit_types`. |
| `app/Support/Cockpit/CockpitPanelPresenter.php` | `files()` rows gain `action` (the module's own verb) and `formats` (Word/PDF, read from `CockpitDocumentFormPresenter`'s `formats` map, `Route::has`-guarded). Both additive. |
| `resources/views/components/cockpit/document-row.blade.php` | NEW. Overview's document row: name, date, status, the mapped action, and the finished artefact. Reuses `.cav-file` classes. |
| `resources/views/components/cockpit/panel.blade.php` | Overview gains a Documents card; the visits card is gated on `has_visits`; the hint measures both kinds. |
| `resources/views/components/cockpit/doc-form.blade.php` | `documents` prop; the closed control's verb flips `Create document` → `Regenerate`. |
| `app/Jobs/BuildRamsDocumentJob.php` | One terminal status, both paths. |

## The four answers

**1. "Nothing recorded" now appears only when the module is genuinely empty.**
The hint is `! $reportsVisits && $documents->isEmpty()`, for all four modules.

**2. `rams` is never special-cased.** The discriminator is
`$module['has_visits']`, derived in the presenter from `visit_types`. Two guard
tests strip `{{-- --}}` and `//` comments first — this repo has had seven
near-misses where a guard matched a comment — and then assert that neither
`panel.blade.php` nor `document-row.blade.php` contains `'rams'`,
`'om'`, `'site_survey'` or `'worksheet'` in EXECUTABLE text. The verb is data
too: `document-row` is asserted not to contain the literal `Edit<`.

**3. O&M is fixed by the same branch**, and `test_the_om_manual_is_reported_on_overview_by_the_same_change()`
asserts it rather than assuming it.

**4. The status bug.** `BuildRamsDocumentJob` now reads:

```php
$record->update(['status' => RamsDocument::STATUS_COMPLETED]);
```

replacing the `$isManualFormGeneration ? STATUS_FOR_REVIEW : STATUS_COMPLETED`
ternary. The completion notification twelve lines below is gated on
`STATUS_COMPLETED`, so it had never fired for that path — that consequence is
pinned:

```php
\Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\RamsReadyMail::class);
```

`assertQueued`, not `assertSent`: `RamsReadyMail implements ShouldQueue`.
Measured — `assertSent` was red first.

## Fence

**`Download` was NOT lifted.** Nothing here needs the word: the format anchors
read `Word` and `PDF`, and the row's first anchor reads `Edit` or `View`. Every
new string was substring-checked against all 21 `DEFERRED_AFFORDANCES` keys and
both `FORBIDDEN_MARKUP` entries; `Edit` does not contain Phase 49's
`Edit details`. Counts unchanged: **2 / 21 / 9 / 13**. Alpine pins unchanged:
` x-data` 1, ` x-show` 1, ` x-model` 2, ` x-cloak` 1, `{!!` 1 (all green in
`tests/Feature/Worksheets`).

## The compliance path behind the PDF anchor

`RamsController::downloadPdf()` already catches `RamsGenerationException`
(GATE-06/07/09) at `:875-882` and returns `back()->with('error', ...)`. From the
panel that lands on the cockpit URL the PM came from, where
`layouts/app.blade.php:1871` prints the flash banner. Asserted by
`test_the_pdf_link_redirects_with_a_message_rather_than_failing()`: 302 back,
session `error` present and non-empty. Never a raw 500.

`patchRamsForDisplay()` is unaffected: the panel renders a document's NAME, DATE
and STATUS and no RAMS CONTENT. The two call sites that render content —
`review()` `:311` and `downloadPdf()` `:867` — still call it FIRST and are
untouched.

## What was measured

`CockpitDocumentOverviewTest` — 17 tests, 164 assertions. The matrix is
**four modules × four document states (none / one / several / superseded) = 16
renders**, and the size is asserted:

```php
$this->assertCount(16, $rendered, 'The matrix is four modules times four document states. ...');
```

plus `assertCount(2, $withVisits)` and `assertCount(2, ...)` so it cannot become
four copies of the same kind of module. The module list is read from
`CockpitModulePresenter::moduleMap()`, so a fifth row joins the matrix the day
it is added.

## Gates (one suite per invocation, foreground, redirected to file)

| Suite | Result |
|---|---|
| `tests/Feature/Cockpit` | `Tests: 392 passed (7856 assertions)` |
| `tests/Unit/Cockpit` | `Tests: 114 passed (1203 assertions)` |
| `tests/Feature/Worksheets` | `Tests: 183 passed (1687 assertions)` |
| `tests/Feature/Documents` | `Tests: 18 passed (142 assertions)` |
| `-Filter Rams` | `Tests: 2 deprecated, 910 passed (3709 assertions)` |
| `-Baseline` | `Tests: 2 skipped, 159 passed (396 assertions)` — `>= 159 AND 0 failed` |
| `-Hashes` | all three match `4abd2b24` |

Cockpit entering was 375 + 114 = 489/0, measured before any edit. 392 + 114 =
506 = 489 + the 17 new tests.

`--group snapshot` NOT run, and deliberately: no renderer was touched. The
change set is the cockpit drawer, two cockpit presenters and one status line in
a job — no PDF/DOCX Blade, no builder, no theme.

`npm run build` NOT needed: `resources/css/cockpit.css` (a Vite entry) is
untouched, because the new row reuses `.cav-file`'s existing classes.

## Three re-expectations, by name

1. `ReviewWorkflowTest::test_generation_job_supports_manual_form_source_without_reviewed_data()` — `FOR_REVIEW` → `COMPLETED`. What the test is for (manual source reaches `buildFromForm`, never `buildFromReview`) is unchanged.
2. `ManualRamsCreationTest::test_rams_status_is_for_review_after_successful_generation()` → renamed `..._is_completed_...`; the AI-fallback test's status assertion re-expected the same way.
3. `CockpitInlineDrawerEndToEndTest::test_the_generate_action_discloses_the_form_and_names_its_outputs()` — the closed-control copy is now DERIVED from the same collection the Blade reads (that fixture's project genuinely holds a worksheet). D-05 is not weakened: a new assertion pins that the closed control still never reads `Generate document`.

## Not done, and why

- No `rams.show` route was added. Edit is `rams.review`, which already IS the edit form.
- `RamsController::regenerate` is not wired to the cockpit: it is a RAMS-only POST, and the cockpit's own `documents.store` already produces a new document, document-agnostically.
- `ProjectPackageRamsReviewService` untouched (known separate three-band follow-up).
- Nothing pushed, nothing deployed.

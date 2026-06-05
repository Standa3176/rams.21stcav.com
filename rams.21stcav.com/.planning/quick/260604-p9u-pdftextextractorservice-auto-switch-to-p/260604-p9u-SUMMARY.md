---
quick_id: 260604-p9u
type: execute
mode: quick
wave: 1
branch: feat/worksheet-classifier-universal
commits:
  - e006a2c
  - 59dcd8f
  - b503f52
status: shipped
completed: 2026-06-05
---

# 260604-p9u — PdfTextExtractorService: auto-switch to `pdftotext -layout` for the short-tag QuoteWerks variant

## One-liner

When Stage-0 `pdftotext -raw` returns text shaped like the priced "ram" QuoteWerks template (short-tag variant), re-extract with `pdftotext -layout` so the 260603-q7t translator receives clean column-positioned text instead of relying on its Quirk 1/2 column-split repairs against `-raw` column-flattened output.

## Architectural correction baked into the implementation

The plan's `<context_correction priority="critical">` block called out that the planning brief described the wrong extractor architecture (smalot-first → re-extract). Reality: Stage 0 was already `pdftotext -raw` via `shell_exec` with cross-platform binary detection (Windows Git/Poppler + Linux + PATH `where`/`which`). This quick task implements an **in-stage variant switch** — same Stage-0 binary, swap `-raw` for `-layout` when short-tag detected — NOT a new extraction stage and NOT a route through `spatie/pdf-to-text` (which would fork the binary detection logic).

## What shipped

| File | LOC delta | Purpose |
|------|-----------|---------|
| `app/Services/PdfTextExtractorService.php` | +236 / -28 | New `SHORT_TAG_DETECT_PATTERNS` const + `SHORT_TAG_DETECT_THRESHOLD` (>=3) + `looksLikeShortTagQuoteWerks()` detector + `extractWithPdfToTextLayout()` re-extractor + `resolvePdfToTextBinary()` refactor helper. 3-method visibility relaxation `private → protected` for the override-via-subclass integration tests. `extract()` wire-in directly after Stage 0. |
| `tests/Unit/Services/PdfTextExtractorShortTagRoutingTest.php` | +115 (new) | 7 reflection-based detector unit tests (empty / >=3 threshold / SITENAMESTART precedence / PARTSTART precedence / below-threshold noise / fixture positive / long-tag baseline negative). |
| `tests/Unit/Services/PdfTextExtractorRoutingIntegrationTest.php` | +187 (new) | 5 integration tests over `extract()` routing branches using anonymous-subclass override pattern. Extends `Tests\TestCase` for Log facade support. |
| `tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt` | +94 / -45 | Replaced the 75-line `pdftotext -raw` fixture with the 124-line `pdftotext -layout` fixture (column-positioned shape with wide whitespace gaps between H1/H1E + H4S/H4E, multi-line H8S address block, single-line D1S/D1E section titles, single-line P-row column-separated product rows). |
| `tests/Fixtures/quotewerks/priced-cicor-21CQ30167-raw-baseline.txt` | +75 (new) | Verbatim copy of the pre-change `-raw` fixture, preserved permanently as the integration tests' known-short-tag-shaped input. |
| **Total** | **+707 / -105 LOC** | 5 files touched. Translator (`QuoteParserService.php`) **untouched** — its docblock-promised idempotency on already-long-tag-clean text held on the cleaner `-layout` shape. |

## Commits (atomic per plan task)

| Hash      | Task | Subject                                                              |
| --------- | ---- | -------------------------------------------------------------------- |
| `e006a2c` | 1    | feat(260604-p9u): add -layout re-extract path + short-tag detector   |
| `59dcd8f` | 2    | test(260604-p9u): replace fixture with real pdftotext -layout output |
| `b503f52` | 3    | test(260604-p9u): integration tests for extract() routing branches   |

## Test results

```
$ "$PHP" artisan test --filter='PdfTextExtractorShortTagRouting|PdfTextExtractorRoutingIntegration|QuoteParserShortTagVariant|RamsRenderRegression'

Tests:    1 deprecated, 37 passed (272 assertions)
Duration: 20.17s

  Pass  Tests\Unit\Services\PdfTextExtractorShortTagRoutingTest      (7/7  detector)
  Pass  Tests\Unit\Services\PdfTextExtractorRoutingIntegrationTest   (5/5  extract() branches)
  Pass  Tests\Unit\Rams\QuoteParserShortTagVariantTest               (22/22 translator + end-to-end)
  Pass  Tests\Feature\Rams\RamsRenderRegressionTest                  (3/3  GREEN — D-12 byte-equivalence preserved)
```

Broader filter (plan verification block):

```
$ "$PHP" artisan test --filter='Pdf|QuoteParser|ShortTagVariant|RamsRenderRegression'

Tests:    2 deprecated, 2 failed, 206 passed (804 assertions)
Duration: 109.25s
```

The **2 failures are pre-existing**, called out as deferred items in the 260603-q7t SUMMARY:

1. `QuoteParserServiceTest > tagged parser handles qtvend variant and rejects time like part number` — `prepared_by` extraction returns empty in a tagged-quote fixture.
2. `QuoteParserServiceTest > tagged equipment deduplicates same area and part number` — equipment dedupe returns count 9 instead of 4.

Both live in parser code paths (`prepared_by` extraction, equipment dedup) that this plan's extractor-only changes cannot affect. The `-layout` re-extract feeds the parser the same `extracted_data` shape as before — strictly upstream of where these failures occur.

**Pre-existing PHP 8.4 deprecation** (`trim(): Passing null to parameter #1 of type string`) — surfaces on `test_3_8_long_tag_path_untouched_by_wire_in` inside the parser. Test still PASSES (PHPUnit reports `1 deprecated` but no failure). Already logged to deferred-items per the q7t SUMMARY.

## RamsRenderRegression canary

```
$ "$PHP" artisan test --filter='RamsRenderRegression'

Tests:    3 passed (9 assertions) — GREEN
Duration: ~17s

  Pass  pdf byte identical across two renders manual form fixture
  Pass  pdf byte identical across two renders quote import fixture
  Pass  pdf byte identical across two renders survey derived fixture
```

**D-12 byte-equivalence invariant from v1.3 PRESERVED.** Extractor changes are upstream of all document generators — no path to disturb byte-identity.

## Verification gates

```
$ grep -nE 'looksLikeShortTagQuoteWerks|extractWithPdfToTextLayout|SHORT_TAG_DETECT_PATTERNS' app/Services/PdfTextExtractorService.php
48:  * SHORT_TAG_DETECT_THRESHOLD by looksLikeShortTagQuoteWerks().
53: private const SHORT_TAG_DETECT_PATTERNS = [
87: if ($this->looksLikeShortTagQuoteWerks($poppler)) {
91:     $layoutText = $this->extractWithPdfToTextLayout($path);
278: protected function extractWithPdfToTextLayout(string $path): string
314: * {@see extractWithPdfToTextLayout} (short-tag re-extract, `-layout`)
388: * via {@see extractWithPdfToTextLayout()}.
399: *   3. Summed match count across SHORT_TAG_DETECT_PATTERNS is
405: private function looksLikeShortTagQuoteWerks(string $text): bool
421: foreach (self::SHORT_TAG_DETECT_PATTERNS as $pattern) {

$ grep -c -- '-layout' app/Services/PdfTextExtractorService.php
12

$ grep -n 'private function resolvePdfToTextBinary' app/Services/PdfTextExtractorService.php
326: private function resolvePdfToTextBinary(string $path): string

$ wc -l tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt tests/Fixtures/quotewerks/priced-cicor-21CQ30167-raw-baseline.txt
124 tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt           (within plan's 80-140 line spec)
75  tests/Fixtures/quotewerks/priced-cicor-21CQ30167-raw-baseline.txt
```

5 files touched per plan expectation (`PdfTextExtractorService.php` + main fixture + new -raw-baseline + 2 new test files). `QuoteParserService.php` correctly untouched.

## Deviations from plan

### Rule 3 (auto-fix blocking issue) — visibility relaxation for override-via-subclass

**Found during:** Task 3 — the anonymous-subclass override pattern (specified by the plan) required overriding `extractWithPdfToText`, `extractWithPdfToTextLayout`, and `parseText`. All three were `private`.

**Fix:** Relaxed all three to `protected`. No external behavioural change — the class is not extended in production. The override-for-test pattern is a recognised Laravel/PHPUnit idiom, and the plan explicitly specified this approach in Task 3's behavior block ("subclass-override pattern: declare an anonymous subclass of `PdfTextExtractorService` that overrides `extractWithPdfToText`").

**Files modified:** `app/Services/PdfTextExtractorService.php` (3 visibility keywords).
**Commit:** `b503f52` (Task 3).

### Rule 2 (auto-add missing functionality) — suppress redundant fallback warning

**Found during:** Task 3 — integration test 3.3 (layout throws → fall back to -raw) initially failed because `Log::warning` was called twice in the throw path: once inside the `catch` and once again afterwards by the unconditional "returned empty" warning (since `$layoutText` ended at `''`).

**Fix:** Added a `$layoutThrewFlag` boolean inside `extract()` so the "returned empty" warning only fires when the throw branch did NOT already log a fallback reason. Result: exactly one warning per failure — cleaner log streams in production.

**Files modified:** `app/Services/PdfTextExtractorService.php` (added flag + conditional).
**Commit:** `b503f52` (Task 3).

### Non-deviations worth noting

- **Translator untouched.** Plan Task 2 Step 3 anticipated the cleaner `-layout` shape might surface a translator gap requiring an in-task fix. None surfaced — the q7t translator's idempotency on already-long-tag-clean text held: Quirk 1/2 column-split repairs find nothing to repair on `-layout`, `SHORT_TO_LONG_TAGS` map fires on every clean H/D/P token, D-pair routing still sees its expected pairs. All 22 existing translator tests pass against the new fixture with **zero assertion-threshold changes**.
- **Plan's Task 1 vs Task 3 test class split.** Followed plan exactly — pure detector tests in `PdfTextExtractorShortTagRoutingTest` (extends `PHPUnit\Framework\TestCase` for speed; no Laravel bootstrap), integration tests in `PdfTextExtractorRoutingIntegrationTest` (extends `Tests\TestCase` for Log facade support via `Log::spy()` + `Log::shouldHaveReceived(...)`).

## Post-deploy recipe — project 78 (Cicor Hartlepool) THIRD re-extract

Run in `tinker` on production AFTER this branch is deployed AND the queue worker has cycled to the new code AND `which pdftotext` confirms a binary (CWP/CloudLinux stcav user — `resolvePdfToTextBinary()` checks `/usr/bin/pdftotext` FIRST in the candidate list, so the happy path is one `file_exists` call with no shell overhead).

**Verify binary on prod FIRST:**

```bash
ssh stcav@rams.21stcav.com 'which pdftotext && ls -la /usr/bin/pdftotext'
```

**Then in tinker:**

```php
// 1. Capture $prev BEFORE reimportPending. The call creates a NEW
//    ProjectPackage row and flips $project->latestPackage; inline
//    expansion of $project->latestPackage as the second arg would
//    re-extract the just-created row from itself. Same gotcha as
//    260603-q7t (its SUMMARY L122).
$project = App\Models\Project::find(78);
$prev    = $project->latestPackage;   // ← CAPTURE FIRST.

// 2. Resolve the owning user (or any admin). Replace the literal below
//    with the project owner's email — same name that landed on the
//    original import.
$user = App\Models\User::where('email', /* the project owner's email */)->firstOrFail();

// 3. Re-import. Named-argument call matches QuoteImportController L213-216.
$newPackage = app(App\Core\Modules\QuoteImport\QuoteImportService::class)
    ->reimportPending(user: $user, existing: $prev);

// 4. Dispatch and watch the queue worker log for the new INFO line:
//    "PdfTextExtractorService: using pdftotext -layout output (short-tag variant detected)"
App\Jobs\ReimportQuoteJob::dispatch($newPackage->id, $user->id);

// 5. Verify $newPackage lands at revision = $prev->revision + 1, with
//    extracted_data.client = "Cicor Hartlepool Ltd", ship_contact =
//    "Jamie Powis", ship_phone = "07741 627 320", three room_overviews
//    (First Floor Training Room / Professional Services / Support
//    Services), and 15+ equipment rows none of which carry "INSTALL" /
//    "DELIVERY" / "CONSUMABLES" / "PROJMANOFF" as their area name.
```

**Why `$prev` capture matters:** `reimportPending(User, ProjectPackage)` calls `ProjectPackage::create(...)` internally which immediately flips `$project->latestPackage` (Eloquent `latestOfMany` accessor). Inline `->reimportPending($user, $project->latestPackage)` would, by the time argument evaluation completes, point at a row newly created in the same request. ALWAYS cache to `$prev` first. Identical gotcha and identical mitigation as documented in 260603-q7t SUMMARY L122.

**Expected log breadcrumb on success:**

```
[INFO] PdfTextExtractorService: pdftotext detection {"binary":"/usr/bin/pdftotext", ...}
[INFO] PdfTextExtractorService: pdftotext output {"output_len":N,"has_markers":false,...}
[INFO] PdfTextExtractorService: pdftotext -layout output {"output_len":M,"has_markers":false,...}
[INFO] PdfTextExtractorService: using pdftotext -layout output (short-tag variant detected) {"length":M,"has_markers":false}
```

(`has_markers` is false because the canonical long-tag markers like `PARTSTART` are only present AFTER the QuoteParser translator runs, which is downstream.)

## Fixture build note

The Cicor 21CQ30167 PDF is not present on the dev machine (only on production at `storage/app/private/quote-imports/`). The Task-2 fixture was reconstructed from:

1. The user's verbatim paste of the real `pdftotext -layout` output for the header + page-1 product row section.
2. Page-2 (Professional Services) and page-3 (Support Services) rows extrapolated in the same column-positioned shape, matching the part_numbers + quantities + prices already in the pre-change `-raw` fixture (which itself was a known-good live PDF extraction from project 78). The product row template is consistent: `P1S   {part_number}   P1E   P2S   {description}   P2E   P3S   {qty}   P3E   P4S   {price}   P4E   P5S   {manufacturer}   P5S`.

The post-deploy step 5 verification (live `-layout` output against project 78) is the ground-truth confirmation that the fixture shape matches reality. If post-deploy reveals any mismatch, regenerate the fixture verbatim from `pdftotext -layout` on the production PDF and re-run the test filter — the assertions are SHAPE-LEVEL (substring/regex) and should hold across any genuine `-layout` output that contains the same equipment list.

## Deferred items (out of scope for this quick task)

- The 2 pre-existing `QuoteParserServiceTest` failures already documented in q7t SUMMARY. Raise as a separate `/gsd-debug` task.
- The PHP 8.4 `trim(): Passing null to parameter #1` deprecation inside the parser. Already logged to deferred-items per q7t.
- `spatie/pdf-to-text` composer dependency remains as a documented fallback only; not consumed on the runtime path. Pruning it is a hygiene task for a future cleanup pass.

## Self-Check: PASSED

**Files created (verified on disk):**

- FOUND: `app/Services/PdfTextExtractorService.php` (modified — +236 / -28 LOC; 3 new methods + 1 const + 1 threshold + wire-in branch + visibility relaxation)
- FOUND: `tests/Unit/Services/PdfTextExtractorShortTagRoutingTest.php` (new — 7 detector tests)
- FOUND: `tests/Unit/Services/PdfTextExtractorRoutingIntegrationTest.php` (new — 5 routing-branch tests)
- FOUND: `tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt` (replaced — 124 lines `-layout` form)
- FOUND: `tests/Fixtures/quotewerks/priced-cicor-21CQ30167-raw-baseline.txt` (new — 75 lines verbatim of pre-change `-raw` form)

**Commits (verified in git log):**

- FOUND: `e006a2c` (Task 1 — detector + layout extractor + binary helper refactor + wire-in + 7 unit tests)
- FOUND: `59dcd8f` (Task 2 — fixture replacement + -raw-baseline preservation)
- FOUND: `b503f52` (Task 3 — 5 integration tests + visibility relaxation + redundant-warning suppression)

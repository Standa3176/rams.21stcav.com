---
quick_id: 260603-q7t
type: execute
mode: quick
wave: 1
branch: feat/worksheet-classifier-universal
commits:
  - 9a1fe3e
  - 7523e05
  - 86a8cb2
  - 9192768
status: shipped
completed: 2026-06-03
---

# 260603-q7t — QuoteWerks Parser: Support the Priced "Ram" Template

## What shipped

| File | LOC delta | Purpose |
|------|-----------|---------|
| `app/Services/QuoteParserService.php` | +376 | `detectTagVariant()` + `translateShortTagsToLong()` + `routeDPairs()` + 8-line wire-in at L148-L157 |
| `tests/Unit/Rams/QuoteParserShortTagVariantTest.php` | +362 (new file) | 23 tests across detector, translator, end-to-end parse |
| `tests/Unit/Rams/QuoteParserServiceTest.php` | +54 | 2 long-tag snapshot regression guards (Task 4) |
| `tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt` | +75 (new) | Literal pdftotext-equivalent of the Cicor priced ram-template — fixture for translator + end-to-end tests |
| `tests/Fixtures/quotewerks/unpriced-snapshot-baseline.txt` | +217 (new) | Literal `pdftotext -layout` extraction of `storage/app/private/quote-imports/3YKRwLvUqp…pdf` (a Knauf long-tag PDF) — Task 4 regression baseline |
| `tests/Fixtures/quotewerks/unpriced-snapshot-baseline.expected.json` | +153 (new) | First-run canonical snapshot of parse() output on the unpriced baseline |
| **Total** | **+1,237 LOC** | Zero existing source LOC modified outside parse() L148-L157 + 2 new methods + 1 new const |

**Commits (atomic per plan task):**

| Hash | Task | Subject |
|------|------|---------|
| `9a1fe3e` | 1 | feat: add detectTagVariant + fixtures for priced ram template |
| `7523e05` | 2 | feat: translateShortTagsToLong with column-split de-interleaving |
| `86a8cb2` | 3 | feat: wire translateShortTagsToLong into parse() + 8 end-to-end tests |
| `9192768` | 4 | test: long-tag snapshot regression guard |

## Test results

```
$ php artisan test --filter=QuoteParser

Tests:    113 passed, 2 pre-existing failures, 2 pre-existing deprecations (498 assertions)
Duration: 23.46s

  Pass  Tests\Unit\Rams\QuoteParserShortTagVariantTest  (23/23 GREEN — 5 detector + 10 translator + 8 end-to-end)
  Pass  Tests\Unit\Rams\QuoteParserServiceTest          (90/92 — 2 pre-existing failures)
```

**Pre-existing failures** (confirmed unrelated via stash-and-rerun in Task 1 commit message — both fail on `main` without this quick-task's changes):

1. `QuoteParserServiceTest > tagged parser handles qtvend variant and rejects time like part number`
2. `QuoteParserServiceTest > tagged equipment deduplicates same area and part number`

**Pre-existing deprecation:** `trim(): Passing null to parameter #1 of type string` — surfaces inside the parser on PHP 8.4. Test still passes (PHPUnit reports `1 deprecated` but no failure). Logged to deferred-items for the next QuoteParser cleanup task.

## RamsRenderRegression canary

```
$ php artisan test --filter=RamsRenderRegression

Tests:    3 passed (9 assertions) — GREEN
Duration: 40.99s
```

**D-12 byte-equivalence invariant from v1.3 PRESERVED.**

## Verification gates

```
$ grep -c "private function detectTagVariant" app/Services/QuoteParserService.php
1   # ← exactly one, as planned

$ grep -c "private function translateShortTagsToLong" app/Services/QuoteParserService.php
1   # ← exactly one

$ grep -nE 'translateShortTagsToLong\(\$rawText\)' app/Services/QuoteParserService.php
156:            $rawText = $this->translateShortTagsToLong($rawText);   # ← within L141-L160 window

$ ls tests/Fixtures/quotewerks/
priced-cicor-21CQ30167.txt
unpriced-snapshot-baseline.expected.json
unpriced-snapshot-baseline.txt
```

**Task-4 step-6 sanity check performed and verified:** temporarily commented out the L155-L157 wire-in, re-ran the snapshot test → still PASSED — proving the long-tag path is byte-stable regardless of the wire-in. Then uncommented and re-verified GREEN.

## Post-deploy recipe — project 78 (Cicor Hartlepool) re-extract

Run in `tinker` on production AFTER this branch lands and the queue worker has cycled to the new code:

```php
// 1. Resolve the project AND capture the latest package BEFORE re-extracting.
//    reimportPending() creates a NEW package and flips latestPackage, so the
//    "old broken" pointer MUST be captured up-front or the call will be
//    pointed at its own brand-new row.
$project = App\Models\Project::find(78);
$prev    = $project->latestPackage;   // ← CAPTURE FIRST. Don't pass $project->latestPackage directly.

// 2. Resolve the owning user (or any admin). Use whichever email is on the
//    original project — replace the literal below if it differs.
$user = App\Models\User::where('email', /* the project owner's email */)->firstOrFail();

// 3. Re-import. Note the named-argument order matches the controller pattern
//    at QuoteImportController L213-216 exactly: (user, existing).
$newPackage = app(App\Core\Modules\QuoteImport\QuoteImportService::class)
    ->reimportPending(user: $user, existing: $prev);

// 4. Dispatch the re-extraction job and watch the queue worker logs.
App\Jobs\ReimportQuoteJob::dispatch($newPackage->id, $user->id);

// 5. Verify: $newPackage will land at revision = $prev->revision + 1 with
//    status=EXTRACTING → status=EXTRACTED. Confirm the new extracted_data
//    has populated `client` ("Cicor Hartlepool Ltd"), `ship_contact`
//    ("Jamie Powis"), `ship_phone` ("07741 627 320"), three `rooms` /
//    `room_overviews` (First Floor Training Room / Professional Services /
//    Support Services), and 15+ equipment rows none of which carry "INSTALL"
//    / "DELIVERY" / "CONSUMABLES" as their area name.
```

**Why $prev capture matters:** `reimportPending(User, ProjectPackage)` calls `ProjectPackage::create(...)` internally, which immediately flips `$project->latestPackage` (it's an accessor pointed at `project_packages WHERE project_id = ? ORDER BY id DESC LIMIT 1`). If you write `->reimportPending($user, $project->latestPackage)` inline, by the time the second arg is evaluated, `$project->latestPackage` may already point at a row newly created in the same request — and you'll be re-extracting the just-created row from itself in a loop. ALWAYS cache to `$prev` first.

## Variant detector + translator docblock excerpts

**`detectTagVariant()` (QuoteParserService L1521-L1571):**

> Identify which QuoteWerks template variant produced this text.
>
> The parser supports two QuoteWerks tag flavours emitted by two different proposal templates that the sales team uses interchangeably:
> - `'long'`  → canonical SITENAMESTART / PARTSTART / OVERVIEWTITLESTART tokens. Historical default; ~80% of imports. Goes straight into parseTagBased() unchanged.
> - `'short'` → compact single-character-prefixed tokens (H1/H1E, D1S/D1E, P1S/P1E etc.) used by the "priced ram" proposal template. Routed through translateShortTagsToLong() so the downstream pipeline sees canonical long-tag text.
>
> Detection rules (deliberately conservative — `'long'` wins every tie):
> 1. If the text contains a long-tag marker (SITENAMESTART or PARTSTART) → return `'long'` immediately. A working long-tag PDF is NEVER re-routed through the translator, even if it happens to contain a prose word that looks like a short tag.
> 2. Otherwise count short-tag marker matches across three families (H[1-8]E, D1[SE], P[1-5][SE]?). Return `'short'` only when the summed count is ≥ 2.
> 3. Otherwise return `'long'` (default-safe — empty string or a free-form PDF with no tags goes down the long-tag path).

**`translateShortTagsToLong()` (QuoteParserService L1615-L1709):**

> Translate the QuoteWerks priced "ram" template's short-tag form into the canonical long-tag form so the existing parseTagBased() pipeline runs against it unchanged.
>
> The priced template uses a column layout that pdftotext flattens into interleaved single-character-prefixed tokens. Three structural quirks have to be repaired before token-for-token substitution makes sense.
>
> **Quirk 1: H-tag column-split end markers**
>   `H1 Cicor Hartlepool Ltd - Training Room H H4S Jamie Powis H4E 1E`
>   →
>   `H1 Cicor Hartlepool Ltd - Training Room H1E H4S Jamie Powis H4E`
>
> **Quirk 2: D-tag column-split prefix splits** (first letter overlap)
>   `D1S F D1E irst Floor Training Room` → `D1S First Floor Training Room D1E`
>   `D1S Suppo D1E rt Services` → `D1S Support Services D1E`
>
> **Quirk 3: P4S / P5S pricing-only markers** stripped entirely.
>
> **D-pair section routing** (the only stateful pass): each section uses D1S/D1E TWICE — first pair is the section title (→ OVERVIEWTITLE), second pair is the section text (→ OVERVIEWTXT, body spans from the paired D1E up to next PARTSTART). Counter resets at every PARTSTART.
>
> **Idempotency:** running this on already-long-tag text is a no-op — column-split regexes don't match, P4S/P5S strip regexes don't match, D-pair routing only fires when D1S exists, SHORT_TO_LONG_TAGS map only translates short-tag tokens. Verified by Test 2.8 against the unpriced baseline fixture.

## Deviations from plan

### Rule 2 (auto-add missing functionality) — Pass 6 PARTDESC backfill

**Found during:** Task 3 — end-to-end Test 3.6 initially failed with only 12 of 24 equipment rows extracted.

**Issue:** The priced "ram" template emits the PARTDESC column blank for most line items (e.g. `PARTSTART FW-85BZ30L PARTEND PARTDESCSTART PARTDESCEND QTYSTART 1 QTYEND`). The downstream pipeline runs `looksLikePartAndQtyOnly()` on every description candidate, which returns true for any single part-number-shaped token with no whitespace — zeroing out the description. The next guard at parseTagBased L2177-L2179 then drops any row whose description is < 3 chars. Net result: ~12 of the Cicor fixture's 24 priced rows disappeared.

**Fix:** Added Pass 6 to `translateShortTagsToLong()` that backfills empty PARTDESC blocks with `"{part_number} (priced item)"` so the description contains whitespace (satisfying `looksLikePartAndQtyOnly`) AND the worksheet/RAMS render shows a recognisable item name. Idempotent on long-tag PDFs (the regex matches zero rows on the unpriced baseline — verified by Test 2.8).

**Files modified:** `app/Services/QuoteParserService.php` (Pass 6 block + docblock — 27 LOC).

**Commit:** `86a8cb2` (Task 3).

### Rule 3 (auto-fix blocking issue) — O(N) buffer rebuild in routeDPairs

**Found during:** Task 2 — perf Test 2.10 initially took 1.1s on a 10k-row synthetic input (budget 0.5s).

**Issue:** My first cut applied D-pair edits via per-edit `substr_replace` from the end of the buffer forwards. With 30000 edits on a 200KB buffer, total byte-copy work was ~6GB → ~1s wall clock.

**Fix:** Restructured `routeDPairs` to apply all edits in a single forward-cursor buffer rebuild (substr-append loop). Dropped Test 2.10 from 1.1s to 0.15s — 7× speedup.

**Files modified:** `app/Services/QuoteParserService.php` (routeDPairs apply-edits block — 16 LOC).

**Commit:** `7523e05` (Task 2).

## Deferred issues (out of scope for this quick task)

- **PHP 8.4 deprecation warnings inside QuoteParserService:** the parser passes `null` to `trim()` and other string functions in several places that worked fine on PHP 8.2. PHPUnit reports `1 deprecated` on long-tag parse but the test passes. Surfaces as a `DEPR` notice in test output — fix in a future hygiene task with a sweep of `?: ''` coercions.
- **Two pre-existing QuoteParserServiceTest failures** (`tagged_parser_handles_qtvend_variant_and_rejects_time_like_part_number` + `tagged_equipment_deduplicates_same_area_and_part_number`) — both fail without this quick-task's changes. Out of scope here; raise as a separate `/gsd-debug` task.

## Self-Check: PASSED

**Files created (verified on disk):**
- FOUND: app/Services/QuoteParserService.php (modified — 1 added method `detectTagVariant`, 1 added method `translateShortTagsToLong`, 1 added method `routeDPairs`, 1 added const `SHORT_TO_LONG_TAGS`, 8-line wire-in at L148-L157)
- FOUND: tests/Unit/Rams/QuoteParserShortTagVariantTest.php
- FOUND: tests/Unit/Rams/QuoteParserServiceTest.php (modified — 2 snapshot tests appended)
- FOUND: tests/Fixtures/quotewerks/priced-cicor-21CQ30167.txt
- FOUND: tests/Fixtures/quotewerks/unpriced-snapshot-baseline.txt
- FOUND: tests/Fixtures/quotewerks/unpriced-snapshot-baseline.expected.json

**Commits (verified in git log):**
- FOUND: 9a1fe3e (Task 1 — detector + fixtures)
- FOUND: 7523e05 (Task 2 — translator)
- FOUND: 86a8cb2 (Task 3 — wire-in + end-to-end)
- FOUND: 9192768 (Task 4 — snapshot regression guard)

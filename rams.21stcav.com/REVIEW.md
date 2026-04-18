# Quote Import Pipeline — Code Review

**Date:** 2026-04-11
**Depth:** Standard (full file read + cross-file analysis)
**Files Reviewed:**
- `app/Services/QuoteParserService2903.php` (the "to-be-restored" parser)
- `app/Services/QuoteParserService.php` (the currently active parser)
- `app/Core/Modules/QuoteImport/QuoteImportService.php`
- `app/Http/Controllers/QuoteImportController.php`
- `app/Services/PdfTextExtractorService.php`

---

## CRITICAL

### C-01: Active parser (`QuoteParserService.php`) is NOT the file being reviewed — the 2903 version is dead code

**Files:** `app/Services/QuoteParserService2903.php` vs `app/Services/QuoteParserService.php`

`QuoteImportService` imports `App\Services\QuoteParserService` (line 17), which resolves to `QuoteParserService.php` (2,938 lines). The file `QuoteParserService2903.php` (2,462 lines) has the same namespace `App\Services` and class name `QuoteParserService` — this is a **duplicate class declaration in the same namespace**. PHP will either throw a fatal `Cannot declare class ... because the name is already in use` error at autoload time, or (if Composer's classmap doesn't include the 2903 file) the 2903 version is simply never loaded and has zero effect on the running system.

The review brief says "the original high-quality parser being restored" — if that restoration has not yet happened (i.e., the file hasn't replaced `QuoteParserService.php`), the entire 2903 analysis is describing code that is not running.

**Action required:** Determine which file is authoritative and eliminate the other. If 2903 is the intended replacement, overwrite `QuoteParserService.php` with it and delete the 2903 backup file. Running both is a class-collision time-bomb.

---

### C-02: Active parser heuristic path omits `site_name` key; merge logic silently reads `site_name` from it

**Files:** `app/Services/QuoteParserService.php` line ~178, `app/Core/Modules/QuoteImport/QuoteImportService.php` lines 427–429

The active parser's heuristic path (non-tagged PDFs) returns:
```php
'site_name' => '',   // always blank from heuristic path
```

`mergeParsedQuoteData()` in `QuoteImportService` reads:
```php
if (($ai['site_name'] ?? '') === '' && ($parsed['site_name'] ?? '') !== '') {
    $ai['site_name'] = (string) $parsed['site_name'];
}
```

The `2903` backup file's heuristic path does **not** include `site_name` in its return array at all — so `$parsed['site_name']` will always be null/absent. The merge's `site_name` fill-in branch is therefore permanently dead for non-tagged PDFs regardless of which version is active.

The tag-based path in the 2903 version also does not return `site_name`. Only the active `QuoteParserService.php` outputs this key (as an empty string). This means the `site_name` merge branch in `QuoteImportService::mergeParsedQuoteData()` can never populate from the parser for any PDF type.

**Impact:** `$ai['site_name']` will be whatever the AI returns (may be blank). The derived `project_name` logic at lines 449–460 depends on `site_name` being non-empty to construct a meaningful project name fallback — this silently fails.

---

## HIGH

### H-01: `QuoteParserService2903` has regressed part-number detection vs. the active `QuoteParserService.php`

**File:** `app/Services/QuoteParserService2903.php` — multiple part-number extraction locations

The active `QuoteParserService.php` has significantly improved part-number recognition:

| Feature | Active (QuoteParserService.php) | 2903 backup |
|---|---|---|
| Digit-first part numbers (e.g. `3000c`, `993-001903`) | Supported via `preg_match('/^\d/', $pn)` branch | Not supported — requires alpha-first token |
| Forward-slash in part numbers (e.g. `QSC-K8/2`) | Accepted (`str_contains($pn, '/')`) | Rejected |
| `isSolePartNumber()` — digit-first tokens | Accepted | Rejected (regex requires `[A-Za-z]` first char) |
| Single alpha char minimum for part matching | `[a-zA-Z]` (1+) | `[a-zA-Z]{2,}` (2+) |
| `PREPARED_BY_PATTERNS` word count | 1–4 words (`{0,3}`) | 2–3 words (`{1,2}`) |
| Quote ref suffix suffixes (e.g. `21CQ28849-04-OPS`) | Supported (`(?:-[A-Z0-9]{1,10})*`) | Not supported |

If 2903 is restored over the active file, all these improvements are lost and part-number extraction will regress for digit-first part numbers and slash-delimited refs.

**Action required:** Before restoring 2903, diff the two files carefully and cherry-pick the improvements back into the 2903 version rather than replacing wholesale. The diff is already captured above.

---

### H-02: `hasStructuredTags()` uses case-sensitive `str_contains` in 2903 vs. case-insensitive `stripos` in the active file

**Files:** `QuoteParserService2903.php` line 1469 vs. `QuoteParserService.php` line 1483

The 2903 version:
```php
return str_contains($rawText, 'PARTSTART')
    && str_contains($rawText, 'PARTDESCSTART')
    && str_contains($rawText, 'QTYSTART');
```

The active version:
```php
return stripos($rawText, 'PARTSTART') !== false
    && stripos($rawText, 'PARTDESCSTART') !== false
    && stripos($rawText, 'QTYSTART') !== false;
```

If a QuoteWerks PDF produces lowercase tag tokens (e.g. from OCR or a differently configured template), the 2903 version will miss the structured path and fall back to the heuristic parser, producing much lower-quality output. The active file's `stripos` is strictly more robust.

---

### H-03: `confirm()` in the controller creates a new project without setting `works_description` when no project exists

**File:** `app/Http/Controllers/QuoteImportController.php` lines 127–139

When `$package->project_id === null`, the controller creates a project via `$service->create()` with `works_description` from `$validated`. It then sets `$overrides = []` to prevent a double-update. However, the `ProjectService::create()` call at line 129 only receives `works_description` if it is present in `$validated`. Since the form field is `nullable`, an empty submission will create the project with `works_description = null`, which is correct — but the `$overrides` nullification means any subsequent `service->confirm()` call cannot propagate user corrections to the project. This is intentional per the comment, but it means if the create path fires, the `confirm()` service call at line 142 receives an empty overrides array and only marks the package as reviewed.

**Risk:** If `ProjectService::create()` throws (e.g. validation, DB constraint), the exception propagates uncaught as a 500 because the controller's `try/catch` only wraps `$this->service->import()` (lines 47–63), not the `confirm()` flow. A DB failure during project creation in `confirm()` would be an unhandled 500.

**Fix:** Wrap the project creation and `service->confirm()` call in the `confirm()` action with a `try/catch` that returns a user-facing redirect error.

---

### H-04: `reimport()` on a project-linked package does not verify the project still exists before calling `$existing->project`

**File:** `app/Core/Modules/QuoteImport/QuoteImportService.php` lines 206–215

```php
if ($existing->project_id) {
    $project = $existing->project;   // could be null if project was soft-deleted
    $this->projectService->log(
        project: $project,           // null passed here if project deleted
        ...
    );
}
```

If the linked project has been soft-deleted, `$existing->project` returns `null` (Eloquent eager-load respects `whereNull('deleted_at')`). `$this->projectService->log()` will then receive `null` for `$project` and either throw a type error or silently skip, depending on the log method signature. This is not wrapped in a try/catch.

**Fix:**
```php
$project = $existing->project;
if ($existing->project_id && $project !== null) {
    $this->projectService->log(...);
}
```

---

## MEDIUM

### M-01: `mergeParsedQuoteData()` field mapping is asymmetric — `quote_reference` field documented in brief is never produced

**File:** `app/Core/Modules/QuoteImport/QuoteImportService.php` — `mergeParsedQuoteData()`

The review brief asks whether the parser returns `quote_reference`. Neither parser returns this key. The merge produces `qw_number` (from parser's `ref`) for the quote reference. Downstream consumers that read `$extracted['quote_reference']` will get `null`. This is a naming contract issue — the field exists under a different key (`qw_number`), not `quote_reference`.

**Impact:** Any template, view, or downstream service that expects `quote_reference` from `extracted_data` will silently get null. Search the views and any service consuming `extracted_data` for `quote_reference` to confirm exposure.

---

### M-02: Raw stream fallback reads the entire PDF file into memory with `file_get_contents`

**File:** `app/Services/PdfTextExtractorService.php` line 147

```php
$raw = (string) file_get_contents($path);
```

This loads the full PDF binary into a PHP string. For a large multi-page QuoteWerks quote (common for 50+ line items), a PDF can easily be 5–20 MB. PHP string processing of 20 MB through `extractPdfLiteralStrings()` — which walks every byte in a while loop — will be slow and memory-intensive. While not a correctness bug, it can cause PHP memory limit errors in restricted environments.

**Fix:** Add a file size guard before triggering the raw-stream path:
```php
if (filesize($path) > 10 * 1024 * 1024) {
    // Skip raw stream fallback for very large PDFs
    $raw = '';
} else {
    $raw = $this->cleanText($this->rawStreamText($path));
}
```

---

### M-03: `$lines` variable potentially undefined in tag-based fallback path of active parser

**File:** `app/Services/QuoteParserService.php` (active) — `parseTagBased()`, client fallback

```php
if ($client === '') {
    $lines  = $this->toLines($rawText);
    $client = $this->extractClient($rawText, $lines);
}
if ($site === '') {
    $lines = isset($lines) ? $lines : $this->toLines($rawText);  // defensive isset
    $site  = $this->extractSite($rawText, $lines);
}
```

This pattern is correctly defensive in the active file (uses `isset`). However, in `QuoteParserService2903.php` the identical block at lines 1559–1562 also uses `isset($lines)`. Both are safe, but relying on `isset` to guard an `$lines` variable that may or may not have been set earlier in the same method is fragile. If the tag-based method is refactored, this guard is easy to miss.

**Fix (minor):** Initialize `$lines = null` at the top of `parseTagBased()` to make the intent explicit.

---

### M-04: Auto-project matching in `import()` uses case-folded SQL but `importFromData()` does not

**File:** `app/Core/Modules/QuoteImport\QuoteImportService.php` lines 93–97 vs. 243–246

`import()` uses `whereRaw('LOWER(client_name) = ?', ...)` for case-insensitive matching.
`importFromData()` uses `where('client_name', $clientName)` — case-sensitive.

The same client with slightly different casing (e.g. "ACME Ltd" vs "Acme Ltd") will match in the PDF import flow but create a duplicate project in the array-import flow.

---

### M-05: `confirm()` controller action silently ignores a non-owned `project_id`

**File:** `app/Http/Controllers/QuoteImportController.php` lines 103–106

```php
$project = Project::where('id', $validated['project_id'])
    ->where('user_id', auth()->id())
    ->firstOrFail();
```

This correctly scopes to the authenticated user. However, if a user submits a `project_id` that exists but belongs to another user, `firstOrFail()` throws a 404 ModelNotFoundException, which Laravel renders as a 404 page. This is acceptable but could be more explicit — a 403 Forbidden would be more semantically correct for an authorization failure, since the project exists; the user just doesn't own it.

---

## LOW

### L-01: `QuoteParserService2903.php` is a legacy backup file that should not exist in source control

The naming convention in `CLAUDE.md` explicitly flags numeric-date suffixes (`PdfService2403-1807.php`) as "NOT recommended for new code" and describes them as "legacy/backup files." Having `QuoteParserService2903.php` in `app/Services/` with the same class name as `QuoteParserService.php` is a maintenance hazard — see C-01.

---

### L-02: Debug `Log::debug()` calls are hardcoded in `parse()` and `parseTagBased()`

**File:** `app/Services/QuoteParserService2903.php` lines 140–147, 1724–1731

Two `Log::debug()` calls log `first_300` (300 chars of raw PDF text) and `raw_sample` (500 chars) on every import. These will dump potentially sensitive quote content (client names, addresses, pricing) into the application log in production. Debug logging should be conditional on an env flag or removed before the file replaces the active parser.

---

### L-03: `extractPdfLiteralStrings()` does not handle multi-byte / high-byte escape sequences

**File:** `app/Services/PdfTextExtractorService.php` lines 201–225

The octal escape handler accumulates up to 3 octal digits correctly. However, when the resulting `chr(octdec($oct))` produces a value > 127 (high-byte character), it is then immediately stripped by the downstream regex `preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s)` in `rawStreamText()`. This means non-ASCII characters in PDF literal strings (accented characters in company names like "Müller GmbH") are always stripped during raw stream extraction. This is a data-quality limitation but does not cause crashes.

---

### L-04: `$overviewRange[0]` and `$overviewRange[1]` are used without checking range length in `extractEquipment()`

**File:** `app/Services/QuoteParserService2903.php` lines 573–574

```php
$overStart = $overviewRange[0] ?? PHP_INT_MAX;
$overEnd   = $overviewRange[1] ?? PHP_INT_MAX;
```

`$overviewRange` can be null (handled by `?? PHP_INT_MAX`) or a 2- or 3-element array (the third element is optional inline text). The null-coalescing on array subscripts is safe in PHP — if `$overviewRange` is null, `$overviewRange[0]` evaluates to null before the `??` fires. This is not a bug but is subtly non-obvious. Consider `$overStart = ($overviewRange !== null) ? $overviewRange[0] : PHP_INT_MAX;` for clarity.

---

## Summary

| Severity | Count | Key Issues |
|---|---|---|
| CRITICAL | 2 | Duplicate class file; `site_name` key mismatch breaks project name derivation |
| HIGH | 4 | Part-number regression if 2903 restored; case-sensitive tag detection; unhandled project deletion in reimport; unguarded 500 in confirm |
| MEDIUM | 5 | `quote_reference` key absent; memory risk in raw-stream fallback; defensive isset; case-folding inconsistency; 404 vs 403 on project ownership |
| LOW | 4 | Backup file in source; debug logging of raw text; high-byte strip; non-obvious null-coalesce |

**Most urgent action:** Resolve C-01 first — determine which parser file is the intended active version and eliminate the duplicate. Everything else is secondary to that, because half this review analyses code that may not be running.

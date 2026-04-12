---
quick_id: 260411-g5w
title: Wire QuoteExtractorService back as primary PDF extraction path
type: quick
autonomous: true
files_modified:
  - app/Services/QuoteExtractorService.php
  - app/Core/Modules/QuoteImport/QuoteImportService.php
---

<objective>
Restore Claude PDF document-vision extraction as the primary extraction path for quote
imports. QuoteExtractorService exists and works but is wired to nothing.
QuoteImportService currently calls the local PHP parser only, producing poor results.

Two changes wire them together:
1. Add `extractFromPath(string $absolutePath): array` to QuoteExtractorService so it can
   be called without an UploadedFile (storage-path-first callers).
2. Replace QuoteImportService's private `extract()` body to delegate to
   QuoteExtractorService and remove the now-unused PdfTextExtractorService /
   QuoteParserService from its constructor.
</objective>

<tasks>

## Task 1 — Refactor QuoteExtractorService: add extractFromPath(), extract callClaude()

**File:** `app/Services/QuoteExtractorService.php`

**Changes:**

1. Extract the HTTP/JSON call and response parsing from `extract()` into a new private
   method `callClaude(string $pdfBase64): array`. It should contain:
   - The `Http::withHeaders(...)` call
   - The `$response->failed()` check and RuntimeException throw
   - The `response->json('content.0.text')` read, `stripMarkdownFences()`, `json_decode`,
     and the JSON error check and RuntimeException throw
   - Return `$decoded`

2. Add a new public method `extractFromPath(string $absolutePath): array`:
   ```php
   public function extractFromPath(string $absolutePath): array
   {
       $pdfBase64 = base64_encode(file_get_contents($absolutePath));
       return $this->callClaude($pdfBase64);
   }
   ```

3. Rewrite `extract(UploadedFile $pdf): array` to delegate to `extractFromPath()`:
   ```php
   public function extract(UploadedFile $pdf): array
   {
       $storedPath = $pdf->store('tmp/rams-uploads', 'local');
       $localPath  = Storage::disk('local')->path($storedPath);

       try {
           return $this->extractFromPath($localPath);
       } finally {
           Storage::disk('local')->delete($storedPath);
       }
   }
   ```

**Verify:** `php artisan about` (bootstraps successfully, no parse errors)

---

## Task 2 — Update QuoteImportService: delegate extract() to QuoteExtractorService

**File:** `app/Core/Modules/QuoteImport/QuoteImportService.php`

**Changes:**

1. Add use statement after the existing use block:
   ```php
   use App\Services\QuoteExtractorService;
   ```

2. Replace the constructor. Remove `PdfTextExtractorService $pdfExtractor` and
   `QuoteParserService $quoteParser` parameters (they are used only in `extract()` and
   nowhere else in the class). Add `QuoteExtractorService $quoteExtractor`:
   ```php
   public function __construct(
       private readonly ProjectService             $projectService,
       private readonly QuoteExtractorService      $quoteExtractor,
       private readonly ProjectQuoteVersionService $quoteVersioner,
   ) {}
   ```

3. Remove the now-unused `use` statements for `PdfTextExtractorService` and
   `QuoteParserService` from the top of the file.

4. Replace the body of the private `extract(string $storagePath, ?string $provider): array`
   method:
   ```php
   private function extract(string $storagePath, ?string $provider): array
   {
       $absolutePath = Storage::disk('local')->path($storagePath);
       $extracted    = $this->quoteExtractor->extractFromPath($absolutePath);

       $extracted['equipment_list']  = $extracted['line_items']  ?? [];
       $extracted['cable_hints']     = [];
       $extracted['quote_reference'] = $extracted['qw_number']   ?? '';

       return $extracted;
   }
   ```

   Update the PHPDoc above `extract()` to reflect the new pipeline:
   ```
   * Pipeline:
   *   PDF file → QuoteExtractorService::extractFromPath() (Claude document-vision API)
   *
   * Adds convenience keys expected by the import transaction:
   *   equipment_list  ← line_items
   *   cable_hints     ← [] (Claude does not extract cable runs from quotes)
   *   quote_reference ← qw_number
   ```

**Verify:** `php artisan about` (bootstraps successfully, no parse errors)

</tasks>

<verification>
After both tasks are complete:

```bash
php artisan about
```

Expected: application boots, no class-not-found or constructor-mismatch errors.

Manual smoke test (requires a real PDF and .env Claude credentials):
- Upload a QuoteWerks PDF via the quote import UI
- Confirm the resulting ProjectPackage has populated `extracted_data` with `qw_number`,
  `client_name`, `line_items`, etc. (not empty strings from the old PHP parser)
</verification>

<success_criteria>
- `QuoteExtractorService` has three methods: `extract()`, `extractFromPath()` (public),
  and `callClaude()` (private)
- `QuoteImportService` constructor takes `QuoteExtractorService` instead of
  `PdfTextExtractorService` + `QuoteParserService`
- `PdfTextExtractorService` and `QuoteParserService` use-statements removed from
  QuoteImportService
- `php artisan about` passes with no errors
- No other files modified
</success_criteria>

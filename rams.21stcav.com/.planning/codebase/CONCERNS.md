# Codebase Concerns

**Analysis Date:** 2026-04-09

## Tech Debt

**Dead/Backup Files Littering the Codebase:**
- Issue: At least 11 backup files with date-stamped suffixes (e.g. `2703`, `2903`) exist alongside their current counterparts, adding ~6,000 lines of dead code.
- Files:
  - `app/Services/QuoteParserService2903.php` (2,462 lines)
  - `app/Services/OmManualDocxService2703.php` (743 lines)
  - `app/Services/RamsExtractionDraftBuilderService2903.php`
  - `app/Services/RamsReviewDataService2703.php`
  - `app/Services/RamsReviewValidatorService2703.php`
  - `app/Http/Controllers/RamsReviewController2703.php`
  - `app/Jobs/BuildRamsDocumentJob2903.php`
  - `app/Jobs/ExtractRamsDraftJob203.php`
  - `app/Core/Modules/OMManual/OmManualGeneratorService2703.php`
  - `app/Services/AI/ClaudeProvider2803.php`
  - `resources/views/rams/quote-review.blade2903.php`
  - `resources/views/pdf/rams.blade-keep-borders.php`
- Impact: Confuses navigation, risks accidentally importing stale code, inflates repo size. Developers must guess which file is "current."
- Fix approach: Delete all backup files. Use git history to recover old versions if needed.

**Duplicate AI Provider Implementations:**
- Issue: Two separate AI provider hierarchies exist with overlapping functionality.
- Files:
  - `app/Services/AI/ClaudeProvider.php` (264 lines), `app/Services/AI/OpenAIProvider.php` (263 lines), `app/Services/AI/AIProviderFactory.php`, `app/Services/AI/AIProviderInterface.php`
  - `app/Core/AI/Providers/ClaudeProvider.php` (194 lines), `app/Core/AI/Providers/OpenAIProvider.php` (157 lines)
- Impact: Bug fixes or feature changes must be applied to both hierarchies. Risk of behavior divergence between old and new providers.
- Fix approach: Determine which hierarchy is canonical (likely `app/Core/AI/Providers/`), migrate all callers, delete the legacy `app/Services/AI/` directory.

**`.well-known/` Directory Contains Stale View Copies:**
- Issue: A `.well-known/resources/views/` directory contains 66 files that appear to be an old snapshot of the Blade views.
- Files: `.well-known/resources/views/` (layouts, rams, pdf, om-manual, projects, site-survey, etc.)
- Impact: Never served by Laravel, but adds confusion and bloat to the repository.
- Fix approach: Delete `/.well-known/resources/` entirely. The `.well-known/acme-challenge/` directory (for SSL) can remain.

**Compiled Views Committed to Repository:**
- Issue: 8 compiled Blade view files exist in `storage/framework/views/`.
- Files: `storage/framework/views/*.php`
- Impact: These are generated files that should not be in version control. They cause merge conflicts and are environment-specific.
- Fix approach: Add `storage/framework/views/*.php` to `.gitignore` and remove from tracking.

**God-Class Service: QuoteParserService:**
- Issue: At 2,938 lines, this is by far the largest file in the codebase. It is a single class handling all PDF quote text parsing with dozens of private methods.
- Files: `app/Services/QuoteParserService.php`
- Impact: Difficult to test individual parsing stages in isolation. Any change risks regressions across the entire parsing pipeline.
- Fix approach: Extract parsing stages into focused collaborator classes (e.g., `QuoteHeaderParser`, `QuoteEquipmentParser`, `QuoteRoomDetector`). The existing test suite (`tests/Unit/Rams/QuoteParserServiceTest.php`, 928 lines) provides a safety net for refactoring.

**Oversized Controller: RamsController:**
- Issue: 746 lines with 22 public/private methods. Handles CRUD, downloads, PDF generation, email, AI settings, retry logic, and status updates.
- Files: `app/Http/Controllers/RamsController.php`
- Impact: Violates single responsibility. AI settings management (`settings()`, `testConnection()`, `saveSettings()`) does not belong in a RAMS document controller.
- Fix approach: Extract AI settings to a dedicated `AiSettingsController`. Consider splitting download/email/retry into invokable controllers or action classes.

**Oversized Controller: ProjectPackageReviewController:**
- Issue: 976 lines with complex data normalization logic (multiple `is_array()` fallback chains).
- Files: `app/Http/Controllers/ProjectPackageReviewController.php`
- Impact: Business logic mixed with HTTP handling. Difficult to unit test data normalization without HTTP context.
- Fix approach: Extract the data normalization / mapping logic into a dedicated service.

## Security Considerations

**Content-Disposition Header Injection:**
- Risk: `$photo->original_name` is interpolated directly into a `Content-Disposition` header without sanitization. A crafted filename containing newlines or quotes could inject headers.
- Files: `app/Http/Controllers/PublicSurveyController.php:264`, `app/Http/Controllers/SiteSurveyController.php:288`
- Current mitigation: None observed.
- Recommendations: Use Laravel's built-in `response()->download()` or sanitize the filename by stripping control characters and quotes. At minimum: `basename(preg_replace('/[\r\n"]/', '', $photo->original_name))`.

**Runtime .env File Manipulation:**
- Risk: `AiSettingsService::writeEnvValues()` reads and rewrites the `.env` file at runtime. A race condition between concurrent requests could corrupt the file.
- Files: `app/Services/AiSettingsService.php:138-166`
- Current mitigation: Atomic write via temp file + rename. Newline injection is stripped.
- Recommendations: This pattern is fundamentally fragile for multi-process environments. Consider storing AI settings in a database table (with encryption for API keys) instead of in `.env`. At minimum, add file locking around the read-modify-write cycle.

**File Upload Validation Uses `extensions` Instead of `mimes`:**
- Risk: `RamsUploadRequest` validates only the file extension string, not the MIME type. An attacker could upload a non-PDF file with a `.pdf` extension.
- Files: `app/Http/Requests/RamsUploadRequest.php:21`
- Current mitigation: Comment explains this is intentional due to shared hosting `/tmp` issues with Symfony's `getMimeType()`.
- Recommendations: If MIME validation is not possible, add server-side content inspection (e.g., check PDF magic bytes `%PDF-`) after upload, before processing.

**Public Survey Token Security:**
- Risk: Survey access tokens are UUID-based with no expiry enforcement beyond "already submitted" status. Tokens are valid forever once created.
- Files: `app/Http/Controllers/PublicSurveyController.php:44-46`, `app/Models/SiteSurvey.php`
- Current mitigation: Throttle middleware on all public survey routes. Token is checked via `resolveSurvey()`.
- Recommendations: Add a configurable TTL (e.g., 30 days) to survey tokens. Check `expires_at` in `resolveSurvey()`.

**Missing Authorization Policies:**
- Risk: Only 2 policies exist (`OmManualPolicy`, `RamsDocumentPolicy`). Other models like `Project`, `CableSchedule`, `SiteSurvey`, `HazardTemplate` use ad-hoc `authorizeProject()`/`authorizePackage()` private methods in controllers.
- Files: `app/Policies/OmManualPolicy.php`, `app/Policies/RamsDocumentPolicy.php`
- Current mitigation: Private authorize methods in controllers check `user_id` ownership.
- Recommendations: Create formal Policy classes for all models to centralize authorization logic and enable `Gate` checks in Blade templates.

## Performance Bottlenecks

**Large Blade Views Without Component Extraction:**
- Problem: Several views exceed 800 lines, with `review.blade.php` at 1,735 lines and `public-survey/show.blade.php` at 1,578 lines.
- Files:
  - `resources/views/project-packages/review.blade.php` (1,735 lines)
  - `resources/views/public-survey/show.blade.php` (1,578 lines)
  - `resources/views/rams/quote-review.blade.php` (1,020 lines)
  - `resources/views/site-survey/show.blade.php` (851 lines)
  - `resources/views/projects/show.blade.php` (851 lines)
- Cause: All markup, JavaScript, and styling in monolithic templates.
- Improvement path: Extract repeating sections (room cards, equipment tables, hazard rows) into Blade components under `resources/views/components/`.

**Synchronous PDF Processing in Some Paths:**
- Problem: While quote upload and RAMS generation use queued jobs (`ExtractRamsDraftJob`, `BuildRamsDocumentJob`), some PDF operations appear to be synchronous in controller actions (e.g., `downloadPdf` methods generate PDFs on-the-fly).
- Files: `app/Http/Controllers/RamsController.php:463-485`, `app/Http/Controllers/SiteSurveyController.php`
- Cause: PDF rendering (DomPDF/mPDF) is CPU-intensive and blocks the web worker.
- Improvement path: Pre-generate PDFs via queued jobs and serve cached files. Add a "generating..." polling pattern similar to the existing quote upload flow.

## Fragile Areas

**AI Response Parsing:**
- Files: `app/Core/AI/AIManager.php`, `app/Core/AI/Providers/ClaudeProvider.php`, `app/Core/AI/Providers/OpenAIProvider.php`
- Why fragile: AI providers return unpredictable JSON. Multiple `json_decode()` calls throughout the codebase (~19 occurrences in app/) with inconsistent error handling. Some use `JSON_THROW_ON_ERROR`, most do not.
- Safe modification: Always use `json_decode($str, true, 512, JSON_THROW_ON_ERROR)` wrapped in try/catch. Centralize JSON parsing into the AI provider layer.
- Test coverage: `tests/Unit/Services/AICacheServiceTest.php` and `tests/Unit/Rams/MethodStatementFallbackTest.php` cover some AI paths, but no direct tests for provider response parsing edge cases.

**QuoteParserService Regex-Heavy Parsing:**
- Files: `app/Services/QuoteParserService.php`
- Why fragile: The parser relies on dozens of regex patterns tuned to QuoteWerks PDF output format. Any change in QuoteWerks export layout could silently break parsing. The confidence scoring system (lines 24-31) masks partial failures.
- Safe modification: Always run the full test suite (`tests/Unit/Rams/QuoteParserServiceTest.php`) after any change. Add new test cases for each new PDF format variation encountered.
- Test coverage: Good (928-line test file), but should be expanded with real-world PDF text fixtures.

## Scaling Limits

**Local File Storage:**
- Current capacity: All documents (PDFs, DOCX, photos) stored on local disk via `Storage::disk('local')`.
- Limit: Single-server capacity. Cannot horizontally scale. Disk space bounded by hosting plan.
- Scaling path: Migrate to S3-compatible storage with `Storage::disk('s3')`. The existing `Storage` facade usage makes this straightforward.

**SQLite/Single-Database Queue:**
- Current capacity: Queue jobs processed by a single `queue:listen` worker (see `composer.json` dev script).
- Limit: Long-running AI generation jobs (up to 600s timeout) block other jobs.
- Scaling path: Use dedicated queue connections for AI jobs vs. quick jobs. Consider Redis-backed queues for better concurrency.

## Dependencies at Risk

**Dual PDF Extraction Libraries:**
- Risk: Both `smalot/pdfparser` and `spatie/pdf-to-text` are installed. `spatie/pdf-to-text` requires `pdftotext` binary on the server.
- Impact: Server environment dependency for OCR fallback. If `pdftotext` is not installed, the fallback chain silently degrades.
- Files: `app/Services/PdfTextExtractorService.php`, `app/Services/PdfOcrExtractorService.php`
- Migration plan: Document the server requirement. Consider removing `spatie/pdf-to-text` if Tesseract OCR (used in `PdfOcrExtractorService`) covers the same use case.

**Three PDF Rendering Libraries:**
- Risk: `barryvdh/laravel-dompdf`, `dompdf/dompdf`, and `mpdf/mpdf` are all installed.
- Impact: Maintenance burden of three PDF libraries with overlapping functionality. `barryvdh/laravel-dompdf` wraps `dompdf/dompdf`, so both are expected, but having `mpdf` too suggests incomplete migration.
- Files: `composer.json`
- Migration plan: Standardize on one PDF renderer and remove the other.

## Test Coverage Gaps

**Low Overall Test Count:**
- What's not tested: Only 21 test files exist for 27 controllers and ~25 service classes.
- Files: `tests/` directory
- Risk: Large swaths of the application have no automated test coverage, particularly:
  - `app/Http/Controllers/CableScheduleController.php` (no feature test)
  - `app/Http/Controllers/SiteSurveyController.php` (no feature test)
  - `app/Http/Controllers/PublicSurveyController.php` (no feature test)
  - `app/Services/DocxBuilderService.php` (948 lines, no unit test)
  - `app/Services/RamsBuilderService.php` (603 lines, no dedicated test)
  - `app/Services/WordDocumentService.php` (790 lines, no dedicated test)
  - `app/Services/OmManualDocxService.php` (753 lines, no unit test)
- Priority: High for public-facing `PublicSurveyController` (unauthenticated endpoint). Medium for document generation services.

**No Integration Tests for AI Providers:**
- What's not tested: The `app/Core/AI/Providers/` classes that make HTTP calls to Claude/OpenAI APIs have no mocked integration tests.
- Files: `app/Core/AI/Providers/ClaudeProvider.php`, `app/Core/AI/Providers/OpenAIProvider.php`
- Risk: API response format changes from Anthropic or OpenAI would not be caught until production.
- Priority: Medium. Add tests with mocked HTTP responses using Laravel's `Http::fake()`.

**No E2E or Browser Tests:**
- What's not tested: No Dusk or similar browser testing framework is installed. Complex JavaScript-heavy views (review pages with inline editing) are untested.
- Risk: UI regressions in the 1,700-line review blade template would go unnoticed.
- Priority: Low (relative to other gaps), but would provide high value for the review workflow.

## Missing Critical Features

**No Automated Cleanup of Temporary Files:**
- Problem: `tempnam()` and `sys_get_temp_dir()` are used in several services for DOCX template creation. Only `PdfOcrExtractorService` and `QuoteExtractorService` use `finally` blocks for cleanup.
- Files: `app/Services/DocxBuilderService.php:140-144`, `app/Services/OmManualDocxService.php:659-663`
- Blocks: Temp files may accumulate on the server if exceptions occur between creation and the `@unlink()` call.

**No Request Rate Limiting on Authenticated AI-Triggering Routes:**
- Problem: Routes that dispatch AI generation jobs (RAMS generation, O&M manual creation) have no throttle middleware. A user could rapidly trigger expensive API calls.
- Files: `routes/web.php` - the `rams.upload.store` and `quote-import.store` routes have throttle, but `rams.regenerate`, `rams.retry-extraction`, `rams.retry-generation` do not.
- Blocks: Uncontrolled AI API spend.

---

*Concerns audit: 2026-04-09*

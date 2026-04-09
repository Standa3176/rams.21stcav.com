# Architecture

**Analysis Date:** 2026-04-09

## Pattern Overview

**Overall:** Laravel MVC with a rich Service Layer and a custom AI abstraction layer

**Key Characteristics:**
- Standard Laravel 11 MVC with Blade templates (server-rendered, no SPA)
- Heavy service layer — controllers are thin, delegating to dedicated service classes
- Custom `app/Core/` namespace for AI abstraction, prompt DTOs, and module-level orchestration services
- Two-phase async pipeline pattern for document generation (Extract → Review → Generate)
- Queue-based background processing for AI-heavy operations via Laravel Jobs

## Domain Overview

This is an internal tool for **21st Century AV Ltd**, a UK audio-visual installation company. It manages the lifecycle of AV installation projects and auto-generates compliance/technical documents:

- **RAMS** (Risk Assessment & Method Statement) — the primary document type
- **O&M Manuals** (Operation & Maintenance) — generated from project data
- **Site Surveys** — room-by-room site assessments with photo capture
- **Cable Schedules** — structured cable routing documents
- **Quote Import** — PDF parsing of QuoteWerks quotes into structured project data

## Layers

**Controllers (`app/Http/Controllers/`):**
- Purpose: Handle HTTP requests, validate input, delegate to services, return views/redirects
- Contains: Thin controllers with constructor-injected services
- Key files: `RamsController.php`, `QuoteImportController.php`, `ProjectPackageReviewController.php`, `SiteSurveyController.php`, `PublicSurveyController.php`, `OmManualController.php`, `CableScheduleController.php`
- Admin sub-namespace: `app/Http/Controllers/Admin/` — `UserController.php`, `AIUsageController.php`, `SolutionTypeController.php`

**Core Module Services (`app/Core/Modules/`):**
- Purpose: High-level orchestration for each business module
- Contains: Module-scoped services that coordinate multiple lower-level services
- Key files:
  - `app/Core/Modules/QuoteImport/QuoteImportService.php` — full quote-to-package pipeline
  - `app/Core/Modules/RAMS/RamsGeneratorService.php` — RAMS generation orchestrator
  - `app/Core/Modules/OMManual/OmManualGeneratorService.php` — O&M content generation
  - `app/Core/Modules/Survey/SurveyService.php` — survey CRUD and photo management
  - `app/Core/Modules/Projects/ProjectService.php` — project lifecycle management
  - `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` — hazard template lookup

**AI Abstraction (`app/Core/AI/`):**
- Purpose: Provider-agnostic AI interaction layer
- Contains: Manager, provider contract, provider implementations, prompt DTOs
- Key files:
  - `app/Core/AI/AIManager.php` — static facade; all AI calls MUST go through this
  - `app/Core/AI/Contracts/AIProviderContract.php` — interface for providers
  - `app/Core/AI/Providers/ClaudeProvider.php` — Anthropic Claude implementation
  - `app/Core/AI/Providers/OpenAIProvider.php` — OpenAI implementation
  - `app/Core/AI/Prompts/BasePrompt.php` — abstract prompt DTO base class
  - `app/Core/AI/Prompts/RamsPrompt.php`, `QuoteExtractionPrompt.php`, `MethodStatementPrompt.php`, `OmManualPrompt.php`, `CableSchedulePrompt.php`, `RoomOverviewSummaryPrompt.php`, `ScopeOfWorksPrompt.php`, `SurveyPrompt.php`

**Services (`app/Services/`):**
- Purpose: Single-responsibility services for specific operations
- Contains: PDF extraction, parsing, classification, document rendering, data building
- Depends on: Models, Core/AI layer
- Used by: Controllers, Jobs, Core Module Services
- Key categories:
  - **PDF/Text extraction:** `PdfTextExtractorService.php`, `PdfOcrExtractorService.php`, `QuoteTextExtractorService.php`
  - **Quote parsing:** `QuoteParserService.php`, `QuoteLineExtractorService.php`, `EquipmentLineParserService.php`
  - **Equipment processing:** `EquipmentClassifierService.php`, `EquipmentNormalizerService.php`, `EquipmentExtractorService.php`
  - **RAMS pipeline:** `RamsBuilderService.php`, `RamsDataBuilderService.php`, `RamsExtractionDraftBuilderService.php`, `RamsReviewDataService.php`, `RamsReviewValidatorService.php`, `RamsDocumentRendererService.php`
  - **Risk/safety:** `RiskTemplateResolverService.php`, `RiskMatrixService.php`
  - **AI-powered generation:** `MethodStatementGeneratorService.php`, `MethodStatementService.php`, `RoomOverviewSummaryService.php`, `PromptBuilderService.php`
  - **Document output:** `DocxBuilderService.php`, `WordDocumentService.php`, `PdfService.php`, `DocumentTemplateService.php`, `OmManualDocxService.php`, `SiteSurveyDocxService.php`, `SurveyPdfService.php`, `CableScheduleXlsxService.php`
  - **Project management:** `ProjectContextResolver.php`, `ProjectResolverService.php`, `ProjectSyncFromQuoteService.php`, `ProjectQuoteVersionService.php`
  - **AI infrastructure:** `AICacheService.php`, `AIUsageService.php`, `AiSettingsService.php`
  - **Operations:** `WorkerMonitorService.php`, `ApproveRamsForGenerationService.php`

**Jobs (`app/Jobs/`):**
- Purpose: Async queue-based processing for long-running AI/document operations
- Key files:
  - `ExtractRamsDraftJob.php` — Phase A: PDF text extraction + local parsing (no AI)
  - `BuildRamsDocumentJob.php` — Phase B: AI method statement + DOCX rendering
  - `BuildOmManualJob.php` — O&M Pass 2: AI content generation + DOCX build

**Models (`app/Models/`):**
- Purpose: Eloquent models with relationships, scopes, status constants, and lifecycle helpers
- Used by: All layers

## Data Flow

**RAMS Generation Pipeline (primary flow):**

1. User uploads QuoteWerks PDF via `QuoteUploadController::store()`
2. `RamsDocument` record created with `status=uploaded`, PDF stored to `storage/app/`
3. `ExtractRamsDraftJob` dispatched to queue (Phase A):
   - `QuoteTextExtractorService` extracts text from PDF (local, pdfparser + OCR fallback)
   - `RamsExtractionDraftBuilderService` parses text, classifies equipment, resolves risks
   - `extracted_data` saved to record, status advances to `awaiting_review`
4. User reviews extracted data on `rams/{id}/quote-review` page
5. User edits and approves; `reviewed_data` saved, status set to `approved`
6. `BuildRamsDocumentJob` dispatched (Phase B):
   - `RamsBuilderService::buildFromReview()` takes `reviewed_data` as sole source of truth
   - `MethodStatementGeneratorService` makes a single AI call via `AIManager::run()`
   - `RamsDataBuilderService` assembles final data structure
   - `RamsDocumentRendererService` → `DocxBuilderService` renders branded DOCX
   - `generated_data` saved, status advances to `completed`
7. User downloads DOCX or PDF from the review page

**Quote Import Pipeline:**

1. User uploads QuoteWerks PDF via `QuoteImportController::store()`
2. `QuoteImportService::import()` runs synchronously:
   - Store PDF, extract text (`PdfTextExtractorService`)
   - Filter to equipment lines (`QuoteLineExtractorService`)
   - AI extraction via `AIManager::run(new QuoteExtractionPrompt(), ...)`
   - Normalize into `ProjectPackage` with `extracted_data`, `equipment_list`, `cable_list`
   - Optionally create/link a `Project`
3. User reviews on `project-packages/{id}/review` page
4. Approved data becomes the source-of-truth for RAMS, O&M, surveys, cable schedules

**O&M Manual Pipeline:**

1. Created from project data or uploaded PDF
2. Pass 1 (synchronous): Extract equipment/content into `extracted_data`
3. `BuildOmManualJob` dispatched (Pass 2): AI generates full O&M content, DOCX built
4. Status: `extracted` → `generating` → `draft` → `final`

**Site Survey Flow:**

1. Admin creates survey from project data (`SiteSurveyController::createFromProject()`)
2. Rooms pre-populated from project package's equipment-by-area data
3. UUID access token auto-generated; public URL shared with field engineer
4. Engineer completes survey at `survey/{token}` (no login required — `PublicSurveyController`)
5. Room-by-room completion with photo uploads to `storage/app/projects/{id}/surveys/{id}/`
6. Final submission marks survey as completed

**State Management:**
- `Project` model has a linear lifecycle state machine: `quote_imported` → `survey_pending` → `engineering` → `installing` → `commissioning` → `handover` → `completed` → `archived`
- `RamsDocument` has a pipeline state machine: `uploaded` → `awaiting_review` → `approved` → `generating` → `completed` (or `failed` at any step)
- `OmManual` follows: `extracted` → `generating` → `draft` → `final` (or `failed`)
- All status transitions are defined as constants on the model classes

## AI Abstraction Pattern

**AIManager (Singleton entry point):**
- All modules call `AIManager::run(BasePrompt, context)` — never instantiate providers directly
- Handles: provider resolution, prompt building, caching (hash-based), retry with suffix, usage logging
- Max 2 attempts per call; retry appends a "return only JSON" suffix
- Cache uses SHA-256 hash of built prompt text, stored in `ai_cache` table with TTL

**Prompt DTO Pattern:**
- Each AI use case has a prompt class extending `BasePrompt`
- Prompt classes define: `build(context)`, `systemMessage()`, `maxTokens()`, `temperature()`
- Default system message: "You are a senior UK AV installation expert. Respond ONLY with valid JSON."
- Supports PDF attachment via `setPdf(base64)` for document vision
- Context is passed via `withContext()` and survives cloning by AIManager

**Provider Contract:**
- Three interaction modes: text-only JSON, PDF+text JSON, typed Prompt DTO execution
- Providers handle HTTP transport only; parsing/validation happens in AIManager
- Configurable via `config/ai.php` with env var overrides

## Key Abstractions

**ProjectPackage:**
- Purpose: Represents a parsed quote import — the canonical "what was quoted" for a project
- Location: `app/Models/ProjectPackage.php`
- Contains: `extracted_data` (AI-parsed), `equipment_list`, `cable_list`, `works_description`
- Pattern: Shared data source for RAMS, O&M, surveys, cable schedules

**RamsDocument Pipeline Status:**
- Purpose: Tracks a RAMS document through extract → review → generate phases
- Location: `app/Models/RamsDocument.php`
- Status constants define the state machine; `reviewed_data` is the generation source of truth

**HazardTemplate Library:**
- Purpose: Reusable risk/hazard templates with pre/post likelihood and severity scores
- Location: `app/Models/HazardTemplate.php`, `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php`
- Pattern: Global templates + user-owned templates, resolved by `RiskTemplateResolverService`

## Entry Points

**Web Routes (`routes/web.php`):**
- Location: `routes/web.php`
- All routes defined here; no API routes
- Public routes: `survey/{token}/*` — UUID-gated, no auth
- Authenticated routes: everything else under `auth` middleware
- Admin routes: nested under `admin` middleware alias

**Queue Workers:**
- `ExtractRamsDraftJob` — Phase A extraction (no AI, up to 600s timeout for OCR)
- `BuildRamsDocumentJob` — Phase B generation (AI call, 180s timeout)
- `BuildOmManualJob` — O&M generation (AI call, 300s timeout)
- All jobs: 2 retries, status set to `failed` on exhaustion

**Artisan Commands:**
- `app/Console/Commands/CreateDocxTemplates.php` — generates base DOCX templates

## Error Handling

**Strategy:** Try/catch in Jobs with status persistence; controller-level catch with flash messages

**Patterns:**
- Jobs catch `\Throwable`, set model `status=failed` and `error_message`, then rethrow
- Jobs have a `failed()` hook for final cleanup after all retries exhausted
- Controllers catch `AIGenerationException` specifically for user-friendly error messages
- AI layer throws `AIGenerationException` after max attempts (wraps provider errors)
- Custom exceptions: `app/Exceptions/AIGenerationException.php`, `app/Exceptions/RamsGenerationException.php`

## Cross-Cutting Concerns

**Authentication:** Laravel Breeze (standard session-based auth). Role field on User: `admin` | `user`. `EnsureUserIsAdmin` middleware registered as `admin` alias in `bootstrap/app.php`.

**Authorization:** Policy-based for `RamsDocument` and `OmManual` (`app/Policies/RamsDocumentPolicy.php`, `app/Policies/OmManualPolicy.php`). Controllers use `$this->authorize()` or manual checks. Admins see all records; regular users see only their own.

**Logging:** `Illuminate\Support\Facades\Log` used throughout services and jobs. Structured context arrays passed to every log call (record IDs, counts, error details). No external logging service.

**Validation:** Form Request classes for critical inputs (`app/Http/Requests/RamsFormRequest.php`, `QuoteImportRequest.php`, `RamsUploadRequest.php`). Review controllers validate inline. Rate limiting via `throttle` middleware on upload/submission routes.

**File Storage:** Local filesystem via Laravel's Storage facade (`storage/app/`). PDF uploads stored at `storage/app/rams-uploads/`, survey photos at `storage/app/projects/{id}/surveys/{id}/`, quote imports at `storage/app/quote-imports/`. Generated DOCX files stored at `storage/app/rams/`.

**Activity Logging:** `ProjectActivityLog` model tracks project lifecycle events (status changes, document additions, imports, reviews). Append-only (no `updated_at`).

**AI Usage Tracking:** `AIUsage` model logs every AI call with provider, model, token counts, and cost estimates. Admin dashboard at `/admin/ai-usage`.

**Caching:** AI response caching via `AICacheService` using SHA-256 prompt hashing. Stored in `ai_cache` DB table with configurable TTL (default 30 days).

---

*Architecture analysis: 2026-04-09*

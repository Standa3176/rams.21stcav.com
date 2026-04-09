<!-- GSD:project-start source:PROJECT.md -->
## Project

**RAMS Platform — AV Operations System**

An internal operations platform for 21st Century AV Ltd that manages the full lifecycle of AV installation projects. Starting from a quoted job, it flows through site survey, engineering review, and generates all compliance and technical documents (RAMS, Worksheets, O&M Manuals, Cable Schedules) from a single unified dataset. No duplicated data, no AI guessing — every output is driven by structured project data.

**Core Value:** One dataset powers every document. Engineers capture real-world data, quotes provide equipment scope, and all outputs are generated with zero guesswork from that shared truth.

### Constraints

- **AI usage**: AI is ONLY allowed for formatting and method statement structuring — never for inventing scope, equipment, or design
- **Data integrity**: All document content must trace back to quote data, survey data, or reviewed inputs
- **Existing pipeline**: Must not break existing RAMS pipeline, extracted/reviewed/generated data flow, or queue-based generation
- **Architecture**: Laravel service-based, thin controllers, shared data services, safe migrations, queue-compatible
- **SQL security**: QuoteWerks SQL connection is read-only, .env configured, no frontend exposure
- **Output formats**: RAMS/Worksheets/O&M as DOCX, Cable Schedules as XLSX
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP ^8.2 - All backend logic, controllers, services, models, jobs
- JavaScript (ES modules) - Alpine.js frontend interactivity (`resources/js/app.js`)
- CSS - Tailwind utility classes (`resources/css/app.css`)
- Blade - Server-side templating (`resources/views/**/*.blade.php`)
## Runtime
- PHP 8.2+ (required by `composer.json`)
- Node.js (for Vite build toolchain; version not pinned, no `.nvmrc`)
- Composer (PHP) - `composer.json`, `composer.lock` present
- npm (JS) - `package.json`, lockfile expected
## Frameworks
- Laravel ^12.0 (`laravel/framework`) - Full-stack MVC framework
- Laravel Breeze ^2.4 (dev) - Authentication scaffolding (login, register, password reset)
- PHPUnit ^11.5.3 - Unit and feature tests
- Mockery ^1.6 - Test doubles / mocking
- FakerPHP ^1.23 - Test data generation
- Vite ^7.0.7 via `laravel-vite-plugin` ^2.0.0 - Asset bundling (`vite.config.js`)
- Tailwind CSS ^3.1.0 with `@tailwindcss/forms` ^0.5.2 - Utility-first CSS
- PostCSS ^8.4.31 + Autoprefixer ^10.4.2 - CSS post-processing
- Laravel Pint ^1.24 (dev) - PHP code style fixer (PSR-12 based)
- Laravel Sail ^1.41 (dev) - Docker dev environment
- Laravel Pail ^1.2.2 (dev) - Real-time log viewer
- Concurrently ^9.0.1 (dev) - Parallel process runner for `composer dev`
## Frontend Stack
- Views located in `resources/views/`
- PDF-specific templates in `resources/views/pdf/`
- Config: `tailwind.config.js`
- PostCSS: `postcss.config.js`
- Font: Figtree (extends default sans-serif)
- Plugin: `@tailwindcss/forms` for form element styling
- Lightweight reactive framework for UI interactivity
- Initialized in `resources/js/app.js`
- HTTP client: Axios ^1.11.0 (imported via `resources/js/bootstrap.js`)
## Key Dependencies
- `laravel/framework` ^12.0 - Core application framework
- `guzzlehttp/guzzle` ^7.10 - HTTP client for external API calls (AI providers)
- `barryvdh/laravel-dompdf` ^3.1 - Renders Blade views to PDF (RAMS and Site Survey downloads)
- `dompdf/dompdf` ^3.1 - Core PDF rendering engine (used by barryvdh wrapper)
- `mpdf/mpdf` ^8.3 - O&M Manual PDF export (full CSS support for complex layouts)
- `smalot/pdfparser` ^2.12 - Primary PHP-native PDF text extraction (`app/Services/PdfTextExtractorService.php`)
- `spatie/pdf-to-text` ^1.54 - Fallback PDF text extraction using `pdftotext` binary
- `phpoffice/phpword` ^1.4 - DOCX document generation (`app/Services/DocxBuilderService.php`)
- `doctrine/dbal` ^4.4 - Database abstraction for migrations and schema introspection
## Database
- Host: `127.0.0.1:3306`
- Database name: `laravel_rams`
- Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`
- Config: `config/database.php`
## Queue / Cache / Session Drivers
- Driver: `database` (default)
- Table: `jobs`
- Failed jobs: `failed_jobs` table (database-uuids driver)
- Job batching: `job_batches` table
- Config: `config/queue.php`
- Dev runner: `php artisan queue:listen --tries=1 --timeout=0`
- Driver: `database` (default)
- Table: `cache`
- AI response caching uses a separate `ai_cache` table with SHA-256 hash keys and configurable TTL (`app/Services/AICacheService.php`)
- Config: `config/cache.php`
- Driver: `database` (default)
- Table: `sessions`
- Lifetime: 120 minutes
- Config: `config/session.php`
## AI / ML Integration
- Contract: `app/Core/AI/Contracts/AIProviderContract.php`
- Supports two providers, swappable via `AI_DEFAULT_PROVIDER` env var
- Provider: `app/Core/AI/Providers/ClaudeProvider.php`
- Model: `claude-sonnet-4-6` (configurable via `CLAUDE_MODEL`)
- Endpoint: `https://api.anthropic.com/v1/messages`
- Supports native PDF document vision (base64-encoded PDFs)
- Anthropic API version: `2023-06-01`
- Provider: `app/Core/AI/Providers/OpenAIProvider.php`
- Model: `gpt-4o` (configurable via `OPENAI_MODEL`)
- Endpoint: `https://api.openai.com/v1/chat/completions`
- Uses `response_format: json_object` for structured output
- PDF support via data URI encoding
- Base class: `app/Core/AI/Prompts/BasePrompt.php`
- Domain-specific prompts in `app/Core/AI/Prompts/`:
- Service: `app/Services/AIUsageService.php`
- Model: `app/Models/AIUsage.php`
- Tracks provider, model, prompt class, input/output/total tokens, estimated cost (USD)
- Cost estimation uses configurable per-1K-token pricing in `config/ai.php`
- Service: `app/Services/AICacheService.php`
- Database-backed (`ai_cache` table), SHA-256 prompt hashing
- Configurable TTL: `AI_CACHE_TTL_DAYS` (default 30 days)
- Prune command: `php artisan ai:cache-prune`
## OCR / External Binary Dependencies
- Used by `app/Services/PdfOcrExtractorService.php` as last-resort PDF text extraction
- Requires: `tesseract-ocr` (apt package), Tesseract 4+
- Language: English (`-l eng --psm 6`)
- Used by `PdfOcrExtractorService.php` for PDF-to-image conversion (`pdftoppm`)
- Requires: `poppler-utils` (apt package)
- Also required by `spatie/pdf-to-text` (`pdftotext` binary)
## File Storage
- Private storage: `storage/app/private/`
- Public storage: `storage/app/public/` (symlinked to `public/storage`)
- S3 configured but not active by default
- Config: `config/filesystems.php`
## Authentication
- Driver: Eloquent user provider
- Scaffolded by Laravel Breeze
- Policies: `RamsDocumentPolicy`, `OmManualPolicy` (registered in `app/Providers/AppServiceProvider.php`)
## Logging
- Config: `config/logging.php`
- Dev: Laravel Pail for real-time log tailing
## Development Workflow
## Configuration Files
| File | Purpose |
|------|---------|
| `config/ai.php` | AI provider selection, model config, pricing, cache TTL |
| `config/rams.php` | Company identity (name, abbreviation) for generated documents |
| `config/database.php` | MySQL connection, Redis config |
| `config/queue.php` | Database queue driver |
| `config/cache.php` | Database cache driver |
| `config/session.php` | Database session driver |
| `config/filesystems.php` | Local + S3 disk config |
| `config/mail.php` | Mail driver (defaults to log) |
| `config/services.php` | Third-party service credentials (Postmark, Resend, SES, Slack) |
| `vite.config.js` | Vite build config with Laravel plugin |
| `tailwind.config.js` | Tailwind content paths, Figtree font, forms plugin |
| `postcss.config.js` | PostCSS with Tailwind and Autoprefixer |
| `phpunit.xml` | PHPUnit test configuration |
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Naming Patterns
- Controllers: PascalCase with `Controller` suffix — `RamsController.php`, `SiteSurveyController.php`
- Services: PascalCase with `Service` suffix — `QuoteParserService.php`, `RamsBuilderService.php`
- Models: PascalCase singular — `RamsDocument.php`, `ProjectPackage.php`, `SiteSurvey.php`
- Jobs: PascalCase with `Job` suffix, verb-first — `BuildRamsDocumentJob.php`, `ExtractRamsDraftJob.php`
- Form Requests: PascalCase with `Request` suffix — `RamsFormRequest.php`, `RamsUploadRequest.php`
- Policies: PascalCase with `Policy` suffix matching model — `RamsDocumentPolicy.php`, `OmManualPolicy.php`
- Prompts: PascalCase with `Prompt` suffix — `MethodStatementPrompt.php`, `RamsPrompt.php`
- Blade views: kebab-case — `quote-review.blade.php`, `_room-form.blade.php` (partials prefixed with underscore)
- Legacy/backup files: numeric date suffix — `PdfService2403-1807.php`, `RamsReviewDataService2703.php` (NOT recommended for new code)
- camelCase throughout: `buildFromForm()`, `buildFromReview()`, `resolveRamsDocxPath()`
- Boolean methods prefixed with `is`/`has`: `isAdmin()`, `isSuperseded()`, `isPipelineStatus()`
- Private helpers follow same camelCase: `stripFences()`, `resolveScope()`
- camelCase: `$ramsDocument`, `$reviewPayload`, `$generatedData`
- Short descriptive names in tight scope: `$e` for exceptions, `$ctx` for context arrays
- Model status constants: `STATUS_UPPERCASE_SNAKE` — `STATUS_AWAITING_REVIEW`, `STATUS_GENERATING`
- Class constants: `UPPERCASE_SNAKE` — `ANTHROPIC_VERSION`, `DEFAULT_TIMEOUT`, `CONFIDENCE_THRESHOLD`
## Code Style
- Laravel Pint (dev dependency in `composer.json`)
- PSR-12 style: 4-space indentation, opening braces on same line for classes/methods
- Aligned column formatting for class properties and array keys (see `RamsDocument::$fillable`, `ClaudeProvider` constructor)
- Laravel Pint (`laravel/pint` ^1.24) — PSR-12 base
- No `.php-cs-fixer` or custom Pint config detected; uses Pint defaults
- Use ASCII art comment dividers to separate controller methods:
- Use `// ══════` or `// ────────` for major sections in service classes
- Use `// ── Label ──` for subsections within methods
## Import Organization
- Standard PSR-4 autoloading: `App\` maps to `app/`
- No path aliases (`@` or `~`) for PHP
- No barrel files or index re-exports
## Error Handling
- `QuoteParserService`: returns empty strings/arrays for missing data (never throws)
- `MethodStatementService`: returns static 5-phase fallback when AI fails
- AI providers: throw `RuntimeException` on HTTP failure or invalid JSON
- `App\Exceptions\AIGenerationException` — thrown by AI manager after retry exhaustion
- `App\Exceptions\RamsGenerationException` — thrown during RAMS pipeline failures
- Use `$this->authorize('view', $rams)` in controllers (policy-based)
- Use `abort_if()` / `abort_unless()` for inline admin checks:
## Logging
- Always prefix log messages with class name: `'RamsController: document soft-deleted'`
- Include structured context array with relevant IDs:
- Use `Log::info()` for successful operations and state transitions
- Use `Log::error()` for failures and exceptions
- Use `Log::warning()` for unexpected-but-recoverable states (e.g., missing `project_id` on creating)
- Model boot events log warnings for data integrity issues:
## Comments
- PHPDoc blocks on all classes explaining purpose, usage patterns, and pipeline position
- PHPDoc on all public methods with `@param`, `@return`, `@throws`
- Inline comments for non-obvious business logic or defensive guard clauses
- Large constant arrays have inline `// ...` explaining why specific values are included/excluded
## Data Flow Pattern
## Function Design
- Controllers return typed responses: `View`, `RedirectResponse`, `JsonResponse`, `BinaryFileResponse`
- Services return data arrays or file paths (strings)
- Parsers return structured arrays with documented shape (see `QuoteParserService` class docblock)
## Module Design
- `app/Services/` — flat namespace for all service classes (primary location)
- `app/Core/Modules/{Module}/` — module-specific service classes (e.g., `HazardLibraryService`)
- `app/Core/AI/` — AI abstraction layer (Contracts, Prompts, Providers)
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## Pattern Overview
- Standard Laravel 11 MVC with Blade templates (server-rendered, no SPA)
- Heavy service layer — controllers are thin, delegating to dedicated service classes
- Custom `app/Core/` namespace for AI abstraction, prompt DTOs, and module-level orchestration services
- Two-phase async pipeline pattern for document generation (Extract → Review → Generate)
- Queue-based background processing for AI-heavy operations via Laravel Jobs
## Domain Overview
- **RAMS** (Risk Assessment & Method Statement) — the primary document type
- **O&M Manuals** (Operation & Maintenance) — generated from project data
- **Site Surveys** — room-by-room site assessments with photo capture
- **Cable Schedules** — structured cable routing documents
- **Quote Import** — PDF parsing of QuoteWerks quotes into structured project data
## Layers
- Purpose: Handle HTTP requests, validate input, delegate to services, return views/redirects
- Contains: Thin controllers with constructor-injected services
- Key files: `RamsController.php`, `QuoteImportController.php`, `ProjectPackageReviewController.php`, `SiteSurveyController.php`, `PublicSurveyController.php`, `OmManualController.php`, `CableScheduleController.php`
- Admin sub-namespace: `app/Http/Controllers/Admin/` — `UserController.php`, `AIUsageController.php`, `SolutionTypeController.php`
- Purpose: High-level orchestration for each business module
- Contains: Module-scoped services that coordinate multiple lower-level services
- Key files:
- Purpose: Provider-agnostic AI interaction layer
- Contains: Manager, provider contract, provider implementations, prompt DTOs
- Key files:
- Purpose: Single-responsibility services for specific operations
- Contains: PDF extraction, parsing, classification, document rendering, data building
- Depends on: Models, Core/AI layer
- Used by: Controllers, Jobs, Core Module Services
- Key categories:
- Purpose: Async queue-based processing for long-running AI/document operations
- Key files:
- Purpose: Eloquent models with relationships, scopes, status constants, and lifecycle helpers
- Used by: All layers
## Data Flow
- `Project` model has a linear lifecycle state machine: `quote_imported` → `survey_pending` → `engineering` → `installing` → `commissioning` → `handover` → `completed` → `archived`
- `RamsDocument` has a pipeline state machine: `uploaded` → `awaiting_review` → `approved` → `generating` → `completed` (or `failed` at any step)
- `OmManual` follows: `extracted` → `generating` → `draft` → `final` (or `failed`)
- All status transitions are defined as constants on the model classes
## AI Abstraction Pattern
- All modules call `AIManager::run(BasePrompt, context)` — never instantiate providers directly
- Handles: provider resolution, prompt building, caching (hash-based), retry with suffix, usage logging
- Max 2 attempts per call; retry appends a "return only JSON" suffix
- Cache uses SHA-256 hash of built prompt text, stored in `ai_cache` table with TTL
- Each AI use case has a prompt class extending `BasePrompt`
- Prompt classes define: `build(context)`, `systemMessage()`, `maxTokens()`, `temperature()`
- Default system message: "You are a senior UK AV installation expert. Respond ONLY with valid JSON."
- Supports PDF attachment via `setPdf(base64)` for document vision
- Context is passed via `withContext()` and survives cloning by AIManager
- Three interaction modes: text-only JSON, PDF+text JSON, typed Prompt DTO execution
- Providers handle HTTP transport only; parsing/validation happens in AIManager
- Configurable via `config/ai.php` with env var overrides
## Key Abstractions
- Purpose: Represents a parsed quote import — the canonical "what was quoted" for a project
- Location: `app/Models/ProjectPackage.php`
- Contains: `extracted_data` (AI-parsed), `equipment_list`, `cable_list`, `works_description`
- Pattern: Shared data source for RAMS, O&M, surveys, cable schedules
- Purpose: Tracks a RAMS document through extract → review → generate phases
- Location: `app/Models/RamsDocument.php`
- Status constants define the state machine; `reviewed_data` is the generation source of truth
- Purpose: Reusable risk/hazard templates with pre/post likelihood and severity scores
- Location: `app/Models/HazardTemplate.php`, `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php`
- Pattern: Global templates + user-owned templates, resolved by `RiskTemplateResolverService`
## Entry Points
- Location: `routes/web.php`
- All routes defined here; no API routes
- Public routes: `survey/{token}/*` — UUID-gated, no auth
- Authenticated routes: everything else under `auth` middleware
- Admin routes: nested under `admin` middleware alias
- `ExtractRamsDraftJob` — Phase A extraction (no AI, up to 600s timeout for OCR)
- `BuildRamsDocumentJob` — Phase B generation (AI call, 180s timeout)
- `BuildOmManualJob` — O&M generation (AI call, 300s timeout)
- All jobs: 2 retries, status set to `failed` on exhaustion
- `app/Console/Commands/CreateDocxTemplates.php` — generates base DOCX templates
## Error Handling
- Jobs catch `\Throwable`, set model `status=failed` and `error_message`, then rethrow
- Jobs have a `failed()` hook for final cleanup after all retries exhausted
- Controllers catch `AIGenerationException` specifically for user-friendly error messages
- AI layer throws `AIGenerationException` after max attempts (wraps provider errors)
- Custom exceptions: `app/Exceptions/AIGenerationException.php`, `app/Exceptions/RamsGenerationException.php`
## Cross-Cutting Concerns
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, or `.github/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->

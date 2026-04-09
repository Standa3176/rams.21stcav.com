# Codebase Structure

**Analysis Date:** 2026-04-09

## Directory Layout

```
rams.21stcav.com/
├── app/
│   ├── Console/Commands/          # Artisan commands
│   ├── Core/                      # Custom domain layer (AI + Modules)
│   │   ├── AI/                    # AI abstraction layer
│   │   │   ├── Contracts/         # Provider interface
│   │   │   ├── Prompts/           # Prompt DTO classes
│   │   │   └── Providers/         # Claude, OpenAI implementations
│   │   └── Modules/               # Module-level orchestration services
│   │       ├── KnowledgeLibrary/  # Hazard template lookups
│   │       ├── OMManual/          # O&M generation orchestrator
│   │       ├── Projects/          # Project lifecycle service
│   │       ├── QuoteImport/       # Quote import pipeline
│   │       ├── RAMS/              # RAMS generation orchestrator
│   │       └── Survey/            # Survey management service
│   ├── Exceptions/                # Custom exception classes
│   ├── Http/
│   │   ├── Controllers/           # Web controllers
│   │   │   └── Admin/             # Admin-only controllers
│   │   ├── Middleware/            # Custom middleware
│   │   └── Requests/             # Form request validation
│   ├── Jobs/                      # Queue jobs (async document generation)
│   ├── Mail/                      # Mailable classes
│   ├── Models/                    # Eloquent models
│   ├── Policies/                  # Authorization policies
│   ├── Providers/                 # Service providers
│   ├── Services/                  # Single-responsibility service classes
│   │   └── AI/                    # (empty/unused — AI services are in Core/AI)
│   └── View/Components/           # Blade component classes
├── bootstrap/                     # Laravel bootstrap + app.php
├── config/                        # Configuration files (incl. ai.php, rams.php)
├── database/
│   ├── factories/                 # Model factories
│   ├── migrations/                # Database migrations
│   └── seeders/                   # Database seeders
├── public/                        # Web root + compiled assets
├── resources/
│   ├── css/                       # Source CSS
│   ├── js/                        # Source JavaScript
│   └── views/                     # Blade templates
├── routes/                        # Route definitions
├── storage/                       # File storage, logs, cache
├── tests/
│   ├── Feature/                   # Feature tests
│   └── Unit/                      # Unit tests
└── vendor/                        # Composer dependencies
```

## Directory Purposes

**`app/Core/AI/`:**
- Purpose: All AI provider interaction — the only place AI API calls are made
- Contains: `AIManager.php` (static facade), `Contracts/AIProviderContract.php` (interface), `Providers/` (Claude + OpenAI), `Prompts/` (typed prompt DTOs)
- Key rule: All modules MUST use `AIManager` — never instantiate providers directly

**`app/Core/Modules/`:**
- Purpose: Module-level orchestration — coordinates multiple services for a business operation
- Contains: One subdirectory per domain module, each with a primary service class
- Key files:
  - `QuoteImport/QuoteImportService.php` — orchestrates PDF → ProjectPackage
  - `RAMS/RamsGeneratorService.php` — orchestrates RAMS generation
  - `OMManual/OmManualGeneratorService.php` — orchestrates O&M generation
  - `Survey/SurveyService.php` — survey CRUD + photo management
  - `Projects/ProjectService.php` — project lifecycle + activity logging

**`app/Services/`:**
- Purpose: Single-responsibility services — each does one thing well
- Contains: ~50 service classes covering PDF extraction, parsing, classification, rendering, etc.
- Key distinction: Services here are lower-level than `Core/Modules/` — they are composed by module services

**`app/Http/Controllers/`:**
- Purpose: Thin HTTP handlers — validate, delegate, respond
- Contains: One controller per resource/feature area
- Sub-directory: `Admin/` for admin-only functionality (users, AI usage, solution types)

**`app/Models/`:**
- Purpose: Eloquent ORM models with relationships, status constants, scopes, lifecycle helpers
- Contains: 15 model classes representing the full domain

**`app/Jobs/`:**
- Purpose: Async queue jobs for long-running operations
- Contains: `ExtractRamsDraftJob.php`, `BuildRamsDocumentJob.php`, `BuildOmManualJob.php`

**`resources/views/`:**
- Purpose: Blade templates organized by feature area
- Contains: Subdirectories mirroring controller/route groupings

**`config/`:**
- Purpose: Application configuration
- Key custom files: `config/ai.php` (AI provider settings), `config/rams.php` (company identity for DOCX branding)

## Key File Locations

**Entry Points:**
- `routes/web.php`: All route definitions (no API routes exist)
- `routes/auth.php`: Authentication routes (Breeze)
- `bootstrap/app.php`: Application bootstrap, middleware registration

**Configuration:**
- `config/ai.php`: AI provider config (Claude/OpenAI keys, models, pricing)
- `config/rams.php`: Company name/short name for document branding
- `config/queue.php`: Queue driver config (used by async jobs)

**Core Logic (by pipeline):**

RAMS Pipeline:
- `app/Http/Controllers/QuoteUploadController.php`: PDF upload entry point
- `app/Jobs/ExtractRamsDraftJob.php`: Phase A — text extraction + local parsing
- `app/Services/QuoteTextExtractorService.php`: PDF text extraction
- `app/Services/RamsExtractionDraftBuilderService.php`: Parse + classify + risk resolve
- `app/Services/QuoteParserService.php`: QuoteWerks PDF text parser
- `app/Services/EquipmentClassifierService.php`: Equipment category classification
- `app/Services/RiskTemplateResolverService.php`: Map equipment to hazard templates
- `app/Http/Controllers/RamsReviewController.php`: Review/approve extracted data
- `app/Services/RamsReviewDataService.php`: Review data assembly
- `app/Services/RamsReviewValidatorService.php`: Review data validation
- `app/Jobs/BuildRamsDocumentJob.php`: Phase B — AI generation + DOCX render
- `app/Services/RamsBuilderService.php`: Generation orchestrator
- `app/Services/MethodStatementGeneratorService.php`: AI method statement
- `app/Services/RamsDataBuilderService.php`: Final data assembly
- `app/Services/RamsDocumentRendererService.php`: DOCX rendering dispatcher
- `app/Services/DocxBuilderService.php`: PhpWord DOCX construction

Quote Import Pipeline:
- `app/Http/Controllers/QuoteImportController.php`: Upload + import trigger
- `app/Core/Modules/QuoteImport/QuoteImportService.php`: Full pipeline orchestrator
- `app/Services/PdfTextExtractorService.php`: PDF text extraction (with OCR fallback)
- `app/Services/PdfOcrExtractorService.php`: Tesseract OCR fallback
- `app/Services/QuoteLineExtractorService.php`: Filter to equipment lines
- `app/Services/EquipmentLineParserService.php`: Parse individual equipment lines
- `app/Services/EquipmentNormalizerService.php`: Normalize equipment data
- `app/Core/AI/Prompts/QuoteExtractionPrompt.php`: AI prompt for structured extraction

Project Package Review:
- `app/Http/Controllers/ProjectPackageReviewController.php`: Review/edit extracted data
- `app/Services/ProjectPackageRamsReviewService.php`: Map package data to RAMS review schema

O&M Manual Pipeline:
- `app/Http/Controllers/OmManualController.php`: CRUD + generation trigger
- `app/Core/Modules/OMManual/OmManualGeneratorService.php`: Content generation
- `app/Jobs/BuildOmManualJob.php`: Async generation job
- `app/Services/OmManualDocxService.php`: DOCX rendering
- `app/Core/AI/Prompts/OmManualPrompt.php`: AI prompt

Site Survey:
- `app/Http/Controllers/SiteSurveyController.php`: Admin CRUD
- `app/Http/Controllers/PublicSurveyController.php`: Public engineer-facing form
- `app/Core/Modules/Survey/SurveyService.php`: Survey business logic

**Testing:**
- `tests/Unit/Rams/QuoteParserServiceTest.php`: Quote parser unit tests
- `tests/Unit/Services/`: Service unit tests
- `tests/Feature/Rams/`: RAMS feature tests
- `tests/Feature/Projects/`: Project feature tests

## Naming Conventions

**Files:**
- Models: `PascalCase.php` — e.g., `RamsDocument.php`, `SiteSurveyRoom.php`
- Controllers: `PascalCaseController.php` — e.g., `RamsController.php`, `QuoteImportController.php`
- Services: `PascalCaseService.php` — e.g., `RamsBuilderService.php`, `QuoteParserService.php`
- Jobs: `PascalCaseJob.php` — e.g., `BuildRamsDocumentJob.php`
- Prompts: `PascalCasePrompt.php` — e.g., `RamsPrompt.php`, `QuoteExtractionPrompt.php`
- Form Requests: `PascalCaseRequest.php` — e.g., `RamsFormRequest.php`
- Blade views: `kebab-case.blade.php` — e.g., `quote-review.blade.php`
- Blade partials: `_kebab-case.blade.php` — e.g., `_room-form.blade.php`
- Migrations: Laravel default `YYYY_MM_DD_HHMMSS_description.php`
- Legacy/backup files: Suffixed with date `DDMM` — e.g., `QuoteParserService2903.php`, `RamsReviewDataService2703.php`

**Directories:**
- View directories: `kebab-case/` matching route groups — e.g., `site-survey/`, `quote-import/`, `project-packages/`
- Module directories: `PascalCase/` — e.g., `QuoteImport/`, `OMManual/`, `RAMS/`

## Route Organization

All routes in `routes/web.php`, organized in this order:

1. **Public routes** (no auth): `survey/{token}/*` — UUID-gated site survey form
2. **Dashboard** (auth): `/dashboard`
3. **Profile** (auth): `/profile`
4. **Quote Import** (auth): `/quote-import/*`
5. **Project Package Review** (auth): `/project-packages/{id}/review`
6. **Projects** (auth): Resource + lifecycle transitions
7. **Admin-only** (auth + admin): `/rams/settings`, `/admin/users`, `/admin/ai-usage`, `/admin/solution-types`, `/admin/worker`
8. **RAMS** (auth): Upload, processing, resource CRUD, review, download, email, retry
9. **Hazard Templates** (auth): CRUD + API endpoint
10. **Cable Schedules** (auth): Resource CRUD
11. **Site Surveys** (auth): Resource CRUD + photos + PDF download
12. **O&M Manuals** (auth): Resource CRUD + generation + download

**Route naming convention:** `resource.action` — e.g., `rams.review`, `quote-import.store`, `site-surveys.photos.upload`

## View Organization

```
resources/views/
├── admin/                    # Admin pages
│   ├── ai-usage.blade.php
│   ├── solution-types/       # Solution type CRUD
│   ├── users/                # User management
│   └── worker.blade.php      # Queue worker monitor
├── auth/                     # Breeze auth pages
├── cable-schedule/           # Cable schedule CRUD
├── components/               # Reusable Blade components
│   └── dashboard/            # Dashboard-specific components
├── emails/                   # Email templates
├── hazard-templates/         # Hazard template CRUD
├── layouts/                  # Layout templates
│   ├── app.blade.php         # Main authenticated layout
│   ├── guest.blade.php       # Unauthenticated layout
│   └── navigation.blade.php  # Navigation partial
├── pdf/                      # PDF rendering templates
│   └── om-manual/            # O&M PDF views
├── profile/                  # Profile management
├── project-packages/         # Package review page
│   └── review.blade.php
├── projects/                 # Project CRUD pages
├── public-survey/            # Public survey form (no auth)
│   └── show.blade.php
├── quote-import/             # Quote import workflow
├── rams/                     # RAMS management pages
├── site-survey/              # Site survey CRUD
├── dashboard.blade.php       # Dashboard page
└── welcome.blade.php         # Landing page
```

**Layout hierarchy:** Views extend `layouts.app` (authenticated) or `layouts.guest` (public). Components in `components/` use Laravel's anonymous Blade component pattern. Dashboard components (`components/dashboard/`) provide reusable stat cards, tables, and status badges.

## Model Relationships Overview

```
User
 ├── hasMany → RamsDocument
 ├── hasMany → OmManual
 ├── hasMany → Project (via owner/user_id)
 └── hasMany → CableSchedule

Project
 ├── belongsTo → User (owner)
 ├── hasMany → ProjectPackage
 ├── hasMany → RamsDocument
 ├── hasMany → OmManual
 ├── hasMany → SiteSurvey
 ├── hasMany → CableSchedule
 ├── hasMany → ProjectQuote
 ├── hasMany → ProjectActivityLog
 ├── hasOne  → ProjectPackage (latestPackage)
 └── hasOne  → ProjectQuote (latestProjectQuote)

ProjectPackage
 ├── belongsTo → Project
 └── belongsTo → User (uploadedBy)

RamsDocument
 ├── belongsTo → User
 ├── belongsTo → Project
 ├── hasOne    → OmManual
 └── belongsTo → RamsDocument (supersededBy)

OmManual
 ├── belongsTo → User
 ├── belongsTo → Project
 └── belongsTo → RamsDocument

SiteSurvey
 ├── belongsTo → User
 ├── belongsTo → Project
 └── hasMany   → SiteSurveyRoom

SiteSurveyRoom
 ├── belongsTo → SiteSurvey
 └── hasMany   → SiteSurveyPhoto

SiteSurveyPhoto
 └── belongsTo → SiteSurveyRoom

CableSchedule
 ├── belongsTo → User
 └── hasMany   → CableScheduleItem

CableScheduleItem
 └── belongsTo → CableSchedule

HazardTemplate
 └── belongsTo → User

ProjectQuote
 ├── belongsTo → Project
 └── belongsTo → User (uploadedBy)

ProjectActivityLog
 ├── belongsTo → Project
 └── belongsTo → User

SolutionType (standalone — no relationships)
AIUsage (standalone — no relationships)
```

## Migration History

Migrations span `2026-03-04` to `2026-04-06`, indicating a ~1 month build:

- **Core tables:** `users`, `cache`, `jobs` (Laravel defaults)
- **RAMS:** `rams_documents` with iterative additions (status, review columns, upload columns, error_message, soft deletes)
- **Projects:** `projects`, `project_packages`, `project_activity_log`, `project_quotes`
- **Surveys:** `site_surveys`, `site_survey_rooms` (with infrastructure fields), `site_survey_photos`; token fields + type fields added later
- **O&M:** `om_manuals` with `source_path` and `error_message` additions
- **Cable Schedules:** `cable_schedules` (with soft deletes)
- **Hazard Templates:** `hazard_templates`
- **AI Infrastructure:** `ai_cache` (with `expires_at`), `ai_usages`
- **Admin:** `solution_types`
- **Cross-cutting:** Soft deletes added to projects, cable schedules, site surveys, om manuals; `is_active` added to users; `project_id` added to module tables

**Pattern:** Multiple duplicate/superseded migration files exist (e.g., multiple `create_hazard_templates_table` and `create_cable_schedules_table`), suggesting schema was rebuilt during development. The authoritative set starts at `2026_03_14_*`.

## Where to Add New Code

**New Feature/Module:**
- Create module directory: `app/Core/Modules/{ModuleName}/`
- Create orchestration service: `app/Core/Modules/{ModuleName}/{ModuleName}Service.php`
- Create model: `app/Models/{ModelName}.php`
- Create controller: `app/Http/Controllers/{ModuleName}Controller.php`
- Create views: `resources/views/{module-name}/`
- Add routes to `routes/web.php` in the authenticated group
- Add migration: `database/migrations/`

**New AI Prompt:**
- Create prompt class: `app/Core/AI/Prompts/{Purpose}Prompt.php` extending `BasePrompt`
- Implement `build(array $context)`, optionally override `systemMessage()`, `maxTokens()`, `temperature()`
- Call via `AIManager::run(new {Purpose}Prompt(), $context)`

**New Service:**
- Create in `app/Services/{PurposeService}.php`
- Use constructor injection (Laravel auto-resolves from container)
- If it orchestrates multiple services for a module, place in `app/Core/Modules/{Module}/`

**New Queue Job:**
- Create in `app/Jobs/{ActionJob}.php` implementing `ShouldQueue`
- Follow the pattern: guard checks → status update → service call → status completion → catch/fail hooks
- Set `$tries` and `$timeout` properties

**New Admin Feature:**
- Controller in `app/Http/Controllers/Admin/`
- Views in `resources/views/admin/{feature}/`
- Routes inside the `Route::middleware('admin')` group in `routes/web.php`

**New Blade Component:**
- Anonymous: `resources/views/components/{name}.blade.php`
- Class-backed: `app/View/Components/{Name}.php` + `resources/views/components/{name}.blade.php`

## Special Directories

**`app/private/`:**
- Purpose: Runtime file storage for uploads (quote imports, site surveys, temp files)
- Generated: Yes (by application at runtime)
- Committed: No (should be in .gitignore)

**`app/rams/`:**
- Purpose: Generated RAMS document storage
- Generated: Yes
- Committed: No

**`app/tmp/` and `app/private/tmp/`:**
- Purpose: Temporary processing files (e.g., PDF OCR intermediates)
- Generated: Yes
- Committed: No

**`database/seeders/`:**
- Purpose: Seed data for hazard templates and solution types
- Key files: `HazardTemplateSeeder.php`, `SolutionTypeSeeder.php`
- Committed: Yes

**`sonny/` and `tools/`:**
- Purpose: Developer scratch/utility directories
- Committed: Likely developer-local only

**Legacy/Backup Files:**
- Pattern: Service files suffixed with date codes (e.g., `QuoteParserService2903.php`, `RamsReviewDataService2703.php`, `OmManualDocxService2703.php`)
- Purpose: Manual version snapshots before refactoring
- Location: Alongside the current versions in `app/Services/` and `app/Core/Modules/`

---

*Structure analysis: 2026-04-09*

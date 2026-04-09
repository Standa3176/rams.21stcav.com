# Technology Stack

**Analysis Date:** 2026-04-09

## Languages

**Primary:**
- PHP ^8.2 - All backend logic, controllers, services, models, jobs

**Secondary:**
- JavaScript (ES modules) - Alpine.js frontend interactivity (`resources/js/app.js`)
- CSS - Tailwind utility classes (`resources/css/app.css`)
- Blade - Server-side templating (`resources/views/**/*.blade.php`)

## Runtime

**Environment:**
- PHP 8.2+ (required by `composer.json`)
- Node.js (for Vite build toolchain; version not pinned, no `.nvmrc`)

**Package Manager:**
- Composer (PHP) - `composer.json`, `composer.lock` present
- npm (JS) - `package.json`, lockfile expected

## Frameworks

**Core:**
- Laravel ^12.0 (`laravel/framework`) - Full-stack MVC framework
- Laravel Breeze ^2.4 (dev) - Authentication scaffolding (login, register, password reset)

**Testing:**
- PHPUnit ^11.5.3 - Unit and feature tests
- Mockery ^1.6 - Test doubles / mocking
- FakerPHP ^1.23 - Test data generation

**Build/Dev:**
- Vite ^7.0.7 via `laravel-vite-plugin` ^2.0.0 - Asset bundling (`vite.config.js`)
- Tailwind CSS ^3.1.0 with `@tailwindcss/forms` ^0.5.2 - Utility-first CSS
- PostCSS ^8.4.31 + Autoprefixer ^10.4.2 - CSS post-processing
- Laravel Pint ^1.24 (dev) - PHP code style fixer (PSR-12 based)
- Laravel Sail ^1.41 (dev) - Docker dev environment
- Laravel Pail ^1.2.2 (dev) - Real-time log viewer
- Concurrently ^9.0.1 (dev) - Parallel process runner for `composer dev`

## Frontend Stack

**Templating:** Blade (server-rendered)
- Views located in `resources/views/`
- PDF-specific templates in `resources/views/pdf/`

**CSS Framework:** Tailwind CSS 3.x
- Config: `tailwind.config.js`
- PostCSS: `postcss.config.js`
- Font: Figtree (extends default sans-serif)
- Plugin: `@tailwindcss/forms` for form element styling

**JavaScript Framework:** Alpine.js ^3.4.2
- Lightweight reactive framework for UI interactivity
- Initialized in `resources/js/app.js`
- HTTP client: Axios ^1.11.0 (imported via `resources/js/bootstrap.js`)

**No SPA/Livewire/Inertia** - This is a traditional server-rendered Blade application with Alpine.js for interactivity.

## Key Dependencies

**Critical (Production):**
- `laravel/framework` ^12.0 - Core application framework
- `guzzlehttp/guzzle` ^7.10 - HTTP client for external API calls (AI providers)

**PDF Processing (Production):**
- `barryvdh/laravel-dompdf` ^3.1 - Renders Blade views to PDF (RAMS and Site Survey downloads)
- `dompdf/dompdf` ^3.1 - Core PDF rendering engine (used by barryvdh wrapper)
- `mpdf/mpdf` ^8.3 - O&M Manual PDF export (full CSS support for complex layouts)
- `smalot/pdfparser` ^2.12 - Primary PHP-native PDF text extraction (`app/Services/PdfTextExtractorService.php`)
- `spatie/pdf-to-text` ^1.54 - Fallback PDF text extraction using `pdftotext` binary

**Document Generation (Production):**
- `phpoffice/phpword` ^1.4 - DOCX document generation (`app/Services/DocxBuilderService.php`)

**Database (Production):**
- `doctrine/dbal` ^4.4 - Database abstraction for migrations and schema introspection

## Database

**Engine:** MySQL (configured in `.env.example` as `DB_CONNECTION=mysql`)
- Host: `127.0.0.1:3306`
- Database name: `laravel_rams`
- Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`
- Config: `config/database.php`

**SQLite** is the framework default fallback but MySQL is the project default.

## Queue / Cache / Session Drivers

**Queue:**
- Driver: `database` (default)
- Table: `jobs`
- Failed jobs: `failed_jobs` table (database-uuids driver)
- Job batching: `job_batches` table
- Config: `config/queue.php`
- Dev runner: `php artisan queue:listen --tries=1 --timeout=0`

**Cache:**
- Driver: `database` (default)
- Table: `cache`
- AI response caching uses a separate `ai_cache` table with SHA-256 hash keys and configurable TTL (`app/Services/AICacheService.php`)
- Config: `config/cache.php`

**Session:**
- Driver: `database` (default)
- Table: `sessions`
- Lifetime: 120 minutes
- Config: `config/session.php`

**Redis:** Configured but not used by default. Available as alternative for queue/cache/session.

## AI / ML Integration

**Provider Abstraction Layer:**
- Contract: `app/Core/AI/Contracts/AIProviderContract.php`
- Supports two providers, swappable via `AI_DEFAULT_PROVIDER` env var

**Claude (Anthropic) - Default:**
- Provider: `app/Core/AI/Providers/ClaudeProvider.php`
- Model: `claude-sonnet-4-6` (configurable via `CLAUDE_MODEL`)
- Endpoint: `https://api.anthropic.com/v1/messages`
- Supports native PDF document vision (base64-encoded PDFs)
- Anthropic API version: `2023-06-01`

**OpenAI - Alternative:**
- Provider: `app/Core/AI/Providers/OpenAIProvider.php`
- Model: `gpt-4o` (configurable via `OPENAI_MODEL`)
- Endpoint: `https://api.openai.com/v1/chat/completions`
- Uses `response_format: json_object` for structured output
- PDF support via data URI encoding

**Prompt DTOs:**
- Base class: `app/Core/AI/Prompts/BasePrompt.php`
- Domain-specific prompts in `app/Core/AI/Prompts/`:
  - `RamsPrompt.php` - RAMS document generation
  - `MethodStatementPrompt.php` - Method statement generation
  - `QuoteExtractionPrompt.php` - Quote data extraction
  - `SurveyPrompt.php` - Site survey analysis
  - `CableSchedulePrompt.php` - Cable schedule generation
  - `OmManualPrompt.php` - O&M manual generation
  - `RoomOverviewSummaryPrompt.php` - Room overview summaries
  - `ScopeOfWorksPrompt.php` - Scope of works generation

**Usage Tracking:**
- Service: `app/Services/AIUsageService.php`
- Model: `app/Models/AIUsage.php`
- Tracks provider, model, prompt class, input/output/total tokens, estimated cost (USD)
- Cost estimation uses configurable per-1K-token pricing in `config/ai.php`

**Response Caching:**
- Service: `app/Services/AICacheService.php`
- Database-backed (`ai_cache` table), SHA-256 prompt hashing
- Configurable TTL: `AI_CACHE_TTL_DAYS` (default 30 days)
- Prune command: `php artisan ai:cache-prune`

## OCR / External Binary Dependencies

**Tesseract OCR:**
- Used by `app/Services/PdfOcrExtractorService.php` as last-resort PDF text extraction
- Requires: `tesseract-ocr` (apt package), Tesseract 4+
- Language: English (`-l eng --psm 6`)

**Poppler Utils:**
- Used by `PdfOcrExtractorService.php` for PDF-to-image conversion (`pdftoppm`)
- Requires: `poppler-utils` (apt package)
- Also required by `spatie/pdf-to-text` (`pdftotext` binary)

## File Storage

**Default:** Local filesystem (`FILESYSTEM_DISK=local`)
- Private storage: `storage/app/private/`
- Public storage: `storage/app/public/` (symlinked to `public/storage`)
- S3 configured but not active by default
- Config: `config/filesystems.php`

## Authentication

**Guard:** Session-based (`web` guard)
- Driver: Eloquent user provider
- Scaffolded by Laravel Breeze
- Policies: `RamsDocumentPolicy`, `OmManualPolicy` (registered in `app/Providers/AppServiceProvider.php`)

## Logging

**Channel:** `stack` (default), delegates to `single` file channel
- Config: `config/logging.php`
- Dev: Laravel Pail for real-time log tailing

## Development Workflow

**Start dev environment:**
```bash
composer dev
```
Runs concurrently:
1. `php artisan serve` - Laravel dev server
2. `php artisan queue:listen --tries=1 --timeout=0` - Queue worker
3. `php artisan pail --timeout=0` - Log viewer
4. `npm run dev` - Vite HMR dev server

**Run tests:**
```bash
composer test
```

**Initial setup:**
```bash
composer setup
```

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

---

*Stack analysis: 2026-04-09*

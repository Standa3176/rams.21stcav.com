# External Integrations

**Analysis Date:** 2026-04-09

## APIs & External Services

### AI Providers

**Anthropic Claude (Default):**
- Purpose: JSON-structured AI completions for RAMS generation, quote extraction, method statements, surveys, cable schedules, O&M manuals, room summaries, scope of works
- SDK/Client: Direct HTTP via `Illuminate\Support\Facades\Http` (Guzzle)
- Implementation: `app/Core/AI/Providers/ClaudeProvider.php`
- Endpoint: `https://api.anthropic.com/v1/messages`
- Auth: `CLAUDE_API_KEY` env var
- Model: `CLAUDE_MODEL` env var (default: `claude-sonnet-4-6`)
- Features: Native PDF document vision (base64), JSON response parsing, markdown fence stripping, newline sanitization
- Timeout: 180 seconds default
- API version header: `anthropic-version: 2023-06-01`

**OpenAI (Alternative):**
- Purpose: Same as Claude -- swappable via `AI_DEFAULT_PROVIDER` env var
- SDK/Client: Direct HTTP via `Illuminate\Support\Facades\Http` (Guzzle)
- Implementation: `app/Core/AI/Providers/OpenAIProvider.php`
- Endpoint: `OPENAI_ENDPOINT` env var (default: `https://api.openai.com/v1/chat/completions`)
- Auth: `OPENAI_API_KEY` env var
- Model: `OPENAI_MODEL` env var (default: `gpt-4o`)
- Features: `response_format: json_object` for structured output, PDF via data URI
- Timeout: 180 seconds default

**Provider Selection:**
- Config: `config/ai.php` (`ai.default` key)
- Env var: `AI_DEFAULT_PROVIDER` (values: `claude` or `openai`)
- Contract: `app/Core/AI/Contracts/AIProviderContract.php`
- All domain services use the contract, making providers fully interchangeable

### AI Prompt Classes (Domain-Specific)

Each prompt class in `app/Core/AI/Prompts/` builds structured prompts for a specific domain:

| Prompt Class | File | Purpose |
|---|---|---|
| `BasePrompt` | `app/Core/AI/Prompts/BasePrompt.php` | Abstract base with system message, max tokens, PDF support |
| `RamsPrompt` | `app/Core/AI/Prompts/RamsPrompt.php` | RAMS document content generation |
| `MethodStatementPrompt` | `app/Core/AI/Prompts/MethodStatementPrompt.php` | Method statement generation |
| `QuoteExtractionPrompt` | `app/Core/AI/Prompts/QuoteExtractionPrompt.php` | Structured data extraction from quotes |
| `SurveyPrompt` | `app/Core/AI/Prompts/SurveyPrompt.php` | Site survey analysis |
| `CableSchedulePrompt` | `app/Core/AI/Prompts/CableSchedulePrompt.php` | Cable schedule generation |
| `OmManualPrompt` | `app/Core/AI/Prompts/OmManualPrompt.php` | O&M manual content |
| `RoomOverviewSummaryPrompt` | `app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php` | Room overview summaries |
| `ScopeOfWorksPrompt` | `app/Core/AI/Prompts/ScopeOfWorksPrompt.php` | Scope of works generation |

## Data Storage

### Database

**MySQL:**
- Primary data store for all application data
- Connection: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` env vars
- Default database: `laravel_rams`
- Also used for: queue jobs, cache, sessions, failed jobs, job batches
- Config: `config/database.php`

**AI-Specific Tables:**
- `ai_cache` - Cached AI responses keyed by SHA-256 prompt hash (`app/Services/AICacheService.php`)
- `ai_usages` - Token usage tracking per request (`app/Services/AIUsageService.php`, `app/Models/AIUsage.php`)

### File Storage

**Local Filesystem (Default):**
- Private: `storage/app/private/` - PDF files, generated documents
- Public: `storage/app/public/` - Publicly accessible via `public/storage` symlink
- Config: `config/filesystems.php`
- Disk: `FILESYSTEM_DISK=local`

**AWS S3 (Configured, Not Active):**
- Config present in `config/filesystems.php`
- Env vars: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`
- Not the default disk; available for future use

### Caching

**Database Cache:**
- Default cache store (`CACHE_STORE=database`)
- Table: `cache`
- Config: `config/cache.php`

**AI Response Cache:**
- Separate system from Laravel cache
- Table: `ai_cache`
- Service: `app/Services/AICacheService.php`
- TTL: `AI_CACHE_TTL_DAYS` (default 30 days)
- Hash algorithm: SHA-256
- Prune command: `php artisan ai:cache-prune`

## Authentication & Identity

**Auth Provider:** Laravel Breeze (session-based)
- Guard: `web` (session driver)
- User provider: Eloquent
- Config: `config/auth.php`
- Scaffolding: `laravel/breeze` ^2.4

**Authorization Policies:**
- `app/Policies/RamsDocumentPolicy.php` - Controls RAMS document access
- `app/Policies/OmManualPolicy.php` - Controls O&M manual access
- Registered in: `app/Providers/AppServiceProvider.php`

**No external auth providers** (no OAuth, SSO, or third-party identity services).

## PDF Generation & Processing

### PDF Output (Generation)

**DomPDF (RAMS + Site Survey PDFs):**
- Package: `barryvdh/laravel-dompdf` ^3.1
- Service: `app/Services/PdfService.php`
- Renders Blade templates (`resources/views/pdf/*.blade.php`) to PDF
- Paper: A4 portrait
- Font: DejaVu Sans
- Output: `storage/app/private/rams/` and `storage/app/private/om-manuals/`

**mPDF (O&M Manuals):**
- Package: `mpdf/mpdf` ^8.3
- Used for complex CSS layout support in O&M manual exports
- Service: `app/Services/OmManualDocxService.php`

**PhpWord (DOCX Generation):**
- Package: `phpoffice/phpword` ^1.4
- Service: `app/Services/DocxBuilderService.php` - RAMS DOCX output
- Service: `app/Services/SiteSurveyDocxService.php` - Site survey DOCX
- Service: `app/Services/OmManualDocxService.php` - O&M manual DOCX
- Template processing via `PhpWord\TemplateProcessor`

### PDF Input (Text Extraction)

**Smalot PDF Parser (Primary):**
- Package: `smalot/pdfparser` ^2.12
- Service: `app/Services/PdfTextExtractorService.php`
- PHP-native, no system binary required
- Singleton registration in `app/Providers/AppServiceProvider.php` with encryption ignore

**Raw Stream Extraction (Secondary Fallback):**
- Built into `app/Services/PdfTextExtractorService.php`
- Extracts literal strings directly from PDF byte streams
- Triggers when Smalot output is < 50 characters

**Tesseract OCR (Tertiary Fallback):**
- Service: `app/Services/PdfOcrExtractorService.php`
- System binaries required: `tesseract` (4+), `pdftoppm` (Poppler)
- Converts PDF to PNG at 200 DPI, then OCRs each page
- Triggers when all text extraction produces < 200 usable characters
- Language: English (`-l eng --psm 6`)

**Extraction Pipeline Order:**
1. Smalot PDF Parser (PHP-native)
2. Raw PDF literal string extraction
3. Tesseract OCR via Poppler pdftoppm
- Each stage includes quality scoring (minimum character thresholds, human-readability heuristics, PDF structural noise detection)

## Email / Notifications

**Mail:**
- Default mailer: `log` (emails logged, not sent)
- SMTP configured but defaults to localhost:2525
- Config: `config/mail.php`
- No production mail service configured by default

**Slack (Configured in services):**
- Config present in `config/services.php`
- Env vars: `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL`
- Not actively used in application code (placeholder config)

## Monitoring & Observability

**Error Tracking:**
- None (no Sentry, Bugsnag, or similar)

**Logs:**
- Channel: `stack` -> `single` file (`storage/logs/laravel.log`)
- Dev tool: Laravel Pail for real-time log tailing
- Config: `config/logging.php`

**AI Usage Monitoring:**
- Model: `app/Models/AIUsage.php`
- Service: `app/Services/AIUsageService.php`
- Tracks per-request: provider, model, prompt class, input/output/total tokens, estimated cost (USD)
- Cost calculation: configurable per-provider per-1K-token rates in `config/ai.php`

## CI/CD & Deployment

**Hosting:** Not configured in codebase (no Dockerfile, no deployment configs detected)

**CI Pipeline:** Not detected (no `.github/workflows/`, no `Jenkinsfile`, no `.gitlab-ci.yml`)

**Docker:** Laravel Sail available as dev dependency for local Docker environments

## Queue & Background Jobs

**Driver:** Database (`QUEUE_CONNECTION=database`)
- Table: `jobs`
- Config: `config/queue.php`

**Job Classes:**
- `app/Jobs/BuildRamsDocumentJob.php` - Async RAMS document generation
- `app/Jobs/BuildOmManualJob.php` - Async O&M manual generation
- `app/Jobs/ExtractRamsDraftJob.php` - Async RAMS draft extraction

**Dev Worker:** `php artisan queue:listen --tries=1 --timeout=0` (run via `composer dev`)

## Environment Configuration

**Required env vars for core functionality:**
- `APP_KEY` - Application encryption key
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` - MySQL connection
- `CLAUDE_API_KEY` or `OPENAI_API_KEY` - At least one AI provider key
- `AI_DEFAULT_PROVIDER` - Which AI provider to use (`claude` or `openai`)

**Optional env vars:**
- `CLAUDE_MODEL` - Override Claude model (default: `claude-sonnet-4-6`)
- `OPENAI_MODEL` - Override OpenAI model (default: `gpt-4o`)
- `AI_CACHE_TTL_DAYS` - AI cache lifetime in days (default: 30)
- `RAMS_COMPANY_NAME` - Company name in generated documents (default: "21st Century AV Ltd")
- `RAMS_COMPANY_SHORT` - Company abbreviation (default: "21CAV")
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` - S3 storage (if enabled)

**Secrets location:**
- `.env` file (gitignored)
- `.env.example` provides template with all required keys

## Webhooks & Callbacks

**Incoming:** None detected

**Outgoing:** None detected (AI provider calls are request/response, not webhook-based)

## Third-Party Service Summary

| Service | Status | Purpose | Auth Env Var |
|---------|--------|---------|-------------|
| Anthropic Claude API | Active (default) | AI completions + PDF vision | `CLAUDE_API_KEY` |
| OpenAI API | Available (alternative) | AI completions | `OPENAI_API_KEY` |
| MySQL | Active | Primary database | `DB_PASSWORD` |
| AWS S3 | Configured, inactive | File storage | `AWS_SECRET_ACCESS_KEY` |
| Tesseract OCR | Optional system binary | PDF OCR fallback | N/A (system binary) |
| Poppler Utils | Optional system binary | PDF to image conversion | N/A (system binary) |
| Slack | Configured, unused | Notifications | `SLACK_BOT_USER_OAUTH_TOKEN` |
| SMTP Mail | Configured, defaults to log | Email | `MAIL_PASSWORD` |

---

*Integration audit: 2026-04-09*

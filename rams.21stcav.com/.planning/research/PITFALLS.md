# Domain Pitfalls

**Domain:** AV Operations Platform — MS SQL Integration, Unified Data Model, Multi-Format Document Generation
**Researched:** 2026-04-09
**Confidence:** HIGH (derived from codebase analysis + established Laravel/SQL Server patterns)

---

## Critical Pitfalls

Mistakes in this category cause rewrites, data loss, or silent corruption of existing documents.

---

### Pitfall C1: MS SQL Driver Mismatch Between Dev and Production

**What goes wrong:** The `sqlsrv` PHP extension (Windows-native) and `pdo_dblib` (Linux/FreeBSD via unixODBC) are entirely different drivers. Code that works on a Windows dev machine using `sqlsrv` will silently fail to connect on a Linux production server that lacks the Microsoft ODBC Driver for SQL Server. The default Laravel `sqlsrv` connection stanza in `config/database.php` assumes the Windows driver. The production environment is unverified.

**Why it happens:** The QuoteWerks SQL server is Windows-based (QuoteWerks is a Windows application). Developers test on Windows. The production server may be Linux (common for Laravel deployments). The two environments require completely different PHP extensions, and the failure mode at connection time is a generic PDO exception — not a driver-specific error message.

**Consequences:** All `QuoteWerksImportService` calls fail in production. Worse, if the connection is wrapped in a try/catch that returns empty data, the failure silently degrades to the PDF fallback with no alert to operators.

**Warning signs:**
- `could not find driver` or `SQLSTATE[HY000]` errors in production logs while dev works fine
- PHP info page shows `sqlsrv` loaded on Windows but no `pdo_odbc` or `pdo_dblib` on Linux
- CI/CD pipeline has no ODBC driver installation step

**Prevention:**
- Before writing `QuoteWerksImportService`, verify the production PHP environment: run `php -m | grep -i sql` on the production server.
- If Linux: install `msodbcsql18` + `php8.2-sqlsrv` (Microsoft's official Linux ODBC driver). Document this as a server prerequisite.
- If using `pdo_dblib` (older approach via FreeTDS): note that it does not support Windows Authentication and has known charset issues with QuoteWerks data (often Windows-1252 encoded).
- Add a `QUOTEWERKS_DB_DRIVER` env var and validate it on first connection attempt with a meaningful error message, not a generic PDO exception.
- Add a health-check artisan command (`php artisan quotewerks:ping`) that tests the connection independently of document generation.

**Phase:** QWSQL-01 — must be resolved before any other QuoteWerks SQL work begins.

---

### Pitfall C2: Trust Server Certificate Not Set — Encrypted Connection Rejected

**What goes wrong:** Modern SQL Server instances (2016+) default to requiring encrypted connections. PHP's `sqlsrv` driver also defaults to requesting encryption. When the QuoteWerks SQL Server uses a self-signed certificate (common for internal company servers), the connection is refused with a certificate verification error unless `TrustServerCertificate=true` is set.

**Why it happens:** The `config/database.php` `sqlsrv` stanza in this codebase has `encrypt` and `trust_server_certificate` commented out. If the QuoteWerks SQL Server uses a self-signed cert (very likely for an internal Windows Server install), every connection will fail.

**Consequences:** Connection always fails in production. The error message (`SSL: Fatal error`) is obscure enough that developers spend hours debugging network/VPN issues before realising it is a certificate trust issue.

**Warning signs:**
- Connection works with SQL Server Management Studio (SSMS) — which defaults to trusting certs — but fails from PHP
- Error message contains `SSL` or `certificate` or `Encryption` in PDO exception text

**Prevention:**
- Set these in the `sqlsrv` connection config (driven by env vars):
  ```php
  'encrypt' => env('QUOTEWERKS_DB_ENCRYPT', 'true'),
  'trust_server_certificate' => env('QUOTEWERKS_DB_TRUST_CERT', 'false'),
  ```
- For internal VPN-only connections, `trust_server_certificate=true` is acceptable. Document this explicitly — it is intentional, not a security oversight.
- Use a named connection (`quotewerks`) rather than the default `sqlsrv` connection so these settings never bleed into the primary MySQL connection.

**Phase:** QWSQL-01.

---

### Pitfall C3: QuoteWerks Column Names Break Laravel Query Builder Assumptions

**What goes wrong:** QuoteWerks uses a legacy MS SQL schema with column names that are reserved words in SQL Server (e.g., `Date`, `Name`, `Type`, `Group`), contain spaces, or use inconsistent casing. Laravel's query builder does not quote identifiers by default for `sqlsrv`. Raw column names in `->select()` or `->where()` clauses fail with syntax errors or return wrong results.

**Why it happens:** QuoteWerks was not designed for external query access. Its schema reflects 1990s-era naming conventions. The `sqlsrv` driver is case-insensitive for identifiers but SQL Server reserved words still require bracket-quoting: `[Date]`, `[Name]`, `[Type]`.

**Consequences:** `->where('Type', 'HW')` fails silently or returns empty. `->select(['Date', 'Name'])` fails with a syntax error. These bugs are not caught by unit tests because tests mock the connection.

**Warning signs:**
- Queries against QuoteWerks tables return empty collections or syntax errors
- Column names in query results are all lowercase when the schema uses PascalCase

**Prevention:**
- Always use bracket-quoted identifiers when querying the QuoteWerks database: `->select(['[DocNo]', '[DocDate]', '[ItemType]'])`.
- Wrap all QuoteWerks queries in a dedicated `QuoteWerksRepository` class. Never put raw QuoteWerks column names outside that class. This isolates the quoting concern.
- Test against the actual QuoteWerks schema — do not assume column names match the documented QuoteWerks API fields.
- Map all column names to internal canonical names immediately in the repository, so `QuoteWerksImportService` never sees a QuoteWerks column name.

**Phase:** QWSQL-02, QWSQL-03.

---

### Pitfall C4: ProjectDataService Becomes a Second God Class

**What goes wrong:** `ProjectDataService` (DATA-01) is designed as the single merge point for all data sources. Without strict discipline, every generator starts adding special-case logic to it: "the worksheet needs this extra field", "the O&M generator needs this restructured". The service grows to 2,000+ lines and develops the same pathologies as the existing `QuoteParserService` (2,938 lines) and `RamsController` (746 lines).

**Why it happens:** It is the easiest place to add data. Controllers and jobs already reference it. There is no mechanical barrier to adding generator-specific logic there.

**Consequences:** Data mutations intended for one generator corrupt the data for another. The canonical data structure (DATA-02) drifts from what generators actually receive. The existing `RamsDataBuilderService` — which already does typed normalisation — becomes redundant or conflicts with `ProjectDataService` if responsibilities are not clearly separated.

**Warning signs:**
- `ProjectDataService` has an `if ($generator === 'worksheet')` branch
- Any generator imports `ProjectDataService` AND also accesses `$ramsDocument->reviewed_data` directly
- The return type of `ProjectDataService::build()` changes between calls without versioning

**Prevention:**
- `ProjectDataService` returns one canonical structure and NOTHING ELSE. It never knows which generator is calling it.
- Generators are allowed to have their own adapter/transformer that receives the canonical structure and reshapes it for their specific template needs. These adapters live in the generator's namespace, not in `ProjectDataService`.
- Write the canonical data structure (DATA-02) as a PHP DTO or a typed array with documented keys BEFORE implementing `ProjectDataService`. The structure is the contract.
- `RamsDataBuilderService` already does typed normalisation. Decide explicitly: does `ProjectDataService` replace it, wrap it, or feed into it? This decision must be made at the start of the DATA-01 phase. Leaving it ambiguous guarantees conflicts.

**Phase:** DATA-01, DATA-02 — design decision must precede implementation.

---

### Pitfall C5: Data Merge Priority Is Undefined — Silent Overwrite Wins

**What goes wrong:** When merging `extracted_data` (PDF parse), `reviewed_data` (human-reviewed), `quotewerks_data` (SQL import), and `survey_data` (site survey), there are fields that exist in multiple sources with different values. If the merge priority is not explicitly defined and enforced, the last writer wins — silently discarding human corrections.

**Concrete example in this codebase:** `RamsDataBuilderService::resolveProjectFields()` already implements form-data-overrides-parsed priority. If `ProjectDataService` re-merges these same fields from SQL import data without respecting that existing priority, a SQL import can overwrite a human-corrected project name.

**Why it happens:** Each integration phase adds a new data source without re-specifying the full priority chain for all fields.

**Consequences:** Human-reviewed data is silently overwritten by imported data. Document generation produces documents with wrong project details. The bug only appears on regeneration, not on first generation.

**Warning signs:**
- Project name or client name in generated DOCX does not match what the reviewer entered
- Re-generating a document after a QuoteWerks sync changes previously correct values

**Prevention:**
- Define and document the merge priority chain at the start of DATA-01:
  `reviewed_data (human) > survey_data (human) > quotewerks_sql > extracted_data (PDF) > defaults`
- `ProjectDataService` must honour `reviewed_data` as the highest-priority source for every field it appears in.
- Add a `data_source` and `data_locked` flag per field where human review has explicitly set a value. A locked field is never overwritten by automated sources.
- The existing `data_confidence` tracking (DATA-04) is a good mechanism for this — use it.

**Phase:** DATA-01, DATA-03.

---

### Pitfall C6: Migrations That Add Non-Nullable Columns to Tables With Data

**What goes wrong:** This codebase already has 46 migrations and a live RAMS pipeline. Adding a non-nullable column without a default (or adding a foreign key without handling orphans) fails on the live database. This is the most common cause of "migration worked in dev, broke in production" failures.

**Existing evidence:** The migration history shows at least two attempts at the same table (`2026_03_09_000002` and `2026_03_09_000003` both named `create_cable_schedules_table`, `create_site_surveys_table`). This pattern of duplicate migration timestamps indicates migrations have been rolled back and re-created rather than added additively — a risky practice on a live system.

**Consequences:** A failed migration in production leaves the schema in a partial state. The `migrations` table records which migrations ran, but if a migration partially executes before failure, the schema is inconsistent. `php artisan migrate` will refuse to run until the partial migration is cleaned up manually.

**Warning signs:**
- Any new migration that uses `->notNull()` without `->default(...)` on a table that already has rows
- Migrations that use `Schema::table()` to add a foreign key without first verifying all existing rows have a valid parent

**Prevention:**
- All new columns on existing tables: use `->nullable()` or `->default(value)` in the migration. Handle the null case in the application layer.
- New `project_id` foreign key on existing tables (PROJ-02): add as `->nullable()` first. Backfill in a separate migration or seeder. Then add the constraint. Never in one step.
- Never reuse migration timestamps. Each migration file gets a unique timestamp. If a migration was wrong, write a NEW migration that corrects it — do not delete and recreate.
- Test migrations against a copy of the production database schema (with `--pretend` first, then on a staging database) before deploying.

**Phase:** PROJ-01, PROJ-02 — these create the `projects` table and re-link all existing tables.

---

## Moderate Pitfalls

---

### Pitfall M1: Named Connection Not Used — QuoteWerks Queries Hit Primary Database

**What goes wrong:** Laravel's `DB::table()` and Eloquent models use the default connection unless explicitly overridden. If `QuoteWerksImportService` uses `DB::table('DocumentItems')` without `->connection('quotewerks')`, it queries the primary MySQL database (which has no such table) and throws a confusing "table not found" error — or worse, silently returns empty if exception handling is overly broad.

**Prevention:**
- All QuoteWerks queries must use `DB::connection('quotewerks')->table(...)`. No exceptions.
- Create a `QuoteWerksRepository` class that injects the named connection and is the ONLY place where QuoteWerks queries are written.
- Add a test that asserts `QuoteWerksRepository` uses the `quotewerks` connection, not the default.

**Phase:** QWSQL-01, QWSQL-02.

---

### Pitfall M2: VPN Dependency Makes Queued Jobs Silently Fail

**What goes wrong:** QuoteWerks SQL is only accessible via VPN. Queued jobs (dispatched via `BuildRamsDocumentJob` pattern) run asynchronously. If the VPN connection drops between job dispatch and execution, the job fails with a connection timeout — but the failure may not surface clearly to the user because the job system (currently database-backed with single `queue:listen`) may re-attempt the job without re-establishing context.

**Why it matters here:** The existing queue has `--tries=1 --timeout=0` in dev (composer.json). Production queue configuration is not visible in the codebase. `BuildRamsDocumentJob` has `$tries = 2` and `$timeout = 180`. If the VPN drops, two retries × 180 seconds = 6 minutes of worker blocking before the job is marked failed.

**Prevention:**
- Test the QuoteWerks SQL connection at the start of any job that needs it, before doing any other work. Fail fast with a clear error: "QuoteWerks database unreachable — check VPN".
- Keep QuoteWerks import as a synchronous, user-triggered action (controller method with a spinner) rather than a background job wherever possible. Background jobs are appropriate for document generation, not for read-only data fetching where immediate feedback matters.
- Set a short connection timeout on the `quotewerks` connection config: `'login_timeout' => 5` (seconds). Do not let the default 30-second timeout block the queue worker.

**Phase:** QWSQL-01, QWSQL-04.

---

### Pitfall M3: DOCX Template Processor Conflict When Multiple Generators Share Templates

**What goes wrong:** PHPWord's `TemplateProcessor` modifies a copy of a `.docx` template file. The existing `DocxBuilderService` (948 lines) already uses `DocumentTemplateService` to resolve template paths. If Worksheet and O&M generators reuse the same template infrastructure without their own template slots, one generator's `setValue('project_name', ...)` will break another generator's template if the placeholder names collide.

**Prevention:**
- Each generator (RAMS, Worksheet, O&M, Cable Schedule) must have its own dedicated template file with non-overlapping placeholder names, OR use the programmatic PHPWord API (not `TemplateProcessor`) for sections that are entirely generated.
- The existing `DocxBuilderService` mixes both approaches (template for cover page, programmatic for body). This pattern is correct — follow it for new generators. Do not expand `TemplateProcessor` usage into body content.
- PHPWord `TemplateProcessor::cloneBlock()` for repeating sections (rooms, equipment rows) is unreliable with complex nested tables. Use programmatic section building for any repeating content.

**Phase:** WORK-01, OM-01.

---

### Pitfall M4: PhpSpreadsheet Added Without Removing mpdf or Rationalising PDF Libraries

**What goes wrong:** The codebase already has three PDF libraries (CONCERNS.md flags this). Adding `phpoffice/phpspreadsheet` for XLSX output (CABLE-01) without cleaning up the existing PDF library situation further increases dependency bloat. More critically, if `phpspreadsheet` is not in `composer.json` when CABLE-01 is implemented, the cable schedule generator will fail in production if it was forgotten.

**Prevention:**
- Add `phpoffice/phpspreadsheet` to `composer.json` at the start of the CABLE-01 phase — not halfway through implementation.
- Use the same phase to remove `mpdf/mpdf` if O&M PDF export can be handled by DomPDF (verify this before removing). The CONCERNS.md note says mpdf handles complex CSS for O&M — verify before touching it.
- Do not use `phpspreadsheet` for anything other than XLSX output. The RAMS/Worksheet/O&M generators stay on PHPWord.

**Phase:** CABLE-01.

---

### Pitfall M5: Breaking the Existing RAMS Pipeline by Refactoring Its Inputs

**What goes wrong:** RAMS-01 requires refactoring the existing RAMS generator to consume from `ProjectDataService` instead of directly from `reviewed_data`. The existing pipeline is: `ExtractRamsDraftJob` → `reviewed_data` → `BuildRamsDocumentJob` → `RamsBuilderService` → `RamsDataBuilderService`. If `RamsDataBuilderService` is modified or removed as part of the `ProjectDataService` introduction, and the job system still dispatches the old job class, existing queued jobs will fail.

**Existing risk factor:** There are already backup job files (`BuildRamsDocumentJob2903.php`, `ExtractRamsDraftJob203.php`). The fact that these exist indicates this pipeline has been broken and recovered from before.

**Warning signs:**
- Any change to `RamsDataBuilderService::assemble()` method signature
- Any change to the keys in `reviewed_data` (the `BuildRamsDocumentJob` reads `activities`, `equipment`, `hazards` directly)
- Adding `project_id` as a required field in a job that was dispatched before `project_id` existed

**Prevention:**
- Treat `BuildRamsDocumentJob` and `RamsBuilderService` as read-only during the DATA-01 and PROJ-01 phases. Introduce `ProjectDataService` AROUND the existing pipeline, not instead of it.
- The migration path for RAMS-01 is: `ProjectDataService` is introduced and new generators use it exclusively. Only after Worksheet, O&M, and Cable Schedule generators are working should the existing RAMS generator be migrated to use `ProjectDataService` as a data source.
- When making any change to `RamsDataBuilderService`, run the existing 928-line test suite (`tests/Unit/Rams/QuoteParserServiceTest.php`) and relevant builder tests before and after. Do not deploy without green tests.
- Queued jobs serialize model IDs, not full model state. If a `RamsDocument` schema changes (new non-nullable column added), jobs dispatched before the migration will fail when they reload the model. Run `php artisan queue:clear` after any schema migration affecting `rams_documents`.

**Phase:** RAMS-01 — must be the LAST generator refactored, not the first.

---

## Minor Pitfalls

---

### Pitfall Mi1: Charset/Collation Mismatch Between QuoteWerks SQL and MySQL

**What goes wrong:** QuoteWerks data may contain Windows-1252 encoded characters (smart quotes, em-dashes, degree symbols) common in AV equipment descriptions. These translate correctly in SQL Server but corrupt when stored in MySQL `utf8mb4` columns without explicit conversion.

**Prevention:**
- Use `mb_convert_encoding($value, 'UTF-8', 'Windows-1252')` on all string fields pulled from QuoteWerks before storing or processing them.
- Test with real QuoteWerks data that includes product descriptions from manufacturers (these frequently contain trademark symbols and special characters).

**Phase:** QWSQL-03.

---

### Pitfall Mi2: Duplicate Migration Timestamp Conflicts

**What goes wrong:** The migration history already contains two separate files with timestamps `2026_03_09_000002` and `2026_03_09_000003` creating different tables (cable_schedules and site_surveys used the same timestamp slots). If a new developer or CI environment runs `php artisan migrate:fresh`, the order of execution may differ from what the current production schema represents.

**Prevention:**
- All new migrations use timestamps derived from the actual creation time. Never manually set a timestamp to an earlier date.
- Run `php artisan migrate:status` on a fresh database before merging any migration PR. Verify the sequence is unambiguous.

**Phase:** PROJ-01 — this creates the foundational `projects` table and must have a clean timestamp.

---

### Pitfall Mi3: Temp File Accumulation in New Document Generators

**What goes wrong:** `DocxBuilderService` and `OmManualDocxService` both use `tempnam()`/`sys_get_temp_dir()` for DOCX construction. CONCERNS.md notes that only some services use `finally` blocks for cleanup. New generators (Worksheet, O&M, Cable Schedule) will repeat this pattern. Without `finally` blocks, any exception during generation leaves orphaned temp files.

**Prevention:**
- Every new generator that creates temp files must use a `try/finally` block with `@unlink($tempPath)` in the `finally`. This is not optional.
- Consider a `TempFileManager` utility class that registers temp paths and cleans up in its destructor.

**Phase:** WORK-01, OM-01, CABLE-01.

---

### Pitfall Mi4: AI Rate Limiting on Newly Exposed Routes

**What goes wrong:** CONCERNS.md flags that `rams.regenerate` and `rams.retry-generation` routes have no throttle middleware. When new document generators are added (Worksheet, O&M), if they also trigger AI calls (even via the existing `MethodStatementGeneratorService`), those routes will have the same gap.

**Prevention:**
- When wiring new generator routes, add throttle middleware immediately: `->middleware('throttle:5,1')` (5 requests per minute per user) on any route that dispatches an AI job.
- Do not add routes first and throttle later — the throttle must be in the initial PR.

**Phase:** WORK-01, OM-01.

---

## Phase-Specific Warnings

| Phase | Likely Pitfall | Mitigation |
|-------|---------------|------------|
| QWSQL-01 (driver setup) | C1: Driver mismatch, C2: TLS cert rejection | Verify production PHP extensions before writing service code |
| QWSQL-02 (schema queries) | C3: Reserved column names, M1: Wrong connection used | Use named `quotewerks` connection; bracket-quote all identifiers |
| QWSQL-03 (data mapping) | Mi1: Charset corruption | Run charset conversion on all string fields before use |
| QWSQL-04 (dual import) | M2: VPN failure in queued context | Keep import synchronous; short connection timeout |
| DATA-01 (ProjectDataService) | C4: God class growth, C5: Merge priority undefined | Define canonical structure as DTO first; document priority chain |
| DATA-02 (canonical structure) | C5: Priority gaps between sources | Write the merge priority spec before writing any code |
| PROJ-01/02 (projects table + re-linking) | C6: Non-nullable columns on live tables | Use nullable columns + backfill pattern; never add constraint in one step |
| RAMS-01 (migrate RAMS to ProjectDataService) | M5: Breaking existing pipeline | Migrate last; keep `BuildRamsDocumentJob` intact until all other generators are working |
| WORK-01, OM-01 (new generators) | M3: Template placeholder collision, Mi3: Temp file leaks | Separate templates per generator; always use try/finally |
| CABLE-01 (XLSX output) | M4: Missing phpspreadsheet dependency | Add to composer.json on day one of the phase |

---

## Sources

- Codebase analysis: `app/Services/RamsDataBuilderService.php`, `app/Services/DocxBuilderService.php`, `app/Jobs/BuildRamsDocumentJob.php`
- Codebase analysis: `config/database.php` (sqlsrv stanza with commented-out TLS options)
- Codebase analysis: `.planning/codebase/CONCERNS.md` (tech debt, fragile areas, test coverage gaps)
- Migration history: `database/migrations/` (46 files, duplicate timestamp pattern observed)
- Composer dependencies: `composer.json` (no sqlsrv extension, no phpspreadsheet)
- Confidence: HIGH for pitfalls derived directly from codebase evidence; MEDIUM for general Laravel/SQL Server patterns applied to this specific stack

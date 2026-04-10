# Phase 2: QuoteWerks SQL Import - Context

**Gathered:** 2026-04-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Import quote data directly from the QuoteWerks MS SQL database as an alternative to PDF upload. The import must produce output structurally identical to the existing PDF pipeline (`extracted_data` format). Includes driver verification, health check command, schema exploration tooling, and dual-input UI.

</domain>

<decisions>
## Implementation Decisions

### Connection Setup
- **D-01:** Production server is Linux (Ubuntu/Debian) — requires Microsoft ODBC Driver for Linux + pdo_sqlsrv PECL extension
- **D-02:** QuoteWerks uses SQL Server Authentication (username + password, not Windows Auth)
- **D-03:** QuoteWerks server likely uses a self-signed certificate — connection config must set `trust_server_certificate=true` and `encrypt=true` via .env variables
- **D-04:** Use ODBC Driver 17 (not 18) for compatibility with self-signed certs on internal VPN connections
- **D-05:** Connection configured as Laravel named connection `quotewerks` in config/database.php — never set as default, never use Eloquent models bound to it

### Data Mapping
- **D-06:** Three key QuoteWerks tables: DocumentHeaders (quote header), DocumentItems (line items), DocumentItemGroups (room/zone grouping)
- **D-07:** Groups/folders in QuoteWerks represent rooms/zones — line items are grouped into named sections
- **D-08:** Charset: unknown — need to detect collation on first connection. If Windows-1252, apply mb_convert_encoding() to all string fields
- **D-09:** SQL import data maps to identical `extracted_data` structure as PDF import — `data_source: 'quotewerks'` annotation with `confidence: 0.95` (higher than PDF OCR)

### Import UX Flow
- **D-10:** SQL import option added to existing quote import page as a toggle/tab: "Upload PDF" | "QuoteWerks Lookup"
- **D-11:** Two lookup methods: quick lookup by quote reference number, plus search by client name/date with result list
- **D-12:** Import runs synchronously (not queued) — direct DB query gives instant result, avoids VPN-drop risk in background jobs
- **D-13:** Connection failure shows inline error with fallback suggestion: "Could not connect to QuoteWerks. Check VPN connection. You can upload a PDF instead."

### Schema Exploration
- **D-14:** Build both: `php artisan quotewerks:ping` (health check, QWSQL-07) and `php artisan quotewerks:schema` (lists tables, columns, sample data for development)
- **D-15:** QuoteWerks uses reserved SQL words as column names (Date, Name, Type, Group) — Claude's discretion on query abstraction (dedicated repository vs raw queries)

### Claude's Discretion
- D-15 query abstraction approach (repository pattern vs raw queries with bracket-quoting)
- D-08 charset detection and conversion strategy
- Error handling granularity for different SQL failure modes (connection refused, timeout, auth failure, query error)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Decisions
- `.planning/phases/01-project-layer-data-foundation/01-CONTEXT.md` — D-02 auto-create on import, D-04 confidence tracking
- `.planning/phases/01-project-layer-data-foundation/01-03-SUMMARY.md` — ProjectDataService canonical structure

### Existing Import Pipeline
- `app/Core/Modules/QuoteImport/QuoteImportService.php` — existing import() and importFromData() methods
- `app/Services/QuoteParserService.php` — how PDF-extracted data is structured
- `config/database.php` — existing sqlsrv connection stanza (has commented-out TLS options)

### Research
- `.planning/research/PITFALLS.md` — MS SQL driver pitfalls, TLS cert issues, QuoteWerks column name quirks
- `.planning/research/STACK.md` — ODBC 17 recommendation, avoid FreeTDS

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `QuoteImportService::importFromData(User, array)` — accepts pre-extracted data, applies auto-create-by-client+site logic. SQL import can call this after extracting from DB.
- `QuoteParserService` — defines the canonical `extracted_data` structure that SQL output must match
- `config/database.php` already has a `sqlsrv` connection template with commented-out TLS options

### Established Patterns
- Named DB connections: `DB::connection('quotewerks')->select(...)` for raw queries
- Service-based architecture: `QuoteWerksImportService` in `app/Core/Modules/QuoteImport/`
- Artisan commands: `app/Console/Commands/` with `$signature` and `$description`

### Integration Points
- Quote import page (`resources/views/quote-import/` or similar) — add toggle tab
- `QuoteImportService::importFromData()` — SQL service feeds into this
- `config/database.php` — add `quotewerks` named connection
- `.env` — add `QW_HOST`, `QW_PORT`, `QW_DATABASE`, `QW_USERNAME`, `QW_PASSWORD` variables

</code_context>

<specifics>
## Specific Ideas

- QuoteWerks reference numbers follow a known format — the lookup should validate the format before querying
- The schema explorer command is primarily a development tool — it doesn't need to be polished, just functional
- SQL import confidence should be 0.95 (vs ~0.6 for PDF OCR) since the data is structured, not parsed

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 02-quotewerks-sql-import*
*Context gathered: 2026-04-10*

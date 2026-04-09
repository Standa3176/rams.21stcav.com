# Technology Stack — Milestone Additions

**Project:** RAMS Platform (rams.21stcav.com)
**Scope:** Additions only — MS SQL connectivity, XLSX generation, DOCX document generators
**Researched:** 2026-04-09
**Base platform:** Laravel 12 / PHP 8.2+ / MySQL (existing, unchanged)

---

## What This Covers

This milestone adds three capabilities to the existing platform:

1. Read-only connection to the QuoteWerks MS SQL database
2. XLSX generation for cable schedules
3. Structured DOCX generation for worksheets and O&M manuals (builds on existing PHPWord)

No changes to the existing stack are required for DOCX — PHPWord 1.4.0 is already installed and
used in `DocxBuilderService`. This file documents what needs to be added and why.

---

## 1. MS SQL Connectivity (QuoteWerks SQL Import)

### Recommendation: PHP `sqlsrv` / `pdo_sqlsrv` PECL extensions + Laravel named connection

**Confidence: HIGH** (verified against Microsoft official docs, February 2026)

### Driver Layer

| Component | Version | Purpose |
|-----------|---------|---------|
| `ext-pdo_sqlsrv` (PECL) | 5.13 | PDO driver for SQL Server — what Laravel's Eloquent uses |
| `ext-sqlsrv` (PECL) | 5.13 | Low-level SQL Server extension (required by pdo_sqlsrv) |
| Microsoft ODBC Driver for SQL Server | 17 or 18 | Required system dependency; both work with driver 5.13 |

**Why this, not an alternative:**

- Laravel's built-in `sqlsrv` database driver (present in `Illuminate\Database`) requires `pdo_sqlsrv` — no Composer package needed
- `doctrine/dbal` 4.4.3 (already installed) explicitly supports `sqlsrv` connections, so schema introspection and Eloquent will work without additional packages
- ODBC 17 is the safer choice for Windows Server targets running older SQL Server (QuoteWerks typically uses SQL Server 2016/2019); ODBC 18 adds TLS 1.2 enforcement by default which can cause issues with internal/self-signed certs
- Driver 5.13 supports PHP 8.2, 8.3, 8.4, and 8.5 — fully compatible with this project's PHP ^8.2 requirement

**What NOT to use:**

- Do not use `yajra/laravel-datatables` or any ORM abstraction over the QuoteWerks connection — this is read-only, raw PDO queries via `DB::connection('quotewerks')` are the right approach
- Do not attempt FreeTDS on Linux as a workaround — the server-side SQL Server instance is Windows, and the Laravel app server should have the Microsoft ODBC driver installed; FreeTDS has encoding and type-mapping edge cases with QuoteWerks data

### Laravel Configuration

`config/database.php` already includes a `sqlsrv` connection block (lines 101–114). The QuoteWerks connection should be added as a second named connection — do not modify the default `sqlsrv` block.

Add to `config/database.php`:

```php
'quotewerks' => [
    'driver'   => 'sqlsrv',
    'host'     => env('QW_DB_HOST', 'localhost'),
    'port'     => env('QW_DB_PORT', '1433'),
    'database' => env('QW_DB_DATABASE', 'QuoteWerks'),
    'username' => env('QW_DB_USERNAME'),
    'password' => env('QW_DB_PASSWORD'),
    'charset'  => 'utf8',
    'prefix'   => '',
    'prefix_indexes' => true,
    // Uncomment if QuoteWerks SQL Server uses self-signed cert:
    // 'trust_server_certificate' => env('QW_DB_TRUST_CERT', 'false'),
    // 'encrypt' => env('QW_DB_ENCRYPT', 'yes'),
],
```

Usage in `QuoteWerksImportService`:

```php
$rows = DB::connection('quotewerks')
    ->table('Quote')
    ->where('PKQuoteHeaderID', $quoteId)
    ->get();
```

### Installation (server-level, not Composer)

On the Laravel app server (Windows or Linux):

**Windows:**
```
# Install ODBC Driver 17 from Microsoft
# Then install PHP extensions via PECL or pre-built .dll from github.com/Microsoft/msphpsql/releases
# Add to php.ini:
extension=pdo_sqlsrv
extension=sqlsrv
```

**Linux (Ubuntu/Debian):**
```bash
# Microsoft ODBC Driver 17
curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
curl https://packages.microsoft.com/config/ubuntu/22.04/prod.list > /etc/apt/sources.list.d/mssql-release.list
apt-get update && ACCEPT_EULA=Y apt-get install -y msodbcsql17 unixodbc-dev

# PHP extensions via PECL
pecl install sqlsrv pdo_sqlsrv
# Add to php.ini: extension=sqlsrv.so and extension=pdo_sqlsrv.so
```

**No Composer install required** — this is a PHP extension, not a package.

---

## 2. XLSX Generation (Cable Schedules)

### Recommendation: `phpoffice/phpspreadsheet` ^2.x

**Confidence: MEDIUM** — package identity is authoritative; version constraint should be verified at install time (`composer require phpoffice/phpspreadsheet`)

| Package | Target Version | PHP Requirement | Purpose |
|---------|---------------|-----------------|---------|
| `phpoffice/phpspreadsheet` | ^2.0 (verify latest at install) | ^8.1 | Write .xlsx cable schedule files |

**Why PhpSpreadsheet, not an alternative:**

- It is the direct successor to and replacement for the abandoned PHPExcel library
- `phpoffice/phpword` (already in the project) is a sibling library from the same PHPOffice organisation — same conventions, same autoload patterns, same OSS license (LGPL-3.0)
- No wrapper package (e.g. Maatwebsite/Laravel-Excel) is needed for this use case. Laravel-Excel adds an Eloquent-export API that is valuable when you're dumping database queries to spreadsheets, but the cable schedule generator needs to write cells with specific column headers, merged cells, and conditional formatting — raw PhpSpreadsheet is more appropriate and avoids a heavy dependency
- Wire generation from structured data (not from database rows) means the generator service calls `$sheet->setCellValue()` and `$spreadsheet->createSheet()` directly — the PhpSpreadsheet API for this is well-documented and stable

**What NOT to use:**

- Do not use `maatwebsite/excel` (Laravel-Excel) — it optimises for query-to-export patterns, not for programmatically building structured documents. It wraps PhpSpreadsheet, so you'd add indirection without benefit
- Do not use `box/spout` (now `openspout/openspout`) — it is optimised for large-dataset streaming and has a minimal API. Cable schedules require cell styling and column control that Spout intentionally omits

**Installation:**

```bash
composer require phpoffice/phpspreadsheet
```

Required PHP extensions (all standard, typically already enabled):
- `ext-zip`, `ext-xml`, `ext-gd`, `ext-mbstring`

**Usage pattern in `CableScheduleGeneratorService`:**

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'Cable Ref');
// ... populate from ProjectDataService ...

$writer = new Xlsx($spreadsheet);
$writer->save(storage_path('app/private/' . $filename));
```

---

## 3. DOCX Generation (Worksheets and O&M Manuals)

### Recommendation: Use existing `phpoffice/phpword` 1.4.0 — no new package needed

**Confidence: HIGH** — PHPWord 1.4.0 is already installed, already used in `DocxBuilderService`, already producing RAMS documents

| Package | Version | Status |
|---------|---------|--------|
| `phpoffice/phpword` | 1.4.0 (locked) | Already installed — extend, do not replace |

**Why no new package:**

- Worksheet and O&M generators follow the same pattern as the existing RAMS DOCX generator (`DocxBuilderService.php`)
- PHPWord 1.4.0 supports sections, tables, headers/footers, styles, and template loading — all features required for worksheets and O&M manuals
- The existing `DocxBuilderService` already abstracts PHPWord into reusable primitives; worksheet and O&M generators should extend or compose from it, not introduce a parallel library

**What the generators need from PHPWord that the existing code already exercises:**

- `PhpOffice\PhpWord\PhpWord` — root document object
- `$section->addTable()` with row/cell API — for room-by-room equipment lists and cable route tables
- `$section->addText()` and `$section->addTitle()` — for narrative content
- `IOFactory::createWriter($doc, 'Word2007')` — for writing `.docx` output

**No API changes in PHPWord 1.4.0 that affect existing patterns.** The locked version is recent (released 2025-05-29 per composer.lock timestamp) and PHP 8.2-compatible.

---

## 4. Laravel Database Multiple Connections Pattern

**Confidence: HIGH** — this is a documented Laravel feature, unchanged in Laravel 12

The QuoteWerks SQL connection must be isolated from the primary MySQL connection. Laravel's multiple connections feature handles this with no additional packages:

- Named connection in `config/database.php` (see section 1 above)
- Env vars prefixed `QW_DB_*` to prevent collision with primary DB vars
- `DB::connection('quotewerks')->...` in `QuoteWerksImportService` only
- No Eloquent models bound to `quotewerks` connection — raw query builder only (read-only, no ORM overhead, no accidental writes)
- Connection is never set as `default` — the primary MySQL connection remains the default for all application models

---

## Summary: What to Add

| Item | Action | Type |
|------|--------|------|
| `pdo_sqlsrv` + `sqlsrv` PHP extensions | Install on server via PECL | System extension |
| Microsoft ODBC Driver 17 | Install on server | System binary |
| `quotewerks` named connection in `config/database.php` | Add config block | Config change |
| `QW_DB_*` env vars | Add to `.env` + `.env.example` | Config change |
| `phpoffice/phpspreadsheet` | `composer require phpoffice/phpspreadsheet` | Composer package |
| PHPWord for DOCX | Already installed — no action | — |

**No new Composer packages for MS SQL.** One new Composer package for XLSX. Zero changes to existing packages.

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| MS SQL connectivity | `pdo_sqlsrv` PECL extension | FreeTDS / ODBC via PDO generic | Encoding/collation issues with QuoteWerks data; Microsoft driver is the supported path |
| XLSX generation | `phpoffice/phpspreadsheet` | `maatwebsite/excel` | Adds unnecessary abstraction layer; cable schedule needs direct cell control |
| XLSX generation | `phpoffice/phpspreadsheet` | `openspout/openspout` | Missing cell styling API; optimised for streaming large datasets, not structured document authoring |
| DOCX (worksheets/O&M) | Extend existing PHPWord | Add `phpoffice/phppresentation` or similar | Wrong format; existing PHPWord is sufficient |

---

## Sources

- Microsoft PHP Drivers for SQL Server — System Requirements (official docs, fetched 2026-04-09):
  https://learn.microsoft.com/en-us/sql/connect/php/system-requirements-for-the-php-sql-driver
- Laravel `config/database.php` — existing `sqlsrv` connection block confirmed present (lines 101–114)
- `composer.lock` — PHPWord 1.4.0 confirmed installed (2025-05-29), PhpSpreadsheet absent
- `doctrine/dbal` 4.4.3 keywords — `sqlsrv` listed as supported dialect (confirmed from composer.lock)
- PHPOffice/PhpSpreadsheet — version constraint MEDIUM confidence; verify with `composer require phpoffice/phpspreadsheet` at implementation time

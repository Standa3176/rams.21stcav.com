# Phase 2: QuoteWerks SQL Import - Research

**Researched:** 2026-04-10
**Domain:** MS SQL Server connectivity, QuoteWerks schema mapping, Laravel dual-connection pattern, UI tab extension
**Confidence:** HIGH (codebase verified; prior STACK.md and PITFALLS.md confirmed against existing files)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Connection Setup**
- D-01: Production server is Linux (Ubuntu/Debian) — requires Microsoft ODBC Driver for Linux + pdo_sqlsrv PECL extension
- D-02: QuoteWerks uses SQL Server Authentication (username + password, not Windows Auth)
- D-03: QuoteWerks server likely uses a self-signed certificate — connection config must set `trust_server_certificate=true` and `encrypt=true` via .env variables
- D-04: Use ODBC Driver 17 (not 18) for compatibility with self-signed certs on internal VPN connections
- D-05: Connection configured as Laravel named connection `quotewerks` in config/database.php — never set as default, never use Eloquent models bound to it

**Data Mapping**
- D-06: Three key QuoteWerks tables: DocumentHeaders (quote header), DocumentItems (line items), DocumentItemGroups (room/zone grouping)
- D-07: Groups/folders in QuoteWerks represent rooms/zones — line items are grouped into named sections
- D-08: Charset: unknown — need to detect collation on first connection. If Windows-1252, apply mb_convert_encoding() to all string fields
- D-09: SQL import data maps to identical `extracted_data` structure as PDF import — `data_source: 'quotewerks'` annotation with `confidence: 0.95` (higher than PDF OCR)

**Import UX Flow**
- D-10: SQL import option added to existing quote import page as a toggle/tab: "Upload PDF" | "QuoteWerks Lookup"
- D-11: Two lookup methods: quick lookup by quote reference number, plus search by client name/date with result list
- D-12: Import runs synchronously (not queued) — direct DB query gives instant result, avoids VPN-drop risk in background jobs
- D-13: Connection failure shows inline error with fallback suggestion: "Could not connect to QuoteWerks. Check VPN connection. You can upload a PDF instead."

**Schema Exploration**
- D-14: Build both: `php artisan quotewerks:ping` (health check, QWSQL-07) and `php artisan quotewerks:schema` (lists tables, columns, sample data for development)
- D-15: QuoteWerks uses reserved SQL words as column names (Date, Name, Type, Group) — Claude's discretion on query abstraction (repository pattern vs raw queries with bracket-quoting)

### Claude's Discretion
- D-15: Query abstraction approach (repository pattern vs raw queries with bracket-quoting)
- D-08: Charset detection and conversion strategy
- Error handling granularity for different SQL failure modes (connection refused, timeout, auth failure, query error)

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| QWSQL-01 | MS SQL driver verified and configured on production server (hard blocker) | Driver install script (ODBC 17 + pecl pdo_sqlsrv), `quotewerks:ping` command pattern |
| QWSQL-02 | QuoteWerksImportService connects to remote MS SQL via read-only direct connection | Named `quotewerks` connection config, `DB::connection('quotewerks')` pattern |
| QWSQL-03 | SQL import pulls header data, line items, and room/group structure from QuoteWerks database | DocumentHeaders/DocumentItems/DocumentItemGroups query shapes, bracket-quoting, charset conversion |
| QWSQL-04 | SQL import produces identical extracted_data structure as PDF import | Canonical `extracted_data` shape confirmed from `QuoteImportService::mergeParsedQuoteData()` and `ProjectDataService` |
| QWSQL-05 | Dual input system — user can import via PDF upload or SQL quote reference lookup | Existing create.blade.php tab extension pattern, new `QuoteWerksController` |
| QWSQL-06 | SQL connection configured via .env with no frontend exposure of credentials | Named connection env vars pattern (QW_DB_*), never bound to Eloquent |
| QWSQL-07 | Health check artisan command (quotewerks:ping) verifies connectivity | Artisan command pattern from `CreateDocxTemplates`, `quotewerks:schema` companion |
</phase_requirements>

---

## Summary

Phase 2 adds a second import path to the existing quote import pipeline. Instead of uploading a PDF and running AI extraction, the user looks up a QuoteWerks quote by reference number (or searches by client) and the system queries the QuoteWerks SQL Server directly. The result is fed into `QuoteImportService::importFromData()` — the same endpoint already used by the array-based import path — so all downstream behaviour (project auto-create, package creation, review flow) is identical.

The primary engineering work is in three areas: (1) server-level driver installation and Laravel connection config, (2) `QuoteWerksImportService` with a companion `QuoteWerksRepository` that handles the SQL-Server-specific quoting quirks, and (3) a UI tab extension to `resources/views/quote-import/create.blade.php` and a new `QuoteWerksImportController` with two routes.

The existing codebase is well-prepared for this phase: `QuoteImportService::importFromData()` is already written and tested, `config/database.php` already has a commented-out `sqlsrv` stanza, the `quotewerks:ping` concept is documented in PITFALLS.md, and the `extracted_data` canonical structure is verified from `ProjectDataService` (Phase 1 output).

**Primary recommendation:** Build a `QuoteWerksRepository` class that owns all bracket-quoted SQL and maps column names to internal names immediately on retrieval. Everything above that layer (service, controller) works with internal names only.

---

## Standard Stack

### Core

| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| `ext-pdo_sqlsrv` (PECL) | 5.13 | PDO driver for SQL Server — what Laravel's `sqlsrv` driver uses | Only Microsoft-supported PHP driver for SQL Server on Linux |
| `ext-sqlsrv` (PECL) | 5.13 | Low-level companion extension required by pdo_sqlsrv | Required dependency of pdo_sqlsrv |
| Microsoft ODBC Driver 17 | 17.x | System-level ODBC layer used by pdo_sqlsrv | ODBC 17 chosen over 18 for self-signed cert compat (D-04) |
| Laravel named connection `quotewerks` | — | Isolates QuoteWerks queries from default MySQL connection | Established Laravel pattern; no extra packages needed |

[VERIFIED: .planning/research/STACK.md — Microsoft official docs fetched 2026-04-09]

### Supporting

| Component | Version | Purpose | When to Use |
|-----------|---------|---------|-------------|
| `doctrine/dbal` | 4.4.3 (already installed) | Schema introspection for `quotewerks:schema` command | Already installed; `sqlsrv` dialect supported |
| `DB::connection('quotewerks')` | — | Raw query builder via named connection | All QuoteWerks queries — never Eloquent on this connection |

[VERIFIED: composer.lock — doctrine/dbal 4.4.3 confirmed present]

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `pdo_sqlsrv` | FreeTDS / `pdo_dblib` | FreeTDS has charset issues with Windows-1252 QuoteWerks data; Microsoft driver is the supported path. Explicitly excluded in STATE.md |
| Raw `DB::connection()` queries | Eloquent model with `$connection = 'quotewerks'` | Eloquent on a read-only foreign connection risks accidental writes, relationship loading, and event firing. Raw query builder is correct for read-only external DB |

### Installation (server-level, not Composer)

```bash
# Step 1: Add Microsoft package repository (Ubuntu 22.04 / Debian equivalent)
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/22.04/prod.list \
    | sudo tee /etc/apt/sources.list.d/mssql-release.list

# Step 2: Install ODBC Driver 17
sudo apt-get update
ACCEPT_EULA=Y sudo apt-get install -y msodbcsql17 unixodbc-dev

# Step 3: Install PHP extensions
sudo pecl install sqlsrv pdo_sqlsrv

# Step 4: Enable in php.ini
echo "extension=sqlsrv.so" | sudo tee -a /etc/php/8.2/cli/conf.d/sqlsrv.ini
echo "extension=pdo_sqlsrv.so" | sudo tee -a /etc/php/8.2/cli/conf.d/pdo_sqlsrv.ini

# Verify
php -m | grep -i sqlsrv
```

**No Composer packages required for MS SQL.** This is a PHP extension, not a library.

[VERIFIED: .planning/research/STACK.md, .planning/research/PITFALLS.md]

---

## Architecture Patterns

### Recommended Project Structure

```
app/
├── Core/Modules/QuoteImport/
│   ├── QuoteImportService.php          (existing — importFromData() entry point)
│   ├── QuoteWerksImportService.php     (NEW — orchestrates SQL → extracted_data)
│   └── QuoteWerksRepository.php       (NEW — all bracket-quoted SQL Server queries)
│
app/Http/Controllers/
│   └── QuoteWerksImportController.php (NEW — lookup and search endpoints)
│
app/Http/Requests/
│   └── QuoteWerksLookupRequest.php    (NEW — validates qw_reference or search params)
│
app/Console/Commands/
│   ├── QuoteWerksPing.php             (NEW — artisan quotewerks:ping)
│   └── QuoteWerksSchema.php           (NEW — artisan quotewerks:schema)
│
config/
│   └── database.php                   (MODIFY — add 'quotewerks' named connection)
│
resources/views/quote-import/
│   └── create.blade.php               (MODIFY — add toggle tab + QuoteWerks panel)
│
routes/
│   └── web.php                        (MODIFY — add 2 new QuoteWerks routes)
```

### Pattern 1: Named Connection Isolation

**What:** All QuoteWerks queries use `DB::connection('quotewerks')`. The `QuoteWerksRepository` is the only class that knows this connection name.

**When to use:** Any time a query must run against the QuoteWerks SQL Server.

```php
// Source: config/database.php (existing sqlsrv stanza as template)
// Add to config/database.php 'connections' array:
'quotewerks' => [
    'driver'                  => 'sqlsrv',
    'host'                    => env('QW_DB_HOST', 'localhost'),
    'port'                    => env('QW_DB_PORT', '1433'),
    'database'                => env('QW_DB_DATABASE', 'QuoteWerks'),
    'username'                => env('QW_DB_USERNAME'),
    'password'                => env('QW_DB_PASSWORD'),
    'charset'                 => 'utf8',
    'prefix'                  => '',
    'prefix_indexes'          => true,
    'encrypt'                 => env('QW_DB_ENCRYPT', 'yes'),
    'trust_server_certificate'=> env('QW_DB_TRUST_CERT', 'true'),
    'login_timeout'           => env('QW_DB_TIMEOUT', 5),
],
```

[VERIFIED: config/database.php lines 101–114 confirmed as template; TLS options were commented out — must be uncommented/added for the `quotewerks` connection per D-03]

### Pattern 2: Repository with Bracket-Quoted Identifiers

**What:** `QuoteWerksRepository` wraps all raw queries and maps QuoteWerks column names (reserved words, PascalCase) to internal names immediately on retrieval.

**When to use:** Every interaction with DocumentHeaders, DocumentItems, DocumentItemGroups.

```php
// Source: PITFALLS.md Pitfall C3 + D-15 Claude's Discretion recommendation
class QuoteWerksRepository
{
    public function __construct(
        private readonly string $connection = 'quotewerks'
    ) {}

    public function findByReference(string $reference): ?array
    {
        $row = DB::connection($this->connection)
            ->table('DocumentHeaders')
            ->select([
                '[DocNo]',
                '[SoldToCompanyName]',
                '[ShipToAddress1]',
                '[ShipToCity]',
                '[DocDate]',
                '[SalesPerson]',
                '[Notes]',
            ])
            ->where('[DocNo]', $reference)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->mapHeader((array) $row);
    }

    public function getItemsByDocNo(string $docNo): array
    {
        $rows = DB::connection($this->connection)
            ->table('DocumentItems')
            ->select([
                '[DocNo]',
                '[ItemType]',
                '[Quantity]',
                '[ManufacturerPartNumber]',
                '[Description]',
                '[GroupName]',
                '[SortOrder]',
            ])
            ->where('[DocNo]', $docNo)
            ->orderBy('[SortOrder]')
            ->get();

        return $rows->map(fn($r) => $this->mapItem((array) $r))->all();
    }

    public function searchByClient(string $clientName, ?string $dateFrom = null): array
    {
        $query = DB::connection($this->connection)
            ->table('DocumentHeaders')
            ->select(['[DocNo]', '[SoldToCompanyName]', '[DocDate]', '[ShipToAddress1]'])
            ->where('[SoldToCompanyName]', 'like', "%{$clientName}%");

        if ($dateFrom) {
            $query->where('[DocDate]', '>=', $dateFrom);
        }

        return $query->orderByDesc('[DocDate]')->limit(20)->get()
            ->map(fn($r) => $this->mapHeader((array) $r))->all();
    }

    // Maps QuoteWerks column names → internal names + charset conversion
    private function mapHeader(array $row): array
    {
        return [
            'doc_no'       => $this->str($row['DocNo']       ?? ''),
            'client_name'  => $this->str($row['SoldToCompanyName'] ?? ''),
            'site_address' => $this->str(trim(implode(', ', array_filter([
                $row['ShipToAddress1'] ?? '',
                $row['ShipToCity']     ?? '',
            ])))),
            'doc_date'     => $row['DocDate']     ?? null,
            'sales_person' => $this->str($row['SalesPerson'] ?? ''),
            'notes'        => $this->str($row['Notes']       ?? ''),
        ];
    }

    private function mapItem(array $row): array
    {
        return [
            'item_type'   => $this->str($row['ItemType']             ?? ''),
            'quantity'    => (int) ($row['Quantity']                 ?? 1),
            'part_number' => $this->str($row['ManufacturerPartNumber'] ?? ''),
            'description' => $this->str($row['Description']          ?? ''),
            'group_name'  => $this->str($row['GroupName']            ?? ''),
            'sort_order'  => (int) ($row['SortOrder']                ?? 0),
        ];
    }

    // Charset conversion: Windows-1252 → UTF-8 (D-08)
    private function str(string $value): string
    {
        // Attempt detection: if valid UTF-8, return as-is; else convert
        if (mb_check_encoding($value, 'UTF-8')) {
            return trim($value);
        }
        return trim(mb_convert_encoding($value, 'UTF-8', 'Windows-1252'));
    }
}
```

[ASSUMED — exact QuoteWerks column names. PITFALLS.md and STACK.md confirm reserved-word bracket-quoting requirement. Actual column names must be verified via `quotewerks:schema` on first connection.]

### Pattern 3: SQL → extracted_data Mapping

**What:** `QuoteWerksImportService` converts the repository output into the canonical `extracted_data` array. The array must match the shape produced by `QuoteImportService::mergeParsedQuoteData()` so `importFromData()` accepts it.

**When to use:** After repository fetch, before calling `QuoteImportService::importFromData()`.

```php
// Source: QuoteImportService::mergeParsedQuoteData() and ProjectDataService (Phase 1)
// The canonical extracted_data keys verified from codebase:
[
    'qw_number'         => $header['doc_no'],
    'client_name'       => $header['client_name'],
    'site_address'      => $header['site_address'],
    'site_name'         => '',
    'prepared_by'       => $header['sales_person'],
    'overview'          => $header['notes'],
    'project_name'      => $header['doc_no'] . ' - ' . $header['client_name'],
    'works_description' => null,
    'rooms'             => $rooms,          // array of room name strings (from group names)
    'equipment'         => $equipment,      // array of equipment rows (see below)
    'equipment_list'    => $equipment,
    'line_items'        => $equipment,
    'cable_hints'       => [],
    'meta' => [
        'source'            => 'quotewerks_sql',   // triggers 0.95 confidence in ProjectDataService
        'parser_confidence' => 0.95,
        'data_source'       => 'quotewerks',       // DATA-04 annotation
        'confidence'        => 0.95,
    ],
]
```

Each equipment row shape (mirrors `mergeParsedQuoteData` parser output):
```php
[
    'quantity'    => $item['quantity'],
    'qty'         => $item['quantity'],
    'part_number' => $item['part_number'],
    'part_no'     => $item['part_number'],
    'name'        => $item['description'],
    'description' => $item['description'],
    'area'        => $item['group_name'],   // group name = room/zone
    'location'    => $item['group_name'],
    'category'    => $this->classifyDescription($item['description']),
]
```

[VERIFIED: QuoteImportService::mergeParsedQuoteData() lines 456–490 — parserEquipment array shape confirmed]
[VERIFIED: ProjectDataService summary (01-03-SUMMARY.md) — `extracted_data.meta.source === 'quotewerks_sql'` triggers 0.95 confidence tier]

### Pattern 4: Tab UI Extension

**What:** The existing `create.blade.php` renders a single PDF upload form. Add an Alpine.js tab switcher to toggle between "Upload PDF" and "QuoteWerks Lookup" panels.

**When to use:** The toggle should be the topmost element in the card, above the existing form.

```html
<!-- Source: resources/views/quote-import/create.blade.php (existing structure) -->
<!-- Wrap current content in x-data tab switcher: -->
<div class="card" style="max-width:680px;" x-data="{ tab: 'pdf' }">

    {{-- Tab bar --}}
    <div style="display:flex; gap:0; border-bottom:2px solid #e5e7eb; margin-bottom:1.25rem;">
        <button type="button"
            @click="tab = 'pdf'"
            :style="tab === 'pdf' ? 'border-bottom:2px solid #007B8A; color:#007B8A;' : 'color:#6b7280;'"
            style="padding:.6rem 1.25rem; background:none; border:none; border-bottom:2px solid transparent; font-weight:600; cursor:pointer;">
            Upload PDF
        </button>
        <button type="button"
            @click="tab = 'sql'"
            :style="tab === 'sql' ? 'border-bottom:2px solid #007B8A; color:#007B8A;' : 'color:#6b7280;'"
            style="padding:.6rem 1.25rem; background:none; border:none; border-bottom:2px solid transparent; font-weight:600; cursor:pointer;">
            QuoteWerks Lookup
        </button>
    </div>

    {{-- PDF panel (existing form) --}}
    <div x-show="tab === 'pdf'">
        {{-- existing form content unchanged --}}
    </div>

    {{-- QuoteWerks panel (new) --}}
    <div x-show="tab === 'sql'">
        {{-- reference lookup form → POST /quote-import/quotewerks/lookup --}}
    </div>
</div>
```

[VERIFIED: create.blade.php — confirmed single-card structure; Alpine.js is the project JS framework per CLAUDE.md]

### Pattern 5: Artisan Command Structure

**What:** Artisan commands follow the existing pattern from `CreateDocxTemplates.php`.

**When to use:** `quotewerks:ping` and `quotewerks:schema`.

```php
// Source: app/Console/Commands/CreateDocxTemplates.php (existing pattern)
class QuoteWerksPing extends Command
{
    protected $signature   = 'quotewerks:ping';
    protected $description = 'Verify connectivity to the QuoteWerks SQL Server database';

    public function handle(): int
    {
        try {
            DB::connection('quotewerks')->getPdo();
            $this->info('QuoteWerks connection OK.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('QuoteWerks connection FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
```

[VERIFIED: app/Console/Commands/CreateDocxTemplates.php — command structure confirmed]

### Anti-Patterns to Avoid

- **Eloquent model on quotewerks connection:** Never create an Eloquent model with `protected $connection = 'quotewerks'`. Use raw `DB::connection('quotewerks')` only. Eloquent adds event firing and relationship loading that is inappropriate for a read-only external DB.
- **Unquoted reserved word column names:** Never write `->where('Type', ...)` or `->select('Date')` against QuoteWerks tables. Always bracket-quote: `->where('[ItemType]', ...)`.
- **QuoteWerks queries outside QuoteWerksRepository:** No QuoteWerks column names should appear in `QuoteWerksImportService` or controllers. The repository handles all SQL; the service handles mapping and business logic.
- **Queued import:** D-12 explicitly forbids queueing the import. VPN drops between dispatch and execution would cause silent failures with no user-visible error.
- **Setting `quotewerks` as the default connection:** D-05 forbids this. Never call `config(['database.default' => 'quotewerks'])` anywhere.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SQL Server bracket-quoting | Custom string wrapper | Bracket-quote literals directly in `QuoteWerksRepository` | Scope is narrow (3 tables); a quoting utility adds indirection for no benefit |
| Charset detection | Auto-detect algorithm | `mb_check_encoding($v, 'UTF-8')` → fallback `mb_convert_encoding($v, 'UTF-8', 'Windows-1252')` | Two-step check is sufficient; QuoteWerks data is either UTF-8 or Windows-1252 |
| SQL Server PDO connection | Custom PDO wrapper | `DB::connection('quotewerks')` | Laravel's built-in sqlsrv driver handles PDO; no wrapper needed |
| Tab switcher UI | Custom JS toggle | Alpine.js `x-data`/`x-show` | Alpine is already the project JS framework; no new dependency |
| Equipment category classification | New logic | Copy `QuoteImportService::classifyDescription()` static method | Already written, tested, correct for this domain |

**Key insight:** The repository pattern keeps all SQL-Server-specific quoting isolated. Above the repository, all code is identical to the PDF import path.

---

## Common Pitfalls

### Pitfall 1: Driver Not Installed — Misleading Error Message

**What goes wrong:** If `pdo_sqlsrv` is not installed, `DB::connection('quotewerks')->getPdo()` throws `could not find driver` — a generic PDO error that looks like a connection string problem, not a missing extension.

**Why it happens:** The production server OS is Linux; the Windows `sqlsrv.dll` extension is not available. QWSQL-01 is flagged as a hard blocker in STATE.md.

**How to avoid:** Run `php -m | grep sqlsrv` on the production server BEFORE writing any service code. If output is empty, follow the install script above. The `quotewerks:ping` command should detect the missing driver and print a clear message.

**Warning signs:** `SQLSTATE[HY000]` or `could not find driver` in the ping output with no connection attempt visible in SQL Server logs.

[VERIFIED: PITFALLS.md Pitfall C1]

### Pitfall 2: Self-Signed Certificate Rejection

**What goes wrong:** PHP's `pdo_sqlsrv` defaults to requesting encryption. If the QuoteWerks SQL Server has a self-signed cert, the connection fails with an SSL error. SSMS works because it prompts users to trust certs; PHP does not.

**Why it happens:** The `config/database.php` sqlsrv stanza has `encrypt` and `trust_server_certificate` commented out. These must be explicitly enabled in the new `quotewerks` connection.

**How to avoid:** Set both in the connection config: `'encrypt' => env('QW_DB_ENCRYPT', 'yes')` and `'trust_server_certificate' => env('QW_DB_TRUST_CERT', 'true')`. Document in `.env.example` that `QW_DB_TRUST_CERT=true` is intentional for internal VPN connections.

**Warning signs:** Connection fails with `SSL Fatal error` or `certificate` in the exception message while SSMS connects fine.

[VERIFIED: PITFALLS.md Pitfall C2; config/database.php lines 112–113 confirmed commented out]

### Pitfall 3: Reserved Word Column Names Break Queries

**What goes wrong:** QuoteWerks column names like `Date`, `Name`, `Type`, `Group` are SQL Server reserved words. Using them unquoted in queries produces syntax errors or wrong results silently.

**Why it happens:** Laravel's query builder does not auto-quote identifiers for `sqlsrv`. `->where('Type', 'HW')` fails because `TYPE` is a reserved word.

**How to avoid:** Always bracket-quote in `QuoteWerksRepository`: `->where('[ItemType]', 'HW')`. Test against real QuoteWerks schema — the actual column names are `[ASSUMED]` in this research and must be verified via `quotewerks:schema`.

**Warning signs:** Empty result sets from queries that should return rows, or SQL syntax errors on otherwise valid-looking queries.

[VERIFIED: PITFALLS.md Pitfall C3]

### Pitfall 4: Wrong Connection Used — Queries Hit MySQL

**What goes wrong:** `DB::table('DocumentItems')` without `->connection('quotewerks')` queries the primary MySQL database, which has no `DocumentItems` table. The error `Table 'laravel_rams.DocumentItems' doesn't exist` is confusing because it looks like a QuoteWerks schema issue.

**Why it happens:** Laravel's `DB::table()` uses the default connection. `QuoteWerksRepository` must always inject the named connection.

**How to avoid:** `QuoteWerksRepository` stores the connection name as a constructor parameter and uses `DB::connection($this->connection)->table(...)` in every method. A feature test asserts the correct connection is used (mock assertion).

[VERIFIED: PITFALLS.md Pitfall M1]

### Pitfall 5: Charset Corruption Stored in MySQL

**What goes wrong:** QuoteWerks data may contain Windows-1252 encoded characters (smart quotes `'`, em-dashes `—`, trademark `™`). These corrupt silently when stored in MySQL `utf8mb4` columns without conversion.

**Why it happens:** SQL Server returns bytes; the PHP driver returns PHP strings; if those bytes are Windows-1252 and not converted, MySQL utf8mb4 rejects them or mangles them.

**How to avoid:** Apply `mb_check_encoding` / `mb_convert_encoding` in `QuoteWerksRepository::str()` before returning any string field. Test with real QuoteWerks product descriptions.

[VERIFIED: PITFALLS.md Pitfall Mi1]

### Pitfall 6: `meta.source` Key Must Be `'quotewerks_sql'` Exactly

**What goes wrong:** If `extracted_data['meta']['source']` is set to `'quotewerks'` instead of `'quotewerks_sql'`, `ProjectDataService` will not recognise it as tier 2 (0.95 confidence) and will fall through to the PDF tier (0.85) or default tier.

**Why it happens:** `ProjectDataService` checks `$extracted['meta']['source'] === 'quotewerks_sql'` exactly (as confirmed in 01-03-SUMMARY.md).

**How to avoid:** Set `meta.source` to `'quotewerks_sql'` (with underscore) in `QuoteWerksImportService`. Add a unit test that asserts this value in the returned array.

[VERIFIED: 01-03-SUMMARY.md — merge priority table, tier 2 condition: `extracted_data.meta.source === 'quotewerks_sql'`]

---

## Code Examples

### Verified: importFromData() call site

```php
// Source: app/Core/Modules/QuoteImport/QuoteImportService.php lines 236–281
// QuoteWerksImportService feeds into this after building $data:
$package = $this->quoteImportService->importFromData($user, [
    'client_name'       => $extracted['client_name'],
    'site_address'      => $extracted['site_address'],
    'name'              => $extracted['project_name'],
    'ref'               => $extracted['qw_number'],
    'works_description' => $extracted['works_description'],
    'extracted_data'    => $extracted,
    'equipment_list'    => $extracted['equipment_list'],
    'cable_list'        => $extracted['cable_hints'] ?? [],
]);
// Returns ProjectPackage — redirect to review as normal
```

### Verified: DB::connection pattern

```php
// Source: STACK.md + PITFALLS.md + config/database.php
$rows = DB::connection('quotewerks')
    ->table('DocumentHeaders')
    ->select(['[DocNo]', '[SoldToCompanyName]', '[DocDate]'])
    ->where('[DocNo]', $reference)
    ->get();
```

### Verified: getPdo() for ping test

```php
// Source: Laravel docs + PITFALLS.md M1
DB::connection('quotewerks')->getPdo();
// Throws PDOException if connection fails — caught in quotewerks:ping handle()
```

### Verified: Alpine.js tab switcher (existing pattern)

```html
<!-- Source: Alpine.js is the project JS framework per CLAUDE.md -->
<div x-data="{ tab: 'pdf' }">
    <div x-show="tab === 'pdf'">...</div>
    <div x-show="tab === 'sql'">...</div>
</div>
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| FreeTDS / pdo_dblib on Linux | Microsoft ODBC Driver + pdo_sqlsrv | ~2016 (Microsoft released official Linux ODBC) | Correct charset handling, SQL Server Auth support |
| ODBC Driver 18 (TLS 1.2 enforced) | ODBC Driver 17 (TLS 1.0+ accepted) | Decision in this project | Avoids self-signed cert rejection on internal VPN |
| Eloquent models on external DBs | Raw `DB::connection()` query builder | Laravel convention for read-only external DBs | No accidental writes, no event firing |

---

## Open Questions

1. **Exact QuoteWerks column names**
   - What we know: Tables are DocumentHeaders, DocumentItems, DocumentItemGroups (D-06). Column names include reserved words (D-15). Common columns likely follow QuoteWerks API naming (DocNo, SoldToCompanyName, etc.).
   - What's unclear: Exact column names cannot be confirmed without a live connection. The research assumes standard QuoteWerks schema names.
   - Recommendation: Wave 0 of planning must include `quotewerks:schema` command. Run it against real QuoteWerks DB before implementing the repository. All column name assumptions are tagged `[ASSUMED]`.

2. **QuoteWerks reference number format**
   - What we know: 21st Century AV quotes begin `21CQ` followed by digits (confirmed in `QuoteParserService` REF_PATTERNS). Full pattern: `21CQ[0-9]{2,15}(?:-[A-Z0-9]{1,10})*`.
   - What's unclear: Whether the `DocNo` column in QuoteWerks stores the full reference or a numeric ID only.
   - Recommendation: Validate input against the `21CQ` regex before querying. If DocNo is numeric only, the lookup requires a separate mapping step.

3. **DocumentItemGroups table structure**
   - What we know: D-06 lists this table; D-07 confirms groups = rooms/zones.
   - What's unclear: Whether group membership is via a foreign key, a `GroupName` text column on DocumentItems, or a separate join table.
   - Recommendation: `quotewerks:schema` command must output column names and sample rows for all three tables before repository implementation.

4. **Production PHP version and web server**
   - What we know: `composer.json` requires PHP ^8.2. pdo_sqlsrv 5.13 supports PHP 8.2–8.5.
   - What's unclear: Whether the production server runs PHP-FPM (requires php-fpm ini changes) or CLI only.
   - Recommendation: When running PECL install, verify both CLI and FPM php.ini directories have the extension enabled.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `pdo_sqlsrv` PHP extension | QWSQL-02 (all SQL queries) | Unknown — must verify on production | — | No fallback; QWSQL-01 is a hard blocker |
| Microsoft ODBC Driver 17 | `pdo_sqlsrv` | Unknown — must verify on production | — | No fallback; prerequisite for extension |
| QuoteWerks SQL Server (network) | QWSQL-02 | Unknown — VPN required | SQL Server 2016/2019 (assumed) | PDF import path (always available) |
| Alpine.js | UI tab toggle (QWSQL-05) | Confirmed present | per `resources/js/app.js` | — |
| `doctrine/dbal` | `quotewerks:schema` command | Confirmed 4.4.3 | 4.4.3 | — |

**Missing dependencies with no fallback:**
- `pdo_sqlsrv` + ODBC Driver 17: Must be installed on production server before any QuoteWerks service code runs. Wave 0 plan task must be "verify `php -m | grep sqlsrv` on production".

**Missing dependencies with fallback:**
- QuoteWerks SQL Server unreachable (VPN down): Graceful error message + fallback suggestion to use PDF import (D-13). The PDF path is always available.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `php artisan test --filter QuoteWerks` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| QWSQL-01 | `quotewerks:ping` returns success when driver present | Unit | `php artisan test --filter QuoteWerksPingCommandTest` | No — Wave 0 |
| QWSQL-01 | `quotewerks:ping` returns failure with clear message when driver absent | Unit | `php artisan test --filter QuoteWerksPingCommandTest` | No — Wave 0 |
| QWSQL-02 | `QuoteWerksRepository` uses `quotewerks` connection, not default | Unit (mock) | `php artisan test --filter QuoteWerksRepositoryTest` | No — Wave 0 |
| QWSQL-03 | `findByReference()` returns mapped header array with internal key names | Unit (mock DB) | `php artisan test --filter QuoteWerksRepositoryTest` | No — Wave 0 |
| QWSQL-03 | `getItemsByDocNo()` returns equipment rows with group_name as area | Unit (mock DB) | `php artisan test --filter QuoteWerksRepositoryTest` | No — Wave 0 |
| QWSQL-03 | Charset conversion applied to Windows-1252 strings | Unit | `php artisan test --filter QuoteWerksRepositoryTest` | No — Wave 0 |
| QWSQL-04 | `QuoteWerksImportService::buildExtractedData()` produces correct `meta.source = 'quotewerks_sql'` | Unit | `php artisan test --filter QuoteWerksImportServiceTest` | No — Wave 0 |
| QWSQL-04 | Produced array passes into `QuoteImportService::importFromData()` and creates ProjectPackage | Feature | `php artisan test --filter QuoteWerksImportFeatureTest` | No — Wave 0 |
| QWSQL-05 | POST /quote-import/quotewerks/lookup creates package and redirects to review | Feature | `php artisan test --filter QuoteWerksImportFeatureTest` | No — Wave 0 |
| QWSQL-05 | Connection failure returns view with error message | Feature | `php artisan test --filter QuoteWerksImportFeatureTest` | No — Wave 0 |
| QWSQL-06 | QW_DB_* env vars read correctly; credentials not exposed in any response | Unit | `php artisan test --filter QuoteWerksImportServiceTest` | No — Wave 0 |
| QWSQL-07 | `php artisan quotewerks:ping` outputs success/failure with correct exit code | Unit | `php artisan test --filter QuoteWerksPingCommandTest` | No — Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --filter QuoteWerks`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/QuoteWerksRepositoryTest.php` — covers QWSQL-02, QWSQL-03, charset
- [ ] `tests/Unit/QuoteWerksImportServiceTest.php` — covers QWSQL-04, QWSQL-06
- [ ] `tests/Feature/QuoteWerksImportFeatureTest.php` — covers QWSQL-04, QWSQL-05
- [ ] `tests/Unit/QuoteWerksPingCommandTest.php` — covers QWSQL-01, QWSQL-07

Note: All QuoteWerks tests must mock `DB::connection('quotewerks')` — no live SQL Server connection in CI. Use Mockery or Laravel's `DB::shouldReceive()` pattern.

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No — import action is behind existing auth middleware | Existing `auth` middleware on all routes |
| V3 Session Management | No — no new session state introduced | — |
| V4 Access Control | Yes — import route must be user-scoped | `auth()->user()` passed to `importFromData()`; `abort_unless()` if needed |
| V5 Input Validation | Yes — quote reference input and search terms | `QuoteWerksLookupRequest` with validation rules; reference validated against `21CQ` regex |
| V6 Cryptography | No — no new crypto | — |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SQL injection via quote reference field | Tampering | Laravel query builder with parameterized bindings (`->where('[DocNo]', $reference)` — never string concat) |
| Credential exposure via error messages | Information Disclosure | Catch `PDOException` in service; log full message, return user-safe "Could not connect to QuoteWerks" only |
| SSRF via QW_DB_HOST | Elevation of Privilege | `QW_DB_HOST` is env-only, never user-controlled; no route accepts a host parameter |
| Over-broad search returning other clients' data | Information Disclosure | Search results are read-only QuoteWerks data; user cannot modify; accept that QuoteWerks is a shared internal system |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Exact QuoteWerks column names: `DocNo`, `SoldToCompanyName`, `ShipToAddress1`, `ShipToCity`, `DocDate`, `SalesPerson`, `Notes`, `ItemType`, `Quantity`, `ManufacturerPartNumber`, `Description`, `GroupName`, `SortOrder` | Architecture Patterns / Code Examples | Repository queries fail; must be corrected after `quotewerks:schema` run |
| A2 | `GroupName` is a column on `DocumentItems` (not a join to `DocumentItemGroups`) | Pattern 2 | Wrong query shape; may need a JOIN to `DocumentItemGroups` |
| A3 | `DocNo` stores the full `21CQxxxxx` reference string (not a numeric surrogate key) | Open Questions | Lookup by reference number fails; need separate mapping |
| A4 | QuoteWerks SQL Server version is 2016 or 2019 | Standard Stack | Older versions may not support all ODBC 17 features; unlikely to be an issue |
| A5 | PHP-FPM and CLI share the same php.ini directory on the production server | Environment Availability | Extension enabled for CLI but not FPM; web requests fail while `php artisan` works |

---

## Project Constraints (from CLAUDE.md)

All directives apply to this phase:

- **AI usage:** No AI in Phase 2. SQL import is structured data — no AI allowed. `QuoteWerksImportService` must not call `AIManager`.
- **Data integrity:** All `extracted_data` content traces to QuoteWerks SQL rows. No fields invented or defaulted without explicit fallback documentation.
- **Existing pipeline:** `QuoteImportService::import()` (PDF path) and `importFromData()` must remain unmodified. SQL import is additive, not a replacement.
- **Architecture:** Thin controller (`QuoteWerksImportController`), dedicated service (`QuoteWerksImportService`), repository pattern (`QuoteWerksRepository`). No QuoteWerks SQL in controllers.
- **SQL security:** QuoteWerks connection is read-only by SQL Server user permissions. Never issue INSERT/UPDATE/DELETE against `quotewerks` connection. No frontend exposure of `QW_DB_*` credentials.
- **Naming conventions:** `QuoteWerksImportService` (Service suffix), `QuoteWerksRepository` (no suffix — matches repository pattern), `QuoteWerksImportController` (Controller suffix), `QuoteWerksLookupRequest` (Request suffix), `QuoteWerksPing` / `QuoteWerksSchema` commands (no suffix on commands per `CreateDocxTemplates` precedent).
- **Code style:** Laravel Pint (PSR-12), 4-space indent, ASCII dividers between controller methods, PHPDoc on all public methods.
- **Logging:** Prefix all log messages: `'QuoteWerksImportService: ...'`, include `user_id`, `doc_no`, `package_id` in context arrays.
- **Error handling:** Connection errors caught and mapped to user-safe messages. Never rethrow raw `PDOException` to user.

---

## Sources

### Primary (HIGH confidence)
- `app/Core/Modules/QuoteImport/QuoteImportService.php` — `importFromData()` signature and body verified; `mergeParsedQuoteData()` equipment array shape confirmed
- `config/database.php` lines 101–114 — existing sqlsrv stanza confirmed as template; TLS options confirmed commented out
- `.planning/research/PITFALLS.md` — Pitfalls C1, C2, C3, M1, Mi1 verified against codebase evidence
- `.planning/research/STACK.md` — ODBC 17 recommendation, pdo_sqlsrv 5.13, no Composer package required; verified against Microsoft docs (fetched 2026-04-09)
- `.planning/phases/01-project-layer-data-foundation/01-03-SUMMARY.md` — `meta.source === 'quotewerks_sql'` trigger for 0.95 confidence tier confirmed
- `resources/views/quote-import/create.blade.php` — single-card structure confirmed; tab insertion point clear
- `app/Http/Controllers/QuoteImportController.php` — existing routes and review flow confirmed
- `routes/web.php` — existing quote-import route group confirmed

### Secondary (MEDIUM confidence)
- QuoteWerks table names (DocumentHeaders, DocumentItems, DocumentItemGroups) from D-06 in CONTEXT.md — user-confirmed decision, not independently verified against live schema
- QuoteWerks reference format `21CQ...` from `QuoteParserService::REF_PATTERNS` — confirmed for PDF path; assumed same in SQL

### Tertiary (LOW confidence — verify via `quotewerks:schema`)
- Exact column names in all three tables (see Assumptions Log A1–A3)

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified in STACK.md against Microsoft official docs; no new Composer packages
- Architecture: HIGH for patterns; LOW for exact QuoteWerks column names (requires live verification)
- Pitfalls: HIGH — derived directly from codebase evidence in PITFALLS.md
- `meta.source` key value: HIGH — verified from Phase 1 completed code

**Research date:** 2026-04-10
**Valid until:** 2026-05-10 (stable domain; QuoteWerks schema does not change frequently)

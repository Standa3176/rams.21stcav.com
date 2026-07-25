---
name: 260723-qw1-quotewerks-direct-import-fix
description: Replace RAMS's non-functional QuoteWerks import (wrong driver, wrong columns, no revision handling) with the verified pattern from the SCC sibling app. Adapts SCC's fetcher + exception to RAMS namespaces; keeps RAMS's Blade + flash-message conventions rather than SCC's SPA modal.
status: in-progress
tasks: 5
---

# QuoteWerks direct-import — replace dead code with verified SCC pattern

## Why

RAMS ships a "QuoteWerks Lookup" tab on `/quote-import` that has never functioned in production. Deep-read of the code reveals it's fundamentally wrong on every dimension:

| Layer | Current RAMS (broken) | Verified (SCC + user's task) |
|---|---|---|
| Driver | `sqlsrv` (native `pdo_sqlsrv` PECL ext — NOT installed on VPS) | `odbc` via system DSN `[QUOTEWERKS_PROD]` (`pdo_odbc` — is installed) |
| Env vars | `QW_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD/ENCRYPT/TRUST_CERT/TIMEOUT` (8 vars, ignored on VPS) | `QUOTEWERKS_ODBC_DSN/USER/PASS` (3 vars) |
| Header table | `DocumentHeaders` | `DocumentHeaders` ✓ |
| Client column | `SoldToCompanyName` ❌ (doesn't exist) | `SoldToCompany` |
| Site column | `SoldToAddress1/2/City/State/PostalCode` ❌ (SoldTo* is billing, not site) | `ShipToCompany` (site name) + `ShipToAddress1/2/City/PostalCode` |
| Scope column | `Subject` ❌ (doesn't exist) | `CustomMemo01` |
| Prepared-by | `SalesPerson` ❌ | `PreparedBy` |
| Value | `TotalSalePrice` ❌ | `Subtotal` / `GrandTotal` |
| Item table | `DocumentItems` ✓ | `DocumentItems` ✓ |
| Item type | `ItemType` string filter `['P','G']` ❌ | `LineType` int bitmask `IN (1, 32, 256)` (product / section header / subsection header) |
| Grouping | `GroupName` column ❌ (doesn't exist) | Walk `LineType` 32/256 header rows, thread `$currentRoom` through subsequent LineType 1 products |
| Ordering | `SortOrder` column ❌ (doesn't exist) | `ORDER BY ID` — item table is a heap; PK ordering = operator's row order |
| Part-number | `ManufacturerPartNumber` ✓ | `ManufacturerPartNumber` ✓ |
| Description | `Description` (long form) | Prefer `Notes` (human-readable); `Description` as fallback |
| Revision handling | None — matches any DocNo | `WHERE (DocNo = ? OR RevisionMasterDocNo = ?) AND Superceeded = 0` (typo `Superceeded` is native QW) |
| Exception | None specific | `App\Exceptions\QuoteWerksUnreachableException extends RuntimeException` |

Existing files touching this dead surface:
- `app/Http/Controllers/QuoteWerksImportController.php` — 148 lines, uses broken service
- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` — 200 lines, broken column shape
- `app/Core/Modules/QuoteImport/QuoteWerksRepository.php` — 165 lines, comment literally says `[ASSUMED] from standard QuoteWerks API conventions` (wrong assumption)
- `app/Http/Requests/QuoteWerksLookupRequest.php` — kept, validates `reference` + `client_name` + `date_from`
- `app/Console/Commands/QuoteWerksPing.php` — smoke-test command (needs adapting)
- `app/Console/Commands/QuoteWerksSchema.php` — schema-explorer command (delete: schema now verified)
- `config/database.php` — `quotewerks` block (needs rewrite)
- `resources/views/quote-import/create.blade.php` lines 257-333 — dual-tab UI (keeps working, minor wiring updates)
- `tests/Unit/QuoteWerksImportServiceTest.php` — needs full rewrite for new shapes

## Design choices (locked)

1. **Traditional Blade + flash messages** — NOT SCC's Alpine.js SPA + JSON + modal. RAMS's `/quote-import` page uses server-side form POST + flash + redirect; SCC's wizard is a single-page Alpine app. Match RAMS's house style per the user's task ("Match RAMS's own conventions").
2. **Keep existing routes** — `/quote-import/quotewerks/lookup` (POST → redirect to review) and `/quote-import/quotewerks/search` (POST → redirect back with results in session). No URL changes = no bookmark breakage.
3. **Fetcher shape** — port SCC's `mapToParsedShape` verbatim (top-level keys `client, site, site_name, ref, prepared_by, scope_narrative, contact_*, equipment[], rooms[]`).
4. **RAMS-side mapper** — `QuoteWerksImportService::buildExtractedData` now consumes the SCC-shape fetcher output and produces RAMS's canonical `extracted_data` shape (same `equipment`, `equipment_list`, `line_items`, `rooms`, `meta.source='quotewerks_sql'` keys — those are RAMS-internal and stable).
5. **Dup-check** — server-side. Before running the ODBC fetch, query `project_packages` for any prior package with `extracted_data->>quote_ref = ?`. If found and `?force=1` not present in the request, redirect back with a warning flash showing "Quote XYZ was imported on {date} as {project name}. Import anyway?" + link with `?force=1`. Not a modal — RAMS uses flash messages, this fits.
6. **Search-by-client** — kept but rewritten to use the verified `SoldToCompany` column with `TOP 20 ... ORDER BY DocDate DESC`.
7. **No JSON responses** — controller returns `RedirectResponse` throughout, matching the existing controller pattern. If we later need an AJAX flow (e.g. inline search-as-you-type), that's a separate follow-up.

## Task 1 — Foundations: config + provider + env + exception

**Files:**
- `config/database.php` — replace the `quotewerks` block (lines 115-134):
  ```php
  // ── QuoteWerks SQL Server (read-only, named connection) ───────────────
  // Never set as default. No Eloquent models bound to this connection.
  // Uses generic ODBC via a system DSN registered on the VPS at /etc/odbc.ini
  // (see WireGuard tunnel setup — 260723-qw1). Requires pdo_odbc (built into
  // most PHP distros; confirm with `extension_loaded('pdo_odbc')`).
  //
  // Lazy resolution — DB::extend('odbc', ...) in AppServiceProvider only
  // fires on first DB::connection('quotewerks') call. Local dev with blank
  // env vars is unaffected; connection only works on the live VPS.
  //
  // Verify: php artisan quotewerks:ping
  'quotewerks' => [
      'driver'   => 'odbc',
      'dsn'      => env('QUOTEWERKS_ODBC_DSN'),
      'username' => env('QUOTEWERKS_ODBC_USER'),
      'password' => env('QUOTEWERKS_ODBC_PASS'),
      'options'  => [],
  ],
  ```
- `app/Providers/AppServiceProvider.php` — inside `register()`, add:
  ```php
  // ── ODBC driver resolver ──────────────────────────────────────────────
  // Laravel 12 has no built-in `odbc` driver. Register a minimal resolver
  // that wraps PDO in Illuminate\Database\Connection. LAZY — only fires
  // when DB::connection('quotewerks') is first used, so blank env vars
  // never break boot/migrate/tinker. Ported from service.21stcav.com
  // (260723-qw1).
  DB::extend('odbc', function (array $config, string $name): \Illuminate\Database\Connection {
      $dsn      = (string) ($config['dsn'] ?? '');
      $username = $config['username'] ?? null;
      $password = $config['password'] ?? null;
      $options  = $config['options'] ?? [];

      // No try/catch — QuoteWerksDbFetcher::fetch() catches PDOException
      // at the call site and re-throws as QuoteWerksUnreachableException.
      $pdo = new \PDO($dsn, $username, $password, $options);

      // Connection ctor: ($pdo, $database, $tablePrefix, $config). ODBC
      // has no per-connection database (DSN abstracts it); prefix unused.
      return new \Illuminate\Database\Connection($pdo, '', '', $config);
  });
  ```
  Add `use Illuminate\Support\Facades\DB;` at the top if not already present. `PDO` stays fully-qualified as `\PDO` — no `use` needed.
- `.env.example` — replace the 8 `QW_DB_*` vars with:
  ```
  # QuoteWerks (live VPS only — blank locally). System ODBC DSN registered
  # at /etc/odbc.ini as [QUOTEWERKS_PROD]. Password lives in
  # /home/stcav/service.21stcav.com/.env on the VPS — DO NOT invent one.
  QUOTEWERKS_ODBC_DSN=
  QUOTEWERKS_ODBC_USER=
  QUOTEWERKS_ODBC_PASS=
  ```
- `app/Exceptions/QuoteWerksUnreachableException.php` — new file:
  ```php
  <?php
  declare(strict_types=1);
  namespace App\Exceptions;
  use RuntimeException;
  /**
   * Thrown when QuoteWerks direct import cannot reach the SQL Server (WireGuard
   * tunnel down, ODBC DSN misconfigured, credentials wrong, etc). Deliberately
   * extends RuntimeException — NOT a Laravel DB exception — so it carries no
   * SQL state metadata. Caller (QuoteWerksImportController) converts to a
   * user-safe flash message.
   *
   * Ported from service.21stcav.com (260723-qw1).
   */
  class QuoteWerksUnreachableException extends RuntimeException
  {
  }
  ```

**Commit:** `feat(quotewerks): odbc driver resolver + config + exception (260723-qw1)`

## Task 2 — DB Fetcher (port SCC verbatim, RAMS namespace)

**Files:**
- `app/Services/Imports/Quote/QuoteWerksDbFetcher.php` — new file. Port SCC's `QuoteWerksDbFetcher` verbatim, changing only the namespace and the `use App\Exceptions\QuoteWerksUnreachableException;` reference. Keep:
  - `declare(strict_types=1)`
  - No constructor, no dependencies
  - `fetch(string $docNo): array` — wraps `fetchHeader` + `fetchItems` in try/catch, re-throws PDOException as QuoteWerksUnreachableException
  - `mapToParsedShape(array $header, array $items): array` — pure transformation, outputs `client, site, site_name, ref, prepared_by, scope_narrative, contact_name/phone/email (all null — deferred), equipment[], rooms[]`
  - `fetchHeader(string $docNo): ?array` — exact SQL from user's task
  - `fetchItems(int $docID): array` — exact SQL from user's task
  - `normalizeUtf8Row(array $row): array` — Windows-1252 → UTF-8
  - `mapEquipmentRow(array $item): array` — Notes preferred, Description fallback; part_number, area, location, qty, unit_price, manufacturer
  - Section-header threading: LineType 32/256 → update `$currentRoom` + append to `$roomNames`; LineType 1 with non-null `$currentRoom` → overwrite `area = $currentRoom`

- `tests/Unit/Services/Imports/QuoteWerksDbFetcherTest.php` — unit tests for `mapToParsedShape`:
  - Header-only (empty items) returns empty equipment + rooms
  - LineType 32 header + 3 products → 3 equipment rows with `area = header text`
  - Notes preferred over Description (Notes wins)
  - Description used when Notes empty (fallback)
  - Nested LineType 256 subsection updates room mid-walk
  - QtyBase 0/null → qty 1 minimum
  - Windows-1252 bytes normalise to UTF-8 (curly quote round-trip)
  - `SoldToCompany` → `client`, `ShipToCompany` → `site_name` (Wave-0 confirmed)

  Do NOT test `fetch()` itself against a real DB — that's an integration test, out of scope for the unit test file. Mock the DB layer if we test it at all.

**Gates:** `--filter QuoteWerksDbFetcher` all green, `php -l` clean on both files.

**Commit:** `feat(quotewerks): port DB fetcher + mapping from SCC (260723-qw1)`

## Task 3 — Wire into RAMS ingestion

**Files:**
- `app/Http/Controllers/QuoteWerksImportController.php` — rewrite. Signature changes:
  ```php
  public function __construct(private readonly QuoteWerksImportService $qwImportService) {}

  public function lookup(QuoteWerksLookupRequest $request, QuoteWerksDbFetcher $fetcher): RedirectResponse
  ```
  Flow:
  1. `$reference = strtoupper(trim($request->validated('reference', '')));` — reject empty
  2. Dup-check: `ProjectPackage::whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extracted_data, '$.quote_ref')) = ?", [$reference])->latest('created_at')->first()`. If found and `!$request->boolean('force')`, redirect back with warning flash + link containing `?force=1&reference={ref}`.
  3. `try { $result = $fetcher->fetch($reference); } catch (QuoteWerksUnreachableException $e) { Log::error(...); return back()->with('error', 'Cannot reach QuoteWerks right now — please upload the quote PDF instead.')->withInput(); }`
  4. If `$result['header'] === null` → back with error "Quote {ref} not found in QuoteWerks."
  5. Wrong doc type check: `strcasecmp($result['header']['DocType'] ?? '', 'QUOTE') !== 0` → back with error "Document {ref} is not a Quote (it is a {docType})."
  6. `$parsed = $fetcher->mapToParsedShape($result['header'], $result['items']);`
  7. `$package = $this->qwImportService->importFromParsedShape($request->user(), $parsed);` — new method (see below)
  8. Redirect to `route('quote-import.review', $package)` with success flash.

  Update `search()` similarly to use the fetcher's SQL against `SoldToCompany` — inline the search SQL in a private helper on the controller, OR add a `searchByClient` method to the fetcher (prefer the fetcher — colocates the QW knowledge). Emit `use App\Services\Imports\Quote\QuoteWerksDbFetcher;`.

- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` — rewrite `buildExtractedData` + `importByReference` + `searchByClient`. Constructor stays `QuoteImportService` injection but DROP the `QuoteWerksRepository` dependency (the class is being deleted). Add `importFromParsedShape(User $user, array $parsedShape): ProjectPackage` — thin wrapper that:
  1. Calls `buildExtractedData($parsedShape)` — new sig (takes SCC-shape array, not header+items)
  2. Delegates to `$this->importService->importFromData($user, [...])` with the RAMS-canonical payload
  3. Logs the import

  `buildExtractedData(array $parsedShape): array` new shape:
  ```php
  // Input: SCC-shape output from QuoteWerksDbFetcher::mapToParsedShape
  //   ['client', 'site', 'site_name', 'ref', 'prepared_by', 'scope_narrative',
  //    'contact_name', 'contact_phone', 'contact_email', 'equipment' => [...], 'rooms' => [...]]

  $equipment = array_map(fn ($item) => [
      'quantity'    => $item['qty'],
      'qty'         => $item['qty'],
      'part_number' => $item['part_number'],
      'part_no'     => $item['part_number'],
      'name'        => $item['description'],
      'description' => $item['description'],
      'area'        => $item['area'],
      'location'    => $item['location'] ?? $item['area'],
      'category'    => $this->classifyDescription($item['description']),
      'unit_price'  => (float) ($item['unit_price'] ?? 0),
      'total_price' => (float) ($item['unit_price'] ?? 0) * (int) $item['qty'],
      'data_source' => 'quotewerks',
      'confidence'  => 0.95,
  ], $parsedShape['equipment']);

  return [
      'qw_number'         => $parsedShape['ref'] ?? '',
      'quote_ref'         => $parsedShape['ref'] ?? '',
      'client_name'       => $parsedShape['client'] ?? '',
      'site_name'         => $parsedShape['site_name'] ?? '',    // NEW — was missing
      'site_address'      => $parsedShape['site'] ?? '',
      'project_name'      => $parsedShape['scope_narrative'] ? Str::limit($parsedShape['scope_narrative'], 80, '') : ($parsedShape['ref'] ?? ''),
      'works_description' => $parsedShape['scope_narrative'] ?? '',
      'prepared_by'       => $parsedShape['prepared_by'] ?? '',  // NEW
      'total_price'       => 0.0,   // Fetcher doesn't currently surface — TODO in a follow-up
      'equipment'         => $equipment,
      'equipment_list'    => $equipment,
      'line_items'        => $equipment,
      'cable_hints'       => [],
      'rooms'             => $parsedShape['rooms'] ?? [],
      'meta'              => [
          'source'            => 'quotewerks_sql',   // Tier-2 confidence trigger — do NOT change
          'confidence'        => 0.95,
          'parser_confidence' => 0.95,
          'data_source'       => 'quotewerks',
          'item_count'        => count($equipment),
          'room_count'        => count($parsedShape['rooms'] ?? []),
      ],
  ];
  ```

  `classifyDescription()` — keep as-is (unchanged, already correct).

- `app/Core/Modules/QuoteImport/QuoteWerksRepository.php` — **DELETE** (all wrong columns; fetcher replaces it).
- `app/Console/Commands/QuoteWerksSchema.php` — **DELETE** (was for schema exploration; schema now verified).
- `app/Console/Commands/QuoteWerksPing.php` — update to use `DB::connection('quotewerks')->getPdo()` + `SELECT TOP 1 DocNo FROM DocumentHeaders`. Keep the command name `quotewerks:ping` — that's called out in the user's task as a VPS smoke test.

- `tests/Unit/QuoteWerksImportServiceTest.php` — full rewrite for the new shapes. Cases:
  - `buildExtractedData` from a well-formed parsedShape produces the RAMS canonical shape
  - `classifyDescription` (unchanged) still classifies known keywords
  - `importFromParsedShape` orchestrates fetcher → service correctly (mock QuoteImportService)

**Gates:**
- Existing broader `--filter QuoteWerks|QuoteImport` must still pass (or updated to match new shapes)
- No file uses `QuoteWerksRepository` after the delete (grep verify before commit)

**Commit:** `feat(quotewerks): rewire controller + service to use new fetcher; delete dead repo + schema-explorer (260723-qw1)`

## Task 4 — UI polish + dup-check flash

**Files:**
- `resources/views/quote-import/create.blade.php` lines 257-333:
  - **Lookup form** — unchanged POST target (`route('quotewerks.lookup')`), but wrap the `reference` input in `<div>` with a small tip: "Live revision only — matches on DocNo OR RevisionMasterDocNo."
  - **Warning flash rendering** — around line 12-18 (where success/warning/error flashes render), ensure `session('warning')` displays with a "Continue anyway →" link. The link URL: `{{ route('quotewerks.lookup') }}?force=1&reference=<old ref>` — but since flash rendering happens in Blade, use the pattern `<a href="{{ url()->current() }}?force=1">Continue</a>` OR (cleaner) surface the force link via a session key `session('qw_force_url')`.
  - **Search results table** — unchanged, still works with the new fetcher output.
  - Add helper text: "QuoteWerks is only available on the live VPS via the office tunnel."

**Gates:**
- `npm run build` succeeds
- Manual test on VPS: enter valid quote number → success; enter invalid → 'not found' flash; enter duplicate → warning flash with continue link; break WireGuard temporarily → 'cannot reach' flash.

**Commit:** `feat(quotewerks): dup-check flash + tip copy on lookup UI (260723-qw1)`

## Task 5 — STATE + SUMMARY + push + VPS deploy notes

**Files:**
- `.planning/STATE.md` — add row for 260723-qw1 (above 260723-eq1)
- `.planning/quick/260723-qw1-quotewerks-direct-import-fix/SUMMARY.md` — standard closeout

**Deploy on VPS (as `stcav`):**
```bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache

# Copy the QuoteWerks env vars from SCC's .env (already correct there):
grep '^QUOTEWERKS_ODBC_' /home/stcav/service.21stcav.com/.env >> .env
php artisan config:cache

# Smoke test the connection under the web PHP:
/usr/local/bin/php artisan quotewerks:ping

# Full round-trip test with a known-good quote number:
/usr/local/bin/php artisan tinker --execute="
  \$f = app(App\Services\Imports\Quote\QuoteWerksDbFetcher::class);
  \$r = \$f->fetch('21CQ14213');
  echo 'header: ', (\$r['header'] ? \$r['header']['DocNo'] : 'null'), PHP_EOL;
  echo 'items: ', count(\$r['items']), PHP_EOL;
"
```

Test quote numbers from the task: `21CQ14213`, `21CQ29531-05-OPS`.

**Commit:** `docs(quick-260723-qw1): PLAN + SUMMARY + STATE for QuoteWerks direct-import`

## Global constraints

- **Read-only against QuoteWerks** — SELECT only. Never INSERT/UPDATE/DELETE.
- **`php -l` after every PHP edit.**
- **`npm run build` before commits touching Blade/JS.**
- **Existing broader tests** (`--filter QuoteImport`) must stay green (or be updated).
- **No new npm deps.**
- **Do NOT touch VPS infra** — no /etc/odbc.ini edits, no CSF changes, no WireGuard config. All infra is already in place per user's task.
- **Match RAMS conventions** — Blade + flash messages + form POST → redirect, NOT SCC's SPA + JSON + modal.
- Commit prefix `feat(quotewerks):` for functional, `docs(quick-…)` for closeout.

## Explicit non-goals

- **JSON API + SPA modal** — that's SCC's pattern for its Alpine wizard. RAMS uses server-flash Blade. Keep the delta small.
- **Contact fetch** — SCC defers `contact_name/phone/email` (they'd need a JOIN to a Contacts table). RAMS also defers. Add later if PM asks.
- **Total price** — Fetcher's `mapToParsedShape` doesn't currently surface a project-level total (SCC gets it from Subtotal on the header row separately). Add a `total_price` key in a follow-up if the Review page needs it.
- **Activity log audit** — SCC writes to `activity_logs` on every attempt with 9+ metadata keys. RAMS's `Log::info` is enough for now; wire an audit trail later if needed.
- **Bulk import** — one quote at a time.
- **RAMS-side handling of pre-approval PDF QuoteWerks re-extract** — the `re-extract` route (line 225 in web.php) is PDF-only. QuoteWerks re-extract would be a separate op. Follow-up.

## Deploy summary

- **No DB migrations.** Config changes only.
- **New env vars** — must be pasted onto VPS .env from SCC's .env (password is not in git).
- **New PHP files** — 3 (exception, fetcher, updated service/controller are rewrites).
- **Deleted files** — 2 (Repository, Schema command).
- **Post-deploy smoke** — `artisan quotewerks:ping` + tinker fetch.

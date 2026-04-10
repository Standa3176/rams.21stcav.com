---
phase: 02-quotewerks-sql-import
plan: "01"
subsystem: database-connectivity
tags: [quotewerks, sql-server, artisan-commands, configuration]
dependency_graph:
  requires: []
  provides:
    - quotewerks named DB connection (config/database.php)
    - quotewerks:ping health check command
    - quotewerks:schema schema exploration command
    - QW_DB_* env var template with ODBC 17 install instructions
  affects:
    - config/database.php
    - .env.example
tech_stack:
  added: []
  patterns:
    - Named Laravel DB connection (sqlsrv driver, read-only)
    - Artisan command with self::SUCCESS/FAILURE exit codes
    - Extension detection before connection attempt
key_files:
  created:
    - app/Console/Commands/QuoteWerksPing.php
    - app/Console/Commands/QuoteWerksSchema.php
  modified:
    - config/database.php
    - .env.example
decisions:
  - "Used QW_DB_* prefix (not QW_*) to avoid future variable collision"
  - "encrypt=yes and trust_server_certificate=true as env-overridable defaults for self-signed cert VPN connections (per D-03, D-04)"
  - "login_timeout=5 prevents indefinite hangs when VPN is down"
  - "pdo_sqlsrv extension check fires before connection attempt — provides actionable install path, not a PHP fatal"
  - ".env.example force-added to git because parent .gitignore has .env.* which is too broad; .env.example is a template, not a secrets file"
metrics:
  duration_minutes: 15
  completed_date: "2026-04-10"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 2
requirements_fulfilled:
  - QWSQL-01
  - QWSQL-06
  - QWSQL-07
---

# Phase 02 Plan 01: QuoteWerks SQL Connection Foundation Summary

**One-liner:** Named sqlsrv connection with TLS/timeout config plus quotewerks:ping (driver+connectivity check) and quotewerks:schema (table/column inspection) artisan commands.

## What Was Built

### Task 1 — Named DB Connection + Env Template (d66a8e0)

Added the `quotewerks` named connection to `config/database.php` inside the existing `connections` array, after the `sqlsrv` stanza. The connection:

- Uses `sqlsrv` driver with ODBC Driver 17 (system-level, not configured here)
- All credentials via `QW_DB_*` env vars; no defaults set for username/password
- `encrypt=yes` and `trust_server_certificate=true` as defaults (self-signed cert on internal VPN, per D-03/D-04)
- `login_timeout=5` prevents hangs when VPN is disconnected
- Never set as default; no `url` key (would override all other keys if set)

Updated `.env.example` with a fully-commented `QW_DB_*` block including step-by-step ODBC Driver 17 + pdo_sqlsrv PECL installation instructions for Ubuntu/Debian.

### Task 2 — Artisan Commands (824a180)

**`quotewerks:ping`** (`app/Console/Commands/QuoteWerksPing.php`):
- Checks `extension_loaded('pdo_sqlsrv')` first — if missing, prints install instructions and exits 1 (never crashes with a PHP fatal)
- On success: connects via `DB::connection('quotewerks')->getPdo()`, prints SQL Server version string
- On failure: prints categorised common causes (VPN, host/port, credentials, TLS)

**`quotewerks:schema`** (`app/Console/Commands/QuoteWerksSchema.php`):
- Options: `--table=TableName` (single table), `--sample=N` (row count, default 3)
- Default: lists all tables from `INFORMATION_SCHEMA.TABLES`, marking the three import targets with `<- import target`
- Then inspects `DocumentHeaders`, `DocumentItems`, `DocumentItemGroups` with column metadata and sample rows
- Uses bracket-quoted table names (`[TableName]`) to handle SQL Server reserved words (per D-15)
- Development tool only; not exposed via HTTP

Both commands follow `CreateDocxTemplates` pattern: `namespace App\Console\Commands`, `handle(): int`, `self::SUCCESS`/`self::FAILURE`. Auto-discovered by Laravel 12 — no Kernel.php registration.

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as written.

### Notes

**`.env.example` force-added to git:** The parent directory `.gitignore` contains `.env.*` which inadvertently excludes `.env.example`. Since `.env.example` is a template with no secrets (all values are blank or placeholder), it was force-added with `git add -f`. This is standard Laravel practice — `.env.example` should always be committed.

## Verification Results

| Check | Result |
|-------|--------|
| `quotewerks` connection block in config/database.php | PASS |
| `trust_server_certificate` bound to `QW_DB_TRUST_CERT` | PASS |
| `QW_DB_HOST` in .env.example | PASS |
| ODBC 17 install instructions in .env.example | PASS |
| No hardcoded credentials in config/ | PASS |
| Default DB connection unchanged (sqlite env default) | PASS |
| pdo_sqlsrv extension check in QuoteWerksPing | PASS |
| `DB::connection('quotewerks')` in both commands | PASS |
| All three TARGET_TABLES present in QuoteWerksSchema | PASS |

## Known Stubs

None — this plan delivers configuration and CLI tooling only. No UI components, no data rendering.

## Threat Flags

No new threat surface introduced beyond what was modelled in the plan's threat register. All connection credentials flow only via env vars. No HTTP routes created.

## Self-Check: PASSED

Files confirmed present:
- `config/database.php` — modified, contains `quotewerks` connection
- `.env.example` — created/tracked, contains `QW_DB_*` block
- `app/Console/Commands/QuoteWerksPing.php` — created
- `app/Console/Commands/QuoteWerksSchema.php` — created

Commits confirmed:
- `d66a8e0` — Task 1 (config + env template)
- `824a180` — Task 2 (artisan commands)

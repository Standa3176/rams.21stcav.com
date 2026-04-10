---
phase: 2
slug: quotewerks-sql-import
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-10
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.3 |
| **Config file** | phpunit.xml |
| **Quick run command** | `php artisan test --filter QuoteWerks` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter QuoteWerks`
- **After every plan completion:** Run `php artisan test`
- **Before phase verification:** Full test suite

---

## Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| QWSQL-01 | quotewerks:ping returns success when driver present | Unit | `php artisan test --filter QuoteWerksPingCommandTest` | No — Wave 0 |
| QWSQL-01 | quotewerks:ping returns failure with clear message when driver absent | Unit | Same | No — Wave 0 |
| QWSQL-02 | QuoteWerksRepository uses 'quotewerks' connection, not default | Unit (mock) | `php artisan test --filter QuoteWerksRepositoryTest` | No — Wave 0 |
| QWSQL-03 | findByReference() returns mapped header array with internal key names | Unit (mock DB) | Same | No — Wave 0 |
| QWSQL-03 | getItemsByDocNo() returns equipment rows with group_name as area | Unit (mock DB) | Same | No — Wave 0 |
| QWSQL-03 | Charset conversion applied to Windows-1252 strings | Unit | Same | No — Wave 0 |
| QWSQL-04 | buildExtractedData() produces correct meta.source='quotewerks_sql' | Unit | `php artisan test --filter QuoteWerksImportServiceTest` | No — Wave 0 |
| QWSQL-04 | Produced array passes into importFromData() and creates ProjectPackage | Feature | `php artisan test --filter QuoteWerksImportFeatureTest` | No — Wave 0 |
| QWSQL-05 | POST /quote-import/quotewerks/lookup creates package and redirects to review | Feature | Same | No — Wave 0 |
| QWSQL-05 | Connection failure returns view with error message | Feature | Same | No — Wave 0 |
| QWSQL-06 | QW_DB_* env vars read correctly; credentials not exposed in any response | Unit | `php artisan test --filter QuoteWerksImportServiceTest` | No — Wave 0 |
| QWSQL-07 | php artisan quotewerks:ping outputs success/failure with correct exit code | Unit | `php artisan test --filter QuoteWerksPingCommandTest` | No — Wave 0 |

---

## Wave 0 Gaps

- [ ] `tests/Unit/QuoteWerksRepositoryTest.php` — covers QWSQL-02, QWSQL-03, charset
- [ ] `tests/Unit/QuoteWerksImportServiceTest.php` — covers QWSQL-04, QWSQL-06
- [ ] `tests/Feature/QuoteWerksImportFeatureTest.php` — covers QWSQL-04, QWSQL-05
- [ ] `tests/Unit/QuoteWerksPingCommandTest.php` — covers QWSQL-01, QWSQL-07

Note: All QuoteWerks tests must mock `DB::connection('quotewerks')` — no live SQL Server connection in CI.

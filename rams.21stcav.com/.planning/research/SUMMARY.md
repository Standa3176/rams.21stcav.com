# Research Summary

**Project:** RAMS Platform — AV Operations System
**Date:** 2026-04-09
**Sources:** STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md

## Executive Summary

The existing codebase delivers a working RAMS pipeline, rich site survey system, cable schedule XLSX generation, and scaffolded O&M manual service. The fundamental problem this milestone solves is structural: every document generator currently resolves project data independently. There is no single source of truth, corrections do not propagate, and some outputs rely on AI extraction rather than structured data. This is an architectural gap, not a feature gap.

The approach: introduce a single `ProjectDataService` as a read-only data merger that all generators consume, pair it with a direct QuoteWerks SQL import path, and build/refactor each document generator to consume from that unified layer.

## Stack Recommendations

### Add
- `pdo_sqlsrv` + `sqlsrv` PECL extensions + Microsoft ODBC Driver 17 — server-level install for MS SQL connectivity
- `phpoffice/phpspreadsheet` ^2.0 — structured cable schedule XLSX authoring
- Laravel named connection `quotewerks` — raw query builder only, never default, never Eloquent

### Already Have (Extend)
- `phpoffice/phpword` 1.4.0 — extend `DocxBuilderService` patterns for Worksheet and O&M
- `barryvdh/laravel-dompdf` — PDF generation for surveys and reports

### Avoid
- `maatwebsite/excel` — query-to-export wrapper, wrong abstraction for structured cell-level authoring
- `openspout/openspout` — streaming-only, no cell styling
- FreeTDS — charset/collation issues with Windows-1252 QuoteWerks data
- ODBC 18 — connection string breaking changes for internal VPN with self-signed certs

## Table Stakes vs Differentiators

### Table Stakes (Must Have)
- `ProjectDataService` — unified data merge layer (everything depends on it)
- QuoteWerks SQL import producing identical `extracted_data` structure to PDF import
- Worksheet generator (DOCX, room-by-room install instructions)
- O&M manual refactored to consume structured data instead of AI-on-PDF
- Cable schedule populated from equipment relationships + survey cable routes
- Survey data wired into `ProjectDataService`
- Data source annotation per field (SQL vs PDF vs manual)

### Differentiators
- Single correction propagates to all four document types simultaneously
- Strict AI boundary: AI formats/phrases only, never invents scope or content
- Room-as-first-class-citizen feeding worksheet constraints from survey data

### Defer
- Data confidence dashboard UI (implement structure, display in review screen only)
- Survey token expiry management UI
- Email notifications, client portal, mobile native app, full-text search

## Architecture Approach

### Core Pattern
`ProjectDataService` sits between all data sources and all generators as a read-only merger.

### Import Layer
Two parallel paths (PDF and QuoteWerks SQL) with identical `extracted_data` output contracts. Downstream is source-agnostic.

### Generator Contract
All generators implement `DocumentGeneratorContract::generate(array $dataset, Model $record): string`. Jobs are thin orchestrators.

### Merge Priority Chain (enforce before implementation)
`reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults`

### Anti-Patterns to Forbid
- Each generator resolving its own data (current state)
- Storing the merged dataset in the database (stale data risk)
- Fat jobs containing business logic
- Querying QuoteWerks SQL at generation time (VPN dependency)

## Critical Pitfalls

| # | Pitfall | Risk | Mitigation |
|---|---------|------|------------|
| 1 | MS SQL driver environment mismatch | HIGH | Run `php -m` on production server before writing any QuoteWerks code |
| 2 | TLS certificate rejection | HIGH | Set `encrypt`/`trust_server_certificate` via env vars in named connection |
| 3 | `ProjectDataService` becoming god class | MEDIUM | Define canonical structure as typed contract BEFORE writing the service |
| 4 | Merge priority undefined | HIGH | Document priority chain before implementation; reviewed_data always wins |
| 5 | Breaking live RAMS pipeline | HIGH | Keep RAMS pipeline read-only; migrate LAST after new generators are stable |
| 6 | Migration on existing data | MEDIUM | Use nullable-first + backfill pattern for all new foreign keys |

## Phase Ordering Implications

Research strongly implies this build order:

1. **Foundation** — ProjectDataService + MS SQL environment validation (hard blocker)
2. **QuoteWerks SQL Import** — can parallel with Phase 1; produces to same contract as PDF
3. **Survey Data Integration** — wire survey data into ProjectDataService before generators need it
4. **New Document Generators** — Worksheets, O&M hardening, Cable Schedule seeding (all consume ProjectDataService)
5. **RAMS Pipeline Migration** — last by design; proven path before touching live pipeline

## Open Questions

- Production server OS (Windows vs Linux) — determines MS SQL driver installation path
- QuoteWerks SQL authentication method (SQL Server Auth vs Windows Auth)
- QuoteWerks database schema (proprietary, needs live instance exploration)
- Whether mpdf is still needed for O&M or can be consolidated to DomPDF

---
*Synthesized: 2026-04-09*

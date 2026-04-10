# Phase 2: QuoteWerks SQL Import - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-10
**Phase:** 02-quotewerks-sql-import
**Areas discussed:** Connection setup, Data mapping, Import UX flow, Schema exploration

---

## Connection Setup

| Option | Description | Selected |
|--------|-------------|----------|
| Windows Server | Uses pdo_sqlsrv PECL extension natively | |
| Linux (Ubuntu/Debian) | Needs Microsoft ODBC Driver for Linux + pdo_sqlsrv | ✓ |
| Not sure | Need to check | |

**User's choice:** Linux (Ubuntu/Debian)

| Option | Description | Selected |
|--------|-------------|----------|
| SQL Server Auth | Username + password | ✓ |
| Windows Auth | Domain credentials, requires Kerberos on Linux | |
| Not sure | Need to check | |

**User's choice:** SQL Server Auth

| Option | Description | Selected |
|--------|-------------|----------|
| Likely self-signed | Internal server, need trust_server_certificate=true | ✓ |
| CA-signed cert | Proper SSL cert | |
| Not sure | Will test during verification | |

**User's choice:** Likely self-signed

---

## Data Mapping

| Option | Description | Selected |
|--------|-------------|----------|
| I know the tables | Can describe which tables hold what | ✓ |
| Somewhat familiar | Know QuoteWerks but haven't queried DB | |
| Not familiar | Need to explore schema first | |

**User's choice:** I know the tables

**Room structure:** Groups/folders — line items grouped into named sections representing rooms

**Key tables:** DocumentHeaders, DocumentItems, DocumentItemGroups

**Charset:** Not sure — need to check collation setting

---

## Import UX Flow

| Option | Description | Selected |
|--------|-------------|----------|
| Existing import page | Add toggle tab to current page | ✓ |
| Separate page | New dedicated page | |
| Project show page | Button on dashboard | |

**User's choice:** Existing import page with toggle

**Lookup:** Both options — quick ref lookup + search by client/date

**Speed:** Synchronous (instant, no queue)

**Error handling:** Both — inline error with fallback to PDF suggestion

---

## Schema Exploration

**Artisan commands:** Both — quotewerks:ping (health check) + quotewerks:schema (explorer)

**Column handling:** Claude's discretion on query abstraction

---

## Claude's Discretion
- Query abstraction pattern (repository vs raw queries)
- Charset detection and conversion strategy
- Error handling granularity

## Deferred Ideas
None

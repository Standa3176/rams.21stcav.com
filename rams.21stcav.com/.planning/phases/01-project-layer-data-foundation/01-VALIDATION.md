---
phase: 1
slug: project-layer-data-foundation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-09
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 |
| **Config file** | phpunit.xml |
| **Quick run command** | `php artisan test --filter=Project` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Project`
- **After every plan completion:** Run `php artisan test`
- **Before phase verification:** Full test suite

---

## Validation Architecture

### Unit Tests
- ProjectDataService::resolve() returns canonical structure
- Merge priority chain (reviewed > survey > quotewerks > extracted > defaults)
- Data source annotation on every field
- Confidence scoring
- Lifecycle state transitions (forward, backward, auto-advance)

### Feature Tests
- Project CRUD (create, read, update, soft-delete)
- Project-Package linking (auto-create on import, migration backfill)
- Dashboard page loads with all document cards
- Lifecycle bar renders correct state
- Client filter on projects index

### Integration Tests
- QuoteImportService auto-creates project
- Survey submission triggers auto-advance
- ProjectActivityLog records all transitions

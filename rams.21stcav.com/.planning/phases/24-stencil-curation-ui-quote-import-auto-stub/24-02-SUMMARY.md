---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 02
subsystem: quote-import
tags: [laravel, mysql, sqlite, quote-import, stencils, device-ports]

# Dependency graph
requires:
  - phase: 24-01
    provides: needs_review/logo_path columns, CategoryPortTemplateResolver, DeviceStencilCacheService needs_review write-through
  - phase: 21-device-port-catalog-stencil-cache
    provides: device_stencils/device_ports schema, DeviceStencilCacheService firstOrCreate cache contract
provides:
  - QuoteImportStencilStubber — single shared auto-stub orchestration service (D-09)
  - Import-time device_stencils/device_ports creation on all 3 quote-import call sites
affects: [24-03, 24-04, 24-05, 24-06, 24-07, 24-08, 24-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Best-effort try/catch wrapper around orchestration calls placed strictly AFTER the ambient DB::transaction closes — mirrors ExtractQuoteJob's existing generateContentPack() best-effort pattern"
    - "Dual-key normalisation ($line['part_number'] ?? $line['sku'] ?? '', $line['name'] ?? $line['description'] ?? '') to unify 3 divergent equipment-line shapes behind one orchestration method"
    - "Always re-classify through the shared classifier rather than trust an upstream category key directly — classifier's own explicit-value short-circuit still respects a genuinely canonical category"

key-files:
  created:
    - app/Services/QuoteImport/QuoteImportStencilStubber.php
    - tests/Feature/Drawings/QuoteImportStencilStubberTest.php
  modified:
    - app/Jobs/ExtractQuoteJob.php
    - app/Core/Modules/QuoteImport/QuoteWerksImportService.php
    - app/Jobs/ReimportQuoteJob.php

key-decisions:
  - "Stubber call in ReimportQuoteJob placed after completePendingReimport() RETURNS, never inside it — verified directly against QuoteImportService::completePendingReimport's source: the entire method body, including its final `return $pending->fresh();`, is wrapped in one DB::transaction closure (confirms RESEARCH.md Assumption A2)."
  - "QuoteWerksImportService::buildExtractedData's stub call placed immediately after $equipment is built (before scope-narrative/room processing) since $equipment is a plain array, not yet persisted — no transaction boundary concern for this call site."
  - "ExtractQuoteJob's stub call placed between the DB::transaction close and generateContentPack() — new stubDeviceStencils() private method mirrors the existing generateContentPack() best-effort try/catch shape exactly."
  - "ExtractQuoteJob's stubber test invokes mergeParsedQuoteData() + the new stubDeviceStencils() directly via reflection (precedent: ExtractQuoteJobClassifyItemTypeTest) rather than the full handle() pipeline — avoids mocking PdfTextExtractorService/QuoteParserService/AIManager, none of which are relevant to proving this wiring."
  - "ReimportQuoteJob's stubber test invokes the real handle() end-to-end with QuoteExtractorService mocked via $this->app->instance() (precedent: ProjectAutoAdvanceTest) — this is the one call site where invoking the real production method was both feasible and the most direct way to prove the transaction-boundary ordering."

patterns-established:
  - "QuoteImportStencilStubber::stubFromEquipmentLines() is the single entry point all 3 call sites use — no call site reimplements normalisation, classification, or template resolution locally."

requirements-completed: []

# Metrics
duration: ~35min
completed: 2026-08-14
---

# Phase 24 Plan 02: Quote-Import Auto-Stub Orchestration Summary

**QuoteImportStencilStubber — the single D-09 orchestration service, wired into all 3 quote-import call sites (PDF upload, QuoteWerks-direct default route, re-import), stubbing device_stencils/device_ports at import time instead of Phase 21's lazy render-time fallback.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-14 (approx, see git commit timestamps — 24-01 completed 11:51, this plan's Task 2 commit landed 12:23)
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 5 (2 created, 3 modified)

## Accomplishments

- `QuoteImportStencilStubber` created — normalises the 3 divergent equipment-line shapes (`ExtractQuoteJob`'s parser shape, `QuoteWerksImportService`'s SQL-import shape, `ReimportQuoteJob`'s Claude-vision sku/description-only shape) behind one `stubFromEquipmentLines()` method, always re-classifying through the shared `EquipmentCategoryClassifier` and filtering to `hardware`-category lines only.
- Delegates port-template resolution to Wave 1's `CategoryPortTemplateResolver` (D-06/D-07 determinism — no guessing, ambiguous/unrecognised device types resolve to a zero-port `needs_review` stub) and stencil caching to `DeviceStencilCacheService`'s `firstOrCreate` contract (idempotent across re-imports — verified by a repeated-call test asserting zero new rows).
- Bulk-inserts `device_ports` rows via a single raw `DevicePort::insert()` statement, only on genuine cache-miss (`$stencil->wasRecentlyCreated`) with a non-empty resolved template — matches the plan's explicit instruction not to duplicate `DeviceStencilCacheService`'s own "no ports on auto-create" contract.
- All 3 call sites wired with a best-effort try/catch (mirrors `ExtractQuoteJob`'s existing `generateContentPack()` pattern) so a stubbing failure can never fail the parent quote import — proven by a dedicated test that forces `CategoryPortTemplateResolver::resolve()` to throw and asserts the parent `ProjectPackage` still reaches `STATUS_EXTRACTED`.
- `ReimportQuoteJob`'s call site required a source-level verification of the transaction boundary: `QuoteImportService::completePendingReimport()`'s entire body (including its final `return $pending->fresh();`) sits inside one `DB::transaction` closure, so the stubber call was placed strictly after `completePendingReimport()` returns, never inside it — confirms RESEARCH.md's previously-unverified Assumption A2.

## Task Commits

Each task was committed atomically:

1. **Task 1: QuoteImportStencilStubber service** - `bd3e76c` (feat)
2. **Task 2: Wire the 3 import call sites + full-flow tests** - `2e6dc8d` (feat)

_No separate test/refactor commits — this plan's tasks are `type="auto"`, not TDD-gated; each task commit bundles its implementation + tests together._

## Files Created/Modified

- `app/Services/QuoteImport/QuoteImportStencilStubber.php` - Single shared auto-stub orchestration service. Public `stubFromEquipmentLines(array $lines): array{created:int, stencils:DeviceStencil[]}`.
- `app/Jobs/ExtractQuoteJob.php` - Adds private `stubDeviceStencils(array $extracted): void` (best-effort try/catch) called between the `DB::transaction` close and `generateContentPack()`.
- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` - Adds a best-effort try/catch stub call inside `buildExtractedData()`, immediately after `$equipment` is built.
- `app/Jobs/ReimportQuoteJob.php` - Captures `completePendingReimport()`'s return value (previously discarded), adds a best-effort try/catch stub call against `$package->equipment_list` strictly after that call returns.
- `tests/Feature/Drawings/QuoteImportStencilStubberTest.php` - 6 tests: (1) `test_stubs_hardware_lines_and_is_idempotent` — hardware/cable/sku-description-shape filtering + idempotency across a repeated call (the `<verify>`-mandated test name); (2) ambiguous device type still creates a needs-review zero-port stub; (3) `ExtractQuoteJob` call site via reflection on `mergeParsedQuoteData()` + the new `stubDeviceStencils()`; (4) `QuoteWerksImportService::buildExtractedData()` call site; (5) `ReimportQuoteJob::handle()` call site with `QuoteExtractorService` mocked, proving the sku/description-only shape and the transaction-boundary ordering; (6) best-effort isolation — forced `CategoryPortTemplateResolver::resolve()` failure, parent import still reaches `STATUS_EXTRACTED`.

## Decisions Made

- **Test fixture part numbers per plan brief** (`FW-85BZ40L`, `BT9910/B`, `PA20`) were referenced in the plan's critical-implementation-notes as the "Light Forms 21CQ30451-01-OPS fixture" convention, but the concrete test fixtures built here use `FW-85BZ40L` for the `display`-template hardware case and separately exercise `PA20` for the ambiguous/zero-port case — `BT9910/B` was not needed as a distinct fixture once the `display` + `switch` + ambiguous cases already covered the 3 CategoryPortTemplateResolver outcome classes (resolved-with-ports / resolved-zero-port / ambiguous-null). No behavioural gap: the resolver itself is already exhaustively tested in `CategoryPortTemplateResolverTest` from Plan 24-01.
- **ExtractQuoteJob test uses reflection on `mergeParsedQuoteData()` + `stubDeviceStencils()` rather than the full `handle()` pipeline** — the plan explicitly permits "`ExtractQuoteJob::handle()` (or its extracted merge logic)"; invoking the full pipeline would require mocking `PdfTextExtractorService`, `QuoteParserService`, `EquipmentNormalizerService`, `EquipmentLineParserService`, and `AIManager`, none of which affect the stubbing wiring under test. Matches the existing `ExtractQuoteJobClassifyItemTypeTest` precedent (reflection instantiation via `newInstanceWithoutConstructor()`, readonly `package` property set via `ReflectionProperty::setValue()`).
- **ReimportQuoteJob test invokes the real `handle()` end-to-end** with `QuoteExtractorService` mocked and bound via `$this->app->instance()` (precedent: `ProjectAutoAdvanceTest`'s `ProjectService` mock) — this is the one call site where running the actual production method was both feasible and the most direct way to prove the DB::transaction boundary ordering (a violation of the "after, not inside" rule would surface as a nested-transaction or stale-data failure in this test).

## Deviations from Plan

None — plan executed exactly as written. All `must_haves.truths`, the `QuoteImportStencilStubber` artifact, and all 3 `key_links` from the plan frontmatter are satisfied; both tasks' `<acceptance_criteria>` are met and asserted by tests.

## Issues Encountered

None new. The 2 pre-existing `DrawIoSpikeController` constructor-arity test failures logged in `deferred-items.md` by Plan 24-01 were re-confirmed present (and unrelated — `DrawIoSpikeController.php` is not in this plan's `files_modified`) while running the broader `tests/Feature/Drawings` suite for regression-checking; not touched, not re-logged (already tracked).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Import-time stencil stubbing is now live on all 3 quote-import paths — Plan 24-03's admin review queue will be populated with `needs_review = true` rows from real imports, not just Phase 21's lazy render-time fallback.
- `QuoteImportStencilStubber` is a stable, container-resolvable service (`app(QuoteImportStencilStubber::class)`) — any future 4th import path only needs one best-effort try/catch call after its own persistence step, never a reimplementation.
- No blockers for Wave 2 continuation (Plans 24-03 onward). D-17 (editing a curated stencil must not silently destroy hand-built artwork) remains Plan 24-05's responsibility, unaffected by this plan.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Services/QuoteImport/QuoteImportStencilStubber.php`
- `app/Jobs/ExtractQuoteJob.php`
- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php`
- `app/Jobs/ReimportQuoteJob.php`

**⚠️ Depends on Plan 24-01's migration already being live.** This plan's code reads/writes `device_stencils.needs_review` (via `DeviceStencilCacheService`, unchanged from Plan 24-01) and creates `device_ports` rows. If Plan 24-01's migration (`2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php`) has not yet been run on live (`php artisan migrate`), this plan's import-time stubbing will hard-fail silently inside its own best-effort try/catch — imports will still succeed, but NO stencils will be stubbed, and the failure will only be visible in the Laravel log (`storage/logs/laravel.log`), not to the importing user. Confirm Plan 24-01's live migration status before/immediately after uploading this plan's files.

Test files (`tests/Feature/Drawings/QuoteImportStencilStubberTest.php`) are not required on live — they exist for the local/CI test suite only.

## Self-Check: PASSED

All 5 `key-files` (2 created, 3 modified) verified present on disk. Both task commit hashes (`bd3e76c`, `2e6dc8d`) verified present in `git log`.

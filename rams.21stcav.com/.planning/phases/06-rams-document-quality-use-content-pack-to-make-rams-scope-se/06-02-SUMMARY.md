---
phase: 06-rams-document-quality
plan: 02
subsystem: pdf-rendering
tags: [rams, blade, pdf, scope-of-works, tdd, dompdf]
dependency_graph:
  requires: [06-01]
  provides: [rams-scope-conditional]
  affects: [resources/views/pdf/rams.blade.php]
tech_stack:
  added: []
  patterns: [tdd-red-green, blade-conditional, dompdf-safe-inline-style]
key_files:
  created:
    - tests/Unit/RamsPdfScopeTest.php
  modified:
    - resources/views/pdf/rams.blade.php
    - phpunit.xml
decisions:
  - "phpunit.xml gets APP_BASE_PATH pointing to worktree — required for Laravel inferBasePath() to find the worktree's bootstrap/app.php and storage paths during parallel agent test runs"
  - "Test uses $data array + stdClass $rams stub — matches actual view variable consumption (view reads from $data[...] not from individually-passed view variables)"
  - "Fallback strings $project['works_description'], $formData['works_description'], and 'AV installation works as per quotation.' removed entirely per D-01 intent"
metrics:
  duration: 45m
  completed: 2026-04-12
  tasks: 1
  files: 3
---

# Phase 06 Plan 02: RAMS PDF Scope Conditional — scope_of_works Exclusive Display

**One-liner:** Replaced silent ternary fallback in rams.blade.php scope section with explicit @if/@else — populated scope_of_works renders as-is; empty scope shows a red italic notice instead of generic boilerplate.

## What Was Built

### Task 1: RAMS PDF scope conditional (D-01) — TDD Red/Green

**Blade change (`resources/views/pdf/rams.blade.php` line 297-305):**

Replaced the single-line ternary:
```blade
{{ $scopeOfWorks ?: ($project['works_description'] ?? $formData['works_description'] ?? 'AV installation works as per quotation.') }}
```

With an explicit `@if/@else` conditional:
```blade
@if($scopeOfWorks)
    {{ $scopeOfWorks }}
@else
    <span style="color:#CC0000; font-style:italic;">Scope of works not generated — return to the review form and click Generate.</span>
@endif
```

The `$project['works_description']`, `$formData['works_description']`, and `'AV installation works as per quotation.'` fallbacks are removed entirely. Engineers now see a clear red italic instruction rather than generic boilerplate.

The inline `style="color:#CC0000; font-style:italic;"` is used instead of a CSS class — required for dompdf compatibility (custom CSS classes are unreliable in dompdf rendering).

**Test file (`tests/Unit/RamsPdfScopeTest.php`):**

5 tests covering both rendering paths:
1. `test_scope_of_works_renders_when_populated` — scope text appears, no notice, no boilerplate
2. `test_notice_renders_when_scope_empty` — notice appears, no boilerplate, no `works_description` fallback
3. `test_working_hours_note_unaffected_when_scope_populated` — note-text paragraph unaffected
4. `test_working_hours_note_unaffected_when_scope_empty` — note-text paragraph unaffected
5. `test_notice_uses_red_inline_style` — confirms `color:#CC0000` and `font-style:italic` inline styles

The test helper `renderScope()` correctly passes `$data` array and a `stdClass` `$rams` stub to the view — matching how the view actually reads its variables (from `$data[...]` and `$rams->...` properties in the `@php` block at line 188).

## Test Results

### Targeted suite
- `RamsPdfScopeTest`: 5/5 passing

### Full suite
- 260 passing, 39 failing
- The 39 failures are pre-existing: `QuoteWerksImportServiceTest`, `QuoteWerksRepositoryTest`, `MethodStatementFallbackTest`, Auth tests, etc. — same pattern as Phase 01 baseline, no regressions introduced

## Commits

| Task | Type | Hash    | Description |
|------|------|---------|-------------|
| 1 RED  | test | 026d551 | Add failing tests for RAMS PDF scope conditional (D-01) |
| 1 GREEN | feat | af7e757 | RAMS PDF scope conditional — replace ternary with @if/@else (D-01) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added APP_BASE_PATH to phpunit.xml for worktree test isolation**
- **Found during:** Task 1 GREEN phase — tests were 2/5 failing even after Blade change applied
- **Issue:** Laravel's `Application::inferBasePath()` uses `ClassLoader::getRegisteredLoaders()` to discover the application root. In a git worktree where `vendor/` is a junction pointing to the main repo, the autoloader path resolves to the main repo — causing the test bootstrap to load the main repo's `bootstrap/app.php` and resolve `storage_path()` to the main repo's storage. The compiled Blade view cache was then read from the main repo's storage, which still had the old ternary compiled.
- **Fix:** Added `<env name="APP_BASE_PATH" value="...worktree path..."/>` to `phpunit.xml`. PHPUnit injects this into `$_ENV` before `Application::inferBasePath()` is called, causing it to return the worktree path via the `isset($_ENV['APP_BASE_PATH'])` branch.
- **Files modified:** `phpunit.xml`
- **Commit:** af7e757

**Note:** This `phpunit.xml` change is worktree-specific. When the orchestrator merges the worktree branch back, it will need to be reverted or the `APP_BASE_PATH` line removed (it should not be present in the main repo's `phpunit.xml`). The main repo does not need APP_BASE_PATH since inferBasePath() will correctly find it from the autoloader.

**2. [Rule 3 - Blocking] Test data adapted to match actual view variable consumption**
- **Found during:** Task 1 RED phase — plan's proposed test used individually-passed view variables (`scopeOfWorks`, `project`, etc.) but the view's `@php` block reads from `$data['...']` and `$rams->...`
- **Fix:** Test `renderScope()` helper passes `['data' => [...], 'rams' => $stub]` instead of individual view variables. `$rams` is a `stdClass` stub with `Carbon::create()` for `->created_at`. No view changes made.
- **Files modified:** `tests/Unit/RamsPdfScopeTest.php` (test data only)
- **Commit:** 026d551

## Known Stubs

None. The scope conditional is fully wired — `$data['scope_of_works']` flows from `RamsBuilderService::runFromReview()` which assigns it from `$reviewedData['scope_of_works'] ?? ''`. When populated (Phase 5 content pack or manual review), scope text shows. When absent, the red notice shows. No invented content, no placeholders.

## Threat Flags

None. The change uses Blade's `{{ }}` auto-escaping (XSS-safe) on `$scopeOfWorks`. The notice text is static HTML — no user data is injected into it. No new endpoints, auth paths, or schema changes.

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| resources/views/pdf/rams.blade.php — @if($scopeOfWorks) present | FOUND |
| resources/views/pdf/rams.blade.php — CC0000 present | FOUND |
| resources/views/pdf/rams.blade.php — old ternary absent | CONFIRMED |
| tests/Unit/RamsPdfScopeTest.php | FOUND |
| Commit 026d551 (RED test) | FOUND |
| Commit af7e757 (GREEN feat) | FOUND |
| RamsPdfScopeTest: 5/5 green | CONFIRMED |
| Full suite: 39 pre-existing failures, 0 regressions from plan-02 changes | CONFIRMED |

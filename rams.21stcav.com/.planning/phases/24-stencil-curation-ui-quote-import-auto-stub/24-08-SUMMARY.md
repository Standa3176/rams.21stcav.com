---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 08
subsystem: cli-tooling
tags: [laravel, artisan, mysql, sqlite, device-stencils, drift-correction]

# Dependency graph
requires:
  - phase: 24-01
    provides: DeviceStencil::audits() HasMany relation, CategoryPortTemplateResolver, AutoGenericStencilGenerator D-05 provisional-rail extension, port_id determinism
  - phase: 24-02
    provides: QuoteImportStencilStubber's bulk device_ports insert shape (reused verbatim by Task 1)
provides:
  - "php artisan stencils:reapply-templates — D-08 opt-in, dry-run-by-default re-templating of untouched auto-generated stencils"
  - "php artisan stencils:coverage-report — live-DB-derived Tier 1/2 curation ranking, feeds Plan 24-09"
affects: [24-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dry-run-by-default / --commit opt-in CLI convention (mirrors PackagesReclassifyEquipmentCommand and BackfillCablePortFksCommand) applied to a THIRD domain (stencils), same per-row $this->table() reporting + idempotency messaging shape"
    - "Eligibility as a hard two-clause Eloquent conjunction (source=X AND whereDoesntHave(relation)) as the entire safety boundary for a destructive opt-in command — no secondary guard needed because the conjunction is provably exhaustive"

key-files:
  created:
    - app/Console/Commands/StencilsReapplyTemplatesCommand.php
    - app/Console/Commands/StencilsCoverageReportCommand.php
    - tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php
    - tests/Feature/Console/StencilsCoverageReportCommandTest.php
  modified: []

key-decisions:
  - "Reapply command's generator hint always uses `$portTemplate ?? []` per the plan's literal instruction — an ambiguous/unrecognised resolve() outcome (null) is treated identically to a resolved-zero-port outcome ([]) for payload-building purposes. This means a stencil that currently HAS ports could, in principle, lose them if a config keyword is later removed and the device becomes ambiguous — that risk is exactly why the command is dry-run-by-default: the diff (old ports N -> new ports 0) is visible in the report table before any --commit."
  - "Coverage-report's own docblock/comments deliberately avoid the literal substring 'device-stencils-seed' so the command's own source-scan test (grepping for that exact directory name) isn't polluted by its own documentation of the rule it satisfies."
  - "Reapply command never mutates `source` — a re-templated stencil stays `auto-generated`, which is what keeps it eligible for a future re-run if the vocabulary changes again. Promotion to `engineer-curated` remains exclusively Plan 24-07's action."

patterns-established:
  - "A stencil that already matches the current template vocabulary produces zero report rows for that stencil — the report table only ever shows drift, never a full listing of every eligible row. This is what makes the idempotency claim testable: after --commit, a second run's report is provably empty (\"Every eligible stencil already matches...\") rather than merely re-listing zero-diff rows."

requirements-completed: []

# Metrics
duration: ~35min
completed: 2026-08-14
---

# Phase 24 Plan 08: Stencil Reapply-Templates + Coverage-Report CLI Tooling Summary

**Two read/write-and-read-only artisan commands: `stencils:reapply-templates` (D-08's dry-run-by-default opt-in escape hatch that safely re-templates only untouched auto-generated stencils, covering the 92 D-11 pre-existing zero-port stubs) and `stencils:coverage-report` (a live-DB-derived Tier 1/2 curation ranking, independent of the seed pack per Phase 21 D-15, that feeds Plan 24-09's bounded top-10 fill).**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-14
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 4 (all created)

## Accomplishments

- `stencils:reapply-templates` — eligibility is the hard `source=auto-generated AND whereDoesntHave('audits')` conjunction from D-08: any promote/edit/discard-regenerate action (which always writes a `device_stencil_audits` row, Plan 24-01/24-07) permanently removes a stencil from this command's reach regardless of its current `source` value. Dry-run by default; `--commit` re-builds `mxgraph_xml` via `AutoGenericStencilGenerator::build()` and wholesale-replaces `device_ports` (delete + bulk-insert, reusing `QuoteImportStencilStubber`'s exact insert shape) only for stencils whose rebuilt XML differs from the stored value.
- Idempotence verified end-to-end by test: running `--commit` twice against unchanged config produces zero further diffs on the second pass, because `CategoryPortTemplateResolver`'s `port_id` derivation (Plan 24-01) and `AutoGenericStencilGenerator::build()` are both pure/deterministic functions of their inputs.
- D-11 confirmed by test: a pre-existing zero-port stub (simulating the 92 real ones) whose name/part_number resolves a non-null port template gets fully templated in one `--commit` pass — no separate one-shot backfill command needed.
- `stencils:coverage-report` — ranks part_numbers by REAL quote-import occurrence, tallied from a live `ProjectPackage.extracted_data['equipment']` DB query (never the on-disk seed pack — Phase 21 D-15 independence rule, verified by a test that greps the command's own source for the seed-pack directory name), filtered to `hardware`-category lines only via the shared `EquipmentCategoryClassifier` (same rationale as `QuoteImportStencilStubber`: never trust an upstream `category` key directly). Reports Tier 1 (auto-generated or no stencil yet) vs Tier 2 (engineer-curated) status per top-N entry, `--limit` configurable (default 10).

## Task Commits

Each task was committed atomically:

1. **Task 1: stencils:reapply-templates command (D-08)** - `13c0538` (feat)
2. **Task 2: stencils:coverage-report command** - `2be7edd` (feat)

_No separate test/refactor commits — this plan's tasks are `type="auto"`, not TDD-gated; each task commit bundles its implementation + tests together._

## Files Created/Modified

- `app/Console/Commands/StencilsReapplyTemplatesCommand.php` - `stencils:reapply-templates {--commit}`. Eligibility query, drift detection via XML string comparison, wholesale `device_ports` replace inside `DB::transaction`, `$this->table()` report + dry-run/commit messaging mirroring `PackagesReclassifyEquipmentCommand`'s exact idiom.
- `app/Console/Commands/StencilsCoverageReportCommand.php` - `stencils:coverage-report {--limit=10}`. Read-only. Live-DB frequency tally over hardware-classified equipment lines, Tier 1/2 split report.
- `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` - 6 tests: dry-run makes zero writes, `--commit` persists + second run is a no-op (idempotence), engineer-curated stencil never touched, auto-generated-but-audited stencil never touched, D-11 zero-port-stub templating, clean no-eligible-stencils state.
- `tests/Feature/Console/StencilsCoverageReportCommandTest.php` - 6 tests: seed-pack independence (source-scan), frequency-ranking order (3 occurrences ranks above 1), hardware-only filtering (cable/service lines excluded), `--limit` bound, Tier 1/2 split, empty-state.

## Decisions Made

- **`$portTemplate ?? []` passed to the generator verbatim, per the plan's literal action text** — an ambiguous/unrecognised `CategoryPortTemplateResolver::resolve()` outcome (`null`) and a resolved-but-portless outcome (`[]`, e.g. `bracket`/`mount`/`cable`) both build a zero-port payload. This is a deliberate design choice inherited from the plan, not something this executor introduced: the command is dry-run-by-default specifically so an operator can see "old ports: N, new ports: 0" in the report table before ever running `--commit` — the config-vocabulary-drift risk is visible, not silent.
- **Coverage-report's docblock/inline comments avoid the literal string `device-stencils-seed`** so the acceptance-criterion test (`grep the class file to confirm — independence rule`) tests the command's actual behaviour rather than being satisfied by a false negative caused by the command's own documentation mentioning the very path it promises never to read. The command genuinely contains zero references to that directory (verified by the test).
- **Neither command mutates `DeviceStencil.source`.** `stencils:reapply-templates` updates `mxgraph_xml` + `device_ports` only, leaving `source = auto-generated` intact so a stencil stays eligible for future re-runs as the vocabulary evolves; promotion to `engineer-curated` remains exclusively Plan 24-07's `stencils:promote`-equivalent admin action, never this CLI tool's job.
- **`stencils:coverage-report`'s Tier lookup treats "no stencil row exists yet" identically to "stencil exists but is still auto-generated"** — both count as Tier 1 in the summary line, since either way an engineer still needs to act on that part_number. This matches the plan's action text ("Tier 1 (auto-generated or missing)").

## Deviations from Plan

None — plan executed exactly as written. All `must_haves.truths`, both `artifacts`, and the one `key_link` (`whereDoesntHave('audits')` eligibility filter) from the plan frontmatter are satisfied; both tasks' `<acceptance_criteria>` are met and asserted by tests.

## Issues Encountered

None new. Did not re-run the broader `tests/Feature/Drawings` suite for this plan (scoped `--filter` on the two new test classes plus a full `tests/Feature/Console` directory run to confirm no cross-test interference, per the verification-gates instruction not to start a full-repo run). The 2 pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md` by Plan 24-01 are unrelated to any file this plan touches and were not re-checked.

## User Setup Required

None - no external service configuration required. Both commands are CLI-only (no HTTP surface, no admin-session boundary applies, per this plan's own `<threat_model>`).

## Next Phase Readiness

- `stencils:reapply-templates` is ready to run against live data any time `config/drawings.php`'s `port_templates`/`port_template_precedence` vocabulary changes — dry-run first, review the diff table, then `--commit`. Covers the 92 pre-existing D-11 zero-port stubs in one pass once Plan 24-01's migration is live.
- `stencils:coverage-report` is ready to produce Plan 24-09's audit-trail input the moment there is real quote-import data on the target environment — no additional wiring needed.
- Both commands depend on Plan 24-01's migration (`device_stencils.needs_review`/`logo_path` + `device_stencil_audits` table) already being live — `stencils:reapply-templates`'s `whereDoesntHave('audits')` eligibility query will hard-fail (missing table) if that migration hasn't run yet on the target environment.
- No blockers for the remaining Wave 3 plans (24-04 through 24-07, 24-09). This plan ran in parallel to 24-02/24-03 per the plan's own wave note and has no UI dependency.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Console/Commands/StencilsReapplyTemplatesCommand.php`
- `app/Console/Commands/StencilsCoverageReportCommand.php`

**No migration in this plan.** Both commands depend entirely on Plan 24-01's `needs_review`/`logo_path`/`device_stencil_audits` migration already having been run on live (`php artisan migrate`). If that migration has not yet been applied on live, `php artisan stencils:reapply-templates` will hard-fail immediately (its eligibility query references `device_stencil_audits` via `whereDoesntHave('audits')`), and `php artisan stencils:coverage-report` will run but every ranked part_number will report as "Tier 1" by definition (no `device_stencils` rows queryable yet in a meaningful way, though the command itself will not error since it doesn't touch `needs_review`/`logo_path`/`audits` directly). Confirm Plan 24-01's live migration status before running either command on live.

**Do NOT run `stencils:reapply-templates --commit` against live/production data as a routine deploy step** — per this plan's verification-gates instruction, `--commit` is an opt-in operator action taken deliberately after reviewing a dry-run report, never an automated part of the deploy pipeline.

Test files (`tests/Feature/Console/Stencils*CommandTest.php`) are not required on live — they exist for the local/CI test suite only.

## Self-Check: PASSED

All 4 `key-files` (2 commands, 2 test files) verified present on disk. Both task commit hashes (`13c0538`, `2be7edd`) verified present in `git log`.

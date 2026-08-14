---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 01
subsystem: database
tags: [laravel, mysql, sqlite, mxgraph, drawio, migrations, eloquent]

# Dependency graph
requires:
  - phase: 21-device-port-catalog-stencil-cache
    provides: device_stencils/device_ports schema, DeviceStencilCacheService firstOrCreate cache contract, AutoGenericStencilGenerator Tier 1 placeholder
provides:
  - device_stencils.needs_review (indexed boolean) + logo_path columns
  - device_stencil_audits table + DeviceStencilAudit model (ACTION_PROMOTE/EDIT/DISCARD_REGENERATE)
  - DeviceStencil::audits() HasMany relation
  - config('drawings.port_templates') + config('drawings.port_template_precedence') vocabulary
  - CategoryPortTemplateResolver — deterministic device-type -> port-template resolution
  - AutoGenericStencilGenerator D-05 extension — provisional <connections> constraints + dashed/muted rail styling when a port template is supplied
affects: [24-02, 24-03, 24-04, 24-05, 24-06, 24-07, 24-08, 24-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "PHP-based (not raw-SQL) migration backfill for portability across MariaDB prod / SQLite test"
    - "Priority-ordered decision tree returning null on ambiguity (never a guessed default) — CategoryPortTemplateResolver mirrors DrawingDataResolverService::inferRoleFromName, not EquipmentCategoryClassifier's unconditional default"
    - "mxGraph stencil-XML state elements verified directly against the vendored parser (public/vendor/drawio/mxgraph/src/shape/mxStencil.js), never against the seed pack, when the seed pack has zero precedent for the grammar in question"

key-files:
  created:
    - database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php
    - app/Models/DeviceStencilAudit.php
    - app/Services/Drawings/CategoryPortTemplateResolver.php
    - tests/Feature/Drawings/DeviceStencilAuditTest.php
    - tests/Feature/Drawings/CategoryPortTemplateResolverTest.php
    - tests/Feature/Drawings/AutoGenericStencilGeneratorTest.php
  modified:
    - app/Models/DeviceStencil.php
    - app/Services/Drawings/DeviceStencilCacheService.php
    - config/drawings.php
    - app/Services/Drawings/AutoGenericStencilGenerator.php

key-decisions:
  - "Backfill loop is PHP (json_decode over metadata), never raw SQL JSON functions — MariaDB prod vs SQLite test diverge on JSON syntax"
  - "needs_review write-through happens in ONE place (DeviceStencilCacheService::resolveForPartNumber's firstOrCreate array) so both Phase 24's future import-time stubbing and Phase 21's existing lazy-create path get it uniformly"
  - "port_id is deterministic ({connector_type}-{n}, 1-based counter per connector_type) — never UUID/time-derived, so Plan 24-08's reapply-templates dry-run diffing stays byte-identical across repeated calls"
  - "'cable' is a standalone short-circuit checked before the precedence list — resolves to permanent zero-port, per D-07 'cable beats everything'"
  - "Provisional rail grammar (dashed/strokealpha) verified against the vendored mxStencil.js parser, not the seed pack, because the seed pack has zero precedent for either element"

patterns-established:
  - "CategoryPortTemplateResolver::resolve() returns null (ambiguous/unrecognised) vs [] (resolved-but-portless, e.g. bracket/mount/cable) — callers must distinguish these two falsy-but-different outcomes"
  - "AutoGenericStencilGenerator::resolvePortLayout() computes spread/tick/constraint geometry ONCE per port and feeds both buildConnections() and buildProvisionalRail() from the same array, preventing the two emitters from independently drifting on coordinate math"

requirements-completed: [DRAW-51]

# Metrics
duration: ~55min
completed: 2026-08-14
---

# Phase 24 Plan 01: Wave 1 Foundation Summary

**Schema (needs_review/logo_path/device_stencil_audits) + deterministic CategoryPortTemplateResolver + AutoGenericStencilGenerator's D-05 provisional-rail extension, using the real mxGraph stencil-XML grammar verified against the vendored draw.io parser.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-08-14T00:00:00Z (approx, see git commit timestamps)
- **Completed:** 2026-08-14
- **Tasks:** 3/3 completed
- **Files modified:** 10 (6 created, 4 modified) + 1 deferred-items.md

## Accomplishments
- Schema foundation shipped: indexed `needs_review` boolean + nullable `logo_path` on `device_stencils`, plus the new `device_stencil_audits` table with a PHP-based (portable) backfill of the existing `metadata.needs_phase_24_curation` flags
- `DeviceStencilCacheService::resolveForPartNumber()` now writes `needs_review = true` on every freshly-created stub, uniformly covering both this phase's future import-time stubbing and Phase 21's existing lazy-create path
- `CategoryPortTemplateResolver` deterministically resolves device-type -> port template, correctly disambiguating the canonical "Samsung 65in Display Bracket" ambiguity case to `bracket` (not `display`), with `cable` beating everything and unrecognised/ambiguous inputs returning `null`
- `AutoGenericStencilGenerator` extended (D-05) to emit named `<connections>` mxGraph constraints and provisional dashed/muted port rails when a resolved port template is supplied — using the exact `dashed`/`strokealpha` grammar verified against the vendored `mxStencil.js` parser, with a mandatory global-alpha reset — while remaining byte-identical to the pre-Phase-24 output for the zero-port case (regression fixture captured directly from the pre-change committed source, not hand-transcribed)

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration + DeviceStencilAudit model + cache-service wiring** - `7e77cb9` (feat)
2. **Task 2: config port_templates vocabulary + CategoryPortTemplateResolver** - `2e8bc7d` (feat)
3. **Task 3: Extend AutoGenericStencilGenerator for D-05 provisional rails** - `7d93fbd` (feat)

_No separate test/refactor commits — this plan's tasks are `type="auto"`, not TDD-gated; each task commit bundles its implementation + tests together._

## Files Created/Modified
- `database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php` - Adds `needs_review`/`logo_path` to `device_stencils`, creates `device_stencil_audits`, PHP-based backfill
- `app/Models/DeviceStencilAudit.php` - Audit trail model, `ACTION_PROMOTE`/`ACTION_EDIT`/`ACTION_DISCARD_REGENERATE` constants
- `app/Models/DeviceStencil.php` - Adds `needs_review`/`logo_path` to fillable/casts, `audits()` HasMany relation
- `app/Services/Drawings/DeviceStencilCacheService.php` - `firstOrCreate` create-array now sets `needs_review => true`
- `config/drawings.php` - New `port_templates` (display/switch/bracket/mount) + `port_template_precedence` keys
- `app/Services/Drawings/CategoryPortTemplateResolver.php` - Deterministic device-type -> port-template resolver
- `app/Services/Drawings/AutoGenericStencilGenerator.php` - D-05 extension: `<connections>` constraints + provisional rail/label emission, `resolvePortLayout()`/`buildConnections()`/`buildProvisionalRail()`/`sideGeometry()`/`fraction()`/`pixel()` helpers
- `tests/Feature/Drawings/DeviceStencilAuditTest.php` - 5 tests (schema shape, indexed column, PHP backfill via migrate:rollback+migrate round-trip, cache-service write-through, audits relation)
- `tests/Feature/Drawings/CategoryPortTemplateResolverTest.php` - 6 tests (display-bracket ambiguity, unrecognised->null, switch->4 ports, cable short-circuit, display->1 port, determinism)
- `tests/Feature/Drawings/AutoGenericStencilGeneratorTest.php` - 11 tests (byte-identical zero-port regression, constraint parity, dashed/stroke batching grammar, strokealpha/reset bracketing, label-inside-mute-window, invented-attribute absence guards, line-element attribute purity, XSS escaping, per-side coordinate mapping)
- `.planning/phases/24-stencil-curation-ui-quote-import-auto-stub/deferred-items.md` - Logs 2 pre-existing, unrelated `DrawIoSpikeController` constructor-arity test failures discovered while running the broader Drawings suite (out of scope for this plan; not touched)

## Decisions Made
- **Regression fixture captured from git history, not hand-transcribed.** To satisfy criterion 6's "byte-identical to current output" acceptance criterion with zero risk of transcription error, the pre-Phase-24 `AutoGenericStencilGenerator` source was pulled from commit `06c9052` (the original Phase 21 commit), renamed into an isolated namespace, and executed directly to capture the true fixture string used in `AutoGenericStencilGeneratorTest::ZERO_PORT_REGRESSION_FIXTURE`.
- **Migration backfill test uses a rollback/insert/re-migrate round-trip.** Because Laravel's `RefreshDatabase` trait runs `migrate:fresh` once per test *suite* run (not once per test) and wraps each test in a transaction, a freshly-migrated `device_stencils` table has no rows for the backfill step to act on within a single test. `DeviceStencilAuditTest::test_backfill_carries_existing_needs_phase_24_curation_flag_into_needs_review_column` explicitly rolls back this migration, inserts a raw row carrying the legacy `metadata.needs_phase_24_curation` flag (simulating genuine pre-Phase-24 data), then re-runs `migrate` so the backfill loop has real work to do — proving the PHP-based backfill logic itself, not just the schema shape.
- **`port_id` determinism scoped per connector_type, not per template.** `{connector_type}-{n}` where `n` restarts at 1 for each distinct connector_type within a template — mirrors the hand-curated seed pack's own `hdmi-1`/`hdmi-2` style and keeps Plan 24-08's dry-run diffing stable.
- **Provisional-rail spread/geometry computed once, shared by both emitters.** `resolvePortLayout()` is the single source of per-port `spread` (`(index_within_side+1)/(count_on_that_side+1)`), consumed by both `buildConnections()` (fraction coords) and `buildProvisionalRail()` (pixel tick/label coords) — guarantees the visible rail tick and the invisible mxGraph constraint for the same port never drift apart.

## Deviations from Plan

None — plan executed exactly as written. All `must_haves.truths`, `artifacts`, and `key_links` from the plan frontmatter are satisfied; all three tasks' `<acceptance_criteria>` are met and asserted by tests.

## Issues Encountered
- **SQLite `ALTER TABLE ... DROP COLUMN` on an indexed column** — the migration's `down()` originally dropped `needs_review` and `logo_path` together via a single `dropColumn([...])` call, which left a dangling index reference on SQLite's rebuild-based ALTER TABLE implementation (`"1 error in index device_stencils_needs_review_index after drop column"`). Fixed by explicitly dropping the index (`$table->dropIndex(['needs_review'])`) in a separate `Schema::table()` call before dropping the columns. This surfaced only when writing the migration's `down()` path (exercised by the backfill test's `migrate:rollback` call) — not a plan deviation, just an implementation detail resolved inline (Rule 1 — bug fix, same task, same commit).
- **Two pre-existing, unrelated test failures** surfaced when running the broader `tests/Feature/Drawings` suite for regression-checking: `DrawIoBuilderServiceTest` and `V13SurfacesUntouchedTest` both assert `DrawIoSpikeController`'s constructor has exactly 2 parameters; it currently has 3. Confirmed via `git log` that `DrawIoSpikeController.php` was last touched in an unrelated prior commit (`9a6837c`, WR-03/4/5 security batch) and is not in this plan's `files_modified` list — out of scope per the SCOPE BOUNDARY rule. Logged to `deferred-items.md`, not fixed.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Plan 24-02 (`QuoteImportStencilStubber`) can now call `CategoryPortTemplateResolver::resolve()` and pass its output straight into `AutoGenericStencilGenerator::build(['ports' => ...])` per the interfaces this plan established.
- Plan 24-04/24-05 (curation UI save/preview) can regenerate `mxgraph_xml` through the same extended generator with confidence the grammar is production-correct.
- Plan 24-07 (promotion) can write to `device_stencil_audits` immediately — model, constants, and relation are in place.
- Plan 24-08 (`stencils:reapply-templates`) can rely on `DeviceStencil::audits()`'s `whereDoesntHave('audits')` eligibility query and on `CategoryPortTemplateResolver`/`port_id` determinism for stable dry-run diffing.
- No blockers for Wave 2. D-17 (editing a curated stencil must not silently destroy hand-built artwork) still needs to be folded into Plan 24-05 before Wave 4 runs — unaffected by this plan.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php`
- `app/Models/DeviceStencilAudit.php`
- `app/Models/DeviceStencil.php`
- `app/Services/Drawings/DeviceStencilCacheService.php`
- `config/drawings.php`
- `app/Services/Drawings/CategoryPortTemplateResolver.php`
- `app/Services/Drawings/AutoGenericStencilGenerator.php`

**⚠️ MANDATORY POST-UPLOAD STEP: run `php artisan migrate` on the server BEFORE any later Phase 24 plan's admin screen is opened on live.** This plan does not run migrations against the live/shared database — per the critical implementation notes, migrations only ran locally against SQLite (`:memory:`) under the test suite. The `device_stencils.needs_review` / `logo_path` columns and the `device_stencil_audits` table do not exist on the live MariaDB database until `php artisan migrate` is run there. Any code from a later Phase 24 plan that queries `needs_review`, `logo_path`, or `device_stencil_audits` will hard-fail on live until this migration has been applied.

Test files (`tests/Feature/Drawings/*.php`) are not required on live — they exist for the local/CI test suite only.

## Self-Check: PASSED

All 10 files_modified files, `deferred-items.md`, and this SUMMARY.md verified present on disk. All 3 task commit hashes (`7e77cb9`, `2e8bc7d`, `7d93fbd`) verified present in `git log`.

---
phase: 22
slug: cable-schedule-with-port-level-fks
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-12
---

# Phase 22 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5.3 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Cable` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~5–10s (Cable filter) / ~30s (Feature: Cable\|Drawings) / full suite ~Phase 21 baseline 1633 tests |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Cable`
- **After every plan wave:** Run `php artisan test --testsuite=Feature --filter='Cable|Drawings'` (catches Phase 21 ↔ 22 cross-impact)
- **Before `/gsd-verify-work`:** Full `php artisan test` must be green (~1633 baseline + ~25–35 new)
- **Max feedback latency:** ~10 seconds (Cable filter at task commit)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 22-01-00 | 01 | 0 | DRAW-37 | — | N/A (failing-test seed) | Feature | `php artisan test --filter=CableScheduleItemMigrationTest` | ❌ W0 | ⬜ pending |
| 22-01-01 | 01 | 1 | DRAW-37 | — | N/A | Feature | `php artisan test --filter=CableScheduleItemMigrationTest` | ❌ W0 | ⬜ pending |
| 22-01-02 | 01 | 1 | DRAW-37 | — | Mass-assignment whitelist via `$fillable` | Unit | `php artisan test --filter=CableScheduleItemRelationsTest` | ❌ W0 | ⬜ pending |
| 22-01-03 | 01 | 1 | DRAW-39 | — | Compat matrix bidirectional + allowlist | Unit | `php artisan test --filter=CableConnectorCompatibilityServiceTest` | ❌ W0 | ⬜ pending |
| 22-02-00 | 02 | 0 | DRAW-38, DRAW-39 | T-22-A4 | N/A (failing-test seed) | Feature | `php artisan test --filter='CableScheduleUpdate'` | ❌ W0 | ⬜ pending |
| 22-02-01 | 02 | 2 | DRAW-38 | T-22-A4 | Cross-project FK guard: `device.project_id === cable_schedule.project_id` | Feature | `php artisan test --filter=CableScheduleUpdatePersistsPortFksTest` | ❌ W0 | ⬜ pending |
| 22-02-02 | 02 | 2 | DRAW-39 | — | XSS-safe Blade escaping of override note | Feature | `php artisan test --filter=CableScheduleUpdatePersistsOverrideNoteTest` | ❌ W0 | ⬜ pending |
| 22-02-03 | 02 | 2 | DRAW-38, DRAW-39 | T-22-A4 | Cross-project FK injection rejected with 422 | Feature | `php artisan test --filter=CableScheduleCrossProjectFkInjectionTest` | ❌ W0 | ⬜ pending |
| 22-03-00 | 03 | 0 | DRAW-40, DRAW-41 | — | N/A (failing-test seed) | Feature | `php artisan test --filter=BackfillCablePortFksCommandTest` | ❌ W0 | ⬜ pending |
| 22-03-01 | 03 | 2 | DRAW-40 | — | Deterministic match only (ambiguous → NULL) | Feature | `php artisan test --filter=BackfillCablePortFksCommandTest` | ❌ W0 | ⬜ pending |
| 22-03-02 | 03 | 2 | DRAW-41 | — | Dry-run default writes nothing; `--apply` persists; idempotent | Feature | `php artisan test --filter=BackfillCablePortFksCommandTest` | ❌ W0 | ⬜ pending |
| 22-04-01 | 02 | 2 | D-10 invariant | — | XLSX export byte-output unchanged for NULL-FK legacy rows | Feature | `php artisan test --filter=CableScheduleXlsxRegressionTest` | ❌ W0 | ⬜ pending |
| 22-04-02 | 02 | 2 | D-10 invariant | — | SchematicGenerator/D2 byte-identical for NULL-FK cables | Feature | `php artisan test --filter=SchematicGeneratorServiceTest` | ✅ exists; extend | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Task IDs above are illustrative until the planner finalises plan/task numbering. The planner MUST keep the (Req → Test) mapping intact.*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Cable/CableScheduleItemMigrationTest.php` — DRAW-37 schema assertions (4 FK columns + `connector_override_note` exist with correct nullability + FK constraints + `nullOnDelete` behavior)
- [ ] `tests/Unit/Models/CableScheduleItemRelationsTest.php` — `$fillable` includes 5 new keys; 4 `belongsTo` relations resolve; NULL-FK rows hydrate without exception
- [ ] `tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php` — exact-match matrix, bidirectional allowlist pairs, empty connector_type = "assume compatible" (Tier 1.5 stencils)
- [ ] `tests/Feature/Cable/CableScheduleUpdatePersistsPortFksTest.php` — picker → controller round-trip persists all 4 FKs from `items[N][source_device_id]` etc.
- [ ] `tests/Feature/Cable/CableScheduleUpdatePersistsOverrideNoteTest.php` — `connector_override_note` accepted (max 500) and persisted when ports are incompatible
- [ ] `tests/Feature/Cable/CableScheduleCrossProjectFkInjectionTest.php` — T-22-A4: engineer in project A cannot pick a device belonging to project B (request returns 422)
- [ ] `tests/Feature/Cable/BackfillCablePortFksCommandTest.php` — dry-run default + `--apply` + 4 outcome categories (matched/ambiguous/no-device-match/already-set) + idempotency
- [ ] `tests/Feature/Cable/CableScheduleXlsxRegressionTest.php` — D-10 invariant: XLSX byte-equivalence for NULL-FK rows AND for newly-FK-populated rows (FK columns invisible to XLSX export)
- [ ] Extend existing `tests/Feature/Drawings/SchematicGeneratorServiceTest.php` — explicit "NULL FK is fine" case for D-10 invariant on SchematicGenerator + SchematicD2SourceBuilder

**No framework install needed** — PHPUnit ^11.5.3, Mockery ^1.6, FakerPHP ^1.23 all present in `composer.json`.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Modal UX feels right on tablet landscape (D-02 side-by-side) | DRAW-38 | Layout + tap targets are visual; no Dusk in this repo | 1. Open a project's cable schedule edit page on iPad-sized viewport (1024×768). 2. Tap 🔗 icon on any row. 3. Confirm SOURCE column left / DESTINATION column right, no horizontal scroll. 4. Pick Source Device → Source Port → Dest Device → Dest Port. 5. Confirm Apply writes canonical "{Mfr} {Model} ({Port})" labels into From/To. |
| 🔗 icon faded vs filled visual state (D-03) | DRAW-38 | Visual color check (teal `btn-teal` palette match) | 1. Open cable schedule with mix of FK-set and FK-unset rows. 2. Confirm unset rows show faded outline icon; set rows show filled teal icon matching Save button color. |
| Override-note required field UX (D-04) | DRAW-39 | Inline form-validation visual + accessibility | 1. In picker modal pick incompatible ports (HDMI src + RJ45 dest). 2. Confirm yellow warning banner appears with "Override reason (required)" field. 3. Confirm Apply button stays disabled until note is non-empty. 4. Submit and confirm note persists to `connector_override_note`. |
| Phone-portrait collapse to stacked (D-02) | DRAW-38 | Responsive viewport check | 1. Open picker on 375px-wide viewport (iPhone portrait). 2. Confirm SOURCE column stacks above DESTINATION (no horizontal scroll). 3. Confirm Apply/Cancel buttons remain reachable. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (8 new test files + 1 extension)
- [ ] No watch-mode flags (PHPUnit single-shot only)
- [ ] Feedback latency < 10s for per-task commit (Cable filter)
- [ ] `nyquist_compliant: true` set in frontmatter after planner accepts this map

**Approval:** pending

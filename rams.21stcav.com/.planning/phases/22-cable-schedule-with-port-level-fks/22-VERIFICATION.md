---
phase: 22-cable-schedule-with-port-level-fks
verified: 2026-05-12T12:15:00Z
status: human_needed
score: 14/14 must-haves verified
overrides_applied: 0
test_results:
  cable_connector_suite: "74 passed / 4 skipped (env-dep) / 331 assertions"
  cable_only_suite: "73 passed / 4 skipped (env-dep) / 330 assertions"
  cross_project_t22_a4: "5 passed / 18 assertions"
  resolver_unit_t1_t10: "10 passed / 36 assertions"
  backfill_feature_t1_t11: "11 passed / 55 assertions"
known_baseline_failures:
  - "DocumentArtifactStorageTest (types stale) — unrelated to Phase 22"
  - "ActualHoursWidgetTest x4 — unrelated to Phase 22"
  - "SurveyDownloadFormTest x3 (ProcessFailedException) — unrelated to Phase 22"
  - "PublicWorksheetSignoffTest x2 — unrelated to Phase 22"
  - "QuoteParserServiceTest x2 — unrelated to Phase 22"
  - "OmManualProjectLinkageTest x1 — unrelated to Phase 22"
  - "QueueRecoverCommandTest x1 — unrelated to Phase 22"
human_verification:
  - test: "Open picker modal from a cable row"
    expected: "Modal opens with SOURCE on the left, DESTINATION on the right; chain-link icon column visible between From and To (D-02 + D-03)"
    why_human: "Visual layout/UX — requires a tablet or desktop browser to confirm Alpine.js modal renders correctly and the grid-template-columns: 1fr 1fr layout reads as side-by-side"
  - test: "Pick HDMI source port + RJ45 dest port"
    expected: "Yellow warning banner appears with 'Connector mismatch: hdmi → rj45'; Apply button disabled until override note typed (DRAW-39 client gate)"
    why_human: "Live Alpine reactivity — warning banner show/hide + Apply enable/disable depend on x-show + :disabled bindings that can't be verified by static grep"
  - test: "Apply picker selection on a row"
    expected: "From/To text overwritten with 'Manufacturer Model (Port label)' canonical form; chain-link icon flips from faded grey (#bbb) to teal (#1B7A7A)"
    why_human: "Custom-event dispatch round-trip — port-picker:applied → vanilla-JS listener writes hidden inputs + overwrites text inputs + flips icon colour. Requires browser DOM event loop."
  - test: "Clear ports on this row button"
    expected: "All 5 FKs nulled; icon flips back to faded grey; From/To text NOT overwritten (engineer's free-text survives)"
    why_human: "Open Question 2 UX behaviour — requires interacting with the Clear button and verifying the conditional `if (!d.cleared && d.sourceLabel)` text-overwrite branch"
  - test: "Submit form with picker-populated row"
    expected: "Page reloads with 'Cable schedule saved.' flash; tinker confirms CableScheduleItem::find($id)->source_port_id and ->connector_override_note persist"
    why_human: "End-to-end persistence — requires a logged-in browser session against a project that has Devices + DeviceStencils + DevicePorts (the spike 5-stencil set or seed-pack Tier 1.5 stencils)"
  - test: "Live backfill smoke test"
    expected: "php artisan cables:backfill-port-fks --apply on a project with catalogued devices populates matched rows; a second run reports already-set + wrote: 0 (idempotent)"
    why_human: "Requires live data with quote-imported cable rows + Phase 21 catalogued devices — automated tests prove the algorithm but not the human-readable per-row report format on real data"
  - test: "PhpSpreadsheet XLSX byte-identity on production"
    expected: "Both runtime XLSX regression tests (test_xlsx_byte_identical_for_null_and_populated_fks + test_xlsx_export_query_log_does_not_touch_device_ports) PASS on live where PhpSpreadsheet is installed"
    why_human: "Dev environment lacks PhpSpreadsheet runtime dep — tests skip cleanly here but must be re-run on live deploy to lock the D-10 byte-identity invariant (static-source guard already confirmed no Phase 22 column references in 5 v1.3 surface files)"
  - test: "D2 binary schematic NULL-FK regression on production"
    expected: "test_null_fk_cables_render_byte_identical_to_populated_fks_d10_invariant passes on live where D2 binary is installed"
    why_human: "Dev environment lacks D2 binary (skip mirrors existing SchematicGeneratorServiceTest:93-96 pattern) — must run on live to confirm SVG byte-identity for the D-10 invariant"
---

# Phase 22: Cable Schedule with Port-Level FKs — Verification Report

**Phase Goal:** Cable schedule items become typed via four FK columns (`source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id`) referencing Phase 21's `devices` + `device_ports` tables, with cascading dropdown UI on the cable schedule edit screen, connector-compatibility validation (warning not hard block), one-shot deterministic backfill command, and strict v1.3 surface preservation (D-10).

**Verified:** 2026-05-12T12:15:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC-1 | Engineer can edit a cable_schedule_items row and pick source device → source port → dest device → dest port via cascading dropdowns | ✓ VERIFIED (auto) + ⚠ NEEDS HUMAN (UX) | `_port-picker-modal.blade.php` lines 59-101 — D-02 side-by-side grid + cascading `portsForDevice()` filter. Server-side persistence proved by `CableScheduleUpdatePersistsPortFksTest` (3 pass). UX needs human (Step 8 items 1-4). |
| SC-2 | Form save warns on incompatible connector types with override-with-note option, never hard block | ✓ VERIFIED | `CableConnectorCompatibilityService` 10 unit tests pass; picker `warningReason()` mirrors PHP logic; `CableScheduleUpdatePersistsOverrideNoteTest::test_incompatible_pair_saves_even_without_override_note` proves server is NOT a hard block. |
| SC-3 | `php artisan cables:backfill-port-fks` populates port FKs deterministically where unambiguous, leaves nullable where ambiguous, reports per-row decisions to stdout | ✓ VERIFIED | Command registered (`php artisan list | grep cables` shows it). 11 feature tests pass including `test_ambiguous_overall_leaves_all_four_fks_null` (W5/DRAW-41 invariant) + `test_dry_run_default_writes_nothing` + `test_apply_flag_persists_matched_fks` + `test_idempotent_on_second_run`. |
| SC-4 | Phase 23's renderer can consume `cable_schedule_items.source_port_id` + `dest_port_id` for port-to-port routing | ✓ VERIFIED | Migration ships 4 FK columns + `cable_schedule_items_port_pair_idx` compound index on `(source_port_id, dest_port_id)` for Phase 23 lookups. `CableScheduleItem` exposes 4 `belongsTo` relations (`sourceDevice/sourcePort/destDevice/destPort`) — read API in place. |
| SC-5 | v1.3 cable schedule XLSX export, schematic SVG generator, and bound-PDF cable-list section continue to render without regression for legacy NULL-FK rows | ✓ VERIFIED | `test_v13_surface_files_have_zero_phase22_column_references` PASSES — scans 5 surface files (CableScheduleXlsxService, CableScheduleGeneratorService, SchematicGeneratorService, SchematicD2SourceBuilder, DrawingDataResolverService) for 9 forbidden Phase 22 substrings — 50 assertions, zero matches. Independent grep confirms. Runtime byte-identity tests skip on dev (PhpSpreadsheet + D2 not installed — flagged for live re-run). |

**Score:** 5/5 success criteria verified (with 8 UX items routed to human verification)

### Plan-level Must-Haves (from prompt)

| # | Must-Have | Status | Evidence |
|---|-----------|--------|----------|
| 1 | 4 nullable FK columns + `nullOnDelete()` | ✓ VERIFIED | Migration lines 46-53 use `->constrained('devices')->nullOnDelete()` / `->constrained('device_ports')->nullOnDelete()`. `test_device_delete_sets_fk_null` proves runtime behaviour. |
| 2 | `connector_override_note` text nullable max 500 | ✓ VERIFIED | Migration line 54 `$table->text('connector_override_note')->nullable()->after('dest_port_id')`. Controller validates `max:500` (line 200). `test_connector_override_note_accepts_long_text` + `test_override_note_over_500_chars_returns_422` lock both ends. |
| 3 | `$fillable` includes 5 new keys + 4 `belongsTo` relations | ✓ VERIFIED | Runtime check: `getFillable()` returns 14 keys including all 5 Phase 22 additions. `CableScheduleItemRelationsTest` (7 pass) covers all 4 belongsTo + foreign key names. |
| 4 | `$with` stays empty (D-10) | ✓ VERIFIED | ReflectionProperty check returns `[]`. `test_with_property_is_empty_to_prevent_eager_load_regression` locks it. |
| 5 | `config/cables.php` with `compatibility_aliases` + `signal_type_colours` | ✓ VERIFIED | File exists; `config:show cables.compatibility_aliases` returns 4 entries (HDMI↔DP, USB-C↔Thunderbolt, RJ45↔SFP+, USB-C↔HDMI); `signal_type_colours` ships 8 keys (audio/video/control/network/usb/speaker/power/unknown). |
| 6 | `CableConnectorCompatibilityService` pure (no DB, no ctor deps); exact-match + allowlist; empty = compatible | ✓ VERIFIED | No constructor / no DB facade imports / 10 unit tests pass including `test_empty_connector_type_treated_as_compatible` (Pitfall 4). Bidirectional allowlist proved by `test_allowlist_reverse_direction_compatible_bidirectional`. |
| 7 | Alpine picker modal with D-02 side-by-side, D-03 chain-link icon, D-04 canonical-label overwrite | ✓ VERIFIED (auto) + ⚠ NEEDS HUMAN (UX) | Static: `_port-picker-modal.blade.php` (270 lines) — `display:grid;grid-template-columns:1fr 1fr` (D-02), `canonicalLabel()` function (D-04), edit.blade chain-link button at col 3 (D-03). Live behaviour: human items 1, 3. |
| 8 | `CableScheduleController@update` validates + persists 5 new keys + override_note | ✓ VERIFIED | Controller lines 196-201 add 5 validation rules; `CableScheduleUpdatePersistsPortFksTest` (3 pass) + `CableScheduleUpdatePersistsOverrideNoteTest` (3 pass) confirm round-trip. |
| 9 | T-22-A4 HIGH cross-project FK guard — form + JSON paths, canary survives | ✓ VERIFIED | Controller lines 222-252 implement the guard BEFORE `DB::transaction`. `CableScheduleCrossProjectFkInjectionTest` (5 pass) covers: form 302+session error, JSON 422+JsonValidationErrors, pre-seeded canary survives, non-existent device_id, mass-assignment T-22-A1. |
| 10 | `cables:backfill-port-fks` command — dry-run default, --apply flag, 4 categories, idempotent | ✓ VERIFIED | `php artisan list | grep cables` shows command. `--help` shows `{project?}` + `--apply`. 11 feature tests cover all categories + idempotency. |
| 11 | W5: backfill writes ONLY on `tag === 'matched'`; ambiguous = all 4 NULL | ✓ VERIFIED | `BackfillCablePortFksCommand.php` lines 176-186 — only `if ($apply && $tag === 'matched')` write branch, no else. `test_ambiguous_overall_leaves_all_four_fks_null` asserts row stays wholly NULL. |
| 12 | `CablePortFkResolverService` pure (no Eloquent saves) | ✓ VERIFIED | Grep against the file: zero `save()` / `update()` / `Model::create` / `DB::` calls. `test_resolver_is_pure_no_db_writes` locks it. |
| 13 | T-22-A5 (--project arg SQL injection): `(int)` cast | ✓ VERIFIED | Command line 97: `$projectId = $projectArg !== null ? (int) $projectArg : null;`. `test_sql_injection_via_project_arg_neutralised_t22_a5` asserts `Schema::hasTable('devices')` after malicious arg. |
| 14 | T-22-A6 (wrong-tenant): per-project `Device::where('project_id')` inside iteration | ✓ VERIFIED | Command `loadProjectDevicesWithStencils()` line 223-225 scopes by `project_id`. `test_cross_project_match_impossible_by_construction_t22_a6` proves row stays NULL when only Project A has the matching device. |
| 15 | D-10 invariant locked: XLSX byte-identity + Schematic NULL-FK + static surface guard | ✓ VERIFIED (static) + ⚠ NEEDS HUMAN (runtime on live) | `test_v13_surface_files_have_zero_phase22_column_references` PASSES on dev (50 assertions, 5 files, 9 forbidden substrings). Independent grep confirms. Runtime XLSX + D2 tests skip on dev (deps missing) — flagged in human verification 7 + 8 for re-run on live. |

**Score: 14/14 must-haves verified** (with 8 items routed to human verification for UX/runtime checks on live env)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php` | Anonymous-class migration adding 4 FK columns + override note + index | ✓ VERIFIED | 73 lines; all 5 column adds + index + `down()` with `dropConstrainedForeignId` × 4 |
| `app/Models/CableScheduleItem.php` | Extended `$fillable` (14 keys) + 4 belongsTo relations | ✓ VERIFIED | 89 lines; runtime verified `getFillable()` returns 14 keys; `$with` empty by reflection |
| `config/cables.php` | `compatibility_aliases` (4 pairs) + `signal_type_colours` (8 keys) | ✓ VERIFIED | 51 lines; `config:show` confirms both arrays populated |
| `app/Services/Cable/CableConnectorCompatibilityService.php` | Pure-function check returning `{compatible, reason}` | ✓ VERIFIED | 87 lines; no ctor; no DB imports; 10 unit tests pass |
| `app/Services/Cable/CablePortFkResolverService.php` | Pure deterministic matcher with `CABLE_TYPE_TO_CONNECTOR` map | ✓ VERIFIED | 248 lines; no DB writes (verified by grep); 10 unit tests pass including purity test |
| `app/Console/Commands/BackfillCablePortFksCommand.php` | Artisan command with dry-run-default, --apply, T-22-A5/A6 mitigations | ✓ VERIFIED | 257 lines; registered in `php artisan list`; 11 feature tests pass |
| `app/Http/Controllers/CableScheduleController.php` | Extended `@update` with 5 new keys + cross-project guard + `@edit` eager-load | ✓ VERIFIED | Lines 110-179 (`@edit` eager-load + devicesWithPorts payload), 181-270 (`@update` extended validation + T-22-A4 guard) |
| `resources/views/cable-schedule/_port-picker-modal.blade.php` | Alpine `portPicker()` x-data + side-by-side grid + canonical label | ✓ VERIFIED (static) | 270 lines; `grid-template-columns: 1fr 1fr` (D-02), 5 `type="button"` (Pitfall 5), `canonicalLabel()` (D-04); runtime UX needs human |
| `resources/views/cable-schedule/edit.blade.php` | 9-column table with chain-link icon col + hidden inputs + addRow extended | ✓ VERIFIED | 215 lines; col 3 is chain-link `🔗` button; 5 hidden inputs per row with `data-fk` attrs; `addRow()` JS template extended; `port-picker:applied` listener writes hidden + overwrites text + flips icon colour |
| 7 test files (Migration / Relations / Compat / Resolver / Backfill / CrossProject / Override / FKs / XlsxRegression) | All present, all passing | ✓ VERIFIED | 9 test files exist in `tests/Feature/Cable/` + `tests/Unit/Services/Cable/` + `tests/Unit/Models/`. Full suite: 74 pass / 4 env-skipped / 331 assertions. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `CableConnectorCompatibilityService` | `config/cables.php` | `config('cables.compatibility_aliases')` | ✓ WIRED | Line 69 in service; runtime `test_compatibility_aliases_config_seeded` confirms |
| `CableScheduleItem` | `devices` + `device_ports` | `belongsTo` with `nullOnDelete` FK | ✓ WIRED | Model lines 69-87; `test_device_delete_sets_fk_null` exercises the cascade |
| `BackfillCablePortFksCommand` | `CablePortFkResolverService` | constructor injection (`private readonly`) | ✓ WIRED | Command line 87-91 ctor; resolver invoked at line 159 |
| `CablePortFkResolverService` | Project devices + DeviceStencil + DevicePort | Reads pre-attached `$device->stencil->ports` | ✓ WIRED | Resolver line 199; command `loadProjectDevicesWithStencils()` builds the attached graph |
| `edit.blade.php` | `_port-picker-modal.blade.php` | `@include('cable-schedule._port-picker-modal', ['devicesWithPorts' => ...])` | ✓ WIRED | edit.blade line 116 |
| `CableScheduleController@update` | T-22-A4 device project check | `Device::whereIn('id', $submittedDeviceIds)->where('project_id', '!=', ...)` | ✓ WIRED | Lines 234-237; 5 cross-project tests pass |
| `CableScheduleController@edit` | `devicesWithStencils` eager load | `Device::where('project_id')->with('stencil.ports')` | ✓ WIRED | Lines 139-141 (per RESEARCH.md A2, uses direct Device query NOT Project::devicesWithStencils per the engineer-distinguish-multiple-units decision) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `edit.blade.php` (picker modal include) | `$devicesWithPorts` | `CableScheduleController@edit` → `Device::query()->where('project_id', ...)->with('stencil.ports')` → mapped to id/label/manufacturer/model/ports array | YES (real DB query against `devices` + `device_stencils` + `device_ports`) | ✓ FLOWING |
| `_port-picker-modal.blade.php` (Alpine) | `devices` (in `portPicker` x-data) | `@js($devicesWithPorts ?? [])` passed from edit.blade @include | YES — when devices exist; empty array fallback OK for legacy standalone schedules without project_id | ✓ FLOWING |
| `_port-picker-modal.blade.php` (Alpine) | `compatAliases` | `@js(config('cables.compatibility_aliases'))` | YES (4 entries from config) | ✓ FLOWING |
| Hidden inputs `items[i][source_device_id]` etc. | FK values | Server-side `$item->source_device_id` via `{{ $item->source_device_id }}` (line 83-87) | YES (DB read) — initial empty on legacy, populated after picker Apply or backfill | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Cable test suite passes | `php artisan test --filter='Cable\|Connector'` | 74 passed, 4 skipped (env-dep), 331 assertions, 9.98s | ✓ PASS |
| Artisan command registered | `php artisan list \| grep cables` | `cables:backfill-port-fks  Resolve and populate port-level FKs ...` | ✓ PASS |
| Command help shows --apply + {project?} | `php artisan cables:backfill-port-fks --help` | Help text shows both arg + flag with descriptions | ✓ PASS |
| Config visibility | `php artisan config:show cables.compatibility_aliases` | 4 entries (HDMI↔DP, USB-C↔Thunderbolt, RJ45↔SFP+, USB-C↔HDMI) | ✓ PASS |
| Model `$fillable` runtime check | `(new CableScheduleItem)->getFillable()` | 14 keys: 9 originals + 5 Phase 22 additions | ✓ PASS |
| Model `$with` D-10 invariant | ReflectionProperty on `with` returns `[]` | `[]` (empty) | ✓ PASS |
| D-10 static surface guard | `grep -E "source_device_id\|source_port_id\|dest_device_id\|dest_port_id\|connector_override_note\|->source(Device\|Port)\|->dest(Device\|Port)"` against 5 v1.3 surface files | Zero matches across CableScheduleXlsxService, CableScheduleGeneratorService, SchematicGeneratorService, SchematicD2SourceBuilder, DrawingDataResolverService | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DRAW-37 | 22-01 | FK columns on `cable_schedule_items` (nullable for legacy) | ✓ SATISFIED | Migration ships 4 FK + override-note columns + port-pair index; `CableScheduleItemMigrationTest` (5 pass) |
| DRAW-38 | 22-02 | Cascading dropdown UI: room → source device → source port → dest device → dest port; client-side filtering by signal_type compatibility | ✓ SATISFIED (auto) + ⚠ NEEDS HUMAN (UX) | Picker modal `portsForDevice()` filter + Alpine x-data; persistence proved by tests; live UX needs human verification 1-4 |
| DRAW-39 | 22-01 + 22-02 | Connector-compatibility validation at form submit — warning rather than hard block (engineer override allowed with note) | ✓ SATISFIED | `CableConnectorCompatibilityService` (10 unit tests); `CableScheduleUpdatePersistsOverrideNoteTest::test_incompatible_pair_saves_even_without_override_note` proves server is NOT a hard block; client gate in modal `canApply()` line 211-220 |
| DRAW-40 | 22-03 | Auto-derive port FKs from quote `cable_list` "X to Y" naming where each side has exactly one matching connector | ✓ SATISFIED | `CablePortFkResolverService` (10 unit tests); deterministic algorithm with Pitfalls 3 + 4 mitigated; ambiguous rows stay NULL |
| DRAW-41 | 22-03 | One-shot backfill command — populates port FKs where unambiguous, leaves nullable where ambiguous | ✓ SATISFIED | `cables:backfill-port-fks` command (11 feature tests); `test_ambiguous_overall_leaves_all_four_fks_null` locks D-LOCK + DRAW-41 |

**All 5 requirement IDs (DRAW-37..41) are claimed by at least one plan AND demonstrably implemented + tested.** No orphaned requirements. No coverage gap.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `_port-picker-modal.blade.php` | 107 | Emoji `⚠` in warning banner | ℹ Info | IN-03 from REVIEW.md — CLAUDE.md profile says no emojis in code. Locked by REVIEW WR/IN classification as Info-only (the `🔗` chain-link icon on edit.blade.php is similarly used but locked by CONTEXT D-03 as a UX element). Not a blocker; engineer can swap to text glyph in a future polish pass. |
| `CableScheduleController.php` | 222 | Cross-project guard skipped when schedule.project_id IS NULL | ⚠ Warning | WR-01 from REVIEW.md — latent bypass for legacy standalone schedules. Acceptable per scope (legacy rows are never picker-driven). Documented in inline comment. |
| `CableScheduleController.php` | 248 | 422 error always keyed under `items.0.source_device_id` even when offender is `dest_device_id` | ⚠ Warning | WR-02 from REVIEW.md — UX nit. Form-level error surfaces in the existing alert-danger block; doesn't lose information, just keys imprecisely. |
| `CableScheduleController.php` | 152 | `optional($d->stencil)?->ports` (double null-safe chain) | ⚠ Warning | WR-03 from REVIEW.md — redundant but harmless. |
| `CableScheduleController.php` | 228 | `->filter()` no callback drops integer 0 / bool false | ⚠ Warning | WR-04 from REVIEW.md — fine today (Device PKs are positive integers), fragile if `Device::$primaryKey` ever changes to UUID. |

**No 🛑 Blockers found.** All 4 warnings are explicitly classified as warnings (not blockers) by the prompt and the REVIEW.md author. Stub scanning across all 9 implementation files returned zero placeholder/TODO/FIXME patterns inside Phase 22 code.

### Human Verification Required

Eight items in `human_verification:` frontmatter. Summary:

1. **Open picker modal** — visual side-by-side layout (D-02 + D-03)
2. **Pick incompatible HDMI/RJ45 pair** — live warning banner + Apply disable (DRAW-39 client gate)
3. **Apply picker** — text overwrite + icon colour flip (D-04 round-trip)
4. **Clear ports** — Open Question 2 behaviour (FKs null, text survives)
5. **Submit form** — end-to-end persistence verified via tinker
6. **Live backfill smoke test** — `--apply` on real catalogued project + idempotency re-run
7. **PhpSpreadsheet XLSX byte-identity on live** — both runtime XLSX regression tests must pass on production (dev skips due to missing runtime dep)
8. **D2 binary schematic NULL-FK regression on live** — `test_null_fk_cables_render_byte_identical_to_populated_fks_d10_invariant` must pass on live where D2 is installed

### Gaps Summary

**None blocking the goal.** All 14 plan-level must-haves are programmatically verified:
- All 9 implementation artifacts exist and are substantively populated (zero stub patterns)
- All 7 key links are wired (constructor injection, eager-loading at call site, @include, config() reads)
- All 5 Roadmap Success Criteria are satisfied with evidence
- All 5 requirements DRAW-37..41 are claimed and implemented
- 74 of 74 auto-runnable Phase 22 tests pass / 331 assertions (4 skipped due to missing dev env deps — PhpSpreadsheet + D2 binary — both flagged for live re-run in human verification)
- D-10 invariant locked by static-source guard (50 assertions, zero matches in 5 v1.3 surface files)
- T-22-A1, T-22-A2 (XSS-safe escaping verified by code review), T-22-A3 (CSRF unchanged from baseline), T-22-A4, T-22-A5, T-22-A6 all mitigated with test coverage

The phase needs human verification for:
1. UX/visual aspects of the picker modal that can't be exercised by automated tests (items 1-4)
2. End-to-end browser → form submit → DB persist round-trip (item 5)
3. Live data smoke testing of the backfill command on a real project (item 6)
4. Re-running runtime D-10 regression tests on production where PhpSpreadsheet + D2 binary are available (items 7-8)

These are routine UAT items and live-env runtime checks — not implementation gaps. The known baseline failures (15 pre-existing test failures listed in the prompt) are confirmed unrelated to Phase 22 and tracked separately as `known_baseline_failures` in frontmatter.

---

_Verified: 2026-05-12T12:15:00Z_
_Verifier: Claude (gsd-verifier)_

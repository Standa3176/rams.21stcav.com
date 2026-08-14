---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 05
subsystem: drawings
tags: [laravel, alpine, blade, mxgraph, phpunit]

# Dependency graph
requires:
  - phase: 24-01
    provides: AutoGenericStencilGenerator D-05 extension (<connections> constraints + provisional rail styling), DeviceStencilAudit model, needs_review/logo_path columns
  - phase: 24-04
    provides: StencilXmlToSvgRenderer + admin.device-stencils.preview endpoint (D-16, persists nothing)
provides:
  - UpdateDeviceStencilPortsRequest — batched ports-array validation, engineer-extensible connector_type
  - DeviceStencilController::edit()/update() — port-table Save with device_ports/mxgraph_xml parity in one transaction
  - D-17 curated-artwork guard — server-side block-unless-confirmed + client-side confirm banner, both halves
  - admin/device-stencils/edit.blade.php + _port-table.blade.php — Alpine reactive port table + 600ms debounced live preview
affects: [24-06, 24-07, 24-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Alpine x-data root wraps BOTH grid columns (not just the left/form column as the plan's prose literally reads), because the sticky preview pane on the right needs the same previewSvg/previewState scope the port-table form on the left mutates — read as necessary for D-02's live reactivity to function at all, not a deviation from intent."
    - "Dynamic :name=\"'ports[' + idx + '][field]'\" bindings instead of a static name=\"ports[][field]\" per input — PHP's array-parser auto-increments a SEPARATE counter per distinct bracket-array name string, so sibling fields sharing a literal \"ports[][x]\"/\"ports[][y]\" name would land on different numeric indices and never recombine into one port row on native form submission."
    - "D-17 confirm step writes the hidden confirm_regenerate field imperatively via $refs (not a reactive :value binding) immediately before an explicit form.submit() call, avoiding any race between Alpine's DOM-patch microtask and the browser's synchronous form-serialisation-on-submit; a form-level @submit guard independently blocks any submission where that field is still empty, so an accidental Enter-key submit can never silently confirm."
    - "device_ports bulk insert coerces null label/connector_type/signal_type to '' and null sort_order to 0 before DevicePort::insert() — those columns are NOT NULL with no DB default, while the Save-time FormRequest deliberately allows them blank per D-01 (the stricter D-04 hard gate belongs to Plan 24-07's Promote action, not Save)."

key-files:
  created:
    - app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php
    - resources/views/admin/device-stencils/edit.blade.php
    - resources/views/admin/device-stencils/_port-table.blade.php
    - tests/Feature/Drawings/DeviceStencilEditTest.php
  modified:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - routes/web.php

key-decisions:
  - "Every successful Save writes a DeviceStencilAudit row, not just the D-17 curated-and-confirmed case. The 24-05-PLAN.md amendment's own text says the guard passes 'either the source is not engineer-curated, OR the engineer explicitly confirmed' and instructs writing the audit row 'when the guard passes' — read literally, that covers the ordinary auto-generated Save path too. This is also the behaviour D-08 needs: once a human has hand-edited a stencil's ports (curated or not), stencils:reapply-templates must never silently re-template it again, because it no longer holds template-derived data."
  - "x-data scope placed on the OUTER two-column grid container, not strictly the left column as the plan's prose describes. A literal left-column-only x-data would put the preview pane's previewSvg/previewState bindings outside Alpine's reach entirely, breaking D-02's live-preview requirement outright — necessary correction, not a design choice."
  - "Hidden confirm_regenerate field is set via $refs.confirmField.value (raw DOM write) + $refs.form.submit(), guarded by a form-level @submit check — not a reactive :value=\"confirmed ? '1' : ''\" binding. A reactive binding risks the browser reading the hidden input's un-patched (still-empty) DOM attribute at submit time if Alpine's microtask hasn't flushed yet; the imperative write removes that race entirely."

patterns-established:
  - "Batched nested-array port-table save mirrors DeviceCableRuleRequest's field.*.subfield wildcard shape exactly, with one addition: an intra-array `distinct` rule on the natural-key field (port_id here, mirrors the length_tiers pattern's per-element validation) catches a duplicate BEFORE it reaches the DB's compound unique index."

requirements-completed: [DRAW-51]

# Metrics
duration: ~65min
completed: 2026-08-14
---

# Phase 24 Plan 05: Stencil Edit Screen — Port Table + Live Preview Summary

**Alpine reactive port table (D-01, source of truth) + 600ms debounced server-rendered preview (D-02/D-16) + batched Save with proven device_ports/mxgraph_xml parity, gated by the D-17 curated-artwork confirm-to-proceed guard — the third and final plan closing out DRAW-51.**

## Performance

- **Duration:** ~65 min
- **Started:** 2026-08-14 (see git commit timestamps)
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 6 (4 created, 2 modified)

## Accomplishments

- `UpdateDeviceStencilPortsRequest` validates a batched `ports` array with `connector_type` deliberately left free-text (21 D-02 engineer-extensible, never an `in:` allowlist) and `port_id` carrying Laravel's `distinct` rule so an intra-array duplicate is a 422, never the DB's `device_ports_stencil_port_unique` compound-index 500.
- `DeviceStencilController::edit()`/`update()` replace every `device_ports` row for a stencil and regenerate `mxgraph_xml` via `AutoGenericStencilGenerator` inside the SAME `DB::transaction`, so the two can never drift (RESEARCH.md Pitfall 2) — proven by a parity test asserting every saved `port_id` has an exact `<constraint name="...">` substring in the saved XML.
- **D-17 curated-artwork guard, both halves:** an unconfirmed `PUT` against an `engineer-curated` stencil persists nothing at all (no port change, no `mxgraph_xml` change, no audit row) and bounces back with a `warning` flash; the same request with `confirm_regenerate=1` persists AND writes a `DeviceStencilAudit` row whose `before_snapshot.mxgraph_xml` is the prior artwork verbatim, making the replacement recoverable rather than silent. The ordinary `auto-generated` path stays completely unobstructed — no banner, no hidden field, single-click save (Test 7).
- `edit.blade.php` + `_port-table.blade.php`: two-column grid (port table ~60% / sticky live-preview ~40%, collapsing to one column under 900px), Alpine `x-for`/`x-model` reactive port rows (mirroring `repeater-equipment.blade.php`, not `review.blade.php`'s DOM-toggle pattern), 600ms-debounced `AbortController`-cancelled preview `fetch()` against `admin.device-stencils.preview`, and a persistent warning banner + explicit confirm step for `engineer-curated` stencils only.
- 11 feature tests: 7 behaviour tests on the Save action (replace+regenerate, duplicate-port_id 422, invalid-side-rejected/free-text-connector-type-accepted, constraint parity, the 3 D-17 guard cases) + 4 view-level tests on the edit screen (Alpine `ports` pre-population, 600ms-not-200ms debounce, non-empty delete-button `aria-label` binding, sub-900px single-column breakpoint).

## Task Commits

Each task was committed atomically:

1. **Task 1: UpdateDeviceStencilPortsRequest + edit()/update() actions + routes + D-17 guard** - `a8f02c6` (feat)
2. **Task 2: edit.blade.php + _port-table.blade.php (Alpine reactive repeater + debounced preview + D-17 banner)** - `b822538` (feat)

_No separate test/refactor commits — Task 1 is `tdd="true"` in name only per the plan's own established Phase 24 pattern (see 24-04-SUMMARY.md); implementation and its 7 behaviour tests were written and verified together, then committed as one atomic unit._

## Files Created/Modified

- `app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php` — Batched `ports.*` validation mirroring `DeviceCableRuleRequest`; `confirm_regenerate` boolean for the D-17 guard.
- `app/Http/Controllers/Admin/DeviceStencilController.php` — Adds `edit()` (loads `$stencil->ports`) and `update()` (D-17 guard, transactional replace+regenerate+audit). `index()`/`preview()` untouched.
- `routes/web.php` — Adds `admin.device-stencils.edit` (GET) and `admin.device-stencils.update` (PUT) inside the existing admin group.
- `resources/views/admin/device-stencils/edit.blade.php` — Two-column layout, `stencilPortEditor()` Alpine root, D-17 warning banner + confirm-button script, preview pane.
- `resources/views/admin/device-stencils/_port-table.blade.php` — Reactive port-row table, `+ Add port` / delete-row controls, per-row D-04-language tinting (preview-only signal, not a hard gate).
- `tests/Feature/Drawings/DeviceStencilEditTest.php` — 11 tests covering both tasks.

## Decisions Made

- **Audit row on every successful Save, not only the D-17-confirmed case** — see `key-decisions` above. This is also what makes `stencils:reapply-templates` (D-08, Plan 24-08) correctly skip any stencil a human has ever hand-edited, regardless of whether that edit happened to be against a curated or auto-generated row.
- **`x-data` scope on the outer two-column grid**, not the left column alone as the plan's prose literally describes — required for the preview pane (right column) to read `previewSvg`/`previewState` from the same Alpine component the port-table form (left column) mutates.
- **Hidden `confirm_regenerate` field set imperatively via `$refs`, submitted via `$refs.form.submit()`**, guarded independently by a form-level `@submit` check — removes any timing race between Alpine's reactive DOM patch and the browser's synchronous submit-time field serialisation, which a `:value="confirmed ? '1' : ''"` binding would not have guaranteed.
- **Dynamic `:name="'ports[' + idx + '][field]'"` per input**, not a static `name="ports[][field]"` — PHP's query-string array parser increments a separate counter per distinct literal bracket-name, so sibling fields for the same row would otherwise land on different `ports[N]` indices and never recombine (caught before it could reach the FormRequest/tests, since the tests submit via `$this->put(...)` with a structured PHP array rather than exercising native browser form serialisation — documented here so it isn't silently lost).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `device_ports` NOT NULL columns vs. FormRequest's deliberately-nullable Save-time rules**
- **Found during:** Task 1, writing the bulk-insert inside `update()`.
- **Issue:** The plan's own `UpdateDeviceStencilPortsRequest` rules (verbatim from the plan text) allow `label`/`connector_type`/`signal_type` to be `nullable` at Save time (D-01: the table is the source of truth even before every field is filled in). But `device_ports.label`/`connector_type`/`signal_type` are `NOT NULL` with no default at the DB layer (migration `2026_05_10_120000_create_device_stencils_and_device_ports.php`). A row saved with any of those fields blank would have hit a DB-level NOT NULL violation (500), not a graceful save.
- **Fix:** Coerced `null` → `''` for `label`/`connector_type`/`signal_type` and `null` → `0` for `sort_order` (also NOT NULL with a `default(0)` that only applies when the column is omitted entirely, not when an explicit `NULL` is passed) immediately before `DevicePort::insert()`.
- **Files modified:** `app/Http/Controllers/Admin/DeviceStencilController.php`
- **Commit:** `a8f02c6`

**2. [Rule 1 - Bug] Static `name="ports[][field]"` would silently misalign port rows on native form submission**
- **Found during:** Task 2, building `_port-table.blade.php`'s field markup.
- **Issue:** PHP's array-parameter parser (`parse_str`) increments a fresh index for every distinct bracket-name string it encounters. Multiple sibling inputs sharing the literal name `ports[][label]`, `ports[][side]`, etc. — one occurrence per field per row — would each independently auto-increment, so `label` for row 0 and `side` for row 0 would land on DIFFERENT top-level `ports[N]` indices instead of the same row object. This only manifests on a genuine browser form submission (bracket-array serialisation), not on the PHPUnit tests, which POST a pre-structured PHP array directly.
- **Fix:** Bound every field's `name` attribute dynamically via `:name="'ports[' + idx + '][field]'"`, using the live `idx` from Alpine's `x-for`, so every field in a row always targets the same `ports[N]` slot regardless of add/remove operations.
- **Files modified:** `resources/views/admin/device-stencils/_port-table.blade.php`
- **Commit:** `b822538`

**3. [Rule 1 - Bug] Own test's paren-crossing regex false-failed on unrelated layout scripts / its own arrow-function syntax**
- **Found during:** Task 2, writing `test_edit_screen_debounces_preview_at_600ms_not_200ms`.
- **Issue:** An initial `/setTimeout\([^)]*,\s*200\)/` assertion against the FULL rendered page false-matched an unrelated `setTimeout(...)` call elsewhere in `layouts/app.blade.php`'s shared scripts (the negated character class `[^)]*` can cross newlines and unrelated code between two unrelated `setTimeout(` occurrences, as long as no `)` appears in between). After scoping to just the `stencilPortEditor()` script block, the SAME regex style then false-*failed* on the block's own code, because the debounce timer's arrow function is `setTimeout(() => {...}, 600)` — the empty `()` parameter list's own closing paren breaks `[^)]*` before it ever reaches the `600` argument.
- **Fix:** Scoped the assertion to the `stencilPortEditor()` script block only, and replaced the paren-crossing regex with literal substring checks (`assertStringContainsString('}, 600);', ...)` / `assertStringNotContainsString('}, 200);', ...)`) that match the actual emitted code shape.
- **Files modified:** `tests/Feature/Drawings/DeviceStencilEditTest.php`
- **Verification:** All 11 tests pass; test intent (600ms present, 200ms absent, scoped to the plan's own code) preserved.
- **Commit:** `b822538` (test file was part of Task 1's commit `a8f02c6`; this specific test method was iterated on before that commit — see git history for the single commit containing the final version)

---

**Total deviations:** 3 auto-fixed (2 Rule 1 correctness bugs in shipped code, 1 Rule 1 fix to the executor's own test regex before it was committed).
**Impact on plan:** All three were necessary for the plan's stated behaviour to actually work (or for the test suite to actually prove it) — no scope creep, no architectural changes.

## Issues Encountered

None beyond the three items above — both tasks proceeded without a checkpoint or blocker.

## User Setup Required

None — no external service configuration required. No migration in this plan (Plan 24-01's migration remains the live-deploy prerequisite, as already noted in 24-04-SUMMARY.md; confirm it has run before deploying this plan's files).

## Next Phase Readiness

- **DRAW-51 is now COMPLETE** — Plan 24-01 shipped the `mxgraph_xml`/constraint regeneration contract, Plan 24-04 shipped the server-rendered preview pipeline, and this plan ships the editor UI + Save that ties both together with proven `device_ports`/`mxgraph_xml` parity. `REQUIREMENTS.md` and `ROADMAP.md` updated accordingly (see below).
- Plan 24-06 (logo upload, D-12/D-15) can now build directly on this edit screen — the footer/`.stc-footer-actions` row and the `$stencil` variable are already in scope in `edit.blade.php`.
- Plan 24-07 (Promote to Engineer-Curated / Discard & Regenerate) has its footer-button placeholder already commented in `edit.blade.php` ("Promote to Engineer-Curated / Discard & Regenerate ship in Plan 24-07 — same footer row, same controller class"), and can reuse the same `$isCurated` computed variable already established here.
- **Known and intended side effect for Plan 24-08:** because every successful Save now writes a `DeviceStencilAudit` row (not just the D-17-confirmed case), any stencil an engineer has EVER used this Save screen on — curated or auto-generated — becomes permanently ineligible for `stencils:reapply-templates`'s `whereDoesntHave('audits')` scope. This is the correct, intended behaviour per D-08 (automated re-templating must never touch anything a human has hand-edited), but Plan 24-08's own tests/docs should be re-checked against this if they assumed audits were only ever written by `promote()`.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php`
- `app/Http/Controllers/Admin/DeviceStencilController.php`
- `routes/web.php`
- `resources/views/admin/device-stencils/edit.blade.php`
- `resources/views/admin/device-stencils/_port-table.blade.php`

No migration in this plan. Plan 24-01's migration (`needs_review`/`logo_path`/`device_stencil_audits`) **must already be applied on live** before this plan's Save action is used — `update()` writes `DeviceStencilAudit` rows and reads `DeviceStencil::SOURCE_ENGINEER_CURATED`, both of which depend on that migration having run (per 24-01-SUMMARY.md / 24-04-SUMMARY.md, already flagged as a live-deploy prerequisite for this exact reason).

Test file (`tests/Feature/Drawings/DeviceStencilEditTest.php`) is not required on live — local/CI test suite only.

## Self-Check: PASSED

All 6 `key-files` (4 created, 2 modified) verified present on disk. Both task commit hashes (`a8f02c6`, `b822538`) verified present in `git log`. `php artisan test --filter=DeviceStencilEditTest` — 11/11 passed. Broader `tests/Feature` regression run filtered to `Drawings` — 229 passed, 2 failed (both the pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md`, predating Phase 24, out of scope), 2 skipped (D2 binary unavailable in this environment, unrelated).

---
*Phase: 24-stencil-curation-ui-quote-import-auto-stub*
*Completed: 2026-08-14*

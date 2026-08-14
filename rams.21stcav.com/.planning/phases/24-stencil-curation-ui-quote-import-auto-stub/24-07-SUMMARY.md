---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 07
subsystem: drawings
tags: [laravel, alpine, blade, mxgraph, phpunit, audit-trail]

# Dependency graph
requires:
  - phase: 24-01
    provides: DeviceStencilAudit model (ACTION_PROMOTE/ACTION_DISCARD_REGENERATE), needs_review column, CategoryPortTemplateResolver
  - phase: 24-05
    provides: admin/device-stencils/edit.blade.php two-column edit screen with a footer-button placeholder comment left for this plan
  - phase: 24-06
    provides: edit.blade.php's logo-upload widget + $isCurated computed variable, both reused unmodified
provides:
  - StencilPromotionValidator — D-04 two-tier hard-block/soft-warn evaluate() gate, dependency-free, callable from HTTP and console contexts
  - DeviceStencilController::promote() — server-side re-validated (T-24-17), audits, redirects to the list with the exact UI-SPEC success copy
  - DeviceStencilController::discard() — unconditional reset-to-template, audited, never runs the validator
  - admin.device-stencils.promote / admin.device-stencils.discard POST routes
  - edit.blade.php footer buttons — Promote to Engineer-Curated (client-side JS mirror of the hard-block rules, UX only) + Discard & Regenerate (data-confirm pattern)
  - DeviceStencilCurationFlowTest — end-to-end criterion 3 proof via real HTTP routes
affects: [24-08, 24-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "StencilPromotionValidator is a plain, dependency-free service class rather than a FormRequest — the only shape in the codebase that is callable identically from an HTTP controller action AND a future console command, mirroring CategoryPortTemplateResolver/AutoGenericStencilGenerator's own dependency-free design."
    - "Promote/Discard are separate <form> elements, siblings of (not nested inside) the ports-Save <form> — native HTML forbids nested forms. Each renders as its own flex item inside the same .stc-footer-actions row, so the visual 'same footer row' contract from 24-05's placeholder comment holds without any markup restructuring trick (no display:contents needed)."
    - "Client-side promotionBlockingReasons() in the Alpine component is a byte-matched JS mirror of StencilPromotionValidator's PHP rules, used ONLY to drive the Promote button's disabled attribute and the reason lines above it. It reads the LIVE (possibly unsaved) ports[] array for responsive UX — the server always re-validates the ACTUALLY-PERSISTED ports on every promote() request regardless of what this JS says (T-24-17)."

key-files:
  created:
    - app/Services/Drawings/StencilPromotionValidator.php
    - tests/Feature/Drawings/DeviceStencilPromotionTest.php
    - tests/Feature/Drawings/DeviceStencilCurationFlowTest.php
  modified:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - routes/web.php
    - resources/views/admin/device-stencils/edit.blade.php

key-decisions:
  - "Every StencilPromotionValidator 'blocking' string carries the 'Blocked: ' prefix baked into the returned value itself (not added by the Blade template at render time). The plan's own <behavior> prose for Test 1 informally wrote the zero-ports reason without that prefix, but the plan's <acceptance_criteria> explicitly demands byte-for-byte matches against 24-UI-SPEC.md's Copywriting Contract table, and every literal example in that table (and in the plan's own <interfaces> block) already has 'Blocked: ' baked in. Resolved in favour of the UI-SPEC source of truth + the stricter acceptance criterion — the Blade template needs no separate prefixing step, and a hostile client tampering with the JS mirror gains nothing either way."
  - "Test 3 (duplicate port_id) builds two UNSAVED DevicePort model instances and injects them via $stencil->setRelation('ports', ...) instead of persisting two rows with the same (device_stencil_id, port_id). The device_ports_stencil_port_unique compound index — the exact reason D-04 documents this check as existing — makes that combination genuinely unpersistable in real data; the in-memory injection proves the defence-in-depth check itself works without fighting the DB constraint that makes the scenario D-04 warns about theoretically unreachable through any known write path."
  - "discard() deliberately leaves DeviceStencil.source and .needs_review untouched — the plan's action text scopes discard's writes to device_ports + mxgraph_xml + the audit row only. A discarded engineer-curated stencil stays engineer-curated (its ports are just reset to template defaults); this mirrors how stencils:reapply-templates (Plan 24-08) also never mutates source."

patterns-established:
  - "D-04's two-tier promotion gate — dependency-free evaluate() service, re-run unconditionally server-side regardless of client state — is the template any future 'stage-gated destructive action with a client-side UX hint' surface in this codebase should follow."

requirements-completed: [DRAW-53]

# Metrics
duration: ~50min
completed: 2026-08-14
---

# Phase 24 Plan 07: Promotion + Audit Trail + Discard-and-Regenerate Summary

**StencilPromotionValidator's D-04 two-tier hard-block/soft-warn gate, re-run unconditionally server-side on every promote() request (T-24-17) regardless of client state, wired to Promote/Discard footer buttons and a D-03 audit trail on both actions — closing DRAW-53 and completing every requirement (DRAW-50/51/52/53) Phase 24 set out to ship.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-14 (see git commit timestamps)
- **Completed:** 2026-08-14
- **Tasks:** 3/3 completed
- **Files modified:** 6 (3 created, 3 modified)

## Accomplishments

- `StencilPromotionValidator::evaluate(DeviceStencil): array{blocking, warnings}` — dependency-free service class (no FormRequest precedent fits, since it must be callable from both an HTTP action and any future console context). Hard-blocks on zero ports, any port missing `label`/`connector_type`/`signal_type`/`direction` (grouped into ONE line per field across all ports, not one line per port — proven by a dedicated behaviour test), and duplicate `port_id`. Soft-warns (promotable) on no manufacturer logo, `signal_type = unclassified`, and missing `x_pct`/`y_pct`. Copy is byte-for-byte from 24-UI-SPEC.md's Copywriting Contract table, including the `"Blocked: "` prefix baked into every hard-block string.
- `DeviceStencilController::promote()` re-runs the FULL validator server-side on every request — proven by a direct-POST bypass test against a zero-port stencil that asserts `source`/`needs_review` never flip and zero audit rows are written when blocked. A successful promote flips `source` to `engineer-curated`, clears `needs_review`, and writes ONE `device_stencil_audits` row (`action = promote`) with non-empty before/after snapshots, then redirects to the list with the exact UI-SPEC success copy (`"{Manufacturer} {Model} promoted to Engineer-Curated. It now renders with full ports on every project using part number {part_number}."`).
- Criterion 4 (cross-project propagation, "zero extra code") proven directly against `DeviceStencilCacheService::resolveForPartNumber()` — the SAME cache lookup a second project would make returns the stub before promotion and the full engineer-curated row with its ports after, using zero code beyond what Phase 21 D-03 already shipped.
- `DeviceStencilController::discard()` never runs the validator and always succeeds, even against a port set that would hard-block Promote — re-resolves the category template via `CategoryPortTemplateResolver` (the identical call shape `stencils:reapply-templates` uses), wholesale-replaces `device_ports`, regenerates `mxgraph_xml`, and writes a `discard-regenerate` audit row capturing the prior state. Deliberately leaves `source`/`needs_review` untouched — a reset, not a promotion.
- `edit.blade.php` footer gains `Promote to Engineer-Curated` (`.btn-primary`, disabled via a client-side `promotionBlockingReasons()` JS mirror of the PHP hard-block rules — UX only, never the enforcement boundary) and `Discard & Regenerate` (`.btn-danger-outline`, the project's established `data-confirm`/`data-confirm-label`/`data-confirm-danger` pattern with the exact UI-SPEC confirm copy), each as its own `<form>` (native HTML forbids nesting forms inside the existing ports-Save form) styled into the same visual footer row Plan 24-05's placeholder comment reserved.
- `DeviceStencilCurationFlowTest` proves the full criterion-3 loop end to end through real HTTP routes (not mocked services): seed a zero-port auto-generated stub → filtered list shows it → edit screen shows the zero-ports blocking copy → PUT valid ports → POST a logo upload → POST promote (redirect + success flash) → list view now shows the engineer-curated badge with no needs-review badge, scoped precisely to that stencil's table row (not the flash banner, which also contains the part_number substring).

## Task Commits

Each task was committed atomically:

1. **Task 1: StencilPromotionValidator (D-04 hard/soft gate)** - `6f887d3` (feat)
2. **Task 2: promote()/discard() actions + routes + footer wiring + audit trail** - `a917cf1` (feat)
3. **Task 3: DeviceStencilCurationFlowTest (criterion 3, full end-to-end)** - `5f8afcd` (feat)

_No separate test/refactor commits — this plan's tasks are `type="auto"` (Task 1 is `tdd="true"` in name only, per every prior Phase 24 plan's own precedent, e.g. 24-05-SUMMARY.md) — each task commit bundles its implementation + tests together._

## Files Created/Modified

- `app/Services/Drawings/StencilPromotionValidator.php` — D-04's two-tier gate. `evaluate(DeviceStencil $stencil): array{blocking: string[], warnings: string[]}`.
- `app/Http/Controllers/Admin/DeviceStencilController.php` — Adds `promote()` and `discard()`. `index()`/`edit()`/`update()`/`uploadLogo()`/`preview()` untouched.
- `routes/web.php` — Adds `admin.device-stencils.promote` (POST) and `admin.device-stencils.discard` (POST) inside the existing admin group.
- `resources/views/admin/device-stencils/edit.blade.php` — Adds `promotionBlockingReasons()` to the `stencilPortEditor()` Alpine component + the Promote/Discard/Cancel footer forms, replacing Plan 24-05's placeholder comment.
- `tests/Feature/Drawings/DeviceStencilPromotionTest.php` — 5 validator behaviour tests (Task 1) + 4 controller behaviour tests (Task 2), 9 total.
- `tests/Feature/Drawings/DeviceStencilCurationFlowTest.php` — 1 end-to-end test covering the full 7-step criterion-3 flow.

## Decisions Made

- **"Blocked: " prefix lives inside the validator's returned strings, not added at render time** — see `key-decisions` above; resolved via the plan's own stricter acceptance criterion + the UI-SPEC source of truth over an informally-worded behaviour-test example.
- **Duplicate-port_id test injects unsaved model instances via `setRelation()`** rather than persisting two DB rows — the compound unique index that D-04 itself cites as the reason this check exists makes the literal scenario unpersistable, so the test proves the in-memory defence-in-depth logic directly.
- **`discard()` never touches `source`/`needs_review`** — scoped strictly to `device_ports` + `mxgraph_xml` + the audit row, per the plan's literal action text ("an explicit reset-to-known-good action, not a promotion").
- **Promote/Discard are separate sibling `<form>` elements**, not nested inside the existing ports-Save `<form>` — HTML forbids form nesting; each is simply its own flex item in the same `.stc-footer-actions` row, so no `display:contents` workaround or JS-driven cross-form submission was needed.

## Deviations from Plan

None beyond the "Blocked: " prefix interpretation documented above under Decisions Made (a spec-interpretation choice, not a Rule 1-4 auto-fix) — plan executed as written. All `must_haves.truths`, both `artifacts`, and both `key_links` from the plan frontmatter are satisfied; all three tasks' `<acceptance_criteria>` are met and asserted by tests.

## Issues Encountered

- **`git status --short` at session start showed the working tree already carrying dozens of untracked `.png`/`.yml` screenshot files and an unrelated `.playwright-mcp/` directory** at the RAMS project root, none of which belong to this plan's `files_modified` list. Confirmed via the working-directory sanity check (`ls artisan composer.json`) that the project root itself was correct; these files were pre-existing session artifacts from prior work, not created by this plan, and were left completely untouched — never staged, never referenced in any commit.

## User Setup Required

None — no external service configuration required. No migration in this plan. Plan 24-01's migration (`needs_review`/`logo_path`/`device_stencil_audits`) **must already be applied on live** before this plan's `promote()`/`discard()` actions are used — both write `DeviceStencilAudit` rows and `promote()` reads/writes `DeviceStencil::SOURCE_ENGINEER_CURATED`/`needs_review`, all of which depend on that migration having run (same live-deploy prerequisite every prior Phase 24 plan since 24-01 has flagged).

## Next Phase Readiness

- **DRAW-53 is now COMPLETE** — the last of Phase 24's four requirements (DRAW-50/51/52/53). Verified against every other 24-0x plan's frontmatter `requirements:` field that DRAW-53 is claimed nowhere else; this plan is its sole source. `REQUIREMENTS.md` and `ROADMAP.md` updated accordingly.
- **Plans 24-01 through 24-07 must ALL be live** (including 24-01's migration) before Plan 24-09's engineer-driven bounded top-10 curation can begin — Plan 24-09 depends on the full curation UI loop this plan closes out being reachable in production.
- **Plan 24-09 is the ONLY remaining plan in Phase 24.** It is a human-checkpoint plan (`autonomous: no`, per its own frontmatter) requiring engineer curation of real devices via the now-complete admin UI, and is explicitly out of scope for an autonomous executor — it was NOT executed by this session, per the orchestrator's instruction.
- **D-17 interaction, now also true for promote/discard:** per 24-05-SUMMARY.md, any audit row (edit, promote, or discard-regenerate) makes a stencil permanently ineligible for `stencils:reapply-templates` (D-08's `whereDoesntHave('audits')` scope). This plan's promote()/discard() both write audit rows unconditionally on success, so a promoted or discarded stencil is — correctly — never touched by automated re-templating again.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Services/Drawings/StencilPromotionValidator.php`
- `app/Http/Controllers/Admin/DeviceStencilController.php`
- `routes/web.php`
- `resources/views/admin/device-stencils/edit.blade.php`

**No migration in this plan.** Plan 24-01's migration (`needs_review`/`logo_path`/`device_stencil_audits`) **must already be applied on live** before this plan's `promote()`/`discard()` actions are used on the deployed site — same prerequisite every Phase 24 plan since 24-01 has flagged (this plan writes `DeviceStencilAudit` rows and reads/writes `DeviceStencil::SOURCE_ENGINEER_CURATED`/`needs_review`, both of which hard-depend on that schema existing).

**Phase 24 is now fully code-complete except Plan 24-09** (human-checkpoint, engineer curation of real devices — not an autonomous-executor task). Once Plans 24-01 through 24-08's files are all live and `php artisan migrate` has run, the entire admin curation loop (browse → edit → upload logo → promote/discard) is usable in production, and Plan 24-09 can begin.

Test files (`tests/Feature/Drawings/DeviceStencilPromotionTest.php`, `tests/Feature/Drawings/DeviceStencilCurationFlowTest.php`) are not required on live — local/CI test suite only.

## Self-Check: PASSED

All 6 `key-files` (3 created, 3 modified) verified present on disk. All 3 task commit hashes (`6f887d3`, `a917cf1`, `5f8afcd`) verified present in `git log`. `php artisan test --filter=DeviceStencilPromotionTest` — 9/9 passed. `php artisan test --filter=DeviceStencilCurationFlowTest` — 1/1 passed. Broader `tests/Feature/Drawings` regression run — 245 passed, 2 failed (both the pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md`, predating Phase 24, out of scope), 2 skipped (D2 binary unavailable in this environment, unrelated).

---
*Phase: 24-stencil-curation-ui-quote-import-auto-stub*
*Completed: 2026-08-14*

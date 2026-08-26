# Phase 27 — Deferred Items

## From Plan 27-06 (GATE-09 engineer-row extension)

**GATE-09 still cannot see `material_handling.large_items` on two production
paths this plan did not touch:**

1. **`RamsController::updateAndDownload()`** ("Save Review" / "Save &
   Download" on the RAMS review screen, `app/Http/Controllers/RamsController.php:332-576`).
   This action builds `$generatedData` from `$rams->generated_data`, mirrors
   several reviewed-data sub-keys onto it (`site_emergency` explicitly, at
   `:576-582`, with a comment explaining exactly why), then calls
   `RamsComplianceUpgradeService::upgrade($generatedData)` directly at
   `:583`. It builds `$reviewedData['material_handling']` (`:494-502`) but
   never mirrors it onto `$generatedData` the way `site_emergency` is
   mirrored — so `enforceDisplayLiftGate()`'s engineer-row loop (Plan 27-06
   Task 2) sees an empty array on this path and cannot fire, even though the
   engineer just typed a non-conforming team size into the form on this
   exact request.

2. **The PDF template reads engineer rows directly from `reviewed_data`,
   bypassing `generated_data`/`upgrade()`/GATE-09 entirely.**
   `resources/views/pdf/rams.blade.php:418` —
   `$matHandling = $rams->reviewed_data['material_handling'] ?? [];` — is a
   second, independent read of the same engineer-typed field that never
   passes through `RamsComplianceUpgradeService::upgrade()` at all. No mirror
   fix on the generation side can close this: the PDF's `material_handling`
   table is wired to `reviewed_data`, not `generated_data`, by design. GATE-09
   as built (a step inside `upgrade()`) structurally cannot police this render
   path without either (a) re-pointing the PDF template at `generated_data`
   (a rendering-behaviour change, and a bigger call than one line — Rule 4
   territory), or (b) duplicating the gate check at PDF-render time (a second
   enforcement point, exactly the kind of divergence-risk D-03 exists to
   prevent).

**Why this plan fixed `RamsBuilderService::runFromReview()`/`runPipeline()`
but not these two:** Plan 27-06's Task 3 acceptance criteria specifically
require proving GATE-09 fires via those two named entry points
(`RamsBuilderService::runFromReview()`/`runPipeline()`, the ones
`DisplayLiftDualPathTest` already exercised for the derived-items branch in
Plan 27-03). The `RamsBuilderService` mirror fix was the minimal change
needed to satisfy that specific, already-scoped proof — it was not scoped to
audit or fix every other place `material_handling` is read. `RamsController.php`
and `resources/views/pdf/rams.blade.php` are not in 27-06-PLAN.md's
`files_modified` list, and the PDF fix in particular is an architectural
question (which source of truth the PDF should render from) that this plan's
`<threat_model>` did not scope and the user was not asked about.

**Recommended next step:** a small follow-up plan (or quick task) that:
- Mirrors `material_handling` onto `$generatedData` in
  `RamsController::updateAndDownload()`, exactly like the existing
  `site_emergency` mirror at `:576-582`.
- Decides, with the user, whether the PDF template should be re-pointed at
  `generated_data['material_handling']` (so it inherits GATE-09 coverage for
  free) or whether GATE-09 needs a second, explicit call site at PDF-render
  time — and records that decision, since it changes what "the live PDF is
  gated" actually means.

Not fixed in this plan because it was discovered near the end of Task 3 while
proving the dual-path tests, is outside 27-06-PLAN.md's scope, and the PDF
question in particular needs a user decision rather than a unilateral Rule 1-3
auto-fix.

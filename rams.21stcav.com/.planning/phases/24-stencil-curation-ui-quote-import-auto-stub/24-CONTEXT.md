# Phase 24: Stencil Curation UI + Quote-Import Auto-Stub - Context

**Gathered:** 2026-08-09
**Status:** Ready for planning
**Milestone:** v2.0 Engineering-Grade AV Drawings (Phase 4 of 5)

<domain>
## Phase Boundary

Phase 24 ships the **tooling that makes Tier 1 port-data fill tractable**. It does NOT do the filling itself at scale, and it does NOT introduce AI.

Two complementary mechanisms:

1. **Quote-import auto-stub** — every new `part_number` seen during a quote import gets a `device_stencils` row plus category-derived `device_ports` rows, generated from a deterministic, allowlist-driven template. Flagged `source = auto-generated` + `needs_review = true` so it lands in a review queue immediately, rather than waiting for someone to open a drawing.

2. **Admin curation UI** at `/admin/device-stencils` — filterable list of stubs awaiting promotion, a per-stencil edit screen (port table + live preview, logo upload), and a "Promote to engineer-curated" action that flips `source`, clears `needs_review`, and writes an audit row.

Cross-project propagation is free — Phase 21's `firstOrCreate(part_number)` cache (21 D-03) means a promoted stencil reaches every project using that part_number on next render, with no per-project migration.

**The problem being solved:** the 2026-05-15 audit found 5 of 97 seeded stencils carry real port data. Verified again 2026-08-09 — `_v1.3-promoted.json` holds 53 entries and `_top-50-gap.json` holds 39, **all 92 with zero ports**; only the 5 hand-curated spike files have ports. Without port rows, AI port-pair proposals and Phase 22's cascading port dropdowns cannot run on real projects.

**Maps requirements:** DRAW-50, DRAW-51, DRAW-52, DRAW-53 (see D-13 for the DRAW-54 correction).

**NOT in scope:**
- **AI port extraction from datasheets** — Phase 25 (`DevicePortExtractorService`, DRAW-54). Phase 24 is deterministic-only; the `ai-extracted` source value is reserved but never written here.
- **Renderer changes beyond stub XML** — Phase 23 owns the renderer. Phase 24 only extends `AutoGenericStencilGenerator`'s emitted shape (per D-05).
- **Bulk 91-device curation sprint** — criterion 5 bounds delivery to the top-10 by quote volume. The rest is ongoing engineer labour, not phase scope.
- **Bound PDF / O&M swap** — Phase 25 (DRAW-57 / DRAW-58).

</domain>

<decisions>
## Implementation Decisions

Decision IDs are Phase-24-scoped. References to Phase 21 decisions are written as "21 D-XX" to avoid collision.

### Port editor interaction model

**D-01 — Hybrid editor: port table is the source of truth, live preview confirms**

The edit screen shows an editable table of port rows (label, side, connector_type, signal_type, direction, sort_order, port_id, x_pct/y_pct) beside a read-only preview pane that re-renders as the table changes.

Explicitly NOT full drag-on-canvas, despite the ROADMAP's "drag-port handles" wording. Rationale: `device_ports` stores position as two nullable decimals (`y_pct` for left/right ports, `x_pct` for top/bottom) and Phase 23's renderer computes positions when they are null — so dragging is a convenience over two numbers, not the only way to express intent. No admin screen in the codebase does drag interaction today; `admin/device-cable-rules` and `admin/devices` are both plain Blade + Alpine forms. A drag canvas would be a bespoke net-new pattern with its own test surface, for marginal gain over numeric fields plus visual confirmation.

**D-02 — Preview renders server-side through the REAL builder**

A debounced POST to an admin preview route runs the same service that produces production stencils (`AutoGenericStencilGenerator` for stub shapes / `DrawIoBuilderService` for full render) against the **unsaved** port set, returning SVG.

Explicitly NOT a client-side JS redraw. A second rendering implementation in JS would have to stay in step with the PHP builder forever, and any divergence would silently teach engineers the wrong thing about their own curation — the preview must not be able to lie. Round-trip cost is acceptable: this is a single-engineer admin screen, not a hot path.

**D-03 — Curation audit trail in a dedicated `device_stencil_audits` table**

Columns: `device_stencil_id`, `user_id`, `action` (`promote` / `edit` / `discard-regenerate`), before/after port snapshot (json), `created_at`.

Generic-named per 21 D-09 so it ports to SCC without rename. NOT `ProjectActivityLog` — that table is project-scoped, and `device_stencils` are deliberately global (no `project_id`), which is precisely what makes cross-project propagation work. NOT `metadata` json alone — that only ever holds the last edit, and cannot answer "who curated what, when" across ~100 stencils, which criterion 5's bounded top-10 delivery target needs to report against.

`metadata` retains its 21 D-02 role (notes, last-edited-by convenience). The audit table is the record of truth; do NOT denormalise last-edited-by into both.

**D-04 — "Promote to engineer-curated" hard-gates structure, soft-warns quality**

**BLOCK promotion** on:
- zero ports
- any port missing `label`, `connector_type`, `signal_type`, or `direction`
- duplicate `port_id` within the stencil (the `device_ports_stencil_port_unique` compound index would throw anyway — catch it in validation, not as a 500)

**WARN but allow** on:
- no manufacturer logo
- `signal_type` left `unclassified`
- missing positional hints (`x_pct` / `y_pct` null)

This deliberately diverges from Phase 22's "warn, never hard block" precedent for connector compatibility. Rationale: promotion is not a save — it removes the stencil from the `needs_review` queue AND starts propagating to every project using that part_number. A promoted zero-port stencil hides the coverage gap it was created to expose. The long tail where a datasheet genuinely omits something is Phase 25's problem, not a reason to weaken the gate here.

### Category templates → stencil XML

**D-05 — Auto-stub emits PROVISIONAL port rails + mxGraph constraints**

`AutoGenericStencilGenerator` is extended so that when a category template seeds N ports, the generated `mxgraph_xml` includes rails and mxGraph constraints for them — styled distinctly (dashed / muted) to mark them template-derived rather than verified.

This **supersedes 21 D-04's "No port rails"** for the stub-with-template-ports case. The bare no-ports placeholder remains for zero-port stubs (ambiguous category per D-07, or genuinely portless items like brackets).

Rationale: per 21 D-02, `port_id` is the mxGraph constraint name used for cable termination in Phase 23. Ports with no constraint in the XML are usable by Phase 22's cascading dropdowns but **invisible to Phase 23's port-to-port cable router** — it would have nothing to terminate on. Data-only ports would also make the drawing contradict the database (blank box the DB says has four ports). Provisional styling preserves 21 D-04's real intent — "engineers know on sight which devices need promoting" — as a styling signal rather than as absence.

**Planner note on criterion 6:** ROADMAP criterion 6 says Tier 2 devices "continue to render with the placeholder". Read that as *no regression / no crash* for uncatalogued devices, not as a prohibition on provisional rails. Zero-port stubs still render exactly the 21 D-04 placeholder. Verification should assert the placeholder path is intact for portless stubs, and that provisional rails render distinctly for templated ones.

**D-06 — Template vocabulary lives in `config/drawings.php` under a new `port_templates` key**

Sits beside the existing `signal_colours`, `zone_vocab`, and `category_to_zone` maps — the same file DRAW-44 already designates for drawing configuration.

Version-controlled, so criterion 2's determinism guarantee ("the same import always produces the same stub shape") is enforced by git rather than by trusting that nobody edited a DB row. Reviewable in a PR diff. Zero migration.

Explicitly NOT a DB table. An admin-editable template table would make "same import, same shape" conditional on mutable DB state, and reproducing a past import's stub shape would require the table's history.

**Device-type vocabulary is NEW — do not reuse either existing "category" axis:**
- `EquipmentCategoryClassifier`'s 7 values (`hardware` / `cables` / `consumables` / `services` / `service_contracts` / `customer_supplied` / `option`) are a **commercial** axis, not a device type.
- `Device::ROLE_*` is only `source` / `destination` / `processor` — a signal-flow role far too coarse for ports (a display and a speaker are both `destination` with nothing in common port-wise).

Reuse the *mechanism*, not the vocabulary: `DrawingDataResolverService` lines 444-464 already does deterministic keyword→role inference, and `EquipmentCategoryClassifier` demonstrates the priority-ordered decision-tree pattern.

**D-07 — Multi-keyword conflicts resolve via an explicit precedence list; anything unenumerated → zero-port stub**

Known compound conflicts are enumerated in config with a declared winner (e.g. `bracket` beats `display`, `mount` beats `screen`, `cable` beats everything), so "Samsung 65\" Display Bracket" deterministically resolves to `bracket`.

ANY multi-match not covered by an explicit rule produces a **zero-port stub** flagged `needs_review`.

This honours criterion 2's "ambiguous categories produce a zero-port stub rather than a wrong guess" while still handling the compound descriptions that actually appear in AV quotes. Explicitly NOT a plain first-match-wins tree — that is never ambiguous by construction, which means it always produces an answer including a confidently wrong one that then propagates cross-project.

Resolution signals are limited to `part_number` prefix and a fixed description-keyword allowlist. **No AI, ever, in this path.**

**D-08 — Template changes never re-apply silently; opt-in artisan command**

New command `stencils:reapply-templates`, dry-run by default with a `--commit` flag, mirroring `PackagesReclassifyEquipmentCommand` (260725-qw3) and `BackfillCablePortFksCommand` (Phase 22).

Re-templates **only** stencils that are still `source = auto-generated` AND have no rows in `device_stencil_audits` — so it can never touch anything an engineer has edited or promoted.

Rationale: `firstOrCreate` protects curated work by never overwriting (21 D-03), but that also freezes every stub on the template version current when it was created. Auto-refreshing on import was rejected because it mutates data as a side effect of an unrelated action, and would let a template regression propagate before anyone noticed. Never re-applying was rejected because it strands the earliest-imported — and therefore highest-volume — devices on the worst templates, which is exactly backwards.

### Claude's Discretion

The user delegated these two areas. Decisions recorded here are binding on the planner unless the user revisits them.

**D-09 — Auto-stub hooks all THREE import paths via one shared service**

New `QuoteImportStencilStubber` service, invoked from:
- `app/Jobs/ExtractQuoteJob.php` (PDF upload path — the only path ROADMAP 24-01 named)
- `app/Core/Modules/QuoteImport/QuoteWerksImportService::buildExtractedData` (QuoteWerks direct import)
- `app/Jobs/ReimportQuoteJob.php` (re-import path)

Each call site gets its own feature test. One implementation, three call sites — there is no shared choke point downstream, because each path persists `ProjectPackage` separately.

**This corrects a ROADMAP defect.** ROADMAP 24-01 specifies only `ExtractQuoteJob`, which is dispatched from exactly one place — `QuoteImportController.php:57`, the PDF-upload path. Since 260725-qw4 the QuoteWerks Lookup tab is the **default** import route with PDF upload demoted to fallback, so hooking only `ExtractQuoteJob` would leave most real imports producing no stubs.

**Related existing behaviour the planner must not duplicate:** `Project::devicesWithStencils()` already creates bare Tier 1 stubs lazily at render time (21 D-07's documented mutating-read side effect). Phase 24 does not replace that; it moves stub creation *earlier* (import time) and *richer* (template ports), so the review queue populates before anyone opens a drawing, and surfaces the "stubs created" toast to the importer.

**D-10 — `needs_review` is a real indexed boolean column, not a metadata json flag**

Migration adds `needs_review` (boolean, default false, **indexed**) to `device_stencils`, and carries existing `metadata.needs_phase_24_curation = true` values (written by Plan 21-02) across into the new column in the same migration.

Rationale: criterion 3 requires the list view to filter `?source=auto-generated&needs_review=1`. MariaDB cannot index a json extract, so a `metadata`-based filter would table-scan every stencil on every list load. `metadata` keeps its 21 D-02 role for notes and last-edited-by.

**D-11 — The 92 existing zero-port stubs need no separate backfill mechanism**

They are all `source = auto-generated` with no audit rows, so they already qualify under D-08's re-apply rule. Running `stencils:reapply-templates` (dry-run, review, then `--commit`) templates them in one pass. Do not build a second one-shot backfill command.

**D-12 — Logo upload MUST route through `SvgSanitizerService`**

DRAW-52 accepts PNG/SVG. `app/Services/Drawings/SvgSanitizerService.php` already exists (shipped in the 2026-07-09 security batch WR-03/4/5) and parses SVG with `DOMDocument` + `LIBXML_NONET | LIBXML_NOENT`, stripping `<script>`, `<foreignObject>`, `on*` handlers, and `javascript:` / `data:image/svg+xml` schemes. Every uploaded SVG passes through it before persist — no exceptions, no new sanitiser.

### Scope corrections to ROADMAP.md

**D-13 — DRAW-54 belongs to Phase 25, not Phase 24**

ROADMAP lists Phase 24's requirements as DRAW-50..54 and maps DRAW-54 to plan 24-03. This is wrong. `.planning/REQUIREMENTS.md:71` files DRAW-54 (`DevicePortExtractorService` — Claude vision over datasheet PDFs) under `### Phase 25 — AI Assist`, and Phase 24's own ROADMAP goal text says "the AI-assisted port extraction layer remains Phase 25 scope."

**Phase 24's requirement set is DRAW-50, DRAW-51, DRAW-52, DRAW-53.** Plan 24-03 (bounded Tier 1 fill) is a delivery task with no requirement ID. The planner should not attempt to satisfy DRAW-54, and ROADMAP.md should be corrected via the roadmap handler — not by hand-editing (universal anti-pattern 15).

**D-14 — Admin route is `/admin/device-stencils`**

DRAW-50 specifies `/admin/device-stencils`; the Phase 24 goal text says `/admin/stencils`. DRAW-50 wins — it matches the established convention (`admin/devices`, `admin/device-cable-rules`) and the existing route-name pattern `admin.device-cable-rules.index`.

Route names: `admin.device-stencils.index` / `.edit` / `.update` / `.promote` / `.preview`. Sits inside the existing `Route::middleware('admin')->group()` block in `routes/web.php` (~line 251).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 24's own scope
- `.planning/ROADMAP.md` §"Phase 24: Stencil Curation UI + Quote-Import Auto-Stub" (lines 142-165) — goal, 6 success criteria, provisional 3-plan split. Note D-13 and D-14 correct it.
- `.planning/REQUIREMENTS.md` §"Phase 24 — Stencil Curation UI" (lines 60-65) — DRAW-50..53 verbatim. DRAW-54 at line 71 is Phase 25.

### Phase 21 foundation — the contract Phase 24 builds on
- `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` — **read D-02 (column shape), D-03 (firstOrCreate cache contract + race rationale), D-04 (Tier 1 no-rails placeholder, superseded here by D-05), D-07 (devicesWithStencils mutating-read side effect), D-09 (SCC-merge generic naming), D-13 (upload-to-live SUMMARY convention).**
- `.planning/phases/21-device-port-catalog-stencil-cache/21-02-seed-pack-promote-and-curate-SUMMARY.md` — seed pack structure, `source` enum, idempotency contract, `metadata.needs_phase_24_curation` flag origin.
- `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` — actual column shape incl. `device_ports_stencil_port_unique` compound index.

### Code Phase 24 extends
- `app/Services/Drawings/AutoGenericStencilGenerator.php` — `build(array $hints): array`; extended by D-05 to emit provisional rails.
- `app/Services/Drawings/DeviceStencilCacheService.php` — `resolveForPartNumber()` / `resolveMany()`; the cache contract that makes criterion 4 free.
- `app/Models/DeviceStencil.php` — `SOURCE_*` constants, `isCurated()`, `normalisePartNumber()`.
- `app/Models/DevicePort.php` — `SIDE_*` / `DIRECTION_*` constants.
- `config/drawings.php` — host for the new `port_templates` key (D-06); see existing `category_to_zone` at line 75 for shape precedent.

### Import paths to hook (D-09)
- `app/Jobs/ExtractQuoteJob.php` — PDF path; dispatched from `app/Http/Controllers/QuoteImportController.php:57`.
- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` §`buildExtractedData` (line 115) — QuoteWerks path, now the default import tab.
- `app/Jobs/ReimportQuoteJob.php` — re-import path.

### Pattern analogs to copy
- `resources/views/admin/device-cable-rules/` (`index` + `edit` + `_form`) — closest admin CRUD analog for the curation UI.
- `resources/views/admin/devices/` (`index` + `edit`) — filter-row pattern at `index.blade.php:112`; admin-editor-without-create precedent.
- `resources/views/layouts/navigation.blade.php:391-402` — where the admin nav entry goes.
- `app/Console/Commands/PackagesReclassifyEquipmentCommand.php` — dry-run-then-`--commit` pattern for D-08's `stencils:reapply-templates`.
- `app/Console/Commands/BackfillCablePortFksCommand.php` — Phase 22's per-row-decision stdout reporting style.
- `app/Services/Imports/EquipmentCategoryClassifier.php` — priority-ordered decision tree pattern (mechanism only — its vocabulary is the wrong axis, per D-06).
- `app/Services/Drawings/DrawingDataResolverService.php:444-464` — deterministic keyword→role inference precedent.

### Security
- `app/Services/Drawings/SvgSanitizerService.php` — mandatory for logo upload (D-12).

### Data being fixed
- `resources/data/device-stencils-seed/_INDEX.md` — manifest schema, source of truth for curation.
- `resources/data/device-stencils-seed/_v1.3-promoted.json` — 53 entries, zero ports.
- `resources/data/device-stencils-seed/_top-50-gap.json` — 39 entries, zero ports.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`DeviceStencilCacheService::resolveForPartNumber()`** — already implements the `firstOrCreate(part_number)` contract. The auto-stub calls through it rather than inserting directly, which is what makes criterion 4 (cross-project propagation) require zero new work.
- **`SvgSanitizerService`** — production-hardened SVG sanitiser already in the tree; DRAW-52's logo upload uses it as-is (D-12).
- **`admin/device-cable-rules` + `admin/devices` Blade trio** — index/edit/_form structure, filter row, `<x-…>` form components, cancel-URL convention. The curation UI should read as a sibling of these, not a new idiom.
- **`AutoGenericStencilGenerator::emitShape()`** — the private XML emitter; D-05 extends it rather than adding a parallel generator.

### Established Patterns
- **Admin routes** live in one `Route::middleware('admin')->group()` block in `routes/web.php` (~line 251), named `admin.{resource}.{action}`.
- **Deterministic classifiers** are priority-ordered decision trees with explicit short-circuits (`EquipmentCategoryClassifier`), never AI.
- **Data-migration commands** are dry-run by default with `--commit` to apply, and report per-row decisions to stdout.
- **Config-driven vocabularies** live as top-level keys in a domain config file (`config/drawings.php` holds four such maps already).
- **21 D-13 deployment convention** — every plan's SUMMARY.md ends with a 🚨 Files to upload to live section. Phase 24 ships migrations (`needs_review` column + `device_stencil_audits` table), so SUMMARYs must call out `php artisan migrate` AFTER upload and BEFORE using the new admin screen.
- **Lint gate** — every touched PHP file must lint clean under Herd PHP 8.4: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`.

### Integration Points
- **Import → stub:** three call sites (D-09) feeding one `QuoteImportStencilStubber`.
- **Stub → drawing:** `AutoGenericStencilGenerator` output consumed by Phase 23's renderer via `mxgraph_xml`; provisional rails must carry mxGraph constraints named by `port_id` (D-05).
- **Ports → cable schedule:** `device_ports` rows become FK targets for Phase 22's `cable_schedule_items.source_port_id` / `dest_port_id` cascading dropdowns; see `app/Services/Cable/StencilPortResolver.php`.
- **Promotion → every project:** no integration work — 21 D-03's cache lookup handles it. Criterion 4's integration test asserts this rather than implements it.
- **Admin nav:** new entry beside `admin.devices.index` / `admin.device-cable-rules.index` in `layouts/navigation.blade.php`.

</code_context>

<specifics>
## Specific Ideas

- **The preview must not be able to lie.** The single strongest steer from the discussion: one renderer, server-side, shared with production output. A JS reimplementation was rejected specifically because silent drift would teach engineers the wrong thing about their own curation.
- **Provisional styling over absence.** Phase 21 used *absence* of port rails as the "needs promoting" signal. Phase 24 keeps the signal but moves it to *styling* (dashed / muted), because absence now costs real function (Phase 23 cable termination).
- **Promotion is a claim, not a save.** The asymmetry justifying D-04's hard gate — a promoted stencil leaves the review queue and propagates everywhere, so a zero-port promotion actively hides the gap it was created to expose.
- **"Display Bracket" is the canonical ambiguity test case.** Matches both `display` and `bracket` keywords with opposite templates (2 HDMI vs zero ports). Use it as a named test in the resolver suite.

</specifics>

<deferred>
## Deferred Ideas

- **AI port extraction from datasheets** — Phase 25 (DRAW-54, `DevicePortExtractorService`). Phase 24 stays deterministic; the `ai-extracted` source value is reserved but never written.
- **Full drag-on-canvas port positioning** — rejected for this phase (D-01), not for all time. If engineers find numeric `x_pct` / `y_pct` entry painful during the top-10 fill, revisit as a follow-up; the table stays the source of truth either way, so a drag layer is additive.
- **Bulk / keyboard affordances for working the review queue fast** — raised as a candidate, not discussed. Worth revisiting once the top-10 fill gives real data on how slow the per-stencil loop actually is.
- **`stencils:coverage-report` fixture provenance** — the "top-10 by quote volume" input for criterion 5 was flagged but not discussed. Phase 21's D-15 independence rule (do not derive the reference list from the seed pack itself, or the assertion is circular) applies here by analogy. The planner should carry it forward; if it needs a decision, raise it during `/gsd-plan-phase`.
- **Whether plan 24-03 belongs in this phase at all** — raised as a candidate, not discussed. It is engineer labour rather than engineering, and could be a post-phase task. Left in scope as ROADMAP has it, bounded to top-10 by criterion 5.

</deferred>

---

*Phase: 24-Stencil Curation UI + Quote-Import Auto-Stub*
*Context gathered: 2026-08-09*

# Phase 20: Drawing Export Pipeline + O&M Integration - Context

**Gathered:** 2026-05-02
**Status:** Ready for planning
**Discussion outcome:** All clear — no additional discussion needed; research locks the architectural decisions. Four gray areas (bound PDF assembly, sheet-numbering UX, ZIP bundle scope, drawings queue isolation) deferred to planner discretion with documented recommendations below.

<domain>
## Phase Boundary

Last v1.3 phase. Wires Phase 17's schematics + Phase 18's rack elevations into:
1. **Bound multi-page project PDF** — cover sheet + drawing register table (sheet number / title / revision / date) + paginated drawings (schematics → rack elevations)
2. **Per-project configurable sheet numbering** — `AV-201` schematics + `AV-301` racks default
3. **ZIP bundle download** — all drawings (PDF + SVG + PNG) for a project in one click
4. **O&M Manual "Drawings" section** — extends Phase 17's `OmManualDocxService` patch to embed both schematic AND rack PNGs (one drawing per page, high-res)
5. **Production hardening** — `pdf:smoke-test --drawings` extension covering rack render, dedicated `drawings` queue, `@font-face` for fallback fonts, license audit (composer + npm), `--disable-dev-shm-usage` Browsershot flag

**Maps requirements:** DRAW-21, DRAW-23, DRAW-28 (3 of 22 v1.3 active requirements).

**NOT in scope (per v1.3 scope reduction 2026-05-02):**
- DXF export (DRAW-29) — moved to v2.0 backlog 999.1 with floor plans
- Floor plans (DRAW-14..20) — moved to v2.0
- Per-revision diff overlay PDF — out of scope
- Multi-stage drawing approval workflow — out of scope (status enum from Phase 17 stays simple)

</domain>

<decisions>
## Implementation Decisions

All major decisions are locked in `.planning/research/SUMMARY.md` + ARCHITECTURE.md §6 + PITFALLS.md CRIT-03/CRIT-04/MOD-01/MOD-10/MOD-12. The user reviewed the gray areas and selected "All clear" — research + planner discretion is sufficient.

### Locked from research (not up for revision)

- **Render path reuses existing infrastructure** — bound PDF uses Phase 17's `PdfRenderService::fromBlade` per-drawing then concatenates. No new render engine. PNG bundle uses existing `fromBladeAsPng`. SVG comes from `project_drawings.generated_svg` directly.
- **`OmManualDocxService` Drawings section already exists** (Phase 17 Plan 03) — Phase 20 extends it to embed rack PNGs in addition to schematic PNGs. Same `$drawingsSection = $phpWord->addSection(...)` pattern, no `$section` reuse, OmManual::project() relation confirmed at line 59.
- **DocumentArtifactStorage::TYPE_DRAWING already exists** — bound PDF + ZIP write to the same disk under `drawings/` subdirectory. Filename convention: `bound-{projectId}-v{version}-{ulid}.pdf` and `bundle-{projectId}-v{version}-{ulid}.zip`.
- **Production hardening — non-negotiable items per PITFALLS.md:**
  - CRIT-03 mitigation: `--disable-dev-shm-usage` Chrome flag in PdfRenderService AND a dedicated `drawings` queue (concurrency=1)
  - CRIT-04 mitigation: extend `pdf:smoke-test --drawings` to cover rack render in addition to schematic; pin chrome-headless-shell version in `.env.example`; embed Arial/Liberation Sans via `@font-face` for guaranteed font availability (already partially in place via the schematic Blade Arial-fallback fix from Phase 17 R0)
  - MOD-01 mitigation: license audit step — `composer licenses` + npm grep, blocks GPL/AGPL deps from landing
  - MOD-10 mitigation: bound PDF includes a generation timestamp + revision-counter in title block; if any drawing in the project has been regenerated since the bound PDF was last built, surface a "regenerate recommended" badge in the UI
  - MOD-12 mitigation: notification for the bound PDF fires from the LAST job in chain (not per-drawing) via `Bus::chain` if multiple regen jobs are queued; Phase 20 ships a single `BuildBoundPdfJob` that runs after the project's drawings are all ready
- **No DXF export** — DRAW-29 moved to v2.0 backlog 999.1 (engineer demand not confirmed; LibreDWG GPL blocker known).

### Recommended defaults for the four open gray areas (planner can adjust)

**Gray Area A — Bound PDF assembly: RECOMMENDATION = (c) hybrid approach.**
- Per-drawing PDF generation already works via `PdfRenderService::fromBlade('pdf.drawings.schematic'|'pdf.drawings.rack', ...)` — keep using that for individual drawing pages.
- Add a new `pdf.drawings.bound-cover` Blade view that renders cover sheet + drawing register table — render to PDF.
- New `BoundPdfBuilderService` concatenates: cover PDF + each drawing's PDF (looped in deterministic order: schematics first by `created_at`, then rack elevations) using a lightweight PHP PDF merge library (e.g. `setasign/fpdi-tcpdf` if not already in composer; otherwise spatie/pdf-merger).
- Failure semantics: if any per-drawing render fails, log + skip that drawing (don't abort the whole bound PDF); include a "[render failed]" placeholder in the drawing register.
- Cleanest debug path; per-drawing failures isolated; reuses existing infrastructure.

**Gray Area B — Sheet numbering: RECOMMENDATION = (a) auto-derived from kind + project counter, with future override hook.**
- Default: `AV-201` first schematic, `AV-202` second, ... `AV-301` first rack, `AV-302` second.
- Stored as `project_drawings.sheet_number` (new nullable column) — auto-set on draft creation, never re-derived.
- Per-drawing manual override deferred to Phase 20.5 / v1.3.x quick task IF engineers ask for it (most won't).
- Title block (already in place from Phase 17/18) consumes `sheet_number` instead of computed-on-render.
- Schema migration adds `project_drawings.sheet_number` (varchar 20 nullable).

**Gray Area C — ZIP bundle: RECOMMENDATION = (a) on-demand server-side build.**
- Generated at download time, not pre-built. ZIP contains: bound-PDF + per-drawing PDF + per-drawing SVG + per-drawing PNG + drawing register CSV.
- Built via `ZipArchive` (PHP standard); streamed to download response (`response()->streamDownload`).
- Acceptable performance: typical 5-drawing project bundles in <2s.
- No staleness risk (always fresh from canonical drawings + manufacturer pack).

**Gray Area D — Drawings queue isolation: RECOMMENDATION = (a) new `drawings` queue name + dedicated worker.**
- Add `drawings` queue to `config/queue.php` connections + a deploy-runbook entry adding a worker process (`php artisan queue:work --queue=drawings --max-jobs=10 --memory=512`).
- Concurrency=1 enforced via `WithoutOverlapping` middleware on `BuildBoundPdfJob`.
- Existing default queue keeps handling RAMS / O&M / Worksheet / Cable Schedule / Schematic jobs.
- Browsershot OOM risk on large bound PDFs (CRIT-03) is meaningfully mitigated when drawings can't pile up behind RAMS jobs.
- Documents in 18-deploy-runbook + ROADMAP "Operational debt" if engineer wants to defer the worker process change.

### Claude's Discretion (planner decides)

- Cover sheet content beyond the title block — recommended: project ref, client name, drawing count, generation date, drawn-by, revision summary table. Planner can decide layout density.
- Drawing register table columns — recommended: sheet number, title, kind, revision, status, date. Per-drawing thumbnail column is nice-to-have, not required.
- Bound PDF page size — recommended: A4 portrait for cover + register, A4 landscape per-drawing pages (matches schematic+rack render orientation). Mixed-orientation Browsershot is well-supported.
- "Regenerate recommended" UI badge details — colour and wording at planner discretion. Recommended: small amber pill near the bound PDF download button reading "Regen needed — drawing changed".
- ZIP filename convention — recommended: `{projectRef}-drawings-{date}.zip`. Planner can decide.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents (planner, executor) MUST read these before planning or implementing.**

### Research (this milestone)
- `.planning/research/SUMMARY.md` — top-level synthesis; Phase 20 sub-section
- `.planning/research/ARCHITECTURE.md` §6 export pipeline integration
- `.planning/research/PITFALLS.md` CRIT-03 (queue OOM), CRIT-04 (Chrome version drift), MOD-01 (DXF/DWG GPL trap — applies to license audit), MOD-10 (O&M reference timing), MOD-12 (notification timing in chained jobs)

### Phase 17 + 18 foundations (already shipped — Phase 20 builds on these)
- `app/Services/Drawings/DrawingExportRendererService.php` — already supports schematic + rack PDF/SVG/PNG; Phase 20 adds bound-PDF + ZIP methods
- `app/Services/PdfRenderService.php` — `fromBlade` + `fromBladeAsPng` extensions
- `app/Services/DocumentArtifactStorage.php` — TYPE_DRAWING constant
- `app/Services/OmManualDocxService.php` lines 154-214 (Phase 17 Plan 03 patch) — Drawings section pattern; Phase 20 extends loop to include rack drawings
- `app/Models/OmManual.php` line 59 — `project()` relation confirmed
- `app/Console/Commands/PdfSmokeTestCommand.php` — Phase 17 Plan 03 added `--drawings` flag (schematic only); Phase 20 extends to rack render
- `app/Models/ProjectDrawing.php` — KIND_* + STATUS_* constants
- `app/Mail/DrawingReadyMail.php` — single mailable, kind discriminator (Phase 20's bound-PDF complete email could reuse OR ship a new BoundPdfReadyMail — planner picks)
- `database/migrations/*` — pattern for adding `sheet_number` column

### Existing codebase precedent
- `app/Jobs/BuildOmManualJob.php` — `Build*Job` shape: `$tries=2`, `$timeout=300`, idempotency timestamps, `failed()` admin alert. New `BuildBoundPdfJob` mirrors this exactly.
- `app/Services/RamsBuilderService.php` regenerate-archives-prior pattern — sheet numbering should be set-once-on-create, NOT re-derived on regen
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot deployment runbook (chrome-headless-shell + AlmaLinux)
- `app/Core/Modules/Projects/ProjectDataService.php` — DATA-03 contract; bound PDF builder MUST consume `resolve()`, never raw extracted_data/reviewed_data

### Industry standards
- Uniform Drawing System Module 1 — Sheet Identification (NCS) — `AV-201` / `AV-301` numbering follows this convention
- AVIXA Standard Guide for AV Systems Design and Coordination — drawing register conventions

</canonical_refs>

<specifics>
## Specific Ideas

User confirmed during this milestone:
- v1.3 ships AVIXA-icon-style "passable basic" — Phase 20 is the deliverable-completion phase
- DXF deferred to v2.0 with floor plans (no engineer demand confirmed yet)
- O&M Manual handover is the primary delivery vehicle — drawings must embed cleanly
- Production hardening matters — Browsershot stability + font fallback already burned the team in 260427-qvr

</specifics>

<deferred>
## Deferred Ideas

### To later phases (within v1.3) — NONE
Phase 20 is the last v1.3 phase. After ship → `/gsd-complete-milestone`.

### To v2.0 / future milestones (per backlog 999.1)
- **DXF export** (DRAW-29) — engineer demand not confirmed; LibreDWG GPLv3 blocker; Teigha paid; defer indefinitely or build via Python `ezdxf` sidecar in v2.0 if demand emerges
- **Per-revision diff overlay PDF** — visual changelog between R0 and R1 — v2.0 enhancement
- **Multi-stage drawing approval workflow** (engineer → senior → client) — v2.0 enhancement
- **Per-drawing manual sheet number override** — small follow-up quick task if engineers ask for it post-v1.3 ship

### Operational debt (post-ship)
- Add `drawings` queue worker process to deploy runbook + monitor
- License audit pass at composer / npm install time (CI hook would be nice; planner can decide if this lands in v1.3 or v1.3.x)

</deferred>

---

*Phase: 20-drawing-export-pipeline-o-m-integration*
*Context gathered: 2026-05-02*
*All gray areas reviewed; user selected "All clear" — research + recommended defaults canonical input for the planner*

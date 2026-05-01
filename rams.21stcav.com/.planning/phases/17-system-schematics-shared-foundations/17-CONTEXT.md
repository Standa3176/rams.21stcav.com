# Phase 17: System Schematics + Shared Foundations - Context

**Gathered:** 2026-05-01
**Status:** Ready for planning
**Discussion outcome:** All clear — no additional discussion needed; research locks the decisions

<domain>
## Phase Boundary

Auto-generate per-room signal-flow SVG schematics from canonical project data via D2 CLI. This phase ALSO carries all shared `project_drawings` infrastructure (table, model, policy, storage type, job pattern, mailable, edit-adapter, `PdfRenderService::waitForJs` extension) so Phases 18 (Rack Elevations), 19 (Floor Plans), and 20 (Drawing Export + O&M Integration) become pure additions on top.

**Maps requirements:** DRAW-01, DRAW-02, DRAW-03, DRAW-04, DRAW-05, DRAW-06, DRAW-22, DRAW-24, DRAW-25, DRAW-26, DRAW-27, DRAW-30 (12 of 30 v1.3 requirements).

</domain>

<decisions>
## Implementation Decisions

All major decisions are **locked in `.planning/research/SUMMARY.md`** and the four detail files (STACK / FEATURES / ARCHITECTURE / PITFALLS). The user reviewed the gray areas and selected "All clear" — research is sufficient; no additional decisions need capturing here. The planner should treat the research files as canonical input.

### Locked from research (not up for revision in Phase 17 planning)

- **D2 CLI v0.7.1** (MPL-2.0, Go binary) for schematic generation. Server-side text → SVG, no headless browser at gen time. Install at `/usr/local/bin/d2` on AlmaLinux production.
- **Single `project_drawings` table** with `kind` discriminator (`schematic`/`rack`/`floor_plan`); stores both auto-generated SVG (regenerable from `source_data`) and optional user-edited `canvas_state` (Konva JSON, used by Phase 19) in the same row. Versioning via `superseded_by_id` self-FK (mirrors Phase 12 install programme regen pattern).
- **`DocumentArtifactStorage::TYPE_DRAWING`** — single new constant; sub-kind in filename convention (`drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}`). Not three constants.
- **Single `DrawingReadyMail`** with kind discriminator. Mirrors v1.1 `*ReadyMail` pattern; idempotency timestamps set BEFORE send.
- **Lock-on-edit + archive-prior-version** for regenerate semantics. Never silently overwrite user edits; UI confirm prompt when regenerate would clobber edits.
- **`Device::isSource()/isDestination()/isProcessor()` classification** for schematic signal direction. Never infer direction from cable-row order (CRIT-05).
- **In-house ~25-symbol AVIXA-conventions-aligned SVG pack** in `resources/svg/av-symbols/`. No OSS AVIXA-compliant library exists; total <100 KB.
- **`PdfRenderService::fromBlade(..., waitForJs: true)`** — 5-line additive option, default `false`. Mostly used by Phase 19 (Konva render), but the extension lands in Phase 17 so it's available when needed.
- **Edit-via-Chat (DRAW-30) rides existing `DocumentEdits` framework** — new `DrawingEditAdapter` extending the same pattern as `RamsEditAdapter`/`OmEditAdapter`/`WorksheetEditAdapter`. Operations are constrained to layout/positioning/grouping/formatting/styling within canonical project data; AI cannot invent equipment/cables/rooms.
- **Chrome version drift mitigation** — extend `pdf:smoke-test` (added in 260427-qvr) with a `--drawings` flag in Phase 20; pin chrome-headless-shell version in `.env.example`.
- **Schematics are read-only in Phase 17** — DRAW-05 (edit auto-generated schematic) deferred to Phase 19 per GAP-4. Phase 17 ships auto-generation + lock-on-edit prompt UX; the actual editor lands in Phase 19 alongside Konva. Rationale: avoids building a separate D2 DSL editor in 17 only to replace it with a richer Konva-based editor in 19. Lock-on-edit semantics are still wired in Phase 17 so Phase 19 plugs in cleanly.
- **Edit-via-Chat scope at Phase 17 baseline** — adapter scaffolding only. `DrawingEditAdapter` framework lands in Phase 17 but is exercised end-to-end starting at Phase 18 (rack elevations) and 19 (floor plans). Schematic chat support follows when DRAW-05 lands in Phase 19.

### Claude's Discretion (planner decides)

- **Schematic granularity** — per-room is the AV-deliverable convention and the recommended baseline. If a project-wide master schematic is needed later, it's a `kind = 'schematic'` row with `room_id = NULL`. Planner can stub the nullable `room_id` column without building the master-render path in Phase 17.
- **Symbol pack at v1** — start with the recommended top-25 (display, projector, speaker, mic, camera, switcher, DSP, amp, codec, control processor, touch panel, BYOD dongle, ClickShare, network switch, USB hub, source PC/laptop, HDMI port, USB port, network port, generic source, generic destination, blanking-panel, PDU, equipment-rack-meta, room-edge marker). Grow organically through real project use.
- **Title block fields** — minimum: project ref, client, drawn-by, date, revision, status. Add "Checked by" + "Approved by" when status workflow exists in Phase 17 if it fits naturally; otherwise defer to Phase 20 (where status state machine matures).
- **Regeneration trigger** — manual "Regenerate" button is sufficient for v1; auto-regen-on-data-change can land in v1.3.x if engineers ask for it.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents (planner, executor) MUST read these before planning or implementing.**

### Research (this milestone)
- `.planning/research/SUMMARY.md` — top-level synthesis; resolved conflicts across the four detail files
- `.planning/research/STACK.md` §1 Schematic Engine + §5 AV Symbol Pack
- `.planning/research/FEATURES.md` Phase 17 — System Schematics
- `.planning/research/ARCHITECTURE.md` §2 Data Model + §3 Service Layer + §4.3 PdfRenderService waitForJs extension + §8 Build Order + §10 Anti-patterns
- `.planning/research/PITFALLS.md` CRIT-01 (Browsershot/React canvas), CRIT-02 (drift vs canonical), CRIT-05 (reversed signal flow), MOD-12 (notification timing)

### Existing codebase precedent
- `app/Services/DocumentArtifactStorage.php` — H-07 type registry pattern (mirror with `TYPE_DRAWING`)
- `app/Services/PdfRenderService.php` — Browsershot wrapper to extend with `waitForJs` flag
- `app/Services/DocumentEdits/Adapters/RamsEditAdapter.php` — pattern for `DrawingEditAdapter`
- `app/Services/DocumentEdits/Adapters/OmEditAdapter.php` — same pattern, second example
- `app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php` — operation-schema-constrained chat prompt
- `app/Models/RamsDocument.php` — status state machine + `superseded_by_id` precedent
- `app/Models/InstallProgramme.php` — regenerate-archives-prior pattern (Phase 12 precedent)
- `app/Jobs/BuildOmManualJob.php` — `Build*Job` shape: `$tries=2`, `$timeout=300`, idempotency timestamps, `failed()` admin alert
- `app/Mail/RamsReadyMail.php` (and siblings) — `*ReadyMail` pattern with `DocumentArtifactStorage::readPath()` attachment
- `app/Core/Modules/Projects/ProjectDataService.php` — DATA-03 contract; Phase 17 generators MUST consume `resolve()`, never raw extracted_data/reviewed_data
- `app/Services/InstallTaskGeneratorService.php` (post 260430-um1) — recent precedent for area-tag distribution from canonical data
- `vite.config.js` + frappe-gantt entry — separate Vite entry pattern for Phase 19's Konva loading

### Industry standards
- AVIXA Audio Video and Control Architectural Drawing Symbols Standard (D401.01) — symbol convention
- AVIXA Standard Guide for AV Systems Design and Coordination — signal-flow direction conventions

### Operational precedent
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot + chrome-headless-shell + AlmaLinux runbook (D2 binary install in Phase 17 should follow same deployment pattern)

</canonical_refs>

<specifics>
## Specific Ideas

User confirmed during milestone setup:
- v1.3 four-phase scope (17–20) is correct; no scaling back
- Schematics are for BOTH internal engineers (printed PDF, on-tablet) AND clients (O&M handover)
- DXF is nice-to-have only; defer to Phase 20 stretch goal
- Floor plans use a real drawing tool (Phase 19 Konva), not just upload — out of scope for Phase 17
- AI chat to edit drawings is in scope (DRAW-30); Phase 17 lays the adapter foundation

</specifics>

<deferred>
## Deferred Ideas

### To later phases (within v1.3)
- **DRAW-05 schematic edit-override** — defers to Phase 19 (when Konva is loaded). Phase 17 stops at "auto-generate + lock-on-edit prompt UX".
- **DRAW-30 chat for schematics** — adapter scaffolding lands in Phase 17; functional schematic chat lands in Phase 19 alongside the schematic editor.
- **Status workflow UI** (DRAW-25) — minimum draft/approved enum lands in Phase 17; richer multi-stage approval defers to Phase 20.
- **Title block extra fields** ("Checked by" / "Approved by") — defer to Phase 20 unless they fit naturally in Phase 17 once the status state machine exists.

### To future milestones (v1.3.x or v1.4+)
- Architect's PDF as background reference for floor plan tracing
- Coverage cones / heat maps (mic pickup, camera FoV)
- Conflict detection (equipment overlap, cable-route clashes)
- Reflected ceiling plan (RCP) as a separate drawing kind
- Multi-stage drawing approval workflow (engineer → senior → client)
- Per-revision diff overlay PDF
- Per-rack PDU outlet mapping
- Auto-regenerate on equipment/cable change (cron or event listener)
- Project-wide master schematic (single-page system overview)

</deferred>

---

*Phase: 17-system-schematics-shared-foundations*
*Context gathered: 2026-05-01*
*All gray areas reviewed; user selected "All clear" — research files are canonical input for the planner*

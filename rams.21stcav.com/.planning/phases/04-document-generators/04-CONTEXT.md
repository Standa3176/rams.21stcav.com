---
phase: 04-document-generators
gathered: 2026-04-11
status: Ready for planning
---

# Phase 4: Document Generators — Context

**Gathered:** 2026-04-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Build three document generators that consume exclusively from `ProjectDataService` canonical data:

1. **Worksheet** — Engineer's job card (room-by-room install guide). New from scratch: model, migration, service, job, controller, views.
2. **O&M Manual** — Refactor existing pipeline. Replace Pass 1 (PDF extraction) with ProjectDataService feed. Keep Pass 2 AI content generation. `BuildOmManualJob` pattern retained.
3. **Cable Schedule** — Refactor existing AI-from-PDF approach. Auto-generate cable items from equipment relationships + survey cable_route_desc. Engineer edits items in existing UI before XLSX export.

All three generators are triggered from the project show page linked records card. Queue-based async processing with status polling (button → spinner → Download). Consistent with existing RAMS and O&M pattern.

</domain>

<decisions>
## Implementation Decisions

### Worksheet Generator

- **D-01: Purpose** — Engineer's job card. Room-by-room: what to install, where, cable routes, power/network needs, key site constraints. Engineer takes this on site.
- **D-02: AI usage** — AI generates install step narrative per room. Reads equipment list + survey room data (dimensions, ceiling type, access notes, mounting constraints) and produces readable steps: e.g. "Mount 86″ display on VESA bracket at 1.5m centre height, cable to rack via ceiling void." AI structures what's in ProjectDataService — never invents scope or equipment.
- **D-03: Sections per room block** — Four sections in each room:
  1. Equipment list (from ProjectDataService rooms[].equipment)
  2. AI-generated install steps (narrative from equipment + survey data)
  3. Cable routes (from survey cable_route_desc + equipment category relationships)
  4. Power & network requirements (from survey has_power, power_outlet_count, requires_additional_power, network_port_count, existing_cabling)
- **D-04: Unsurveyed rooms** — Include with partial data. Survey fields show "Not surveyed" where null. Room section still renders with equipment list and AI steps.
- **D-05: Output format** — DOCX, matching existing RAMS/O&M pattern. Use PHPWord (already installed). Queue-based async job: `BuildWorksheetJob`.
- **D-06: Status states** — Follow OmManual pattern: `pending` → `generating` → `draft` → `final` (or `failed`).

### O&M Manual Refactoring

- **D-07: Data source strategy** — Replace Pass 1 (PDF extraction / `OmManualService::extractFromQuote()`) with a direct ProjectDataService feed. Pass 2 AI content generation is retained unchanged. The `BuildOmManualJob` → `OmManualDocxService::build()` chain is preserved.
- **D-08: New Pass 1 replacement** — `OmManualGeneratorService` (or a new `OmManualProjectDataService`) reads equipment list and room data from `ProjectDataService::resolve($project)` and produces the same reviewed data shape that the existing Pass 2 AI expects. No user review step needed — ProjectDataService data is already reviewed.
- **D-09: AI scope (Pass 2 — unchanged)** — AI generates from the equipment list:
  1. Operating procedures per system type (display, audio, VC, control)
  2. Routine maintenance schedule with task + interval per equipment category
  3. Fault-finding guide (common faults + resolution per system)
  4. Installed asset register (equipment, model, location — serial placeholder)
- **D-10: Existing pipeline compatibility** — `OmManualDocxService::build()` and `BuildOmManualJob` are not restructured. The entry point changes (no more PDF upload for project-linked O&Ms) but the downstream is preserved.
- **D-11: Trigger point** — `OmManualController::generateFromProject()` already exists. Update it to call ProjectDataService instead of triggering PDF extraction. The route `POST /om-manuals/generate-from-project/{project}` is already registered.

### Cable Schedule Refactoring

- **D-12: Data source** — Auto-generate `CableScheduleItem` records from ProjectDataService equipment relationships + survey `cable_route_desc`. Replaces AI-from-PDF approach (`CableScheduleService::generateFromQuote()`).
- **D-13: Item content** — Each generated item contains:
  - `from`: equipment location (room name + equipment name/position)
  - `to`: destination endpoint (rack unit, display position, or endpoint device)
  - `cable_type`: inferred from equipment category (HDMI → HDMI 2.0, speaker → 2-core speaker cable, network device → Cat6, etc.)
  - `length`, `route_notes`: left blank — engineer fills these in the existing UI
- **D-14: Generation service** — New `CableScheduleGeneratorService` that reads from ProjectDataService and creates `CableScheduleItem` records. `CableScheduleXlsxService::build()` is unchanged — it reads from existing `CableScheduleItem` records.
- **D-15: Engineer edit flow** — After auto-generation, engineer edits items in the existing CableSchedule show/edit UI before exporting XLSX. This is already built — Phase 4 only changes how items are initially populated.

### Generation Trigger & Project Wiring

- **D-16: Trigger location** — All three generators triggered from the project show page linked records card. The `$linkedRecords` array (Phase 1) already has a row per document type with an action button. Phase 4 wires those buttons to dispatch the relevant job.
- **D-17: UX pattern** — Status polling consistent with existing RAMS/O&M pattern:
  - Button shows "Generate" when no document exists or status is `failed`
  - Button shows "Generating…" (disabled spinner) when job is running
  - Button shows "Download" when status is `draft` or `final`
  - Page polls or refreshes — no page reload required (existing Alpine.js pattern)
- **D-18: Project show page changes** — Minimal. The existing `$linkedRecords` card already handles all document types. Phase 4 adds the generate routes + job dispatch. The card rendering logic is already in place.

### Claude's Discretion

- Exact cable type inference rules per equipment category (HDMI vs HDBaseT vs Cat6 for displays, for example)
- Whether `BuildWorksheetJob` calls AI per room in parallel or sequentially
- Worksheet model schema (column names, nullable strategy)
- Whether to create a new `OmManualProjectDataService` or modify the existing `OmManualGeneratorService`
- PHPWord template vs programmatic build for worksheets (match existing DocxBuilderService pattern)

</decisions>

<specifics>
## Specific Notes

- The AI-generated install steps in worksheets are the same constraint as RAMS method statements: AI structures what's in ProjectDataService, never invents it. The prompt must receive the actual equipment list + survey room data as grounding context.
- Cable type inference: use equipment category as the key signal. The existing `equipment_list` has `category` per item (display, audio, VC, control, networking, infrastructure). Map categories to cable types deterministically — this is not an AI task.
- The `OmManualController::generateFromProject()` route already exists and is already wired. The Phase 4 change is inside that method's implementation, not the route structure.
- The project show page `$linkedRecords` for Worksheet type currently has no generate logic — it likely shows "Create" or is empty. Phase 4 wires the actual generate button.

</specifics>

<canonical_refs>
## Canonical References

Downstream agents MUST read these before planning or implementing.

### Core Data Source
- `app/Core/Modules/Projects/ProjectDataService.php` — canonical data source; `resolve($project)` returns rooms[], equipment[], activities[], risks[], survey_meta, meta
- `.planning/phases/01-project-layer-data-foundation/01-CONTEXT.md` — merge priority chain, confidence tracking, canonical data shape decisions
- `.planning/phases/03-survey-data-integration/03-CONTEXT.md` — survey room normalization shape, global fields, one-survey enforcement

### O&M Manual (existing pipeline — refactor target)
- `app/Services/OmManualService.php` — Pass 1 to be replaced (PDF extraction)
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — Pass 2 AI generation (retained)
- `app/Services/OmManualDocxService.php` — DOCX builder (retained, no changes)
- `app/Jobs/BuildOmManualJob.php` — existing job (pattern to follow for Worksheet job)
- `app/Models/OmManual.php` — status constants, fillable
- `app/Http/Controllers/OmManualController.php` — `generateFromProject()` is the Phase 4 entry point

### Cable Schedule (existing infrastructure — refactor data source)
- `app/Services/CableScheduleService.php` — AI-from-PDF generator (replaced by new service)
- `app/Services/CableScheduleXlsxService.php` — XLSX builder (retained, no changes)
- `app/Models/CableSchedule.php` — model and status
- `app/Models/CableScheduleItem.php` — per-cable items (Phase 4 auto-populates these)
- `app/Http/Controllers/CableScheduleController.php` — existing controller

### Worksheet (new — no existing files)
- `app/Jobs/BuildRamsDocumentJob.php` — pattern reference for new BuildWorksheetJob
- `app/Services/DocxBuilderService.php` — existing DOCX pattern reference

### Project Integration
- `resources/views/projects/show.blade.php` — linked records card (action buttons to wire)
- `app/Http/Controllers/ProjectController.php` — `show()` method builds `$linkedRecords`
- `routes/web.php` — existing om-manuals and cable-schedules routes (add worksheet routes)

### Requirements
- `.planning/REQUIREMENTS.md` — WORK-01..04, OM-01..04, CABLE-01..04

</canonical_refs>

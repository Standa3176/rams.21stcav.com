# Phase 1: Project Layer & Data Foundation - Context

**Gathered:** 2026-04-09
**Status:** Ready for planning

<domain>
## Phase Boundary

Introduce the `projects` table as the top-level entity, link all systems via `project_id`, build the ProjectDataService as the canonical data merge layer, and create a project dashboard with lifecycle state management. This phase delivers the foundation that every subsequent generator phase depends on.

</domain>

<decisions>
## Implementation Decisions

### Project-Package Relationship
- **D-01:** One project has one active package (ProjectPackage). New quote revisions replace the old package — versioning is within the package record, not multiple packages per project.
- **D-02:** Importing a quote auto-creates a project if none exists for that client+site combination. Quote data (client, site, name) auto-populates project fields — user can edit before saving.
- **D-03:** Migration auto-creates a project for each existing ProjectPackage record and links them (project_id backfilled).

### Dashboard Layout
- **D-04:** Project show page is lifecycle-first: big lifecycle progress bar at top (focal point per UI-SPEC), then document status cards below.
- **D-05:** Linked documents display as status cards — one card per document type (RAMS, Survey, Worksheet, O&M, Cable Schedule) showing latest status, date, and action button.
- **D-06:** Empty document cards show action prompt: "No RAMS yet" with a "Generate RAMS" button. Cards are always visible, never hidden.
- **D-07:** No "Generate All" button — each document type has its own individual generate/action button.
- **D-08:** Project metadata in sidebar: client name, site address, quote reference + version, created/updated dates. No data source badge in sidebar.
- **D-09:** Navigation: breadcrumb "Projects > Project Name" — no prev/next buttons.

### Projects Index
- **D-10:** Add client name filter alongside existing status filter tabs and search.
- **D-11:** Search covers project name, client, site address, and quote reference.

### Project Creation
- **D-12:** All four fields required at creation: name, client, site address, quote reference.
- **D-13:** Quote versioning: simple text field with suffix convention (ABC123-01). User manually bumps version. No separate version number field.
- **D-14:** Warn but allow when creating a project with same client+site as existing one — show "Similar project exists" warning.
- **D-15:** Projects are shared across all users — any authenticated user can see and work on any project.
- **D-16:** All project fields editable at any lifecycle state — no locking after engineering.
- **D-17:** Projects can only be soft-deleted (archived). No hard delete capability.

### Lifecycle Transitions
- **D-18:** Semi-automatic transitions. Three auto-advance events:
  - Quote imported → survey_pending
  - Survey submitted → engineering
  - All documents generated → handover
- **D-19:** Any authenticated user can trigger lifecycle transitions (advance or revert).
- **D-20:** State can be moved in any direction (backwards allowed for corrections).
- **D-21:** Archiving = soft hide from default project list. Archived projects accessible via filter. All data preserved.
- **D-22:** All state transitions (auto and manual) logged to ProjectActivityLog with user, timestamp, old state, new state.

### Data Merge Display
- **D-23:** Data source shown via tooltip on hover — small tooltip showing "Source: PDF Import" or "Source: QuoteWerks SQL" or "Source: Manual Review" per field.
- **D-24:** Confidence scores: only flag low-confidence fields (don't clutter high-confidence ones). Threshold is Claude's discretion.
- **D-25:** Merged data visible in two places: review screen (for editing) and a "Project Data" tab on the project page (read-only view of canonical merged dataset).

### Data Structure
- **D-26:** Rooms data merged from both sources: quote defines rooms via group structure, survey enriches with physical details. Merge by room name with auto-matching (fuzzy) and manual fallback for unmatched rooms.
- **D-27:** Equipment structure, DTO vs array, caching strategy, and empty data handling are all Claude's discretion.

### Claude's Discretion
- Project creation form: full page or modal (Claude decides)
- Low confidence threshold value (Claude decides)
- ProjectDataService implementation: typed DTO class vs associative array
- Equipment structure in canonical dataset (flat vs nested)
- Caching strategy for resolved datasets
- Graceful degradation behavior when sources are missing

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Existing Models & Services
- `app/Models/Project.php` — Project model with lifecycle state machine
- `app/Models/ProjectPackage.php` — Current quote container (extracted_data, equipment_list, cable_list)
- `app/Services/ProjectContextResolver.php` — Existing prototype of ProjectDataService (resolves project/equipment/activities/rooms)
- `app/Models/ProjectActivityLog.php` — Activity log model for audit trail

### Architecture Reference
- `.planning/codebase/ARCHITECTURE.md` — Current service layer patterns and data flow
- `.planning/codebase/STRUCTURE.md` — Directory and file organization conventions
- `.planning/codebase/CONVENTIONS.md` — Coding patterns and naming conventions

### UI Design Contract
- `.planning/phases/01-project-layer-data-foundation/01-UI-SPEC.md` — Typography, spacing, color, copywriting, and component contracts for this phase

### Research
- `.planning/research/ARCHITECTURE.md` — ProjectDataService design patterns and merge priority chain
- `.planning/research/PITFALLS.md` — Migration safety, god class prevention, existing pipeline preservation
- `.planning/research/SUMMARY.md` — Synthesized findings across all dimensions

### Existing Views
- `resources/views/projects/index.blade.php` — Project listing with status filter tabs and search
- `resources/views/projects/show.blade.php` — Project detail page (existing, needs enhancement)
- `resources/views/projects/create.blade.php` — Project creation form (if exists)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `ProjectContextResolver` — Already resolves project, equipment, activities, rooms from latest package. Extend or replace with ProjectDataService.
- `components/dashboard/status-badge.blade.php` — Status badge component with full color map.
- `Project` model — Already has 8-state lifecycle state machine with status constants.
- `ProjectActivityLog` — Append-only activity logging already in place.

### Established Patterns
- Service-based architecture with thin controllers — all business logic in service classes.
- `RamsDataBuilderService::resolveProjectFields()` demonstrates the merge priority pattern (reviewed > extracted).
- Queue-based async generation via Jobs with status tracking on model (status constants, failed hook).
- Policy-based authorization for RAMS and O&M — extend pattern to projects.

### Integration Points
- `routes/web.php` — Add project CRUD routes alongside existing project routes.
- `resources/views/layouts/navigation.blade.php` — Projects link already in nav.
- `QuoteImportService::import()` — Hook into this to auto-create projects on import.
- `SurveyService` — Hook survey submission to trigger auto-advance to engineering state.

</code_context>

<specifics>
## Specific Ideas

- Quote import flow: when importing a quote, auto-create project with pre-filled fields from quote data. User reviews and confirms before saving.
- Project dashboard lifecycle bar should be the visual focal point (per UI-SPEC).
- All document cards always visible with action prompts when empty — engineers need to see what's available even if not yet generated.
- Client filter on projects index is a practical need for finding projects by company.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 01-project-layer-data-foundation*
*Context gathered: 2026-04-09*

# Phase 1: Project Layer & Data Foundation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-09
**Phase:** 01-project-layer-data-foundation
**Areas discussed:** Project-Package model, Dashboard layout, Lifecycle transitions, Data merge display, Project creation flow, Data structure details

---

## Project-Package Model

| Option | Description | Selected |
|--------|-------------|----------|
| One project, many packages | Multiple quote revisions as separate packages | |
| One project, one package | Each project has one active package, new revision replaces old | x |
| Evolve ProjectPackage | Don't create separate projects table | |

**User's choice:** One project, one package
**Notes:** New quote revisions replace within the same package record.

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-create project | Quote import auto-creates project | x |
| Manual link | User manually creates/selects project | |
| Always create | Every import creates new project | |

**User's choice:** Auto-create project

| Option | Description | Selected |
|--------|-------------|----------|
| Migration creates projects | Auto-create project per existing package | x |
| Orphan until linked | Existing packages stay unlinked | |
| Backfill + nullable | Nullable project_id, background task links | |

**User's choice:** Migration creates projects

---

## Dashboard Layout

| Option | Description | Selected |
|--------|-------------|----------|
| Lifecycle-first | Big lifecycle bar at top, docs below | x |
| Documents-first | Grid of document cards with status | |
| Activity-first | Timeline of activity, docs in sidebar | |

**User's choice:** Lifecycle-first

| Option | Description | Selected |
|--------|-------------|----------|
| Status cards | One card per doc type with status + action | x |
| Grouped table | Table rows by type | |
| Tabs by type | Tab per document type | |

**User's choice:** Status cards

| Option | Description | Selected |
|--------|-------------|----------|
| Action prompt | Card shows 'No X yet' with generate button | x |
| Greyed placeholder | Dimmed card with 'Not started' | |
| Hide until ready | Card only appears when available | |

**User's choice:** Action prompt

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, generate all | One button for all generators | |
| No, individual only | Each type has own button | x |
| Both options | Individual + Generate All | |

**User's choice:** No, individual only

Sidebar metadata: Client + site address, Quote reference + version, Created/updated dates (all selected). No data source badge.

Navigation: Back to list only (breadcrumb).

Projects index: Add client filter. Create form: Claude's discretion.

---

## Lifecycle Transitions

| Option | Description | Selected |
|--------|-------------|----------|
| Manual only | User clicks Advance | |
| Semi-automatic | Some auto-trigger, manual available | x |
| Fully automatic | State advances on events | |

**User's choice:** Semi-automatic

Permissions: Any user on project can trigger transitions.

Auto-advance events selected:
- Quote imported -> survey_pending
- Survey submitted -> engineering
- All docs generated -> handover

State can be moved backwards (any direction). Archive = soft hide. All transitions logged to activity log.

---

## Data Merge Display

| Option | Description | Selected |
|--------|-------------|----------|
| Inline badges | Small source badge next to each field | |
| Tooltip on hover | Hover to see source + confidence | x |
| Separate panel | Side panel with source breakdown | |

**User's choice:** Tooltip on hover

Confidence: Only flag low-confidence fields. Threshold: Claude's discretion.

Merged data visible in: review screen (editing) + Project Data tab (read-only). Both selected.

---

## Project Creation Flow

All four fields required (name, client, site, quote ref). Quote import auto-fills all fields from quote data.

Quote versioning: suffix in reference field (ABC123-01). Manual bump.

Duplicate projects: warn but allow.

Projects shared across all users. Full edit anytime. Soft delete (archive) only.

---

## Data Structure Details

Rooms: merged from quote groups + survey rooms. Auto name-matching with manual fallback.

Equipment structure, DTO vs array, caching, empty data handling: all Claude's discretion.

---

## Claude's Discretion

- Create form: full page or modal
- Low confidence threshold
- ProjectDataService: DTO class vs associative array
- Equipment structure (flat vs nested)
- Caching strategy
- Graceful degradation for missing sources

## Deferred Ideas

None — discussion stayed within phase scope.

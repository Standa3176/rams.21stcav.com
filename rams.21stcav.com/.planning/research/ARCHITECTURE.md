# Architecture Patterns

**Domain:** AV Operations Platform — unified data layer + multi-format document generation
**Researched:** 2026-04-09
**Confidence:** HIGH (derived from direct codebase inspection, not speculation)

---

## Context: What Exists and What Is Being Added

The existing system has a well-structured service layer built around a **single-document pipeline**: one PDF upload produces one RAMS document. Data flows linearly — extract, review, generate, render. Each output type (RAMS, O&M, Cable Schedule) currently has its own isolated pipeline that resolves project data independently.

The milestone being designed adds three things that change this topology:

1. **A second import path** (QuoteWerks SQL alongside the existing PDF import)
2. **A unified data merge point** (ProjectDataService) that all generators consume
3. **Two new generators** (Worksheets, plus hardened O&M and Cable Schedule) that must share the same data source as RAMS

This requires one architectural addition — the `ProjectDataService` layer — and a refactor of existing generators to consume from it rather than directly from their own data sources.

---

## Recommended Architecture

### Layer Map (complete picture)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  HTTP LAYER                                                             │
│  Controllers (thin) — delegate immediately to services                  │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼─────────────────────────────────────┐
│  IMPORT LAYER (two parallel paths, identical output contract)           │
│                                                                         │
│  QuoteImportService (PDF)          QuoteWerksImportService (SQL)        │
│    └─ existing pipeline              └─ NEW: reads remote MS SQL        │
│    └─ produces: ProjectPackage       └─ produces: ProjectPackage        │
│         └─ extracted_data                 └─ extracted_data (same keys) │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                     ProjectPackage.extracted_data
                     (canonical quote data store)
                                    │
┌───────────────────────────────────▼─────────────────────────────────────┐
│  REVIEW LAYER (existing, unchanged)                                     │
│                                                                         │
│  Human review UI                                                        │
│    └─ extracted_data → reviewed (edits) → ProjectPackage.reviewed_data  │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼─────────────────────────────────────┐
│  SURVEY LAYER (existing infrastructure, enhanced data capture)          │
│                                                                         │
│  SiteSurvey + SiteSurveyRoom models                                    │
│    └─ per-room: displays, audio, cable routes, constraints, photos      │
│    └─ global: site risks, H&S notes, access restrictions                │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼─────────────────────────────────────┐
│  UNIFIED DATA LAYER  ◄── THE ARCHITECTURAL ADDITION                     │
│                                                                         │
│  ProjectDataService                                                     │
│    └─ Input:  Project model + latestPackage + latestSurvey              │
│    └─ Merge:  reviewed_data → survey rooms → site risks                 │
│    └─ Output: canonical ProjectDataset (typed array, see below)         │
│    └─ Rule:   READ ONLY — never persists anything                       │
│    └─ Extends: existing ProjectContextResolver (promote to Core)        │
└─┬─────────────────────┬─────────────────────┬───────────────────────────┘
  │                     │                     │
  ▼                     ▼                     ▼
┌────────────┐  ┌──────────────┐  ┌──────────────────────┐
│  RAMS      │  │  Worksheets  │  │  O&M + Cable Sched.  │
│  Generator │  │  Generator   │  │  Generators          │
│  (refine)  │  │  (NEW)       │  │  (NEW / harden)      │
└────────────┘  └──────────────┘  └──────────────────────┘
      │                │                     │
      ▼                ▼                     ▼
   DOCX            DOCX                DOCX / XLSX
```

---

## Component Boundaries

### 1. Import Layer — Two Paths, One Contract

| Component | Responsibility | Location |
|-----------|---------------|----------|
| `QuoteImportService` | Existing PDF → ProjectPackage pipeline | `app/Core/Modules/QuoteImport/` |
| `QuoteWerksImportService` | NEW: SQL → ProjectPackage pipeline | `app/Core/Modules/QuoteImport/` |
| `ProjectPackage` model | Quote data store (extracted + reviewed) | `app/Models/ProjectPackage.php` |

**Design rule:** `QuoteWerksImportService::import()` must produce a `ProjectPackage` with `extracted_data` structured identically to what `QuoteImportService` produces. The downstream review UI and all generators must be completely unaware of which import path was used. This is enforced by defining a single `ExtractedDataContract` — a typed array shape documented in a class constant — that both services fill.

The SQL service reads from QuoteWerks database tables (header + line items + room/group structure), maps them to the same keys the PDF parser produces (`equipment`, `room_overviews`, `activities`, `cable_list`, etc.), and then the standard review workflow takes over. No special-casing downstream.

### 2. Unified Data Layer — ProjectDataService

**Location:** `app/Core/Modules/Projects/ProjectDataService.php`

This is the central architectural addition. It replaces the ad-hoc pattern where each generator independently digs through `reviewed_data` or `extracted_data` with its own logic.

**Existing foundation to build on:** `ProjectContextResolver` (in `app/Services/`) already does this job for the subset of data needed by surveys — it resolves `project`, `equipment`, `activities`, and `rooms` from the latest package. `ProjectDataService` extends this concept to include survey data and replaces `ProjectContextResolver` as the single resolve point.

**What it merges:**

```
Source 1: Project model columns
  → project.name, ref, client_name, site_address
  → Authority: always the Project model (not quote snapshot)

Source 2: ProjectPackage.reviewed_data (preferred) or extracted_data
  → equipment list (hardware items only for RAMS/Worksheets/O&M)
  → room_overviews (area → overview text + summary)
  → activities (installation task keys)
  → scope_of_works, site_logistics, programme/personnel
  → cable_list (raw cable entries for Cable Schedule seeding)
  → data_source tag: 'pdf' | 'quotewerks_sql'

Source 3: SiteSurvey + SiteSurveyRoom (latest completed survey)
  → per-room: displays, audio systems, cable routes, power/network
  → per-room: mounting constraints, access limitations, photos
  → global: site_risks, hs_notes, site_constraints
```

**Canonical output shape** (`ProjectDataset`):

```php
[
    'project'    => [
        'id', 'name', 'ref', 'client_name', 'site_address',
        'lifecycle_status',
    ],
    'equipment'  => [
        // Per item: name, quantity, area, category, data_source, confidence
    ],
    'rooms'      => [
        // Per area: room, overview, summary, works_summary, solution_type_id
        // MERGED with survey room data when survey exists:
        // + displays, audio, cable_routes, power_network, constraints, photos
    ],
    'activities' => [
        // Per activity: key, label
    ],
    'risks'      => [
        // Per hazard: hazard, risk_level, control_measures
        // Source: reviewed_data hazards + survey site_risks merged
    ],
    'survey'     => [
        // survey_id, status, submitted_at
        // global: site_risks, hs_notes, site_constraints
    ],
    'programme'  => [
        // project_manager_name, lead_engineer_name, planned_start_date, etc.
    ],
    'cables'     => [
        // Per cable: cable_type, from_location, to_location, cores, notes
    ],
    'meta'       => [
        'data_source'     => 'pdf' | 'quotewerks_sql' | 'manual',
        'has_survey'      => bool,
        'survey_complete' => bool,
        'confidence'      => float,  // lowest confidence across sources
    ],
]
```

**Key rules:**
- `ProjectDataService` is NEVER injected into queued Jobs directly. Jobs receive the `ProjectDataset` array (already resolved and passed as job payload), or resolve it at the start of `handle()` before any long-running work.
- The service NEVER persists. Mutation of project data goes through dedicated services (`ProjectService`, review controllers, `SurveyService`).
- All four generators call `ProjectDataService::resolve(Project $project): array` — no other data fetching.
- Merge priority for conflicting fields: survey data > reviewed_data > extracted_data > project model defaults.

### 3. Generator Interface Pattern

All document generators follow a single interface. This prevents drift as new generators are added and makes the data contract explicit.

**Interface:** `app/Contracts/DocumentGeneratorContract.php`

```php
interface DocumentGeneratorContract
{
    /**
     * Generate a document from the canonical project dataset.
     *
     * @param  array  $dataset   Output of ProjectDataService::resolve()
     * @param  Model  $record    The persisted document record (RamsDocument, Worksheet, OmManual, CableSchedule)
     * @return string            Absolute path to the generated file
     */
    public function generate(array $dataset, Model $record): string;
}
```

**Existing generators to update:**

| Generator | Current pattern | Change needed |
|-----------|----------------|---------------|
| `RamsBuilderService` | Takes `reviewedData + formData + record` | Add `generateFromDataset(array $dataset, RamsDocument $record)` entry point; keep `buildFromReview()` for backward compat |
| `OmManualGeneratorService` | Extracts from PDF internally | Pass 2 (`generateContent()`) switches to accept `$dataset` from `ProjectDataService` |
| `CableScheduleXlsxService` | Takes `CableSchedule` model, reads `$schedule->items` | Add dataset seeding step: `ProjectDataService` pre-populates `cable_schedule_items` from `cables` array before generation |

**New generators:**

| Generator | Location | Output |
|-----------|----------|--------|
| `WorksheetGeneratorService` | `app/Core/Modules/Worksheet/` | DOCX via `DocxBuilderService` or dedicated renderer |
| (Refined) `OmManualGeneratorService` | `app/Core/Modules/OMManual/` | DOCX via existing `OmManualDocxService` |

All new generators are queued via new Job classes following the same pattern as `BuildRamsDocumentJob`.

### 4. Queue Job Pattern (unchanged structure, new instances)

Each new document type gets its own Job following the established pattern:

```
BuildWorksheetJob     → WorksheetGeneratorService::generate($dataset, $record)
BuildOmManualJobV2    → OmManualGeneratorService::generateFromDataset($dataset, $record)
RebuildCableScheduleJob → seeds items from dataset, then CableScheduleXlsxService::build($record)
```

Jobs:
- Resolve `ProjectDataService` at the start of `handle()`, before dispatch queuing overhead
- Set model `status = 'generating'` before work, `status = 'completed'` or `status = 'failed'` after
- Have a `failed()` hook that sets `status = 'failed'` and logs `error_message`
- Use 2 retries maximum, consistent with existing jobs

---

## Data Flow: Import to Generation

```
IMPORT (either path)
  PDF upload → QuoteImportService::import()         }
  SQL pull   → QuoteWerksImportService::import()    }  → ProjectPackage (extracted_data)
                                                              │
                                                    REVIEW
                                                    Human reviews extracted_data
                                                    Edits + approves
                                                              │
                                                    ProjectPackage (reviewed_data set)
                                                              │
SURVEY (runs in parallel with review, not blocking)
  SiteSurveyController::createFromProject()
  Field engineer fills rooms + global data
  Submitted → SiteSurvey.status = 'completed'
                                                              │
                                                    RESOLVE (on demand)
                                                    ProjectDataService::resolve($project)
                                                    Merges all three sources → ProjectDataset
                                                              │
                                         ┌────────────────────┼────────────────────┐
                                         ▼                    ▼                    ▼
                              BuildRamsDocumentJob  BuildWorksheetJob   BuildOmManualJobV2
                              (dispatch on approve) (dispatch on demand) (dispatch on demand)
                                         │                    │                    │
                                         ▼                    ▼                    ▼
                                     DOCX file           DOCX file           DOCX / XLSX file
                                     stored in           stored in           stored in
                                     storage/app/rams/   storage/app/        storage/app/
                                                         worksheets/         om-manuals/ or
                                                                             cable-schedules/
```

**Key data flow rules:**

1. No generator reads from `ProjectPackage` or `SiteSurvey` directly. They only consume the resolved `ProjectDataset` array.
2. `ProjectDataService::resolve()` is always called fresh at generation time — no stale cached dataset stored in the database.
3. Survey data is optional. Generators receive a `meta.has_survey = false` flag and degrade gracefully (omit survey-specific sections, leave cable routes blank, etc.).
4. The `cables` array in the dataset feeds `CableScheduleItem` seeding — the existing `CableScheduleXlsxService` is not modified; it still reads from the model's `items` relationship. The seeding step (create or refresh `CableScheduleItem` rows from `$dataset['cables']`) happens in the job before calling the XLSX service.

---

## How to Evolve Existing Services Without Breaking RAMS

The RAMS pipeline must not be touched until `ProjectDataService` is stable and tested. The evolution path is additive, not replacement:

### Phase order rationale

**Phase A — Build the foundation first (ProjectDataService + QuoteWerks SQL import)**

These two are prerequisites for everything else. `ProjectDataService` with no generators consuming it is safe — it's purely read-only. Deploy it, verify it resolves correctly for existing projects, then wire generators to it one at a time.

**Phase B — Wire RAMS to ProjectDataService (additive entry point, not replacement)**

Add `RamsBuilderService::generateFromDataset(array $dataset, RamsDocument $record)` as a new entry point. The existing `buildFromReview()` stays intact and continues to be called by `BuildRamsDocumentJob`. The new entry point is only activated by a feature flag or separate action. RAMS pipeline cannot break because the old path is untouched.

**Phase C — New generators (Worksheets, O&M hardening, Cable Schedule seeding)**

These are net-new. No risk to existing pipeline. They consume `ProjectDataService` from day one.

**Phase D — Retire direct data access in old generators**

Once new generators are stable and `ProjectDataService` is proven, old entry points (`buildFromReview`, direct `OmManualGeneratorService` PDF extraction) can be deprecated. This is optional — they can coexist indefinitely.

### Concrete preservation rules

- `BuildRamsDocumentJob` calls `RamsBuilderService::buildFromReview()` — this method signature does not change.
- `ProjectPackage.reviewed_data` remains the authoritative store for quote-sourced data. `ProjectDataService` reads from it; it never writes to it.
- `RamsDocument.reviewed_data` (separate from package) remains the RAMS-specific review store. The RAMS pipeline reads from this, not from `ProjectDataService`. Only the new generators use `ProjectDataService` as their source.
- `ProjectContextResolver` is not deleted. It can be deprecated gradually as `ProjectDataService` is adopted, or kept as a thin wrapper that delegates to `ProjectDataService`.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Each Generator Resolving Its Own Data

**What goes wrong:** `WorksheetGeneratorService` does its own `$project->latestPackage->reviewed_data` query. `OmManualGeneratorService` does the same. Survey data is merged differently in each. A field name change in `ProjectPackage` breaks three places.

**Why it happens:** It's the path of least resistance — copy the pattern from `RamsBuilderService::reviewedToParsed()` and adapt it.

**Instead:** Every generator calls `ProjectDataService::resolve()`. The merge logic lives in exactly one place.

### Anti-Pattern 2: Storing the Merged Dataset in the Database

**What goes wrong:** A `projects.canonical_data` JSON column is saved after merge. It goes stale when reviewed_data or survey is updated. Generators consume stale data.

**Why it happens:** Seems like a performance optimisation.

**Instead:** Resolve fresh at generation time. The query cost is trivial (3 eager-loaded relationships). If performance becomes a concern, cache with a cache key that invalidates on `ProjectPackage::updated` and `SiteSurvey::updated`.

### Anti-Pattern 3: Fat Jobs

**What goes wrong:** `BuildWorksheetJob::handle()` contains data resolution, normalisation, business logic, and rendering all inline. Cannot be unit-tested, impossible to reason about failure.

**Instead:** Jobs are orchestrators only — resolve dataset, call service, handle status. All logic lives in injectable services.

### Anti-Pattern 4: Treating QuoteWerks SQL as a Live Data Source

**What goes wrong:** `CableScheduleXlsxService` queries QuoteWerks SQL at render time to get the latest cable data. VPN drops, SQL is offline, render fails.

**Instead:** `QuoteWerksImportService::import()` pulls all data at import time and stores it in `ProjectPackage.extracted_data`. SQL is only accessed during import, never during generation.

---

## Scalability Considerations (within scope of single-company system)

| Concern | Current approach | With new generators |
|---------|-----------------|---------------------|
| Queue throughput | 3 job types, 2 retries each | Add 3 more job types — no bottleneck, same queue |
| SQL connection to QuoteWerks | Not applicable yet | Read-only, single connection at import time only, .env configured |
| Storage | Per-type directories under `storage/app/` | Add `storage/app/worksheets/` — same pattern |
| `ProjectDataService` query cost | N/A | 3 eager-loaded relationships on one project — negligible |

---

## Build Order Implications

For roadmap phasing, this architecture implies the following dependency chain:

```
1. ProjectDataService  →  (prerequisite for all generators)
2. QuoteWerksImportService  →  (independent of ProjectDataService, can be parallel)
3. Survey enhancements (room data capture)  →  (required for Worksheets and O&M to be useful)
4. RAMS wiring to ProjectDataService  →  (validates the data service before new generators use it)
5. WorksheetGeneratorService  →  (depends on ProjectDataService + survey room data)
6. OmManualGeneratorService hardening  →  (depends on ProjectDataService)
7. CableSchedule seeding from dataset  →  (depends on ProjectDataService + cable data in package)
```

Items 1 and 2 can be developed in parallel. Items 3 and 4 can overlap. Items 5, 6, 7 should not begin until item 1 is deployed and smoke-tested against real projects.

---

## Sources

- Direct inspection of existing codebase (2026-04-09):
  - `app/Services/ProjectContextResolver.php` — existing merge pattern to extend
  - `app/Services/RamsBuilderService.php` — established pipeline pattern to follow
  - `app/Core/Modules/Projects/ProjectService.php` — lifecycle management pattern
  - `app/Services/CableScheduleXlsxService.php` — XLSX generator interface
  - `app/Services/OmManualDocxService.php` — DOCX generator interface
  - `app/Core/Modules/OMManual/OmManualGeneratorService.php` — two-pass orchestration pattern
  - `app/Models/ProjectPackage.php`, `SiteSurvey.php`, `Project.php` — data model topology
- `.planning/PROJECT.md` — requirements and constraints
- `.planning/codebase/ARCHITECTURE.md` — existing architecture map

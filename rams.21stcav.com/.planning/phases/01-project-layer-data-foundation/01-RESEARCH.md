# Phase 1: Project Layer & Data Foundation — Research

**Researched:** 2026-04-09
**Domain:** Laravel 12 brownfield — Project model extension, canonical data merge service, dashboard UI
**Confidence:** HIGH (all findings derived from direct codebase inspection)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Project-Package Relationship**
- D-01: One project has one active package (ProjectPackage). New quote revisions replace the old package — versioning is within the package record, not multiple packages per project.
- D-02: Importing a quote auto-creates a project if none exists for that client+site combination. Quote data (client, site, name) auto-populates project fields — user can edit before saving.
- D-03: Migration auto-creates a project for each existing ProjectPackage record and links them (project_id backfilled).

**Dashboard Layout**
- D-04: Project show page is lifecycle-first: big lifecycle progress bar at top (focal point per UI-SPEC), then document status cards below.
- D-05: Linked documents display as status cards — one card per document type (RAMS, Survey, Worksheet, O&M, Cable Schedule) showing latest status, date, and action button.
- D-06: Empty document cards show action prompt: "No RAMS yet" with a "Generate RAMS" button. Cards are always visible, never hidden.
- D-07: No "Generate All" button — each document type has its own individual generate/action button.
- D-08: Project metadata in sidebar: client name, site address, quote reference + version, created/updated dates. No data source badge in sidebar.
- D-09: Navigation: breadcrumb "Projects > Project Name" — no prev/next buttons.

**Projects Index**
- D-10: Add client name filter alongside existing status filter tabs and search.
- D-11: Search covers project name, client, site address, and quote reference.

**Project Creation**
- D-12: All four fields required at creation: name, client, site address, quote reference.
- D-13: Quote versioning: simple text field with suffix convention (ABC123-01). User manually bumps version. No separate version number field.
- D-14: Warn but allow when creating a project with same client+site as existing one — show "Similar project exists" warning.
- D-15: Projects are shared across all users — any authenticated user can see and work on any project.
- D-16: All project fields editable at any lifecycle state — no locking after engineering.
- D-17: Projects can only be soft-deleted (archived). No hard delete capability.

**Lifecycle Transitions**
- D-18: Semi-automatic transitions. Three auto-advance events: Quote imported → survey_pending; Survey submitted → engineering; All documents generated → handover.
- D-19: Any authenticated user can trigger lifecycle transitions (advance or revert).
- D-20: State can be moved in any direction (backwards allowed for corrections).
- D-21: Archiving = soft hide from default project list. Archived projects accessible via filter. All data preserved.
- D-22: All state transitions (auto and manual) logged to ProjectActivityLog with user, timestamp, old state, new state.

**Data Merge Display**
- D-23: Data source shown via tooltip on hover — small tooltip showing "Source: PDF Import" or "Source: QuoteWerks SQL" or "Source: Manual Review" per field.
- D-24: Confidence scores: only flag low-confidence fields (don't clutter high-confidence ones). Threshold is Claude's discretion.
- D-25: Merged data visible in two places: review screen (for editing) and a "Project Data" tab on the project page (read-only view of canonical merged dataset).

**Data Structure**
- D-26: Rooms data merged from both sources: quote defines rooms via group structure, survey enriches with physical details. Merge by room name with auto-matching (fuzzy) and manual fallback for unmatched rooms.
- D-27: Equipment structure, DTO vs array, caching strategy, and empty data handling are all Claude's discretion.

### Claude's Discretion
- Project creation form: full page or modal (Claude decides)
- Low confidence threshold value (Claude decides)
- ProjectDataService implementation: typed DTO class vs associative array
- Equipment structure in canonical dataset (flat vs nested)
- Caching strategy for resolved datasets
- Graceful degradation behavior when sources are missing

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PROJ-01 | User can create a project with name, client, site address, and quote reference | ProjectController::store() + ProjectService::create() already exist; need quote_reference field on Project model and form extension |
| PROJ-02 | All system records (RAMS, surveys, worksheets, O&M, cable schedules) are linked via project_id | Migration `add_project_id_to_module_tables` already runs for 4 tables; worksheet table does not yet exist (Phase 4); project_id columns are nullable FK already |
| PROJ-03 | User can view a project dashboard showing all related records with status indicators | ProjectController::show() exists + show.blade.php partially exists; need "Linked Records" card added |
| PROJ-04 | Project follows lifecycle state machine (quote_imported → … → archived) | State machine fully implemented in Project model + ProjectService::transition(); auto-advance hooks need wiring into QuoteImportService and SurveyService |
| PROJ-05 | Quote references support versioning (ABC123-01, -02) | Requires adding `quote_reference` text field to projects table; current `ref` column serves this but needs the suffix convention confirmed |
| DATA-01 | ProjectDataService merges extracted_data, reviewed_data, and survey_data into canonical dataset | ProjectContextResolver already does subset of this; needs promotion to ProjectDataService in Core/Modules/Projects/ |
| DATA-02 | Canonical data structure: { project, equipment, rooms, activities, risks, survey_data } | Shape defined in research ARCHITECTURE.md; requires `risks` and `survey` top-level keys added beyond what ProjectContextResolver returns |
| DATA-03 | All document generators consume exclusively from ProjectDataService — no direct data access | Phase 1 only defines the service; generator wiring happens in Phases 4 & 5 — Phase 1 must not break existing RAMS pipeline |
| DATA-04 | Every data field carries source annotation (data_source: pdf / quotewerks / manual, confidence: 0.0–1.0) | `meta.source` already set in QuoteImportService::mergeParsedQuoteData(); per-field annotation is a new requirement needing a wrapper pattern |
| DATA-05 | Merge priority enforced: reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults | Pattern already exists in ProjectContextResolver; Phase 1 formalises it and adds survey_data and quotewerks_sql tiers |
</phase_requirements>

---

## Summary

Phase 1 is almost entirely a brownfield evolution, not a greenfield build. The `projects` table, lifecycle state machine, `ProjectService`, `ProjectController`, and `ProjectActivityLog` are all already in production. The `project_id` FK is already on `rams_documents`, `om_manuals`, `cable_schedules`, and `site_surveys`. The `ProjectContextResolver` service already does the core data-resolution logic that `ProjectDataService` formalises.

The actual work in Phase 1 is:

1. **Schema delta** — add `quote_reference` text field to `projects` table (the current `ref` column lacks explicit versioning convention); update `$fillable`, controller validation, and creation form.
2. **Visibility and sharing delta** — the current ProjectController uses `forUser(auth()->id())` scoping (user-isolated). D-15 requires shared projects visible to all authenticated users. This is a significant controller and query change.
3. **ProjectDataService** — promote `ProjectContextResolver` into `app/Core/Modules/Projects/ProjectDataService.php`, extend its output to include `risks`, `survey`, `programme`, `cables`, and `meta` keys, and add per-field `data_source` + `confidence` annotation.
4. **Auto-advance hooks** — wire three automatic lifecycle transitions into `QuoteImportService` and `SurveyService` via `ProjectService::transition()`.
5. **Dashboard UI** — add "Linked Records" card to `show.blade.php`; add client-name filter to `index.blade.php`; extend search to include `site_address`.
6. **"Similar project" warning** — detect same client+site on project creation and surface a flash warning (D-14).
7. **Backfill migration** — data migration that sets `project_id` on existing `ProjectPackage` records that have a project but may have been created before the FK was added.

**Primary recommendation:** Start with the ProjectDataService contract (DATA-01/02/05) as a typed array spec — write the shape first, implement second. Every other task in Phase 1 feeds into or consumes from this shape.

---

## Standard Stack

### Core (already in composer.json — no installs needed)
| Library | Version | Purpose | Notes |
|---------|---------|---------|-------|
| Laravel | 12.x | Framework | Already installed [VERIFIED: composer.json] |
| PHPUnit | 11.x | Test framework | Already installed [VERIFIED: composer.json] |
| Laravel Pint | ^1.24 | Code style enforcement | PSR-12 baseline, run before every commit [VERIFIED: composer.json] |

### No new packages required
All Phase 1 work — schema, services, controllers, Blade templates — uses the existing Laravel stack. No new Composer dependencies needed.

**Installation:** None required.

---

## Architecture Patterns

### Existing Infrastructure (Already Deployed)

The following components are ALREADY IN PRODUCTION and must not be broken:

```
app/
├── Core/
│   └── Modules/
│       └── Projects/
│           ├── ProjectService.php        ← lifecycle + activity log — EXTEND ONLY
│           └── [ProjectDataService.php]  ← CREATE HERE (next to ProjectService)
├── Services/
│   └── ProjectContextResolver.php       ← DEPRECATE gradually; ProjectDataService replaces it
├── Http/Controllers/
│   └── ProjectController.php            ← MODIFY (sharing, search, warning)
├── Models/
│   └── Project.php                      ← MODIFY (add quote_reference, update fillable)
│   └── ProjectPackage.php               ← READ ONLY (no changes needed)
│   └── ProjectActivityLog.php           ← READ ONLY (already has STATUS_CHANGED action)
```

### Pattern 1: ProjectDataService (DATA-01, DATA-02, DATA-03, DATA-05)

**What:** Read-only service in `app/Core/Modules/Projects/ProjectDataService.php` that extends the `ProjectContextResolver` pattern to produce a canonical typed-array dataset consumed by all downstream generators.

**Entry point:**
```php
// Source: derived from ProjectContextResolver pattern (app/Services/ProjectContextResolver.php)
class ProjectDataService
{
    public function resolve(Project $project): array
    {
        $package = $project->relationLoaded('latestPackage')
            ? $project->latestPackage
            : $project->latestPackage()->first();

        $survey  = $project->relationLoaded('siteSurveys')
            ? $project->siteSurveys->where('status', 'completed')->first()
            : $project->siteSurveys()->where('status', 'completed')->latest()->first();

        // reviewed_data wins over extracted_data (merge priority D-05)
        $source  = (array) ($package?->reviewed_data ?? $package?->extracted_data ?? []);
        $dataSource = $package?->reviewed_data ? 'manual' : ($source['meta']['source'] ?? 'pdf');

        return [
            'project'   => $this->resolveProjectFields($project),
            'equipment' => $this->resolveEquipment($source, $dataSource),
            'rooms'     => $this->resolveRooms($source, $survey),
            'activities'=> $this->resolveActivities($source, $dataSource),
            'risks'     => $this->resolveRisks($source, $survey, $dataSource),
            'survey'    => $this->resolveSurveyMeta($survey),
            'programme' => $this->resolveProgramme($source, $dataSource),
            'cables'    => $this->resolveCables($source, $dataSource),
            'meta'      => [
                'data_source'     => $dataSource,
                'has_survey'      => $survey !== null,
                'survey_complete' => $survey?->status === 'completed',
                'confidence'      => 1.0, // lowest confidence across sources
            ],
        ];
    }
}
```

**Per-field annotation pattern (DATA-04):** Each item in `equipment`, `rooms`, `activities`, `risks` carries a `data_source` key and a `confidence` float (0.0–1.0):
```php
// Example equipment item with annotation
[
    'name'        => 'Samsung 65" UHD Display',
    'quantity'    => 2,
    'area'        => 'Boardroom',
    'category'    => 'hardware',
    'data_source' => 'pdf',      // 'pdf' | 'quotewerks_sql' | 'manual'
    'confidence'  => 0.9,
]
```

**Confidence threshold for "flag as low" (D-24 — Claude's discretion):** Flag fields with confidence < 0.7. This surfaces genuinely uncertain items (OCR-derived values, AI extractions without parser confirmation) without cluttering high-confidence reviewed data.

**Merge priority implementation (DATA-05):**
```
reviewed_data (human-approved edits to package) → wins for all package-sourced fields
survey_data (field engineer submission)          → wins for room physical details only
quotewerks_sql (future Phase 2)                 → placeholder tier; not implemented Phase 1
extracted_data (AI PDF extraction)              → fallback when reviewed_data absent
defaults                                         → empty arrays / Project model columns
```

**Key rule:** `ProjectDataService::resolve()` NEVER persists. It is a pure read function. [VERIFIED: established in pre-phase decisions in STATE.md]

### Pattern 2: Auto-Advance Lifecycle Hooks (PROJ-04, D-18)

**What:** Three automatic transitions triggered by existing service calls.

**Hook 1 — Quote imported → survey_pending:**
Wire into `QuoteImportService::confirm()` after status is set to `STATUS_REVIEWED`. Call `ProjectService::transition($project, Project::STATUS_SURVEY_PENDING, $user)` only if project is currently in `STATUS_QUOTE_IMPORTED`. [VERIFIED: QuoteImportService::confirm() exists and has $project reference]

**Hook 2 — Survey submitted → engineering:**
Wire into `SurveyService` at the survey submission point. Call `ProjectService::transition($project, Project::STATUS_ENGINEERING, $user)` only if project is currently in `STATUS_SURVEY_PENDING`.

**Hook 3 — All documents generated → handover:**
This is the most complex hook. Must check: has at least one RAMS, Survey, Worksheet, O&M, and Cable Schedule in a completed state? If yes, call `ProjectService::transition($project, Project::STATUS_HANDOVER, system_user)`. Note: Worksheet does not exist in Phase 1, so this hook should NOT be activated until Phase 4. Document this constraint explicitly in the hook code.

**Guard pattern:** All three auto-advance calls must be wrapped in `canTransitionTo()` check and catch `InvalidArgumentException` — never let auto-advance break the primary user action.
```php
// Source: derived from ProjectController::transition() pattern
if ($project->canTransitionTo(Project::STATUS_SURVEY_PENDING)) {
    try {
        $this->projectService->transition($project, Project::STATUS_SURVEY_PENDING, $user);
    } catch (\InvalidArgumentException) {
        // Auto-advance failed silently — primary action succeeds regardless
        Log::warning('ProjectDataService: auto-advance skipped', ['project_id' => $project->id]);
    }
}
```

### Pattern 3: Shared Project Visibility (D-15)

**Critical change from current behaviour:** `ProjectController::index()` currently uses `->forUser(auth()->id())` scope which isolates projects per user. D-15 requires ALL authenticated users to see ALL projects.

**Change required:**
- Remove `->forUser(auth()->id())` from index query
- Remove `->forUser(auth()->id())` from statusCounts query
- Remove the `abort_if($project->user_id !== auth()->id() ...)` guard in `show()` — replace with simple `auth()->check()` (all authenticated users can view)
- Keep `user_id` on project as the "created by" field for audit purposes — do not remove

**Impact on existing tests:** `QuoteProjectResolutionTest.php` contains 12 user-scoping assertions (e.g., `test_project_ref_match_is_scoped_to_authenticated_user`). These tests will pass unchanged because the upload flow creates per-user projects — the sharing change is a display change, not a creation change.

### Pattern 4: "Similar Project" Warning (D-14)

**What:** When storing a new project, check for existing projects with the same client+site. If found, redirect back with a warning flash but still create the project.

**Implementation:** In `ProjectController::store()`, after validation but before calling `ProjectService::create()`, query for projects with matching `client_name` and `site_address` (case-insensitive LIKE). If found, set a session warning. Create the project regardless.
```php
// In ProjectController::store() — before $this->service->create()
$similar = Project::whereRaw('LOWER(client_name) = ?', [strtolower($validated['client_name'])])
    ->whereRaw('LOWER(site_address) = ?', [strtolower($validated['site_address'])])
    ->exists();

$project = $this->service->create(auth()->user(), $validated);

$response = redirect()->route('projects.show', $project)->with('success', 'Project created.');
if ($similar) {
    $response = $response->with('warning', 'A project with the same client and site address already exists. Please confirm this is intentional.');
}
return $response;
```

### Pattern 5: ProjectDataService Caching (D-27 — Claude's discretion)

**Recommendation:** No database caching. Resolve fresh on each call.

**Rationale:** The query cost is 3 eager-loaded relationships on one project (package, survey, activityLog). This is negligible. Caching risks stale data when package is reviewed or survey is submitted. The existing `AICacheService` approach (SHA-256 prompt hashing into `ai_cache` table) is overkill for a read-only data merge that takes < 5ms.

**If performance becomes an issue (unlikely at current scale):** Use `cache()->remember()` with a key based on `project_{id}_data_{package_updated_at}_{survey_updated_at}` and a 5-minute TTL. Invalidate on `ProjectPackage::saved` and `SiteSurvey::saved` model events.

### Pattern 6: ProjectDataService — associative array vs typed DTO (D-27 — Claude's discretion)

**Recommendation:** Associative array (not a DTO class).

**Rationale:** The existing `ProjectContextResolver` returns an associative array. All existing generator services consume associative arrays (`reviewed_data`, `extracted_data`, `form_data`). Introducing a DTO class adds a new pattern that no other part of the codebase uses. Generators in Phases 4 and 5 would need to convert DTO → array for template rendering anyway. Document the array shape as class constants instead:
```php
class ProjectDataService
{
    /** Keys guaranteed present in the resolved dataset. */
    const DATASET_KEYS = ['project', 'equipment', 'rooms', 'activities', 'risks', 'survey', 'programme', 'cables', 'meta'];

    /** Keys guaranteed present on each equipment item. */
    const EQUIPMENT_ITEM_KEYS = ['name', 'quantity', 'area', 'category', 'data_source', 'confidence'];
}
```

### Recommended Project Structure (Phase 1 additions)

```
app/
├── Core/Modules/Projects/
│   ├── ProjectService.php               [EXISTING — no changes except auto-advance]
│   └── ProjectDataService.php           [NEW — canonical data merge layer]
├── Http/Controllers/
│   └── ProjectController.php            [MODIFY — sharing, search, warning]
├── Models/
│   └── Project.php                      [MODIFY — quote_reference field + fillable]
database/migrations/
│   └── YYYY_MM_DD_add_quote_ref...php   [NEW — adds quote_reference to projects table]
│   └── YYYY_MM_DD_backfill_packages...  [NEW — data migration for existing packages]
resources/views/projects/
│   ├── index.blade.php                  [MODIFY — client filter, search extension]
│   ├── show.blade.php                   [MODIFY — Linked Records card, Project Data tab]
│   └── create.blade.php                 [MODIFY — quote_reference field]
tests/Feature/Projects/
│   └── ProjectDataServiceTest.php       [NEW — unit test for resolve() output shape]
│   └── ProjectLifecycleTest.php         [NEW — auto-advance transition tests]
│   └── ProjectSharingTest.php           [NEW — shared visibility tests]
```

### Anti-Patterns to Avoid

- **ProjectDataService calling generators:** The service resolves only. It never knows which generator is consuming it. No `if ($generator === 'rams')` branches.
- **Storing the resolved dataset:** No `projects.canonical_data` JSON column. Resolve fresh every time.
- **Touching BuildRamsDocumentJob or RamsBuilderService:** These are read-only for Phase 1. The RAMS pipeline must not change.
- **Removing ProjectContextResolver immediately:** Keep it as a deprecated wrapper. Phase 5 (RAMS migration) will retire it.
- **Using `->forUser()` on the new shared queries:** Remove this scope from index/show — projects are shared per D-15. The scope remains useful only for admin "created by me" filters.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Lifecycle state transitions | Custom state field updates | `ProjectService::transition()` | Already handles DB transaction, activity log, milestone timestamps |
| Activity logging | Direct `ProjectActivityLog::create()` calls | `ProjectService::log()` | Consistent signature, tested |
| Soft delete | `Project::delete()` with archive flag | Laravel `SoftDeletes` trait (already on model) | Already implemented — `deleted_at` column in schema |
| Fuzzy room matching | Custom Levenshtein implementation | PHP built-in `similar_text()` + `levenshtein()` with threshold 75% | Use for D-26 room merge; these are standard library functions |
| Confidence calculation | Custom ML scoring | Simple heuristic: reviewed_data = 0.95, parsed_with_structure = 0.85, AI-extracted = 0.70, no_source = 0.50 | Confidence is informational, not actioned by any algorithm |

---

## Runtime State Inventory

Step 2.5: SKIPPED — this is not a rename/refactor/migration phase. No runtime state audit required.

---

## Common Pitfalls

### Pitfall 1: Breaking the Shared-Projects Scope Change

**What goes wrong:** Removing `->forUser(auth()->id())` from `ProjectController::index()` makes ALL projects visible. If `statusCounts` query is not also updated, the count badge shows wrong numbers (only the current user's counts).

**How to avoid:** Both `$projects` query AND `$statusCounts` query must drop the `forUser()` scope in the same commit. Run `QuoteProjectResolutionTest` after the change — these tests use user-isolated project creation and will catch cross-user leakage.

**Warning signs:** Status tab showing "Engineering (3)" but only 1 project visible in the list.

### Pitfall 2: Migration Adding Non-Nullable Column to Existing Projects Table

**What goes wrong:** Adding `quote_reference` as `->string()->notNull()` on the `projects` table fails if any rows already exist.

**How to avoid:** Add as `->string('quote_reference', 100)->nullable()`. The application treats a null `quote_reference` as "not yet assigned" — the creation form requires it, but existing rows may be null. [VERIFIED: Pitfall C6 in .planning/research/PITFALLS.md]

### Pitfall 3: Auto-Advance Breaking Primary Action on Exception

**What goes wrong:** `QuoteImportService::confirm()` calls `ProjectService::transition()` for auto-advance. If the project is already past `survey_pending` (e.g., manually advanced), `canTransitionTo()` returns false and `transition()` throws `InvalidArgumentException`. This exception bubbles up and rolls back the entire `confirm()` transaction.

**How to avoid:** Wrap auto-advance calls in a try/catch that only catches `InvalidArgumentException`. Log a warning, do not rethrow. The primary action (confirming the package) always succeeds regardless of auto-advance outcome.

### Pitfall 4: ProjectDataService God-Class Growth

**What goes wrong:** Every generator phase adds special-case data fields to `ProjectDataService` — "the worksheet needs this", "the O&M needs that". The service grows to 2,000+ lines.

**How to avoid:** `ProjectDataService::resolve()` returns ONE canonical shape (documented by `DATASET_KEYS` constant) and NOTHING ELSE. Generators have their own adapter/transformer that reshapes the canonical array for their template. No generator-specific logic lives in `ProjectDataService`. [VERIFIED: Pitfall C4 in .planning/research/PITFALLS.md]

### Pitfall 5: Merge Priority Override by extracted_data

**What goes wrong:** If `reviewed_data` is null on a package (package never reviewed), `ProjectDataService` falls back to `extracted_data`. But some fields in `extracted_data` (like `client_name`) may differ from what is stored on the `Project` model itself (the authoritative source). Using `extracted_data['client_name']` over `$project->client_name` silently returns stale data.

**How to avoid:** Project model fields (`name`, `ref`, `client_name`, `site_address`) always come from the `Project` model directly — not from any package field. This matches the existing pattern in `ProjectContextResolver::resolveProjectFields()`. Equipment, rooms, activities, risks, cables come from the package. [VERIFIED: app/Services/ProjectContextResolver.php line 75-85]

### Pitfall 6: D-15 Sharing Conflicts with Existing Authorization

**What goes wrong:** `ProjectController::show()` currently has `abort_if($project->user_id !== auth()->id() && role !== 'admin', 403)`. Removing this for shared projects also removes protection against unauthenticated access (the `auth` middleware handles route-level auth, but the explicit check is the belt-and-suspenders guard).

**How to avoid:** Replace the per-user ownership check with a simple `abort_unless(auth()->check(), 401)` — any authenticated user can view any project. The route-level `auth` middleware already enforces login, so this is belt-and-suspenders only. Keep admin hard-delete guard (`abort_unless(auth()->user()->isAdmin(), 403)`) unchanged.

### Pitfall 7: Backfill Migration Touching Live ProjectPackage Records

**What goes wrong:** The D-03 backfill that creates projects for existing `ProjectPackage` records may create duplicate projects if some packages already have `project_id` set.

**How to avoid:** The backfill migration must only create projects for `ProjectPackage` records WHERE `project_id IS NULL`. Query: `ProjectPackage::whereNull('project_id')->get()`. Never re-process packages that already have a project.

---

## Code Examples

### ProjectDataService::resolve() skeleton

```php
// Location: app/Core/Modules/Projects/ProjectDataService.php
// Source: derived from app/Services/ProjectContextResolver.php

namespace App\Core\Modules\Projects;

use App\Models\Project;
use App\Models\SiteSurvey;

class ProjectDataService
{
    /** Keys guaranteed present in every resolved dataset. */
    const DATASET_KEYS = ['project', 'equipment', 'rooms', 'activities', 'risks', 'survey', 'programme', 'cables', 'meta'];

    /**
     * Resolve canonical project dataset from all available sources.
     * READ ONLY — never persists anything.
     *
     * Merge priority: reviewed_data > survey_data > extracted_data > defaults
     */
    public function resolve(Project $project): array
    {
        $package = $project->relationLoaded('latestPackage')
            ? $project->latestPackage
            : $project->latestPackage()->first();

        $survey = $this->resolveLatestCompletedSurvey($project);

        // Merge priority: reviewed_data wins over extracted_data
        $packageData = (array) ($package?->reviewed_data ?? $package?->extracted_data ?? []);
        $dataSource  = $package?->reviewed_data
            ? 'manual'
            : (string) ($package?->extracted_data['meta']['source'] ?? 'pdf');

        return [
            'project'    => $this->resolveProjectFields($project),
            'equipment'  => $this->resolveEquipment($packageData, $dataSource),
            'rooms'      => $this->resolveRooms($packageData, $survey, $dataSource),
            'activities' => $this->resolveActivities($packageData, $dataSource),
            'risks'      => $this->resolveRisks($packageData, $survey, $dataSource),
            'survey'     => $this->resolveSurveyMeta($survey),
            'programme'  => $this->resolveProgramme($packageData, $dataSource),
            'cables'     => $this->resolveCables($packageData, $dataSource),
            'meta'       => [
                'data_source'     => $dataSource,
                'has_survey'      => $survey !== null,
                'survey_complete' => $survey?->status === 'completed',
                'confidence'      => $this->lowestConfidence($packageData),
            ],
        ];
    }
}
```

### Per-field data_source annotation helper

```php
// Annotate a single field with its provenance
private function annotate(mixed $value, string $dataSource, float $confidence): array
{
    return [
        'value'       => $value,
        'data_source' => $dataSource,
        'confidence'  => $confidence,
    ];
}

// Use for scalar project fields in the review UI:
// $this->annotate($project->client_name, 'manual', 0.95)
```

### Confidence heuristic (Claude's discretion)

```php
private function confidenceForSource(string $dataSource, bool $hasParserConfirmation = false): float
{
    return match ($dataSource) {
        'manual'        => 0.95,  // human-reviewed
        'quotewerks_sql'=> 0.90,  // structured SQL data
        'pdf'           => $hasParserConfirmation ? 0.85 : 0.70,
        default         => 0.50,  // unknown source
    };
}

// Flag threshold (D-24): confidence < 0.7 → show warning indicator
const LOW_CONFIDENCE_THRESHOLD = 0.7;
```

### Auto-advance hook in QuoteImportService::confirm()

```php
// After $package->update(['status' => ProjectPackage::STATUS_REVIEWED])
// Wire D-18 auto-advance: package confirmed → survey_pending
if ($package->project && $package->project->canTransitionTo(Project::STATUS_SURVEY_PENDING)) {
    try {
        $this->projectService->transition(
            $package->project,
            Project::STATUS_SURVEY_PENDING,
            $user,
        );
    } catch (\InvalidArgumentException $e) {
        Log::warning('QuoteImportService: auto-advance to survey_pending skipped', [
            'project_id' => $package->project->id,
            'reason'     => $e->getMessage(),
        ]);
    }
}
```

### Shared project query (D-15)

```php
// ProjectController::index() — remove forUser() scope
$query = Project::with('latestPackage')
    ->orderByDesc('updated_at');  // all users, no scope

// statusCounts similarly without forUser()
$statusCounts = Project::selectRaw('status, count(*) as total')
    ->groupBy('status')
    ->pluck('total', 'status');
```

### Client filter on index (D-10)

```php
// In ProjectController::index() — add $client filter alongside $status and $search
$client = $request->query('client');

if ($client) {
    $query->where('client_name', 'like', "%{$client}%");
}

// Pass to view for pre-populating filter input
return view('projects.index', compact('projects', 'statusCounts', 'status', 'search', 'client', 'isAdmin', 'showDeleted'));
```

### Extended search (D-11 — add site_address to search)

```php
// Extend existing search in ProjectController::index()
if ($search) {
    $query->where(function ($q) use ($search) {
        $q->where('name',         'like', "%{$search}%")
          ->orWhere('client_name', 'like', "%{$search}%")
          ->orWhere('ref',         'like', "%{$search}%")
          ->orWhere('site_address','like', "%{$search}%");  // D-11 addition
    });
}
```

---

## State of the Art

| Old Approach | Current Approach | Phase 1 Change | Impact |
|--------------|------------------|----------------|--------|
| ProjectContextResolver (flat, 4 keys) | ProjectContextResolver (production) | Replace with ProjectDataService (8 keys + meta) | All generators in Phases 4/5 consume from one place |
| Per-user project isolation | `forUser(auth()->id())` scoping | Remove scope — all-user shared | RAMS upload flow continues unchanged (still creates per-user records) |
| No per-field data provenance | `meta.source` on package level only | Per-field `data_source` + `confidence` on every item | Enables D-23 tooltip UI and D-24 confidence flagging |
| Manual lifecycle advance only | Manual transitions + canTransitionTo() | Add 2 auto-advance hooks (quote confirm, survey submit) | D-18 fulfilled; handover auto-advance deferred to Phase 4 |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `ProjectPackage.reviewed_data` structure has same keys as `extracted_data` (equipment, room_overviews, activities, hazards) | Architecture Patterns / ProjectDataService | If reviewed_data has different keys, merge logic needs adapting — read RamsReviewController to verify actual keys before implementing |
| A2 | `SiteSurvey` model has a `status` field that includes a 'completed' value | Architecture Patterns / auto-advance | If completion is tracked differently (e.g., `submitted_at` not null), the survey-complete check needs updating |
| A3 | The `worksheets` table does not yet exist | Phase Requirements / PROJ-02 | If it does exist, the Linked Records card needs a 5th document type immediately |

**A1 verification path:** Read `app/Http/Controllers/RamsReviewController.php` lines 60–120 to see what keys are written to `reviewed_data`.
**A2 verification path:** Read `app/Models/SiteSurvey.php` full file for status constants.
**A3 verification path:** `php artisan migrate:status | grep worksheet` — or check `database/migrations/` for worksheet table.

---

## Open Questions (RESOLVED)

1. **Does `ProjectPackage` have a `reviewed_data` column?**
   - **RESOLVED:** No. The `project_packages` migration (`2026_03_14_000002`) creates: `extracted_data` (json), `equipment_list` (json), `cable_list` (json), `works_description` (text), `notes` (text). No `reviewed_data` column exists. Human review edits are stored on `RamsDocument.reviewed_data`, not on the package. ProjectDataService merge priority for package data is simply `extracted_data`. The `reviewed_data` tier in the merge chain applies only when resolving from `RamsDocument` records, not from `ProjectPackage`.

2. **How does D-25 "Project Data tab" surface on show.blade.php?**
   - **RESOLVED:** Plan 01-04 implements the Project Data tab as an Alpine.js tab strip on show.blade.php. The "Linked Records" card (built in Plan 01-02) is wrapped inside an "Overview" tab, and a "Project Data" tab shows the read-only canonical dataset. This resolves the D-25 vs UI-SPEC tension — both are delivered.

3. **What columns does `project_packages` migration actually create?**
   - **RESOLVED:** Confirmed columns: `id`, `user_id`, `project_id`, `ref`, `extracted_data` (json), `equipment_list` (json), `cable_list` (json), `works_description` (text), `notes` (text), `status`, timestamps. No `reviewed_data` column.

---

## Environment Availability

Step 2.6: SKIPPED — Phase 1 is pure PHP/Blade code and schema changes. No external CLI tools, services, or databases beyond the existing MySQL instance are required. No new Composer packages. No queue changes.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (Laravel's default) |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter=Project` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PROJ-01 | Project creation with all 4 fields | Feature | `php artisan test --filter=ProjectCreationTest` | No — Wave 0 |
| PROJ-02 | All records linked via project_id | Feature | Covered by existing `QuoteProjectResolutionTest` | Yes (`test_upload_creates_rams_document_linked_to_resolved_project`) |
| PROJ-03 | Dashboard shows all linked records | Feature | `php artisan test --filter=ProjectDashboardTest` | No — Wave 0 |
| PROJ-04 | Lifecycle auto-advance transitions | Feature | `php artisan test --filter=ProjectLifecycleTest` | No — Wave 0 |
| PROJ-05 | Quote reference versioning (text field) | Feature | `php artisan test --filter=ProjectCreationTest` | No — Wave 0 |
| DATA-01 | ProjectDataService::resolve() returns full dataset | Unit | `php artisan test --filter=ProjectDataServiceTest` | No — Wave 0 |
| DATA-02 | Canonical shape has all required keys | Unit | `php artisan test --filter=ProjectDataServiceTest` | No — Wave 0 |
| DATA-03 | Generators don't access package directly | Static/manual | N/A — enforced by code review in Phase 4/5 | N/A |
| DATA-04 | Every equipment/room item has data_source + confidence | Unit | `php artisan test --filter=ProjectDataServiceTest` | No — Wave 0 |
| DATA-05 | reviewed_data wins over extracted_data | Unit | `php artisan test --filter=ProjectDataServiceTest` | No — Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Project`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Projects/ProjectCreationTest.php` — covers PROJ-01, PROJ-05
- [ ] `tests/Feature/Projects/ProjectDashboardTest.php` — covers PROJ-03
- [ ] `tests/Feature/Projects/ProjectLifecycleTest.php` — covers PROJ-04 auto-advance
- [ ] `tests/Unit/Projects/ProjectDataServiceTest.php` — covers DATA-01, DATA-02, DATA-04, DATA-05
- [ ] `tests/Feature/Projects/ProjectSharingTest.php` — covers D-15 all-user visibility

*(Existing `QuoteProjectResolutionTest.php` covers PROJ-02 and can be relied upon.)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Laravel Breeze session-based auth — already in place |
| V3 Session Management | no | No new sessions introduced |
| V4 Access Control | yes | Shared project visibility (D-15) removes per-user ownership check — ensure `auth` middleware covers all project routes |
| V5 Input Validation | yes | All project form fields validated in FormRequest or inline validation array |
| V6 Cryptography | no | No new crypto operations |

### Known Threat Patterns for This Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Insecure Direct Object Reference (project_id in URL) | Elevation of Privilege | Route model binding + `auth` middleware; removed per-user ownership check is acceptable because D-15 is intentional shared access |
| Mass assignment via Project::create() | Tampering | `$fillable` array on Project model must be kept strict; do not use `$guarded = []` |
| SQL injection via search/filter inputs | Tampering | Use Eloquent `where('column', 'like', "%{$search}%")` — parameterized, safe |
| CSRF on lifecycle transition forms | Tampering | All POST forms use `@csrf` directive (verified in existing show.blade.php) |

---

## Sources

### Primary (HIGH confidence — direct codebase inspection)
- `app/Models/Project.php` — 8-state lifecycle, STATUS constants, canTransitionTo(), LIFECYCLE/TRANSITIONS arrays
- `app/Models/ProjectPackage.php` — extracted_data, equipment_list, cable_list; `reviewed_data` NOT confirmed on this model
- `app/Models/ProjectActivityLog.php` — ACTION constants, append-only pattern
- `app/Services/ProjectContextResolver.php` — existing merge pattern that ProjectDataService extends
- `app/Core/Modules/Projects/ProjectService.php` — create(), transition(), archive(), reopen(), log(), milestoneColumn()
- `app/Core/Modules/QuoteImport/QuoteImportService.php` — confirm() hook point for auto-advance, createProject flow
- `app/Http/Controllers/ProjectController.php` — full CRUD + transition + archive + reopen; forUser() scope usage identified
- `app/Http/Controllers/QuoteImportController.php` — confirm() creates project if none, passes to ProjectService
- `resources/views/projects/show.blade.php` — lifecycle progress bar, lifecycle action panel, reopen form (existing)
- `resources/views/projects/index.blade.php` — filter tabs, search form (existing)
- `database/migrations/2026_03_14_000001_create_projects_table.php` — full schema confirmed
- `database/migrations/2026_03_14_000020_add_project_id_to_module_tables.php` — FK on 4 tables confirmed
- `tests/Feature/Projects/QuoteProjectResolutionTest.php` — 14 existing tests, user-scoped matching logic
- `.planning/phases/01-project-layer-data-foundation/01-CONTEXT.md` — all locked decisions
- `.planning/phases/01-project-layer-data-foundation/01-UI-SPEC.md` — design tokens, surface specs, component inventory
- `.planning/codebase/ARCHITECTURE.md` — layer map, data flow, AI abstraction pattern
- `.planning/codebase/CONVENTIONS.md` — naming, error handling, logging patterns
- `.planning/research/ARCHITECTURE.md` — ProjectDataService design, merge priority chain
- `.planning/research/PITFALLS.md` — migration safety, god class prevention, existing pipeline preservation

### Secondary (MEDIUM confidence — derived patterns)
- `.planning/research/SUMMARY.md` — synthesised findings; used to validate research direction

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all existing
- Architecture: HIGH — derived directly from production code
- Pitfalls: HIGH — C4, C5, C6 verified against actual codebase
- Assumptions A1/A2/A3: LOW — require executor to read 2 additional files before implementing

**Research date:** 2026-04-09
**Valid until:** 2026-05-09 (stable codebase; extends to 30 days from research)

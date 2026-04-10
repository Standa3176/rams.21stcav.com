---
phase: 01-project-layer-data-foundation
verified: 2026-04-10T00:00:00Z
status: gaps_found
score: 7/14 must-haves verified
overrides_applied: 0
gaps:
  - truth: "User can create a project with name, client, site address, and quote reference (all required)"
    status: failed
    reason: "quote_reference field is absent from create.blade.php form. The store() validation in ProjectController does not include quote_reference as a required rule. The plan required quote_reference to be a required field on the create form with an inline error block and help text — none of this exists."
    artifacts:
      - path: "resources/views/projects/create.blade.php"
        issue: "No quote_reference input field rendered anywhere in the form"
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "store() validates only: name, ref (nullable), client_name, site_address, works_description, notes — quote_reference is absent"
    missing:
      - "Add quote_reference input field to create.blade.php inside .form-grid-2"
      - "Add 'quote_reference' => ['required', 'string', 'max:50'] to store() validation"
      - "Add @error('quote_reference') block in create form"

  - truth: "Projects are visible to all authenticated users, not scoped per-user"
    status: failed
    reason: "ProjectController::index() still applies ->forUser(auth()->id()) on both the main query and statusCounts. This contradicts decision D-15 (shared visibility). Any user only sees their own projects."
    artifacts:
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "Line 38: ->forUser(auth()->id()) still present on main query. Line 57: Project::forUser(auth()->id()) still on statusCounts query."
    missing:
      - "Remove ->forUser(auth()->id()) from main query chain in index()"
      - "Remove Project::forUser(auth()->id()) from statusCounts query"
      - "Replace with Project::query() and Project::query() respectively"

  - truth: "Any user can advance or revert project lifecycle state in any direction"
    status: failed
    reason: "ProjectController::transition() calls $this->authorizeProject($project) which enforces user_id === auth()->id() OR admin. Non-owning users cannot trigger transitions, contradicting D-19."
    artifacts:
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "Line 201: $this->authorizeProject($project) blocks non-owners; show() also has abort_if check at line 102-105 blocking non-owner views"
    missing:
      - "Remove authorizeProject() check from transition() — replace with abort_unless(auth()->check(), 403)"
      - "Relax show() guard from user_id ownership check to auth()->check() only"

  - truth: "All auto-advance transitions are logged to ProjectActivityLog via ProjectService::transition()"
    status: failed
    reason: "The feature test class calls $service->importFromData() but that method does not exist on QuoteImportService — only import(UploadedFile $file) exists. All 6 feature tests in ProjectAutoAdvanceTest will throw BadMethodCallException. The auto-create-on-import path (test 1 and test 2) cannot be verified to work at all."
    artifacts:
      - path: "tests/Feature/ProjectAutoAdvanceTest.php"
        issue: "Tests 1 and 2 call $service->importFromData($user, array) — method does not exist"
      - path: "app/Core/Modules/QuoteImport/QuoteImportService.php"
        issue: "No importFromData() method defined; only import(User, UploadedFile, ...) exists"
    missing:
      - "Either add importFromData(User $user, array $data): ProjectPackage convenience method to QuoteImportService that accepts an array instead of an UploadedFile"
      - "Or rewrite tests 1 and 2 to use the correct import() API (mock UploadedFile or create factory fixture)"

  - truth: "POST /projects without quote_reference returns 422 with validation error"
    status: failed
    reason: "store() validation does not include quote_reference — the field is not required and not validated. Submitting without it will succeed."
    artifacts:
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "store() validation array at lines 82-88 has no quote_reference rule"
    missing:
      - "Add quote_reference to validation rules in store() (required, string, max:50)"

  - truth: "Similar-project warning flash fires but does not block creation"
    status: failed
    reason: "ProjectController::store() contains no similar-project warning check (LOWER(client_name) + LOWER(site_address) comparison). The plan's PART B logic was not implemented."
    artifacts:
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "store() calls $this->service->create() directly with no prior similar-project query"
    missing:
      - "Add similar-project check before create() call: Project::whereRaw('LOWER(client_name) = ?', ...)->whereRaw('LOWER(site_address) = ?', ...)->whereNull('deleted_at')->exists()"
      - "Add ->with('warning', '...') to redirect response when similar project found"

  - truth: "GET /projects?search=acme searches across name, client_name, site_address, and ref columns"
    status: failed
    reason: "Search in index() covers name, client_name, and ref only — site_address is not included in the orWhere chain. Plan spec and acceptance criteria required site_address to be searchable."
    artifacts:
      - path: "app/Http/Controllers/ProjectController.php"
        issue: "Lines 48-52: orWhere covers name, client_name, ref — no orWhere for site_address"
    missing:
      - "Add ->orWhere('site_address', 'like', \"%{$search}%\") to the search where clause in index()"
deferred: []
human_verification:
  - test: "Visual: project create form at /projects/create once gaps are resolved"
    expected: "All 4 fields (name, client, site address, quote reference) present with correct copy per UI-SPEC. 'New Project' title. 'Back to Projects' link. 'Create Project' button."
    why_human: "UI layout, copy, and visual appearance cannot be verified programmatically"
  - test: "Responsive layout collapse at 900px on project show page"
    expected: "Two-column grid collapses to single column at 900px"
    why_human: "CSS media query behaviour requires browser testing"
  - test: "Alpine.js tab toggle on project show page"
    expected: "Overview and Project Data tabs switch without page reload"
    why_human: "JavaScript runtime behaviour requires browser testing"
---

# Phase 1: Project Layer & Data Foundation — Verification Report

**Phase Goal:** Engineers can create and manage projects as top-level entities, and `ProjectDataService` exists with a defined canonical contract ready for all generators to consume
**Verified:** 2026-04-10
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| #  | Truth | Status | Evidence |
|----|-------|--------|----------|
| 1  | User can create a project with name, client, site address, and quote reference (all required) | FAILED | `quote_reference` absent from `create.blade.php` and `store()` validation |
| 2  | Projects are visible to all authenticated users, not scoped per-user | FAILED | `->forUser(auth()->id())` still in `index()` and `statusCounts` |
| 3  | Any user can advance or revert project lifecycle state in any direction | FAILED | `authorizeProject()` ownership check in `transition()` and `show()` blocks non-owners |
| 4  | Project can only be soft-deleted; archived projects appear via filter | VERIFIED | `SoftDeletes` trait used; `?status=archived` pattern present in index |
| 5  | All state transitions are logged to ProjectActivityLog with user, timestamp, old state, new state | FAILED (partial) | `ProjectService::transition()` logs correctly; but feature tests calling `importFromData()` (non-existent method) prevent auto-advance hook verification |
| 6  | Every existing ProjectPackage record has a project_id after migration — orphans get a newly auto-created Project | VERIFIED | Migration `2026_04_10_000002_backfill_project_id_on_module_tables.php` exists and handles backfill (note: executor correctly identified that packages already had non-null project_id per existing schema — no orphan risk) |
| 7  | Project show page has lifecycle progress bar as the first visual element | VERIFIED | Progress bar markup confirmed unchanged in `show.blade.php` |
| 8  | Linked Records section shows one row per document type (RAMS, Survey, Worksheet, O&M, Cable Schedule) with status badge, date, and action button | VERIFIED | `$linkedRecords` array built with all 5 types; `show.blade.php` contains "Linked Records" section |
| 9  | Empty document rows show action prompt text and a button — never hidden | VERIFIED | Code review confirms D-06 compliance; all rows always render |
| 10 | Project metadata sidebar shows client name, site address, quote reference + version, created/updated dates | VERIFIED | `show.blade.php` line 932: `$project->quote_reference ?? $project->ref ?? '—'` in sidebar |
| 11 | Breadcrumb shows Projects > Project Name | VERIFIED | `<nav class="breadcrumb" aria-label="breadcrumb">` at line 14 of `show.blade.php` |
| 12 | Projects index has a client name filter alongside status filter tabs | VERIFIED | `<select name="client">` with "All clients" option in `index.blade.php`; `->when($client, ...)` in controller |
| 13 | No Generate All button exists anywhere on the show page | VERIFIED | Grep for "Generate All" returns 0 matches in `show.blade.php` |
| 14 | ProjectDataService::resolve(project) returns a canonical array with keys: project, equipment, rooms, activities, risks, survey, programme, cables, meta | VERIFIED | `ProjectDataService.php` exists with full implementation; `$canonicalData` passed from `ProjectController::show()` to view; Project Data tab renders in `show.blade.php` |

**Score:** 7/14 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_04_10_000001_add_quote_reference_to_projects_table.php` | quote_reference column on projects table | VERIFIED | Adds `text('quote_reference')->nullable()` |
| `database/migrations/2026_04_10_000002_backfill_project_id_on_module_tables.php` | Backfill project_id on module tables | VERIFIED | Handles all 4 module tables with Schema::hasColumn guard |
| `app/Models/Project.php` | quote_reference in fillable, TRANSITIONS_BACKWARD, canTransitionTo updated | VERIFIED | All three present and correct |
| `app/Http/Controllers/ProjectController.php` | Shared visibility (no forUser scope), similar-project warning | FAILED | `forUser()` scope still present on lines 38 and 57; no similar-project check in `store()`; `authorizeProject()` still called in `transition()` and `show()` |
| `app/Http/Requests/ProjectRequest.php` | Validation rules requiring all 4 fields | MISSING | File does not exist; validation remains inline without `quote_reference` |
| `resources/views/projects/create.blade.php` | quote_reference field with validation error block | FAILED | Field is absent; no `quote_reference` input rendered |
| `app/Core/Modules/Projects/ProjectDataService.php` | canonical data merge service | VERIFIED | Full implementation with CONFIDENCE_THRESHOLD=0.7, resolve(), isLowConfidence() |
| `tests/Unit/ProjectDataServiceTest.php` | 8 unit tests for resolve() contract | VERIFIED | All 8 tests exist; summary confirms they pass |
| `tests/Unit/ProjectTransitionTest.php` | Bidirectional transition tests | VERIFIED | 12 tests covering forward, backward, and invalid transitions |
| `tests/Feature/ProjectAutoAdvanceTest.php` | 6 feature tests for auto-create and auto-advance | STUB | Tests 1 and 2 call `$service->importFromData()` which does not exist on `QuoteImportService` — these tests will fail at runtime |
| `app/Core/Modules/QuoteImport/QuoteImportService.php` | auto-create project on import (D-02) and auto-advance hook | PARTIAL | Auto-advance hook (confirm → survey_pending) is wired correctly; auto-create by client+site match is in the import() transaction but only when $project===null and $createProject===false; `importFromData()` test method does not exist |
| `app/Core/Modules/Survey/SurveyService.php` | auto-advance hook after survey submission | VERIFIED | Lines 210-220 and 306-316 confirm STATUS_ENGINEERING hooks in complete() and submitPublic() |
| `resources/views/projects/show.blade.php` | Linked Records card, lifecycle bar, sidebar, Project Data tab | VERIFIED | All sections present; canonicalData variable wired; tab strip with Alpine.js |
| `resources/views/projects/index.blade.php` | client name filter | VERIFIED | Select dropdown with All clients option, auto-submits on change |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `ProjectController::index()` | `Project::query()` (shared visibility) | remove forUser() scope | NOT_WIRED | `forUser()` still called on lines 38 and 57 |
| `ProjectController::store()` | Similar-project warning | `Project::whereRaw(LOWER(client_name))` | NOT_WIRED | No similar-project check exists in store() |
| `ProjectController::show()` | `ProjectDataService::resolve()` | `$canonicalData` passed to view | WIRED | Lines 164-166 confirm injection and use |
| `QuoteImportService::confirm()` | `ProjectService::transition(STATUS_SURVEY_PENDING)` | auto-advance guard | WIRED | Lines 252-267 confirmed |
| `SurveyService::complete()` | `ProjectService::transition(STATUS_ENGINEERING)` | auto-advance guard | WIRED | Lines 210-220 confirmed |
| `ProjectDataService::resolve()` | `ProjectPackage reviewed_data / extracted_data` | merge priority chain | WIRED | resolveSourceTier() implements all 4 tiers |
| `resources/views/projects/show.blade.php` | `canonicalData` from controller | `$canonicalData` variable | WIRED | Used in Project Data tab panel |
| `resources/views/projects/index.blade.php` | `ProjectController::index()` | `?client=` param filter | WIRED | `->when($client, fn($q) => $q->where('client_name', $client))` on line 45 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `show.blade.php` Project Data tab | `$canonicalData` | `ProjectDataService::resolve()` | Yes — merges reviewed_data/extracted_data from ProjectPackage | FLOWING |
| `show.blade.php` Linked Records | `$linkedRecords` | Eager-loaded relationships in `show()` | Yes — real Eloquent collections from DB | FLOWING |
| `index.blade.php` client dropdown | `$clients` | `Project::query()->distinct()->pluck('client_name')` | Yes — real DB query | FLOWING |

### Behavioral Spot-Checks

Step 7b: SKIPPED (PHP not available in bash environment; server must be running for HTTP checks)

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| PROJ-01 | 01-01 | User can create a project with name, client, site address, and quote reference | BLOCKED | `quote_reference` field missing from create form and validation |
| PROJ-02 | 01-01 | All system records linked via project_id | SATISFIED | Module tables have project_id; backfill migration confirms; all 4 module tables covered |
| PROJ-03 | 01-02 | User can view project dashboard showing all related records with status indicators | SATISFIED | Linked Records card with all 5 types; status badges; sidebar |
| PROJ-04 | 01-01, 01-04 | Project follows lifecycle state machine | PARTIAL | State machine correct; bidirectional transitions verified; but transition() still gated by ownership check instead of auth()->check() |
| PROJ-05 | 01-01 | Quote references support versioning (ABC123-01, -02) | PARTIAL | `quote_reference` column exists on projects table; model fillable updated; but field not on create form and not required |
| DATA-01 | 01-03 | ProjectDataService merges extracted/reviewed/survey data into canonical dataset | SATISFIED | Full implementation verified |
| DATA-02 | 01-03 | Canonical data structure: { project, equipment, rooms, activities, risks, survey_data } | SATISFIED | 9 keys returned: project, equipment, rooms, activities, risks, survey, programme, cables, meta |
| DATA-04 | 01-03 | Every data field carries source annotation (data_source, confidence) | SATISFIED | All collection items annotated by resolveEquipment, resolveRooms, resolveActivities, resolveRisks |
| DATA-05 | 01-03 | Merge priority: reviewed_data > survey_data > quotewerks_sql > extracted_data > defaults | SATISFIED | resolveSourceTier() implements all 4 tiers with correct confidence scores |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Http/Controllers/ProjectController.php` | 38 | `->forUser(auth()->id())` scope in index() | Blocker | Prevents PROJ-01/D-15 goal: shared project visibility |
| `app/Http/Controllers/ProjectController.php` | 57 | `Project::forUser(auth()->id())` in statusCounts | Blocker | statusCounts only reflects user's own projects |
| `app/Http/Controllers/ProjectController.php` | 102-105 | `abort_if(project->user_id !== auth()->id() ...)` in show() | Blocker | Non-owning users get 403 on project show pages |
| `app/Http/Controllers/ProjectController.php` | 201 | `$this->authorizeProject($project)` in transition() | Blocker | Non-owning users cannot advance/revert lifecycle |
| `resources/views/projects/create.blade.php` | — | `quote_reference` input field absent | Blocker | Users cannot enter quote reference at project creation |
| `app/Http/Controllers/ProjectController.php` | 82-88 | `store()` validation missing `quote_reference` | Blocker | Quote reference not required; PROJ-01/PROJ-05 not met |
| `tests/Feature/ProjectAutoAdvanceTest.php` | 54, 94 | `$service->importFromData()` — method does not exist | Blocker | Tests 1 and 2 will error; auto-create behaviour is untested |

### Human Verification Required

#### 1. Create Form Visual Accuracy (after gaps resolved)

**Test:** Visit `/projects/create` in a browser
**Expected:** "New Project" title; "Back to Projects" text link (not button) at top; form shows name, client name, site address, quote reference fields in 2-column grid; inline validation messages on submit without required fields; "Create Project" CTA button
**Why human:** UI layout, copy fidelity, and label/ID accessibility cannot be verified with grep

#### 2. Responsive Layout Collapse

**Test:** Resize browser to below 900px on `/projects/{id}`
**Expected:** Two-column grid (left content + right sidebar) collapses to single column
**Why human:** CSS media query behaviour requires a browser to render and test

#### 3. Alpine.js Tab Toggle

**Test:** Click "Overview" and "Project Data" tabs on `/projects/{id}`
**Expected:** Tab content switches without page reload; active tab has teal underline
**Why human:** JavaScript runtime behaviour requires browser testing

## Gaps Summary

Seven gaps block Phase 1 goal achievement. They fall into three root causes:

**Root cause 1 — Incomplete Plan 01-01 execution (Plan 01-01 SUMMARY.md flagged "PARTIAL"):**
The controller shared-visibility changes, similar-project warning, and `quote_reference` create form field/validation were never implemented. This leaves PROJ-01, PROJ-04, and PROJ-05 partially unsatisfied.

Gaps: 1 (quote_reference create form + validation), 2 (shared visibility / forUser scope), 3 (transition ownership check), 5 (similar-project warning), 6 (quote_reference not required in store()), 7 (search doesn't cover site_address)

**Root cause 2 — Feature test method mismatch:**
The auto-advance feature tests (Plan 01-04) call `QuoteImportService::importFromData()` which was never added to the service. The import() method requires an UploadedFile; the tests pass an array. Tests 1 and 2 of ProjectAutoAdvanceTest will throw BadMethodCallException at runtime.

Gap: 4 (auto-advance tests broken)

**What is working well:** ProjectDataService (DATA-01, DATA-02, DATA-04, DATA-05) is fully implemented and tested. The lifecycle state machine (TRANSITIONS_BACKWARD, canTransitionTo) is correct. The project show page Linked Records card, breadcrumb, sidebar, and Project Data tab are all wired and rendering.

---

_Verified: 2026-04-10_
_Verifier: Claude (gsd-verifier)_

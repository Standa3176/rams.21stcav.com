---
phase: 260525-pyu
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Policies/RamsDocumentPolicy.php
  - app/Policies/OmManualPolicy.php
  - app/Policies/ProjectPolicy.php
  - app/Policies/ProjectDrawingPolicy.php
  - app/Http/Controllers/RamsController.php
  - app/Http/Controllers/OmManualController.php
  - app/Http/Controllers/WorksheetController.php
  - app/Http/Controllers/CableScheduleController.php
  - app/Http/Controllers/SiteSurveyController.php
  - app/Http/Controllers/ProjectController.php
  - app/Http/Controllers/QuoteImportController.php
  - app/Http/Controllers/ProjectPackageReviewController.php
  - app/Http/Controllers/DocumentEditController.php
  - tests/Feature/OmManual/OmManualProjectLinkageTest.php
  - tests/Feature/Drawings/BoundPdfDownloadTest.php
  - tests/Feature/Drawings/ZipBundleDownloadTest.php
  - tests/Feature/Drawings/RackEditorEndpointsTest.php
  - tests/Feature/DocumentEdits/ShowChangeSetAuthAndPreviewTest.php
  - tests/Feature/DocumentEdits/ParseEndpointTest.php
  - tests/Feature/DocumentEdits/DocumentEditHardeningTest.php
  - tests/Feature/Projects/ActualHoursWidgetTest.php
  - tests/Feature/Authorization/SharedWorkspaceAccessTest.php
autonomous: true
requirements: []
must_haves:
  truths:
    - "A logged-in non-admin user (role=user) who is NOT the owner can download any RAMS PDF and DOCX without a 403"
    - "A logged-in non-admin non-owner can view, edit, regenerate, and delete any RAMS, O&M, Worksheet, Cable Schedule, Site Survey, and Drawing"
    - "A logged-in non-admin non-owner can view/edit/delete any Project and review/approve any quote package"
    - "Every listing page (RAMS, O&M, Worksheet, Cable Schedule, Site Survey, Projects) shows ALL records to any authenticated user, not just their own"
    - "AI document-edit chat threads on any document are accessible to any authenticated user (404 still returned for a non-existent document)"
    - "Genuinely administrative endpoints (restore, forceDestroy, admin worker/AI/user management) still 403 for non-admin users"
    - "Document rendering output is byte-identical — RamsRenderRegression canary stays green"
  artifacts:
    - path: "app/Policies/RamsDocumentPolicy.php"
      provides: "view/update/delete return true for any authenticated user"
      contains: "return true"
    - path: "app/Policies/OmManualPolicy.php"
      provides: "view/update/delete return true for any authenticated user"
      contains: "return true"
    - path: "app/Policies/ProjectPolicy.php"
      provides: "view/update/delete return true for any authenticated user"
      contains: "return true"
    - path: "app/Policies/ProjectDrawingPolicy.php"
      provides: "view/update/delete return true for any authenticated user"
      contains: "return true"
    - path: "tests/Feature/Authorization/SharedWorkspaceAccessTest.php"
      provides: "Regression proof: non-admin non-owner can access all document surfaces, admin-only endpoints still 403"
      min_lines: 80
  key_links:
    - from: "RamsController download/downloadPdf"
      to: "RamsDocumentPolicy::view"
      via: "$this->authorize('view', $rams)"
      pattern: "authorize\\('view'"
    - from: "DocumentEditController::authorizeDocument"
      to: "any authenticated user"
      via: "ownership 403 branch removed, 404-on-missing kept"
      pattern: "document_not_found"
---

<objective>
Relax authorization so ANY authenticated user has full access to every Projects function and document. This is the intended shared team workspace for the 3-person company.

Fixes a production bug: non-admin staff (zack, alison — role=user) get HTTP 403 "This action is unauthorized" when downloading RAMS PDF/DOCX, because all documents are owned by sonny (user 1) and the rule is owner-OR-admin.

Purpose: Make the platform behave as the shared workspace it is meant to be. Any logged-in user can view, download, create, edit, regenerate, and delete any document (RAMS, O&M, Worksheet, Cable Schedule, Site Survey, Drawing) and any project, and see ALL records in every listing. Genuinely administrative endpoints (restore / forceDestroy / admin panel) stay admin-only.

Output: Relaxed policies + neutralised inline ownership gates + un-scoped listing filters, with the existing 403-asserting test suite updated to the new shared-access behaviour and a new regression test proving the shared workspace.

NON-NEGOTIABLE CONSTRAINTS:
- Authorization-only. NO changes to rendering, storage paths (respect H-07 DocumentArtifactStorage), routes, migrations, AI behaviour, or any business logic.
- Do NOT touch the RamsRenderRegression byte-equivalence guard's behaviour — this change must not alter document output.
- Preserve all genuinely admin-only gates (restore, forceDestroy, admin/* controllers, WorkerMonitor, AI usage).
- The field-operations cluster (Commissioning, InstallProgramme, TaskAssignment/Photo/Status, TimeEntry) is OUT of scope — see <scope_boundary>.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md

# Existing decision lineage already in the codebase (someone began this relaxation):
#   ProjectController::show  → already `abort_unless(auth()->check(), 403)` with "D-15: Projects are shared across all authenticated users."
#   ProjectController::index → already un-scoped (shows all projects, no where('user_id'))
#   ProjectController::transition → already `abort_unless(auth()->check(), 403)` with "D-19".
# This plan FINISHES that relaxation consistently across all document surfaces.
# Reuse the same comment style: "Shared workspace: any authenticated user has full access."

<interfaces>
User model (app/Models/User.php):
```php
public function isAdmin(): bool { return $this->role === 'admin'; }
// Relationships used by listing methods: ramsDocuments(), omManuals(), worksheets() (HasMany)
```

Policy method signatures (all four policies, registered in AppServiceProvider::boot()):
```php
public function view(User $user, RamsDocument $rams): bool   // currently: $user->id === $rams->user_id || $user->isAdmin()
public function update(User $user, RamsDocument $rams): bool
public function delete(User $user, RamsDocument $rams): bool
// ProjectDrawingPolicy uses $drawing->generated_by instead of user_id
```

DocumentEditController ownership seam (app/Http/Controllers/DocumentEditController.php):
```php
private function authorizeDocument(Request $request, string $type, int $id): ?JsonResponse
// returns null = allowed; 401 unauthenticated; 404 document_not_found; 403 document_forbidden (THIS 403 must go)
private function ownerIdFor(string $type, int $id): ?int  // returns user_id or null when row missing — KEEP for the 404 path
```
</interfaces>
</context>

<scope_boundary>
## IN SCOPE — relax to "any authenticated user" (the shared workspace)

These are the "Projects functions and documents" named in the goal: RAMS, O&M Manuals, Worksheets, Cable Schedules, Site Surveys, Drawings, the Project itself, quote import / package review, and the AI document-edit chat surface for those documents.

## OUT OF SCOPE — DO NOT TOUCH (preserve current behaviour)

1. **Field-operations cluster** — these use an owner-OR-admin-OR-**assigned-engineer** model (a third, broader branch already), are a distinct install/commissioning/time-tracking concern, and are NOT part of "Projects functions and documents" as enumerated in the goal:
   - `CommissioningController`, `CommissioningItemController`, `CommissioningResyncController`, `CommissioningSignoffController`
   - `InstallProgrammeController`, `TaskAssignmentController`, `TaskPhotoController`, `TaskStatusController`
   - `TimeEntryController` (+ `TimeEntryService`)
   - Their tests (`tests/Feature/Commissioning/*`, `tests/Feature/InstallTasks/*`, `tests/Feature/TimeEntries/*`, `tests/Feature/FieldView/*`, `tests/Unit/Services/TimeEntryServiceTest.php`) MUST stay green unchanged.
   - **If the user later wants these relaxed too, that is a follow-up — flag it in the summary, do not assume it.**

2. **Genuinely admin-only endpoints** — keep `abort_unless(auth()->user()->isAdmin(), 403)` exactly as-is:
   - `ProjectController::restore` (~385), `forceDestroy` (~399)
   - `OmManualController::restore` (~490), `forceDestroy` (~500)
   - `CableScheduleController::restore` (~348), `forceDestroy` (~358)
   - `SiteSurveyController` admin gates (~423, ~433)
   - `RamsController` admin gates (~704, ~723, ~1011)
   - `app/Http/Controllers/Admin/*` (UserController, AIUsageController, SolutionTypeController) — entirely untouched
   - `WorkerMonitorController` — all `isAdmin` gates untouched

3. **HazardTemplate** personal/global split (`HazardTemplateController:131` per-user template ownership) — separate concept, leave as-is.

4. **Public token-gated routes** — `PublicSurveyController`, `PublicWorksheetController`, `SurveyController` public flows, `SurveyVariationController` — token/auth-check based, unrelated, do not touch.

5. **`show_deleted` / trashed listings** — the `$isAdmin && $request->boolean('show_deleted')` branches that surface soft-deleted rows stay admin-only (viewing the trash is administrative). Only the NON-deleted listing is un-scoped.
</scope_boundary>

<tasks>

<task type="auto">
  <name>Task 1: Relax the four resource policies to any-authenticated-user</name>
  <files>
    app/Policies/RamsDocumentPolicy.php
    app/Policies/OmManualPolicy.php
    app/Policies/ProjectPolicy.php
    app/Policies/ProjectDrawingPolicy.php
  </files>
  <action>
    In all four policy classes, change every `view`, `update`, and `delete` method body to `return true;`.

    These methods are only ever reached behind the `auth` middleware (all document/project routes require authentication), so `return true` is equivalent to "any authenticated user". The framework never invokes a policy for a guest on these routes.

    For each method, replace the owner-OR-admin expression with `return true;` and update the one-line docblock above it to reflect shared access. Keep the typed signatures and `use` imports exactly as-is (the `$user` / model params stay for the framework contract even though unused).

    Specific replacements:
    - RamsDocumentPolicy::view/update/delete — replace `return $user->id === $rams->user_id || $user->isAdmin();` with `return true;`
    - OmManualPolicy::view/update/delete — replace `return $user->id === $manual->user_id || $user->isAdmin();` with `return true;`
    - ProjectPolicy::view/update/delete — replace `return $user->id === $project->user_id || $user->isAdmin();` with `return true;`
    - ProjectDrawingPolicy::view/update/delete — replace `return $user->id === $drawing->generated_by || $user->isAdmin();` with `return true;`

    Update each docblock comment from "Allow the document owner OR any admin to ..." to "Shared team workspace: any authenticated user may ... (auth middleware guarantees a logged-in user here)."

    Do NOT add new methods, do NOT register anything new, do NOT change AppServiceProvider. The policies are already registered.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && vendor\bin\pint --test app/Policies/RamsDocumentPolicy.php app/Policies/OmManualPolicy.php app/Policies/ProjectPolicy.php app/Policies/ProjectDrawingPolicy.php && php -l app/Policies/RamsDocumentPolicy.php</automated>
  </verify>
  <done>All 12 policy methods (4 classes x view/update/delete) return true. Pint clean, no syntax errors. No other policy logic changed.</done>
</task>

<task type="auto">
  <name>Task 2: Neutralise inline ownership gates across document controllers (preserve admin-only gates)</name>
  <files>
    app/Http/Controllers/RamsController.php
    app/Http/Controllers/OmManualController.php
    app/Http/Controllers/WorksheetController.php
    app/Http/Controllers/CableScheduleController.php
    app/Http/Controllers/SiteSurveyController.php
    app/Http/Controllers/ProjectController.php
    app/Http/Controllers/QuoteImportController.php
    app/Http/Controllers/ProjectPackageReviewController.php
    app/Http/Controllers/DocumentEditController.php
  </files>
  <action>
    Replace every owner-OR-admin (and owner-ONLY) inline gate on IN-SCOPE document/project actions with an authenticated-user check. Pattern: change the ownership predicate so the action proceeds for any logged-in user, while keeping the route's auth requirement intact.

    Preferred minimal-diff approach: where the controller already runs behind `auth` middleware, replace the ownership `abort_if(...)` / `abort_unless(...)` line with `abort_unless(auth()->check(), 403);` and a comment `// Shared workspace: any authenticated user has full access.` This keeps a guard in place (defensive) while removing the owner restriction — and matches the existing D-15/D-19 style already in ProjectController.

    For private helper methods (`authorizeProject`, `authorizePackage`, `authorizeSurvey`), change the helper body to `abort_unless(auth()->check(), 403);` — this relaxes every call site at once (minimal diff, single source).

    EXACT edits per file:

    **RamsController.php**
    - Line ~134: the create() pre-fill ownership check `if ($candidate->user_id !== auth()->id() && ! auth()->user()->isAdmin()) { abort(403, ...); }` — remove the ownership condition; keep `$project = $candidate;` unconditionally. (Any authed user may pre-fill from any project.)
    - Line ~234 `generateFromProject`: replace `abort_if($project->user_id !== auth()->id() && auth()->user()?->role !== 'admin', 403);` with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - PRESERVE lines ~704, ~723, ~1011 (`abort_unless(auth()->user()?->isAdmin(), 403)` / `isAdmin()`) — admin-only, do not touch.
    - The policy-based methods (view/update/delete/download/etc.) need NO change here — Task 1 already relaxed the policy.

    **OmManualController.php**
    - Lines ~129 (storeFromProject), ~155 (generateFromProject), ~217 (status), ~237 (retryGeneration): replace each `abort_if($x->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);` with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - PRESERVE ~492 (restore), ~502 (forceDestroy) — admin-only.

    **WorksheetController.php**
    - Lines ~78 (show — note: actual is `$worksheet->user_id`), ~100 (generateFromProject — `$project->user_id`), ~138 (status), ~165 (download), ~201 (retryGeneration), ~227 (destroy): replace each `abort_if(... user_id !== auth()->id() && ! auth()->user()->isAdmin(), 403);` block with `abort_unless(auth()->check(), 403); // Shared workspace`.

    **CableScheduleController.php**
    - Lines ~112 (edit) and ~183 (update): these are owner-ONLY `abort_unless($cableSchedule->user_id === auth()->id(), 403);` (NO admin escape) — replace with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - Lines ~333 (destroy), ~378 (generateFromProject), ~410 (status), ~433 (download), ~472 (retryGeneration): replace each ownership gate with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - PRESERVE ~348 (restore), ~358 (forceDestroy) — admin-only.

    **SiteSurveyController.php**
    - Private helper `authorizeSurvey()` (~591): change body to `abort_unless(auth()->check(), 403); // Shared workspace` — relaxes show/edit/update/confirmRooms/etc. at once.
    - Line ~92 (store ownership-when-project block): remove the ownership condition / make it a no-op (any authed user may create a survey on any project). Simplest: delete the `abort_if(...)` inside the `if (! empty($data['project_id']))` block, or replace with `abort_unless(auth()->check(), 403);`.
    - Lines ~124 (createFromProject), ~166 (supersedeFromProject): replace with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - Line ~306 (projectData): replace `abort_unless($project->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);` with `abort_unless(auth()->check(), 403); // Shared workspace`.
    - PRESERVE ~423, ~433 (admin-only). Leave the public/token room-scope `abort_unless($room->site_survey_id === ...)` checks (~364, ~449) untouched — they are integrity checks, not ownership.

    **ProjectController.php**
    - Private helper `authorizeProject()` (~412-417): change body to `abort_unless(auth()->check(), 403); // Shared workspace` — relaxes edit/update/archive/reopen/destroy at once.
    - Line ~234 is in RamsController, not here. Within ProjectController, `show` (~117) and `transition` (~299) are ALREADY relaxed — leave them.
    - PRESERVE ~385 (restore), ~399 (forceDestroy) — admin-only.

    **QuoteImportController.php**
    - Private helper `authorizePackage()` (~234-240): change body to `abort_unless(auth()->check(), 403); // Shared workspace`.
    - Listing/lookup owner-scoping (`->where('user_id', auth()->id())` at ~28, ~105 forUser scope, ~132 project lookup): handled in Task 3 — but the ~132 `Project::where('id', ...)->where('user_id', auth()->id())->firstOrFail()` must drop the `->where('user_id', auth()->id())` so any authed user can reassign a package to any project. Make that change here in Task 2 alongside the helper (it is an authorization gate, not a listing).

    **ProjectPackageReviewController.php**
    - Private helper `authorizePackage()` (~909-915): change body to `abort_unless(auth()->check(), 403); // Shared workspace`. This relaxes all 7 call sites (review show/save/approve/etc.).

    **DocumentEditController.php**
    - `authorizeDocument()` (~512-528): KEEP the 401 unauthenticated branch and the 404 `document_not_found` branch (driven by `ownerIdFor()` returning null). REMOVE the 403 `document_forbidden` branch entirely (the `if (! $isAdmin && (int) $ownerId !== (int) $user->id) { return ...403; }` block). Keep `ownerIdFor()` because it is what detects a missing document for the 404. Net effect: any authenticated user may open/parse/apply edit threads on any existing document; a non-existent document still 404s.

    GLOBAL RULES for this task:
    - Do NOT change any `where('user_id', auth()->id())` LISTING filter here — those are Task 3 (except QuoteImport ~132 noted above, which is an authorization-style gate on a reassignment lookup).
    - Do NOT touch any out-of-scope controller (see scope_boundary).
    - Do NOT touch storage paths, rendering, validation rules, or job dispatch.
    - Preserve every `isAdmin()` admin-only gate listed above verbatim.
    - Keep `use` imports and method signatures intact.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && php -l app/Http/Controllers/RamsController.php && php -l app/Http/Controllers/OmManualController.php && php -l app/Http/Controllers/WorksheetController.php && php -l app/Http/Controllers/CableScheduleController.php && php -l app/Http/Controllers/SiteSurveyController.php && php -l app/Http/Controllers/ProjectController.php && php -l app/Http/Controllers/QuoteImportController.php && php -l app/Http/Controllers/ProjectPackageReviewController.php && php -l app/Http/Controllers/DocumentEditController.php && vendor\bin\pint --test app/Http/Controllers</automated>
  </verify>
  <done>
    All in-scope inline ownership gates relaxed to `auth()->check()`; DocumentEditController 403 ownership branch removed (404/401 preserved); all admin-only `isAdmin()` gates preserved verbatim; out-of-scope controllers untouched. No syntax errors, Pint clean.
  </done>
</task>

<task type="auto">
  <name>Task 3: Un-scope listing/dropdown filters so every authenticated user sees all records</name>
  <files>
    app/Http/Controllers/RamsController.php
    app/Http/Controllers/OmManualController.php
    app/Http/Controllers/WorksheetController.php
    app/Http/Controllers/CableScheduleController.php
    app/Http/Controllers/SiteSurveyController.php
    app/Http/Controllers/QuoteImportController.php
  </files>
  <action>
    Remove the owner-scoping from every IN-SCOPE listing and dropdown query so any authenticated user sees ALL records. Keep the `show_deleted`/trashed branches admin-only (those stay as-is).

    EXACT edits:

    **RamsController::index (~57-64)** — the non-admin branch uses `auth()->user()->ramsDocuments()->with('omManual')`. Change so BOTH branches query all records. Simplest minimal diff: set `$query = RamsDocument::query()->with(['user', 'omManual']);` for the main listing regardless of admin, and for the trashed branch keep `RamsDocument::onlyTrashed()...` gated behind `$showTrashed` (which already requires `$isAdmin`). Keep `$isAdmin` computed (the Blade uses it for the show-deleted toggle and the user column). Net: every authed user sees all RAMS in the normal list; only admins can toggle trashed.

    **OmManualController::index (~43-45)** — non-admin branch `auth()->user()->omManuals()->with('project')`. Change the main listing to `OmManual::with(['user', 'project'])->latest()->paginate(15)` for everyone. Keep the `$showDeleted` (admin-only) trashed branch as-is.

    **OmManualController::create (~56)** — `Project::forUser(auth()->id())` dropdown. Change to `Project::query()` (all projects) — `Project::orderByDesc('updated_at')->get([...])`. Drop the `forUser` scope call here.

    **WorksheetController::index (~59-61)** — non-admin branch `auth()->user()->worksheets()->with('project')`. Change main listing to `Worksheet::with('project')->latest()->paginate(15)` for everyone. Keep `$isAdmin` for the Blade.

    **CableScheduleController::index (~43)** — `CableSchedule::where('user_id', auth()->id())`. Change to `CableSchedule::query()->withCount('items')->latest()->paginate(15)` (drop the `where('user_id', ...)`). Keep the admin-only `show_deleted` trashed branch as-is.

    **SiteSurveyController::index (~39)** — `SiteSurvey::where('user_id', auth()->id())`. Drop the `where('user_id', ...)` so it lists all surveys. Keep the admin-only trashed branch.
    **SiteSurveyController::create (~68)**, **createFromProject (~137)**, **edit (~265)** — `Project::where('user_id', auth()->id())` dropdowns. Drop the `->where('user_id', auth()->id())` in all three so the project dropdown lists all projects.

    **QuoteImportController::index (~28) and ~105** — `Project::forUser(auth()->id())` dropdowns. Change to `Project::query()->orderByDesc('updated_at')->get([...])` (drop `forUser`). (The ~132 reassignment lookup was already handled in Task 2.)

    Also in **OmManualController** there is a `storeFromProject`/`store` project lookup at ~89 `Project::where('id', $projectId)->where('user_id', auth()->id())->firstOrFail()` — drop the `->where('user_id', auth()->id())` so any authed user can upload an O&M for any project.
    And at **~66** the `create()` selected-project lookup `Project::where('id', $selectedProjectId)->where('user_id', auth()->id())` — drop the `->where('user_id', auth()->id())`.

    DO NOT remove the `Project::forUser` SCOPE METHOD from the model — it may be used elsewhere; only stop calling it in these in-scope dropdowns. (Verify with a grep before deleting anything from the model — do not delete it.)

    DO NOT touch out-of-scope listings (Commissioning/InstallProgramme/TimeEntry use `->where('user_id', ...)` for their own assigned-engineer model — leave them).
    DO NOT change ProjectController::index (already un-scoped).
    Keep all `paginate()`, `with()`, `withCount()`, `latest()` and ordering exactly as the original except for the removed `user_id` filter — no other query behaviour changes.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && php -l app/Http/Controllers/RamsController.php && php -l app/Http/Controllers/OmManualController.php && php -l app/Http/Controllers/WorksheetController.php && php -l app/Http/Controllers/CableScheduleController.php && php -l app/Http/Controllers/SiteSurveyController.php && php -l app/Http/Controllers/QuoteImportController.php && vendor\bin\pint --test app/Http/Controllers</automated>
  </verify>
  <done>
    Every in-scope listing/dropdown query lists ALL records (no `where('user_id', auth()->id())` and no `forUser(auth()->id())` on in-scope surfaces). Admin-only trashed/show_deleted branches preserved. Out-of-scope listings untouched. No syntax errors, Pint clean.
  </done>
</task>

<task type="auto">
  <name>Task 4: Update the existing 403-asserting tests to the new shared-access behaviour + add a shared-workspace regression test</name>
  <files>
    tests/Feature/OmManual/OmManualProjectLinkageTest.php
    tests/Feature/Drawings/BoundPdfDownloadTest.php
    tests/Feature/Drawings/ZipBundleDownloadTest.php
    tests/Feature/Drawings/RackEditorEndpointsTest.php
    tests/Feature/DocumentEdits/ShowChangeSetAuthAndPreviewTest.php
    tests/Feature/DocumentEdits/ParseEndpointTest.php
    tests/Feature/DocumentEdits/DocumentEditHardeningTest.php
    tests/Feature/Projects/ActualHoursWidgetTest.php
    tests/Feature/Authorization/SharedWorkspaceAccessTest.php
  </files>
  <action>
    Update the IN-SCOPE tests that assert non-owner 403 to assert the NEW shared-access behaviour, and ADD a focused regression test. Do NOT touch out-of-scope tests (Commissioning, InstallTasks, TimeEntries, FieldView, TimeEntryServiceTest).

    For each test below, the previous expectation was "non-owner/intruder/other-user gets 403". Flip it to "non-owner non-admin can now access (200 / OK / expected JSON), AND a 401/404 still behaves correctly where applicable". Where the test name encodes the old behaviour (e.g. `test_non_owner_gets_403`), rename it to reflect the new contract (e.g. `test_non_owner_can_download_shared_bound_pdf`) and update assertions.

    **tests/Feature/OmManual/OmManualProjectLinkageTest.php**
    - `test_om_edit_page_is_forbidden_for_another_user` (~248): rename to `test_om_edit_page_is_accessible_to_any_authenticated_user`; change `$response->assertForbidden();` to `$response->assertOk();` (or `assertSuccessful()` — match how the owner-access test below it asserts).
    - `test_om_download_is_forbidden_for_another_user` (~261): rename to `..._accessible_to_any_authenticated_user`; the `$other` user download should now succeed. Mirror the owner-download test's assertion (likely a streamed/binary download — assert `assertOk()` / `assertDownload()` consistent with the sibling owner test). Verify by reading the matching owner-success test in the same file and copying its assertion shape.

    **tests/Feature/Drawings/BoundPdfDownloadTest.php**
    - `test_non_owner_gets_403` (~170): rename to `test_non_owner_can_download_shared_bound_pdf`; the `$intruder` request should now return the PDF. Replace `$response->assertForbidden();` with the same success assertions used by the owner test (`test_owner_can_download_bound_pdf_when_ready_drawings_exist`) — assert 200 and PDF magic header.

    **tests/Feature/Drawings/ZipBundleDownloadTest.php**
    - `test_non_owner_gets_403` (~259): rename to `test_non_owner_can_download_shared_bundle`; replace `assertForbidden()` with the success assertions from the owner bundle test (200 + ZIP entries).

    **tests/Feature/Drawings/RackEditorEndpointsTest.php**
    - `test_edit_page_403s_for_non_owner_non_admin` (~113): rename to `test_edit_page_accessible_to_any_authenticated_user`; replace `assertForbidden()` with `assertOk()` (match the owner edit-page success test).
    - `test_flip_rack_mounted_403s_for_non_owner` (~428): rename to `test_flip_rack_mounted_allowed_for_any_authenticated_user`; the device flag SHOULD now change. Replace `assertForbidden()` + `assertNull($device->is_rack_mounted, '...unchanged on 403')` with a success assertion (200 + `assertTrue`/`assertSame` that the flag was updated, mirroring the owner-success flip test).

    **tests/Feature/DocumentEdits/ShowChangeSetAuthAndPreviewTest.php**
    - `test_non_owner_returns_403` (~80): rename to `test_non_owner_can_view_shared_change_set`; the intruder GET should now return 200 with the change-set JSON. Replace `->assertStatus(403)->assertJsonPath('error', 'document_forbidden')` with the success assertion the owner path uses (200 + the change-set payload). KEEP any separate unauthenticated→401 and missing-doc→404 tests unchanged.

    **tests/Feature/DocumentEdits/ParseEndpointTest.php**
    - `test_non_owner_gets_403` (~189): rename to `test_non_owner_can_parse_on_shared_document`; replace the 403/`document_forbidden` assertion with the success assertion the owner parse test uses. KEEP unrelated 401/404 tests.

    **tests/Feature/DocumentEdits/DocumentEditHardeningTest.php**
    - `test_other_users_cannot_open_a_thread_on_someone_elses_worksheet` (~38): rename to `test_any_authenticated_user_can_open_a_thread_on_a_shared_worksheet`; replace 403/`document_forbidden` with the open-thread success assertion (mirror the owner open-thread test).
    - `test_revisions_view_forbidden_for_other_user` (~142): rename to `test_revisions_view_accessible_to_any_authenticated_user`; replace `assertStatus(403)` with the owner success assertion.
    - KEEP the admin-bypass test and any 401/404/validation tests unchanged.

    **tests/Feature/Projects/ActualHoursWidgetTest.php**
    - `test_non_owner_non_admin_does_not_see_widget` (~58): the actual-hours widget visibility is controlled by `ProjectController::show $canSeeActualHours = $project->user_id === auth()->id() || isAdmin()`. DECISION: the goal says "relax so all authed users see actual hours" (prior_investigation note on line 248). So the widget SHOULD now be visible to any authenticated user. Update this test: the stranger now sees the widget → rename to `test_any_authenticated_user_sees_actual_hours_widget` and assert the widget IS present (mirror the owner-sees-widget assertion). ALSO update `ProjectController::show` line ~248: change `$canSeeActualHours = $project->user_id === auth()->id() || auth()->user()?->isAdmin();` to `$canSeeActualHours = auth()->check();` with comment `// Shared workspace: all authenticated users see actual hours.` (This is the one remaining in-scope gate not covered in Task 2 — make this small controller edit here since it is tightly coupled to this test.)

    **NEW: tests/Feature/Authorization/SharedWorkspaceAccessTest.php**
    Create a focused regression test (uses RefreshDatabase) proving the shared workspace end-to-end. Cover:
    1. `test_non_admin_non_owner_can_download_rams_pdf` — owner (user 1) creates a RamsDocument with a generated file (fake the `documents` disk via `Storage::fake('documents')` per the H-07 test pattern; seed a file at the readPath); a second `role=user` user downloads via `rams.download` and `rams.download-pdf` → 200, not 403. THIS IS THE EXACT PRODUCTION BUG — it must pass.
    2. `test_non_admin_non_owner_can_view_and_delete_rams` — second user GETs the RAMS show/review and can DELETE it → not 403.
    3. `test_non_admin_non_owner_can_access_om_worksheet_cable_survey` — parametrise or repeat: a non-owner role=user can hit the show/edit/download routes for an OmManual, Worksheet, CableSchedule, SiteSurvey owned by user 1 without a 403.
    4. `test_listings_show_all_records_to_any_authenticated_user` — user 1 owns a RAMS; user 2 (role=user) GETs `rams.index` and the response contains user-1's record (assertSee the project_name or ref).
    5. `test_admin_only_endpoints_still_forbid_non_admin` — a role=user user hitting `projects.restore` / `projects.force-destroy` (or the cable/om restore routes) → 403. Confirms the preserved admin gates.
    6. `test_guest_is_redirected_to_login` — an unauthenticated request to `rams.index` → redirect to login (302), proving auth middleware still guards everything.

    Use `User::factory()->create(['role' => 'user'])` for the non-admin actor and `['role' => 'admin']` only where an admin is needed. Model the factory/fixture setup on the existing tests in tests/Feature/Rams and tests/Feature/OmManual (read those for the established `makeProject` / `makeManual` / RamsDocument creation helpers and route names). Respect H-07: never hand-build storage paths in the test — use `app(DocumentArtifactStorage::class)->writePath(...)` with `Storage::fake('documents')` to place the downloadable file, mirroring DocumentArtifactStorageTest.

    Do NOT modify RamsRenderRegressionTest or any structural/byte-equivalence guard — they must keep passing untouched.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && php artisan test --filter="SharedWorkspaceAccess|OmManualProjectLinkage|BoundPdfDownload|ZipBundleDownload|RackEditorEndpoints|ShowChangeSetAuthAndPreview|ParseEndpoint|DocumentEditHardening|ActualHoursWidget"</automated>
  </verify>
  <done>
    All updated in-scope tests pass asserting shared access; new SharedWorkspaceAccessTest passes all 6 cases (including the exact production-bug repro: non-admin non-owner downloads RAMS PDF → 200, and admin-only endpoints still 403). Out-of-scope tests untouched.
  </done>
</task>

<task type="auto">
  <name>Task 5: Full-suite regression gate — confirm no breakage and byte-equivalence preserved</name>
  <files>
    (no source files — verification task)
  </files>
  <action>
    Run the full test suite to confirm:
    - All previously-green tests still pass (out-of-scope Commissioning/InstallTasks/TimeEntries still owner-or-admin-or-assigned).
    - The RamsRenderRegression byte-equivalence canary is still green (authorization-only change must not alter rendering).
    - The structural/dead-path guards (Phase22_1InvariantGuard, DeadPathRemovalGuard, V13SurfacesUntouched, CableScheduleXlsxRegression) are still green.
    - No test still asserts the OLD owner-403 behaviour on an in-scope surface.

    If any in-scope test outside Task 4's list fails because it asserted the old 403 behaviour, update it to the shared-access expectation following the same flip pattern (rename + success assertion) and document it in the summary. If any OUT-OF-SCOPE test fails, that is a regression in this change — investigate and fix the code (do not change the out-of-scope test's intent).

    Then run Laravel Pint across the touched files to ensure PSR-12 compliance.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && php artisan test && vendor\bin\pint --test app/Policies app/Http/Controllers tests/Feature/Authorization</automated>
  </verify>
  <done>
    Full suite green. RamsRenderRegression + all structural guards still pass (byte-equivalence preserved). No in-scope test asserts owner-403 anymore. Out-of-scope tests unchanged and green. Pint clean across all touched files.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| guest → app | Unauthenticated request crosses the `auth` middleware. MUST still be rejected (redirect to login). |
| authenticated user → any document | After this change, ANY logged-in user may read/write ANY in-scope document. This is the INTENDED model for a 3-person shared workspace. |
| authenticated user → admin endpoint | Non-admin authed user MUST still be blocked from restore/forceDestroy/admin panel. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-pyu-01 | Elevation of Privilege | Guest accessing documents | mitigate | `auth` middleware unchanged; all relaxed gates still require `auth()->check()`; Task 4 test #6 proves guest → login redirect. |
| T-pyu-02 | Elevation of Privilege | Non-admin reaching admin-only endpoints (restore/forceDestroy/admin/*) | mitigate | All `isAdmin()` gates preserved verbatim (explicitly listed in scope_boundary + Task 2); Task 4 test #5 proves non-admin → 403. |
| T-pyu-03 | Information Disclosure | Cross-tenant data leak between unrelated companies | accept | Single-tenant internal app for ONE 3-person company; shared workspace is the explicit business requirement. No multi-tenant boundary exists. |
| T-pyu-04 | Tampering | Render output changes as a side effect of auth refactor | mitigate | Authorization-only edits; no rendering/storage/path code touched; Task 5 confirms RamsRenderRegression byte-equivalence canary stays green. |
| T-pyu-05 | Tampering | Cross-project FK / path-traversal guards weakened | accept | Those guards (CableSchedule cross-project FK injection, ZIP basename, room-scope integrity checks) are NOT ownership gates and are explicitly left untouched. |
</threat_model>

<verification>
- `php artisan test` — full suite green, including RamsRenderRegression + all structural guards.
- Production-bug repro (SharedWorkspaceAccessTest #1): a `role=user` non-owner downloads a RAMS PDF and DOCX owned by user 1 → HTTP 200, NOT 403.
- Admin-only endpoints (SharedWorkspaceAccessTest #5): `role=user` → 403 on restore/forceDestroy.
- Guest (SharedWorkspaceAccessTest #6): unauthenticated → redirect to login.
- `vendor\bin\pint --test` clean on all touched files.
- Grep sanity: no in-scope controller method still contains an owner-`user_id`-comparison authorization gate (only the preserved `isAdmin()` admin gates and out-of-scope/integrity checks remain).
</verification>

<success_criteria>
- Any authenticated user (incl. role=user, non-owner) can view, download, create, edit, regenerate, and delete any RAMS, O&M, Worksheet, Cable Schedule, Site Survey, Drawing, and any Project; and review/approve any quote package; and open AI edit threads on any document.
- Every in-scope listing shows ALL records to any authenticated user.
- The original 403-on-RAMS-download bug for zack/alison is fixed (proven by automated test).
- Admin-only endpoints (restore, forceDestroy, admin panel, worker monitor, AI usage) remain admin-only.
- Field-operations cluster (Commissioning/InstallProgramme/TaskAssignment/TaskPhoto/TaskStatus/TimeEntry) and public token routes are unchanged.
- Document rendering is byte-identical (RamsRenderRegression green).
- Full test suite passes; Pint clean.
</success_criteria>

<output>
After completion, create `.planning/quick/260525-pyu-relax-authorization-so-any-authenticated/260525-pyu-SUMMARY.md`.

In the summary, explicitly flag for the user:
- Whether they want the OUT-OF-SCOPE field-operations cluster (Commissioning / Install Programme / Task assignment+photo+status / Time Entry) ALSO relaxed to any-authenticated-user. It was deliberately left as owner-OR-admin-OR-assigned-engineer because it is install/commissioning/time-tracking, not a "Projects document". If the shared-workspace intent extends there, it is a quick follow-up.
- That the `Project::forUser()` model scope was left in place (only call sites on in-scope dropdowns were un-scoped) in case it is still referenced elsewhere.
</output>

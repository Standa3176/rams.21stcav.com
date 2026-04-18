---
quick_task: 260413-qxb
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/RamsController.php
  - app/Http/Requests/RamsFormRequest.php
  - resources/views/rams/create.blade.php
  - resources/views/projects/show.blade.php
autonomous: true
must_haves:
  truths:
    - "From a project with no package, clicking '+ Create RAMS' opens the RAMS form pre-filled with project data"
    - "The resulting RAMS document has project_id set to the originating project"
    - "The RAMS form works correctly without a project_id (standalone creation path unchanged)"
    - "Validation failure on the RAMS form repopulates all pre-filled values including project_id"
  artifacts:
    - path: "app/Http/Controllers/RamsController.php"
      provides: "create() accepts ?project_id, store() links RAMS to project"
    - path: "app/Http/Requests/RamsFormRequest.php"
      provides: "project_id validated as nullable|integer|exists:projects,id"
    - path: "resources/views/rams/create.blade.php"
      provides: "Section A inputs pre-filled from $project, hidden project_id field"
    - path: "resources/views/projects/show.blade.php"
      provides: "No-package fallback replaced with link to rams.create?project_id={id}"
  key_links:
    - from: "projects/show.blade.php @else fallback (line ~503)"
      to: "route('rams.create', ['project_id' => $project->id])"
      via: "anchor tag replacing span"
    - from: "RamsController::store()"
      to: "ramsDocument->project_id"
      via: "validated project_id written after create()"
---

<objective>
Wire the RAMS manual-create form to projects that have no ProjectPackage.

Purpose: Projects without a reviewed quote package currently dead-end with "Upload quote in Quote History" — but the Project model already holds the key fields (name, client_name, site_address, works_description, ref). The buildFromForm() path already generates a full RAMS from typed input. This task connects those two things.

Output: Four targeted file edits. No new files, no schema changes, no new routes needed (rams.create route already exists).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.planning/PROJECT.md

Key facts confirmed from file reads:
- RamsController::create() returns a View, compact()-passes hazard/PPE/provider vars — no $project yet
- RamsController::store() creates RamsDocument then calls buildFromForm($validated, $ramsDocument) — no project_id link
- RamsFormRequest has no project_id rule — must add
- create.blade.php Section A: all value= attributes use old('field') with no fallback — safe to extend
- projects/show.blade.php @else at line 503: bare span, inside the header action div
- projects/show.blade.php empty-state @else at line 522: bare text, no link
- RamsDocument::$project_id column exists (Phase 01)
- No imports to add for Project model — RamsController already uses Project (generateFromProject method at line 183)
</context>

<tasks>

<task type="auto">
  <name>Task 1: RamsController create() + store() + RamsFormRequest</name>
  <files>app/Http/Controllers/RamsController.php, app/Http/Requests/RamsFormRequest.php</files>
  <action>
THREE changes across two files:

**1a. RamsFormRequest — add project_id rule**

In `app/Http/Requests/RamsFormRequest.php`, inside the `rules()` array, add after `'doc_author'`:

```php
// Project linkage (optional — set when creating from project show page)
'project_id' => ['nullable', 'integer', 'exists:projects,id'],
```

**1b. RamsController::create() — accept project_id, pass $project to view**

Replace the method signature and return statement of `create()`:

Current signature (line 77):
```php
public function create(): View
```

New signature:
```php
public function create(): View
```
(signature unchanged, return type stays View)

Inside the method body, before the `return view(...)` call (after the hazardLibrary block, around line 129), add:

```php
// ── Project pre-fill (optional) ──────────────────────────────
$project = null;
if ($projectId = request()->query('project_id')) {
    $candidate = \App\Models\Project::find((int) $projectId);
    if ($candidate) {
        if ($candidate->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            abort(403, 'You do not own this project.');
        }
        $project = $candidate;
    }
}
```

Then update the `compact()` call to include `$project`:

```php
return view('rams.create', compact(
    'hazardLibrary',
    'ppeOptions',
    'personsOptions',
    'providers',
    'defaultProvider',
    'hazardTemplates',
    'project',
));
```

**1c. RamsController::store() — link RAMS to project after create**

After the `RamsDocument::create([...])` block (after line 160, before the try block), add:

```php
// Link to originating project if supplied
if (! empty($validated['project_id'])) {
    $ramsDocument->project_id = (int) $validated['project_id'];
    $ramsDocument->save();
}
```

Note: Do NOT touch `generateFromProject()` — that is a separate path that must remain unchanged.

The `isAdmin()` check uses the existing `auth()->user()->isAdmin()` helper. Confirm this method exists on the User model before writing — if it does not, use `auth()->user()->role === 'admin'` or whatever the project uses. Check `app/Models/User.php` first.
  </action>
  <verify>
    Run: `php artisan route:list | grep rams.create`
    Then manually visit: `/rams/create?project_id=1` (use a real project ID from the DB)
    The page should load without error. If the project exists and belongs to the user, $project is non-null.
    Also confirm: `php artisan test --filter=RamsFormRequest` if any tests exist.
  </verify>
  <done>
    - create() passes $project (nullable) to the view without error for both ?project_id= and no query param
    - store() sets ramsDocument->project_id when project_id is in validated data
    - RamsFormRequest accepts project_id as nullable integer
  </done>
</task>

<task type="auto">
  <name>Task 2: Pre-fill Section A fields in rams/create.blade.php</name>
  <files>resources/views/rams/create.blade.php</files>
  <action>
Four targeted changes in the Blade view:

**2a. Page title — show project name when present**

Line 3 (`@section('title', 'Create RAMS Document')`):
```blade
@section('title', $project ? 'Create RAMS — ' . $project->name : 'Create RAMS Document')
```

Line 8 (page-title h1):
```blade
<h1 class="page-title">
    {{ $project ? 'Create RAMS — ' . $project->name : 'Create RAMS Document' }}
</h1>
```

**2b. Hidden project_id field**

Immediately after the `@csrf` line (line 35), add:
```blade
<input type="hidden" name="project_id" value="{{ old('project_id', $project?->id) }}">
```

**2c. Pre-fill Section A inputs**

Update the `value=` attributes for the five pre-fillable fields. Use `old(field) ?? $project?->field ?? ''` pattern:

- `project_ref` (line ~50): `value="{{ old('project_ref') ?? $project?->ref ?? '' }}"`
- `project_name` (line ~65): `value="{{ old('project_name') ?? $project?->name ?? '' }}"`
- `client_name` (line ~80): `value="{{ old('client_name') ?? $project?->client_name ?? '' }}"`
- `site_address` textarea (line ~109): `{{ old('site_address') ?? $project?->site_address ?? '' }}`

For `works_description` — locate the textarea for works_description further down in the file (it will be in Section A or B). Apply the same pattern: `{{ old('works_description') ?? $project?->works_description ?? '' }}`

`site_contact` has no corresponding column on Project, leave it as `old('site_contact')`.

Note on old() nullsafe interaction: `old('field')` returns null when not in a validation-fail context, so the `??` chain correctly falls through to `$project?->field`. On validation fail, `old('field')` returns the submitted value (including the empty string), so the project fallback is intentionally skipped.
  </action>
  <verify>
    Visit `/rams/create?project_id={id}` in browser.
    Project Reference, Project Name, Client Name, Site Address, Works Description should all be pre-filled with the project's data.
    Submit the form with an error (e.g., remove a required hazard checkbox) — on return, fields should retain the submitted values (not revert to project data), and the hidden project_id should be preserved.
  </verify>
  <done>
    - Section A fields pre-filled when ?project_id is set
    - Page title shows project name
    - Hidden project_id field present and repopulated on validation fail
    - Standalone /rams/create (no query param) still works with blank fields
  </done>
</task>

<task type="auto">
  <name>Task 3: Replace dead-end fallback in projects/show.blade.php</name>
  <files>resources/views/projects/show.blade.php</files>
  <action>
Two targeted replacements in the RAMS Documents section:

**3a. Header action fallback (line ~503)**

Find (inside the `@else` at the end of the header action chain, around line 502–503):
```blade
@else
    <span style="font-size:.78rem; color:#888;">Upload quote in Quote History</span>
@endif
```

Replace the span with a link:
```blade
@else
    <a href="{{ route('rams.create', ['project_id' => $project->id]) }}"
       class="btn btn-teal btn-sm" style="font-size:.78rem;">+ Create RAMS</a>
@endif
```

**3b. Empty-state paragraph fallback (line ~522)**

Find (inside `@if ($project->ramsDocuments->isEmpty())`, the final `@else` branch):
```blade
@else
    Upload a quote in Quote History to enable RAMS generation.
@endif
```

Replace with:
```blade
@else
    <a href="{{ route('rams.create', ['project_id' => $project->id]) }}"
       style="color:var(--teal);">Create a RAMS manually</a> using the project details.
@endif
```

Do not modify any other branches in either conditional block. The reviewed-package path, awaiting-review path, generating path, has-completed path, and has-package-but-unreviewed path must all remain exactly as they are.
  </action>
  <verify>
    Navigate to a project that has no packages (or create/find one via DB).
    The RAMS Documents header should show a teal "+ Create RAMS" button (not the grey span).
    The empty-state paragraph (when no RAMS exist) should show a "Create a RAMS manually" link.
    Click each link — both should go to `/rams/create?project_id={id}`.
    Navigate to a project that DOES have a reviewed package — verify the existing form-POST buttons are unchanged.
  </verify>
  <done>
    - No-package projects show "+" Create RAMS" teal button in header
    - Empty-state shows "Create a RAMS manually" link
    - All other RAMS action branches on projects with packages are unchanged
    - Both links route to rams.create with correct project_id query param
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Query param → controller | project_id arrives as untrusted user input in the URL |
| Hidden form field → store() | project_id travels through the form — user could tamper it |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-qxb-01 | Elevation of Privilege | create() project_id query param | mitigate | Ownership check: abort 403 if project->user_id !== auth()->id() and not admin |
| T-qxb-02 | Elevation of Privilege | store() hidden project_id field | mitigate | RamsFormRequest validates exists:projects,id; store() only links — never grants access to the project itself; the RAMS is owned by the creating user |
| T-qxb-03 | Tampering | store() project_id tampered to another user's project | accept | Linking a RAMS to another project's ID does not expose that project's data; the RAMS content comes from the form, not the project record; low risk |
</threat_model>

<verification>
Full flow smoke test:
1. Find or create a project with no packages (`projects` table, `id` = X, user_id = your user)
2. Visit `/projects/X` — RAMS section should show `+ Create RAMS` button
3. Click it — lands on `/rams/create?project_id=X` with Section A pre-filled
4. Submit with all required fields — RAMS should generate and redirect to review page
5. Check DB: `select project_id from rams_documents order by id desc limit 1` — should equal X
6. Visit `/projects/X` — the new RAMS should appear in the list
7. Visit `/rams/create` (no query param) — blank form, no errors
</verification>

<success_criteria>
- Projects with no package show a teal "+ Create RAMS" button (not a grey dead-end span)
- The RAMS create form pre-fills project_ref, project_name, client_name, site_address, works_description from the project
- The resulting RamsDocument has project_id correctly set
- Validation failures repopulate all fields including hidden project_id
- Standalone RAMS creation (no project_id) is unaffected
- No changes to generateFromProject() or the reviewed-package flow
</success_criteria>

<output>
After completion, create `.planning/quick/260413-qxb-pre-fill-rams-create-form-from-project-d/260413-qxb-SUMMARY.md` with:
- What was changed and why
- Any deviations from plan (e.g., isAdmin() method name)
- Verification result
</output>

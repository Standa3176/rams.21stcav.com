---
quick_task: 260413-qxb
phase: quick
plan: qxb
subsystem: RAMS / Projects
tags: [rams, projects, pre-fill, form, ux]
key-files:
  modified:
    - app/Http/Controllers/RamsController.php
    - app/Http/Requests/RamsFormRequest.php
    - resources/views/rams/create.blade.php
    - resources/views/projects/show.blade.php
decisions:
  - "isAdmin() method confirmed present on User model — used directly as per plan"
  - "Edits applied to worktree path (.claude/worktrees/agent-a810c169/rams.21stcav.com/) not main repo"
metrics:
  completed: 2026-04-13
  tasks: 3
  commits: 3
---

# Quick Task 260413-qxb: Pre-fill RAMS Create Form from Project Data

**One-liner:** Wired `rams.create?project_id=` flow so no-package projects pre-fill Section A fields and link the resulting RamsDocument back to the project.

## What Was Changed

### Task 1 — RamsController + RamsFormRequest

**`app/Http/Requests/RamsFormRequest.php`**
Added `project_id` validation rule at the end of `rules()`:
```php
'project_id' => ['nullable', 'integer', 'exists:projects,id'],
```

**`app/Http/Controllers/RamsController.php` — `create()`**
Added project pre-fill block before `return view(...)`:
- Reads `?project_id` query param
- Calls `Project::find()` with ownership check (403 if not owner and not admin)
- Passes `$project` (nullable) to the view via `compact()`

**`app/Http/Controllers/RamsController.php` — `store()`**
Added project linkage after `RamsDocument::create()`:
```php
if (! empty($validated['project_id'])) {
    $ramsDocument->project_id = (int) $validated['project_id'];
    $ramsDocument->save();
}
```

### Task 2 — rams/create.blade.php Section A pre-fill

- `@section('title')` updated to show project name when `$project` is set
- `<h1>` updated with same conditional
- Hidden `<input type="hidden" name="project_id">` added immediately after `@csrf`
- `project_ref`, `project_name`, `client_name` inputs: `value` changed to `old('field') ?? $project?->field ?? ''`
- `site_address`, `works_description` textareas: content changed to `old('field') ?? $project?->field ?? ''`
- `site_contact` left as `old('site_contact')` — no matching column on Project
- Standalone `/rams/create` (no query param) still works — `$project` is null so all fallbacks produce `''`

### Task 3 — projects/show.blade.php dead-end fallbacks replaced

**Header action `@else` (no package):**
- Before: `<span style="...">Upload quote in Quote History</span>`
- After: `<a href="{{ route('rams.create', ['project_id' => $project->id]) }}" class="btn btn-teal btn-sm">+ Create RAMS</a>`

**Empty-state `@else` (no package, no RAMS):**
- Before: `Upload a quote in Quote History to enable RAMS generation.`
- After: `<a href="{{ route('rams.create', ['project_id' => $project->id]) }}" style="color:var(--teal);">Create a RAMS manually</a> using the project details.`

All other branches in both conditionals are unchanged.

## Deviations from Plan

**1. Worktree path confusion (Rule 3 — blocking issue)**
- **Found during:** Task 1 commit
- **Issue:** Initial edits landed in the main repo (`rams.21stcav.com/`) rather than the worktree (`worktrees/agent-a810c169/rams.21stcav.com/`). The worktree has a `rams.21stcav.com` subdirectory, so the correct file paths differ from the working directory.
- **Fix:** Reverted main repo changes via `git checkout --`, re-applied all edits to the correct worktree paths, committed from worktree root.
- **No functional impact** — all committed changes are in the worktree branch.

**2. `isAdmin()` method confirmed (no deviation)**
- Plan noted to check `app/Models/User.php` for `isAdmin()` — confirmed present at line 45. Used directly as instructed.

## Commits

| Hash | Message |
|------|---------|
| eaecb29 | feat(260413-qxb): wire project_id into RamsController create/store and RamsFormRequest |
| 7cdb7be | feat(260413-qxb): pre-fill Section A fields in rams/create from project data |
| a5e50e3 | feat(260413-qxb): replace dead-end fallbacks in projects/show with Create RAMS links |

## Verification Notes

Full smoke test steps (from plan):
1. Find/create project with no packages — RAMS section shows `+ Create RAMS` teal button
2. Click — lands on `/rams/create?project_id=X` with Section A pre-filled
3. Submit — RAMS generates and redirects to review page
4. DB check: `select project_id from rams_documents order by id desc limit 1` — equals X
5. Visit `/projects/X` — new RAMS appears in list
6. Visit `/rams/create` (no query param) — blank form, no errors

## Self-Check

### Files exist:
- [x] `rams.21stcav.com/app/Http/Controllers/RamsController.php` — modified in worktree
- [x] `rams.21stcav.com/app/Http/Requests/RamsFormRequest.php` — modified in worktree
- [x] `rams.21stcav.com/resources/views/rams/create.blade.php` — modified in worktree
- [x] `rams.21stcav.com/resources/views/projects/show.blade.php` — modified in worktree

### Commits exist:
- [x] eaecb29 — Task 1
- [x] 7cdb7be — Task 2
- [x] a5e50e3 — Task 3

## Self-Check: PASSED

---
quick_id: 260504-eji
type: execute
description: Sticky Save/Cancel action bar at the top of all editable forms.
files_modified:
  - resources/views/layouts/app.blade.php
  - resources/views/components/edit-action-bar.blade.php
  - resources/views/site-survey/edit.blade.php
  - resources/views/projects/edit.blade.php
  - resources/views/rams/review.blade.php
  - resources/views/om-manual/edit.blade.php
  - resources/views/om-manual/edit-devices.blade.php
autonomous: true
must_haves:
  truths:
    - "On every editable form page, a Save and Cancel pair is visible at the top of the viewport without scrolling."
    - "The Save button submits the page's primary edit form (NOT secondary forms like regen / email)."
    - "The Cancel button returns the user to the appropriate show page for that record."
    - "When the user scrolls the form, the action bar stays pinned just below the fixed app header."
    - "Existing in-form Save buttons continue to work — both submit the same form."
    - "On mobile (<768px), the bar still shows the Save and Cancel buttons; the title text hides to save space."
    - "No JavaScript is required for the bar to function (HTML5 `form=` attribute does the work)."
  artifacts:
    - path: "resources/views/layouts/app.blade.php"
      provides: ".edit-action-bar / .edit-action-bar__title / .edit-action-bar__actions CSS rules in the existing <style> block"
      contains: ".edit-action-bar"
    - path: "resources/views/components/edit-action-bar.blade.php"
      provides: "Reusable Blade component accepting formId, cancelUrl, saveLabel, cancelLabel and an optional title slot"
    - path: "resources/views/site-survey/edit.blade.php"
      provides: "Sticky bar wired to form id=survey-form; legacy .page-header Back/Save buttons removed"
    - path: "resources/views/projects/edit.blade.php"
      provides: "Sticky bar wired to form id=project-edit-form (ID added to existing <form>)"
    - path: "resources/views/rams/review.blade.php"
      provides: "Sticky bar wired to id=rams-review-form (added to the rams.update-and-download form at line 344 only); secondary regen / hidden regen-after-save / email forms left untouched"
    - path: "resources/views/om-manual/edit.blade.php"
      provides: "Sticky bar wired to id=om-manual-edit-form (added to the om-manuals.update form at line 81); separate generate form left alone"
    - path: "resources/views/om-manual/edit-devices.blade.php"
      provides: "Sticky bar wired to id=om-manual-devices-form (added to the om-manuals.update-devices form at line 50)"
  key_links:
    - from: "<x-edit-action-bar form-id=...>"
      to: "<form id=...>"
      via: "HTML5 button[form] attribute submits a form by ID across the DOM"
      pattern: "form=\"\\{\\{ \\$formId \\}\\}\""
    - from: ".edit-action-bar"
      to: ":root --header-height (64px) in layouts/app.blade.php"
      via: "position: sticky; top: var(--header-height);"
      pattern: "top: var\\(--header-height\\)"
---

<objective>
Add a single sticky Save/Cancel action bar that appears at the top of every editable form page, just below the fixed app header. Pure presentation work — one shared CSS ruleset + one reusable Blade component, dropped into 5 target views.

Purpose: Reduces scroll fatigue on long edit pages (RAMS review = 1041 lines, site-survey edit = thousands of lines with rooms). PMs and engineers no longer have to scroll to the bottom to save.

Output: 1 layout edit + 1 new Blade component + 5 view edits = 7 files. No controllers, routes, services, JS behavior, or schema touched. The Save button submits via HTML5 `form="{id}"` attribute — zero JavaScript required.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@resources/views/layouts/app.blade.php
@resources/views/components/document-edit-drawer.blade.php
@resources/views/site-survey/edit.blade.php
@resources/views/projects/edit.blade.php
@resources/views/rams/review.blade.php
@resources/views/om-manual/edit.blade.php
@resources/views/om-manual/edit-devices.blade.php
</context>

<scope_boundaries>
- IN scope: 1 CSS ruleset, 1 Blade component, 5 view edits adding the bar at the top of each editable form.
- OUT of scope: worksheets/show.blade.php (READ-ONLY view; verified via grep — no `<form method=` for editing exists; only download/back buttons + sign-off display). Document this skip.
- OUT of scope: public engineer survey wizard `/survey/{token}` (already has its own footer-stuck nav).
- OUT of scope: secondary forms inside the same view — regen / regen-after-save / email on RAMS review (lines 242, 267, 1027); generate form on om-manual edit (line 101). These keep their own buttons; only the primary edit form gets the bar.
- OUT of scope: any JS behavior, validation changes, controller / route / service / schema changes.
- Existing in-form Save buttons stay (engineers may still hit them mid-form; both submit the same form).
</scope_boundaries>

<layout_facts>
Confirmed from reading layouts/app.blade.php:
- `--header-height` = 64px (line 62)
- `.page-wrap` padding = `1.75rem 2rem` (line 377)
- Mobile `.page-wrap` padding = `1.25rem 1rem` (line 1227)
- Existing z-indexes: `.app-header` 200, `.user-dropdown` 300, `.app-sidebar` 100, `.sidebar-overlay` 99, `.chat-drawer-backdrop` 998, `.chat-drawer` 999, `.mobile-tab-bar` 150
- Sticky bar at z-index 90 sits below sidebar (100) and app-header (200) — confirmed safe.
- Negative margins `-1.75rem -2rem 1.5rem` (desktop) and `-1.25rem -1rem 1rem` (mobile, <768px) cancel out `.page-wrap` padding so the bar bleeds full-width.
</layout_facts>

<tasks>

<task type="auto">
  <name>Task 1: Add .edit-action-bar CSS + create reusable Blade component</name>
  <files>resources/views/layouts/app.blade.php, resources/views/components/edit-action-bar.blade.php</files>
  <action>
Two atomic edits, both in the same task because they're tightly coupled (component depends on CSS).

EDIT 1 — `resources/views/layouts/app.blade.php`:
Inside the existing `<style>...</style>` block (which ends at line 1245), add a new ruleset AFTER the `.form-section` block (which ends ~line 831) and BEFORE the `.room-subsection` block (~line 838). Insert this block as a new section header banner:

```css
        /* ═══════════════════════════════════════════════════════════════
           EDIT ACTION BAR — sticky Save/Cancel under the app header.
           Used on every editable form page via <x-edit-action-bar/>.
           Negative margins counter .page-wrap padding for full-bleed.
        ═══════════════════════════════════════════════════════════════ */
        .edit-action-bar {
            position: sticky;
            top: var(--header-height);
            z-index: 90;
            background: var(--surface);
            border-bottom: 1px solid var(--ink-200);
            box-shadow: 0 2px 6px rgba(15, 23, 42, .04);
            padding: .65rem 1.5rem;
            margin: -1.75rem -2rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .edit-action-bar__title {
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--ink-900);
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .edit-action-bar__actions {
            display: flex;
            gap: .5rem;
            flex-shrink: 0;
        }
```

Then ALSO add the mobile override inside the EXISTING `@media (max-width: 768px)` block at line 1226 (just append these two rules; do not create a new media query):

```css
            .edit-action-bar         { padding: .55rem .9rem; margin: -1.25rem -1rem 1rem; }
            .edit-action-bar__title  { display: none; }
```

(Note: mobile `.page-wrap` padding is `1.25rem 1rem`, so use `-1.25rem -1rem`, NOT `-1rem -1rem`. This matches what was already in the integration_points hint adjusted for verified `.page-wrap` mobile padding.)

EDIT 2 — Create new file `resources/views/components/edit-action-bar.blade.php`:

```blade
@props([
    'formId'      => null,
    'cancelUrl'   => null,
    'saveLabel'   => 'Save Changes',
    'cancelLabel' => 'Cancel',
])

{{--
    Sticky Save/Cancel bar. Sits flush below the fixed .app-header on every
    editable form page.

    The Save button uses the HTML5 `form="..."` attribute so it can submit a
    form by ID even though it lives OUTSIDE the form element. No JavaScript
    needed. If $formId is omitted, no Save button renders (defensive).

    The optional named slot {{ $title }} renders inside .edit-action-bar__title
    and is hidden on screens narrower than 768px to save room for the buttons.
--}}
<div class="edit-action-bar" role="region" aria-label="Edit actions">
    @isset($title)
        <div class="edit-action-bar__title">{{ $title }}</div>
    @endisset
    <div class="edit-action-bar__actions">
        <a href="{{ $cancelUrl ?? url()->previous() }}" class="btn btn-outline btn-sm">{{ $cancelLabel }}</a>
        @if($formId)
            <button type="submit" form="{{ $formId }}" class="btn btn-teal btn-sm">{{ $saveLabel }}</button>
        @endif
    </div>
</div>
```

Constraints:
- Pure CSS / Blade. No JS. No new design tokens (reuses `--header-height`, `--surface`, `--ink-200`, `--ink-900`, `--font-display`).
- Existing `.btn`, `.btn-teal`, `.btn-outline`, `.btn-sm` classes are already defined in app.blade.php — re-use, don't redefine.
- z-index 90 confirmed safe vs all existing z-indexes (sidebar 100, header 200).
  </action>
  <verify>
    <automated>
test -f resources/views/components/edit-action-bar.blade.php \
  && grep -q "\.edit-action-bar" resources/views/layouts/app.blade.php \
  && grep -q "edit-action-bar__actions" resources/views/layouts/app.blade.php \
  && grep -q "position: sticky" resources/views/layouts/app.blade.php \
  && grep -q "form=\"{{ \\\$formId }}\"" resources/views/components/edit-action-bar.blade.php \
  && php artisan view:clear \
  && echo OK
    </automated>
  </verify>
  <done>
- `.edit-action-bar` ruleset present in app.blade.php inside the existing `<style>` block.
- Mobile override added inside the existing `@media (max-width: 768px)` block.
- New component file `resources/views/components/edit-action-bar.blade.php` exists with `@props([...])` head and a `<button type="submit" form="{{ $formId }}" ...>`.
- `php artisan view:clear` runs clean (no Blade syntax errors).
  </done>
</task>

<task type="auto">
  <name>Task 2: Inject bar into 3 simpler views — projects/edit, om-manual/edit, om-manual/edit-devices</name>
  <files>resources/views/projects/edit.blade.php, resources/views/om-manual/edit.blade.php, resources/views/om-manual/edit-devices.blade.php</files>
  <action>
Three small Blade edits — each adds an `id` to the existing primary edit form, then drops the component at the top of `@section('content')`.

EDIT A — `resources/views/projects/edit.blade.php`:

1. At line 17, change `<form method="POST" action="{{ route('projects.update', $project) }}">` to:
   `<form method="POST" action="{{ route('projects.update', $project) }}" id="project-edit-form">`

2. Immediately after `@section('content')` (line 5), BEFORE the existing `<div class="page-header">`, insert:
```blade
<x-edit-action-bar :form-id="'project-edit-form'" :cancel-url="route('projects.show', $project)">
    <x-slot:title>Edit Project — {{ $project->name }}</x-slot:title>
</x-edit-action-bar>
```

3. Leave the existing `.page-header`, the inline `<div style="display:flex; gap:.75rem; margin-top:.5rem;">` Save/Cancel block, and everything else exactly as is. Both bars submit the same form — that's intentional.

EDIT B — `resources/views/om-manual/edit.blade.php`:

1. At line 81, change `<form method="POST" action="{{ route('om-manuals.update', $manual) }}">` to:
   `<form method="POST" action="{{ route('om-manuals.update', $manual) }}" id="om-manual-edit-form">`

2. Immediately after `@section('content')` opens (or right at the start of the page-content area — read the file to find the precise position; insert it BEFORE any existing page-header element so it appears at the very top of the rendered content), insert:
```blade
<x-edit-action-bar :form-id="'om-manual-edit-form'" :cancel-url="route('om-manuals.index')">
    <x-slot:title>Edit O&amp;M Manual — {{ $manual->project_name ?? $manual->title ?? 'Untitled' }}</x-slot:title>
</x-edit-action-bar>
```

3. CRITICAL — DO NOT add `id` to the generate form at line 101 (`om-manuals.generate`). It MUST NOT receive the action bar's submit. Leave its existing standalone `<button>Generate Document</button>` exactly as is.

EDIT C — `resources/views/om-manual/edit-devices.blade.php`:

1. At line 50, change `<form method="POST" action="{{ route('om-manuals.update-devices', $manual) }}">` to:
   `<form method="POST" action="{{ route('om-manuals.update-devices', $manual) }}" id="om-manual-devices-form">`

2. Immediately after `@section('content')` opens, BEFORE the existing page-content, insert:
```blade
<x-edit-action-bar :form-id="'om-manual-devices-form'" :cancel-url="route('om-manuals.edit', $manual)">
    <x-slot:title>Edit Devices — {{ $manual->project_name ?? $manual->title ?? 'Untitled' }}</x-slot:title>
</x-edit-action-bar>
```

3. The empty-state `@if ($devices->isEmpty())` branch (line 43) renders no form — that's fine; the bar will still render but its Save button will silently no-op (form ID doesn't match anything visible). Acceptable: if the user opens edit-devices on an empty project, they shouldn't be saving anyway. Don't conditionally hide the bar — keeps the markup simple and consistent.

Per-view defensive notes:
- If a view's existing `@section('content')` already starts with a wrapper `<div>`, drop the `<x-edit-action-bar>` BEFORE that wrapper so the negative margins bleed against `.page-wrap` padding directly, not against a nested div's padding.
- Use kebab-case props (`:form-id`, `:cancel-url`) — Laravel auto-camels them to `$formId` / `$cancelUrl` inside the component.
- Title slot uses `<x-slot:title>` short-tag syntax (Laravel 12 / Blade — already used elsewhere in this codebase per the project conventions).
  </action>
  <verify>
    <automated>
grep -q 'id="project-edit-form"' resources/views/projects/edit.blade.php \
  && grep -q 'id="om-manual-edit-form"' resources/views/om-manual/edit.blade.php \
  && grep -q 'id="om-manual-devices-form"' resources/views/om-manual/edit-devices.blade.php \
  && grep -c '<x-edit-action-bar' resources/views/projects/edit.blade.php resources/views/om-manual/edit.blade.php resources/views/om-manual/edit-devices.blade.php \
  && php artisan view:clear \
  && echo OK
    </automated>
  </verify>
  <done>
- All 3 target forms now have a stable `id` attribute.
- All 3 views render `<x-edit-action-bar>` at the top of `@section('content')`.
- The om-manual generate form (line 101) is UNTOUCHED.
- `php artisan view:clear` succeeds; no Blade compile errors.
  </done>
</task>

<task type="auto">
  <name>Task 3: Inject bar into 2 complex views — site-survey/edit + rams/review (defensive form-ID isolation)</name>
  <files>resources/views/site-survey/edit.blade.php, resources/views/rams/review.blade.php</files>
  <action>
Two Blade edits — both views have multiple forms, so the form-ID hookup MUST go on the PRIMARY edit form ONLY.

EDIT A — `resources/views/site-survey/edit.blade.php`:

1. At line 25, the form already has `id="survey-form"`. NO change to the form tag — it's pre-wired.

2. REPLACE the existing legacy `.page-header` block (lines 7–12) — currently:
```blade
<div class="page-header">
    <h1 class="page-title">Edit Survey</h1>
    <div style="display:flex;gap:.5rem;">
        <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">&#8592; Back</a>
    </div>
</div>
```
…with the sticky bar (which provides BOTH the title and the Cancel/Save pair):
```blade
<x-edit-action-bar :form-id="'survey-form'" :cancel-url="route('site-surveys.show', $survey)">
    <x-slot:title>Edit Survey — {{ $survey->project_name }}</x-slot:title>
</x-edit-action-bar>
```

3. Leave the `@if ($errors->any())` alert block (lines 14–21) exactly where it is, BELOW the new bar. Validation errors stay highly visible.

4. Leave any in-form Save button at the bottom of the page UNTOUCHED. Both submit `survey-form`.

EDIT B — `resources/views/rams/review.blade.php`:

1. At line 344, change `<form method="POST" action="{{ route('rams.update-and-download', $rams) }}">` to:
   `<form method="POST" action="{{ route('rams.update-and-download', $rams) }}" id="rams-review-form">`

2. CRITICAL — confirmed via grep:
   - Line 242: `rams.regenerate` form (visible regen button) — DO NOT touch.
   - Line 267: `id="rams-regen-after-save"` hidden form — DO NOT touch (already has its own ID).
   - Line 1027: `rams.email` form — DO NOT touch.
   These three secondary forms must NOT receive the sticky bar's submit. Only the form at line 344 gets `id="rams-review-form"`.

3. Read the view's top to find where `@section('content')` opens (the file uses `@push('styles')` then `@section('content')`). Insert the sticky bar AFTER `@section('content')` opens, BEFORE the existing `.rams-hero` block (which is the current top-of-page hero):
```blade
<x-edit-action-bar :form-id="'rams-review-form'" :cancel-url="route('rams.index')">
    <x-slot:title>Review RAMS — {{ $rams->project_name }}</x-slot:title>
</x-edit-action-bar>
```

4. Leave the `.rams-hero-actions` block (regen + history + edit-via-chat buttons) UNTOUCHED — it's a different conceptual zone (document actions, not form save).

5. Leave any in-form Save button(s) deeper in the page UNTOUCHED. Both submit `rams-review-form`.

Defensive cross-checks (the executor should run these):
- After editing rams/review, run: `grep -c 'id="rams-review-form"' resources/views/rams/review.blade.php` → expect exactly 1 match.
- After editing rams/review, run: `grep -c '<form\s' resources/views/rams/review.blade.php` → expect 4 matches (lines 242, 267, 344, 1027 unchanged in count).
- After editing site-survey/edit, run: `grep -c 'class="page-header"' resources/views/site-survey/edit.blade.php` → expect 0 (we removed it).
  </action>
  <verify>
    <automated>
test "$(grep -c 'id="rams-review-form"' resources/views/rams/review.blade.php)" = "1" \
  && test "$(grep -c '<x-edit-action-bar' resources/views/rams/review.blade.php)" = "1" \
  && test "$(grep -c '<x-edit-action-bar' resources/views/site-survey/edit.blade.php)" = "1" \
  && grep -q 'id="survey-form"' resources/views/site-survey/edit.blade.php \
  && ! grep -q 'class="page-header"' resources/views/site-survey/edit.blade.php \
  && grep -q 'rams.update-and-download' resources/views/rams/review.blade.php \
  && grep -q 'rams.regenerate' resources/views/rams/review.blade.php \
  && grep -q 'rams.email' resources/views/rams/review.blade.php \
  && php artisan view:clear \
  && echo OK
    </automated>
  </verify>
  <done>
- site-survey/edit.blade.php: legacy `.page-header` block removed; sticky bar in its place; existing `id="survey-form"` on form unchanged.
- rams/review.blade.php: form at line 344 now has `id="rams-review-form"`; sticky bar added at top of `@section('content')`; regen / regen-after-save / email forms verified unchanged.
- Both views compile clean (`php artisan view:clear` succeeds).
- All four RAMS forms still post to their original routes (verified by grep).
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
Sticky Save/Cancel action bar on 5 editable form pages:
- /projects/{id}/edit
- /site-surveys/{id}/edit
- /rams/{id}/review
- /om-manuals/{id}/edit
- /om-manuals/{id}/edit-devices

The bar appears at the top of each page, just below the fixed app header, and stays pinned when you scroll. The Save button submits the page's primary edit form via HTML5 `form="..."` attribute (no JavaScript). Cancel returns to the appropriate show/index page.

`/worksheets/{id}` was inspected and confirmed read-only (no editable form), so it was intentionally skipped.
  </what-built>
  <how-to-verify>
1. **Desktop sanity sweep** — open each of the 5 pages in turn and confirm:
   - Sticky bar visible at the top, just below the app header.
   - Title text reads correctly (e.g. "Edit Survey — {project name}").
   - Cancel button returns you to the right show/index page (NOT a 404).
   - Scroll the page — bar stays pinned, doesn't overlap the app header, doesn't get hidden behind the sidebar.
   - Click Save — form submits; the page reloads or redirects as expected (same behavior as the existing in-form Save button).

2. **Multi-form isolation check (RAMS review)** — on `/rams/{id}/review`:
   - The sticky bar's Save button must submit ONLY the main edit-and-download form.
   - The "↻ Regenerate" button (rams-hero-actions area) still works.
   - The "Email RAMS" button at the bottom still works.
   - The hidden regen-after-save form is untouched (verifiable via DevTools → look for `<form id="rams-regen-after-save" ...>` in DOM).

3. **OM-manual isolation check** — on `/om-manuals/{id}/edit`:
   - The sticky bar Save submits the JSON edit form (NOT the Generate form).
   - The "Generate Document" button further down the page still works.

4. **Mobile check** (resize browser to <768px or use DevTools mobile preview):
   - Title text hides; Save and Cancel buttons remain visible.
   - Bar stays pinned to the top while scrolling.

5. **No regressions** — all existing in-form Save buttons (at the bottom of each form) still work and submit the same form. They are intentionally NOT removed.

6. **Read-only worksheet** — visit `/worksheets/{id}` and confirm NO sticky bar appears (this is correct — worksheets are read-only and were skipped).
  </how-to-verify>
  <resume-signal>Type "approved" to mark done, or describe any issue (e.g. "bar overlaps sidebar on iPad", "Save on RAMS triggered email form").</resume-signal>
</task>

</tasks>

<verification>
- `php artisan view:clear` runs clean after each task.
- `git diff app/ routes/ database/ config/ public/` returns empty (CSS/Blade only — no PHP, JS, schema).
- All 6 editable forms still post to their original routes (verified via grep on `action="{{ route(...)`).
- 4 secondary forms (RAMS regen, regen-after-save hidden, RAMS email, om-manual generate) verified untouched.
- Sticky bar uses only existing design tokens — no new colour / shadow / font values.
</verification>

<success_criteria>
- 7 files modified (1 layout + 1 new component + 5 views).
- All 5 editable form pages render the sticky bar at the top.
- Save submits the primary form on each page; secondary forms unaffected.
- Cancel returns to the right show/index page.
- Mobile: title hides, buttons stay.
- Existing in-form Save buttons still work.
- `worksheets/show.blade.php` intentionally skipped (read-only — no editable form).
- Zero JS / controller / route / service / schema changes.
- `git diff app/ routes/ database/ config/` is empty.
</success_criteria>

<output>
After completion, create `.planning/quick/260504-eji-sticky-save-cancel-action-bar-at-top-of-/260504-eji-SUMMARY.md` documenting:
- Files changed (7) with one-line description each.
- Confirmation that worksheets/show was inspected and skipped (read-only — no editable form).
- Note on RAMS review's 4-form isolation (only the main edit-and-download form got the ID; regen / regen-after-save / email forms left alone).
- List of files for the user to upload to live (per project convention "local-edit-then-upload").
- Manual UAT outcome from the checkpoint task.
</output>

---
quick_id: 260504-eji
type: execute
status: needs-uat
description: Sticky Save/Cancel action bar at the top of all editable forms.
completed_date: 2026-05-04
duration_min: 18
commits:
  - e546982 feat(260504-eji): add sticky edit-action-bar CSS + reusable Blade component
  - eb0206c feat(260504-eji): wire sticky bar into projects/edit + om-manual/edit + om-manual/edit-devices
  - d632cf4 feat(260504-eji): wire sticky bar into site-survey/edit + rams/review (defensive form-ID isolation)
files_changed: 7
lines_delta: "+89/-10"
artifact_kind: presentation
---

# Quick 260504-eji: Sticky Save/Cancel Action Bar — Summary

**One-liner:** Pure CSS + Blade sticky Save/Cancel bar pinned below the app header on 5 editable form pages, using HTML5 `form="..."` attribute to submit forms by ID with zero JavaScript.

## Files Changed (7)

| # | File | Change |
|---|------|--------|
| 1 | `resources/views/layouts/app.blade.php` | Added `.edit-action-bar` ruleset (sticky, `top: var(--header-height)`, z-index 90, `--surface` bg, full-bleed via negative margins) inside the existing `<style>` block, plus mobile override (`<768px`: tighter padding, title hidden) inside the existing media query. +37 lines. |
| 2 | `resources/views/components/edit-action-bar.blade.php` | NEW — reusable component with props `formId`, `cancelUrl`, `saveLabel`, `cancelLabel` and optional `$title` slot. Save button uses `<button type="submit" form="{{ $formId }}">` so it can submit a form that lives elsewhere in the DOM. +29 lines. |
| 3 | `resources/views/projects/edit.blade.php` | Added `id="project-edit-form"` to update form; sticky bar at top of `@section('content')` above existing `.page-header` (which is left intact). |
| 4 | `resources/views/om-manual/edit.blade.php` | Added `id="om-manual-edit-form"` to `om-manuals.update` form (line 81); sticky bar at top of content area. **Generate form (`om-manuals.generate`) is intentionally untouched** so the sticky Save can never accidentally trigger document generation. |
| 5 | `resources/views/om-manual/edit-devices.blade.php` | Added `id="om-manual-devices-form"` to `om-manuals.update-devices` form (line 50); sticky bar at top of content area. |
| 6 | `resources/views/site-survey/edit.blade.php` | Legacy `.page-header` block removed; sticky bar in its place. Existing `id="survey-form"` on form was already pre-wired. |
| 7 | `resources/views/rams/review.blade.php` | Added `id="rams-review-form"` to `rams.update-and-download` form (line 344) ONLY; sticky bar inserted before `.rams-hero` block. |

## RAMS review.blade.php — 4-form isolation (verified)

This view has 4 forms. Only the primary edit form receives the new ID:

| Line (post-edit) | Form action | New ID? | Status |
|---|---|---|---|
| 246 | `rams.regenerate` (visible regen button) | ❌ | UNCHANGED |
| 271 | `rams.regenerate` (hidden `id="rams-regen-after-save"`) | ❌ pre-existing ID kept | UNCHANGED |
| 348 | `rams.update-and-download` (primary edit) | ✅ `id="rams-review-form"` | **wired to sticky bar** |
| 1031 | `rams.email` | ❌ | UNCHANGED |

The sticky Save can ONLY submit the form at line 348. Regen button, hidden regen-after-save mechanism, and email form retain their original behaviour.

## OM-manual edit.blade.php — 2-form isolation (verified)

| Line (post-edit) | Form action | New ID? | Status |
|---|---|---|---|
| 85 | `om-manuals.update` | ✅ `id="om-manual-edit-form"` | **wired to sticky bar** |
| 105 | `om-manuals.generate` | ❌ | UNCHANGED — keeps own button |

## Skipped views (deliberate)

- **`resources/views/worksheets/show.blade.php`** — inspected and confirmed READ-ONLY. No `<form method="POST">` for editing; the page only contains download/back buttons + sign-off display. Skipping per plan scope_boundaries.
- **`resources/views/surveys/show.blade.php`** (public engineer wizard at `/survey/{token}`) — already has its own footer-stuck nav. Out of scope per plan.
- 4 secondary forms across RAMS review and OM-manual edit (regen / regen-after-save / email / generate) intentionally untouched.

## Architecture Notes

- **Zero JavaScript.** The Save button uses HTML5 `<button type="submit" form="...">` to submit a form by ID across the DOM.
- **Reuses existing design tokens** — no new colour/shadow/font values: `--header-height` (64px), `--surface`, `--ink-200`, `--ink-900`, `--font-display`.
- **Z-index 90** sits below sidebar (100), header (200), modals (998-999) and above page content. No clash with existing z-index map.
- **Negative margins** (`-1.75rem -2rem` desktop, `-1.25rem -1rem` mobile) cancel `.page-wrap` padding so the bar bleeds edge-to-edge while keeping the inner content contained.
- **Existing in-form Save buttons preserved** on all 5 views — engineers mid-form can still hit them; both submit the same form.

## Deviations from Plan

None — plan executed exactly as written. All CSS values, form IDs, and slot wording match the spec verbatim.

## Verification (Self-Check) — PASSED

- ✅ All 7 files exist and modified.
- ✅ All 3 commits (`e546982`, `eb0206c`, `d632cf4`) present in `git log`.
- ✅ `git diff --stat HEAD~3 HEAD -- app/ routes/ database/ config/ public/` returns empty (CSS/Blade only).
- ✅ `php artisan view:clear` runs clean after every task.
- ✅ `php artisan view:cache` succeeds — every Blade template compiles.
- ✅ `grep -c 'id="rams-review-form"' rams/review.blade.php` = 1 (exactly one form ID'd).
- ✅ All 4 RAMS form actions (`rams.regenerate` x2, `rams.update-and-download`, `rams.email`) still grep cleanly.
- ✅ `class="page-header"` removed from site-survey/edit (= 0 matches).
- ✅ Total file footprint = 7 (1 layout + 1 new component + 5 views), line delta +89/-10.

## Files to Upload to Live (per project convention)

Upload these 7 files to live (rsync/SFTP):

```
resources/views/layouts/app.blade.php
resources/views/components/edit-action-bar.blade.php   (NEW)
resources/views/projects/edit.blade.php
resources/views/om-manual/edit.blade.php
resources/views/om-manual/edit-devices.blade.php
resources/views/site-survey/edit.blade.php
resources/views/rams/review.blade.php
```

After upload, run on live: `php artisan view:clear`.

## Manual UAT — PENDING (Task 4 human-verify checkpoint)

The autonomous executor stopped here per the plan's `checkpoint:human-verify` task. Smoke test list:

1. **Desktop sweep** — open each of the 5 pages, confirm sticky bar visible at top, title text correct, Cancel returns to right show/index page, scroll keeps bar pinned.
2. **RAMS multi-form isolation** — sticky Save on `/rams/{id}/review` submits ONLY the edit-and-download form; the Regenerate, hidden regen-after-save, and Email RAMS forms still work.
3. **OM-manual isolation** — sticky Save on `/om-manuals/{id}/edit` submits the JSON edit form; the "Generate Document" button further down still works.
4. **Mobile preview (<768px)** — title text hides, Save and Cancel remain visible, bar still pinned during scroll.
5. **Existing in-form Save buttons** at the bottom of each form still work (regression safety).
6. **Read-only worksheet** — visit `/worksheets/{id}` and confirm NO sticky bar appears (correctly skipped).

UAT outcome will be recorded by the orchestrator after user types "approved" or describes any issue.

---
phase: 14-mobile-field-view
plan: "05"
subsystem: mobile-ui
tags: [blade, alpine, tailwind, mobile-first, ui-spec, field-view, inst-03, inst-04g]

# Dependency graph
requires:
  - phase: 14-mobile-field-view
    provides: 14-01 Wave 0 test scaffold (FieldViewResponsivenessTest still-red until this wave)
  - phase: 14-mobile-field-view
    provides: 14-02 schema + models (InstallTaskPhoto, TimeEntry)
  - phase: 14-mobile-field-view
    provides: 14-03 service layer (ClockInBlockedException copy, HeicImageConverter)
  - phase: 14-mobile-field-view
    provides: 14-04 HTTP endpoints (9 routes consumed: field, status, notes, photos CRUD, time-entries start/stop)
provides:
  - Production mobile Blade view at /projects/{project}/programme (INST-03a complete)
  - Horizontal photo strip with HEIC-aware camera input (INST-03d / D-09 / D-12)
  - Tap-to-advance task rows + overflow-menu bottom-sheet (INST-03c / D-05 / D-06 / D-07 / D-08)
  - Blur-save notes textarea with auto-grow (INST-03f)
  - Programme + per-room linear progress counters (INST-03g)
  - Clock-in chip with setInterval-driven elapsed timer + 422 inline error (INST-04g UX)
  - Five new view artefacts: root view + 3 partials + 1 Blade component
affects: [14 (phase done), 15-time-tracking, 16-commissioning]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "@push('styles') → @vite(app.css, app.js) scoped opt-in to Tailwind + Alpine on the authenticated layout. Other pages continue to use layouts/app.blade.php's inline design-token CSS unchanged."
    - "Alpine factories defined inline in a @push('scripts') block: fieldRoot() for page-level state (clock, sheet, counters), fieldTaskRow() for per-row state (status, notes, menu). Shared csrf() helper reads <meta name=\"csrf-token\">."
    - "Custom DOM events as row→root bridge: task-saved (carries server counters payload for progress refresh) and open-blocked-sheet (row ⋮ menu → single bottom-sheet instance at page root)."
    - "Native <dialog>.showModal() for the photo lightbox — no external library, Esc-dismiss + backdrop:bg-black/60 out of the box."
    - "Server-authoritative state: all status/notes/photo saves re-render from the PATCH/POST response. No optimistic UI (per RESEARCH anti-pattern)."
    - "motion-safe: Tailwind prefix on every transition so reduced-motion users get instant state changes."
    - "WCAG 2.5.5 AAA touch targets: every tappable element clears 44×44 CSS px via h-11/w-11 or p-3 + content."

key-files:
  created:
    - rams.21stcav.com/resources/views/components/install-task/photo-upload.blade.php
    - rams.21stcav.com/resources/views/install-programmes/_field-room.blade.php
    - rams.21stcav.com/resources/views/install-programmes/_field-task-row.blade.php
    - rams.21stcav.com/resources/views/install-programmes/_field-sheet.blade.php
  modified:
    - rams.21stcav.com/resources/views/install-programmes/field.blade.php

key-decisions:
  - "Add @push('styles') → @vite(app.css + app.js) on field.blade.php instead of modifying the shared app.blade.php layout. The layout uses hand-rolled inline CSS tokens as its primary style system; injecting @vite globally would double-load styles on every authenticated page (risk: cascade conflicts with .card/.btn/.stat-card etc). Scoping the push to this single page gets Tailwind + Alpine where the UI-SPEC needs them without blast-radius on other pages. Documented as Rule 3 auto-fix in Deviations."
  - "Alpine factory functions live inline in the Blade file via @push('scripts') rather than in resources/js/app.js. This matches the existing 'Blade-per-page' convention used by public-survey/show.blade.php and keeps the bundler change at zero. Two small functions (fieldRoot, fieldTaskRow) totalling ~200 lines — cheap to read, easy to diff, no tree-shaking concern."
  - "Keyboard handlers at the article-level use @keydown.enter.prevent + @keydown.space.prevent AND every nested interactive island (overflow button, photo strip, notes textarea) carries @click.stop + @keydown.stop. Without the keydown.stop, Space inside the notes textarea would bubble to the row and fire advance() — engineers typing notes would accidentally complete their tasks."
  - "room_name is passed in the task-saved event's detail alongside the server-returned counters. The server payload (Plan 04 contract) only returns {id, status, blocked_reason, counters:{room:{complete,total},programme:{complete,total}}} — which room the counters belong to has to come from the dispatching row. Root fieldRoot listener uses this.lastRoomName set either by the open-blocked-sheet dispatch (so sheet-driven saves attribute to the right room) or by the task-saved detail (so direct tap-advance saves do the same)."
  - "Notes textarea begins rows=1 (single-line) and auto-grows via $el.style.height = $el.scrollHeight + 'px' on @focus and @input. UI-SPEC D-locked. max-h-[200px] on the element caps the visual takeover when an engineer writes a long note — content beyond scrolls internally. Debounce-save is blur-only (textarea.@blur → saveNotes) which is simpler than a keystroke timer and matches the 'save on blur' pattern already established by caption inputs."
  - "Clock elapsed uses setInterval(30_000) recomputing now() - clocked_in_at each tick. Per UI-SPEC: '30 s granularity acceptable because we display H:MM not H:MM:SS'. Cheaper than requestAnimationFrame for a chip that only needs minute-level precision. Stored in this.clock._tickHandle so stopClockTicker() can clearInterval on clock-out."
  - "clockChipClasses() is a computed method, not a :class='{...}' object expression, so the transition target's background can flip between three discrete palettes (white→teal→red) without Alpine re-evaluating five ternaries. Same pattern for iconColor() and rowClasses() on the task row."
  - "Empty-state copy is baked into the Blade, not owned by Alpine, because the 'programme has 0 tasks' and 'engineer has 0 assignments' conditions are a server-decision driven by $programme and $rooms->isEmpty(). Putting them in Alpine would require bi-directional state plumbing that buys nothing: the user cannot create tasks from this page — they have to navigate away — so state never changes while the page is alive."

patterns-established:
  - "Tailwind utility classes + inline <style> design tokens coexist cleanly when @push('styles') scopes Vite to just the pages that need it. Pattern reusable for any future mobile-first page that wants Tailwind without disrupting the authenticated chrome."
  - "Alpine root ↔ component bridging via window.CustomEvent — not $dispatch() — because the bottom-sheet is a sibling of task rows, not a descendant. $dispatch bubbles through DOM parents; CustomEvent-on-window broadcasts to the whole tree. Use the latter whenever the listener is not an ancestor."
  - "Per-row factory (fieldTaskRow) + shared csrf() helper keeps the Alpine data definitions on <article> elements short enough to read in a glance. The factory returns a fresh object per row — no shared mutable state across rows."

requirements-completed:
  - INST-03a
  - INST-03b
  - INST-03c
  - INST-03d
  - INST-03f
  - INST-03g
  - INST-03h
  - INST-04g

# Metrics
duration: ~35 min (including worktree reset + composer dump-autoload + full regression)
completed: 2026-04-20
---

# Phase 14 Plan 05: Wave 4 Mobile Field View UI Summary

**Five Blade artefacts (1 root view replacement + 3 partials + 1 Blade component) that turn the Plan 04 URL contract into a touchable, thumb-friendly field page. FieldViewResponsivenessTest flips its last-red assertion green, and the Phase 14 Wave 0 scaffold is now fully green on a no-imagick dev box.**

## Performance

- **Duration:** ~35 minutes (including worktree reset to the correct Wave-3 base, composer dump-autoload to fix stale cross-worktree paths, and a full 673-test regression)
- **Started:** 2026-04-20T11:30Z (approx)
- **Completed:** 2026-04-20T12:05Z (approx)
- **Tasks:** 3 of 3 completed (all committed atomically with `--no-verify` per sequential wave policy)
- **Files created:** 4 (photo-upload component, _field-room partial, _field-task-row partial, _field-sheet partial)
- **Files modified:** 1 (field.blade.php — full rewrite of Plan 04's 60-line placeholder)

## Accomplishments

### Task 1 — Photo upload component + bottom-sheet partial (commit `ccbb734`)

- Created `resources/views/components/install-task/photo-upload.blade.php` (176 lines). Forked from `components/survey/photo-upload.blade.php` but retargeted to `$task->photos`:
  - Horizontal-scroll strip of 80×80 thumbnails (`w-20 h-20`) using `overflow-x-auto`. Each thumbnail wraps a `<button>` that opens the lightbox.
  - Dashed camera placeholder `<label>` wraps a hidden `<input type="file" accept="image/*,image/heic,image/heif" capture="environment">` so iOS Safari opens the rear camera; multiple selection is not supported per the UI-SPEC (one-at-a-time upload is cleaner on flaky 4G).
  - Upload handler posts `FormData` to `POST /install-tasks/{task}/photos` via `fetch()` with `X-CSRF-TOKEN` from the `<meta name="csrf-token">` tag. 20 MB client-side guard before upload; three explicit error messages for 500/422/other per 14-UI-SPEC.md Copywriting rules.
  - Caption inputs save on `@blur` via `PATCH /install-task-photos/{photo}`. Errors are silent per the spec — captions are nice-to-have, not blocking.
  - Native `<dialog>.showModal()` lightbox replaces a JS library: Esc-to-close + `backdrop:bg-black/60` out of the box. Lightbox has an inline Delete photo button with `confirm()` guard.
  - `data-testid="task-photo-upload"` on the outer element for future Playwright/Dusk hooks.
- Created `resources/views/install-programmes/_field-sheet.blade.php` (84 lines). Single bottom-sheet instance at the page root, reason-required:
  - `<template x-if="sheet.open">` gate so the DOM is not rendered when the sheet is closed (avoids backdrop + body flicker on initial paint).
  - `translate-y-full → translate-y-0` enter 250 ms `ease-out`, inverse leave 200 ms `ease-in` — matches existing `x-modal` timing. `motion-safe:` prefix on every transition.
  - `max-h-[75vh] overflow-y-auto pb-[env(safe-area-inset-bottom)]` so a landscape / short-phone user still has a scrollable sheet that doesn't hide behind the iOS home-indicator.
  - Save reason button is `:disabled="!sheet.reason.trim() || sheet.saving"` so the server's `required_if:status,blocked` validator never fires — the UX gate catches it first.
  - Backdrop click + Esc key both trigger `dismissSheet()` which `confirm('Discard this reason?')`s if the textarea has unsaved content.

### Task 2 — Task row + room section partials (commit `c25fad6`)

- Created `resources/views/install-programmes/_field-task-row.blade.php` (135 lines). The whole card is the tap target:
  - `data-testid="task-row"` — this is the assertion `FieldViewResponsivenessTest::test_view_contains_required_ui_spec_markers` was waiting for, and the reason this plan exists as its own wave.
  - `role="button" tabindex="0"` + `@click="advance()"` + `@keydown.enter.prevent="advance()"` + `@keydown.space.prevent="advance()"` for keyboard parity.
  - Inline Heroicons v2 status icon (5 status flavours: `circle` outline / `clock` solid / `check-circle` solid / `exclamation-triangle` solid / `minus-circle` solid) rendered in the exact colour per UI-SPEC's Status colour contract via the `iconColor()` method.
  - Row bg + border flip via `rowClasses()` computed method — returns the right `bg-amber-50 border-amber-300` (etc.) pair for each status. Optional `ring-green-400` (savedPulse, 400 ms) and `ring-red-400` (errorPulse, 4 s) overlays.
  - Overflow ⋮ (`w-11 h-11`) opens an `absolute right-0 top-12 w-48` menu with Mark blocked / Mark skipped / Reopen task (Reopen only when status==='complete'). `@click.stop` + `@keydown.stop` on the container prevent the ⋮ from bubbling to the row's `advance()`.
  - `<x-install-task.photo-upload :task="$task" />` embedded directly. `@click.stop` wrapper so thumbnail / caption / camera taps don't fire `advance()`.
  - Notes textarea starts `rows="1"`; focus and input grow it via `$el.style.height = $el.scrollHeight + 'px'` with a `max-h-[200px]` cap. Blur triggers `saveNotes()` → `PATCH /install-tasks/{task}/notes`. `maxlength="5000"` matches the Plan 04 server validator. Errors inline (`text-red-600 text-xs`) with 4 s auto-clear.
- Created `resources/views/install-programmes/_field-room.blade.php` (47 lines). Per-room collapsible section:
  - `x-data="{ open: true }"` — expanded by default per D-01.
  - Chevron button: `chevron-down` SVG when `open`, `chevron-right` when not. Button has `min-h-[44px]` touch target.
  - Counter shows `{$complete} of {$total}` normally; switches to `✓ Complete` in `text-green-600 font-semibold` when the room is entirely done. `data-testid="room-counter"` so the root `applyCounters()` can surgically update this node after a task save.
  - Loops tasks via `@include('install-programmes._field-task-row')` — each task gets a fresh Alpine component instance.

### Task 3 — Root field.blade.php with sticky bar, clock chip, progress, scope toggle (commit `d23910d`)

- Rewrote `resources/views/install-programmes/field.blade.php` (454 lines). Replaced the Plan 04 60-line placeholder with the full UI-SPEC layout:
  - **Sticky bar** `h-14 bg-[#0B3C45] text-white sticky top-0 z-30`: back chevron (44×44 tap target) + 2-line project name/ref on the left, clock-in chip on the right. `pt-[env(safe-area-inset-top)]` on the outer wrapper clears the iOS notch; the bar itself stays 56 px.
  - **Clock-in chip:** three visible states (Clock in / On the clock · H:MM / Try again) + two transient states (Clocking… saving / error). State-driven background via `clockChipClasses()`: white on inactive, `#178A95` when active, red on error. `setInterval(30_000)` recomputes elapsed H:MM. POSTs to `/projects/{project}/time-entries/start` and `/stop`; on 422 the `body.message` from `ClockInBlockedException` (Plan 04's 422 translation) surfaces inline per UI-SPEC.
  - **Programme progress block:** `h-2 bg-gray-200 rounded-full` track + `{width:X%}` fill. Fill colour switches to `bg-green-600` at 100%. Counter copy switches between three states per spec: "Programme not generated yet" / "N of M tasks complete" / "All tasks complete · ready for commissioning". `aria-live="polite"` on the counter.
  - **Scope toggle:** rendered only when `!$isOwnerOrAdmin`. Segmented pill; active segment `bg-white shadow-sm text-[#0B3C45]`. Navigation links (not Alpine fetch), query-param driven — simpler than a client swap for this volume.
  - **Empty states:** `data-testid="empty-state-programme"` for no-programme (clipboard icon + CTA-less body); `data-testid="empty-state-engineer"` for engineer-with-zero-assignments (Show all programme tasks link that swaps `scope=all`).
  - **Task list:** `foreach ($rooms as $roomName => $roomTasks) @include('install-programmes._field-room', …)` — identical-order iteration to the existing InstallProgrammeController::field() payload, counters lookup from `$counters['room'][$roomName]`.
  - **Bottom-sheet:** single instance via `@include('install-programmes._field-sheet')` at the end of the Alpine root.
  - **Alpine factories** (`fieldRoot`, `fieldTaskRow`) in a `@push('scripts')` block at the bottom of the view. `fieldRoot` listens for `task-saved` events and refreshes the programme progress bar + room counter node. `fieldTaskRow` dispatches `open-blocked-sheet` to bridge the overflow menu to the root sheet instance.
  - **`@push('styles')` → `@vite(['resources/css/app.css', 'resources/js/app.js'])`**: Rule-3 auto-fix (see Deviations).

## Task Commits

Each task committed atomically with `--no-verify` (sequential executor; orchestrator runs the full hook suite after wave merge):

1. **Task 1** `ccbb734` — feat(14-05): add photo-upload component + bottom-sheet partial
2. **Task 2** `c25fad6` — feat(14-05): add task-row + room-section partials with tap-to-advance + overflow
3. **Task 3** `d23910d` — feat(14-05): rewrite field.blade.php with full UI-SPEC mobile layout

## Files Created / Modified

Created:
- `rams.21stcav.com/resources/views/components/install-task/photo-upload.blade.php` — 176 lines. Horizontal strip + camera input + caption blur save + `<dialog>` lightbox.
- `rams.21stcav.com/resources/views/install-programmes/_field-room.blade.php` — 47 lines. Collapsible room section + chevron SVGs + counter.
- `rams.21stcav.com/resources/views/install-programmes/_field-task-row.blade.php` — 135 lines. Tappable row + overflow menu + embedded photo upload + auto-grow notes.
- `rams.21stcav.com/resources/views/install-programmes/_field-sheet.blade.php` — 84 lines. Bottom-sheet for blocked/skipped reason with disabled-until-reason Save.

Modified:
- `rams.21stcav.com/resources/views/install-programmes/field.blade.php` — rewritten (66 deleted + 446 added → 454 final lines). Plan 04's placeholder becomes the production UI.

## Decisions Made

See frontmatter `key-decisions` for the full list.

Headlines:
- `@push('styles')` scopes Vite (Tailwind + Alpine) to just this page so other authenticated pages keep their inline-token CSS.
- Alpine factories are inline in the Blade, not in `app.js` — matches the public-survey precedent and keeps the bundler change at zero.
- Bridging: row ⋮ → root sheet uses `window.CustomEvent` (not `$dispatch`) because the sheet is a sibling, not a descendant.
- `room_name` piggy-backs on the `task-saved` event detail so the single room counter node can be surgically updated after each save (server only returns the relevant room's counter, not all rooms).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Tailwind CSS was not loaded on the authenticated layout**

- **Found during:** Task 3 initial load attempt — the plan mandates Tailwind utility classes (`bg-[#178A95]`, `h-14`, `motion-safe:transition-all`, etc.) throughout the UI. Inspection of `resources/views/layouts/app.blade.php` revealed the layout uses hand-written inline CSS (design tokens under `:root`) and has NO `@vite(...)` directive anywhere. The guest layout (`guest.blade.php`) does have `@vite` for Breeze screens, but authenticated routes do not.
- **Issue:** Without Tailwind's generated stylesheet, every Tailwind class in the new view would render the right DOM but with zero applied CSS. The plan cannot meet UI-SPEC sign-off with bare HTML.
- **Fix:** Added `@push('styles')` + `@vite(['resources/css/app.css', 'resources/js/app.js'])` at the top of `field.blade.php`. This pushes into `@stack('styles')` at layout line 816, so Tailwind (from `app.css`) and Alpine (from `app.js`) load on THIS page only. Other authenticated pages are unaffected — their stack is empty and the layout's inline CSS remains their sole styling system. No conflict because Tailwind's reset doesn't touch class selectors (`.card`, `.btn`, etc.); the preflight is scoped to elements.
- **Files modified:** `resources/views/install-programmes/field.blade.php` (6-line `@push('styles')` block added)
- **Commit:** `d23910d`
- **Blast-radius verification:** Full 673-test regression suite run post-fix shows no regressions. Tests that render the guest layout (which already uses `@vite`) still pass; tests that render the authenticated layout on non-Phase-14 pages still pass (they don't push into the styles stack, so they don't pick up `@vite` either).

### Scope Boundary Notes

- **Worktree base reset:** The worktree was on commit `6f23f370` (pre-Wave-3 base). The prompt specified the expected base as `11738d0` (Plan 04's final commit). I did `git reset --soft 11738d0 && git reset --hard HEAD` per the prompt's instructions to get the correct post-Wave-3 starting state.
- **Composer autoload stale paths:** The worktree's `vendor/composer/autoload_psr4.php` pointed to a different worktree (`agent-aaec0366`). Ran `php composer.phar dump-autoload` to regenerate for this worktree. No composer install needed — `vendor/` was already materialised, just the path references were wrong.
- **No orchestrator-owned files staged:** `.planning/ROADMAP.md` had pre-existing unstaged changes from Plan 04's completion (checkboxes for 14-01 through 14-04). Those were already staged by an earlier process, so I unstaged them before each of my three commits. Per the orchestrator instructions, STATE.md / ROADMAP.md / REQUIREMENTS.md writes are owned by the orchestrator after wave completion — this plan does not touch them.

### Authentication Gates

None — all work was server-side Blade + client-side Alpine + Tailwind. No external service, no OAuth, no API keys.

## Issues Encountered

None beyond the Rule-3 auto-fix already documented. The three auto-fixes Plan 04 had to make on `HeicImageConverter` (Rule 2 — lazy imagick check, copy passthrough) all held through this wave; no additional service-layer fixes needed.

## User Setup Required

None for this plan's code. For the page to render in production with full UI-SPEC visuals:
- `npm install && npm run build` — must have been run so `public/build/manifest.json` exists (the `@vite` push resolves against it). In this worktree the manifest was already present and was not touched.
- Developer smoke: open `/projects/{project}/programme` in Chrome DevTools iPhone SE (375×667) emulator, confirm no horizontal scrollbar, tap a task → row turns amber, tap again → green with pulse, ⋮ → Mark blocked → sheet slides up → Save disabled until reason entered → Save → row turns red with reason shown. Clock in → chip turns teal, shows `On the clock · 0:00`; clock in again → 422 inline error shown; clock out → chip returns to white Clock in state.

## Next Plan Readiness

Phase 14 is COMPLETE after this wave. The orchestrator owns the merge + STATE.md advancement. Downstream phases can now:

- **Phase 15 (Time Tracking):** the `TimeEntry` model and the `clock_in / clock_out / last_heartbeat_at` columns exist from Plan 02; this plan proved the clock chip wires end-to-end. Phase 15 adds the `category` column via a non-destructive migration, the heartbeat loop, and the `programme:close-stale-sessions` command.
- **Phase 16 (Commissioning):** `HeicImageConverter` shipped in Plan 03 and is consumed by `TaskPhotoService` in this plan. Phase 16's `commissioning_items.evidence_photo_path` can inject the same converter. The `<x-install-task.photo-upload>` Blade component is the fork source for Phase 16's commissioning-evidence component — copy and retarget.

Phase 14 Wave 0 test coverage now fully green on dev box:
- `FieldPageTest` (7/7)
- `FieldViewResponsivenessTest` (3/3) ✓ — **this plan's deliverable**
- `InstallTaskStatusUpdateTest` (8/8)
- `InstallTaskPhotoUploadTest` (7/7 + 1 imagick-skip)
- `InstallTaskNotesTest` (4/4)
- `TimeEntryTest` (6/6)
- `HeicImageConverterTest` (1/1 + 2 imagick-skip)
- `InstallTaskPhotosSchemaTest` (3/3)
- `TimeEntriesSchemaTest` (5/5)

Full regression (phase 14 + every other phase): 673 passed, 3 skipped, 0 failed. Duration 85 s.

## Known Stubs

None. Every surface rendered by this plan consumes real data:
- The photo strip iterates `$task->photos` (live collection from Plan 02 relation).
- The counters use `$counters['programme']` and `$counters['room'][$roomName]` (live data from Plan 04 controller).
- The open-entry badge uses `$openEntry` (live user-scoped TimeEntry from Plan 04).
- The scope toggle uses `$scope` and `$isOwnerOrAdmin` (live from Plan 04).

## Threat Flags

None. Every new surface honours the plan's `<threat_model>`:
- **T-14-15 (XSS):** zero `{!! !!}` or `x-html` in any of the five files — verified via `grep -rn "{!!" resources/views/install-programmes/ resources/views/components/install-task/` returning no hits, and `grep -rn "x-html"` same. Dynamic text uses `{{ $var }}` (Blade auto-escape) or `x-text`.
- **T-14-16 (CSRF):** every `fetch()` with method != GET includes `X-CSRF-TOKEN` header from the `<meta name="csrf-token">` tag. 4 fetch call-sites across the two JS files, all CSRF-aware.
- **T-14-17 (Clickjacking):** accepted per threat_model — existing Laravel X-Frame-Options unchanged.
- **T-14-18 (Info disclosure via x-data):** the `@js(...)` encoded payload only contains task title + status + blocked_reason + notes that the logged-in user is already authorised to see (Plan 04's ownership guard on `field()` runs before render). No cross-tenant leakage.
- **T-14-19 (Input validation bypass):** client-side `!sheet.reason.trim()` check is UX only; Plan 04's `required_if:status,blocked` on the server is the authoritative gate.
- **T-14-20 (DoS via setInterval):** `clock._tickHandle` stored so `stopClockTicker()` can `clearInterval`; Alpine destroys the component on navigation, no global leak.

No new trust boundaries introduced beyond those the plan's threat_model already captured.

## Self-Check: PASSED

**Files created (verified with `ls`):**
- FOUND: `rams.21stcav.com/resources/views/components/install-task/photo-upload.blade.php`
- FOUND: `rams.21stcav.com/resources/views/install-programmes/_field-room.blade.php`
- FOUND: `rams.21stcav.com/resources/views/install-programmes/_field-task-row.blade.php`
- FOUND: `rams.21stcav.com/resources/views/install-programmes/_field-sheet.blade.php`

**Files modified (verified with `git diff --stat HEAD~3 HEAD`):**
- FOUND: `rams.21stcav.com/resources/views/install-programmes/field.blade.php` — 66 deletions + 446 additions = full replacement

**Commits (verified with `git log --oneline`):**
- FOUND: `ccbb734 feat(14-05): add photo-upload component + bottom-sheet partial`
- FOUND: `c25fad6 feat(14-05): add task-row + room-section partials with tap-to-advance + overflow`
- FOUND: `d23910d feat(14-05): rewrite field.blade.php with full UI-SPEC mobile layout`

**Test outcomes (verified with `php artisan test --filter=...`):**
- FieldViewResponsivenessTest: **3 passed** (6 assertions) — including the previously-RED `test_view_contains_required_ui_spec_markers` (data-testid="task-row")
- Phase-14 scoped (FieldPage|FieldViewResponsiveness|InstallTaskStatusUpdate|InstallTaskPhotoUpload|InstallTaskNotes|TimeEntry|HeicImageConverter|InstallTaskPhotosSchema|TimeEntriesSchema): **44 passed, 3 skipped** (imagick-gated HEIC tests)
- **Full regression** (`php artisan test`): **673 passed, 10 warnings, 3 skipped, 0 failed** — zero regressions against Plan 04's baseline

**UI-SPEC compliance (verified with heuristic greps):**
- `grep -c 'data-testid="task-row"' resources/views/install-programmes/_field-task-row.blade.php` → 2 ✓
- `grep -c 'h-14' resources/views/install-programmes/field.blade.php` → 2 ✓ (sticky bar height)
- `grep -c 'bg-\[#0B3C45\]' resources/views/install-programmes/field.blade.php` → 2 ✓ (UI-SPEC secondary)
- `grep -c 'bg-\[#178A95\]' resources/views/install-programmes/field.blade.php` → 4 ✓ (UI-SPEC accent)
- `grep -c 'pb-24' resources/views/install-programmes/field.blade.php` → 1 ✓ (mobile tab-bar clearance)
- `grep -c "env(safe-area-inset-top)" resources/views/install-programmes/field.blade.php` → 1 ✓ (iOS notch)
- `grep -rcE "navigator.serviceWorker|serviceWorker.register|manifest.json" resources/views/install-programmes/` → 0 ✓ (INST-03h online-only)
- `grep -rn "{!!" resources/views/install-programmes/ resources/views/components/install-task/` → 0 ✓ (XSS safety)
- `grep -rn "x-html" resources/views/install-programmes/ resources/views/components/install-task/` → 0 ✓ (XSS safety)

---
*Phase: 14-mobile-field-view*
*Completed: 2026-04-20*

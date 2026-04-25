---
phase: 15-time-tracking
plan: 04
subsystem: time-tracking-mobile-ui
tags: [time-tracking, mobile, alpine, heartbeat, visibility-api, bottom-sheet, phase-15]

# Dependency graph
requires:
  - phase: 15-time-tracking
    plan: 02
    provides: POST /projects/{project}/time-entries/start {category} + /stop {note?} + /time-entries/{entry}/heartbeat endpoints
  - phase: 14-mobile-field-view
    plan: 05
    provides: fieldRoot() Alpine factory + clock chip + _field-sheet.blade.php bottom-sheet pattern + csrf() helper
provides:
  - Mobile clock-in category picker (4-pill bottom-sheet, last-used pre-highlight)
  - Silent 60s heartbeat loop with exponential retry ladder (60 → 120 → 300 → 300s)
  - Page Visibility API pause/resume with catch-up ping on focus-regain
  - Optional clock-out note bottom-sheet with Skip / Save & clock out buttons
  - localStorage key `last-category-{user_id}` for D-02 pre-select persistence
  - Tickclock elapsed format: `0:07` | `1:23` | `12h 34m` (per spec)
affects: 15-05-dashboard-widget (engineers now submit category on clock-in; widget consumption path is unchanged but categories are now present on every new entry)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Extend-in-place Alpine factory — `fieldRoot()` grows three new state objects (categorySheet, noteSheet, heartbeat) + eight new methods without touching `fieldTaskRow()` or existing Phase 14 sheet logic"
    - "Closure-scoped projectId, DOM-attribute userId — projectId stays in the factory closure (Phase 14 precedent at line 227); userId arrives via `data-user-id` attribute and is read via `document.querySelector` inside init(). Asymmetry is intentional: reuses Phase 14 closure and avoids expanding the factory signature"
    - "setTimeout + recursive re-schedule (not setInterval) for heartbeat — allows variable delay per tick so the exponential ladder works without clearing/restarting intervals"
    - "Page Visibility API — document.addEventListener('visibilitychange') pauses heartbeat when document.hidden, fires immediate catch-up ping then restarts the loop on focus-regain"
    - "Declarative partial + factory-owned state — two new partials are markup-only (x-if / @click / x-model); all state and methods live in fieldRoot() so the partials stay tiny and testable by grep-assertions alone"
    - "localStorage preference with explicit-tap confirmation — D-02 requires pre-highlight but no auto-submit; partial reads categorySheet.lastUsed for class binding, submitCategory writes on success"

key-files:
  created:
    - resources/views/install-programmes/_field-category-sheet.blade.php
    - resources/views/install-programmes/_field-note-sheet.blade.php
  modified:
    - resources/views/install-programmes/field.blade.php

key-decisions:
  - "projectId via closure, userId via data-attribute — projectId was already closed over by Phase 14's toggleClock (line 227 `${projectId}/time-entries/...`); Phase 15 submitCategory/submitNote reuse the same closure. userId is new and exposed via `data-user-id=\"{{ auth()->id() }}\"` on the x-data div, read via querySelector inside init(). Mixing both in the factory signature would have bloated the diff without benefit."
  - "setTimeout + recursive reschedule, not setInterval, for heartbeat — allows delayMs to change per tick so the exponential ladder (60→120→300→300s) works without tearing down and recreating a timer on every failure. stopHeartbeat() clears the pending handle idempotently."
  - "422 from heartbeat stops the loop silently — the server-side contract (Plan 15-02) returns 422 when the entry is already closed (stale-close fired or admin edited it). Client gives up the loop rather than spamming retries on an entry that no longer exists."
  - "Visibility-paused flag separate from currentEntryId — two booleans gate sendHeartbeat: `visibilityPaused` (tab hidden) and `!currentEntryId` (not clocked in). Separating them means clockout correctly stops the loop even if the tab is currently hidden."
  - "D-19 reasoning: no Europe/London conversion in Phase 15 — the clock chip renders a DURATION (elapsed minutes since clocked_in_at), and duration arithmetic is timezone-invariant. `new Date(clocked_in_at)` parses the UTC ISO-8601 string, `Date.now() - since.getTime()` produces a millisecond delta that's identical in any browser timezone. D-19's Europe/London requirement is satisfied by UTC storage alone; a presentation-layer TZ conversion only becomes necessary for future wall-clock displays (e.g. 'Started at 09:15 BST'), which are deferred."
  - "Elapsed format ladder `H:MM` for <10h, `Hh MMm` for 10h+ — matches 15-CONTEXT.md §specifics. 10h cutoff is the natural visual break where single-digit hours become double-digit and the colon format stops reading cleanly."
  - "No explicit Cancel button on category sheet — matches Phase 14 _field-sheet.blade.php precedent. Dismissal via backdrop tap or ESC is sufficient; an explicit Cancel would add visual weight to the primary decision (pick a category)."
  - "openNoteSheet resets note='' on every open — not persisted across sessions. Each clock-out is a fresh note opportunity; storing drafts would cross a D-06 ('optional') boundary into 'never lose your words' territory that isn't required."
  - "submitCategory writes localStorage AFTER the server POST succeeds — ordering means a failed clock-in does not overwrite the previous last-used preference. D-02 wants the preference to reflect actual started sessions, not intended ones."
  - "dismissCategorySheet / dismissNoteSheet refuse to close mid-POST — `if (this.X.saving) return;` guards prevent the user from closing the sheet while the backend is mid-transaction. Avoids confusing UI state where the chip says 'On the clock' but the sheet is gone before the response arrives."

patterns-established:
  - "Closure-captured server identifiers in Alpine factories — other mobile-scoped Alpine components should follow this: projectId / programmeId go into the factory closure via `x-data=\"fooRoot({ projectId: {{ ... }} })\"` and are referenced directly by methods. Per-request ephemeral IDs (not rendered into the factory call) use data-* attributes."
  - "Bottom-sheet partial trio — Phase 14 established the first (_field-sheet); Phase 15 added two more using the same structure (motion-safe transitions, aria-modal, escape-to-close, safe-area-inset-bottom). Any future mobile picker/input follows this template."
  - "Heartbeat + visibility pattern — the startHeartbeat/stopHeartbeat/sendHeartbeat/_backoffHeartbeat quartet + visibilitychange listener is a reusable shape for any long-running client liveness ping (future: client-portal session keepalive, presence indicator)."

requirements-completed: [INST-04b, INST-04c, INST-04d, INST-04f]

# Metrics
duration: ~1h 10m (includes human verification loop)
completed: 2026-04-21
---

# Phase 15 Plan 04: Mobile Field-View Clock Extensions Summary

**Phase 14's mobile clock chip gains Phase 15 muscle: a 4-pill category picker bottom-sheet on clock-in, a silent 60s server heartbeat with exponential-retry + Page Visibility pause, and an optional note sheet on clock-out — all wired into the existing `fieldRoot()` Alpine factory as additive state and methods, with zero disturbance to the Phase 14 task-row / blocked-reason machinery.**

## Performance

- **Duration:** ~1 h 10 min end-to-end (includes human checkpoint walkthrough of all 10 verification steps)
- **First commit:** 2026-04-21T15:34:19+01:00 (Task 1 — partials)
- **Second commit:** 2026-04-21T15:38:19+01:00 (Task 2 — factory extension)
- **Human-verify checkpoint approved:** 2026-04-21
- **Tasks:** 3 (2 auto + 1 human-verify)
- **Files created:** 2
- **Files modified:** 1
- **Commits:** 2 (Task 3 is a checkpoint, no code commit)

## Accomplishments

- **Two new Blade partials (166 lines total)** — `_field-category-sheet.blade.php` (4-pill grid, last-used highlight via `categorySheet.lastUsed === '...'`, disabled mid-POST) and `_field-note-sheet.blade.php` (textarea with 500-char counter, Skip button sending `null`, Save sending trimmed text). Both mirror Phase 14's `_field-sheet.blade.php` pattern (motion-safe transitions, aria-modal, escape-to-close, safe-area-inset-bottom).
- **Three new Alpine state objects** in `fieldRoot()`:
  - `categorySheet` — `{ open, lastUsed, saving, error }`
  - `noteSheet` — `{ open, note, saving, error }`
  - `heartbeat` — `{ handle, delayMs, consecutiveFailures, currentEntryId, visibilityPaused }`
- **Eight new Alpine methods** — `openCategorySheet`, `dismissCategorySheet`, `submitCategory`, `openNoteSheet`, `dismissNoteSheet`, `submitNote`, `startHeartbeat`, `stopHeartbeat`, `sendHeartbeat`, `_backoffHeartbeat` (ten in total; two pairs of open/dismiss). All fetch calls use the Phase 14 `csrf()` helper + `fetch()` pattern — no axios, no new dependencies.
- **`toggleClock()` rewritten as a two-line router** — clocked-in path opens the note sheet, clocked-out path opens the category sheet. The actual POST happens in `submitCategory` / `submitNote`.
- **`init()` extended** — pre-loads `localStorage.getItem('last-category-' + userId)` into `categorySheet.lastUsed`, resumes heartbeat if `clock.openEntry` is present on page load (supports refresh mid-session), wires the `visibilitychange` listener, preserves all Phase 14 `task-saved` and `open-blocked-sheet` listeners verbatim.
- **`tickClock()` elapsed format updated** — `H:MM` for sub-10-hour sessions, `Hh MMm` for 10+-hour marathons. Embeds a D-19 reasoning comment explaining why no `Carbon::setTimezone('Europe/London')` call is needed at this presentation layer (duration math is TZ-invariant; wall-clock displays are deferred).
- **`data-user-id="{{ auth()->id() }}"`** exposed on the outer `x-data` div — the only new DOM surface for Phase 15. Read by `init()` and `submitCategory` via `document.querySelector('[data-user-id]')?.dataset.userId`.
- **Phase 14 preservation confirmed by human verifier** — blocked-reason sheet, task-row tap-to-advance, photo upload, room progress counters all still work after Phase 15 wiring.

## Alpine State + Method Map

### `categorySheet` (D-01, D-02)

| Field | Type | Purpose |
|-------|------|---------|
| `open` | bool | Drives `<template x-if>` in partial |
| `lastUsed` | string\|null | Pre-selected pill; pulled from localStorage on init |
| `saving` | bool | Disables pills + blocks dismiss during POST |
| `error` | string\|null | Inline error message under the pill grid |

**Methods:** `openCategorySheet()` (resets error/saving, opens); `dismissCategorySheet()` (refuses mid-POST); `async submitCategory(category)` (whitelists 4 values, POSTs to `/projects/${projectId}/time-entries/start`, on success: sets openEntry + startClockTicker + startHeartbeat + writes localStorage + closes sheet).

### `noteSheet` (D-06)

| Field | Type | Purpose |
|-------|------|---------|
| `open` | bool | Drives `<template x-if>` in partial |
| `note` | string | Two-way-bound textarea (`x-model`), max 500 chars |
| `saving` | bool | Disables buttons + blocks dismiss during POST |
| `error` | string\|null | Inline error message above buttons |

**Methods:** `openNoteSheet()` (resets note/error/saving, opens); `dismissNoteSheet()` (refuses mid-POST); `async submitNote(note)` (trims + slices to 500, POSTs to `/projects/${projectId}/time-entries/stop`, on success: clears openEntry + stopClockTicker + stopHeartbeat + resets chip + closes sheet). `note === null` path (Skip) sends empty body.

### `heartbeat` (D-08, D-09, D-10)

| Field | Type | Purpose |
|-------|------|---------|
| `handle` | setTimeout id\|null | Pending tick handle; cleared by stopHeartbeat |
| `delayMs` | number | Current delay; resets to 60000 on success |
| `consecutiveFailures` | number | Index into the retry ladder |
| `currentEntryId` | number\|null | Entry being heartbeated; null when clocked out |
| `visibilityPaused` | bool | True while `document.hidden === true` |

**Methods:**

- `startHeartbeat()` — idempotent; clears any pending tick, no-op if no currentEntryId, schedules `sendHeartbeat` via setTimeout (not setInterval — so delayMs can vary per tick).
- `stopHeartbeat()` — clears the pending timeout.
- `async sendHeartbeat()` — POSTs to `/time-entries/${id}/heartbeat`. On 200/204 → resets delayMs=60000, consecutiveFailures=0. On 422 → `stopHeartbeat()` + return (entry closed server-side; give up silently). On other non-OK → `_backoffHeartbeat()`. Always re-schedules (unless visibilityPaused or !currentEntryId) with the possibly-updated delayMs.
- `_backoffHeartbeat()` — increments failure counter, picks delay from `ladder = [60000, 120000, 300000, 300000]`.

## Verification Evidence

### Automated (Task 1 + Task 2 acceptance criteria)

**Partials:**
- `resources/views/install-programmes/_field-category-sheet.blade.php` — 81 lines, contains `data-category="installation|commissioning|testing|other"`, `categorySheet.open`, `submitCategory`, `@keydown.escape.window`.
- `resources/views/install-programmes/_field-note-sheet.blade.php` — 85 lines, contains `noteSheet.open`, `submitNote(null)` (Skip), `maxlength="500"`.

**Factory extension (field.blade.php):**
- `@include('install-programmes._field-category-sheet')` + `@include('install-programmes._field-note-sheet')` — present.
- `data-user-id="{{ auth()->id() }}"` — present on outer `x-data` div.
- Three new state keys (`categorySheet:`, `noteSheet:`, `heartbeat:`) — all present.
- Methods `submitCategory`, `submitNote`, `startHeartbeat`, `sendHeartbeat`, `_backoffHeartbeat`, `dismissCategorySheet`, `dismissNoteSheet` — all defined.
- `visibilitychange` listener — present in init().
- `last-category-` localStorage key — present in init() and submitCategory.
- Retry ladder `[60000, 120000, 300000, 300000]` — present in _backoffHeartbeat.
- `${projectId}/time-entries/start` and `${projectId}/time-entries/stop` — closure-scoped, matching Phase 14 precedent.
- Phase 14 methods preserved: `openSheet`, `dismissSheet`, `submitSheet`, `applyCounters`, `fieldTaskRow` — all still present.

### Human-verify checkpoint (Task 3) — APPROVED

User walked through all 10 steps of the verification script and confirmed "approved":

1. ✅ Dev server + queue listener + Vite running, mobile viewport
2. ✅ First clock-in taps Clock-in chip → category sheet slides up with 4 pills, none highlighted
3. ✅ Tap Installation → sheet closes, chip shows "On the clock · 0:00" teal; localStorage `last-category-{user_id}` = 'installation'
4. ✅ 90s wait → single POST to `/time-entries/{id}/heartbeat` returning 204; DB `last_heartbeat_at` updated
5. ✅ Tab switch → return triggers immediate catch-up POST to heartbeat on focus-regain
6. ✅ Clock-out tap → note sheet opens; typing increments character counter; "Save & clock out" POSTs with `{note: "..."}`; DB row has correct notes + clocked_out_at
7. ✅ Second clock-in → category sheet shows "Installation" pill pre-highlighted with teal ring; tap Testing updates localStorage to 'testing'
8. ✅ Skip button path → POST to /stop with empty body; DB notes = null
9. ✅ Exponential retry confirmed: blocked POST → 60s → 120s → 300s → stays at 300s
10. ✅ No regression in Phase 14 blocked-reason sheet, task-row tap-to-advance, or photo upload

## Threat Mitigation Verification

| Threat | Disposition | Status |
|--------|-------------|--------|
| T-15-04-01 (localStorage tampering) | accept | localStorage is per-origin; value is read only to pre-highlight a pill; user must still tap explicitly (D-02); server ignores localStorage. No privilege impact — as designed. |
| T-15-04-02 (entry IDs in DOM/network) | accept | Entry IDs are sequential integers, not secrets. Access control sits server-side (Plan 15-02 ownership guard on `recordHeartbeat`). Brute-forcing peer IDs only yields 403. |
| T-15-04-03 (malicious scripts create many heartbeats) | accept | Only legitimate Alpine scope creates the interval; same-origin policy + Plan 15-02 throttle (10/min) are outer rings. |
| T-15-04-04 (running tab keeps session alive indefinitely) | mitigate | Plan 15-03 stale-close fires at 120 min of missed heartbeat — but if the tab is still heartbeating, that is by definition an active session. The accepted risk is "unattended phone on site" which is a user error, not a system fault. No additional client-side mitigation needed. |
| T-15-04-05 (XSS injecting `submitCategory('installation')`) | accept | Blade auto-escapes `{{ }}`; category values hardcoded in the loop; user IDs come from server-side `auth()->id()`. No user-supplied data enters the sheet partials. |
| T-15-04-06 (visibilitychange listener persists across unloads) | accept | Alpine components are torn down on page navigation; the heartbeat timer is cleared by stopHeartbeat (not tied to page lifecycle explicitly, but Alpine destruction clears closures). No ghost interval risk in practice — confirmed by the human verifier who saw heartbeat stop after clock-out. |

## Task Commits

1. **Task 1:** `b0b3dd3` — feat(15-04): add field category and note bottom-sheet partials — 2 new files, 166 insertions, mirroring the Phase 14 bottom-sheet pattern with motion-safe transitions, aria-modal, escape-to-close.
2. **Task 2:** `caebd79` — feat(15-04): wire category/note sheets + heartbeat into fieldRoot Alpine factory — 258 lines changed in field.blade.php (222 additions, 36 modifications); three new state objects, ten new methods, visibilitychange listener, data-user-id attribute, tickClock reformat.
3. **Task 3 (checkpoint):** no commit — human-verify walkthrough complete, user approved.

## Files Created / Modified

**Created (2):**
- `resources/views/install-programmes/_field-category-sheet.blade.php` — 81 lines, declarative partial with 4-pill grid.
- `resources/views/install-programmes/_field-note-sheet.blade.php` — 85 lines, textarea + Skip/Save buttons with 500-char counter.

**Modified (1):**
- `resources/views/install-programmes/field.blade.php` — 640 lines total (+222/-36 from Phase 14 baseline). Three new state objects, ten new methods, extended init(), replaced toggleClock() body, extended tickClock() format, added data-user-id attribute, added two `@include` lines for new partials. Phase 14 `fieldTaskRow()` factory untouched; existing `sheet` / `openSheet` / `dismissSheet` / `submitSheet` / `applyCounters` preserved verbatim.

## Decisions Made

See frontmatter `key-decisions` for the full list. Highlights:

- **projectId via closure, userId via data-attribute** — intentional asymmetry reusing Phase 14's factory closure for projectId; new userId joins via DOM attribute to avoid bloating the factory signature.
- **setTimeout + recursive reschedule (not setInterval) for heartbeat** — enables variable delay per tick for the exponential ladder without tearing down a timer on every failure.
- **422 from heartbeat stops the loop silently** — matches Plan 15-02 contract where 422 means the entry is already closed.
- **D-19 reasoning embedded in tickClock comment** — Phase 15 chip is a duration (TZ-invariant); Europe/London conversion is deferred until a wall-clock display appears.
- **No explicit Cancel button on sheets** — matches Phase 14 _field-sheet precedent; backdrop tap or ESC dismisses.
- **Write localStorage after server POST succeeds** — failed clock-ins don't overwrite the last-used preference.
- **Dismiss refuses mid-POST** — avoids confusing UI states where the chip says clocked-in but the sheet vanished before the response arrived.

## Deviations from Plan

None. The plan executed exactly as written:

- Partials' markup (80+ lines each) matches the plan's code block verbatim.
- `fieldRoot()` extension — all three state objects and all ten methods match the plan's code blocks line-for-line.
- `data-user-id` attribute added to the outer x-data div per STEP 6.
- tickClock() replaced with the H:MM / Hh MMm ladder per STEP 7.
- Phase 14 methods (`fieldTaskRow`, `openSheet`, `dismissSheet`, `submitSheet`, `applyCounters`) preserved verbatim per the "DO NOT" guardrails.

All 10 steps of the human-verify checkpoint passed on the first attempt.

## Authentication Gates

None. All fetch calls use the same `X-CSRF-TOKEN` + Laravel session auth as Phase 14.

## Issues Encountered

None. The plan's explicit `projectId`-closure / `userId`-attribute distinction anticipated the one possible confusion point ahead of coding and resolved it pre-emptively.

## Known Stubs

None. Every state transition has a live code path; every button tap is wired to a fetch call or a state mutation; no "coming soon" placeholders.

## Self-Check: PASSED

**Files created (2):**
- `resources/views/install-programmes/_field-category-sheet.blade.php` — FOUND (81 lines)
- `resources/views/install-programmes/_field-note-sheet.blade.php` — FOUND (85 lines)

**Files modified (1):**
- `resources/views/install-programmes/field.blade.php` — MODIFIED (640 lines; +222/-36 from Phase 14)

**Commits exist:**
- `b0b3dd3` — FOUND (Task 1: partials)
- `caebd79` — FOUND (Task 2: factory extension)

**Human-verify checkpoint (Task 3):**
- Approved by user after walking through all 10 verification steps.

---
*Phase: 15-time-tracking*
*Plan: 04*
*Completed: 2026-04-21*

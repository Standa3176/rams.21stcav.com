---
phase: 15-time-tracking
verified: 2026-04-21T00:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
requirements_coverage:
  satisfied: [INST-04, INST-04a, INST-04b, INST-04c, INST-04d, INST-04e, INST-04f, INST-04g, INST-04h, INST-04i]
  blocked: []
  orphaned: []
advisory:
  - "REVIEW WR-01: TOCTOU race in closeStaleSessions — candidate scan does not re-verify staleness inside the row lock. Real but not goal-blocking (stale-close safety net still auto-closes abandoned sessions; worst case is a false-positive closure with duration≈0). Tracked for a post-Phase-15 quick fix."
  - "REVIEW WR-02: Inconsistent TimeEntryEditException message handling — heartbeat path returns a generic 'Session is no longer active.' while update path returns $e->getMessage() verbatim. Contract inconsistency, no security/data impact."
  - "REVIEW info items IN-01…IN-06: dead clock.error state, duplicated category list, notes trim asymmetry, redundant ternary, unused eager-load, defensive fallback — all cosmetic/hygiene, non-blocking."
---

# Phase 15: Time Tracking Verification Report

**Phase Goal:** Engineers clock in/out per project with category selection. Open sessions are protected by server heartbeat; stale sessions auto-closed by scheduled command. Actual hours visible on project dashboard.
**Verified:** 2026-04-21
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `time_entries` table has columns: `id`, `project_id`, `user_id`, `category`, `clocked_in_at`, `clocked_out_at`, `last_heartbeat_at`, `notes` | ✓ VERIFIED | Base table from Phase 14 migration `2026_04_20_000002_create_time_entries_table.php`; Phase 15 ALTER migration `2026_04_21_000001_extend_time_entries_for_phase_15.php` adds `category` (string 32 nullable), `notes` (text nullable), `closure_reason` (string 32 nullable), plus composite index `(project_id, clocked_in_at)`. All 8 required columns plus Phase 15's closure_reason and audit foreign-key surface are in place. |
| 2 | Clock in creates a row with `clocked_out_at = null`; second clock-in is rejected with an error | ✓ VERIFIED | `TimeEntryService::start()` wraps `DB::transaction + lockForUpdate`; throws `ClockInBlockedException::alreadyOpen()` when an existing open entry is detected (Phase 14 guard preserved). Category param required per INST-04a-style enum. Feature tests `test_double_clock_in_rejected` green per 15-02 SUMMARY. |
| 3 | `php artisan programme:close-stale-sessions` closes entries where `last_heartbeat_at` is older than 2 hours | ✓ VERIFIED | `app/Console/Commands/CloseStaleSessionsCommand.php` exists with signature `programme:close-stale-sessions {--minutes=120}`. Delegates to `TimeEntryService::closeStaleSessions(120)`. `routes/console.php` line 85 registers the command via `Schedule::command(...)->hourly()->withoutOverlapping(30)->runInBackground()->onOneServer()`. 8 feature tests green per 15-03 SUMMARY. |
| 4 | All `clocked_in_at`/`clocked_out_at` values are stored as UTC in database | ✓ VERIFIED | Laravel default timezone is UTC (no `app.timezone` override observed). `TimeEntryService::start()`/`stop()`/`closeStaleSessions()` all persist via `now()` and model `$casts` declare `clocked_in_at`/`clocked_out_at`/`last_heartbeat_at` as `datetime` (UTC storage, Carbon-aware retrieval). D-19 reasoning comment embedded in `field.blade.php` `tickClock()` documents the timezone-invariant duration semantics. |
| 5 | Project dashboard shows total actual hours and per-category breakdown | ✓ VERIFIED | `app/Http/Controllers/ProjectController.php:248-262` computes `$canSeeActualHours` (owner OR admin) and calls `$this->timeEntryService->summaryForProject($project)`. `resources/views/projects/show.blade.php:72-75` conditionally includes `_actual-hours-widget.blade.php`. Widget renders total (`Xh Ym`) + 4-row bar breakdown with brand-teal palette (#178A95 / #21A8B5 / #4FB8C2 / #9CA3AF) + empty state. 6 feature tests green per 15-05 SUMMARY. |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `database/migrations/2026_04_21_000001_extend_time_entries_for_phase_15.php` | Adds category, notes, closure_reason + composite index | ✓ VERIFIED | All three columns present, index `time_entries_project_clocked_in_index` created, reversible via `dropIndex` + `dropColumn`. |
| `database/migrations/2026_04_21_000002_create_time_entry_audits_table.php` | Append-only history table with cascadeOnDelete + restrictOnDelete | ✓ VERIFIED | Table created with both FK constraints, index on `(time_entry_id, edited_at)`. |
| `app/Models/TimeEntry.php` | CATEGORIES const + CLOSURE_REASON_STALE_AUTO_CLOSE + audits() relation | ✓ VERIFIED | Lines 39-55 declare four `CATEGORY_*` constants + `CATEGORIES` array + `CLOSURE_REASON_STALE_AUTO_CLOSE`. Line 95 declares `audits(): HasMany`. |
| `app/Models/TimeEntryAudit.php` | FIELDS + timeEntry() + editor() belongsTo | ✓ VERIFIED | Model present, belongsTo relations wired, FIELD_CATEGORY/FIELD_NOTES constants set. |
| `app/Models/User.php` | timeEntries() + timeEntryAudits() hasMany | ✓ VERIFIED | Lines 72, 81 declare both relations. |
| `app/Exceptions/TimeEntryEditException.php` | 5 named constructors | ✓ VERIFIED | Lines 23, 31, 39, 47, 55 declare `alreadyClosed`, `entryStillOpen`, `invalidField`, `invalidCategory`, `noteTooLong`. |
| `app/Services/TimeEntryService.php` | 6 methods: start, stop, recordHeartbeat, editEntry, summaryForProject, closeStaleSessions | ✓ VERIFIED | Lines 64, 116, 172, 199, 280, 322 — all six methods with correct signatures. |
| `app/Http/Requests/{Start,Stop,Heartbeat,Update}TimeEntryRequest.php` | 4 FormRequest classes | ✓ VERIFIED | All four files exist in `app/Http/Requests/`. |
| `app/Http/Controllers/TimeEntryController.php` | start + stop + heartbeat + update endpoints | ✓ VERIFIED | Lines 67, 111, 146, 172 — all four endpoints present. |
| `app/Console/Commands/CloseStaleSessionsCommand.php` | programme:close-stale-sessions Artisan command | ✓ VERIFIED | Signature declared line 32; delegates to `TimeEntryService::closeStaleSessions` line 52. |
| `routes/web.php` | 4 routes: start, stop, heartbeat, update with throttles | ✓ VERIFIED | Lines 326, 331, 336 (throttle:10,1), 341 (throttle:20,1). |
| `routes/console.php` | Hourly scheduler for programme:close-stale-sessions | ✓ VERIFIED | Line 85-87 — `Schedule::command(...)->hourly()->withoutOverlapping(30)`. |
| `resources/views/install-programmes/_field-category-sheet.blade.php` | 4-pill category bottom-sheet | ✓ VERIFIED | `@foreach` iterates `['installation','commissioning','testing','other']`; `submitCategory()` handlers + `categorySheet.open` wiring present. |
| `resources/views/install-programmes/_field-note-sheet.blade.php` | Optional note sheet with Skip + Save | ✓ VERIFIED | `noteSheet.open` template, `maxlength="500"`, `submitNote(null)` (Skip) + `submitNote(noteSheet.note)` (Save) both wired. |
| `resources/views/install-programmes/field.blade.php` | fieldRoot extended with categorySheet/noteSheet/heartbeat | ✓ VERIFIED | Lines 211, 217, 223 declare the three state objects; lines 235, 254, 329, 367 wire localStorage read, `visibilitychange` listener, `submitCategory`, `openNoteSheet`; `data-user-id` attribute on x-data div line 22. |
| `resources/views/projects/_actual-hours-widget.blade.php` | Actual Hours widget with total + 4 bars + empty state | ✓ VERIFIED | Brand-teal palette hex-hardcoded (#178A95 / #21A8B5 / #4FB8C2 / #9CA3AF), "No time tracked yet" empty state present, 4 category rows with inline bar widths. |
| `resources/views/projects/show.blade.php` | @include widget behind canSeeActualHours guard | ✓ VERIFIED | Lines 72-75 — `@if (! empty($canSeeActualHours) && $actualHours !== null) @include('projects._actual-hours-widget')`. |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `TimeEntryController::heartbeat` | `TimeEntryService::recordHeartbeat` | Constructor-injected service call | ✓ WIRED | Controller catches `AuthorizationException` (403) and `TimeEntryEditException` (422); 204 on success. |
| `TimeEntryController::update` | `TimeEntryService::editEntry` + `TimeEntryAudit::create` | Service atomic DB::transaction | ✓ WIRED | `editEntry()` captures old_value, updates entry, creates audit row in single transaction; returns fresh entry + audit fields to controller. |
| `ProjectController::show` | `TimeEntryService::summaryForProject` | Constructor-injected service; gated by `$canSeeActualHours` | ✓ WIRED | Line 252 calls service only when gate passes; `compact()` expanded with both vars. |
| `CloseStaleSessionsCommand` | `TimeEntryService::closeStaleSessions` | Constructor-injected service | ✓ WIRED | `handle()` reads `--minutes` option, delegates, prints summary. |
| `routes/console.php` | `programme:close-stale-sessions` command | `Schedule::command(...)->hourly()->withoutOverlapping(30)` | ✓ WIRED | Full chain includes `runInBackground`, `onOneServer`, `appendOutputTo(storage/logs/stale-sessions.log)`. |
| `routes/web.php` | `TimeEntryController::heartbeat` | `POST /time-entries/{entry}/heartbeat` + `throttle:10,1` | ✓ WIRED | Route name `time-entries.heartbeat`. |
| `routes/web.php` | `TimeEntryController::update` | `PATCH /time-entries/{entry}` + `throttle:20,1` | ✓ WIRED | Route name `time-entries.update`. |
| `field.blade.php` submitCategory | `POST /projects/{projectId}/time-entries/start` | Closure-scoped projectId, X-CSRF-TOKEN fetch | ✓ WIRED | Uses the Phase 14 factory-closure precedent. |
| `field.blade.php` sendHeartbeat | `POST /time-entries/{id}/heartbeat` | Entry-id-scoped fetch, exponential backoff on failure | ✓ WIRED | Ladder `[60000, 120000, 300000, 300000]`; visibility-paused semaphore; immediate catch-up on focus-regain. |
| `_actual-hours-widget.blade.php` | `TimeEntryService::summaryForProject` | Controller compact() payload → view `$actualHours` | ✓ WIRED | Widget reads `total_minutes` + `per_category` straight from service output. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| `_actual-hours-widget.blade.php` | `$actualHours['total_minutes']` + `$actualHours['per_category']` | `ProjectController::show()` → `TimeEntryService::summaryForProject($project)` → `TimeEntry::where('project_id', …)->whereNotNull('clocked_out_at')->get(...)` + PHP reduce | ✓ YES — real Eloquent query against `time_entries` | ✓ FLOWING |
| `field.blade.php` clock chip elapsed | `clock.openEntry.clocked_in_at` | Server-rendered `openEntry` (Phase 14); heartbeat + start responses update it | ✓ YES — real DB row | ✓ FLOWING |
| `field.blade.php` lastUsed category | `localStorage['last-category-{user_id}']` | Written by `submitCategory` after 200 response | ✓ YES — real user preference (per-device) | ✓ FLOWING |
| Stale-close log writer | `Log::warning` context | Eloquent `$entry` inside `DB::transaction` | ✓ YES — real entry IDs from DB | ✓ FLOWING |

No hollow props, no hardcoded empty fallbacks reach the rendering path in production code (fallback in widget partial only kicks in when the service contract is somehow violated; actual service always returns the 4-key dict).

### Behavioral Spot-Checks

SKIPPED — PHP is not on PATH in the verification sandbox. Per the orchestrator note, Phase 15 scope tests are all green (53 TimeEntry + 6 ActualHours + 8 CloseStaleSessions + 137 Project + related = 255 passing). The pre-existing `QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` failure is pre-Phase 15 queue infra (Phase 09) and unrelated.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| INST-04 | 15-01 | Umbrella — engineers clock in/out with actual hours logged per category | ✓ SATISFIED | All sub-requirements satisfied below. |
| INST-04a | 15-01 | time_entries table schema with category, notes, UTC timestamps | ✓ SATISFIED | Phase 14 create migration + Phase 15 ALTER migration; columns verified. |
| INST-04b | 15-02, 15-04 | Clock in creates row with clocked_in_at=now(UTC), clocked_out_at=null | ✓ SATISFIED | `TimeEntryService::start()` persists + category; controller endpoint + FormRequest in place. |
| INST-04c | 15-02, 15-04 | Clock out sets clocked_out_at=now(UTC) | ✓ SATISFIED | `TimeEntryService::stop()` + `StopTimeEntryRequest` (+ optional note). |
| INST-04d | 15-02, 15-04 | Heartbeat every 60s updates last_heartbeat_at server-side | ✓ SATISFIED | `TimeEntryService::recordHeartbeat()` + `POST /time-entries/{entry}/heartbeat` route + Alpine `sendHeartbeat()` loop with exponential retry + visibility pause. |
| INST-04e | 15-03 | Scheduled job closes entries with last_heartbeat_at >2h old; logs warning | ✓ SATISFIED | `CloseStaleSessionsCommand` + hourly scheduler + `Log::warning` in service per closure. |
| INST-04f | 15-01, 15-04 | All storage in UTC; display in Europe/London | ✓ SATISFIED | Laravel default UTC storage; D-19 reasoning documented — Phase 15 displays are timezone-invariant durations, so no presentation-layer TZ conversion required. |
| INST-04g | 15-02 | Guard: only one open time entry per user per project (Phase 14 lockForUpdate preserved) | ✓ SATISFIED | `TimeEntryService::start()` DB::transaction + lockForUpdate pattern unchanged; `ClockInBlockedException::alreadyOpen` still thrown. |
| INST-04h | 15-02, 15-05 | Per-project total + per-category breakdown shown on project dashboard | ✓ SATISFIED | `summaryForProject` service method + Actual Hours widget with 4-row bar breakdown + empty state, gated by owner/admin visibility (D-16). |
| INST-04i | 15-01 | v1.2 tracks actuals only — no budget comparison | ✓ SATISFIED | No budget columns added, no budget logic in widget. Deferred-items list in 15-CONTEXT.md explicit. |

All 10 requirement IDs declared in plan frontmatter are satisfied. No orphaned requirements found.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| `app/Services/TimeEntryService.php` | 322-372 | TOCTOU race: candidate scan (no lock) → row lock → no re-verify of staleness predicate | ⚠️ Warning (WR-01 per REVIEW) | Late heartbeat arriving between scan and lock can cause false-positive closure with duration≈0. Safety-net semantics of stale-close still hold. Advisory per prompt — does not fail phase. |
| `app/Http/Controllers/TimeEntryController.php` | 152-154, 183-184 | Inconsistent message handling: heartbeat discards exception message; update echoes verbatim | ⚠️ Warning (WR-02 per REVIEW) | Contract inconsistency; no security impact. Advisory per prompt — does not fail phase. |
| `resources/views/install-programmes/field.blade.php` | 199, 271, 308-317 | `clock.error` declared, checked in multiple render paths, never assigned truthy | ℹ️ Info (IN-01) | Dead UI state — chip never goes "Try again" red. Cosmetic. |
| `_field-category-sheet.blade.php`, `field.blade.php`, `_actual-hours-widget.blade.php` | multiple | Four-category enum duplicated across 4 locations (constant + 3 view/JS copies) | ℹ️ Info (IN-02) | Adding 5th category requires syncing all. Cosmetic. |
| `app/Services/TimeEntryService.php` | 230-235 | editEntry does not trim-to-null empty notes (stop() does) | ℹ️ Info (IN-03) | Minor data-quality divergence. |
| `_actual-hours-widget.blade.php` | 51, 60 | Redundant ternary on bar width | ℹ️ Info (IN-04) | Cosmetic. |
| `ProjectController.php` | 130, 232-240 | Eager-load includes installProgrammes; Actual Hours uses its own query | ℹ️ Info (IN-05) | Not a Phase 15 regression; one added aggregate query per show. |
| `_actual-hours-widget.blade.php` | 17-19 | Defensive fallback duplicates enum shape | ℹ️ Info (IN-06) | Low-value defence. |

All REVIEW items are advisory per the orchestrator's instruction; no blockers identified.

### Human Verification Required

None — the human-verify checkpoints in 15-04 and 15-05 were walked and approved by the user during execution (logged in SUMMARYs). Mobile clock-in flow (10 steps) and widget visibility/empty-state/mobile-375px (8 steps) both confirmed pass.

### Gaps Summary

No gaps. Every ROADMAP success criterion has a concrete implementation, every declared requirement ID maps to shipped code, every artifact exists and is wired, and the data-flow traces show real DB queries feeding the rendered surface. The two REVIEW warnings (WR-01 TOCTOU, WR-02 message inconsistency) are real-but-advisory and do not block goal achievement per the orchestrator note; the six info items are cosmetic/hygiene. Phase 14 regression remains green (6/6 feature tests per 15-01 and 15-02 SUMMARY). Pre-existing `QueueRecoverCommandTest` failure is unrelated Phase 09 queue infra.

**Phase 15 goal achieved: engineers can clock in/out with category selection, server-side heartbeat protects open sessions, scheduled stale-close safety-net runs hourly, and actual hours are visible on the project dashboard to owners/admins.**

---

_Verified: 2026-04-21T00:00:00Z_
_Verifier: Claude (gsd-verifier)_

---
phase: 15-time-tracking
plan: 02
subsystem: time-tracking-service
tags: [time-tracking, service-layer, heartbeat, retro-edit, audit-log, form-request, http-api, phase-15]

# Dependency graph
requires:
  - phase: 15-time-tracking
    plan: 01
    provides: time_entries.{category,notes,closure_reason,last_heartbeat_at}, TimeEntry::CATEGORIES, TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE, TimeEntryAudit + FIELDS, User::timeEntries/timeEntryAudits relations
provides:
  - TimeEntryService::start(+category) — enum-validated + persisted
  - TimeEntryService::stop(+note) — trimmed, empty-to-null, <=500 char enforced
  - TimeEntryService::recordHeartbeat — owner-only, idempotent, no log spam
  - TimeEntryService::editEntry — owner+admin retro-edit with atomic audit write
  - TimeEntryService::summaryForProject — total_minutes + per_category totals over closed entries
  - TimeEntryService::closeStaleSessions — per-row transactional sweep, returns count
  - TimeEntryEditException — 5 named constructors (alreadyClosed, entryStillOpen, invalidField, invalidCategory, noteTooLong)
  - Four FormRequests — StartTimeEntryRequest, StopTimeEntryRequest, HeartbeatTimeEntryRequest, UpdateTimeEntryRequest
  - TimeEntryController::heartbeat — POST 204/403/422
  - TimeEntryController::update — PATCH 200/403/422 with audit-row echo back
  - Route time-entries.heartbeat — POST, throttle:10,1
  - Route time-entries.update — PATCH, throttle:20,1
affects: 15-03-stale-close-command (calls closeStaleSessions), 15-04-retro-edit-ui (calls PATCH /time-entries/{entry}), 15-05-dashboard-widget (calls summaryForProject)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Enum validation at service layer — InvalidArgumentException → 422 pattern extends the ClockInBlockedException → 422 translation"
    - "Append-only audit inside DB::transaction — retcon-proof (T-15-02-02, T-15-02-04)"
    - "Strict owner-only heartbeat vs owner-OR-admin retro-edit — distinct guards per threat model"
    - "Per-row lockForUpdate in closeStaleSessions — avoids holding multi-row locks (T-15-02-08)"
    - "FormRequest withValidator() after-hook — conditional validation when field value depends on another field"
    - "Controller catches domain exceptions and returns generic messages — mitigates information disclosure (T-15-02-06)"

key-files:
  created:
    - app/Exceptions/TimeEntryEditException.php
    - app/Http/Requests/StartTimeEntryRequest.php
    - app/Http/Requests/StopTimeEntryRequest.php
    - app/Http/Requests/HeartbeatTimeEntryRequest.php
    - app/Http/Requests/UpdateTimeEntryRequest.php
    - tests/Unit/Services/TimeEntryServiceTest.php
    - tests/Feature/TimeEntries/TimeEntryHeartbeatTest.php
    - tests/Feature/TimeEntries/TimeEntryEditTest.php
  modified:
    - app/Services/TimeEntryService.php
    - app/Http/Controllers/TimeEntryController.php
    - routes/web.php
    - tests/Feature/TimeEntries/TimeEntryTest.php

key-decisions:
  - "Heartbeat is strict owner-only (not owner-or-admin) — peer admins shouldn't silently keep an engineer's session alive; retro-edit is owner-OR-admin per D-05"
  - "editEntry() writes audit row INSIDE the DB::transaction alongside the update — single atomic unit; partial-write impossible"
  - "summaryForProject() uses PHP-side aggregation (not raw SQL) — SQLite/MySQL parity preserved; per-category dict always has all 4 keys including 0s"
  - "closeStaleSessions() iterates one candidate at a time inside its own DB::transaction — no multi-row lock held concurrently (T-15-02-08)"
  - "Null last_heartbeat_at fallback: clocked_out_at = clocked_in_at + 1min — rare but non-zero duration preserved for ops review"
  - "Heartbeat throttle:10,1 vs Update throttle:20,1 — heartbeat is automated (60s cadence = max 1/min), update is human-paced (form submits); 10 and 20 give ~10x and ~3x headroom respectively"
  - "Log::info on editEntry but NOT recordHeartbeat — heartbeat cadence (60s) would flood logs; stale-close Log::warning is the observable surface"
  - "Feature tests assert JSON validation errors via assertJsonValidationErrors — not bare assertStatus(422) — catches message-shape regressions"

patterns-established:
  - "FormRequest-per-endpoint even for empty bodies (HeartbeatTimeEntryRequest) — keeps controller signatures uniform and gives a hook for future cross-cutting rules"
  - "Domain exception → HTTP status mapping via controller try/catch — TimeEntryEditException → 422, AuthorizationException → 403, RuntimeException → 422"
  - "Audit-row echo-back: PATCH response includes old_value/new_value/edited_at straight from the TimeEntryAudit write, so the client can render a toast without a follow-up GET"

requirements-completed: [INST-04b, INST-04c, INST-04d, INST-04g, INST-04h]

# Metrics
duration: 10min
completed: 2026-04-21
---

# Phase 15 Plan 02: Service + Controller Summary

**TimeEntryService now covers the full Phase 15 surface — category-aware start, optional note on stop, heartbeat, retro-edit with atomic audit, per-project summary and stale-session auto-close — exposed through four FormRequest-gated controller endpoints with distinct owner/admin guards per the STRIDE register.**

## Performance

- **Duration:** 10 min
- **Started:** 2026-04-21T14:12:32Z
- **Completed:** 2026-04-21T14:21:52Z
- **Tasks:** 2
- **Files created:** 8
- **Files modified:** 4
- **Commits:** 4 (2 RED, 2 GREEN — no refactor step needed)

## Accomplishments

- **TimeEntryService grew from 2 methods to 6** — start (category-aware), stop (note-aware), recordHeartbeat, editEntry, summaryForProject, closeStaleSessions. Phase 14's DB::transaction + lockForUpdate guard pattern is preserved; Phase 15 extensions layer on top rather than replacing.
- **TimeEntryEditException** — five named constructors mirroring the ClockInBlockedException → 422 translation pattern. Each message is the engineer-facing payload; internal IDs stay server-side in Log::warning only (T-15-02-06 mitigation).
- **Four FormRequests + UpdateTimeEntryRequest::withValidator() after-hook** — the conditional "value must be a valid category when field === 'category'" check is a clean FormRequest pattern worth reusing for future retro-edit surfaces.
- **Two new routes registered** — `POST /time-entries/{entry}/heartbeat` (throttle:10,1) and `PATCH /time-entries/{entry}` (throttle:20,1), both inside the existing `auth` middleware group (inherits CSRF + session auth).
- **Retro-edit audit flow is atomic** — `editEntry()` wraps the entry update + `TimeEntryAudit::create()` in a single `DB::transaction`. A partial write (entry updated but no audit row, or vice versa) is impossible. Mitigates T-15-02-02 (tampering) and T-15-02-04 (repudiation).
- **Strict owner-only heartbeat** — service throws `AuthorizationException` when `$entry->user_id !== $user->id`, even for admins. Per the CONTEXT Security Concerns section: peer admins shouldn't silently keep an engineer's session alive. Retro-edit is owner-OR-admin per D-05.
- **53 tests passing** across TimeEntry surfaces: 22 unit (service) + 9 feature (start/stop) + 4 feature (heartbeat, including the 429 rate-limit test) + 8 feature (retro-edit) + 10 Phase 15-01 regression. Zero Phase 14 regressions.

## Final TimeEntryService Method Signatures

```php
public function start(Project $project, User $user, string $category): TimeEntry
// InvalidArgumentException for bad category, ClockInBlockedException for double clock-in

public function stop(Project $project, User $user, ?string $note = null): TimeEntry
// InvalidArgumentException for note >500 chars, RuntimeException for no open entry

public function recordHeartbeat(TimeEntry $entry, User $user): void
// AuthorizationException for non-owner, TimeEntryEditException for closed entry

public function editEntry(TimeEntry $entry, User $editor, string $field, ?string $newValue): TimeEntry
// AuthorizationException for non-owner-non-admin, TimeEntryEditException for
// open entry / invalid field / invalid category / oversize note

public function summaryForProject(Project $project): array
// Returns ['total_minutes' => int, 'per_category' => ['installation' => int, ...4 keys]]

public function closeStaleSessions(int $staleAfterMinutes = 120): int
// Returns count of entries closed
```

## FormRequest Rule Sets

| Request | Rules |
|---------|-------|
| `StartTimeEntryRequest` | `category` required, string, `Rule::in(TimeEntry::CATEGORIES)` |
| `StopTimeEntryRequest` | `note` nullable, string, max:500 |
| `HeartbeatTimeEntryRequest` | (empty) |
| `UpdateTimeEntryRequest` | `field` required, `Rule::in(['category','notes'])`; `value` nullable string max:500; `withValidator` after-hook enforces `Rule::in(TimeEntry::CATEGORIES)` when `field === 'category'` |

## Route Map

| Method | Path | Name | Middleware |
|--------|------|------|------------|
| POST | `projects/{project}/time-entries/start` | `time-entries.start` | auth + `throttle:30,1` + CSRF |
| POST | `projects/{project}/time-entries/stop` | `time-entries.stop` | auth + `throttle:30,1` + CSRF |
| POST | `time-entries/{entry}/heartbeat` | `time-entries.heartbeat` | auth + `throttle:10,1` + CSRF |
| PATCH | `time-entries/{entry}` | `time-entries.update` | auth + `throttle:20,1` + CSRF |

`php artisan route:list --name=time-entries` prints the 4 routes with the above middleware chain.

## Retro-Edit Audit Flow

```
 Engineer/Admin ─POST PATCH─▶ TimeEntryController::update()
                                    │
                                    ▼
                         UpdateTimeEntryRequest (whitelist + conditional value check)
                                    │
                                    ▼
                         TimeEntryService::editEntry()
                          ├── ownership gate  (throws AuthorizationException → 403)
                          ├── state gate      (throws TimeEntryEditException → 422)
                          ├── field/value gate (throws TimeEntryEditException → 422)
                          │
                          ▼  DB::transaction (atomic)
                          ├── capture $oldValue
                          ├── $entry->update([$field => $newValue])
                          ├── TimeEntryAudit::create([...])
                          └── Log::info('entry edited', [...])

 Response 200 { id, field, old_value, new_value, edited_at }
          ─ old_value + new_value pulled from the audit row just written
          ─ edited_at from audit->edited_at (canonical, not now())
```

If any gate throws, no audit row is written. If the `editEntry` transaction fails mid-flight, both the update and the audit roll back together.

## Task Commits

1. **Task 1: TimeEntryService extensions + TimeEntryEditException**
   - `726c03a` (test) — failing unit tests for all 5 new/extended methods (RED)
   - `b4dfe42` (feat) — exception class + service extensions (GREEN — 22/22 passing)
2. **Task 2: FormRequests + Controller endpoints + routes + feature tests**
   - `8aff8ab` (test) — updated Phase 14 tests + new heartbeat/edit feature tests (RED)
   - `6cf32fb` (feat) — 4 FormRequests, extended controller, 2 new routes (GREEN — 21/21 new feature tests passing)

## Files Created/Modified

**Created (8):**
- `app/Exceptions/TimeEntryEditException.php` — 5 named factories
- `app/Http/Requests/StartTimeEntryRequest.php` — category validation
- `app/Http/Requests/StopTimeEntryRequest.php` — note validation (nullable max:500)
- `app/Http/Requests/HeartbeatTimeEntryRequest.php` — empty body
- `app/Http/Requests/UpdateTimeEntryRequest.php` — whitelist + conditional category check
- `tests/Unit/Services/TimeEntryServiceTest.php` — 22 tests
- `tests/Feature/TimeEntries/TimeEntryHeartbeatTest.php` — 4 tests (1 in @group rate-limit)
- `tests/Feature/TimeEntries/TimeEntryEditTest.php` — 8 tests

**Modified (4):**
- `app/Services/TimeEntryService.php` — 2 methods became 6; preserved DB::transaction + lockForUpdate pattern
- `app/Http/Controllers/TimeEntryController.php` — start/stop take FormRequests; new heartbeat() + update() methods; reorganised with ASCII section dividers
- `routes/web.php` — 2 new routes beneath the existing time-entry block
- `tests/Feature/TimeEntries/TimeEntryTest.php` — all 6 existing tests send category payload; 3 new tests for missing/invalid category + stop-with-note

## Decisions Made

- **Strict owner-only heartbeat (not owner-or-admin)** — T-15-02-01 explicitly calls out the malicious-peer-keeping-session-alive risk. Even a legitimate admin shouldn't silently extend an engineer's session; if ops need to close a runaway session they should use closeStaleSessions or an explicit clock-out path.
- **Owner-OR-admin retro-edit** — per D-05 admins can edit any finished entry (same audit surface, no bypass). The audit row records whoever did the edit, so retcons are still traceable.
- **Audit row INSIDE the same DB::transaction as the entry update** — partial-write impossible. A failing audit insert rolls back the entry update too. Retcon detection depends on audit permanence (T-15-02-02 / T-15-02-04).
- **summaryForProject aggregates in PHP, not raw SQL** — SQLite/MySQL portability (CASTs / date-diff functions differ). Volume is low (one project lifetime of time entries). If Phase 16+ needs cross-project aggregation, push to SQL then.
- **Per-category dict always has all 4 keys** — consumer (Plan 15-05 dashboard widget) can render the 4-row breakdown unconditionally without null-checking. Cost is negligible (array_fill_keys once).
- **Stale-close iterates one candidate at a time in its own DB::transaction** — T-15-02-08 mitigation. Each row acquires its own lockForUpdate, completes, and releases before the next. No multi-row lock held concurrently.
- **Null last_heartbeat_at fallback adds 1 minute (not 0)** — preserves a non-zero duration so ops can still see "this was a real session" in dashboards. Matches D-11 fallback semantics.
- **Heartbeat throttle:10,1 vs update throttle:20,1** — heartbeat automated at 60s = 1/min, so 10/min = 10x headroom for retry bursts. Update is human-paced (form submit), 20/min = ~3/min per user is plenty. Higher throttles invite abuse; lower throttles would false-positive on legitimate mobile network hiccups.
- **Log::info on editEntry but NOT recordHeartbeat** — heartbeat at 60s cadence would flood logs (60 lines/user/hour × N users). Editing is a rare, consequential event worth logging. Stale-close Log::warning gives the liveness-failure signal without heartbeat per-call noise.
- **Feature tests use `assertJsonValidationErrors(['category'])` rather than bare `assertStatus(422)`** — catches regressions that change the error shape (e.g. controller swallows the FormRequest 422 and returns a plain message). Stronger contract with the frontend.

## Deviations from Plan

None. Plan executed exactly as written — pre-flight caller enumeration confirmed only the controller + test files referenced `service->start()`, and both were updated atomically within their respective tasks. Phase 14 feature tests were updated in the same commit as the new category-required tests to avoid a false-red window.

## Issues Encountered

None. Each TDD cycle landed clean:
- Task 1 RED → GREEN (22/22 passing on first implementation run)
- Task 2 RED → GREEN (21/21 new feature tests + 32 pre-existing related tests, all passing on first run)

The rate-limit test in `TimeEntryHeartbeatTest` uses `@group rate-limit` per plan, but runs fine in the default suite since the Laravel cache uses the array driver during tests — no cross-test flakiness.

## Self-Check: PASSED

**Files created (8):**
- `app/Exceptions/TimeEntryEditException.php` — FOUND
- `app/Http/Requests/StartTimeEntryRequest.php` — FOUND
- `app/Http/Requests/StopTimeEntryRequest.php` — FOUND
- `app/Http/Requests/HeartbeatTimeEntryRequest.php` — FOUND
- `app/Http/Requests/UpdateTimeEntryRequest.php` — FOUND
- `tests/Unit/Services/TimeEntryServiceTest.php` — FOUND
- `tests/Feature/TimeEntries/TimeEntryHeartbeatTest.php` — FOUND
- `tests/Feature/TimeEntries/TimeEntryEditTest.php` — FOUND

**Files modified (4):**
- `app/Services/TimeEntryService.php` — MODIFIED (6 methods total)
- `app/Http/Controllers/TimeEntryController.php` — MODIFIED (4 public methods)
- `routes/web.php` — MODIFIED (2 new routes)
- `tests/Feature/TimeEntries/TimeEntryTest.php` — MODIFIED (category payloads + 3 new tests)

**Commits exist:**
- `726c03a` FOUND (Task 1 RED)
- `b4dfe42` FOUND (Task 1 GREEN)
- `8aff8ab` FOUND (Task 2 RED)
- `6cf32fb` FOUND (Task 2 GREEN)

**Test suite status:** 53/53 TimeEntry-related tests pass (22 unit + 9 Phase-14-regression feature + 4 heartbeat + 8 retro-edit + 10 Plan 15-01 regression). `php artisan route:list --name=time-entries` shows the 4 expected routes.

---
*Phase: 15-time-tracking*
*Plan: 02*
*Completed: 2026-04-21*

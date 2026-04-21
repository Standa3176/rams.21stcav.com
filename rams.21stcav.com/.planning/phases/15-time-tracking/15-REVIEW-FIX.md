---
phase: 15-time-tracking
fixed_at: 2026-04-21T00:00:00Z
review_path: .planning/phases/15-time-tracking/15-REVIEW.md
iteration: 1
findings_in_scope: 2
fixed: 2
skipped: 0
status: all_fixed
---

# Phase 15: Code Review Fix Report

**Fixed at:** 2026-04-21T00:00:00Z
**Source review:** .planning/phases/15-time-tracking/15-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 2 (Critical + Warning only; 6 Info items deferred)
- Fixed: 2
- Skipped: 0

## Fixed Issues

### WR-01: Race condition in `closeStaleSessions` can auto-close a live session

**Files modified:** `rams.21stcav.com/app/Services/TimeEntryService.php`
**Commit:** 5858e29
**Applied fix:** Added a post-lock staleness re-verification inside the per-candidate
`DB::transaction` closure in `closeStaleSessions`. After acquiring `lockForUpdate()`,
the code now re-checks the same predicate used by the candidate query
(`last_heartbeat_at < cutoff`, or `clocked_in_at < cutoff` when `last_heartbeat_at`
is NULL). If a late heartbeat arrived between the candidate scan and acquiring the
row lock, the closure now early-returns without writing `clocked_out_at`, preserving
the live session. `$cutoff` is passed into the closure via `use()`. Per-row lock
isolation (T-15-02-08) and D-11 "no phantom hours" semantics are preserved because
the fresh row read inside the lock yields the up-to-date `last_heartbeat_at`, which
is then used for `$closedAt` when the row is still genuinely stale. Comment block
added to document the TOCTOU rationale for future readers.

### WR-02: Heartbeat 422 discarded `TimeEntryEditException` message (contract violation)

**Files modified:** `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php`
**Commit:** 43c2855
**Applied fix:** Replaced the hardcoded `'Session is no longer active.'` string in
`heartbeat()`'s `TimeEntryEditException` catch block with `$e->getMessage()`,
mirroring the `update()` path. The class docblock explicitly states that the
exception message is the payload shown to the user, so both endpoints now honour
that contract. Copy responsibility stays in `TimeEntryEditException::alreadyClosed()`
(single source of truth, reviewable in one place). No security impact — the embedded
entry id was supplied by the client on its own request. Comment added to link the
decision to the documented contract.

---

_Fixed: 2026-04-21T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_

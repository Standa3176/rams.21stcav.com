---
phase: 15-time-tracking
reviewed: 2026-04-21T00:00:00Z
depth: standard
files_reviewed: 21
files_reviewed_list:
  - rams.21stcav.com/app/Console/Commands/CloseStaleSessionsCommand.php
  - rams.21stcav.com/app/Exceptions/TimeEntryEditException.php
  - rams.21stcav.com/app/Http/Controllers/ProjectController.php
  - rams.21stcav.com/app/Http/Controllers/TimeEntryController.php
  - rams.21stcav.com/app/Http/Requests/HeartbeatTimeEntryRequest.php
  - rams.21stcav.com/app/Http/Requests/StartTimeEntryRequest.php
  - rams.21stcav.com/app/Http/Requests/StopTimeEntryRequest.php
  - rams.21stcav.com/app/Http/Requests/UpdateTimeEntryRequest.php
  - rams.21stcav.com/app/Models/TimeEntry.php
  - rams.21stcav.com/app/Models/TimeEntryAudit.php
  - rams.21stcav.com/app/Models/User.php
  - rams.21stcav.com/app/Services/TimeEntryService.php
  - rams.21stcav.com/database/migrations/2026_04_21_000001_extend_time_entries_for_phase_15.php
  - rams.21stcav.com/database/migrations/2026_04_21_000002_create_time_entry_audits_table.php
  - rams.21stcav.com/resources/views/install-programmes/_field-category-sheet.blade.php
  - rams.21stcav.com/resources/views/install-programmes/_field-note-sheet.blade.php
  - rams.21stcav.com/resources/views/install-programmes/field.blade.php
  - rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php
  - rams.21stcav.com/resources/views/projects/show.blade.php
  - rams.21stcav.com/routes/console.php
  - rams.21stcav.com/routes/web.php
findings:
  critical: 0
  warning: 2
  info: 6
  total: 8
status: issues_found
---

# Phase 15: Code Review Report

**Reviewed:** 2026-04-21T00:00:00Z
**Depth:** standard
**Files Reviewed:** 21
**Status:** issues_found

## Summary

Phase 15 extends the Phase 14 time_entries scaffold with category, clock-out notes,
closure_reason, an append-only `time_entry_audits` log, a retro-edit + heartbeat +
stale-close service, mobile field-view UI (category / note bottom sheets and an
exponential-backoff heartbeat loop), and an owner/admin-gated Actual Hours widget
on the project show page.

Overall the code is well-structured, thoroughly documented, and follows the
thin-controller / service-heavy pattern required by CLAUDE.md. The guard-exception
translation mirrors Phase 14's established `ClockInBlockedException → 422` pattern,
the migrations are additive (no existing-column changes), and the command
delegates all state logic to the service.

Two warnings to flag. WR-01 is a TOCTOU race in `closeStaleSessions` that can
prematurely auto-close a session whose heartbeat arrives between the candidate
scan and the row lock. WR-02 is a small but user-visible inconsistency in how
`TimeEntryEditException` messages surface to the client (generic string from the
heartbeat path, specific string from the update path). The info items are
duplication / dead-state hygiene and cosmetic cleanups.

No critical issues: no injection, no secrets, no authz bypass, no data-loss paths.

## Warnings

### WR-01: Race condition in `closeStaleSessions` can auto-close a live session

**File:** `rams.21stcav.com/app/Services/TimeEntryService.php:322-372`

**Issue:** The scheduled sweep first reads candidate IDs with
`where('last_heartbeat_at', '<', $cutoff)` (no lock), then re-fetches each row
with `lockForUpdate()` and only re-verifies `whereNull('clocked_out_at')` — it
does **not** re-verify the staleness predicate. If an engineer's heartbeat
lands between the candidate scan and the row lock (entirely possible on an
hourly sweep with a 2-hour cutoff when the heartbeat is 60s apart), the row
will still be open and will be closed using the freshly-updated
`last_heartbeat_at` as `clocked_out_at`. Net effect: a live session is killed,
the engineer's next heartbeat 422s, and the log shows a
`stale_auto_close` entry whose duration is near-zero.

Additionally, because `$closedAt = $entry->last_heartbeat_at` picks up the
refreshed heartbeat after the lock, the closure timestamp will reflect the
*new* heartbeat rather than the actual stale-out point, which defeats D-11's
"no phantom hours" intent.

**Fix:** Re-verify staleness inside the lock, and re-read the row (not just
`$entry->last_heartbeat_at`) so a late heartbeat is respected:

```php
foreach ($candidates as $candidate) {
    DB::transaction(function () use ($candidate, $cutoff, &$closed) {
        $entry = TimeEntry::where('id', $candidate->id)
            ->whereNull('clocked_out_at')
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            return; // already closed
        }

        // Re-verify staleness after locking — a late heartbeat may have
        // arrived between the candidate scan and acquiring the lock.
        $stillStale = ($entry->last_heartbeat_at !== null
                && $entry->last_heartbeat_at < $cutoff)
            || ($entry->last_heartbeat_at === null
                && $entry->clocked_in_at < $cutoff);

        if (! $stillStale) {
            return;
        }

        $closedAt = $entry->last_heartbeat_at
            ?? $entry->clocked_in_at->copy()->addMinute();

        $entry->update([
            'clocked_out_at' => $closedAt,
            'closure_reason' => TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE,
        ]);

        // ...log + $closed++ unchanged...
    });
}
```

### WR-02: Heartbeat 422 message leaks a generic string; update path leaks the exception message verbatim

**File:** `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php:152-154, 183-184`

**Issue:** `heartbeat()` catches `TimeEntryEditException` and returns a hardcoded
`'Session is no longer active.'`, discarding the exception's specific message
(`alreadyClosed()` embeds the entry id). By contrast, `update()` returns
`$e->getMessage()` straight to the client, which surfaces the entry id
(`"Cannot edit an open entry #42. Clock out first."`) and the field name
(`"Field 'foo' is not retro-editable."`).

Leaking the entry id isn't a security issue (the client already has the id — it
sent it), but the two paths handle the same exception class differently, and
`invalidField()` echoes user-supplied input back into the response which can be
surprising in logs / error trackers. More importantly, per the class docblock
("The exception message is the payload shown to the user"), the heartbeat path
violates the documented contract.

**Fix:** Pick one policy and apply it consistently. Recommend mirroring
`update()` so the copy lives in the exception factory (which can be reviewed
for tone in one place):

```php
} catch (TimeEntryEditException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
}
```

If the friendlier "Session is no longer active." copy is preferred for
engineer-facing screens, move it into `TimeEntryEditException::alreadyClosed()`
so the controller stays transport-only.

## Info

### IN-01: `clock.error` state is declared but never written

**File:** `rams.21stcav.com/resources/views/install-programmes/field.blade.php:199, 271, 308-317, 84-85`

**Issue:** The `clock.error` property is declared on the Alpine root, checked in
`clockChipClasses()` / `clockAriaLabel()` / the inline alert, and cleared in
`toggleClock()`, but nothing in the Phase 15 flow ever sets it truthy.
`submitCategory()` writes `categorySheet.error` and `submitNote()` writes
`noteSheet.error` — both sheet-local. The "Try again" chip state and the red
banner below the sticky bar are therefore dead UI.

**Fix:** Either wire `clock.error` to the sheet errors (so the chip turns red
after a failed clock-in even once the sheet dismisses), or delete the
`clock.error` property and the three render paths that depend on it.

### IN-02: Category list duplicated across PHP enum and three Blade / JS locations

**File:**
- `rams.21stcav.com/resources/views/install-programmes/_field-category-sheet.blade.php:56`
- `rams.21stcav.com/resources/views/install-programmes/field.blade.php:330`
- `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php:17-19, 25-30`

**Issue:** The four-category enum lives in four places: `TimeEntry::CATEGORIES`
(canonical), a hardcoded assoc array in the category-sheet partial, a
hardcoded array in `submitCategory()`'s client guard, and the widget's
`$categories` metadata. Adding a fifth category (future "travel" / "break" /
"documentation") requires synchronising all four.

**Fix:** Pass `TimeEntry::CATEGORIES` into the Blade views so the PHP enum is
the single source of truth. For the JS guard, emit the enum as a JSON blob via
`@js(\App\Models\TimeEntry::CATEGORIES)` and consume it from Alpine state.

### IN-03: `editEntry` treats empty-string notes as a valid value (unlike `stop`)

**File:** `rams.21stcav.com/app/Services/TimeEntryService.php:230-235`

**Issue:** `stop()` normalises whitespace-only / empty notes to `null` (lines
118-121), but `editEntry()` does not. A `PATCH { field: 'notes', value: '' }`
writes an empty string into `time_entries.notes` and records it verbatim in the
audit log. Minor data-quality divergence.

**Fix:** Mirror `stop()`'s trim-to-null before the length check:

```php
if ($field === TimeEntryAudit::FIELD_NOTES) {
    $newValue = $newValue !== null ? trim($newValue) : null;
    if ($newValue === '') {
        $newValue = null;
    }
    if ($newValue !== null && mb_strlen($newValue) > 500) {
        throw TimeEntryEditException::noteTooLong(mb_strlen($newValue));
    }
}
```

### IN-04: Actual Hours widget has a redundant ternary

**File:** `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php:51, 60`

**Issue:** Line 51 computes `$pct = $total > 0 ? max(2, (int) round(...)) : 2`.
Line 60 then renders `width: {{ $mins > 0 ? $pct : 2 }}%` — but `$pct` is
already guaranteed to be `>= 2` because of the `max(2, …)` on line 51, so the
line-60 ternary is unreachable on its false branch.

**Fix:** Simplify to `style="width: {{ $pct }}%; …"`.

### IN-05: `ProjectController::show` eager-loads `installProgrammes` but the view never reads them

**File:** `rams.21stcav.com/app/Http/Controllers/ProjectController.php:130, 232-240`

**Issue:** The eager-load block includes `installProgrammes` (latest 5), and
Linked Records references `$project->installProgrammes`. Not a Phase 15 bug,
but the Actual Hours feature depends on `$timeEntryService->summaryForProject`
which runs a dedicated query — the widget does NOT read eager-loaded time
entries. No N+1 regression from this phase. Flagged only so the reviewer can
confirm the widget's per-page query count is acceptable (one aggregate query
added per `projects.show` render for owners + admins).

**Fix:** None required. Consider eager-loading `timeEntries` into a scope that
pre-aggregates (e.g. `->withSum('closedTimeEntries as actual_minutes', …)`) if
the page's query count becomes a concern.

### IN-06: `per_category` in the summary contract is untyped in the widget include

**File:** `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php:17-19`

**Issue:** The partial falls back to `['installation' => 0, ...]` if
`$actualHours['per_category']` is missing, but the service already guarantees
that shape (line 286 of `TimeEntryService`). The defensive fallback is fine,
but it hardcodes the four keys again — if the enum gains a member, this
fallback silently drops it. Low-value defence.

**Fix:** Remove the fallback (the service contract is authoritative) or
derive it from `TimeEntry::CATEGORIES` to stay in sync.

---

_Reviewed: 2026-04-21T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

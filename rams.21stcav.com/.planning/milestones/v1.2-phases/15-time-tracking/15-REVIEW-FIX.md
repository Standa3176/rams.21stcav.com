---
phase: 15-time-tracking
fixed_at: 2026-04-21T00:00:00Z
review_path: .planning/phases/15-time-tracking/15-REVIEW.md
iteration: 2
findings_in_scope: 8
fixed: 6
already_fixed: 2
not_applicable: 1
skipped: 0
status: all_fixed
---

# Phase 15: Code Review Fix Report (Combined)

**Fixed at:** 2026-04-21T00:00:00Z
**Source review:** .planning/phases/15-time-tracking/15-REVIEW.md
**Iteration:** 2 (combined summary covering iterations 1 + 2)

**Summary:**
- Findings in scope: 8 (2 Warning + 6 Info)
- Fixed this pass (iteration 2): 5 (IN-01, IN-02 + IN-06, IN-03, IN-04)
- Fixed in prior pass (iteration 1): 2 (WR-01, WR-02)
- Not applicable: 1 (IN-05 — awareness note, no code change requested)
- Skipped: 0

Note: IN-02 and IN-06 were fixed together in a single commit because they both
concern category-enum duplication and the widget fix for IN-06 (fallback shape)
is a natural companion to IN-02 (central `CATEGORY_LABELS` constant). The
commit message and this report call them out separately.

## Fixed Issues

### WR-01: Race condition in `closeStaleSessions` can auto-close a live session (iteration 1)

**Files modified:** `rams.21stcav.com/app/Services/TimeEntryService.php`
**Commit:** 5858e29
**Applied fix:** Added a post-lock staleness re-verification inside the per-candidate
`DB::transaction` closure in `closeStaleSessions`. After acquiring `lockForUpdate()`,
the code now re-checks the same predicate used by the candidate query
(`last_heartbeat_at < cutoff`, or `clocked_in_at < cutoff` when `last_heartbeat_at`
is NULL). If a late heartbeat arrived between the candidate scan and acquiring the
row lock, the closure now early-returns without writing `clocked_out_at`, preserving
the live session. `$cutoff` is passed into the closure via `use()`. Comment block
added to document the TOCTOU rationale for future readers.

### WR-02: Heartbeat 422 discarded `TimeEntryEditException` message (iteration 1)

**Files modified:** `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php`
**Commit:** 43c2855
**Applied fix:** Replaced the hardcoded `'Session is no longer active.'` string in
`heartbeat()`'s `TimeEntryEditException` catch block with `$e->getMessage()`,
mirroring the `update()` path. The class docblock explicitly states that the
exception message is the payload shown to the user, so both endpoints now honour
that contract. Copy responsibility stays in `TimeEntryEditException::alreadyClosed()`
(single source of truth). Comment added to link the decision to the documented
contract.

### IN-01: `clock.error` state is declared but never written

**Files modified:** `rams.21stcav.com/resources/views/install-programmes/field.blade.php`
**Commit:** c641f62
**Applied fix:** Chose the "delete the dead state" path (smaller diff than wiring it up
to sheet errors, which are already surfaced in the category / note bottom sheets
themselves). Removed:

- `error: null` from `clock` reactive state
- `!clock.error` predicate from three `x-show` bindings on the clock chip
- `x-show="clock.error" … Try again` span
- The red banner `<div x-show="clock.error" …>` below the sticky bar
- `if (this.clock.error)` branches in `clockChipClasses()` and `clockAriaLabel()`
- `this.clock.error = null;` reset in `toggleClock()`

Error surfacing already happens inside `categorySheet.error` (clock-in failure) and
`noteSheet.error` (clock-out failure); a replacement comment was added at the
former banner site to document why the sticky-bar error surface was removed.
Verified with `php artisan test --filter=FieldView` — 10 passed / 0 failed.

### IN-02: Category list duplicated across PHP enum and three Blade / JS locations

**Files modified:**

- `rams.21stcav.com/app/Models/TimeEntry.php` (added `CATEGORY_LABELS` const)
- `rams.21stcav.com/resources/views/install-programmes/_field-category-sheet.blade.php`
- `rams.21stcav.com/resources/views/install-programmes/field.blade.php`
- `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php`

**Commit:** 684fd66 (shared with IN-06)
**Applied fix:** Promoted the canonical DB-value → UI-label map onto the `TimeEntry`
model as a public `CATEGORY_LABELS` constant (alongside the existing `CATEGORIES`
list). Three consumer sites now read from it:

1. `_field-category-sheet.blade.php` — the `@foreach` over hardcoded
   `['installation' => 'Installation', …]` now iterates `TimeEntry::CATEGORY_LABELS`.
2. `field.blade.php` (JS guard in `submitCategory`) — the hardcoded
   `['installation', 'commissioning', …].includes(…)` allow-list is now rendered
   via `@json(\App\Models\TimeEntry::CATEGORIES)` so the client guard can't drift
   from the server enum. (Had to be careful not to mention the `@json` directive
   by name inside a surrounding `//` comment — Blade processes `@directive`
   everywhere, including inside `<script>` comments, so naming the directive
   verbatim creates `json_encode(,15,512)` and a PHP parse error; reworded the
   comment to avoid the literal directive reference.)
3. `_actual-hours-widget.blade.php` — the hardcoded `$categories` assoc array now
   iterates `CATEGORY_LABELS` and merges in widget-local colours via a
   `$categoryColours` map keyed by `TimeEntry::CATEGORY_*` constants. Adding a
   fifth category now only requires: adding the const + appending it to
   `CATEGORIES` + appending it to `CATEGORY_LABELS` in the model, plus deciding a
   colour in the widget.

Verified with `php artisan test --filter='FieldPage'` (7 passed) and
`php artisan test --filter=ActualHours` (6 passed).

### IN-03: `editEntry` treats empty-string notes as a valid value (unlike `stop`)

**Files modified:** `rams.21stcav.com/app/Services/TimeEntryService.php`
**Commit:** 1a109ae
**Applied fix:** Aligned `editEntry()`'s note normalisation with `stop()`'s. When
`$field === TimeEntryAudit::FIELD_NOTES`, the service now runs the same
trim-then-null-coerce pipeline before the length check:

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

This means a retro-edit to an empty/whitespace string now stores `NULL` in
`time_entries.notes` and records `null` as the audit row's `new_value`, matching
`stop()` exactly. Verified with `php artisan test --filter=TimeEntryEdit` —
8 passed / 0 failed (including `note over 500 rejected` which still triggers the
length guard correctly).

### IN-04: Actual Hours widget has a redundant ternary

**Files modified:** `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php`
**Commit:** 7a76ddf
**Applied fix:** Simplified line 60 from `width: {{ $mins > 0 ? $pct : 2 }}%` to
`width: {{ $pct }}%`. `$pct` is already clamped to `>= 2` via
`max(2, (int) round(...))` in the `@php` block, so the ternary's false branch
was unreachable. Added an inline comment explaining why to forestall future
"defensive" re-introductions. Verified with `php artisan test --filter=ActualHours`
(6 passed).

### IN-06: `per_category` fallback in widget hardcodes the four keys again

**Files modified:** `rams.21stcav.com/resources/views/projects/_actual-hours-widget.blade.php`
**Commit:** 684fd66 (shared with IN-02)
**Applied fix:** Replaced the hardcoded fallback
`['installation' => 0, 'commissioning' => 0, 'testing' => 0, 'other' => 0]`
with `array_fill_keys(\App\Models\TimeEntry::CATEGORIES, 0)`. The fallback shape
now tracks the enum automatically — if a fifth category is added, the widget
will zero it in correctly without a silent drop. Combined into the IN-02 commit
because the cleanup shares the same file / motivation.

## Not Applicable

### IN-05: `ProjectController::show` eager-loads `installProgrammes` but the widget uses a separate aggregate query

**File:** `rams.21stcav.com/app/Http/Controllers/ProjectController.php:130, 232-240`
**Status:** `not_applicable`
**Reason:** Per REVIEW.md: "No Phase 15 bug… Flagged only so the reviewer can
confirm the widget's per-page query count is acceptable… **Fix:** None required."
This is an awareness note, not an actionable finding. The per-phase budget
(one additional aggregate query per `projects.show` render for owners + admins)
was accepted during Plan 15-05 execution and no code change is requested.
Recorded here so the orchestrator can close the finding out rather than leave
it hanging.

## Verification Summary

Ran phase-relevant PHPUnit filters after each fix (iteration 2):

| Filter                                    | Tests | Result |
|-------------------------------------------|-------|--------|
| `FieldView` (after IN-01, IN-02)          | 10    | pass   |
| `FieldPage` (after IN-02)                 | 7     | pass   |
| `ActualHours` (after IN-02, IN-04, IN-06) | 6     | pass   |
| `TimeEntryEdit` (after IN-03)             | 8     | pass   |

No regressions introduced by any iteration-2 commit.

## Commit Log (chronological)

```
5858e29 fix(15): WR-01 re-verify staleness inside lockForUpdate in closeStaleSessions
43c2855 fix(15): WR-02 surface TimeEntryEditException message on heartbeat 422
c641f62 fix(15): IN-01 remove dead clock.error state from field view
684fd66 fix(15): IN-02 IN-06 centralise category enum via TimeEntry::CATEGORY_LABELS
1a109ae fix(15): IN-03 trim notes to null in editEntry to match stop() behaviour
7a76ddf fix(15): IN-04 drop redundant ternary in actual-hours widget bar width
```

---

_Fixed: 2026-04-21T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 2 (combined summary covering iterations 1 + 2)_

# Phase 15: Time Tracking — Context

**Gathered:** 2026-04-21
**Status:** Ready for planning
**Requirements:** INST-04, INST-04a, INST-04b, INST-04c, INST-04d, INST-04e, INST-04f, INST-04g, INST-04h, INST-04i
**Depends on:** Phase 12 (programmes), Phase 14 (time_entries schema + TimeEntryService foundation)

<domain>
## Phase Boundary

Extend Phase 14's `time_entries` foundation into a full time-tracking feature:

1. **Category selection** — engineer picks one of 4 categories per session (installation / commissioning / testing / other)
2. **Server heartbeat** — 60s ping updates `last_heartbeat_at` so sessions can be detected as alive/stale
3. **Stale-session auto-close** — `programme:close-stale-sessions` artisan command closes sessions inactive > 2h
4. **Project dashboard widget** — PM/admin see total hours + per-category breakdown on the project show page
5. **Schema extension + notes field** — add `category` enum and `notes` text columns
6. **Post-clock-out editing** — engineers can correct their own finished entries with audit log

Not in scope (deferred per INST-04i): budget comparison, cross-project time reports, per-engineer performance reports, export/CSV.

Not in scope (not required by INST-04 family): calendar view, manual time entry creation, overtime thresholds.

</domain>

<decisions>
## Implementation Decisions

### Category selection UX

- **D-01** — Clock-in opens a **bottom-sheet** with 4 category pills (installation / commissioning / testing / other). Engineer taps one to confirm and start the session. Matches the Phase 14 blocked-reason sheet pattern for consistency.
- **D-02** — **First clock-in per device** requires explicit pick. Subsequent clock-ins **pre-select the last-used category** (stored in localStorage keyed by user_id) — one-tap confirm. Still requires a tap so engineer can change if starting a different activity.
- **D-03** — **Category locked to session** — no mid-session change. To switch activity, engineer clocks out and clocks in again with the new category. Produces one entry = one category = accurate per-category durations.
- **D-04** — **Engineers can edit category on their own finished entries**. Past entries get an inline edit control (scoped via ownership guard to the entry's `user_id`). Edits are logged to a new `time_entry_audits` table (who, when, old value, new value) so ops can detect retcon patterns.
- **D-05** — Admins can also edit any finished entry (same edit control, no audit-log bypass).

### Notes

- **D-06** — **Optional note on clock-out** — inline prompt `Add a note? (optional)` after tap-to-clock-out. Skip defaults to `null`. Captured via the same sheet pattern as the reason input. Max 500 chars.
- **D-07** — Notes are **editable post-clock-out** via the same retro-edit flow as category (D-04). Also audit-logged.

### Heartbeat & offline behaviour

- **D-08** — Heartbeat runs **silently** — no visible indicator. Out-of-sight when working. Failure handling happens server-side (stale-close) not client-side banners.
- **D-09** — **Exponential retry on failure** — 60s → 120s → 300s → stays at 300s until a success resets the cycle. Session stays open locally; local chip timer keeps ticking (approximate but OK — stale-close is the safety net).
- **D-10** — **Pause heartbeat when tab hidden** via Page Visibility API (`document.hidden`). Resume on focus. Fires an immediate catch-up ping on focus-regain so liveness is re-established instantly. Reduces battery drain.
- **D-11** — **`clocked_out_at = last_heartbeat_at`** when `programme:close-stale-sessions` closes an entry. Prevents phantom hours from the 2h gap between heartbeat stop and scheduled run. Falls back to `clocked_in_at + 1min` if `last_heartbeat_at IS NULL` (e.g. server never got a heartbeat at all).
- **D-12** — Stale-close writes `closure_reason = 'stale_auto_close'` to a new column so dashboard can distinguish manual stops from auto-stops. Entry is otherwise indistinguishable — no separate state.

### Dashboard widget

- **D-13** — Widget lives in the **top summary row** of the project show page, alongside progress bar and status pill. New card titled "Actual Hours".
- **D-14** — Content: **Total hours** prominent (large number) + **per-category breakdown** as 4 rows. No per-engineer detail in v1. No date filter (actuals for entire project lifetime).
- **D-15** — Breakdown visual: **horizontal bar chart** — 4 stacked bars, one per category, sized by hours. Brand-teal palette (full teal for installation, teal-hover for commissioning, teal-mid for testing, text-muted for other). Pure CSS — no chart library dependency.
- **D-16** — **Visible to project owner + admin only** (via ownership guard pattern from Phase 14). Engineers assigned to the project see the project page but not the widget — avoids individual-hours exposure.

### Scheduler

- **D-17** — `programme:close-stale-sessions` runs **hourly** via Laravel scheduler in `app/Console/Kernel.php` (or `routes/console.php` per Laravel 12 pattern — whichever the existing commands use). Reactive enough to catch stale sessions within an hour of the 2h mark.
- **D-18** — Stale-close logs a `Log::warning` with `user_id`, `project_id`, `entry_id`, `last_heartbeat_at`, `closed_at`. No email to engineer. No PM notification. Log-only — ops reviews logs periodically.

### Timezone (from INST-04f)

- **D-19** — All DB storage in **UTC** (`->timestamp()` with default Laravel UTC). Display in **Europe/London** BST/GMT-aware via `Carbon::setTimezone('Europe/London')` at the presentation layer only — never for storage. Applies to both the clock chip's elapsed display and the dashboard widget totals.

### Claude's Discretion

- Close-stale-sessions cadence — locked to hourly (D-17) but exact cron syntax left to planner
- Live-data migration: if dev DB has Phase 14 time_entries rows, backfill `category = 'other'` and `closure_reason = NULL`. Flag any open (`clocked_out_at IS NULL`) rows for stale-close on first command run
- Bottom-sheet exact layout — planner may refine (pill size, spacing, icon vs text-only)
- Audit log table shape — `time_entry_audits(id, time_entry_id, edited_by_user_id, field, old_value, new_value, edited_at)` is the baseline; planner may extend
- Clock-out note sheet exact UX — planner chooses whether it's a separate sheet or inline below the chip
- Page Visibility API implementation — the `visibilitychange` listener wiring is a planner detail
- Horizontal bar chart exact sizing — planner picks bar heights, gap, label placement
- Per-category colour exact hex beyond the teal-family defaults above
- `programme:close-stale-sessions` handling of concurrent writes — planner ensures `lockForUpdate` pattern consistent with Phase 14 TimeEntryService

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 14 foundation (already shipped)
- `database/migrations/2026_04_20_000002_create_time_entries_table.php` — baseline schema to extend (add `category`, `notes`, `closure_reason`)
- `app/Services/TimeEntryService.php` — `start()` / `stop()` methods with `DB::transaction + lockForUpdate` (INST-04g). Extend for heartbeat, stale-close, category support
- `app/Http/Controllers/TimeEntryController.php` — existing 422 translation via `ClockInBlockedException`. Extend with `heartbeat()` endpoint and `update()` for retro-edit
- `app/Exceptions/ClockInBlockedException.php` — pattern for domain → HTTP error translation
- `app/Models/TimeEntry.php` — add new fillable fields + relationships
- `resources/views/install-programmes/field.blade.php` — existing clock chip Alpine component. Extend with bottom-sheet for category + note-on-stop sheet

### Project-level refs
- `.planning/REQUIREMENTS.md` §INST-04 — 10 requirements (INST-04, INST-04a through INST-04i)
- `.planning/ROADMAP.md` §"Phase 15: Time Tracking" — goal + 5 success criteria
- `.planning/phases/14-mobile-field-view/14-CONTEXT.md` — Phase 14 locked decisions (D-09 photo schema pattern, D-11 fail-loud ethos, INST-04g one-entry guard)
- `./CLAUDE.md` — thin-controller + service-layer pattern, queue-based async, no AI for scope invention

### Laravel framework (for planner research)
- Laravel 12 scheduler docs: `app/Console/Kernel.php` or `routes/console.php` for cron entry
- `Carbon::setTimezone('Europe/London')` docs for display-only timezone conversion
- Page Visibility API (MDN): `document.hidden` + `visibilitychange` event

### No new external specs
Requirements fully captured in INST-04 family + Phase 14 precedent.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`TimeEntryService::start/stop`** — already handles the race-free guard via `DB::transaction + lockForUpdate`. Phase 15 extends this pattern for heartbeat + stale-close, not replaces it.
- **`ClockInBlockedException` → 422 translation** — controllers throw domain exceptions; translator middleware maps them. Reuse for any new edit conflicts (e.g. `CannotEditClosedEntryException` if we need one).
- **Phase 14 bottom-sheet Blade pattern** — `resources/views/install-programmes/_field-sheet.blade.php` bottom-sheet for blocked-reason. Copy the structure for category picker and clock-out note sheet.
- **Alpine clock chip component** in `field.blade.php:47-80` — `toggleClock()`, `clockChipClasses()`, `_tickHandle` for setInterval. Extend with heartbeat interval + visibility listener.
- **Existing project show page layout** — top summary row is a `.card-sm` grid. New "Actual Hours" card slots in without restructuring.

### Established Patterns
- **Thin controller + service pattern** — controllers validate + authorise, services do the work. Every Phase 14 controller follows this. Heartbeat endpoint + retro-edit follow the same.
- **Ownership guards via `abort_if`** — canonical pattern from Phase 14 (`InstallProgrammeController::field()`, `TaskPhotoController::show()`). Project owner OR admin OR assigned engineer. Dashboard widget visibility (D-16) is stricter: owner + admin only.
- **AJAX state updates without page reload** — Phase 14's tap-to-advance precedent. Retro-edit of past entries follows the same `fetch()` + toast pattern.
- **Heartbeat as POST endpoint with CSRF** — not a WebSocket. Simple Axios POST per INST-04d.

### Integration Points
- **Clock chip in field.blade.php** — bottom-sheet for category opens in the same Alpine scope that holds the clock state. Category pre-select comes from `localStorage.getItem('last-category-' + user_id)`.
- **Project show page** — `resources/views/projects/show.blade.php` top summary row needs a new partial `_actual-hours-widget.blade.php`.
- **Laravel scheduler** — add `->hourly()->name('programme:close-stale-sessions')` to existing scheduler config. Use `withoutOverlapping()` so the command never runs twice concurrently.
- **Audit log** — new `time_entry_audits` table + model. Write from a single service method `TimeEntryService::editEntry()` so both engineer and admin edits produce an audit row.

</code_context>

<specifics>
## Specific Ideas

- Brand-teal palette for the breakdown bar: installation = `var(--teal)`, commissioning = `var(--teal-hover)`, testing = `var(--teal-mid)`, other = `var(--text-muted)` — keeps visual weight hierarchy (installation is the most time, most saturated).
- Category labels on the widget: "Installation" / "Commissioning" / "Testing" / "Other" (title case). DB values stored lowercase.
- Elapsed time format on clock chip: `0:07` for <1h, `1:23` for 1-9h, `12h 34m` for 10h+. Display-only; storage is precise timestamps.
- Heartbeat endpoint: `POST /time-entries/{entry}/heartbeat` (no body, 204 response). Ownership guard: `entry.user_id === auth()->id()`.

</specifics>

<deferred>
## Deferred Ideas

- **Budget comparison** — INST-04i explicit deferral, post-v1.2 once labour source (QuoteWerks lines?) is decided
- **Cross-project time view** — "my hours across all projects" for engineers. Not in INST-04 scope
- **Date range filter on dashboard widget** — gold-plating per INST-04h. v1 shows lifetime totals
- **Per-engineer roll-up on dashboard** — INST-04h only requires per-category, not per-engineer. Discussed; declined for privacy
- **PM email/push notification on stale-close** — log-only in v1 (D-18). Add if ops flags false-positive noise
- **Export to CSV / payroll integration** — not in scope, no requirement
- **Calendar view of time entries** — not in scope
- **Manual time entry creation (non-clock-based)** — not in scope; admins can retro-edit existing entries but can't invent new ones without a clock cycle

</deferred>

---

*Phase: 15-time-tracking*
*Context gathered: 2026-04-21*

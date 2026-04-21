# Phase 15: Time Tracking — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-21
**Phase:** 15-time-tracking
**Areas discussed:** Category Selection UX, Heartbeat & Offline Behaviour, Dashboard Widget Design
**Areas skipped:** Live-data Migration + Close-stale Scheduling (user chose not to discuss — captured as Claude's Discretion in CONTEXT.md)

---

## Area 1: Category Selection UX

### Q1: Where does the engineer pick the category?

| Option | Description | Selected |
|--------|-------------|----------|
| Bottom-sheet on clock-in (Recommended) | Tap Clock-in → bottom sheet with 4 category pills → confirm | ✓ |
| Inline pill group above the chip | 4 pills always visible; tap pill then tap chip | |
| Default 'installation', tap chip to edit | Clock in with default; edit mid-session via menu | |
| Per-day lock at first clock-in | Prompt on first clock-in of day; reuse for rest | |

**User's choice:** Bottom-sheet on clock-in — matches Phase 14 blocked-reason sheet pattern for consistency.

### Q2: Can the engineer change the category mid-session?

| Option | Description | Selected |
|--------|-------------|----------|
| No — category locked to session (Recommended) | Clock out + clock in again to switch | ✓ |
| Yes — edit updates the whole session | Retroactively changes already-logged time | |
| Yes — splits into two entries automatically | Auto-close current, auto-open new | |

**User's choice:** No — category locked to session. Produces cleaner actuals per category.

### Q3: Can the engineer edit the category on a finished entry later?

| Option | Description | Selected |
|--------|-------------|----------|
| No — sealed on clock-out (Recommended) | Immutable; admin override only | |
| Yes — engineer can edit their own past entries | Edit button on own entries; audit log needed | ✓ |
| Admin-only retroactive edit | Engineer can't, admin can | |

**User's choice:** Yes — engineers edit their own. Decision implies audit log requirement (captured in CONTEXT.md D-04 — new `time_entry_audits` table).

### Q4: When does the engineer add notes?

| Option | Description | Selected |
|--------|-------------|----------|
| Optional free-text on clock-out (Recommended) | Inline prompt after tap-to-clock-out | ✓ |
| Available on past-entry edit only | No clock-out prompt; edit later | |
| Always prompt at clock-out (required) | Block clock-out on empty note | |
| Not needed this phase | Schema column exists, UI deferred | |

**User's choice:** Optional on clock-out. Max 500 chars per CONTEXT.md D-06.

---

## Area 2: Heartbeat & Offline Behaviour

### Q1: Visible heartbeat state or silent?

| Option | Description | Selected |
|--------|-------------|----------|
| Silent — no visible indicator (Recommended) | Background ping; show nothing unless failing | ✓ |
| Always-on 'last sync' micro-indicator | Tiny dot with timestamp, green/amber/red | |
| Only show when failing | Banner after 2 missed heartbeats | |

**User's choice:** Silent. Failure handled server-side (stale-close) not via UI.

### Q2: What happens on heartbeat failure?

| Option | Description | Selected |
|--------|-------------|----------|
| Exponential retry; keep session running (Recommended) | 60s → 120s → 300s; client keeps chip running | ✓ |
| Retry 3 times then auto-clock-out client-side | Aggressive; false-close risk | |
| Linear 60s retry forever | Simple but constant traffic during outage | |

**User's choice:** Exponential retry with session staying open locally. 2h stale-close is the safety net.

### Q3: How should `clocked_out_at` be set when auto-closed?

| Option | Description | Selected |
|--------|-------------|----------|
| clocked_out_at = last_heartbeat_at (Recommended) | Accurate; prevents phantom hours | ✓ |
| clocked_out_at = now() at close time | Over-reports by up to 2h | |
| Zero-duration + manual review flag | Conservative but ops overhead | |

**User's choice:** Use `last_heartbeat_at`. Most accurate actuals.

### Q4: Should the tab keep heartbeating in background?

| Option | Description | Selected |
|--------|-------------|----------|
| Pause when tab hidden (Recommended) | Page Visibility API; accepts stale-close at 2h for pocketed phone | ✓ |
| Keep heartbeating in background | Better for open-tab-while-working | |
| Pause when hidden + ping on focus regain | Hybrid; best UX, slightly more wiring | |

**User's choice:** Pause when hidden. Note: CONTEXT.md D-10 adds the focus-regain catch-up ping from option 3 as an enhancement, since it was mentioned in the option descriptions and pairs naturally with pause-on-hidden.

---

## Area 3: Dashboard Widget Design

### Q1: Where does the widget live?

| Option | Description | Selected |
|--------|-------------|----------|
| Top summary row, alongside progress/status (Recommended) | New card next to existing summary cards | ✓ |
| Dedicated 'Time' tab on project page | Clean separation, one click away | |
| Below programme section, full-width | More space but requires scrolling | |
| Both top summary AND below programme | Duplicates data | |

**User's choice:** Top summary row. High visibility, consistent with existing project page layout.

### Q2: What detail does the widget show?

| Option | Description | Selected |
|--------|-------------|----------|
| Total hours + per-category breakdown (Recommended) | Matches INST-04h exactly | ✓ |
| + per-engineer roll-up | Reveals individual hours | |
| + date range filter | Adds UI; INST-04h doesn't require it | |

**User's choice:** Total + per-category only. INST-04h compliance without gold-plating.

### Q3: How should the breakdown render?

| Option | Description | Selected |
|--------|-------------|----------|
| Horizontal bar chart (Recommended) | Stacked bars, teal palette, pure CSS | ✓ |
| Simple labelled list | Plain text, smallest footprint | |
| Donut/pie chart | Needs chart library dependency | |
| Icon + count grid | Most polished; most code | |

**User's choice:** Horizontal bar chart. Brand-consistent, no new JS dep.

### Q4: Visibility — who sees the widget?

| Option | Description | Selected |
|--------|-------------|----------|
| Project owner + admin (Recommended) | Engineers see project page but not widget | ✓ |
| Everyone with project access | Includes assigned engineers | |
| Owner+admin see full; engineers see their own | Tiered visibility | |

**User's choice:** Owner + admin only. Avoids individual-hours exposure to peers.

---

## Claude's Discretion (user declined to discuss)

- Close-stale-sessions cadence: locked to hourly in CONTEXT.md D-17 based on 2h stale threshold
- Live-data migration for existing Phase 14 rows: backfill `category = 'other'`, flag open rows for stale-close on first command run (CONTEXT.md "Claude's Discretion" block)
- Audit-log table exact shape: `time_entry_audits` with id, entry_id, edited_by_user_id, field, old_value, new_value, edited_at
- Bottom-sheet exact layout, clock-out note sheet UX, bar chart sizing details — planner can refine

## Deferred Ideas

- Budget comparison (INST-04i explicit post-v1.2 deferral)
- Cross-project time view
- Date range filter on dashboard widget
- Per-engineer roll-up on dashboard
- PM email/push on stale-close (log-only in v1)
- Export to CSV / payroll integration
- Calendar view
- Manual time entry creation (non-clock-based)

---

*Audit log — not for agent consumption. See CONTEXT.md for decisions used by downstream agents.*

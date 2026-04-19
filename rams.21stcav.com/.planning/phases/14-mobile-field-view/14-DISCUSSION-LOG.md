# Phase 14: Mobile Field View - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 14-mobile-field-view
**Areas discussed:** Layout & navigation, Task status interaction, Photo capture flow

---

## Gray Area Selection

| Option | Description | Selected |
|--------|-------------|----------|
| Layout & navigation | Room/task organisation; clock in/out placement; task scope filter; programme progress display | ✓ |
| Task status interaction | Tap model; blocked/skipped handling; regression policy; save feedback | ✓ |
| Photo capture flow | Count per task; completion requirement; HEIC approach; captions | ✓ |
| Clock in/out scope | How Phase 14 slots into Phase 15's `time_entries` schema | |

**Not selected:** Clock in/out scope — moved to Claude's Discretion in CONTEXT.md.

---

## Layout & Navigation

### Q1: What's the primary screen organisation for the field page?

| Option | Description | Selected |
|--------|-------------|----------|
| Room sections, tasks within | Scrollable list of rooms; collapsible sections with per-room progress counter | ✓ |
| Flat task list, room as header | One long list; room name is a sticky subheader | |
| Tab per room | Room picker tabs; one room's tasks visible at a time | |

### Q2: What's the engineer's default task scope when they open the page?

| Option | Description | Selected |
|--------|-------------|----------|
| Their assigned tasks only | Filter: `assigned_to = auth()->id()`; admin/PM see all; toggle for "Show all" | ✓ |
| Today's assigned tasks only | Assigned + date window | |
| All programme tasks always | No filter | |

### Q3: Where do clock in/out controls live on the page?

| Option | Description | Selected |
|--------|-------------|----------|
| Sticky top bar above task list | Persistent header with project + status + button | ✓ |
| Fixed bottom action bar | Thumb-reach bar pinned to bottom | |
| Collapsed "Session" panel at top | Tap to expand for in/out + elapsed time | |

### Q4: How should programme-level progress be displayed?

| Option | Description | Selected |
|--------|-------------|----------|
| Progress bar + "X of Y tasks complete" | Linear bar + count | ✓ |
| Percentage only | Just `67% complete` as text | |
| Segmented bar by status | Colour-coded segments for pending/in_progress/complete | |

**Notes:** All four recommended options taken. User moved directly to next area.

---

## Task Status Interaction

### Q1: How does the engineer change a task's status?

| Option | Description | Selected |
|--------|-------------|----------|
| Tap-to-advance cycle | pending → in_progress → complete; stops at complete | ✓ |
| Explicit status buttons | Each task shows a pill per status | |
| Modal on tap | Bottom-sheet with status + notes + photos | |

### Q2: How are the 'blocked' and 'skipped' statuses surfaced?

| Option | Description | Selected |
|--------|-------------|----------|
| Overflow menu (⋮) per task | Hidden behind overflow; require a reason note | ✓ |
| Always visible alongside main statuses | Treated equally with pending/in_progress/complete | |
| Separate "Flag issue" button per task | Distinct CTA that opens a modal | |

### Q3: Can engineers regress a task from 'complete' back to 'in_progress'?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, via overflow menu | Allowed but off the main path; audit log entry | ✓ |
| Yes, any status change inline | Tapping complete cycles back to pending | |
| No, completion is final (admin only) | Engineer cannot regress | |

### Q4: What visual confirmation does the engineer get when a status save succeeds?

| Option | Description | Selected |
|--------|-------------|----------|
| Inline row state change + brief checkmark pulse | Row animates; no toast | ✓ |
| Toast notification at top | "Task marked complete" slides in for 2s | |
| Haptic only (vibration) | `navigator.vibrate()` | |

**Notes:** All recommended. User moved to next area.

---

## Photo Capture Flow

### Q1: How many photos per task should engineers be able to capture?

| Option | Description | Selected |
|--------|-------------|----------|
| Multiple photos (gallery per task) | New `install_task_photos` table, N rows per task, mirrors `site_survey_photos` | ✓ |
| One photo per task | Single column on `install_tasks` | |
| Up to 3 per task (UI cap) | N rows, UI-capped | |

### Q2: Are photos required for a task to be marked complete?

| Option | Description | Selected |
|--------|-------------|----------|
| Optional (recommended only) | UI nudges but doesn't block completion | ✓ |
| Required — cannot complete without ≥1 photo | Hard block | |
| Per-task configurable | `requires_photo` flag per task type | |

### Q3: Which HEIC → JPEG conversion approach for iOS photos?

| Option | Description | Selected |
|--------|-------------|----------|
| Intervention Image (Imagick driver, synchronous) | `composer require intervention/image` + PHP imagick extension; fails loudly if extension missing | ✓ |
| GD only (no Imagick) | Cannot decode HEIC natively; would need CLI fallback | |
| Queue job (async) with Intervention + Imagick | Upload saves original, dispatches conversion job | |

### Q4: Should photos support captions?

| Option | Description | Selected |
|--------|-------------|----------|
| Optional caption, inline | Saves on blur via AJAX; mirrors `site_survey_photos.caption` | ✓ |
| No captions | Pure photos; engineers use per-task notes instead | |
| Caption required on upload | Must enter text before upload completes | |

**Notes:** All recommended. User chose "I'm ready for context" — wrap up.

---

## Claude's Discretion

Areas the user did not pick to discuss, and sub-decisions inside picked areas that didn't need questions. Captured as Claude's Discretion in CONTEXT.md:

- Clock in/out backend wiring — minimal `time_entries` schema in Phase 14, Phase 15 extends
- Notes input pattern — inline auto-expanding textarea, save on blur
- Empty state — "No tasks assigned to you yet" + "Show all" link
- Photo thumbnail layout — horizontal scrolling strip, 80×80
- Max photo size — 20 MB
- Storage path — `storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg`
- Upload feedback — placeholder thumbnail swapped for real thumbnail on success
- Default room expansion — all rooms expanded on load

## Deferred Ideas

- Offline / service worker / localStorage queue — INST-03h is online-only
- Time tracking categories, heartbeat, stale-session closure — Phase 15
- Commissioning evidence, signatures, snagging PDF — Phase 16
- Per-task-type `requires_photo` flag — Phase 16 if needed
- Task-status audit trail UI — out of scope here
- Push notifications on task reassignment — not on any current roadmap
- Backfill HEIC conversion for existing `site_survey_photos` — follow-up todo

# Phase 16: Commissioning Checklist & Sign-off — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `16-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-04-21
**Phase:** 16-commissioning-checklist-signoff
**Areas discussed:** Item generation, Signature flow
**Areas declined for discussion (Claude's Discretion in CONTEXT.md):** Checklist UX, Completion rules

---

## Item Generation

### Q1 (initial) — Mapping source for equipment-type → AVIXA categories

| Option | Description | Selected |
|--------|-------------|----------|
| Static PHP config | `config/commissioning.php` with equipment-keyword → categories array. All in git. (Recommended) | |
| DB-editable table | `equipment_avixa_categories` table + admin UI. Flexibility + ongoing maintenance. | |
| Engineer picks per item | Items created with all 7 categories; engineer marks N/A on-site. | |
| AI-suggested at generation | Claude / OpenAI suggests mapping. Violates PROJECT.md "AI never invents scope". | |

**User's initial response:** Free-text about part/brand TBC flagging + uppercase part numbers + AI one-sentence description
**Resolution:** User clarified the comment was meant for a different project — discarded.

### Q1 (re-asked) — Mapping source

| Option | Description | Selected |
|--------|-------------|----------|
| Static PHP config | `config/commissioning.php`. (Recommended) | ✓ |
| DB-editable table | admin UI. | |
| Engineer picks per item | zero config, more taps. | |
| Let me describe it | freetext | |

**User's choice:** Static PHP config
**Notes:** No admin surface; edits via deploy; aligns with no-AI-scope constraint.

### Q2 — Item grain (per-equipment-instance vs. per-unique-name)

| Option | Description | Selected |
|--------|-------------|----------|
| Per equipment instance | Three identical displays → three separate items per category. (Recommended) | ✓ |
| Per unique name | One item covers all three displays. Fewer taps; loses per-unit traceability. | |
| Per room × equipment type | One item per room × type. Middle ground. | |

**User's choice:** Per equipment instance
**Notes:** Matches install_tasks grain; preserves per-unit audit trail.

### Q3 — Lifecycle trigger for commissioning_items generation

| Option | Description | Selected |
|--------|-------------|----------|
| On programme complete | Auto-generate when last install_task hits complete. (Recommended) | ✓ |
| On programme creation | Generated eagerly at Phase 12 programme creation; staleness risk. | |
| Lazy on first view | Engineer taps "Start commissioning"; binds to user action. | |
| Admin button | Explicit trigger; extra step to forget. | |

**User's choice:** On programme complete
**Notes:** Aligns generation with the workflow moment where equipment reality is final.

### Q4 — Drift handling when equipment changes post-generation

| Option | Description | Selected |
|--------|-------------|----------|
| Re-sync preserves statuses | "Re-sync from programme" button; diff summary; preserves unchanged, adds new, soft-deletes removed. (Recommended) | ✓ |
| Locked once generated | Frozen after generation; admin intervenes manually. | |
| Always regenerate | Wipe + rebuild; preserves statuses only when names identical. Risk of silent data loss. | |

**User's choice:** Re-sync preserves statuses
**Notes:** Matches data-integrity ethos; engineer sees a diff before confirming.

### Q5 — Equipment data source

| Option | Description | Selected |
|--------|-------------|----------|
| install_tasks rows | Phase 12 denormalised room × equipment. (Recommended) | ✓ |
| ProjectDataService::resolve() | Fresh 4-tier merge. Misses engineer-side swaps. | |
| project_packages equipment_list | Quote-level list. Misses install-time changes. | |

**User's choice:** install_tasks rows
**Notes:** Reflects what was physically installed, not what was quoted.

### Q6 — Keyword matching strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Case-insensitive contains | Config substrings match equipment_name lowercased. (Recommended) | ✓ |
| Regex per keyword | More power; more breakage. | |
| Prefix match on part-number | Precise; fights data quality. | |

**User's choice:** Case-insensitive contains
**Notes:** Simple and forgiving; matches typical AV equipment naming.

### Q7 — Unmatched-equipment fallback

| Option | Description | Selected |
|--------|-------------|----------|
| Skip (no items) | Generic hardware produces zero items. (Recommended) | ✓ |
| All 7 categories | Noisy; fights data quality. | |
| Cabling-only default | Lightweight catch-all for passive hardware. | |
| Flag for admin mapping | Extra workflow step per unmapped kit. | |

**User's choice:** Skip (no items)
**Notes:** Avoids forcing inappropriate categories onto passive hardware.

### Q8 — Category list (stick with 7 AVIXA or extend)

| Option | Description | Selected |
|--------|-------------|----------|
| Stick with 7 | Exactly INST-05e list. (Recommended) | ✓ |
| Add "End-user Training" | 8th category for handover briefing. | |
| Let me describe | freetext | |

**User's choice:** Stick with 7
**Notes:** Matches REQUIREMENTS.md verbatim; revisit with engineer feedback post-v1.2.

---

## Signature Flow

### Q1 — Capture medium

| Option | Description | Selected |
|--------|-------------|----------|
| In-person on engineer's device | Client signs on phone/tablet with finger/stylus. DPI-corrected. (Recommended) | ✓ |
| Emailed remote link only | Tokenised link; client signs on their own device. | |
| Both — engineer picks | Covers both scenarios; 2× implementation cost. | |

**User's choice:** In-person on engineer's device
**Notes:** Matches typical AV handover workflow; remote flow deferred.

### Q2 — Timing in the flow

| Option | Description | Selected |
|--------|-------------|----------|
| After snagging PDF preview | Preview → review → sign → re-generate with signature. (Recommended) | ✓ |
| Before PDF generation | Sign on summary; PDF bakes in signature after. Weaker audit defensibility. | |
| Per-room signature | Multi-signature; complexity multiplier. | |

**User's choice:** After snagging PDF preview
**Notes:** Client explicitly acknowledges the document they're signing.

### Q3 — Client metadata captured

| Option | Description | Selected |
|--------|-------------|----------|
| Name + role + company | Three freetext fields. (Recommended) | ✓ |
| Name + role only | Company inferred from project.client_id. | |
| Name + role + email + phone | Full contact capture; more fields to fill. | |
| Free-text block | Minimum structure; hardest to query. | |

**User's choice:** Name + role + company
**Notes:** Stored on new `commissioning_signoffs` row. Capture what the signing person states, not what's inferred.

### Q4 — Fail-item policy for sign-off

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — fails become snag list | Any pass/fail/na mix unlocks completion; fails roll to PDF. (Recommended) | ✓ |
| No — pass/na only | Strict; forces resolution before handover. | |
| Client chooses per-item | Accept-with-snag / Must-fix-first toggle per fail. More UI. | |

**User's choice:** Yes — fails become snag list
**Notes:** Standard AV-industry handover practice; perfect doesn't block handover.

### Q5 (meta) — More questions on Signature flow or write CONTEXT?

| Option | Description | Selected |
|--------|-------------|----------|
| More questions | Drill into engineer per-item sign-off, storage shape, certification text, re-sign rules. | |
| I'm ready for context | Remaining details → Claude's Discretion in CONTEXT.md. | ✓ |

**User's choice:** I'm ready for context

---

## Claude's Discretion (unselected areas + sub-details)

Areas the user declined to discuss, left to planner:

- **Checklist UX**: grouping, screen count, mobile vs. desktop — planner mirrors Phase 14 (room-grouped scrollable list on mobile)
- **Completion rules**: photo required on fail (audit defensibility), optional on pass/na; fail-reason note required (bottom-sheet pattern)
- **Engineer per-item sign-off**: auto-fill from `auth()->user()->name`; no PIN challenge for v1
- **Signature storage**: base64 PNG on `commissioning_signoffs` row (per INST-05f); not file on disk
- **Certification text above canvas**: planner drafts; review recommended before ship
- **Snagging PDF layout**: reuse DomPDF pattern from RAMS / Site Survey
- **Bottom-sheet reuse**: fork `_field-sheet.blade.php` into `_commissioning-fail-sheet.blade.php` + `_commissioning-signoff-sheet.blade.php`
- **Item ordering**: by room → equipment → category
- **Re-sync diff UI**: `N added / M removed / K unchanged` summary + confirm

---

## Deferred Ideas

- Emailed remote client-sign-off link (post-v1.2 follow-up)
- Engineer PIN / 2FA challenge for per-item sign-off
- Re-open completed commissioning (INST-05i blocks this in v1)
- AI-suggested AVIXA category mapping (violates PROJECT.md no-AI-for-scope)
- DB-editable AVIXA mapping admin UI (revisit if config churn becomes pain)
- 8th "End-user Training Completed" AVIXA category
- Multi-day / per-room client signature sessions
- Commissioning_item_audits retro-edit table (unnecessary with INST-05i immutability)
- Part/brand TBC flagging + uppercase + AI description (user confirmed wrong-project comment)
- `STATUS_COMMISSIONING → STATUS_HANDOVER` auto-advance (future phase)
- Re-generating snagging PDF after signature

---

*Generated by /gsd-discuss-phase 16 — audit trail for compliance / software review*

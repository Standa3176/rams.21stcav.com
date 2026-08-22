# Phase 260822-esf: Project Deliverables Selection — Discussion Log

**Date:** 2026-08-22
**Mode:** default (interactive)
**Areas offered:** Selection model · What 'only selected' means · Health + lifecycle · Defaults + rollout
**Areas selected initially:** first three. Defaults + rollout was declined, then covered after Claude flagged that three of its decisions had no safe default.

> Human reference only. Downstream agents read `260822-CONTEXT.md`, not this file.

---

## Area 1 — Selection model

**Q: How many states should each deliverable have?**
Options: Three states (Required / Not required / Not yet decided) [recommended] · Two states
**Selected:** Three states

**Q: If a deliverable is marked Not required, can a user still create one?**
Options: Soft — allow, auto-flip flag [recommended] · Hard — block until re-enabled · Soft, but warn first
**Selected:** Soft — allow, auto-flip flag

**Q: Should changes to the checklist be recorded?**
Options: Who, when and why [recommended] · Who and when only · No, current state only
**Selected:** Who, when and why

**Q: Which items belong on the checklist?** (multi-select)
Options: The five core documents · Add Install Programme · Add Drawings · Add Snagging
**Selected:** all four options, **plus user-added "Programming"**

**Follow-up:** Programming exists nowhere in the application. Claude asked what it
meant rather than assuming, offering: tracked flag only · a real deliverable to
build later · a tab to attach files to.
**Selected:** Tracked flag only, no document.

**Claude flagged:** Drawings and Snagging have no project tab today, and Snagging
has no edit adapter — so including them means the phase adds tabs, not just flags.

---

## Area 2 — What 'only selected' means

**Q: What happens to a Not-required deliverable in the project tab strip?**
Options: Muted, moved to the end [recommended] · Hidden entirely · Unchanged, health only
**Selected:** Muted, moved to the end
**Note:** recommendation was grounded in the UX-05 note at `show.blade.php:777-782`,
where empty-tab hiding was tried and deliberately reverted.

**Q: What if a deliverable already has data and someone marks it Not required?**
Options: Never hide — warn and keep visible [recommended] · Block the change · Hide but keep data
**Selected:** Never hide — warn and keep visible

**Q: Where is the checklist edited after setup?**
Options: Project Data tab [recommended] · Project edit page · Inline from the tab strip
**Selected:** Project Data tab

---

## Area 3 — Health + lifecycle

**Q: A project with Survey marked Not required — what happens to `STATUS_SURVEY_PENDING`?**
Options: Skip the status entirely [recommended] · Pass through instantly · Keep it, ignore in health
**Selected:** Skip the status entirely
**Note:** highest-risk decision in the phase — changes the status machine.

**Q: How should health treat a deliverable marked Not required?**
Options: Rule doesn't apply [recommended] · Treat as satisfied
**Selected:** Rule doesn't apply

**Q: How should health treat 'Not yet decided'?**
Options: Amber after a grace period [recommended] · Ignore entirely · Treat as Required
**Selected:** Amber after a grace period

**Q: Should a Required-but-missing deliverable block marking a project Completed?**
Options: Warn, don't block [recommended] · Hard block · No change to completion
**Selected:** Warn, don't block

---

## Area 4 — Defaults + rollout

Initially declined. Claude re-offered it, noting that defaults, placement and
back-catalogue retrofit had no safe default and would otherwise be invented by
the planner. User chose to discuss.

**Q: How should the checklist be pre-filled at import?**
Options: Derive from quote content [recommended] · Everything Required · Everything Not yet decided
**Selected:** Derive from quote content

**Q: Where does the checklist appear during import?**
Options: Step in the existing confirm flow [recommended] · Modal popup on import review · Setup card on the project page
**Selected:** Step in the existing confirm flow
**Note:** this deviates from the user's original "pop up" framing. The intent —
ask once, up front — is preserved; the mechanism was changed because a modal over
the dense review screen is the most likely thing to be dismissed unread. User
accepted the recommendation.

**Q: What happens to every existing project when this ships?**
Options: Infer from what exists [recommended] · Everything Required · Everything Not yet decided
**Selected:** Infer from what exists
**Note:** "Everything Not yet decided" was argued against explicitly — combined
with the amber grace period it would turn the whole project list amber on day one.

**Q: Manual projects are created via `ProjectService::create()`. How do they get a checklist?**
Options: Same checklist on the create form [recommended] · Default all Required, edit after · Default to Not yet decided
**Selected:** Same checklist on the create form

---

## Claude's discretion (recorded, not asked)

Grace-period duration; default state for manual projects with no quote; schema
shape and audit storage mechanism; copy and visual treatment of the "Not required"
grouping; where the amber prompt surfaces.

## Deferred ideas raised during discussion

- Programming document generator — own phase (D-05).
- `type=drawing` AI-edit surface is dead (found in `260817-w4k`) — made more
  visible by putting Drawings on the deliverables list.
- Snagging edit adapter — separate work.

## Scope creep redirected

**Programming** was the only genuinely new capability raised. Rather than
rejecting or silently absorbing it, Claude split it: the *flag* is in scope
(small, consistent with the phase), the *generator* is deferred to its own phase.

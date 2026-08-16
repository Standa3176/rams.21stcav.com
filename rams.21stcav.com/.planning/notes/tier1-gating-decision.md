---
date: "2026-08-16"
promoted: true
---

# Decision: Tier 1 "NO TBC POLICY" blocking gate is O&M-only — this is intentional, not an oversight

**Decided by:** user, 2026-08-16, during the QA pass that also surfaced the
test-health backlog cleared in quick task 260816-t5c.

## The asymmetry

The three document types this project generates enforce completeness very
differently at their render/build path:

- **O&M Manual** — `OmManualValidationService` (see
  `app/Services/OmManualValidationService.php`) runs a hard, blocking
  "NO TBC POLICY" check. Any required field left blank/absent throws
  `OmManualValidationException` (`app/Exceptions/OmManualValidationException.php`)
  **before** AI generation or rendering happens. A partially-scoped O&M
  Manual cannot be produced.
- **Worksheet** — `BuildWorksheetJob` (`app/Jobs/BuildWorksheetJob.php:87`)
  has a coarse binary gate: it throws only when there are **zero** rooms, or
  when **no room at all** has any content (no equipment, no steps, no
  pre-install answers). A worksheet with 8 rooms where 7 are empty and 1 has
  content passes this gate — it is not required that every room be complete.
- **Site Survey** — no blocking gate exists at the render/submit path at
  all. `SiteSurveyTierOneReadinessService`
  (`app/Services/Survey/SiteSurveyTierOneReadinessService.php`) computes a
  deterministic per-room readiness percentage and a `missing` checklist
  (AV scope, dimensions, power/network answers, pre-install Q&A, photos,
  engineer sign-off) — but it is read-only scoring for the UI. It never
  throws and never blocks a submit.

## The decision

Asked whether Worksheet and Site Survey should enforce the same blocking bar
as O&M Manual, the user's answer was: **partial scope is legitimate.**

A worksheet covering 8 rooms with 7 empty is a **valid document**, not a
defect — some rooms genuinely have no AV scope on a given job, and forcing
every room to carry content before a worksheet can be built would block real,
correctly-scoped output.

**No blocking gate is to be added to Worksheet or Site Survey.** The O&M-only
"NO TBC POLICY" gate stays exactly where it is; it is not a partial rollout
of a rule that should eventually reach the other two document types.

## Why this is not a bug

`SiteSurveyTierOneReadinessService` already exists and already computes the
same kind of per-room completeness signal that a blocking gate would need.
It is **deliberately advisory, not blocking** — it exists to inform the UI
(so an engineer/PM can see readiness percentage and what's missing), not to
prevent submission or generation. Its existence without an accompanying
`throw` is the intended shape, not a half-finished feature.

**A future contributor should not "fix" the missing Worksheet/Site Survey
gates by porting `OmManualValidationService`'s pattern over.** If a future
requirement genuinely calls for stricter enforcement on one of these
document types, that is a new decision requiring its own product
conversation — not a bug fix against this one.

## Related

- Quick task 260816-t5c (this decision was recorded as Item 3 of that task).
- Quick task 260816-ru4 — the survey `access_token` test repair that this
  same QA pass also produced.

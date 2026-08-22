---
phase: 260822-esf-project-deliverables-selection
status: awaiting-discussion
started: 2026-08-22
branch: feat/worksheet-classifier-universal (new phase branch may be cut at execute-time)
scope: Per-project deliverables checklist — which documents (Site Survey, RAMS, Worksheet, O&M, Cable Schedule) a project actually requires. Chosen at import/setup with defaults derived from the quote, editable afterwards, and respected by the project tab strip, ProjectHealthService and the lifecycle status machine.
estimated: TBD — set at plan-phase
plans: TBD
milestone: none (out-of-milestone RAMS-ops track, same as 260726-rf3 / 260727-wt1)
---

## Trigger

User request, 2026-08-22:

> "when a project is imported/setup i want a pop up / check box question that ask which items are needed for this project ie sitesurvey, rams, worksheet, om, etc. user can mod this selection after the project is setup, but once user has selected what they want, project setup to provide only the items selected."

Immediate provocation was the Volkswagen Blakelands import, where a
hardware-only supply section ("Digital Production") produced document
scaffolding for work that was never in scope.

## Root cause — the app cannot express "not required"

This is not primarily a UI-clutter problem. The application has no way
to distinguish **"this deliverable is missing"** from **"this deliverable
was never required"**, and three subsystems silently assume the former:

1. **`ProjectHealthService.php:50-53`** — any project in
   `STATUS_ENGINEERING` with no approved RAMS returns
   `red / "No approved RAMS in engineering"`. A hardware-only supply job
   is permanently red with no way to say the RAMS was never needed.
2. **`ProjectHealthService.php:56-60`** — same shape for surveys in
   `STATUS_SURVEY_PENDING` after 14 days.
3. **`Project::STATUS_SURVEY_PENDING`** is a lifecycle stage, so a
   project that needs no survey still has to transit a survey state.

Shipping only the tab-hiding half would leave the project nagging
red forever — users would learn to ignore the health colour, which is
worse than the status quo.

## Prior art in this repo — hiding was tried and reverted

`resources/views/projects/show.blade.php:777-782` carries the note:

> *"Re-audit UX-05 — was `@if($count > 0)` gate, so on a fresh project
> 7/9 tabs rendered label-only and the user couldn't tell which held
> data. Now render the count pill unconditionally (muted "0" for
> empties)."*

Empty tabs were previously hidden, users could not find things, and the
gate was deliberately removed in favour of always-render-with-muted-count.
Any design here that hard-hides deselected deliverables will reproduce
that regression, plus a worse variant: a tab that is gone with no visible
route to bring it back.

## No canonical deliverables list exists

Three lists disagree today, and the checklist needs exactly one:

| Source | Contents |
|---|---|
| Project tabs (`show.blade.php:764-774`) | surveys, rams, worksheets, cable, om, install, quotes, assets, data |
| `DocumentArtifactStorage::TYPE_*` | rams, om-manuals, worksheets, cable-schedules, snagging, drawings, site-surveys, reference-files |
| `DocumentEditAdapterRegistry` | rams, survey, worksheet, om, cable, drawing |

Note Quotes / Asset Register / Project Data are **inputs**, not
deliverables, and must not be selectable. Establishing the canonical
list is arguably the highest-value output of this phase.

## Goal

After this phase ships:

1. Every project carries an explicit, auditable record of which
   deliverables it requires.
2. That record is seeded automatically from quote content at import
   (no labour/install lines → RAMS, Worksheet and Survey default off)
   and confirmed rather than authored by the user.
3. It is editable at any time after setup.
4. `ProjectHealthService` does not penalise a project for a deliverable
   marked not-required.
5. The project tab strip de-emphasises not-required deliverables
   **without hiding them irrecoverably**.
6. A deliverable that already has data can never be hidden by a flag
   change.

## Open decisions for `/gsd-discuss-phase`

These are genuine product decisions, not implementation details. They
must be locked before planning.

- **D-?? Two states or three.** Required / Not required, or
  Required / Not required / Not yet decided. At import time nobody
  always knows; a forced boolean manufactures a wrong answer at the
  worst moment.
- **D-?? What "provide only the items selected" means visually.**
  Recommended: keep in the tab strip, moved to the end and muted under a
  "Not required" grouping, with an inline "add anyway" that flips the
  flag. Alternatives: hard-hide (see prior art above), or leave the UI
  untouched and change health/reporting only.
- **D-?? Lifecycle interaction.** Does a project with no survey skip
  `STATUS_SURVEY_PENDING` entirely, or pass through it instantly? This
  changes the state machine, not just a view.
- **D-?? Soft or hard gate.** If Survey is not-required, may a user
  still create one? Recommended soft — allow, and auto-flip the flag.
- **D-?? Audit trail.** Who marked a deliverable not-required, when, and
  why. "We did not produce an O&M because it was not required" is a
  contractual claim. Cheap now, painful to retrofit.
- **D-?? Entry-point coverage.** Projects are also created via
  `ProjectService::create()` (`app/Core/Modules/Projects/ProjectService.php:33`),
  not only quote import. Both paths need the checklist or the two
  diverge.
- **D-?? Where the checklist lives at import.** A modal on top of the
  already-dense import review screen risks being clicked through. A step
  in the existing confirm flow, or a dismissible setup card on the
  project page, may serve better.
- **D-?? Retrofit.** What happens to the existing project back-catalogue
  — default everything to required, or infer from what already exists?

## Explicitly out of scope

- Changing what any deliverable *contains* — this phase decides whether
  a deliverable is expected, not how it is generated.
- Snagging, Drawings and Reference Files unless discussion promotes them
  into the canonical deliverables list.
- `type=drawing` AI-edit surface (found dead in 260817-w4k; separate
  decision).

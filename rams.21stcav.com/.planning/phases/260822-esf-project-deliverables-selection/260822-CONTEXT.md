# Phase 260822-esf: Project Deliverables Selection - Context

**Gathered:** 2026-08-22
**Status:** Ready for planning

<domain>
## Phase Boundary

Every project carries an explicit, auditable record of which deliverables it
requires. The record is seeded at creation (derived from quote content where
one exists), editable at any time afterwards, and honoured by the project tab
strip, `ProjectHealthService`, and the project status machine.

This phase decides **whether a deliverable is expected**. It does not change
what any deliverable contains or how it is generated.

</domain>

<decisions>
## Implementation Decisions

### Selection model

- **D-01: Three states, not two.** Each deliverable is `Required` /
  `Not required` / `Not yet decided`. Rationale: at import nobody always
  knows, and a forced boolean manufactures a wrong answer at the moment the
  user knows least. The third state also makes an unreviewed default
  distinguishable from a deliberate choice.
- **D-02: Soft gate that auto-flips.** A user may create a deliverable that is
  marked `Not required`; doing so flips the flag back to `Required`
  automatically. No hard block anywhere. Rationale: scope changes constantly
  on real jobs, and hard gates get worked around — usually by disabling the
  feature entirely.
- **D-03: Full audit trail.** Every flag change records **who, when, and why**.
  The reason may be optional free text but who/when is automatic.
  Rationale: "we produced no O&M because it was not required" is a contractual
  claim and must be defensible. Cheap now, painful to retrofit.

### Canonical deliverables list

- **D-04: Nine selectable deliverables.** Site Survey, RAMS, Worksheet, O&M,
  Cable Schedule, Install Programme, Drawings, Snagging, **Programming**.
- **D-05: Programming is a tracked flag with NO generator.** It records that
  the project needs programming work (Crestron / Q-SYS config etc.) so it
  appears in scope and health. The application produces no Programming
  document. Building a Programming generator is explicitly NOT in this phase.
- **D-06: Quotes, Asset Register and Project Data are excluded.** They are
  inputs, not deliverables, and must not be selectable.
- **D-07: This list becomes the single source of truth.** Three lists disagree
  today — project tabs (9), `DocumentArtifactStorage::TYPE_*` (8), and
  `DocumentEditAdapterRegistry` (6). Planning must reconcile against D-04
  rather than adding a fourth list.
  ⚠ **Consequence:** Drawings and Snagging have **no project tab today**, and
  Snagging has no edit adapter. Including them means this phase adds tabs.
  Programming has neither and no model at all.

### Presentation

- **D-08: Muted and moved to the end — never hidden.** Not-required
  deliverables stay in the tab strip under a "Not required" grouping, with an
  inline "add anyway" that flips the flag. Rationale: `show.blade.php:777-782`
  records that empty tabs were previously hidden behind `@if($count > 0)` and
  the gate was **deliberately reverted** because users could not tell which
  tabs held data. Hard-hiding would reproduce that regression and add a worse
  one — a tab gone with no visible route back.
- **D-09: A deliverable holding data is never hidden**, regardless of flag
  state. Marking it not-required warns and leaves it fully visible.
  Deselecting states an intention about scope; it must never resemble a delete.
- **D-10: Edited from the Project Data tab** after setup — the tab that
  already exists for project-level settings. No new navigation.

### Health and lifecycle

- **D-11: A not-required Survey skips `STATUS_SURVEY_PENDING` entirely.**
  The project moves from `quote_imported` straight to `engineering`. Not a
  pass-through — the stage genuinely never happened and the audit trail should
  not claim it did. ⚠ This changes the status machine; anything assuming a
  fixed stage sequence must be found and updated.
- **D-12: Not-required deliverables drop out of the health calculation
  entirely** — not "treated as satisfied". No red, no amber, no mention.
  Treating them as satisfied would overstate progress percentages by counting
  work that was never done. This is the direct fix for the permanently-red
  hardware-only project.
- **D-13: "Not yet decided" goes amber after a grace period.** Long enough not
  to shout on day one, short enough that the state cannot become a permanent
  parking space. Grace duration is Claude's discretion (see below).
- **D-14: Completion warns, does not block.** Marking a project Completed with
  required-but-missing deliverables lists what is outstanding and asks for
  confirmation. Consistent with D-02.

### Defaults and rollout

- **D-15: Import defaults are derived from quote content.** No labour/install
  lines → RAMS, Worksheet and Survey default to `Not required`. The import
  already has this information. Rationale: an accurate default converts the
  checklist from a chore into a confirmation — and is the single strongest
  defence against it being clicked through unread.
- **D-16: The checklist is a step in the existing import confirm flow**, not a
  modal layered on the review screen. The review screen is already dense and
  actively worked; a modal there is the thing most likely to be dismissed
  unread.

> **⚠ CLARIFICATION — 2026-08-22, after pattern mapping (user-confirmed).**
> **D-16 means a NEW INTERSTITIAL STEP, not a fieldset.**
>
> Pattern mapping found there is no multi-step confirm flow to add a step to:
> review → confirm is a **single form** (`quote-import/review.blade.php:53-80`
> → `QuoteImportController::confirm()`). So "a step" had two possible readings.
>
> Locked reading: build a **distinct page between review and confirm** showing
> only the deliverables checklist — new route, view and controller action.
>
> Rejected: adding a fieldset to the review form. That would place the checklist
> on the same dense screen a modal was rejected for (D-16's original rationale),
> and a section low on a long form is the easiest thing of all to scroll past.
> Paying for a real step is the point of the decision, not an accident of it.
- **D-17: Existing projects are inferred from what already exists.** Has a
  RAMS → `Required`; none → `Not yet decided`. Rationale: preserves current
  health for active projects while flagging genuinely ambiguous ones.
  Explicitly rejected: defaulting the whole back-catalogue to
  `Not yet decided`, which combined with D-13 would turn the entire project
  list amber on day one — precisely how people learn to ignore the colour.
- **D-18: Manual projects get the same checklist on the create form.**
  `ProjectService::create()` is a real second entry point; without this the two
  paths diverge. No quote exists there, so it needs its own sensible default
  (Claude's discretion).

> **⚠ CORRECTION — 2026-08-22, after research (user-confirmed).**
> **D-18 is WITHDRAWN. Its premise was false.**
>
> D-18 assumed manual project creation was a live second entry point. It is not:
> - `ProjectController::create():78-81` is a hard `redirect()->route('quote-import.create')`,
>   so `resources/views/projects/create.blade.php` can never render.
> - The only form posting to `projects.store` lives inside that unreachable view,
>   making the store route effectively dead too.
> - The dashboard's two `projects.create` links (`dashboard.blade.php:10,141`)
>   therefore land on quote import.
>
> **Quote import is the ONLY live path to a project.** The checklist ships on the
> import confirm flow (D-16) and nowhere else. Do not build checklist UI on the
> manual-create form, and do not revive that form in this phase — reviving it is
> a separate feature with its own decisions (what a project needs when there is
> no quote to derive from).
>
> The dead create form / store route is recorded as a finding for a future task,
> not fixed here.

### Claude's Discretion

- Grace-period duration before `Not yet decided` goes amber (D-13).
- Default state for manually-created projects where no quote exists (D-18).
- Schema shape — dedicated table vs JSON column on `projects`; audit storage
  mechanism (D-03).
- Exact copy and visual treatment of the "Not required" tab grouping.
- Whether the amber prompt appears on the project list, the project page, or
  both.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### The root cause this phase exists to fix
- `app/Services/ProjectHealthService.php:50-53` — `STATUS_ENGINEERING` with no
  approved RAMS returns `red / "No approved RAMS in engineering"`. This is why
  a hardware-only project is permanently red today.
- `app/Services/ProjectHealthService.php:56-60` — same shape for surveys in
  `STATUS_SURVEY_PENDING` after 14 days.

### Prior art that constrains the UI decision
- `resources/views/projects/show.blade.php:777-782` — the UX-05 note recording
  that empty tabs were hidden and the gate deliberately reverted. **Read before
  proposing any hiding behaviour.**
- `resources/views/projects/show.blade.php:764-774` — the current 9-entry tab
  array that D-04 must reconcile with.

### The three disagreeing type lists (D-07)
- `app/Services/DocumentArtifactStorage.php:33+` — `TYPE_*` constants (8).
- `app/Services/DocumentEdits/DocumentEditAdapterRegistry.php:21-26` — adapter
  map (6, including a `drawing` entry that is dead — see deferred).

### Status machine and entry points
- `app/Models/Project.php:17-31` — `STATUS_*` constants including
  `STATUS_SURVEY_PENDING`; `:100-120` `$fillable`; `:113` stage timestamps.
- `app/Core/Modules/Projects/ProjectService.php:33` — `Project::create()`, the
  manual entry point covered by D-18.
- `app/Core/Modules/QuoteImport/` + `QuoteImportController::confirm()` — the
  import confirm flow that D-16 extends.

### Repo conventions
- `.planning/codebase/CONVENTIONS.md`, `.planning/codebase/ARCHITECTURE.md`,
  `.planning/codebase/TESTING.md` — PHPUnit 11 (NOT Pest) and local test conventions.
- `CLAUDE.md` — GSD workflow enforcement; AI must never invent scope.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Tab strip with count pills** (`show.blade.php:764-791`) — already renders
  muted "0" counts via `ws-tab__count--empty`. The "Not required" grouping in
  D-08 is a variation on styling that already exists, not a new component.
- **`ProjectHealthService::assess()`** — single, well-factored entry point with
  first-match-wins red/amber ordering. D-12 is a filter applied to the existing
  rules, not a rewrite.
- **Project Data tab** — already exists as the home for project-level settings
  (D-10), so no new navigation is required.
- **Alpine `setTab` + localStorage** (`show.blade.php:755-757`) — per-project
  active-tab persistence. Reordering tabs must not break a stored tab key.

### Established Patterns
- Policies live in `app/Policies/` and are permissive shared-workspace by
  design (see `260817-w4k`). Any new authorisation lands there, not inline.
- Soft-delete filtering is applied in-memory in `ProjectHealthService` — new
  filters should follow the same shape.
- Audit-style history in this codebase is a separate table, not a JSON blob
  (see `device_stencil_audits`, Phase 24 D-03) — the natural precedent for D-03.

### Integration Points
- `ProjectHealthService::assess()` — D-12.
- Project status transitions — D-11 (highest-risk integration; find every
  assumption of a fixed stage order).
- Quote-import confirm flow — D-15, D-16.
- `ProjectService::create()` — D-18.
- Project tab strip — D-04, D-08, D-09.
- A data migration for the back-catalogue — D-17.

</code_context>

<specifics>
## Specific Ideas

- The provoking case is the Volkswagen Blakelands import, where a hardware-only
  supply section ("Digital Production") produced document scaffolding for work
  never in scope. That project is the natural acceptance test for D-15.
- User's original framing was "a pop up / check box question". D-16 deliberately
  moves this into the confirm flow rather than a modal — the intent (ask once,
  up front) is preserved; the mechanism is changed to protect it from being
  dismissed unread.

</specifics>

<deferred>
## Deferred Ideas

- **A Programming document generator.** D-05 keeps Programming as a flag only.
  Building a generated Programming deliverable is its own phase.
- **`type=drawing` AI-edit surface is dead.** Found during `260817-w4k`:
  `ProjectDrawing` has no `user_id`, so every document-edit endpoint 404s for
  drawings. Pinned by a test, deliberately unchanged. Needs its own decision —
  and note D-04 now puts Drawings on the deliverables list, which makes this
  more visible, not less.
- **Snagging has no edit adapter.** D-04 includes it as a deliverable; wiring it
  into the document-edit surface is separate work.
- **`config/rams_tier1.php` hazard-library changes** — unrelated to this phase,
  still awaiting the user's H&S sign-off.

</deferred>

---

*Phase: 260822-esf-project-deliverables-selection*
*Context gathered: 2026-08-22*

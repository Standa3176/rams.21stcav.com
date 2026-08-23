# Phase 26: Hazard Library Structural Inversion - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-23
**Phase:** 26-hazard-library-structural-inversion
**Areas discussed:** Hazard source of truth, How include-when is evaluated
**Areas offered but not selected:** Score-default touch-point (HAZ-04), Removal strategy for the injection points

---

## Area selection

| Option | Description | Selected |
|--------|-------------|----------|
| Hazard source of truth | Where the 18 hazards live; what happens to the 13 seeded templates and user-created ones | ✓ |
| How include-when is evaluated | Deterministic match, structured job attributes, AI judgement, or hybrid | ✓ |
| Score-default touch-point (HAZ-04) | How typical L×S is pre-filled without being silently committed | |
| Removal strategy for the 5 injection points | Delete outright vs env-flag gate; what renders when zero hazards match | |

**Notes:** Presented after a codebase scout that found two corrections to the ROADMAP's
framing — five injection paths rather than the two named, and two non-matching hazard
vocabularies in the app (11 in config, 13 in the DB). Both corrections are recorded in
CONTEXT.md `<domain>` and `<code_context>`.

---

## Hazard source of truth

### Q1 — Where should the 18 hazards + include-when conditions live?

| Option | Description | Selected |
|--------|-------------|----------|
| Seeder → DB, git is the source | Version-controlled seeder/reference file; `hazard_templates` gains `include_when` and is reseeded from it. Review form, AI resolver and controller keep working unchanged; the 18 stay auditable in git | ✓ |
| DB only — edit in the app | `hazard_templates` becomes sole source, 18 arrive as a data migration, 21CAV edits through the UI thereafter. Most flexible, but settled positions become mutable with no git trail and drift from `hazard-library.md` is invisible | |
| `config/rams_tier1.php` only | One 18-entry config library with include-when. No migration, but it isn't what the review form reads, so the engineer-selection path needs rewiring and no edit is possible without a deploy | |

**User's choice:** Seeder → DB, git is the source
**Notes:** → D-01. Keeps the settled 21CAV positions auditable against the skill while
leaving every existing consumer of `hazard_templates` untouched.

### Q2 — What happens to the app's hazards that aren't in the skill's 18?

Named: *Struck by Falling Objects*, *Hidden Services (Electrical, Plumbing, Gas)*,
*Sharps & Hand / Power Tools*, *Display Installation / Wall Mounting*,
*Fixings / Substrate Failure*, *Interaction with Other Trades*.

| Option | Description | Selected |
|--------|-------------|----------|
| Fold into the nearest skill hazard | Map each into the skill's equivalent, merging control text worth keeping. The 18 stay the register vocabulary | ✓ |
| Retire them outright | Delete; anything genuinely missing gets raised as a change to `hazard-library.md`. Cleanest but discards existing control wording | |
| Keep as optional extras | The 18 auto-populate; extras remain manually-selectable with no include-when. Loses nothing but keeps the vocabulary split and leaves padding possible | |

**User's choice:** Fold into the nearest skill hazard
**Notes:** → D-02. Suggested mapping recorded in CONTEXT.md; the planner may refine it
against the source text.

### Q3 — How should the reseed treat what's already in `hazard_templates`?

| Option | Description | Selected |
|--------|-------------|----------|
| Replace globals, leave user rows | Supersede the 13 `is_global=true` rows with the 18; leave `is_global=false` rows untouched. Seeder upserts by name so it's re-runnable on live | ✓ |
| Upsert globals by name, keep unmatched | Safest against data loss, but leaves the folded-in extras alive and re-introduces the split vocabulary Q2 just closed | |
| Wipe the table and reseed all 18 | Guarantees a clean register but destroys engineer-created hazards unrecoverably on live | |

**User's choice:** Replace globals, leave user rows
**Notes:** → D-03. Upsert-by-name matters because validation is happening on live, where
the seeder may be run more than once.

### Q4 — What's the include-when for a user-created custom hazard?

| Option | Description | Selected |
|--------|-------------|----------|
| Null = manual-only | `include_when` nullable; null means the hazard never auto-populates. No UI work this phase, and it can't re-create the padding problem | ✓ |
| Author it in the UI | Add an include-when field to the create/edit form so custom hazards auto-populate too. More complete but extra UI surface and lets non-settled conditions into auto-population | |
| You decide | Defer to the planner based on what the controller and library UI already support | |

**User's choice:** Null = manual-only
**Notes:** → D-04. The UI-authoring option was captured as a deferred idea.

---

## How include-when is evaluated

**Constraint surfaced before the questions:** `rams_documents` stores scope in a
free-form `form_data` JSON; validated fields cover project/client/site/personnel/dates/
permits/material-handling only. No building age, operative count, site type or strip-out
flag exists. Also noted: 4 of the 18 are `Include when: Always`, so the register is never
literally empty.

### Q1 — How should an include-when condition be evaluated against a job?

| Option | Description | Selected |
|--------|-------------|----------|
| Hybrid: always / match / AI | 4 unconditional; clean triggers by keyword/tag match against scope narrative and existing fields; judgement calls to the AI pass already running at generation | ✓ |
| AI decides all 18 | Pass all conditions plus scope to the model, engineer confirms on review. Simplest and fits existing architecture, but nothing is reproducible — same job, different register | |
| Deterministic only — capture the missing data | Predicate vocabulary plus new review-form fields (building age, operative count, site type, strip-out). Fully auditable and makes GATE-05 trivial, but stretches the phase boundary | |

**User's choice:** Hybrid: always / match / AI
**Notes:** → D-05. Three-tier structure recorded in CONTEXT.md with the candidate hazards
per tier. Tier assignment per hazard is left to planning; the tiering rule is locked.
The deterministic-only option's new capture fields were preserved as a deferred idea.

### Q2 — What happens when a condition can't be evaluated?

| Option | Description | Selected |
|--------|-------------|----------|
| Include and flag for review | Unresolvable condition means the hazard appears, visibly marked as needing confirmation. Matches the skill's own asbestos framing ("or age unknown") | ✓ |
| Exclude and list what was skipped | Strictly evidence-based, but a hazard can silently not appear on a job that needed it | |
| Ask the engineer inline | Block generation on unresolved conditions. Most accurate but adds an interactive step to a flow that runs straight through | |

**User's choice:** Include and flag for review
**Notes:** → D-06. Safety-critical default — an engineer removing a surplus hazard is a
better failure mode than never seeing one that applied.

---

## Claude's Discretion

- **Score-default touch-point (HAZ-04)** — not selected for discussion. Recorded in
  CONTEXT.md with a suggested approach and one hard constraint: Working at Height must
  render residual `1×4`, and typical scores must never reach `generated_data` untouched.
- **Removal strategy for the five injection paths** — not selected. Recorded with a
  strong steer toward an env-flag gate following the existing `RAMS_TIER1_DEFAULTS` /
  `RAMS_UNIFIED_COMPOSER` precedent, because validation is happening on live.
- **Empty/near-empty register render behaviour** — what Section 5 shows when only the 4
  `Always` hazards match. Must not fall back to the old baseline.

## Deferred Ideas

- **Include-when authoring UI for custom hazards** — separate capability; revisit only if
  null-means-manual proves limiting.
- **Structured job-scope capture** (building age, operative count, site type, strip-out) —
  would collapse D-05 tier 3 into tier 2 and make the register fully reproducible.
  Rejected here as review-form scope creep; strong candidate for its own phase if AI
  tiering proves unreliable.
- **Narrowing `RAMS_TIER1_DEFAULTS`** — currently gates hazards, standards references and
  the COSHH baseline together. Tidy-up, not Phase 26 work.

## Out-of-band decision recorded during this session

Phase 26 will be **deployed to live and validated there** against production data rather
than in a local or staging environment (user decision, 2026-08-23). This is why the
env-flag steer above matters — reversibility must be an `.env` edit, not a redeploy.

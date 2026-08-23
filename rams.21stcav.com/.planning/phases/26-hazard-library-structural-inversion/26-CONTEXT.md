# Phase 26: Hazard Library Structural Inversion - Context

**Gathered:** 2026-08-23
**Status:** Ready for planning

<domain>
## Phase Boundary

Replace every unconditional hazard-injection path with the skill's full 18-hazard
library, each carrying an **include-when** condition. A new RAMS starts from an
effectively empty register and is *added to* when the job's captured scope matches a
hazard's trigger — never a full register the engineer prunes
(`PORTING-NOTES.md`: *"The default should be an empty register that the user adds to,
never a full register the user prunes."*).

Typical L×S scores from `hazard-library.md` are ported as **editable defaults**, never
silently committed, aligning residual severity where the skill holds it at initial
severity (Working at Height residual `1×4`, not the current baseline's `2×3`).

**Scoping correction found during discussion:** the ROADMAP names two injection
mechanisms. There are **five** paths, and killing only the two named leaves the fixed
11 hazards re-entering at render time. All five must go. See `<code_context>`.

**Scoping correction 2:** 4 of the skill's 18 hazards are `Include when: Always`
(Slips/trips/falls, Low voltage AV connections, Fire and evacuation, COSHH substances).
"Starts empty" therefore means *empty of unconditional job-irrelevant padding* — not a
literally zero-row register. Plans and verification must not assert a zero-hazard
starting state.

**In scope:** the 18-hazard library, include-when conditions and their evaluation,
score defaults as editable values, removal of all five injection paths.
**Out of scope:** the validation gates themselves (GATE-01/04/05 land in Phase 30),
house-rule text edits (Phases 27–28), CDM/A&E (Phase 29).

</domain>

<decisions>
## Implementation Decisions

### Hazard source of truth
- **D-01:** The 18 hazards live in a **version-controlled seeder/reference file; the DB
  is the runtime store**. `hazard_templates` gains an `include_when` column and is
  reseeded from that file. Git holds the settled 21CAV positions and stays auditable
  against `hazard-library.md`; `HazardTemplateController`, the RAMS review form and the
  AI resolver keep reading the DB and need no rewiring. `config/rams_tier1.php` drops
  out of the hazard business entirely.
- **D-02:** The app's hazards that are absent from the skill's 18 — *Struck by Falling
  Objects*, *Hidden Services (Electrical, Plumbing, Gas)*, *Sharps & Hand / Power
  Tools*, *Display Installation / Wall Mounting*, *Fixings / Substrate Failure*,
  *Interaction with Other Trades* — are **folded into the nearest skill hazard**, not
  kept alongside it. Suggested mapping (planner may refine against the source text):
  Hidden Services + Fixings / Substrate Failure → *Fixings into walls, ceilings and
  pillars*; Display Installation / Wall Mounting → *Manual handling*; Struck by Falling
  Objects → *Working at height*; Sharps & Hand / Power Tools → *Cable pulling and
  termination* or *Dust from drilling and cutting*; Interaction with Other Trades →
  *Occupied premises*. Control wording worth keeping is merged into the surviving
  hazard's control list. The 18 are the register vocabulary; no parallel list survives.
- **D-03:** Reseed **replaces the global rows and leaves user rows alone**. The 13
  `is_global=true` rows from `HazardTemplateSeeder` are superseded by the 18; rows with
  `is_global=false` (user-created, owned via `user_id`) are untouched. The seeder
  **upserts by name** so it is safely re-runnable on live without duplicating. No
  truncate — a wipe would destroy engineer-created hazards unrecoverably.
- **D-04:** `include_when` is **nullable, and null means manual-only**. A user-created
  custom hazard never auto-populates; it appears only when an engineer explicitly picks
  it. No new authoring UI this phase — that keeps non-settled conditions out of the
  auto-population path and cannot re-create the padding problem.

### Include-when evaluation
- **D-05:** **Hybrid evaluation**, in three tiers:
  1. **Always** — the 4 `Include when: Always` hazards are included unconditionally.
  2. **Deterministic match** — clean triggers resolve by keyword/tag match against the
     scope narrative and the existing structured form fields: drilling / percussive
     tools, strip-out or decommission, ceiling void or riser, mains connection or
     disconnection, first-fix cabling, any penetration, any display/mount/rack,
     mounting above standing reach.
  3. **AI judgement** — conditions needing reading rather than matching go to the AI
     pass that already runs during generation: building pre-2000 or age unknown
     (asbestos), fewer than 3 operatives or split areas (lone working), site outside
     normal travel radius (occupational road risk), warehouse/workshop/yard/loading bay
     (vehicle and plant movement), live building with staff present (occupied premises).

  Deterministic wherever it can be, model only where it must be. Tier assignment per
  hazard is a planning decision; the tiering rule itself is locked.
- **D-06:** When a condition **cannot be evaluated** because the data isn't captured,
  the hazard is **included and visibly flagged as needing confirmation** — not silently
  dropped. Safety-critical default: an engineer removing a surplus hazard is a better
  failure than one never seeing a hazard that applied. The skill points the same way —
  asbestos is *"Any building pre-2000, **or age unknown**"*. Flagged-for-confirmation
  hazards must be distinguishable on the review screen from confidently-matched ones.

### Claude's Discretion

The user did not select these areas; the planner decides, within the stated constraints.

- **Score-default touch-point (HAZ-04).** The requirement is absolute — *"Do not let the
  app apply the typical scores silently."* Typical L×S must be visibly pre-filled and
  editable, and must not reach `generated_data` untouched. Suggested default (planner
  may choose otherwise if it satisfies HAZ-04): carry the typical score as a default on
  the hazard row plus a marker recording that it is un-reviewed, surface it pre-filled
  on the review screen, and treat a human or model touch as what promotes it to a
  committed score. **Constraint:** Working at Height must render residual `1×4`, not
  `2×3` (`config/rams_tier1.php:67-68`).
- **Removal strategy for the five injection paths.** All five must stop injecting the
  fixed 11. **Strong steer:** gate the new behaviour behind an env flag rather than
  deleting outright — `config('rams_tier1.enabled') = env('RAMS_TIER1_DEFAULTS', true)`
  already gates all five and is the established precedent (see also
  `RAMS_UNIFIED_COMPOSER`). Phase 26 is being validated **on live against production
  data** (see `<specifics>`), so a bad hazard set must be one `.env` edit from off, not
  a git revert plus redeploy. Note the existing flag is all-or-nothing across hazards,
  standards *and* COSHH — a new, narrower flag may be cleaner than overloading it.
- **Empty/near-empty register render behaviour.** What Section 5 renders when only the
  4 `Always` hazards match. Must not fall back to the old baseline.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Source of truth — the 21cav-rams skill (settled positions, not proposals)
- `.planning/reference/21cav-rams-skill/references/hazard-library.md` — the 18 hazards,
  their `Include when` conditions, typical initial/residual scores and full control
  text. **The authority for this phase.** Note its own caveat: scores are typical
  starting points, raise initial where site conditions are worse than normal.
- `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` — the empty-register-by-default
  principle (HAZ-02's rationale) and the "do not apply typical scores silently" rule
  (HAZ-04).
- `.planning/reference/21cav-rams-skill/references/house-rules.md` — settled positions
  that constrain hazard control text; *Restricted access and ceiling void working* is
  never titled "confined space" (RULE-06, enforced in Phase 28).

### Project planning
- `.planning/ROADMAP.md` §"Phase 26: Hazard Library Structural Inversion" — goal and the
  4 success criteria.
- `.planning/REQUIREMENTS.md` §"Group C — Hazard library reconciliation" — HAZ-01..04.

### Code the phase replaces
- `config/rams_tier1.php:52` — `baseline_hazards`, the 11 fixed hazards; `:36` the
  `RAMS_TIER1_DEFAULTS` kill-switch; `:67-68` the Working-at-Height `2×3` residual.
- `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php:36-44` —
  `MANDATORY_KEYWORDS`; `:195-230` `mandatoryBaseline()` and its hardcoded fallback
  controls, including the "confined spaces" string Phase 28 also targets.
- `app/Services/Rams/Tier1RamsDefaultsService.php:59-85` —
  `injectDefaultsIntoRamsData()`.
- `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php:30-45` — render-time
  last-resort fallback and the RA{NN} ref scheme.
- `resources/views/pdf/rams.blade.php:315-316` — **live** render-time fallback.
- `resources/views/pdf/rams-v2.blade.php:371-372` — composer-path render-time fallback.
- `database/seeders/HazardTemplateSeeder.php` — the 13 current global templates.
- `database/migrations/2026_03_09_000001_create_hazard_templates_table.php` — the table
  `include_when` is added to.

</canonical_refs>

<code_context>
## Existing Code Insights

### The five injection paths (all must stop injecting the fixed 11)

| # | Path | Fires |
|---|---|---|
| 1 | `Tier1RamsDefaultsService::injectDefaultsIntoRamsData():70` — called from `RamsBuilderService:273` and `:752` | Generate-time, when `$data['hazards']` is empty |
| 2 | `resources/views/pdf/rams.blade.php:315-316` | Render-time, when `$hazards` is empty — **this is the live template** |
| 3 | `resources/views/pdf/rams-v2.blade.php:371-372` | Render-time (composer path; not live, `RAMS_UNIFIED_COMPOSER` unset in prod) |
| 4 | `RiskAssessmentComposer::compose():35-38` | Render-time last resort |
| 5 | `HazardLibraryService::mergeWithMandatory()` / `mandatoryBaseline()` | **Always**, regardless of engineer selection |

Killing only #1 and #5 (the two the ROADMAP names) leaves #2 re-injecting the same 11
at render on the live template. All five read `config('rams_tier1.enabled')`.

### Reusable Assets
- **`hazard_templates` table + `HazardTemplate` model** — already the engineer-facing
  library, with `visibleTo($userId)` (global OR own) scoping and a `controls` JSON cast.
  The natural home for the 18 plus `include_when`.
- **`HazardTemplateSeeder`** — established seeding pattern that already guards on
  `->where('name', ...)`, so upsert-by-name (D-03) follows existing practice.
- **`RAMS_TIER1_DEFAULTS` env kill-switch** — proven all-five-path gate; the precedent
  for the flag steer in Claude's Discretion.
- **AI extraction path** — `HazardLibraryService::resolveFromSeeds()`, consumed by
  `RamsBuilderService:410` and `RiskTemplateResolverService:193`, already fuzzy-matches
  model-produced hazard names to library rows. Tier-3 AI evaluation (D-05) plugs in here
  rather than needing a new AI call.

### Established Patterns
- **Denormalised hazard storage.** `reviewed_data['hazards']` / `generated_data['hazards']`
  hold plain arrays (`hazard`, `controls`, `pre_likelihood`/`pre_severity`,
  `post_likelihood`/`post_severity`), **not FKs** to `hazard_templates`. Consequence:
  reseeding the library cannot retro-change an already-issued RAMS. No historical-data
  migration is needed.
- **Dual key vocabulary.** `RiskAssessmentComposer` reads `initial_l`/`initial_s`/
  `residual_l`/`residual_s` and falls back to `pre_*`/`post_*`. New hazard rows must
  satisfy both readers.
- **Precedence chain.** `reviewed_data` → `generated_data` → config baseline. Engineer
  values always win; the phase removes only the last link.
- **Stable RA{NN} refs** assigned 1-indexed by `RiskAssessmentComposer` (matching
  `DocxBuilderService::buildRiskAssessment()` at `:1119`). A variable-length register
  makes these shift between generations — GATE-01/GATE-03 reference resolution depends
  on them.

### Integration Points
- Migration adding `include_when` to `hazard_templates`.
- Seeder rewrite → the 18, upserting by name.
- `HazardLibraryService` — `MANDATORY_KEYWORDS` and `mergeWithMandatory()` removed;
  include-when resolution added.
- `RamsBuilderService:273` / `:752` — where the register is now assembled from matches.
- RAMS review screen — flagged-for-confirmation hazards (D-06) and editable pre-filled
  scores (HAZ-04).
- All four render-time fallbacks.

### What the app does NOT capture
`rams_documents` holds scope in a free-form `form_data` JSON. Validated fields
(`RamsController:336-366`) cover project/client/site/personnel/dates/permits/material
handling — there is **no** building age, operative count, site type or strip-out flag.
This is why D-05 is hybrid rather than deterministic-only, and why D-06 exists.

</code_context>

<specifics>
## Specific Ideas

- **Validation happens on live.** Phase 26 is deployed to `rams.21stcav.com` and tested
  there against production data rather than in a local or staging environment (user
  decision, 2026-08-23). This is why the env-flag steer in Claude's Discretion matters:
  reversibility must be an `.env` edit, not a redeploy. Deploy as `stcav`, not root.
- **The proof job is 21CQ30960 (VW Blakelands)** — the professional review that triggered
  this milestone. Success criterion 4 requires regenerating it and spot-checking the
  register against the source quote **manually**. It cannot be validated against the old
  fixed 11/7-item lists, which by construction cannot contain the answer.
- **Working at Height residual `1×4`** is the named, checkable score change — the visible
  proof HAZ-03 landed.
- The user chose not to discuss the score touch-point or the injection-removal strategy;
  both are recorded above as discretion with constraints, not as open questions.

</specifics>

<deferred>
## Deferred Ideas

- **Include-when authoring UI for custom hazards** — D-04 makes user-created hazards
  manual-only with a null condition. Letting engineers author their own include-when
  conditions is a separate capability; revisit only if the null-means-manual default
  proves limiting.
- **Structured job-scope capture** (building age, operative count, site type, strip-out
  flag) — would let D-05 tier 3 collapse into tier 2, making the whole register
  deterministic and reproducible and GATE-05 trivial. Rejected here as review-form scope
  creep; a strong candidate for its own phase if AI tiering proves unreliable.
- **Narrowing `RAMS_TIER1_DEFAULTS`** — the existing flag gates hazards, standards
  references *and* the COSHH baseline together. Splitting it per-concern is tidy-up, not
  Phase 26 work.

</deferred>

---

*Phase: 26-hazard-library-structural-inversion*
*Context gathered: 2026-08-23*

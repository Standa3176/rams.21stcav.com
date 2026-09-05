# Phase 28: PPE, Ceiling & Electrical Boundary House Rules - Context

**Gathered:** 2026-09-05
**Status:** Ready for planning. *(D-09 was raised as a blocking open question and was
CLOSED the same day — RULE-11 moved out to Phase 31. No open questions remain.)*

<domain>
## Phase Boundary

Every occurrence of FFP2 becomes FFP3 with face-fit testing; no hazard title, fallback
string or generated document text labels a ceiling void, comms room or riser a "confined
space"; the ceiling-load and electrical-scope-boundary statements land in generated
output; and GATE-06 and GATE-07 ship in the same phase so neither fires against a
still-broken default.

**In scope:** RULE-01 (FFP3 + face-fit), RULE-06 (restricted-access hazard title),
RULE-09 (electrical scope boundary), RULE-10 (ceiling load), GATE-06, GATE-07. **Five
requirements, two gates — that is the whole phase.**

**Out of scope:** **RULE-11 (fire-stopping) — moved to Phase 31 on 2026-09-05, see D-09.**
`config/rams_tier1.php:286` (the Expanding Foam COSHH entry) is edited by this phase for
its FFP3 content and by Phase 31 for its fire-stop claim; **do not fix the fire-stop
wording here** — Phase 31 owns it and now has a success criterion for it. Also out of
scope: display-lift team sizes and manual handling (Phase 27, shipped);
CDM duty-holder and A&E arrangements (Phase 29); structural gates GATE-01/04/13/14
(Phase 30); standards/COSHH scoping (Phase 31); GATE-17's RCS/WEL and RIDDOR content
(explicitly noted in `REQUIREMENTS.md:110` as *extending* RULE-01 — a later phase, not
this one); the hazard include-when library itself (Phase 26, shipped).

</domain>

<decisions>
## Implementation Decisions

Four decisions were taken. All four concern **GATE-06 and GATE-07 mechanics** — the user
selected that area only, and deliberately left the other three gray areas to the planner
(recorded under Claude's Discretion **with constraints**, not as open questions, with the
single exception of D-09).

### GATE-07 — detecting the "confined space" mislabel

- **D-01: The detector is negation-aware. It flags affirmative labelling only.**

  A blunt substring check on `confined space` is **wrong** and would fire on the app's own
  corrected library text. `database/seeders/HazardTemplateSeeder.php:220` reads:

  > *"Confirm ventilation and safe access before entering ceiling voids, comms rooms or
  > enclosures. **These are not classified as confined spaces**, but access is restricted
  > and is treated as a controlled activity."*

  That sentence is the fix, not the defect. GATE-07 errors on **affirmative** constructions
  — a hazard *name* containing "confined space", "is a confined space", "confined space
  entry", "confined space permit", or a citation of the confined-spaces ACOP **L101**
  (named explicitly in `house-rules.md` §"Ceiling work" as a thing not to cite) — and
  treats "not classified as / are not confined spaces" as clean.

  **Constraint:** this inherits `ControlTextRuleViolations`' conservative-by-construction
  rule verbatim (`ControlTextRuleViolations.php:29-39`) — a line the detector cannot
  confidently classify is **CLEAN**. A false positive silently overwrites an engineer's
  deliberate wording on a live safety document; a false negative merely leaves today's
  already-shipped behaviour unchanged. Phase 27 made *"never flags the app's own corrected
  library text (every control line `HazardTemplateSeeder` emits)"* a load-bearing
  acceptance criterion for this exact class. **Carry that criterion forward and extend it
  to the two new detectors.** It is not box-ticking.

- **D-02: The permit-type picker is OUT of GATE-07's scope. The gate scans generated
  document content, not UI vocabulary.**

  `resources/views/rams/review.blade.php:264` offers `'Confined Space'` as one of eight
  permit-to-work types (alongside Hot Works, PASMA, IPAF, Electrical Isolation, Asbestos
  Awareness). That is a generic permit vocabulary; ticking it is an engineer's deliberate
  act about a client-imposed permit regime, not the app mislabelling a ceiling void. The
  house rule governs **how 21CAV describes a ceiling void**, not whether the concept may
  be named anywhere in the UI.

  The user was offered, and declined, both alternatives: gating a ticked Confined Space
  permit against jobs whose only restricted-access scope is a ceiling void (declined —
  needs a scope signal, and DATA-01 proved those can be empty on live jobs), and removing
  the option from the list outright (declined — removes an engineer's ability to record a
  genuine permit).

  **Consequence for planning:** GATE-07's scan surface is the generated payload — hazard
  names, control lines, `generated_data` — plus the source-level guard in D-04. It does
  **not** walk Blade pick-lists. Note the deliberate asymmetry with D-03/D-04 below: the
  PPE pick-lists *are* changed, because unlike a permit category the string
  `'Dust Mask (FFP2)'` becomes document content verbatim and is unconditionally wrong.

### GATE-06 / GATE-07 — what happens on a hit

- **D-03: Both behaviours, split by source. Auto-correct what is library-owned; throw on
  what survives.**

  The codebase already has two distinct mechanisms and Phase 27 shipped them as a
  **pair** (RULE-13 + GATE-09). Phase 28 mirrors that shape exactly:

  1. **Tier-1 auto-correction.** Add `ffp2` and `confined_space` entries to
     `ControlTextRuleViolations::DETECTORS` plus their own `detectXxx()` methods. The
     class docblock (`:19-25`) *already names this phase* as the intended extension:
     *"Phase 28 adds `ffp2` / `confined_space`, Phase 31 adds `coshh_wrong_category` —
     each is a new `DETECTORS` entry plus its own `detectXxx()` method.
     `reviewedToRisk()` calls only `detectAll()`; it never changes when a detector is
     added here."* **Do not modify `RamsBuilderService::reviewedToRisk()` to add a
     detector.** A control line a new detector flags is replaced with current library
     text **regardless of the `controls_reviewed` marker** — a house rule is a settled
     safety position, not an engineer preference. This is the Blocker-1 path that
     corrected 438 hazard rows across 60 documents on 2026-08-26.
  2. **An independent re-check that throws.** Anything that survives into the final
     payload raises `RamsGenerationException`, the GATE-09 shape
     (`RamsComplianceUpgradeService.php:1104-1213`, surfaced at
     `RamsController.php:597-604`).

  The user was shown and declined throw-only (would make some of the 60 existing
  documents un-regenerable until hand-edited) and auto-correct-only (contradicts the
  requirement wording *"GATE-06 errors on any FFP2 occurrence"* and never surfaces a
  defect an engineer typed themselves).

  **Constraint:** `ControlTextRuleViolations` is the ONE choke point. Per its docblock,
  *"do not duplicate a detector's logic anywhere else."* If the throwing re-check needs
  the same classification, it calls into this class — the way `enforceDisplayLiftGate()`
  calls `parseStatedTeamSize()` / `parseStatedInches()` rather than keeping copies.

- **D-04: GATE-06 gets a repo-wide static source test on top of the runtime gate.**

  Fix all **14 live source sites** and add a test that fails on `FFP2` appearing in any
  non-backup source file. Rationale given by the user's selection: this milestone has been
  reopened repeatedly by reintroduction, and a runtime gate only catches code paths the
  proof job actually exercises — nothing stops a 15th site being added to a path
  21CQ30960 never touches.

  The 14 live sites (verified 2026-09-05):

  | File | Lines |
  |---|---|
  | `app/Http/Controllers/ProjectPackageReviewController.php` | `:33` — `'Dust Mask (FFP2)'` PPE pick-list |
  | `app/Http/Controllers/RamsReviewController.php` | `:42` — same pick-list |
  | `app/Services/DocxBuilderService.php` | `:2038` — **the live renderer** |
  | `app/Services/Rams/RamsComplianceUpgradeService.php` | `:349`, `:361`, `:747`, `:796` |
  | `app/Services/RiskMatrixService.php` | `:133` — **the hedge**, *"Wear FFP2 or FFP3"* |
  | `app/Services/RiskTemplateResolverService.php` | `:44`, `:106` (`:521`/`:523` already FFP3) |
  | `resources/views/pdf/rams-v2.blade.php` | `:540`, `:1964` |
  | `resources/views/pdf/rams.blade.php` | `:498`, `:1903` |

  Two specific traps in that list:
  - **`RiskMatrixService.php:133` hedges** — *"Wear FFP2 or FFP3 dust masks"*. A detector
    or a find-and-replace looking for a bare `FFP2` token will fix it, but a human
    reviewing the diff may read the line as already-compliant. It is not: the house rule
    is FFP3, singular.
  - **Both controllers' PPE pick-lists** flip to FFP3. Per D-02's asymmetry, these are
    changed even though the permit picker is not.

  **Excluded from the fix, retained in the scan's exclusion list** (5 further occurrences,
  all in frozen copies): `resources/views.backup-260430/pdf/rams.blade.php`,
  `.../rams.blade - keep boarder.php`, `.../rams.blade-keep-borders.php`, and
  `resources/views/pdf/rams.blade - keep boarder.php`,
  `resources/views/pdf/rams.blade-keep-borders.php`. The user chose the option that
  leaves backups untouched; the exclusion list is the price of that and should be
  narrow and commented.

  **Constraint:** `config/rams_tier1.php:128` and
  `database/seeders/HazardTemplateSeeder.php:294` are **already FFP3**, and the seeder
  already states *"All operatives face-fit tested."* RULE-01's face-fit clause is
  therefore partly shipped — verify before rewriting it, and make the corrected sites
  consistent with the seeder's existing phrasing rather than inventing a second one.

### Claude's Discretion

The user chose not to discuss these three areas. The planner decides **within the stated
constraints** — these are constrained discretion, not open questions. D-09 is the one
exception: it is a genuine open question the planner must not resolve alone.

- **D-05 — RULE-06's canonical hazard title. There is a live mismatch; pick one and say
  which.**

  ROADMAP success criterion 2 and `house-rules.md` §"Ceiling work" both say the title must
  read **"Restricted access and ceiling void working"**. Phase 26 actually shipped
  **"Restricted access and ceiling voids"** — plural, no "working" — and it is now
  load-bearing in several places: `LegacyHazardNameFoldMap.php:97` (`'confined spaces'` →
  `'Restricted access and ceiling voids'`), `:92` (`'cable installation in ceiling voids'`
  → same), `HazardTemplateSeeder.php` hazard #7, and live DB rows.

  Renaming to match the skill means editing the fold map's *target*, the seeder, and
  accepting that `LegacyHazardNameFoldMap`'s drift-guard against the seeder (`:121`) must
  move with it. Amending the requirement to the shipped title means editing
  `REQUIREMENTS.md` RULE-06 and ROADMAP SC2 and recording it as a deliberate app-side
  divergence — the Phase 27 D-01 precedent, which is established and accepted practice
  here.

  **Constraint:** whichever way it goes, all four surfaces must agree — fold map target,
  seeder, requirement text, ROADMAP criterion. **Do not ship a third spelling.** And do
  not silently pick the shipped title on the grounds that it is less work: say which was
  chosen and why, the way Phase 27 D-01 did.

- **D-06 — where RULE-09 and RULE-10 statements land, and what triggers them.**

  RULE-10's ceiling-load sentence **already exists** and is correct:
  `HazardTemplateSeeder.php:223` — *"No standing, kneeling or leaning on the suspended
  ceiling grid. All loads supported from the structural soffit or a purpose-designed
  ceiling mount kit only."* But it sits inside hazard #7, gated on
  `include_when: 'signal:ceiling_void_access'`. `house-rules.md` says *"State this
  wherever ceiling-mounted devices appear"* — **a ceiling-mounted display or speaker is
  not the same signal as entering a ceiling void.** The planner must establish whether
  the existing signal actually fires for a ceiling-mount job, and if not, where the
  statement goes instead.

  RULE-09's electrical scope boundary has **no home at all today.** The nearest existing
  text is `RamsComplianceUpgradeService.php:565` (*"All mains electrical connections by
  qualified electrician only — no live working by AV engineers"*) and
  `HazardTemplateSeeder.php:148` — neither states the actual boundary (*terminates at the
  existing socket outlet or client data outlet; no alteration to the fixed installation*).
  An `exclusions` block exists in the DOCX (`DocxBuilderService.php:620-864`, sourced from
  `reviewed_data['exclusions']` + `$data['exclusions']`, with a V2 override hook) and is
  the obvious unconditional home.

  **Constraint — this is the DATA-01 trap and it is the single most likely way Phase 28
  ships something unreachable.** Phase 27's RULE-03 was implemented correctly and proved
  nothing, because its trigger (`scope_items.decommission`) is never populated on a live
  job. Before depending on ANY scope signal, **verify it is non-empty on 21CQ30960**.
  If the honest answer is that a conditional trigger cannot be relied on, prefer the
  unconditional exclusions block and say so — `house-rules.md` §"Scope boundaries to
  state explicitly" opens with *"These recur on almost every job and are worth stating
  even when obvious"*, which is licence for unconditional.

  **Constraint:** no AI decides whether a statement applies (`CLAUDE.md:12`, carried from
  Phase 26's D-05 correction). Deterministic derivation or unconditional — nothing else.

- **D-07 — how much of `house-rules.md` §"Electrical scope boundary" ships.**

  RULE-09 as written in `REQUIREMENTS.md:89` is one sentence. The skill section
  (`house-rules.md:59-79`) is considerably wider and contains four further settled
  positions:
  1. No circuit isolation / lock-off / test-dead / **BS 7671** language for *plugging
     equipment into an existing socket*; reserve that wording for genuine
     fixed-installation work, carried out by the **client's competent electrician**.
  2. **Never** a live-working PPE row (e.g. insulated gloves "for live working") — it
     directly contradicts "no live working" and *"always gets picked up"*.
  3. Never *"first-fix power"* or *"power cabling"*; call it **"first-fix AV
     signal/data/ELV cabling"**.
  4. Hardwired supplies are isolated by the client's authorised person with site lock-off.

  The planner may ship the boundary sentence alone and defer the rest — but must **say so
  explicitly and record what was deferred**, the Phase 27 RULE-12 precedent (*"do not
  silently narrow … without saying so"*). Note there is an **open user decision on BS 7671
  wording carried in project memory** that is not resolved here; if the planner's route
  requires settling it, that is a checkpoint question, not a planner call.

- **D-08 — gate flag, surfacing, and whether a backfill migration is needed.** Not
  discussed; the user moved on when offered. Precedents to follow rather than re-invent:
  - **Env flag:** `RAMS_DISPLAY_LIFT_GATE` (`config/rams_tier1.php:74`) is the shape.
    **Strong steer (carried from Phase 27's reversibility note):** GATE-06 and GATE-07
    get their own flag(s), not a reuse of the display-lift one. This milestone is
    validated on live production data; a gate that blocks generation needs an instant
    off switch that does not also disarm GATE-09.
  - **Surfacing:** `RamsController.php:597-604` already catches
    `RamsGenerationException` — the established surface.
  - **Backfill:** Plan 27-08 shipped a migration that touched 60 documents / 438 hazard
    rows. Whether Phase 28 needs an equivalent depends on how many existing
    `reviewed_data` payloads carry FFP2 — **measure on live before deciding**, don't
    assume either way.

### D-09 — RESOLVED 2026-09-05: RULE-11 is OUT of Phase 28, moved to Phase 31

**User decision:** *"move RULE-11 out and fix the requirements table."*

**Edits made the same day, all four surfaces now agree:**
1. `ROADMAP.md` Phase 28 `**Requirements**:` — RULE-11 removed, with a note saying where
   it went and why.
2. `ROADMAP.md` Phase 31 `**Requirements**:` — RULE-11 added, with a warning that it is
   wider than a COSHH scoping fix (exclusions list and hazard register are new surface
   for that phase).
3. `ROADMAP.md` Phase 31 — **new success criterion 5** covering the one-consistent-position
   rule, the "to the original rating" contradiction, and the expanding-foam COSHH claim,
   verified by regenerating 21CQ30960. *This was the point of the whole exercise: a
   requirement with no criterion is what created D-09 in the first place, so RULE-11 was
   not allowed to land in Phase 31 the same way it sat in Phase 28.*
4. `ROADMAP.md` milestone bullet for Phase 31 and `REQUIREMENTS.md` — traceability row
   flipped to Phase 31 and RULE-11's own entry annotated with the reassignment.

**Destination rationale:** RULE-11's concrete defect is a COSHH-table entry — Expanding
Foam described as a cable-penetration fire-stop — and Phase 31 criterion 2 *already named
the expanding-foam entry* before this move. RULE-05 and GATE-10 own that table.

**Consequence for Phase 28 planning:** `config/rams_tier1.php:286` is touched by both
phases — by Phase 28 for FFP3 content, by Phase 31 for the fire-stop claim. **Phase 28
must not "tidy" the fire-stop wording while it is in that file.** Leaving a known defect
in place is deliberate here, not an oversight.

---

*Original question, retained for the record:*

**Is RULE-11 (fire-stopping) in Phase 28 or not?**

`REQUIREMENTS.md:178` assigns **RULE-11 → Phase 28**, and the ROADMAP's Phase 28
`**Requirements**:` line lists it. But **none of the four ROADMAP success criteria
mention fire-stopping.** A planner reading the criteria will build to them and RULE-11
will ship silently unbuilt; a planner reading the requirements line will build a fifth
deliverable with no criterion to verify against.

RULE-11 is also not small. Its text (`REQUIREMENTS.md:91`) covers the exclusions list,
the hazard register **and** QA taking one consistent position, and specifically bans
describing expanding foam as a cable-penetration fire-stop — the 21CQ30960 defect where
RA13 and RA18 stated the correct position and then the COSHH table contradicted both.
Note that `config/rams_tier1.php:286` (the Expanding Foam COSHH entry) is named in
ROADMAP success criterion 1 for its FFP3 content, so **this phase is already editing the
file where the RULE-11 contradiction lives** — which argues for folding it in.

Its former blocker is gone: the requirement previously needed a human decision on an
approved fire-stopping product, and that was **CLOSED on 2026-08-26** — there is no 21CAV
product to name, fire-stopping is excluded outright
(`house-rules.md` §"Fire-stopping — one consistent position";
`.planning/reference/SKILL-RESYNC-2026-08-26.md` §B-1).

**Resolution required before `/gsd:plan-phase 28`:** either (a) add a fifth ROADMAP
success criterion for RULE-11 and build it, or (b) move RULE-11 to a later phase and
update `REQUIREMENTS.md:178`'s phase assignment. **Do not leave the two documents
disagreeing** — that is the exact failure mode that made Phase 27 restate two criteria
mid-verification.

**→ (b) was chosen. See the RESOLVED block above.**

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Source of truth — the 21cav-rams skill (settled positions)
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Respiratory
  protection" (`:54-57`) — RULE-01. FFP3 not FFP2, never the typo "FFE3", specify
  face-fit testing, rationale is respirable crystalline silica from masonry/concrete.
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Electrical scope
  boundary" (`:59-79`) — RULE-09 **in full**. Four positions wider than the requirement
  text; see D-07.
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Ceiling work"
  (`:135-144`) — RULE-06 **and** RULE-10 together. Load from structural soffit or
  purpose-designed mount kit, never grid/pipework/sprinkler; ceiling voids and comms
  rooms are not confined spaces under the Confined Spaces Regulations 1997; title the
  hazard "Restricted access and ceiling void working"; **do not cite ACOP L101**.
- ~~`.planning/reference/21cav-rams-skill/references/house-rules.md` §"Fire-stopping — one
  consistent position" (`:105-113`)~~ — RULE-11, **now Phase 31 (D-09 resolved). Not
  Phase 28 reading.** Listed only so nobody re-derives it from the roadmap.
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Scope boundaries to
  state explicitly" (`:217-227`) — the unconditional-statement licence relevant to D-06.
- `.planning/reference/SKILL-RESYNC-2026-08-26.md` §B-1 — records that the fire-stopping
  section was absent from the 2026-08-23 vendoring and recovered on 2026-08-26, closing
  RULE-11's open human decision.
- ⚠️ **The skill directory is shared with a second live application** (Service Contractor
  Creator vendors a copy of `resources/rams-skill/` and drifts silently). Editing a
  reference file here changes another live app's safety content. Phase 27 D-01's rule
  stands: **divergences are recorded app-side only; the skill stays a clean upstream
  reference. Do not edit it.**

### Project planning
- `.planning/ROADMAP.md` §"Phase 28: PPE, Ceiling & Electrical Boundary House Rules" —
  goal, dependency on Phase 26, and 4 success criteria. **Criterion 2 names a hazard
  title the app does not currently use — see D-05. No criterion covers RULE-11 — see
  D-09.**
- `.planning/REQUIREMENTS.md` — Group A `:67-68` (GATE-06, GATE-07); Group B `:81`
  (RULE-01), `:86` (RULE-06), `:89` (RULE-09), `:90` (RULE-10), `:91` (RULE-11, with its
  2026-08-26 restatement and closure note); `:110` (GATE-17 — **a later phase**, but it
  extends RULE-01 and names the WEL-vs-EAV and RIDDOR-number defects, so read it to know
  what Phase 28 is deliberately *not* doing); traceability table `:159-178`.
- `.planning/phases/27-manual-handling-display-lift-house-rules/27-CONTEXT.md` — the
  reversibility posture, the "no AI decides safety scope" rule, the two-generation-entry-
  point trap, and the `LegacyHazardNameFoldMap` shape for settled positions.
- `.planning/phases/27-manual-handling-display-lift-house-rules/27-VERIFICATION.md` —
  **read Blocker 1 and Blocker 2 in full.** Blocker 1 is the stale-`reviewed_data`
  bypass and the two-tier union policy that D-03 builds on; Blocker 2 is DATA-01, the
  empty-scope-signal trap that D-06 must avoid. Also records that a verification run as
  `root` silently tested pre-fix code while looking like a failed verification.
- `.planning/phases/26-hazard-library-structural-inversion/26-CONTEXT.md` — D-01 (the DB
  is the hazard runtime store, the seeder is the version-controlled source), D-05
  correction (no AI decides safety scope), D-06 (`needs_confirmation` surfacing).
- `CLAUDE.md:12` — AI is never permitted to invent scope. Binds D-06's trigger question.

### Code this phase changes
- `app/Services/Rams/ControlTextRuleViolations.php` — **the extension point, and it names
  this phase.** `:19-25` docblock declares the `ffp2` / `confined_space` additions;
  `:84-88` the `DETECTORS` registry; `:95-106` `detect()`; `:113-129` `detectAll()`;
  `:29-39` the conservative-by-construction rule D-01 inherits.
- `app/Services/RamsBuilderService.php:542` — the `detectAll()` call site (tier-1
  precedence branch). **Do not edit this to add a detector** — the registry is the
  extension point.
- `app/Services/Rams/RamsComplianceUpgradeService.php:1104-1213` —
  `enforceDisplayLiftGate()`, the shape of an independent throwing re-check; `:55-63` the
  flag-gated call site; `:349`, `:361`, `:565`, `:747`, `:796` FFP2 / electrical text.
- `app/Http/Controllers/RamsController.php:587-604` — where `RamsGenerationException` is
  caught and surfaced. The established gate surface.
- `config/rams_tier1.php:74` — `display_lift_gate_enabled` / `RAMS_DISPLAY_LIFT_GATE`, the
  env-flag shape for D-08; `:128` already-FFP3 Expanding Foam entry; `:286` the Expanding
  Foam COSHH entry named in ROADMAP criterion 1. ⚠️ **`:286` is also the home of RULE-11's
  fire-stop contradiction, which is Phase 31's, not this phase's. Edit it for FFP3 content
  only and leave the fire-stop claim alone** (D-09).
- `database/seeders/HazardTemplateSeeder.php:210-227` — hazard #7 "Restricted access and
  ceiling voids": `:220` the negating sentence D-01 must not flag, `:223` RULE-10's
  ceiling-load statement, `:227` the `signal:ceiling_void_access` include-when that D-06
  must validate. `:294` already-FFP3 + face-fit control.
- `app/Services/Rams/LegacyHazardNameFoldMap.php:92`, `:97`, `:121` — the fold map targets
  and the drift-guard, both of which move if D-05 renames the hazard.
- `app/Services/DocxBuilderService.php:2038` (FFP2) and `:620-864` (`buildScopeOfWorks`
  and the exclusions block, with its V2 override hook) — **DocxBuilderService is the live
  primary renderer.** A claim proven only through the PDF blade proves nothing.
- `app/Services/RiskMatrixService.php:133` — the *"FFP2 or FFP3"* hedge.
- `app/Services/RiskTemplateResolverService.php:44`, `:106` (FFP2) and `:521`, `:523`
  (already FFP3 — the target phrasing).
- `app/Http/Controllers/ProjectPackageReviewController.php:33`,
  `app/Http/Controllers/RamsReviewController.php:42` — PPE pick-lists.
- `resources/views/pdf/rams-v2.blade.php:540`, `:1964`;
  `resources/views/pdf/rams.blade.php:498`, `:1903`.
- `resources/views/rams/review.blade.php:264` — the permit-type picker. **Out of scope
  per D-02 — listed so nobody "fixes" it.**

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`ControlTextRuleViolations`** — an ordered detector registry with a single choke point,
  built in Plan 27-08 and **explicitly designed for this phase's two detectors**. Extending
  it is a registry entry plus a `detectXxx(string $control): bool` method; no caller
  changes. This is the largest single reason Phase 28 should be small.
- **The two-tier union policy in `RamsBuilderService::reviewedToRisk()`** — tier 1
  (detected house-rule violation) overrides `controls_reviewed`; tier 2 leaves
  engineer-owned text alone. Proven on production: 438 rows backfilled to
  `controls_reviewed = true`, and tier 1 still correctly replaced the Manual Handling
  controls that breached RULE-02/RULE-13.
- **`enforceDisplayLiftGate()` + `RamsGenerationException` + `RamsController.php:597-604`**
  — the complete throw-and-surface path GATE-09 established. GATE-06/07 reuse it.
- **`RAMS_DISPLAY_LIFT_GATE` / `RAMS_HAZARD_LIBRARY_TIERING` / `RAMS_UNIFIED_COMPOSER`** —
  the proven env-flag rollback pattern for live-validated safety changes.
- **`LegacyHazardNameFoldMap`** — the all-static single-choke-point shape for a settled
  21CAV position, plus its seeder drift-guard test.
- **The DOCX `exclusions` block** (`DocxBuilderService.php:620-864`) — an existing
  unconditional home for scope-boundary statements, with a V2 override hook already wired.
  Directly relevant to RULE-09.
- **Already-correct text to copy rather than reinvent:**
  `HazardTemplateSeeder.php:294` (FFP3 + *"All operatives face-fit tested"*), `:223`
  (RULE-10's ceiling-load sentence), `config/rams_tier1.php:128`,
  `RiskTemplateResolverService.php:521-523`.

### Established Patterns
- **Conservative by construction for anything that rewrites safety text.** Ambiguity is
  clean. False negatives are preferred to false positives, deliberately and in writing.
- **Deterministic derivation only for safety content.** No AI decides what applies
  (`CLAUDE.md:12`; Phase 26 D-05).
- **Two generation entry points** — `runPipeline()` (fresh) and `runFromReview()`
  (already-reviewed package, *most real projects*). Phase 26 was closed prematurely twice
  for proving only one. Phase 27 added a third (Save Review). **Prove all of them.**
- **The seeder is the version-controlled source; the DB is the runtime store.** Reseeding
  cannot retro-change an already-issued RAMS — hence backfill migrations when a house rule
  changes (D-08).
- **Live primary renderer is DOCX**, not the PDF blade.
- **A gate ships with the fix, never before it** — the phase goal states this outright, and
  it is why GATE-06/07 are in the same phase as RULE-01/RULE-06.

### Integration Points
- Two new `ControlTextRuleViolations::DETECTORS` entries → consumed unchanged by
  `RamsBuilderService::reviewedToRisk()` tier 1.
- A new throwing re-check → `RamsComplianceUpgradeService::upgrade()`, flag-gated, caught
  at `RamsController.php:604`.
- A new repo-wide static source test (D-04) with a narrow, commented backup-file exclusion
  list.
- RULE-09/RULE-10 statement injection → hazard controls, the DOCX exclusions block, or the
  method statement (D-06 undecided).
- Possible backfill migration over existing `reviewed_data` (D-08, measure first).

</code_context>

<specifics>
## Specific Ideas

- **The user's stated priority in this discussion was avoiding false positives**, chosen
  three times running: negation-aware detection over a blunt ban; the permit picker left
  alone rather than stripped; auto-correct-then-throw rather than throw-only. The through
  line is that **a gate must not make a correct document un-generatable or silently
  rewrite an engineer's deliberate words.** Where the planner faces a similar call not
  covered above, that is the steer.
- **The one place the user went the other way is the static source test** — chosen
  specifically because reintroduction has reopened this milestone before. Breadth is
  wanted at *build* time; caution is wanted at *runtime*.
- **Validation happens on live** at `rams.21stcav.com` against production data, not a
  staging environment (carried from Phases 26 and 27). **Deploy as `stcav`, not root** —
  a Phase 27 verification run as root silently `git pull`-failed on dubious ownership,
  reported "Nothing to migrate", and tested pre-fix code. Note the VPS deploy key is
  read-only: push from a write-access machine, then pull on the server.
- **The proof job is 21CQ30960 (VW Blakelands)** — the professional review that triggered
  this milestone. It is the source of the FFP2/FFP3 contradiction, the confined-space
  mislabel and the RULE-11 fire-stopping contradiction. Last regenerated at RAMS 102
  (2026-08-26, Phase 27 verification). ROADMAP criterion 4 requires a clean regeneration
  through the DOCX path.
- The user selected only the GATE-06/07 area from four offered. The other three are
  recorded as constrained discretion, **except D-09 (RULE-11 scope), which is a genuine
  open question the planner must not resolve alone.**

</specifics>

<deferred>
## Deferred Ideas

- **RULE-11 — fire-stopping, one consistent position → Phase 31** (moved 2026-09-05,
  D-09). Not deferred vaguely: it has a home, a success criterion (Phase 31 criterion 5)
  and a traceability row. Phase 31 planning must note it is **wider than a COSHH scoping
  fix** — the exclusions list and hazard register need the same position, which is new
  surface for that phase.
- **GATE-17 — RCS exposure limits and RIDDOR** (`REQUIREMENTS.md:110`). Explicitly noted
  there as *extending* Phase 28's RULE-01: respirable crystalline silica controlled below
  its **WEL** (0.1 mg/m³, 8-hour TWA) and never called an "EAV"; no asbestos-awareness
  training in the RCS control; RIDDOR reporting is **0345 300 9923** or online, never an
  invented "HSE Incident Hotline". Same subject matter as RULE-01 and tempting to fold in
  — **it is a later phase.** Note it if the FFP3 work brushes against an EAV/WEL string.
- **BS 7671 / lock-off / test-dead wording** (`house-rules.md:64-71`, part of D-07). There
  is an open user decision on BS 7671 wording carried in project memory that this
  discussion did not settle. If the planner's route to RULE-09 requires settling it, that
  is a checkpoint question.
- **"First-fix power" → "first-fix AV signal/data/ELV cabling"** (`house-rules.md:76-79`).
  Part of §Electrical, likely deferrable out of RULE-09's minimum; if deferred, say so.
- **DATA-01 — `scope_items.decommission` is never populated** (raised by Phase 27's
  Blocker 2, tracked in `REQUIREMENTS.md` Group E). Belongs with the quote-import work,
  not here — but it is the reason D-06 must verify any signal before depending on it.
- **A general gate framework.** GATE-09 established the mechanism and GATE-06/07 are its
  first reuse; formalising it for GATE-11/12/13/14 remains milestone-level work.
- **Extending GATE-06/07 to worksheet output.** RAMS-scoped for now, mirroring the same
  boundary Phase 27 drew for GATE-09.

</deferred>

---

*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Context gathered: 2026-09-05*

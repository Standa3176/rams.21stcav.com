# Phase 27: Manual-Handling & Display-Lift House Rules - Context

**Gathered:** 2026-08-25
**Status:** Ready for planning — **with one blocking pre-planning edit** (see D-01)

<domain>
## Phase Boundary

Every generated RAMS states a single, non-negotiable display-lift team size derived from
one shared source; wall-mount removal is called out as the highest-risk lift on a
strip-out; mount and bracket rows stop inheriting display handling text; and GATE-09
errors rather than silently accepting a non-conforming lift.

**In scope:** the display-lift team-size policy and every place that states it (RAMS
hazard control text, the §6.7 material-handling table, the method-statement string, and
— by user decision — the worksheet safety profile); RULE-03's wall-mount removal
sequence; RULE-12's mount/bracket text inheritance; GATE-09.

**Out of scope:** FFP2/FFP3 and confined-space wording (Phase 28), CDM/A&E (Phase 29),
structural gates GATE-01/04/13/14 (Phase 30), standards/COSHH scoping (Phase 31), the
hazard include-when library itself (Phase 26, shipped).

**Scoping correction found during discussion — the ROADMAP names the wrong file.**
ROADMAP success criterion 1 cites `config/rams_tier1.php:78` as the home of the
`Two-person lift mandatory for displays 55" and above` threshold. **That string is
already gone** — Phase 26 removed `baseline_hazards` from that config entirely (`:78` is
now COSHH content). The size ladder RULE-02 targets is alive in a different, unnamed
place: `RamsComplianceUpgradeService::suggestHandlingMethod()`. Planning must not chase
the config file.

</domain>

<decisions>
## Implementation Decisions

### Display-lift team size — RULE-02 amended by 21CAV house decision

> ⚠️ **This phase's governing requirement was changed during discussion.** RULE-02 as
> written in `REQUIREMENTS.md:74` says *"All displays are two-operative team lifts
> regardless of panel size. Never four-operative; never conditional on screen size."*
> It is sourced from `house-rules.md:8-11`, which this milestone declares the winner in
> any app-vs-skill disagreement (`ROADMAP.md:13`). The user was shown that conflict
> explicitly, including that a size ladder is the exact defect the 21CQ30960
> professional review raised, and settled it deliberately. The amended position below
> is what Phase 27 builds.

- **D-01 (BLOCKING — do before planning):** The settled 21CAV position is a
  **two-operative floor with a ladder above it**:
  - **2 operatives minimum** for every display up to and including 90 inches
  - **3 operatives minimum** above 90 inches
  - **Never 1 operative** for a display, at any size. **Never 4 operatives**, at any size.

  RULE-02's four-operative ban and its "mechanical aids are additional, never a
  substitute" clause both survive intact. Only its *"regardless of panel size"* clause is
  amended.

  > **CORRECTION (2026-08-25, after research — user decision). A single-operative band
  > below 55″ is reinstated.** The D-01 text above is left unmodified; this amendment
  > supersedes its "never 1 operative" clause. Every other clause stands.
  >
  > **The bands now are:**
  >
  > | Item | Team size |
  > |---|---|
  > | Scheduling / touch / control panels ≤14″ | **No manual-handling row at all** — existing exclusion, unchanged |
  > | Display under 55″ | **1 operative** |
  > | Display 55″ to 90″ inclusive | **2 operatives minimum** |
  > | Display above 90″ | **3 operatives minimum** |
  > | Any display, any size | **Never 4 or more** |
  >
  > **Why it changed:** research surfaced that `SafetyProfileService` has no equivalent
  > of `suggestHandlingMethod()`'s ≤14″ small-panel exclusion, so D-04's removal of the
  > 55″ threshold would have put a "minimum 2-person lift" instruction on a 10.1″
  > room-booking panel. Asked to resolve that, the user kept the ≤14″ exclusion **and**
  > reinstated a single-operative band below 55″ — returning to the position first typed
  > at the start of the discussion (*"55 can be one man"*), which the two-operative-floor
  > answer had displaced. The user stated it twice; the reversal was named explicitly and
  > confirmed, and the exact boundary was pinned in a follow-up rather than assumed.
  >
  > **Consequences for planning:**
  > - The ≤14″ exclusion is **not** the same thing as the <55″ single-operative band. A
  >   ≤14″ control panel produces **no row**; a 43″ display produces a **1-operative
  >   row**. `DisplayLiftPolicy` must model three outcomes, not two: no-row, a team size,
  >   and the band boundaries between them.
  > - GATE-09's error conditions are now: 4 or more operatives at any size; 2 operatives
  >   above 90″; **1 operative at 55″ or larger**. 1 operative below 55″ is correct output,
  >   not a defect.
  > - D-05's silent 2-operative fallback for an **unresolvable** size is unchanged. Note
  >   the asymmetry this creates and do not "fix" it: an unknown size takes 2, which is
  >   above the <55″ band's 1. That is deliberate — unknown defaults conservatively.
  > - This is now a **two-step divergence from the skill**, not one. `house-rules.md:8-11`
  >   says two-operative regardless of size; the app says 1 / 2 / 3 by band. Still recorded
  >   app-side only — do not edit the skill.

  **Required edits before `/gsd:plan-phase 27` runs** — planning against the unamended
  text will produce a plan that contradicts this decision:
  1. `.planning/REQUIREMENTS.md:74` — RULE-02 restated as the floor-plus-ladder position,
     with a note that it is a deliberate 21CAV override of `house-rules.md`, dated
     2026-08-25, and why.
  2. `.planning/ROADMAP.md` §"Phase 27" success criterion **1** — currently asserts
     *"mandates a two-operative team lift for every display regardless of panel size"* and
     *"no wording implies a four-operative or size-conditional lift"*. The
     four-operative half stands; the size-conditional half does not.
  3. `.planning/ROADMAP.md` §"Phase 27" success criterion **3** — currently
     *"GATE-09 errors when any … RAMS specifies a display lift as anything other than
     two-operative"*. Becomes band-conformance: errors on 1 operative at any size, on 4+
     at any size, and on 2 where the panel exceeds 90 inches.
  4. `.planning/reference/21cav-rams-skill/references/house-rules.md` is the **skill**,
     not the app — **do not edit it**. The divergence is recorded on the app side only,
     so the skill stays a clean upstream reference.

- **D-02:** Above 90 inches the third operative is **required, not an allowance**.
  Generated text reads as a minimum team size. A panel-lift trolley or other mechanical
  aid does **not** discharge the third operative — the user was offered an aid-satisfiable
  variant and rejected it. This preserves RULE-02's "additional, not instead of" principle
  at the new band boundary.

  Consequence: the existing `>=65in -> "Two persons may lift if using a panel-lift trolley"`
  wording (`RamsComplianceUpgradeService.php:1261-1262`) is an aid-as-substitute
  construction. At 65–90 inches the floor is 2 anyway so it is harmless, but the same
  construction must not be carried above 90.

- **D-03:** The bands and the sentences they produce live in **one PHP class** (working
  name `DisplayLiftPolicy`). Every stating point reads it — `suggestHandlingMethod()`,
  the `MethodStatementService:461` string, the hazard-library seeder's Manual handling
  control text, `SafetyProfileService`, **and GATE-09 itself**. The gate and the
  generator must resolve the team size through the same call, so a future edit cannot
  make them disagree.

  Rejected: bands in `config/rams_tier1.php` — Phase 26 deliberately moved safety content
  *out* of that file (D-01/26), and putting it back partially reverses that. Rejected:
  bands in the DB hazard control text alone — §6.7 needs per-item output, not one
  paragraph.

  Precedent: `app/Services/Rams/LegacyHazardNameFoldMap.php` (Phase 26, Plan 26-08) is
  the established shape — a version-controlled PHP class holding a settled 21CAV
  position, consumed at a single choke point by every path that needs it.

- **D-04:** **Worksheets are in scope.** `SafetyProfileService::LARGE_DISPLAY_INCHES = 55`
  (`app/Services/Worksheet/SafetyProfileService.php:23`) is removed and the room warning
  reads from the same class. Effects: every display produces a lift warning (today a
  43-inch produces none), and the >90 band is stated on worksheets too. Rationale: two
  engineers reading two 21CAV documents about the same job must not be told different
  team sizes.

  Note the asymmetry — GATE-09's *scope* was not extended by this decision. See
  Claude's Discretion.

- **D-05:** When the display's size **cannot be resolved**, take the 2-operative floor
  **silently** — no confirmation flag, no gate error. The inch-parsing regex at
  `RamsComplianceUpgradeService.php:1237-1240` returns null for descriptions like
  "Samsung QM98 commercial display" or a bare part number.

  The user was shown, and declined, three alternatives: flagging it `needs_confirmation`
  via Phase 26's D-06 mechanism, erroring in GATE-09, and falling back to `weight_kg`
  metadata. **Accepted residual risk, recorded deliberately:** a >90-inch panel whose
  description carries no parseable inch number ships a 2-operative instruction, and
  nothing surfaces it. Do not re-engineer this away without asking.

### Claude's Discretion

The user chose not to discuss these. The planner decides, within the stated constraints.

- **RULE-12 — mount/bracket rows inheriting display text.** Root cause is located and
  precise: in `suggestHandlingMethod()` the `display` branch (`:1254`) is evaluated
  **before** the `mount`/`bracket` branch (`:1270`), so a line reading "double-arm wall
  mount for 65 inch display" matches on the word *display* and returns display handling
  text — exactly the 21CQ30960 §6.7 defect. Reordering the branches is the minimum fix.

  **Constraint:** RULE-12's actual wording is stronger than reordering —
  *"derive from actual equipment weight, manufacturer handling requirements and route
  assessment — never from screen diagonal alone."* The RAMS path today has no weight
  input at all; `SafetyProfileService` reads a `weight_kg` tag that the RAMS path ignores,
  and its coverage on real quote lines is **unverified**. The planner must either (a)
  verify `weight_kg` coverage and use it, or (b) fix the ordering, satisfy RULE-12's
  "mount rows must not inherit display text" clause, and record the
  derive-from-weight clause as explicitly deferred with a reason. Do not silently
  narrow RULE-12 to the ordering fix without saying so.

  **Constraint:** `house-rules.md:18-19` gives the settled non-display position —
  *"wall mounts and rack rails are usually two-person, small brackets and video bar
  mounts single-person."* Non-display items are NOT covered by D-01's two-operative
  floor; single-person is correct for a small bracket. D-01 governs displays only.

- **RULE-03 — wall-mount removal statement.** ROADMAP success criterion 2 allows either
  the method statement or a hazard control. Needs a deterministic strip-out/decommission
  signal; `EquipmentClassifierService::textIndicatesDrilling()` is the established
  precedent for deriving a real signal rather than hardcoding `false` (Plan 26-07).

  **Constraint:** the required sequence is fixed by `house-rules.md:13-16` — controlled
  to the lowest practicable height, **one operative each side**, before release from the
  mount, and stated *explicitly* as the highest-risk lift on a strip-out. Note "one
  operative each side" is a two-operative construction and is consistent with D-01's
  floor. **No AI** decides whether this statement applies (`CLAUDE.md:12` — AI is never
  allowed to invent scope; carried forward from Phase 26's D-05 correction).

- **GATE-09 — enforcement mechanism.** **There is no gate framework in this codebase.**
  GATE-03 and GATE-08 are marked shipped (`REQUIREMENTS.md:52-53`, quick task
  260817-r5e) but were delivered as *generator fixes* that make references resolve — not
  as error-raising checks. A `grep` for gate/validator classes across `app/` returns only
  `LegacyHazardNameFoldMap.php` and a test, both incidental. GATE-09 therefore
  **establishes the mechanism GATE-06/07/11/12/13/14 will reuse** — that is a bigger
  decision than one gate, and the planner should treat it as such.

  Open sub-decisions left to planning: whether "errors" blocks generation, raises a
  blocking banner on the RAMS review screen, or both; whether it runs at generation time,
  review time, or both; and whether GATE-09 policing extends to worksheet output (D-04
  wired the *numbers* to worksheets but did not extend the *gate*).

  **Constraint:** GATE-09 must resolve team size via D-03's shared class, never its own
  copy of the bands. **Constraint:** per D-05, an unresolvable display size is not a gate
  error.

- **Reversibility.** Not raised in discussion, but Phase 26's `<specifics>` establishes it
  as a standing project condition: this milestone is validated **on live against
  production data**, so a bad safety position must be one `.env` edit from off, not a git
  revert plus redeploy. `RAMS_HAZARD_LIBRARY_TIERING` (Phase 26) and
  `RAMS_UNIFIED_COMPOSER` are the precedents. **Strong steer:** gate GATE-09's erroring
  behaviour behind its own flag. A gate that blocks generation is exactly the kind of
  change that needs an instant off switch on live.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Source of truth — the 21cav-rams skill (settled positions)
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Manual handling"
  (`:6-19`) — the unamended two-operative position, the wall-mount removal sequence
  (RULE-03, still authoritative and unamended), and the non-display guidance that
  constrains RULE-12. **Read `<decisions>` D-01 first: its team-size clause has been
  deliberately overridden on the app side. Every other line of this section stands.**
- `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` — the validation-gates rationale
  behind GATE-09.

### Project planning
- `.planning/ROADMAP.md` §"Phase 27: Manual-Handling & Display-Lift House Rules"
  (`:129-142`) — goal and 4 success criteria. **Criteria 1 and 3 require the D-01 edit
  before they are usable as a plan target.**
- `.planning/REQUIREMENTS.md` §"Group B" `:74-75`, `:84` — RULE-02, RULE-03, RULE-12;
  §"Group A" `:62` — GATE-09. **RULE-02 requires the D-01 edit.**
- `.planning/phases/26-hazard-library-structural-inversion/26-CONTEXT.md` — D-01 (DB is
  the hazard runtime store), D-05 correction (no AI decides safety scope), D-06
  (needs_confirmation surfacing), and the live-validation / env-flag posture.
- `.planning/phases/26-hazard-library-structural-inversion/26-VERIFICATION.md` — what
  Phase 27 is building on top of.
- `CLAUDE.md:12` — AI is never permitted to invent scope. Binds RULE-03's trigger and any
  temptation to have a model decide lift team size.

### Code this phase changes
- `app/Services/Rams/RamsComplianceUpgradeService.php:1228-1290` —
  `suggestHandlingMethod()`. `:1237-1240` inch-parsing regex (D-05's null case);
  `:1254-1266` the display size ladder (**the actual RULE-02 target — 4 persons at
  85 inches and above, 3 persons at 65 and above**); `:1270-1281` the mount/bracket branch
  that the display branch shadows (**the RULE-12 root cause**); `:1203-1213` the
  `material_handling_derived` summary statement ("Team lifts (minimum 2 persons) … for
  items over 20 kg").
- `app/Services/MethodStatementService.php:461` — hardcoded *"mount displays and screens
  using two-person lifts"* method-step string.
- `app/Services/Worksheet/SafetyProfileService.php:23` — `LARGE_DISPLAY_INCHES = 55`
  (D-04 removes it); `:37-39` the warning it produces.
- `app/Services/Rams/LegacyHazardNameFoldMap.php` — **the shape D-03 should follow.**
- `database/seeders/` hazard-template seeder — Manual handling control text (Phase 26's
  library; the seeder is the version-controlled source, the DB is the runtime store).
- `app/Http/Controllers/RamsReviewController.php:217`, `:247` and
  `app/Services/RamsReviewDataService.php:161`, `:203` — the `needs_confirmation`
  surfacing Phase 26 built; the natural precedent if GATE-09 surfaces on the review
  screen.
- `app/Services/RamsBuilderService.php` — `runPipeline()` and `runFromReview()`, the two
  generation entry points. **Phase 26 was closed prematurely twice for fixing only one of
  them.** Any Phase 27 change must be proven on both.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`LegacyHazardNameFoldMap`** — version-controlled PHP class holding a settled 21CAV
  position, consumed at one choke point. Direct template for D-03's `DisplayLiftPolicy`.
- **`EquipmentClassifierService::textIndicatesDrilling()`** — the pattern for deriving a
  real deterministic scope signal from job text, established in Plan 26-07 to replace a
  hardcoded `false`. The model for RULE-03's strip-out signal.
- **`needs_confirmation` flag + review-screen treatment** (Phase 26, D-06) — an existing,
  wired surface for "this needs a human's eye". D-05 declined to use it for unresolvable
  sizes, but it remains available if GATE-09 wants a non-blocking surface.
- **`RAMS_HAZARD_LIBRARY_TIERING` / `RAMS_UNIFIED_COMPOSER`** — proven env-flag rollback
  pattern for live-validated safety changes.
- **`weight_kg` / `display_size_in` structured tags** — `SafetyProfileService` already
  reads them metadata-first with keyword fallback (`:14-18`). The RAMS path does not.
  Relevant to RULE-12; **coverage on real quote lines is unverified** — verify before
  depending on it.

### Established Patterns
- **Metadata-first, keyword-fallback.** `SafetyProfileService`'s documented rule
  (`:14-18`). `suggestHandlingMethod()` is keyword/regex-only and predates it — the
  divergence is why the two documents can disagree.
- **Deterministic derivation only for safety content.** No AI decides what applies
  (`CLAUDE.md:12`; Phase 26 D-05 correction). Applies to lift team size and to RULE-03's
  trigger.
- **Two generation entry points.** `runPipeline()` (fresh) and `runFromReview()`
  (already-reviewed quote package — *most real projects*). Phase 26 shipped twice against
  only one and had to be reopened both times.
- **Denormalised hazard storage.** `reviewed_data`/`generated_data` hold plain arrays, not
  FKs. Reseeding cannot retro-change an already-issued RAMS — no historical migration
  needed, but also no retro-fix of documents already out the door.
- **Live primary renderer is DOCX**, not the PDF blade
  (`DocxBuilderService::buildRiskAssessment()`). Any "the text now reads X" claim must be
  proven through the DOCX path or it proves nothing about what engineers receive.

### Integration Points
- New `DisplayLiftPolicy` class → consumed by `suggestHandlingMethod()`,
  `MethodStatementService:461`, the hazard seeder, `SafetyProfileService`, and GATE-09.
- Branch reordering inside `suggestHandlingMethod()` (RULE-12).
- A strip-out/decommission signal → RULE-03 statement injection.
- GATE-09 → a new enforcement surface (mechanism undecided; see Claude's Discretion).
- Both `RamsBuilderService` entry points.

</code_context>

<specifics>
## Specific Ideas

- **The band numbers are the user's own, stated verbatim:** *"55 can be one man, 65-85
  2 man and >90 3 man."* Shown the RULE-02 conflict, the user first selected the
  **two-operative floor** variant, dropping the one-man band. Research then surfaced the
  small-panel problem, and in resolving it the user **reinstated the single-operative
  band below 55 inches** — see D-01's correction block, which is the governing text.
  The final position is closer to what was first typed than to the intermediate
  floor-only answer.
- **No band gaps remain.** Under D-01-as-corrected: ≤14 inch control panels get no row;
  below 55 is 1 operative; 55 to 90 inclusive is 2; above 90 is 3. The 56–64 and 86–90
  gaps in the originally-typed ladder are closed.
- **The reversal was raised, not absorbed.** Claude flagged that "1 man below 55" reverses
  D-01's "never 1 operative at any size" and that RULE-02/GATE-09 had already been
  committed on the floor position. The user confirmed, and the exact boundary was pinned
  in a follow-up question rather than inferred. Recorded so a later reader does not treat
  the divergence from `house-rules.md` as drift.
- **Validation happens on live** at `rams.21stcav.com` against production data, not a
  staging environment (carried from Phase 26). Deploy as `stcav`, not root.
- **The proof job is 21CQ30960 (VW Blakelands)** — the professional review that triggered
  this milestone, and the source of both the §6.7 mount-inherits-display defect (RULE-12)
  and the size-ladder finding. Currently at RAMS 98, verified clean for Phase 26 on
  2026-08-25. ROADMAP success criterion 4 requires regenerating it without tripping
  GATE-09.
- The user chose not to discuss RULE-12, RULE-03 or GATE-09; all three are recorded as
  discretion **with constraints**, not as open questions.

</specifics>

<deferred>
## Deferred Ideas

- **Weight-driven manual handling (RULE-12's full clause)** — deriving handling text from
  actual equipment weight, manufacturer handling requirements and route assessment rather
  than screen diagonal. Blocked on unverified `weight_kg` coverage across real quote
  lines. If the planner cannot verify coverage, this becomes its own phase and RULE-12
  ships partially — which must be stated explicitly, not glossed.
- **Flagging unresolvable display sizes for confirmation** — D-05 accepted silent
  2-operative fallback. Revisit only if a live pack is found stating 2 operatives for a
  panel that is actually above 90 inches.
- **Extending GATE-09 to worksheet output** — D-04 wired the worksheet to the shared band
  numbers but left the gate RAMS-scoped.
- **A general gate framework** — GATE-09 establishes the mechanism, but formalising it for
  the remaining six gates (06, 07, 11, 12, 13, 14) is milestone-level work, not Phase 27's.
- **Structured job-scope capture** (strip-out flag, building age, operative count) —
  carried forward unresolved from Phase 26. Would make RULE-03's trigger deterministic by
  data rather than by text inference.

</deferred>

---

*Phase: 27-manual-handling-display-lift-house-rules*
*Context gathered: 2026-08-25*

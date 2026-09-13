# Phase 30: Structural Validation Gates - Context

**Gathered:** 2026-09-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Ship the gates that catch a RAMS **contradicting itself** — not a house rule broken
(Phases 27–29 did that), but Section A disagreeing with Section B, or a line referencing
something that is not in the document.

**In scope — five gates:**

| Gate | Check |
|---|---|
| GATE-01 | Orphan controls — a method step / hazard control referencing a document, permit or hold point with no supporting hazard row and client-responsibility entry |
| GATE-02 | Every area/room has at least one method step |
| GATE-04 | Residual ≤ initial on every hazard (error); residual severity below initial severity (flag for human review) |
| GATE-13 | Hot-works contradiction — "no hot works" asserted while a hot-works permit is required or solder/flux is listed in COSHH |
| GATE-14 | Missing risk references — a method step failing to cite hazards its own text plainly implies |

**Out of scope:** GATE-05 and GATE-10 (Phase 31); making the COSHH or standards tables
job-conditional (Phase 31); any change to the Phase 26 hazard library itself.

</domain>

<decisions>
## Implementation Decisions

### Gate scope and arming

- **D-01:** All five requirements ship in Phase 30 — GATE-01, GATE-02, GATE-04, GATE-13, GATE-14. The ROADMAP goal line ("the three gates") is **stale**: it predates the 2026-08-25 addition of GATE-13/14, which the ROADMAP **Requirements** line and the REQUIREMENTS.md traceability table (`:166-167`) both assign to Phase 30. The planner MUST write the two missing success criteria for GATE-13 and GATE-14 — the existing four cover only 01/02/04 — and should correct the goal line in the same pass.

- **D-02:** **GATE-13 ships whole but disarmed**, and is flipped on in Phase 31. Its COSHH half cannot fire correctly today: `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` sets `$data['coshh_baseline']` **unconditionally** (`:81`, docblock `:34` says "ALWAYS set") from `config/rams_tier1.php`, whose baseline carries Tin/Lead Solder (`:162`) and Rosin Flux (`:173`). Every generated RAMS therefore lists solder and flux, so an armed GATE-13 would error on any document also asserting "no hot works" — the common case, and precisely the "no gate ships ahead of the default it's meant to police" failure the milestone header warns against. Phase 31 (GATE-10 / RULE-05) makes that table conditional. Build both halves now with tests; do not split one gate across two phases.

- **D-03:** **All five gates ship disarmed** (`env(..., false)`), flipped after live verification. This follows 29 D-03 rather than the GATE-06/07/09 armed precedent. The config comment block at `config/rams_tier1.php:115-133` records why: GATE-06/07/09 could default `true` **because the corpus was measured clean at ship time**; GATE-11/12 default `false` because 46/54 production rows still carried the placeholder. Phase 30's corpus has not been measured, so armed-by-default is not available. Deploy order: ship code (flags false) → verify live regeneration → flip each flag as a separate one-line `.env` change.

- **D-04:** *(Claude's discretion — user delegated)* **Three kill-switch flags**, partitioned by when they flip and what fails independently:
  - `RAMS_STRUCTURAL_GATES` → GATE-01 + GATE-02 + GATE-04 (flip together after Phase 30 live verification; shared failure mode = false positive on legitimate output)
  - `RAMS_MISSING_RISK_REF_GATE` → GATE-14 alone (distinct failure mode — bad hazard inference; MUST be killable without disarming the structural trio)
  - `RAMS_HOT_WORKS_GATE` → GATE-13 (flips in Phase 31 per D-02)

  Five separate flags were rejected: 01/02/04 could only ever be flipped in lockstep, so two extra config blocks buy no rollback granularity. A single phase-wide flag is impossible — GATE-13 flips a phase later than the rest. Each flag is **new and independent** and must never reuse `RAMS_DISPLAY_LIFT_GATE`, `RAMS_PPE_CEILING_ELECTRICAL_GATE` or `RAMS_CDM_AE_GATE`, per the established doctrine at `config/rams_tier1.php:91-95` and `:114-117`. Note the counter-example: GATE-11 and GATE-12 sharing one flag produced the status ambiguity reconciled at `REQUIREMENTS.md:72` — do not repeat it.

### GATE-01 orphan matching

- **D-05:** GATE-01 errors when **either** the hazard row **or** the client-responsibility entry is missing — both are required support. Two of three sources agree: `PORTING-NOTES.md:66-68` ("must have a matching hazard row *and* a matching `clientReqs` entry") and `REQUIREMENTS.md:62`. **ROADMAP criterion 1 is wrong** — its "with no matching hazard row AND no matching `clientReqs` entry" describes a gate that only fires when both are absent, letting the half-supported case through. The planner should correct ROADMAP criterion 1 to match the canonical wording.

- **D-06:** The trigger vocabulary — what counts as "references a document, permit or hold point" (asbestos register, permit to work, hot-works permit, refurbishment & demolition survey, isolation certificate, hold point, …) — lives in `config/rams_tier1.php` as **data, not code**, so it is tunable without a deploy. This matters specifically because a gate's false-positive rate can only be learned from the live corpus, and D-03 means that learning happens post-deploy.

- **D-07:** Hazard-side matching **reuses Phase 26's signal machinery** — `app/Services/Rams/HazardIncludeWhenResolver.php` (`resolve(Collection $library, array $signals): Collection`, with `TIER2_ACTIVITY_SIGNALS` / `TIER2_KEYWORD_SIGNALS` / `TIER3_KEYWORD_PRECHECK` maps) against the `include_when` column on `hazard_templates`. Do not build a second, parallel hazard-matching vocabulary; a trigger is satisfied on the hazard side when its mapped signal resolves a hazard into the set.

- **D-08:** **`clientReqs` does not exist in this application.** It is a skill-side JSON key (`.planning/reference/21cav-rams-skill/assets/*.json`), rendered by the skill as "Section 4 — Client responsibilities and pre-start hold points". A repo-wide search finds it **only** in `.planning/` docs and the vendored skill — zero app-side occurrences. Its app equivalent is the **union of two buckets**: `$data['client_responsibilities']` (`resources/views/pdf/rams.blade.php:298`) and `client_responsibilities_expanded` (`:447`, written at `app/Services/Rams/RamsDisplayPatchService.php:402`), which render together under §6.3 "Pre-Installation Requirements (Client Responsibilities)" (`:1568-1587`). Note the app's Section 4 is *Scope of Works* — the skill's "Section 4" does NOT map to the app's Section 4. Both buckets are pooled and matched with the **same signal vocabulary** as D-07, so a trigger's definition of satisfaction lives in exactly one place.

### Claude's Discretion

- **D-04 (flag granularity)** — user answered "You decide". Reasoning recorded inline above.
- **Where the five gate methods live** — not discussed. The four existing gates are private static methods on `RamsComplianceUpgradeService` (already 2,038 lines). The planner may keep them there for consistency or extract per-gate classes; either is acceptable provided each gate stays independently flag-gated and independently testable.
- **Error message wording, and first-violation vs collect-all** — every existing gate throws on the FIRST violation found (`:1311`, `:1442`). Planner's call whether the structural gates follow suit or report all violations at once.

### Open questions for the researcher (NOT decided — do not assume)

1. **GATE-04 has no warn channel, and none exists in the codebase.** GATE-04's spec is explicitly two-tier — *flag* `s2 < s1` for human review, *error* when residual score exceeds initial (`REQUIREMENTS.md:65`, ROADMAP criterion 3). But every gate in the app is fail-closed: `RamsGenerationException` thrown from `upgrade()`, caught at `RamsController.php:597` and `:852`, redirect-with-error. A grep across `app/Services/Rams/` and `RamsController.php` for any non-throwing advisory surface returns **nothing**. Phase 30 must invent the warning mechanism or GATE-04 cannot meet its spec — and the phase's `UI hint: yes` ("gate errors/warnings surface on the RAMS review screen") depends on it. This also unblocks GATE-05 in Phase 31, which is warn-only. **This area was offered and not selected for discussion; it is genuinely unresolved, not deferred.**
2. **GATE-02's definition of "area".** Which field enumerates areas/rooms, and whether a RAMS with zero areas passes vacuously or errors.
3. **GATE-14's implied-hazard inference.** Deterministic keyword→hazard map, reuse of the D-07 signal maps, or AI. Highest false-positive risk in the phase and the reason it gets its own kill switch (D-04).
4. **Corpus measurement.** D-03 defers arming to post-deploy verification; the researcher should assess whether a read-only measurement pass (the `29-MEASUREMENT.md` pattern) is worth running first, and whether any gate needs a backfill migration before its flag can be flipped.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Source of truth — gates
- `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` §66-70 — the 12 validation gates; **GATE-01's authoritative wording** (settles the AND/OR contradiction, D-05) and the canonical asbestos-orphan failure
- `.planning/reference/21cav-rams-skill/SKILL.md` §46-48 — the same orphan-control rule stated from the skill side
- `.planning/REQUIREMENTS.md` `:62` (GATE-01), `:63` (GATE-02), `:65` (GATE-04), `:74` (GATE-13), `:75` (GATE-14) — requirement text; `:154-167` traceability table; `:72` the shared-flag counter-example
- `.planning/ROADMAP.md` §225-238 — Phase 30 goal, requirements, 4 success criteria (criterion 1 needs correcting per D-05; two criteria still to be written per D-01)
- `.planning/reference/SKILL-RESYNC-2026-08-26.md` — the truncated-snapshot recovery that added Group D; context for why GATE-13/14 arrived late

### Gate implementation pattern (read before writing any gate)
- `app/Services/Rams/RamsComplianceUpgradeService.php` `:62-97` (flag-gated dispatch in `upgrade()`), `:1201` `enforceCdmGate`, `:1227` `enforceEmergencyGate`, `:1319` `enforceDisplayLiftGate`, `:1446` `enforceFfp2AndConfinedSpaceGate` — the four shipped gates
- `config/rams_tier1.php` `:74-134` — the three existing gate flags **and the decision comments explaining armed-vs-disarmed defaults** (`:115-133` is the D-03 rationale; `:91-95` and `:114-117` are the independent-flag doctrine)
- `app/Services/Rams/ControlTextRuleViolations.php` — the ordered detector registry, `detect()` / `detectAll()`, conservative-by-construction and negation-aware discipline
- `app/Http/Controllers/RamsController.php` `:597`, `:852` — the only two places a gate exception is caught and surfaced

### GATE-01 specific
- `app/Services/Rams/HazardIncludeWhenResolver.php` — Phase 26 signal resolver reused for hazard-side matching (D-07)
- `resources/views/pdf/rams.blade.php` `:298`, `:447`, `:1568-1587` — the two client-responsibility buckets and where they render (D-08)
- `app/Services/Rams/RamsDisplayPatchService.php` `:402` — where `client_responsibilities_expanded` is written
- `.planning/reference/21cav-rams-skill/assets/example-project.json` `:234` and `renault-golden-project.json` `:56` — the skill's `clientReqs` shape, for fixture authoring

### GATE-13 specific
- `app/Services/Rams/Tier1RamsDefaultsService.php` `:81` (+ docblock `:34`) — the unconditional `coshh_baseline` assignment that forces D-02
- `config/rams_tier1.php` `:162`, `:173` — Tin/Lead Solder and Rosin Flux in the baseline

### Prior-phase decisions that bind this phase
- `.planning/phases/29-cdm-duty-holder-emergency-arrangements/29-CONTEXT.md` — D-03 (disarmed-by-default precedent), D-02 (measure-first + idempotent backfill)
- `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-CONTEXT.md` — D-01 (negation-aware detection), D-03 (auto-correct library-owned, throw on engineer-typed), D-04 (static source test atop the runtime gate), D-08 (independent flag)
- `.planning/phases/27-manual-handling-display-lift-house-rules/27-CONTEXT.md` — D-03 (one PHP class owns the rule and the sentences it produces)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RamsComplianceUpgradeService::upgrade()` — the single dispatch point; all four live gates hang off it behind `config('rams_tier1.*_gate_enabled')` checks. New gates plug in the same way.
- `ControlTextRuleViolations` — ordered detector registry with per-line `detect()` and array-wide `detectAll()`. The natural machinery for GATE-01's trigger detection and GATE-13's contradiction scanning; already carries the negation-aware, conservative-by-construction discipline.
- `HazardIncludeWhenResolver` — Phase 26's signal→hazard resolver, reused wholesale for D-07/D-08 matching.
- `RamsGenerationException` + the two controller catch sites — the entire existing error-surfacing path.

### Established Patterns
- **Every gate is fail-closed and flag-gated.** When the flag is false the method is never called and `upgrade()` proceeds byte-identical to pre-gate behaviour — no redeploy needed to roll back. Preserve this property exactly.
- **Throw on the FIRST violation**, with a message naming the offending item.
- **Flags are independent by doctrine** — one gate's rollback must never disarm another's.
- **Armed-by-default requires a measured-clean corpus.** This is the rule D-03 applies.

### Integration Points
- `config/rams_tier1.php` — three new flag blocks (D-04) plus the GATE-01 trigger vocabulary (D-06).
- `RamsComplianceUpgradeService::upgrade()` `:62-97` — three new flag-gated dispatch blocks.
- `RamsController.php` `:597` / `:852` — existing catch sites; the warn channel (open question 1) will need somewhere new to surface, likely the review screen.
- **Three generation entry points plus the live PDF render** must all see the gate — Plan 27-07 closed exactly this class of bypass for GATE-09 (`RamsController::updateAndDownload()` mirroring `material_handling` before `upgrade()`, and the PDF template reading gated `generated_data`). Check the same four surfaces here.

</code_context>

<specifics>
## Specific Ideas

- 21CQ30960 (VW Blakelands) is the canonical real-document target — ROADMAP criterion 4 requires all gates to pass clean against a fresh regeneration of it, and it is the document whose professional review (RAMS 97) produced GATE-13 and GATE-14 in the first place.
- GATE-13's concrete defect to reproduce in a fixture: RA18 says no hot works, §6.8 requires a hot-works permit for soldering, COSHH carries Tin/Lead solder and rosin flux — three sections disagreeing.
- GATE-14's concrete defect: Step 4 "Display & Mount Installation" cites RA11/12/13/21 but omits RA01 Working at Height and RA02 Manual Handling, on a step entirely about lifting displays onto wall mounts.
- GATE-01's canonical failure: "review the asbestos register" with no asbestos hazard behind it.

</specifics>

<deferred>
## Deferred Ideas

- **GATE-13's arming** — deliberately deferred to Phase 31 (D-02), not dropped. Phase 31 must flip `RAMS_HOT_WORKS_GATE` once RULE-05/GATE-10 make the COSHH table job-conditional.
- **Making the COSHH and standards tables job-conditional** — Phase 31 scope; raised here only as the blocker behind D-02.
- **DATA-01** (`REQUIREMENTS.md:119`) — `scope_items.decommission` never populated. Unscheduled Group E pipeline defect, not a gate; noted because it means some hazard signals can never fire on real jobs, which could mask or distort GATE-01 matching (D-07) on strip-out work.

</deferred>

---

*Phase: 30-structural-validation-gates*
*Context gathered: 2026-09-13*

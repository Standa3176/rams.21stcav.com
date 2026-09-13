# Phase 30: Structural Validation Gates - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-09-13
**Phase:** 30-structural-validation-gates
**Areas discussed:** Gate scope, GATE-01 orphan matching
**Areas offered but not selected:** Warn vs error channel, GATE-14 implied hazards

---

## Area selection

| Option | Description | Selected |
|--------|-------------|----------|
| Gate scope — 3 or 5 | GATE-13/14 assigned to Phase 30 in Requirements + traceability, but no success criterion covers them | ✓ |
| Warn vs error channel | GATE-04 needs two-tier flag/error; no warning surface exists in the codebase | |
| GATE-01 orphan matching | AND/OR contradiction across sources; what counts as a document/permit/hold-point reference | ✓ |
| GATE-14 implied hazards | Inferring which hazards a method step's text implies; highest false-positive risk | |

---

## Gate scope

### Q1 — What actually ships in this phase?

| Option | Description | Selected |
|--------|-------------|----------|
| All five — goal line is stale | Requirements line + traceability table are authoritative; planner writes the two missing success criteria | ✓ |
| 01/02/04 + 13, defer 14 | GATE-13 clones the shipped GATE-08 pattern cheaply; GATE-14's inference is a different problem class | |
| Three only — criteria are authoritative | Ship exactly what the four success criteria describe; move 13/14 to a new phase | |
| You decide | | |

**User's choice:** All five — goal line is stale
**Notes:** Nothing gets orphaned; the "three gates" wording predates the 2026-08-25 additions.

### Q2 — How should GATE-13 ship, given its COSHH half would error on most of the corpus?

Context established before asking: `Tier1RamsDefaultsService:81` sets `coshh_baseline` unconditionally, and the config baseline carries Tin/Lead Solder (`:162`) and Rosin Flux (`:173`) — so every generated RAMS lists them.

| Option | Description | Selected |
|--------|-------------|----------|
| Permit half now, COSHH half in 31 | Ship only the self-contained "no hot works" vs "permit required" check | |
| Whole gate, disarmed until 31 | Build both halves with tests behind a flag defaulting false; Phase 31 flips it | ✓ |
| Whole gate, COSHH check scoped to engineer additions | Fire only on `$data['coshh']`, never on `coshh_baseline` (mirrors 28 D-03) | |
| You decide | | |

**User's choice:** Whole gate, disarmed until 31
**Notes:** Keeps one gate in one phase rather than splitting it across two.

### Q3 — Arming posture for gates 01, 02, 04, 14

| Option | Description | Selected |
|--------|-------------|----------|
| Measure corpus first, then decide per gate | 30-MEASUREMENT.md pass; clean gates ship armed, dirty ones disarmed + backfill | |
| All disarmed, flip after live verification | Uniform 29 D-03 posture; safest against deploy-order traps | ✓ |
| Arm 01/02/04, disarm 13/14 | Assume the structural trio is clean | |
| You decide | | |

**User's choice:** All disarmed, flip after live verification
**Notes:** Consistent with the `config/rams_tier1.php:115-133` rule that armed-by-default requires a measured-clean corpus.

### Q4 — How many kill-switch flags?

| Option | Description | Selected |
|--------|-------------|----------|
| Three — trio / 14 / 13 | Partitioned by flip timing and independent failure mode | (Claude's pick) |
| Five — one per gate | Strictest reading of independent-flag doctrine | |
| Two — 30-now / 31-later | Minimal config surface, but couples GATE-14's inference risk to the trio | |
| You decide | | ✓ |

**User's choice:** You decide
**Claude's pick and reasoning:** Three flags. GATE-01/02/04 flip together and share a failure mode, so separate flags buy no rollback granularity. GATE-14 fails differently (bad inference) and must be killable without taking the trio down — the one coupling likely to actually bite. GATE-13 flips a phase later, so a single phase-wide flag is impossible.

---

## GATE-01 orphan matching

### Q1 — Which failure condition?

Context established before asking: `PORTING-NOTES.md:66-68` and `REQUIREMENTS.md:62` both say both supports are required; ROADMAP criterion 1 says fire only when both are missing.

| Option | Description | Selected |
|--------|-------------|----------|
| Either missing → error | Matches canonical PORTING-NOTES + REQUIREMENTS; ROADMAP criterion 1 gets corrected | ✓ |
| Both missing → error, one missing → warn | Two-tier like GATE-04; needs the warn channel that doesn't exist | |
| Both missing → error only | Literal ROADMAP criterion 1; lets half-supported references through | |
| You decide | | |

**User's choice:** Either missing → error

### Q2 — How is a document/permit/hold-point reference detected?

| Option | Description | Selected |
|--------|-------------|----------|
| Curated phrase list in config | Trigger vocabulary as data in `config/rams_tier1.php`; tunable without deploy | ✓ |
| New detectors in ControlTextRuleViolations | Inherits negation-aware, conservative-by-construction discipline; vocabulary is code | |
| Both — detector reads config vocabulary | Logic in the registry, phrase list in config | |
| You decide | | |

**User's choice:** Curated phrase list in config
**Notes:** Chosen partly because D-03 means false-positive rate is only learned post-deploy, so the vocabulary must be tunable without a redeploy.

### Q3 — What counts as a matching hazard row?

| Option | Description | Selected |
|--------|-------------|----------|
| Each config entry carries its own matchers | Trigger phrases + hazard matcher + client-req matcher as a triple | |
| Reuse Phase 26 include-when signals | Map trigger → signal key, check against the resolved hazard set | ✓ |
| Head-noun substring match | Simplest; brittle on synonyms | |
| You decide | | |

**User's choice:** Reuse Phase 26 include-when signals
**Notes:** Verified feasible during discussion — `HazardIncludeWhenResolver::resolve(Collection $library, array $signals)` exists with tiered signal maps, and `include_when` is a real column on `hazard_templates`.

### Q4 — Which bucket is the app's `clientReqs`?

Context established before asking: `clientReqs` is a skill-side JSON key with zero app-side occurrences; the app has two buckets rendering at §6.3.

| Option | Description | Selected |
|--------|-------------|----------|
| Union of both, signal-matched | Both buckets pooled, same Phase 26 signal vocabulary as the hazard side | ✓ |
| Union of both, keyword-matched | Matched on the trigger's own key term instead of signals | |
| Expanded bucket only | Treat the legacy flat array as deprecated for gate purposes | |
| You decide | | |

**User's choice:** Union of both, signal-matched
**Notes:** Gives one matching mechanism for both halves of the gate.

---

## Claude's Discretion

- **Flag granularity (D-04)** — user answered "You decide"; three flags chosen, reasoning above and in CONTEXT.md.
- **Where the gate methods live** — not discussed; planner's call between extending `RamsComplianceUpgradeService` and extracting per-gate classes.
- **First-violation vs collect-all reporting** — not discussed; existing gates throw on first violation.

## Unresolved (carried to CONTEXT.md as open questions)

- **GATE-04's warn channel** — the area was offered and declined. GATE-04's spec is inherently two-tier and no non-throwing advisory surface exists anywhere in the codebase. Recorded as open question 1 for the researcher, not as a deferred idea, because the phase cannot meet GATE-04's spec without resolving it.
- GATE-02's definition of "area".
- GATE-14's implied-hazard inference approach.
- Whether a corpus measurement pass should precede arming.

## Deferred Ideas

- GATE-13's arming → Phase 31 (deliberate, per D-02).
- COSHH/standards tables becoming job-conditional → Phase 31 scope.
- DATA-01 (`scope_items.decommission` never populated) → unscheduled Group E; noted because it can mask GATE-01 matching on strip-out work.

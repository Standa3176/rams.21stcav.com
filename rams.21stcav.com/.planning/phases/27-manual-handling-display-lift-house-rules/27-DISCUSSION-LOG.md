# Phase 27: Manual-Handling & Display-Lift House Rules - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-25
**Phase:** 27-manual-handling-display-lift-house-rules
**Areas discussed:** Lift text (display-lift team size, single source, worksheet scope, unresolvable size)

---

## Gray area selection

Four areas were offered: Lift-text single source, Mount/bracket rows (RULE-12),
Wall-mount removal (RULE-03), GATE-09 enforcement.

**User's response (free text):** *"do lift text and take large display 55\" can be one man
. 65-85 2 man and >90 3 man"*

**Notes:** Selected Lift text only, and stated a size-conditional team ladder in the same
breath. That ladder directly contradicts RULE-02, the requirement the phase exists to
enforce, so the discussion was halted before proceeding.

---

## RULE-02 conflict — escalated before proceeding

Claude flagged, before asking anything further, that the stated ladder reverses rather
than clarifies the phase:

- `REQUIREMENTS.md:74` (RULE-02): all displays two-operative regardless of panel size;
  never four-operative; never conditional on screen size.
- `house-rules.md:8-11` is its source, and `ROADMAP.md:13` declares the skill the winner
  in any app-vs-skill disagreement.
- A size ladder is the exact defect the 21CQ30960 professional review raised — it is why
  RULE-02 was written.
- One-man 55-inch would be a new position, not a restoration: `SafetyProfileService`
  currently treats 55 inches as the threshold where two-person handling *starts*.
- Adopting the ladder would require rewriting RULE-02, GATE-09 and ROADMAP success
  criteria 1 and 3.

| Option | Description | Selected |
|--------|-------------|----------|
| Keep RULE-02 as-is | Two-operative for every display regardless of size; skill wins; ROADMAP/GATE-09 unchanged | |
| My ladder overrides the skill | 1 operative ≤55, 2 at 65-85, 3 above 90; full requirement rewrite; 56-64 and 86-90 gaps need filling | |
| Two-operative floor, ladder above | Never fewer than 2 (no one-man display lift), 3 permitted above 90; narrow RULE-02 amendment; GATE-09 errors on 1 or 4 operatives | ✓ |
| Park it — check with Ali first | Capture both positions as an open blocker; planning waits until settled off-line | |

**User's choice:** Two-operative floor, ladder above.
**Notes:** The one-man band was dropped. RULE-02's four-operative ban and its
"mechanical aids are additional, never a substitute" clause survive; only the
"regardless of panel size" clause is amended. The 56-64 and 86-90 gaps in the originally
typed ladder are closed by the floor — everything to 90 inches inclusive is 2 operatives.

---

## Lift text — Q1: is the third operative required or an allowance above 90 inches?

| Option | Description | Selected |
|--------|-------------|----------|
| Required — 3 minimum above 90 | Text reads as a minimum team size; GATE-09 errors on a 2-operative lift for a panel above 90; one number per band, deterministic to enforce | ✓ |
| Allowance — 2 minimum, 3 where practicable | Floor stays 2 everywhere; above 90 the text suggests a third operative or trolley where warranted; gate errors only below 2 or above 3 | |
| Required, with a stated aid alternative | 3 minimum above 90, satisfiable by 2 operatives plus a panel-lift trolley; gate errors on 2 with no aid named | |

**User's choice:** Required — 3 minimum above 90 inches.
**Notes:** By rejecting the aid-satisfiable variant, the user kept mechanical aids as
strictly additional — a trolley never discharges the third operative. The existing
`>=65in` wording *"Two persons may lift if using a panel-lift trolley"*
(`RamsComplianceUpgradeService.php:1261-1262`) is an aid-as-substitute construction;
harmless at 65-90 where the floor is 2 anyway, but it must not be carried above 90.

---

## Lift text — Q2: where do the size bands live?

Context given: the wording must hold in three independent places — the Manual handling
hazard control text (DB library), `suggestHandlingMethod()` (§6.7 per-item table), and a
hardcoded string at `MethodStatementService.php:461`.

| Option | Description | Selected |
|--------|-------------|----------|
| One PHP class, all three read it | `DisplayLiftPolicy` owns bands and sentence; generator, method step, seeder and GATE-09 all call it so gate and generator cannot drift; mirrors `LegacyHazardNameFoldMap` from Phase 26 | ✓ |
| Config file (`config/rams_tier1.php`) | Tunable without deploy, but partially reverses Phase 26's deliberate move of hazard content out of that file | |
| DB hazard library only | Bands in the Manual handling control text; §6.7 and the method step cite the hazard — but §6.7 needs per-item output, not one paragraph | |
| You decide | Planner picks, constrained to a single shared source for gate and generator | |

**User's choice:** One PHP class, all three read it.

---

## Lift text — Q3: does the worksheet document come in scope?

Context given: `SafetyProfileService::LARGE_DISPLAY_INCHES = 55` fires a
"minimum 2-person lift" warning only at 55 inches and above, so a 43-inch display gets no
lift warning at all, and it never mentions a 3-operative band.

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — point it at the same class | Drop the 55 constant, call the shared class; every display gets a warning; above 90 says 3; two 21CAV documents cannot state different team sizes | ✓ |
| No — RAMS only this phase | Phase 27's requirements and GATE-09 all say "RAMS"; leave the constant and defer the divergence | |
| Yes, but warning-only | Wire the numbers, but keep GATE-09 RAMS-scoped | |

**User's choice:** Yes — point it at the same class.
**Notes:** The gate's own scope was not extended by this answer; whether GATE-09 polices
worksheet output is left to the planner.

---

## Lift text — Q4: what happens when the display's size cannot be determined?

Context given: the inch-parsing regex at `RamsComplianceUpgradeService.php:1237-1240`
returns null for "Samsung QM98 commercial display" or a bare part number, and a null size
also blinds GATE-09's band conformance check.

| Option | Description | Selected |
|--------|-------------|----------|
| Default to 2 and flag for confirmation | Takes the floor but marked `needs_confirmation`, reusing Phase 26's D-06 mechanism and its existing review-screen treatment | |
| Default to 2 silently | Current behaviour with the ladder above it; a 98-inch panel with an unparseable description quietly ships a 2-operative instruction | ✓ |
| GATE-09 errors on unknown size | Strictest; risks blocking real jobs on extractor data quality rather than a safety defect | |
| Use `weight_kg` when inches are absent | Closest to RULE-12's derive-from-weight clause; needs a weight-to-band mapping and coverage is unverified | |

**User's choice:** Default to 2 silently.
**Notes:** Recorded in CONTEXT.md D-05 as an accepted residual risk, with the three
declined alternatives named so a later agent does not re-engineer it unasked.

---

## Wrap-up

| Option | Description | Selected |
|--------|-------------|----------|
| Cover the remaining three | Discuss RULE-12, RULE-03 and GATE-09 | |
| Just GATE-09 | Establishes the mechanism every later gate reuses | |
| More questions on lift text | Keep going on the display-lift position | |
| Write CONTEXT.md now | Remaining three go in as Claude's discretion with constraints | ✓ |

**User's choice:** Write CONTEXT.md now.

## Claude's Discretion

- **RULE-12** — mount/bracket rows inheriting display text. Root cause located (branch
  ordering at `:1254` vs `:1270`). Constrained: must not silently narrow RULE-12 to the
  ordering fix; non-display items follow `house-rules.md:18-19`, not D-01's floor.
- **RULE-03** — wall-mount removal statement placement and its deterministic strip-out
  trigger. Constrained by `house-rules.md:13-16` and by `CLAUDE.md:12` (no AI decides
  scope).
- **GATE-09** — enforcement mechanism, timing, and surface. No gate framework exists;
  GATE-09 establishes what six later gates reuse. Constrained to read D-03's shared class.
- **Reversibility** — not raised by the user; carried forward from Phase 26 as a standing
  project condition, with a strong steer toward an env flag on GATE-09's erroring
  behaviour.

## Deferred Ideas

- Weight-driven manual handling (RULE-12's full derive-from-weight clause) — blocked on
  unverified `weight_kg` coverage.
- Flagging unresolvable display sizes for confirmation — declined in Q4, revisit only on
  live evidence.
- Extending GATE-09 to worksheet output.
- A general gate framework for the remaining six gates.
- Structured job-scope capture (strip-out flag, building age, operative count) — carried
  forward unresolved from Phase 26.

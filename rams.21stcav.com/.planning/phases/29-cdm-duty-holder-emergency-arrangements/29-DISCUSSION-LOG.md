# Phase 29: CDM Duty-Holder & Emergency Arrangements - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-09-11
**Phase:** 29-cdm-duty-holder-emergency-arrangements
**Areas discussed:** Fix site + the 54 live documents; A&E default vs house rule

---

## Area selection

| Option | Description | Selected |
|--------|-------------|----------|
| A&E default vs house rule | The house-rules.md conflict with ROADMAP criterion 2 | ✓ (selected later, in the second pass) |
| How GATE-12 proves "real A&E" | Curated list vs plausibility check vs narrow scope | ✓ (resolved as a consequence of D-05/D-07) |
| CDM wording + who can override | Verbatim skill wording, conditionality, review-form override | (left to planner as constrained discretion) |
| Fix site + the 54 live documents | Render layer vs compose layer; backfill of persisted rows | ✓ |

**Notes:** The A&E conflict was raised by Claude before selection, as a concern rather than
a question — the roadmap says name an A&E by default, `house-rules.md` forbids naming an
unverified one. The user initially selected only the fix-site area, then chose to settle the
A&E conflict after Claude flagged that leaving it open risked shipping the exact defect
GATE-12 exists to catch.

---

## Fix site + the 54 live documents

### Q1 — Where should the A&E fix land?

| Option | Description | Selected |
|--------|-------------|----------|
| Make it data-driven + fix all 3 | Kill the literal in all three templates, read `site_emergency.nearest_hospital` through one helper, AND add it to the composer layer. Correct regardless of `RAMS_UNIFIED_COMPOSER`. | ✓ |
| Composer layer only | Fix `WelfareComposer`/`EmergencyComposer` only. Clean, matches architecture intent — but changes nothing live unless the flag is on, and config defaults it false. | |
| Legacy templates only | Fix the three literals, skip the composer. Smallest diff, reaches today's output — leaves a latent defect for the day the flag flips. | |
| You decide | Constrained to: correct under both flag settings; verify prod's actual setting first. | |

**User's choice:** Make it data-driven + fix all 3 (→ D-01)
**Notes:** Chosen on reintroduction risk — this milestone has been reopened by
reintroduction twice already.

### Q2 — How should live production rows be handled?

| Option | Description | Selected |
|--------|-------------|----------|
| Measure-first + idempotent backfill | Phase 28-07 shape: count affected production rows at a checkpoint, then an idempotent migration patching only rows still reading the placeholder. | ✓ |
| Gate-driven repair on next regeneration | No migration; GATE-11 throws and forces a human fix. Rejected in Phase 28 for making existing documents un-regenerable until hand-edited. | |
| Auto-correct at patch time, no migration | `RamsDisplayPatchService` overwrites placeholders on every patch. Self-healing, but the defect stays in the database. | |
| You decide | Constrained to: break the `:429` carry-forward; never overwrite an engineer's hand-entered value. | |

**User's choice:** Measure-first + idempotent backfill (→ D-02)
**Notes:** Context for the question was Claude's finding that `reviewed_data['cdm']`
outranks the `cdm_duty_holders` default in the blade merge (`rams.blade.php:1824`), so a
code-only fix would not reach existing documents — the Phase 27 Blocker-1 shape.

### Q3 — What should GATE-11's kill-switch default to?

| Option | Description | Selected |
|--------|-------------|----------|
| Ships disarmed, armed after backfill verifies | Flag defaults false; deploy → backfill → verify live regeneration → flip true in `.env` separately. | ✓ |
| Ships armed, backfill migrates first | Matches Phase 27/28 gates; atomic deploy, no escape window — but no soft landing if the migration misses a row shape. | |
| Warn-only first, escalate later | Records violations without throwing for one cycle. Safest for users, but the roadmap says GATE-11 "errors", and Phase 28 found warn-only defects get ignored. | |
| You decide | Constrained to: dedicated env flag, never shared with GATE-09 or GATE-06/07. | |

**User's choice:** Ships disarmed, armed after backfill verifies (→ D-03)
**Notes:** Directly applies the Phase 28 lesson that a content gate defaulting ON is a
deploy-order trap.

### Q4 — What happens to the CDM carry-forward?

| Option | Description | Selected |
|--------|-------------|----------|
| Carry only non-placeholder values | Keep the carry-forward but skip any field still reading `[To be confirmed]`. Kills the reseed loop, loses nothing an engineer typed. | ✓ |
| Leave carry-forward alone | Relies on the backfill being complete forever; any future placeholder starts propagating again. | |
| Carry-forward also validated by the gate | Catch at generation rather than prevent at copy. Converts a silent data issue into a thrown error on a document the engineer didn't author. | |
| You decide | Constrained to: a placeholder must not propagate between documents. | |

**User's choice:** Carry only non-placeholder values (→ D-04)

---

## A&E default vs house rule

### Q1 — What prints when no verified 24/7 A&E is recorded?

| Option | Description | Selected |
|--------|-------------|----------|
| House-rule hold-point wording | The skill's verbatim line, rendered as a hold point. Never asserts an unverified hospital. Requires restating ROADMAP criterion 2. | ✓ |
| Engineer must enter one — blank blocks | Review form requires a named A&E; GATE-12 throws on blank. Matches the roadmap, but adds friction and invites guessing — the failure mode behind the 2014-closed-A&E incident. | |
| Hold-point now, blocking once armed | Two-stage: honest wording live now, escalate later. Still needs the criterion restated as an interim position. | |
| You decide | Constrained to: never assert an unverified 24/7 ED. | |

**User's choice:** House-rule hold-point wording (→ D-05)

### Q2 — What should RULE-08 become?

| Option | Description | Selected |
|--------|-------------|----------|
| Two-branch restatement | Verified → name + full address + postcode + route/travel time at induction. Not verified → hold point + 24/7 ED requirement. Banned string never appears in either branch. | ✓ |
| Ban the string only | Simplest to verify, but drops the positive requirement — an unverified hospital would pass RULE-08. | |
| Leave RULE-08, note the exception | Least churn, but leaves REQUIREMENTS.md asserting something the implementation deliberately doesn't do — the drift that made RULE-07 dangerous. | |

**User's choice:** Two-branch restatement (→ D-06)

### Q3 — How do the Welfare bullet and section 7.0 relate?

| Option | Description | Selected |
|--------|-------------|----------|
| 7.0 is the single source; welfare defers | Welfare bullet drops the A&E sentence, points to Section 7.0. Makes contradiction structurally impossible. | ✓ |
| Both render, one shared helper | Keeps A&E in both places for reader convenience; two render sites to keep correct in every template. | |
| Welfare drops it, 7.0 gains the hold point | Same as option 1 without the cross-reference line. | |
| You decide | Constrained to: no two A&E positions in one document; `'TBC'` fallback removed either way. | |

**User's choice:** 7.0 is the single source; welfare defers (→ D-07)
**Notes:** Claude's scout found the two sites can already contradict each other today — 7.0
can show a named hospital while the welfare bullet says it is still to be identified.

### Q4 — What should GATE-12 error on?

| Option | Description | Selected |
|--------|-------------|----------|
| Plausibility check, no dataset | Errors on the banned string; on urgent-care/MIU/walk-in/UTC keywords in a named value; on a named value with no address or postcode. Hold point passes clean. | ✓ |
| Curated static list of verified EDs | Highest confidence, would have caught the 2014 incident directly — but an unowned maintained dataset that false-positives in new regions. | |
| Banned-string check only | Trivially reliable, zero false positives — but does not check a named A&E is real, which is GATE-12's whole purpose. | |
| You decide | Constrained to: hold-point line passes clean; unclassifiable values are CLEAN. | |

**User's choice:** Plausibility check, no dataset (→ D-08)
**Notes:** This closes the scoping call ROADMAP criterion 3 explicitly required be made
during planning.

---

## Claude's Discretion

- **CDM duty-holder wording (RULE-07)** — the user chose to send this to the planner rather
  than discuss it, on the basis that `standards-and-legislation.md:23-41` prescribes the
  sentence, the conditional Contractor row, the Client-row rule and the four forbidden
  statements almost verbatim. Three sub-choices remain genuinely open and are listed in
  CONTEXT.md: unconditional vs occupied-premises-only application, whether engineers get a
  review-form override, and full vs short-form sentence in the PD/PC rows.
- Test-fixture regeneration strategy for the four `tilda-21cq29531` expected-output files.
- Whether GATE-11/GATE-12 share one detector class or take separate `DETECTORS` entries.

## Deferred Ideas

- Site-level A&E / asbestos-register / access / welfare inheritance — out of scope for
  v3.1; would give GATE-12 a maintained record to verify against.
- Curated static list of verified 24/7 EDs — declined for D-08; revisit if site-level
  inheritance lands.
- Escalating GATE-12 to block generation on a blank A&E — declined for now; viable once the
  corpus is clean and a site-level A&E source exists.

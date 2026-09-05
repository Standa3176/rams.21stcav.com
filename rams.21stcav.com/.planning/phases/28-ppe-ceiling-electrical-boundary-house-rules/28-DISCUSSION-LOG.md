# Phase 28: PPE, Ceiling & Electrical Boundary House Rules - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-09-05
**Phase:** 28-ppe-ceiling-electrical-boundary-house-rules
**Areas discussed:** GATE-07 false positives (which broadened to cover GATE-06/07 mechanics generally)

---

## Preamble — wrong-repo catch

The command was issued as `/gsd-discuss-phase 28` from the **GSD Dashboard** working
directory, which has no Phase 28 (its roadmap tops out at Phase 16 plus backlog 999.1).
Two other projects on the machine do: RAMS Phase 28 (open, follows a completed Phase 27)
and Service Contractor Creator Phase 28 (already complete). The user confirmed **RAMS**.
Recorded because the GSD SDK resolves `.planning/` from the current working directory —
writing this artifact from the wrong cwd would have put it in the wrong repo silently.

---

## Area selection

| Option | Description | Selected |
|--------|-------------|----------|
| GATE-07 false positives | A naive "confined space" substring check errors on the app's OWN corrected text (`HazardTemplateSeeder.php:220`) and on `review.blade.php:264`'s legitimate permit dropdown | ✓ |
| Canonical hazard title | ROADMAP/skill say "Restricted access and ceiling void working"; Phase 26 shipped "Restricted access and ceiling voids" | |
| Where RULE-09/10 land | RULE-10's sentence exists but is include-when gated; RULE-09 has no home. And what triggers them, given DATA-01 | |
| Scope breadth: RULE-09 + RULE-11 | How much of house-rules §Electrical ships; RULE-11 assigned to Phase 28 in REQUIREMENTS.md but in no success criterion | |

**User's choice:** GATE-07 false positives only.
**Notes:** The three unselected areas were recorded in CONTEXT.md as constrained
discretion (D-05, D-06, D-07/D-08) rather than dropped. The RULE-11 half of the fourth
area was escalated to **D-09, an open question**, because it is a document-vs-document
contradiction the planner cannot resolve alone.

---

## GATE-07 false positives — Q1: negating mentions

| Option | Description | Selected |
|--------|-------------|----------|
| Negation-aware detector | Flag only affirmative labelling — "is a confined space", "confined space entry/permit", a hazard NAME containing it, or an ACOP L101 citation. Treat "not classified as confined spaces" as clean. Matches the conservative-by-construction rule | ✓ |
| Rewrite the seeder line, then ban the phrase | Reword the library control to avoid the words entirely, so the gate can be a blunt substring check. Simplest, but changes shipped safety text | |
| Allowlist the known-good lines | Blunt check plus an exemption list. Cheapest, but brittle — a one-word seeder edit re-arms the false positive | |
| You decide | Discretion, constrained to never flagging the seeder's own text | |

**User's choice:** Negation-aware detector.
**Notes:** Grounded in `HazardTemplateSeeder.php:220`, whose corrected control reads
*"These are not classified as confined spaces, but access is restricted…"* — the fix, not
the defect. Phase 27 already made "never flags the app's own corrected library text" a
load-bearing acceptance criterion for `ControlTextRuleViolations`; that criterion carries
forward to both new detectors.

---

## GATE-07 false positives — Q2: the permit-type picker

| Option | Description | Selected |
|--------|-------------|----------|
| Out of scope — document text only | The gate scans generated content, not UI vocabulary. 'Confined Space' sits alongside Hot Works, PASMA, IPAF as a generic permit category; ticking it is an engineer's deliberate act | ✓ |
| In scope, but only on ceiling-void work | Error when a Confined Space permit is attached to a job whose only restricted-access scope is a ceiling void. Needs a scope signal | |
| Remove the option entirely | Drop it from the list and police the string everywhere. Cleanest gate, but removes the ability to record a genuine client-imposed permit | |
| You decide | Discretion | |

**User's choice:** Out of scope — document text only.
**Notes:** Option 2 was declined partly because DATA-01 proved live scope signals can be
empty. This creates a deliberate asymmetry with Q3/Q4: the *permit* picker is left alone,
but the *PPE* pick-lists at `ProjectPackageReviewController.php:33` and
`RamsReviewController.php:42` are changed — because `'Dust Mask (FFP2)'` becomes document
content verbatim and is unconditionally wrong, whereas a permit category is not.

---

## GATE-07 false positives — Q3: what happens on a hit

| Option | Description | Selected |
|--------|-------------|----------|
| Both, split by source | New `ffp2`/`confined_space` DETECTORS entries auto-correct library-owned control lines (tier 1, the Blocker-1 path that fixed 438 rows), AND an independent re-check throws on anything surviving into the final payload. How Phase 27 shipped RULE-13 + GATE-09 | ✓ |
| Throw only | No auto-correction; any occurrence blocks generation until hand-fixed. Loudest, but some of the 60 existing documents would become un-regenerable | |
| Auto-correct only | Silent replacement, never blocks. Contradicts "GATE-06 errors on any FFP2 occurrence" and never surfaces engineer-typed defects | |
| You decide | Discretion, constrained to the single choke point | |

**User's choice:** Both, split by source.
**Notes:** `ControlTextRuleViolations`' own docblock (`:19-25`) already names this phase
as the intended extension — *"Phase 28 adds `ffp2` / `confined_space` … each is a new
`DETECTORS` entry plus its own `detectXxx()` method. `reviewedToRisk()` calls only
`detectAll()`; it never changes when a detector is added here."* The extension point was
built for this work.

---

## GATE-07 false positives — Q4: how wide GATE-06 reaches

| Option | Description | Selected |
|--------|-------------|----------|
| Runtime + repo-wide static test | Gate the payload at runtime AND fail a test on FFP2 in any non-backup source file. Fix all 14 live sites incl. the `RiskMatrixService:133` "FFP2 or FFP3" hedge and both PPE pick-lists | ✓ |
| Runtime output only | Fix the 14 sites, gate the payload, no source test. Cost: nothing stops a 15th site on a code path the proof job never exercises | |
| Runtime + static test, and clean the dead files too | Also purge FFP2 from `views.backup-260430/` and the two "keep borders" blades so no exclusion list is needed | |
| You decide | Discretion, constrained to the DOCX path and both entry points | |

**User's choice:** Runtime + repo-wide static test (backups left untouched).
**Notes:** The stated reason for going broad here — against the caution shown in Q1–Q3 —
is that this milestone has been reopened repeatedly by reintroduction. Breadth at build
time, caution at runtime. The five backup-file occurrences stay, so the scan carries a
narrow commented exclusion list.

---

## Wrap-up

| Option | Description | Selected |
|--------|-------------|----------|
| Next — write the context | Four decisions captured; unselected areas become constrained discretion | ✓ |
| More questions on gates | Env flag, blocking vs banner, backfill migration for the 60 existing documents | |
| Discuss one of the other three areas | Go back to hazard title / statement placement / scope breadth | |

**User's choice:** Next — write the context.
**Notes:** The env-flag, surfacing and backfill questions were offered and passed over;
they are recorded as **D-08** with the Phase 27 precedents attached rather than left blank.

---

## Claude's Discretion

- **D-05** — RULE-06's canonical hazard title (shipped "…ceiling voids" vs the skill's
  "…ceiling void working"). Constraint: all four surfaces must agree; state which was
  chosen and why; never ship a third spelling.
- **D-06** — where RULE-09/RULE-10 statements land and what triggers them. Constraint:
  verify any scope signal is non-empty on 21CQ30960 first (the DATA-01 trap); no AI
  decides applicability.
- **D-07** — how much of `house-rules.md` §"Electrical scope boundary" ships beyond the
  one-line requirement. Constraint: if narrowed, say so explicitly (Phase 27 RULE-12
  precedent).
- **D-08** — gate env flag, surfacing mechanism, and whether a backfill migration is
  needed. Constraint: own flag, not a reuse of `RAMS_DISPLAY_LIFT_GATE`; measure the
  existing `reviewed_data` before deciding on a migration.

**Not discretion — genuinely open:** D-09, whether RULE-11 (fire-stopping) is in this
phase. `REQUIREMENTS.md:178` said yes; no ROADMAP success criterion mentioned it.

### D-09 — resolved same day (2026-09-05)

| Option | Description | Selected |
|--------|-------------|----------|
| Add a fifth Phase 28 success criterion and build RULE-11 here | Keeps the requirements line honest; grows Phase 28 by a fifth deliverable spanning exclusions, hazard register and QA | |
| Move RULE-11 out and fix the requirements table | Keeps Phase 28 to five requirements and two gates; RULE-11 gets a phase where its defect actually lives | ✓ |

**User's choice:** *"move RULE-11 out and fix the requirements table."*
**Destination:** Phase 31 (Standards/COSHH Scoping & Padding Gates) — chosen on evidence,
not preference: Phase 31's success criterion 2 *already named the expanding-foam COSHH
entry* before this move, and RULE-05/GATE-10 own that table.
**Notes:** RULE-11 was **not** allowed to land in Phase 31 the same way it sat in
Phase 28 — a new success criterion 5 was written for it, since a requirement with no
criterion is precisely what created D-09. Phase 31's requirements line also carries a
warning that RULE-11 is wider than a COSHH scoping fix. One consequence recorded for
Phase 28 planning: `config/rams_tier1.php:286` is now touched by both phases, and
Phase 28 must edit it for FFP3 content only, leaving the fire-stop claim for Phase 31.

## Deferred Ideas

- GATE-17 (RCS WEL-not-EAV, no asbestos-awareness in the RCS control, real RIDDOR number)
  — same subject as RULE-01, but a later phase.
- BS 7671 / lock-off / test-dead wording — carries an unresolved user decision.
- "First-fix power" → "first-fix AV signal/data/ELV cabling".
- DATA-01 (`scope_items.decommission` never populated) — belongs with quote import.
- A general gate framework for GATE-11/12/13/14.
- Extending GATE-06/07 to worksheet output.

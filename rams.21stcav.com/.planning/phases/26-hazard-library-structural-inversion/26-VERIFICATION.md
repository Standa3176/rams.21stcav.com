---
phase: 26
slug: hazard-library-structural-inversion
status: gaps_found
verified: 2026-08-24
method: live production verification against real project data
---

# Phase 26 — Verification

**Verdict: GAPS FOUND.** Three of four requirements verified on live. HAZ-02 is
satisfied on one generation path and not on another; it has been reopened in
`REQUIREMENTS.md`.

Verification was performed on the live server (`rams.21stcav.com`) against real
production data, per the phase's own Success Criterion 4 and the standing decision
that RAMS changes are validated on live rather than in a local/staging environment.

---

## Deploy

Deployed 2026-08-24 as `stcav` (not root) to
`/home/stcav/rams.21stcav.com.git/rams.21stcav.com/`. Commits `83a7538..8cb9907`
pushed to both `rams-live` and `origin`.

```
php artisan migrate --force      → 2026_08_23_160000_add_include_when_to_hazard_templates  DONE
php artisan db:seed --class=HazardTemplateSeeder --force
                                 → "18 standard hazards seeded, 11 superseded global row(s) removed."
php artisan optimize:clear       → DONE
```

**On the 11 removals.** Live held the 13 old seeded global hazards. Two match a skill
hazard by name case-insensitively (`Manual Handling` → *Manual handling*,
`Working at Height` → *Working at height*) and were updated in place; the other 11
were removed. 13 − 2 = 11. Consistent with D-03. Post-deploy counts confirmed
`global: 18 | user: 0` — there were no engineer-created custom hazards on live, so
the removals could only have touched the old seeded set. Nothing was lost.

---

## Verified on live ✅

| Req | Evidence |
|-----|----------|
| **HAZ-01** | `HazardTemplate::where('is_global',true)->count()` = **18** on production |
| **HAZ-03** | Working at height on live reads `3x4 -> 1x4`. The old baseline was `2×3`. This is the named checkable proof, confirmed against production data, not a fixture |
| **HAZ-04** | Editable numeric L×S + `score_reviewed` marker shipped (Plan 26-05). Note the review-screen UI itself was not visually confirmed during this pass — see Outstanding below |

Local suite at time of deploy: **2265 passed**, 1 pre-existing unrelated
`QueueRecoverCommandTest` memory flake (untouched).

---

## Gap found ❌ — HAZ-02 holds on only one of two generation paths

`RamsBuilderService` has two generation entry points. Plan 26-04 wired one.

| Path | Hazard resolution | Tiered include-when? |
|---|---|---|
| `runPipeline()` (`:707`) → `riskResolver->resolve()` (`:732`) | `RiskTemplateResolverService` | ✅ wired by 26-04 |
| `runFromReview()` (`:131`) → `reviewedToRisk()` (`:136`) | per-name `resolveFromSeeds()` | ❌ never wired |

`reviewedToRisk()` maps reviewed hazards 1:1 and calls `resolveFromSeeds()` per name
solely to fill a missing score or empty control list. It never calls
`HazardIncludeWhenResolver`. **Any project whose quote package has already been
reviewed takes this path — which is most real projects.**

### Live evidence

A genuinely fresh generation of 21CQ30960 (project 92 → RAMS id **96**; RAMS 95 has
`superseded_by=null`, confirming 96 was not produced by the regenerate button, which
copies `reviewed_data` forward and would have been an invalid test):

- `96 status=completed reviewed=7 generated=11`
- Package `extracted_data['hazards']` holds **7** entries saved during the August
  review, in the old vocabulary — `Working at Height`, `Manual Handling`,
  `Electrical Hazards`, `Slips, Trips & Falls (Same Level)`, `Noise and Vibration`, …
  (these 7 are exactly the retired `MANDATORY_KEYWORDS` list)
- Rendered register: **11 hazards, all old vocabulary**, including **"Confined
  Spaces"** — the exact mislabel `house-rules.md` RULE-06 and GATE-07 target, which
  the new library replaces with *Restricted access and ceiling void working*
- **Absent entirely:** all 4 always-tier hazards (Slips/trips/falls, Low voltage AV
  connections, Fire and evacuation, COSHH substances) and all 5 confirm-tier hazards
  (Asbestos, Vehicle and plant movement, Lone and small-team working, Occupational
  road risk, Occupied premises). These are supposed to appear on every job
- Uniform 9/4 scoring across most rows — the GATE-05 "assembled from the library,
  not the job" signature

### Why the test suite could not have caught it

All 2265 tests exercised `HazardIncludeWhenResolver` directly or generated through
`runPipeline`. None generated through `runFromReview` with a pre-populated reviewed
hazard list. A synthetic fixture starts with empty reviewed data and passes forever.
This gap was reachable only by generating from a real project with real review
history — which is what the live-validation approach was for.

### Why the plan-check did not catch it

Plan 26-04 was written and verified against "both live call sites", and it wired
both call sites it named. `runFromReview()` appeared in no artifact — not RESEARCH.md's
injection-path map, not PATTERNS.md, not the orchestrator's scout, not the plan-checker's
two passes. The five-path injection map was correct about *removal* and incomplete about
*population*.

### Not a regression

The 21CQ30960 output is materially what it would have been before Phase 26. Live is
not degraded and there is nothing to roll back. `RAMS_HAZARD_LIBRARY_TIERING=false`
would not change this path, because the path never reaches the flag.

---

## Outstanding

1. **Gap closure Plan 26-07** — wire tiered resolution into `runFromReview()`, merging
   tier-1/tier-3 hazards on top of reviewed picks rather than replacing them (engineer
   values must still win on scores and controls, per the `reviewed_data` precedence
   rule). Add a generation test through `runFromReview` with populated reviewed
   hazards — the coverage hole.
2. **Unexplained 7 → 11 delta.** `reviewedToRisk()` maps 1:1, so 7 reviewed hazards
   should yield 7 generated. `generated_data` holds 11. Something downstream adds 4.
   Trace before or during 26-07 — if a surviving injection path is responsible, the
   five-path removal map is also incomplete.
3. **Review-screen UI unconfirmed.** `/rams/96/quote-review` was never opened during
   this pass — the screenshot taken was `/rams/96/review`, the read-only
   post-generation diff view. HAZ-04's numeric inputs and the needs-confirmation badge
   have not been visually verified on live.
4. **Rollback proof not yet run.** Toggling `RAMS_HAZARD_LIBRARY_TIERING=false` and
   confirming it degrades to explicit picks without resurrecting the old 11 remains
   outstanding. Worth doing after 26-07, when the flag actually governs the path most
   projects take.
5. **RAMS 96 is a test artifact** on production for project 92. Supersede or delete it
   when convenient.

---

*Verified 2026-08-24 against live production data.*

---

# Round 2 — after Plan 26-07 (2026-08-24)

**Verdict: GAPS FOUND again.** HAZ-02 and HAZ-03 reopened. Verified on live against
project 92 / RAMS **97** (fresh generation via `generateFromProject`, `reviewed=7
generated=21`), DOCX/PDF output inspected end to end.

## Confirmed working

- Tiered resolution now fires on the `runFromReview()` path — 14 library hazards were
  added on top of the 7 reviewed picks. The 26-07 fix works.
- Scoring on the new rows is job-shaped, not uniform: Fixings `3x4 -> 1x4`, Asbestos
  `2x5 -> 1x5`, Vehicle and plant `3x5 -> 1x4`, Cable pulling `3x2 -> 1x1`.
- Five tier-3 rows carry `needs_confirmation`: Occupied premises, Asbestos-containing
  materials, Vehicle and plant movement, Lone and small-team working, Occupational road
  risk. Exactly the designed behaviour.
- `Decommissioning and WEEE` present — correct for a strip-out job.
- `Restricted access and ceiling voids` present with correct wording and the explicit
  "these are not classified as confined spaces" control.
- The sixth injection path is gated — Cable pulling and Fixings now arrive on merit.

## Gap 1 — legacy reviewed names collide instead of deduping (HAZ-02)

Dedup matches near-exact names only. Reworded legacy names produce duplicate rows:

| Legacy row | Library row | Result |
|---|---|---|
| RA04 `Slips, Trips & Falls (Same Level)` | RA08 `Slips, trips and falls` | duplicate |
| RA06 `Working in Occupied Premises` | RA09 `Occupied premises` | duplicate |
| RA07 `Confined Spaces` | RA10 `Restricted access and ceiling voids` | duplicate |

`Working at Height`, `Manual Handling` and `Noise and Vibration` deduped correctly.
`Electrical` is absent from the output entirely — cause not yet established; either it
deduped against `Electrical Hazards` or its tier-2 signal did not fire. **Establish which
before assuming.**

**Severity: this ships `Confined Spaces` in a client-facing safety document** — the exact
mislabel `house-rules.md` records as a known failure mode, two rows above its own
correction. RULE-06 / GATE-07 land in Phase 28, but Phase 26 is what puts the row there.

**Cause:** D-02's fold mapping (old app names → nearest skill hazard) was applied to the
seeder but never to reviewed-data passthrough.

## Gap 2 — legacy scores defeat HAZ-03

RA01 Working at Height renders **`3x3 -> 2x2`**, not the required `1x4`. The stale
reviewed row wins over the library default.

Reviewed-beats-library is correct in principle, but this project's `reviewed_data` is not
engineer judgement — it is old `MANDATORY_KEYWORDS` baseline output saved into the project
package in August. The precedence rule is faithfully preserving the padding the milestone
exists to remove. The first eight rows are all `3x3 -> 2x2`, still the GATE-05
uniform-scoring signature.

`score_reviewed` (shipped in 26-05) is the discriminator: where it is false, no human ever
touched the score and the library default should win.

## Not regressions — other phases' scope, confirmed still outstanding

Observed in the same document; do NOT fix in Phase 26:
- `Dust mask (FFP2)` in the PPE table (p18) — RULE-01 / GATE-06, Phase 28
- RA02 `Team lift required for screens and equipment over 40" — minimum two persons` —
  the size-conditional threshold RULE-02 bans, Phase 27
- Material handling table `minimum 3 persons recommended for 65"` — contradicts RULE-02's
  two-operative position, Phase 27
- CDM duty-holder rows all blank — RULE-07 / GATE-11, Phase 29
- `Nearest hospital A&E to be identified at site induction` — RULE-08 / GATE-12, Phase 29
- Standards table cites BS EN 60849, BS 8492, HSG 47, laser safety on a job with no laser
  or PA/VA scope — RULE-04 / GATE-10, Phase 31

## Outstanding

1. **Plan 26-08** — apply D-02's fold mapping to reviewed-hazard names before dedup; add
   the `score_reviewed=false ⇒ library default wins` rule; establish why `Electrical` is
   absent; add live-shaped test fixtures using the real legacy vocabulary.
2. Re-verify on live against 21CQ30960 afterwards.
3. RAMS 96 and 97 are test artefacts on production for project 92 — supersede or delete.
4. Rollback proof still not run.
5. Review-screen UI (`/rams/{id}/quote-review`) still not visually confirmed on live.

*Verified 2026-08-24 against live production data (RAMS 97).*

---

# Round 3 — after Plan 26-08 (2026-08-25)

**Verdict: HAZ-01..04 ALL VERIFIED ON LIVE.** Two operational items remain open (below);
the four requirements themselves are closed on production evidence.

Verified against project 92 / RAMS **98** — a fresh `generateFromProject` run through the
deployed 26-08 code. `reviewed=7 generated=18`.

## Every round-2 defect closed

| Check | Round 2 (RAMS 97) | Round 3 (RAMS 98) |
|---|---|---|
| Row count | 21 | **18** |
| `Confined Spaces` | present, client-facing | **gone** — folded to *Restricted access and ceiling voids* |
| Slips duplicate pair | 2 rows | merged |
| Occupied premises duplicate pair | 2 rows | merged |
| Working at height | `3x3 -> 2x2` | **`3x4 -> 1x4`** |
| `Electrical Hazards` | absent from output | **folded to `Electrical` `2x5 -> 1x4`** |
| Vocabulary | mixed legacy + library | **all canonical library** |
| Uniform `3x3 -> 2x2` block | 8 rows | gone |

Scores now match `hazard-library.md` exactly — *Manual handling* `4x3 -> 2x3`,
*Slips, trips and falls* `3x3 -> 2x2*, *Fixings* `3x4 -> 1x4`, *Asbestos* `2x5 -> 1x5`.
Five tier-3 rows carry `needs_confirmation`: Occupied premises, Asbestos-containing
materials, Vehicle and plant movement, Lone and small-team working, Occupational road risk.

## The `Electrical` anomaly — resolved, benign

Round 2 recorded `Electrical` as absent with an unexplained cause, and the plan-checker
flagged a possible silent data-loss path. **It was not data loss.** `Electrical Hazards`
was present throughout (it renders as row 3 of RAMS 97) and now folds correctly to
`Electrical`. The round-2 note derived from a misreading of the RAMS 96 output. No
data-loss path exists; no further investigation needed.

## Open observation — 18 of 18 is not yet proof of discrimination

RAMS 98 contains **the entire 18-hazard library**. For this job that is defensible: a
multi-room strip-out and install, occupied automotive premises, ceiling-mounted mics,
mains isolation and disconnection, drilling and fixings, a commercial-vehicle area, and a
~60-mile trip from Reading. Every row can be justified from the scope.

But a register equal to the whole library is precisely the shape GATE-05 exists to warn
about. If **every** job renders 18/18, the inversion has not achieved discrimination — it
is a longer route to the same full register, and `PORTING-NOTES.md`'s "empty register the
user adds to" is satisfied only in mechanism, not in outcome.

**Not a Phase 26 defect on this evidence, but it must be tested before the milestone is
called done:** generate a RAMS for a simple single-room display swap and confirm the row
count is materially lower. If it is not, the tier-2 signal vocabulary is matching too
broadly and needs narrowing.

## Still open (operational, not requirement gaps)

1. **Rollback proof (26-06 Task 3).** `RAMS_HAZARD_LIBRARY_TIERING=false` +
   `php artisan config:clear` + regenerate; confirm the register degrades to reviewed/
   manual picks only and does **not** resurrect the old 11-hazard baseline; restore the
   flag. This is the live safety net and has never been exercised.
2. **Review-screen UI unconfirmed.** `/rams/{id}/quote-review` has still not been opened
   on live. HAZ-04's numeric L×S inputs and the needs-confirmation badge are test-covered
   but not visually verified.
3. **Discrimination check** — the simple-job test described above.
4. **Test artefacts on production:** RAMS 96, 97 and 98 for project 92. Supersede or
   delete when convenient.

## What this phase cost, and why

Three rounds of live verification found three real defects that **2296 passing tests did
not catch**:
1. `runFromReview()` never wired to the tiered resolver (round 1)
2. Legacy reviewed names colliding instead of folding, shipping `Confined Spaces` (round 2)
3. Stale legacy scores defeating HAZ-03 (round 2)

The tests were not weak — every fixture started from clean data, while real projects carry
accumulated review history in `reviewed_data`. That gap was only ever reachable through
production data. It also surfaced a **sixth** hazard-injection path
(`RamsComplianceUpgradeService::addProjectSpecificRisks()`) that no artifact had catalogued.

*Verified 2026-08-25 against live production data (RAMS 98).*

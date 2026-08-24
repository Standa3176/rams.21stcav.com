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

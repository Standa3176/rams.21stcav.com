# Plan 28-07 Task 1 — Production Measurement (checkpoint:human-action)

**Run:** 2026-09-06, on `rams.21stcav.com` as `stcav` (not root), read-only.
**Status:** ✅ Checkpoint satisfied. The migration may now be written against real numbers.

Research could not reach the production DB, so no count was ever hardcoded. These are measured.

---

## Results

### Pass 1 — corpus scope

| Metric | Value | Share |
|---|---:|---:|
| Total `RamsDocument` rows | **54** | — |
| Carrying `FFP2` anywhere in `reviewed_data`+`generated_data` | **54** | **100%** |
| Carrying the raw substring `confined space` anywhere | **52** | 96% |
| `reviewed_data['exclusions']` already set (`isset`) | **32** | 59% |

### Pass 2 — by surface (this is what shapes the migration)

| Metric | Value | Self-heals on regeneration? |
|---|---:|---|
| `FFP2` in `ppe` / `ppe_matrix` | **54** | ❌ **No** — no rule-scanning path touches the PPE array |
| `FFP2` in hazard `controls[]` | **36** | ⚠️ Only on full regeneration (tier-1); **not** on Save Review |
| Hazard **NAME** containing `confined space` | **52** | ⚠️ Only on full regeneration (fold map); **not** on Save Review |
| **Affirmative** confined-space claim in control text | **0** | — |
| Negating-only confined-space text in controls | 5 | n/a (correct text) |

### Pass 3 — the exact stored hazard names

```
99    Confined Spaces
distinct=1
```

**One distinct string**, 99 occurrences across 52 documents (counted in both `reviewed_data` and
`generated_data`, hence 99 > 52).

---

## What the numbers mean

**1. `affirmative_cs = 0` — D-01's negation-awareness cost nothing and was still right.**
Zero live documents make an affirmative confined-space claim in control *text*. The 52 hits are
the hazard **name**, not prose. A blunt substring gate would have fired on the 5 documents
carrying the seeder's own corrected negating sentence; the negation-aware detector does not.

**2. `ppe_ffp2 = 54` — the PPE migration is mandatory, for the entire corpus.**
This surface has no self-healing path whatsoever (research Q4). Without a migration, every one of
the 54 documents keeps `Dust Mask (FFP2)` forever.

**3. `hazard_NAME_confined = 52` — this reverses a decision made during planning.**
The stored name is the **pre-Phase-26 legacy** `"Confined Spaces"` — a literal confined-space
mislabel sitting in live documents today. It is *not* Phase 26's title
("Restricted access and ceiling voids").

The plan-checker raised this as Warning 4 and the orchestrator dispositioned it as acceptable, on
the reasoning that *"the old title was never a confined-space mislabel — it's the correct
position, just not the skill's exact phrasing."* **That reasoning was wrong**: it reasoned about
Phase 26's title without measuring what was actually stored. `28-02-PLAN.md`'s
`<scope_decision_no_title_backfill>` block is superseded by this measurement — see the correction
recorded there.

**4. The stored name is exactly `LegacyHazardNameFoldMap`'s key.** So the migration's target value
is unambiguous and identical to what a full regeneration would produce:
`"Confined Spaces"` → `"Restricted access and ceiling void working"`. A deterministic
single-string replace, not a fuzzy cleanup.

---

## ⚠️ Deploy-order consequence — do NOT deploy with the gate at its default

`config/rams_tier1.php:98` defaults `ffp2_confined_space_gate_enabled` to **`true`**.

GATE-06 throws on any `FFP2` surviving into `$data`; GATE-07 throws on a confined-space hazard
name. **The Save Review path (`RamsController.php:603`) calls `upgrade()` on `generated_data`
directly, bypassing both `reviewedToRisk()`'s tier-1 correction and the fold-map name resolution.**

Therefore, arming the gate against unmigrated data blocks **Save Review on effectively the entire
live corpus** — 54 documents on the PPE/FFP2 surface, 52 on the hazard-name surface. Plan 28-06's
own test `stale confined space hazard name is blocked on save review` asserts exactly this
behaviour; at this prevalence it stops being an edge case and becomes an outage.

Regeneration (`runFromReview()`) is unaffected — the fold and tier-1 correction both run before
`upgrade()` gates.

**Required deploy sequence (28-08 must follow it):**

1. Deploy with `RAMS_PPE_CEILING_ELECTRICAL_GATE=false` in `.env`.
2. Run the migration.
3. Re-run the Pass-1 and Pass-2 scans — require `ppe_ffp2 = 0` and `hazard_NAME_confined = 0`.
4. Only then set `RAMS_PPE_CEILING_ELECTRICAL_GATE=true` (or remove the override).
5. Regenerate 21CQ30960 and confirm clean.

---

## Migration scope, now settled by data

| Surface | Rows | Action |
|---|---:|---|
| `ppe` / `ppe_matrix` FFP2 | 54 docs | Fold through `PpeVocabularyFoldMap` (Plan 28-03's map — reuse, do not re-implement) |
| Hazard `controls[]` FFP2 | 36 docs | Replace via `ControlTextRuleViolations` + current library text |
| Hazard **name** `Confined Spaces` | 52 docs / 99 occurrences | Replace with `Restricted access and ceiling void working` |
| `exclusions` already set | 32 docs | Append RULE-09's electrical-boundary bullet if absent |

Both `reviewed_data` and `generated_data` must be patched — the counts above span both.

---

*Queries were read-only and performed no writes. Scripts: `/tmp/scan.php`, `/tmp/names.php` on the
production host.*

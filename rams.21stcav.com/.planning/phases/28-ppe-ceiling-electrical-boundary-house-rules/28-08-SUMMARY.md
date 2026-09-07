# Plan 28-08 — Summary (Phase 28 close-out)

**Completed:** 2026-09-07
**Requirements:** RULE-01, RULE-06, RULE-09, RULE-10, GATE-06, GATE-07 — all closed
**Status:** ✅ **4 of 4 ROADMAP success criteria met.** Deployed and verified on live production.

---

## Live deployment transcript

Performed on `rams.21stcav.com` as **`stcav`** (never root), 2026-09-07.

| Step | Result |
|---|---|
| `git pull` | `93d4a2b..ffb00a3`, then `ffb00a3..45f6770` — 59 + 3 files |
| Gate disarmed pre-migration (`RAMS_PPE_CEILING_ELECTRICAL_GATE=false`) | `config('...gate_enabled')` → `bool(false)` ✅ |
| Migration 1 — `2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet` | 67 doc/column pairs: 61 ppe folds, 15 control replaces, **52 hazard-name replaces**, 32 exclusions appends |
| Post-migration-1 scan | `ppe_ffp2=0` ✅ `hazard_NAME_confined=0` ✅ **`controls_ffp2=34`** ❌ |
| Diagnosis | 2 distinct strings under 3 legacy hazard names absent from `hazard_templates` |
| Migration 2 — `2026_09_07_090000_backfill_residual_ffp2_control_lines` | **34 documents, 48 control lines** — exactly the predicted 34 + 14 |
| Post-migration-2 scan | `ppe_ffp2=0 controls_ffp2=0 hazard_NAME_confined=0 affirmative_cs=0` ✅ |
| Gate armed | `bool(true)` ✅ |
| **Criterion 4 — armed gate vs real proof job** | Project 92 = `21CQ30960-OPS` (Volkswagen Group), RAMS 102 → **`GATE RESULT: PASSED`** ✅ |

---

## The four criteria

**1. No FFP2 anywhere; face-fit stated.** 14 live source sites corrected (28-03 + 28-04), all 54
production documents migrated to zero, and a repo-wide static ban test (`FfpTwoBannedFromSourceTest`)
prevents a 15th site appearing on a path the proof job never exercises. Three independent layers:
static source ban, tier-1 auto-correct, runtime throw.

**2. No confined-space mislabel in any title, fallback or generated text.** Hazard renamed to
`Restricted access and ceiling void working` across seeder, fold map, drift-guard, requirement and
roadmap; 52 production documents backfilled from the legacy `Confined Spaces`;
`hazard_NAME_confined=0` on live.

**3. Ceiling-load and electrical-boundary statements land in output.** RULE-10 needed no new
mechanism — research proved empirically that `signal:ceiling_void_access` already fires for
ceiling-*mounted* jobs; 28-05 locked it with an end-to-end regression test. RULE-09's boundary
sentence ships as an unconditional exclusions bullet, plus a backfill for the 32 documents whose
`exclusions` key was already set and could never receive it otherwise.

**4. GATE-06/07 error on the defect and pass clean on a real project.** Throw behaviour proven by
revert-and-restore across three generation entry points (`runPipeline()`, `runFromReview()`, Save
Review) plus the previously-uncaught `downloadPdf()` path; clean pass proven live above.

---

## What the live data changed about the plan

**Two planning decisions were overturned by measurement, not by argument.**

**1. The hazard-name backfill.** The plan-checker raised it (Warning 4); the orchestrator
dispositioned it as cosmetic, reasoning *"the old title was never a confined-space mislabel."*
That reasoning examined the code and not the data. Production held the **pre-Phase-26 legacy name
`Confined Spaces`** in 52 of 54 documents — a literal mislabel in live client documents. It was
also load-bearing: GATE-07's name check throws on it, and Save Review bypasses the fold map, so
arming without the backfill would have blocked Save Review corpus-wide. `28-02-PLAN.md` carries a
correction banner.

**2. The deploy order.** `config/rams_tier1.php` defaults the gate to `true`. With 54/54 documents
carrying FFP2 and 52/54 a mislabelled name, a naive deploy arms a gate that blocks Save Review on
effectively everything. The measurement forced an explicit sequence — ship flag-off → migrate →
re-measure to zero → arm — which is now recorded in `28-08-PLAN.md`.

**Why the first migration left 34 documents behind, and why that was correct.** It resolves
replacement control text through `hazard_templates` keyed by name. The residual lines sat under
three legacy names absent from the library — `Dust from Drilling & Cutting` (ampersand),
`Working in Ceiling Voids`, and a long AI-generated one — so the lookup missed and it **failed
closed rather than guessing**. Diagnosis then showed only two distinct strings across all 48
occurrences, both the app's own pre-28-04 hardcoded output, making the follow-up a literal
exact-match replace needing no library at all.

**`affirmative_cs=0` / `negating_only=5` — the negation-awareness paid for itself on real data.**
Five production documents carry the seeder's own corrected sentence *"These are **not** classified
as confined spaces"*. A blunt substring gate would have flagged all five and the migration would
have rewritten correct safety text. D-01's negation-aware detector left them untouched.

---

## Local verification

Full suite (`php artisan test`, unfiltered): **2442 passed, 9638 assertions, 2 failed.**

Both failures verified pre-existing, not assumed:

- `RamsBuilderServiceTest:561` — reverted 28-01 + 28-03 source to `c27bfec` and re-ran; still
  fails there.
- `QueueRecoverCommandTest:163` — Phase 28 touched zero queue files
  (`git diff --name-only | grep -ci queue` = 0); the test's own comment marks it deferred from a
  prior quick task.

Both logged in `deferred-items.md`.

---

## Process failures worth recording

**1. The runbook's first `git pull` had nothing to pull.** 35 commits were never pushed. Caught by
`var_dump(config(...))` returning `NULL` — a check placed in the runbook to verify `config:cache`
wasn't lying about the flag value, which happened to also prove the code wasn't deployed.

**2. A safety step was written as a comment, not a command.** `# 2. set
RAMS_PPE_CEILING_ELECTRICAL_GATE=false in .env` inside a copy-paste block. Bash ignored it, `.env`
was never touched, and the gate came up armed against unmigrated data the moment the code landed —
the exact outage the sequence existed to prevent. Caught by the same `var_dump`, now returning
`bool(true)`. Resolved in minutes with no engineer impact observed.

**3. The arming gate was under-specified.** It required `ppe_ffp2=0` and `hazard_NAME_confined=0`
but omitted `controls_ffp2` — the one surface the first migration under-covered. Caught because the
migration's reported counts diverged from the measurement and were investigated rather than
accepted.

The pattern in all three: **the verification steps caught the mistakes, including mistakes in the
instructions themselves.** Worth preserving in future runbooks.

---

## Deferred, stated not silent

- **D-07's four further `house-rules.md` electrical positions** — BS 7671 / lock-off / test-dead
  wording (carries an unresolved user decision), the live-working-PPE-row ban, the
  "first-fix power" → "first-fix AV signal/data/ELV cabling" rename, and hardwired-supply
  isolation. Recorded in `28-05-SUMMARY.md` and `REQUIREMENTS.md`.
- **Face-fit wording on two fallback strings.** `RamsComplianceUpgradeService.php:760` and `:809`
  now read FFP3 but do not state face-fit testing; the seeder's library control
  (`HazardTemplateSeeder.php:294`) does. RULE-01's face-fit clause is satisfied via the library
  path. Whether the fallbacks should also state it is a content decision, raised and not silently
  taken. Would be a small source change plus a follow-up data replace.
- **`DocxBuilderService.php:1903`** — §6.11 boilerplate naming `confined-space entry` as a
  Principal Contractor permit category. Deliberately excluded (`28-01-PLAN.md`
  `<scope_decisions>`); never enters `$data`, so the gate never scans it.
- **RULE-11 (fire-stopping)** — moved to Phase 31 during discussion (D-09), with its own success
  criterion there.

---

## Phase 28 — CLOSED

29 commits. `config/rams_tier1.php:286`'s fire-stop wording deliberately untouched (Phase 31 owns
it). Rollback remains one `.env` edit plus `config:clear`, with no effect on GATE-09.

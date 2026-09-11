# Plan 29-01 — Measurement (Wave 0)

**Status:** Task 1 (local suite runtime) complete. Task 2 (production CDM placeholder count +
live `RAMS_UNIFIED_COMPOSER` state) complete — measured 2026-09-11 on the live VPS as `stcav`
against `https://rams.21stcav.com` (`stcav_rams` database, verified NOT the `rams-accept` copy).

---

## Local suite runtime

**Run:** 2026-09-11, local dev machine (Herd PHP 8.4.25), `php artisan test` (full suite, no
filter), read-only — no database writes beyond the test suite's own transactional fixtures.

| Metric | Value |
|---|---:|
| Wall-clock elapsed (measured around the process) | **576s** (~9m 36s) |
| PHPUnit-reported `Duration` | **531.45s** (~8m 51s) |
| Passed | **2455** |
| Failed | **1** |
| Deprecated | 2 |
| Warnings | 10 |
| Skipped | 6 |
| Assertions | 9664 |

**The 1 failure is pre-existing and out of scope for this plan.**
`Tests\Feature\Queue\QueueRecoverCommandTest::unhealthy queue runs restart and drain plan`
fails asserting `QueueRecoverCommand::EXIT_RECOVERED` — the test's own inline comment
(`tests/Feature/Queue/QueueRecoverCommandTest.php:159-162`) documents this as a known,
already-flagged memory-threshold interaction "out of scope for this quick task." Nothing in
Plan 29-01 touches the queue-recovery command; per the executor scope boundary this failure is
logged, not fixed, and does not block Wave 0.

---

## Production CDM placeholder count

**Status:** ✅ MEASURED — 2026-09-11, operator ran the Task 2 tinker command on
`rams.21stcav.com` as `stcav` (read-only `select` + in-memory filter, zero writes).

| Metric | Value |
|---|---:|
| Total `RamsDocument` rows | **54** |
| Rows carrying the CDM `[To be confirmed]` placeholder (`reviewed_data['cdm']` or `generated_data['cdm_duty_holders']`) | **46** (85%) |

**Consequence for Plan 29-05:** 46 of 54 production rows (85% of the live corpus) carry the
placeholder. The backfill migration is load-bearing, not cosmetic — a code-only fix (Plan
29-02/29-03) would leave 85% of existing documents still showing `[To be confirmed]` on
regeneration until the backfill runs. This confirms the D-02 measure-first rationale and gives
Plan 29-05 its real target count.

---

## Production `RAMS_UNIFIED_COMPOSER` state

**Status:** ✅ MEASURED — 2026-09-11, operator ran
`php artisan tinker --execute="echo config('rams.unified_composer') ? 'true' : 'false';"` on
the VPS as `stcav` against the live app (`config()` read, zero writes).

| Metric | Value |
|---|---|
| Live `config('rams.unified_composer')` | **false** |

**Consequence for Plans 29-02/29-04:** production currently renders through the **legacy blade
path** (`rams.blade.php`, `rams-v2.blade.php`, `DocxBuilderService.php`) — the unified composer
path (`EmergencyComposer`/`WelfareComposer`/`EmergencySectionDto`) is dormant in production
today. This resolves the open question RESEARCH.md flagged as UNKNOWN and that CONTEXT.md D-01
required planning to verify. **Both layers remain required — D-01 is unchanged**: Plan 29-04's
legacy render-site fixes are what actually change live documents right now, while Plan 29-02's
composer/DTO wiring is correct-but-dormant insurance for the day `RAMS_UNIFIED_COMPOSER` flips
to `true` in production. Neither plan is rescoped by this finding.

---

*Local suite run was read-only against the local test database only. No production data was
touched by Task 1. Task 2's commands were read-only (a `config()` read and a `select` +
in-memory filter; zero writes) — see the threat model in `29-01-PLAN.md`. No production writes
occurred in either task.*

---

## Production deploy 2026-09-11 (Plan 29-06, Task 3 — deploy + backfill half)

**Status:** ✅ Deploy and backfill migration verified on production. Visual document
inspection (the other half of Task 3 / ROADMAP criterion 4) is **outstanding** — see
`29-06-SUMMARY.md`.

**Deploy:** Pushed `429fdfd..38eb41d` to the RAMS remote; the VPS pulled fast-forward
`efdac0f..38eb41d` as `stcav` at `/home/stcav/rams.21stcav.com.git/rams.21stcav.com`.
49 files changed. `php artisan optimize:clear && php artisan config:cache` ran clean.

**Backfill migration (`2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php`)** ran via
`php artisan migrate --force` as `stcav`, with this exact output:

```
backfill_cdm_duty_holder_placeholder: 46 document(s) touched — 46 generated_data.cdm_duty_holders PD/PC replace, 0 reviewed_data.cdm PD/PC row replace (143.18ms)
```

**Interpretation:**

- **46 touched matches the 29-01 measurement of 46/54 exactly** — every production row this
  phase's own Wave 0 measurement identified as carrying the CDM `[To be confirmed]` placeholder
  was replaced by the backfill, no more and no fewer.
- **The `0` for `reviewed_data.cdm` empirically confirms 29-RESEARCH.md's finding** that
  `generated_data.cdm_duty_holders` is the column feeding live output, and that no production
  row had engineer-entered CDM rows carrying the placeholder. The placeholder-only guard
  (D-04's carry-forward guard) never needed to skip a real typed value in this run — there was
  none to skip.

**Gate arming state:** `RAMS_CDM_AE_GATE` remains unset (defaults `false`) in the production
`.env` — the gates are deployed but **NOT armed**, per D-03. This is the intended end state
until the outstanding visual document inspection (below) passes.

**Outstanding (not done in this session):** The visual inspection of a regenerated live
project's PDF and DOCX — CDM duty-holder table wording, Section 7.0 A&E row, Welfare First Aid
bullet deferring to Section 7.0. No document was opened and checked. **ROADMAP Phase 29 success
criterion 4 is therefore NOT met** — the deploy and backfill halves are done; the
document-inspection half is not. See `29-06-SUMMARY.md` for the full breakdown and follow-ups.

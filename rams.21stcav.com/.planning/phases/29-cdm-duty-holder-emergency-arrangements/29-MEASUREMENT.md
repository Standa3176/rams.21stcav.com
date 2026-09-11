# Plan 29-01 — Measurement (Wave 0)

**Status:** Task 1 (local suite runtime) complete. Task 2 (production CDM placeholder count +
live `RAMS_UNIFIED_COMPOSER` state) is a `checkpoint:human-action` — **pending**, awaiting the
operator to run the read-only commands on `rams.21stcav.com` as `stcav`.

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

**Status:** ⏳ PENDING — requires the operator to run the Task 2 tinker command on
`rams.21stcav.com` as `stcav`. See `29-01-PLAN.md` Task 2 `<how-to-verify>` for the exact
read-only command block.

| Metric | Value |
|---|---:|
| Total `RamsDocument` rows | TBD |
| Rows carrying the CDM `[To be confirmed]` placeholder (`reviewed_data['cdm']` or `generated_data['cdm_duty_holders']`) | TBD |

---

## Production `RAMS_UNIFIED_COMPOSER` state

**Status:** ⏳ PENDING — requires the operator to run
`php artisan tinker --execute="echo config('rams.unified_composer') ? 'true' : 'false';"` on
the VPS. Cannot be inferred from this repo/session — `29-RESEARCH.md` Finding 4 confirms the
local `.env` has no `RAMS_UNIFIED_COMPOSER` line (resolves to the `config/rams.php:43` default
`false` locally) and that this tells us nothing about production, which may run `config:cache`.

| Metric | Value |
|---|---|
| Live `config('rams.unified_composer')` | TBD |

---

*Local suite run was read-only against the local test database only. No production data was
touched by Task 1. Task 2's commands are read-only (a `config()` read and a `select` +
in-memory filter; zero writes) — see the threat model in `29-01-PLAN.md`.*

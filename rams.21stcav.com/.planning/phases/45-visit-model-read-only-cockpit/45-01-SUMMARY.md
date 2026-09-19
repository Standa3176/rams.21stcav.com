---
phase: 45-visit-model-read-only-cockpit
plan: 01
subsystem: planning
tags: [requirements, traceability, test-baseline, phpunit, gate]

# Dependency graph
requires: []
provides:
  - "VIS-01..VIS-10 requirement IDs in .planning/REQUIREMENTS.md with ten traceability rows under Milestone v4.0"
  - "45-BASELINE.md: the MEASURED D-06 behaviour-preservation gate — 159 passed / 2 skipped / 0 failed (396 assertions) in 88.82s at clean HEAD 4abd2b24"
  - "The canonical enumerated 12-path baseline command, copy-pasteable, that 45-04 and 45-08 must re-run character-identical"
  - "Pre-phase sha256 of resources/views/layouts/app.blade.php, resources/css/app.css, tailwind.config.js for Plan 45-08 criterion-4 hash assertions"
affects: [45-02, 45-03, 45-04, 45-05, 45-06, 45-07, 45-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A behaviour-preservation gate is expressed as '>= N passed AND 0 failed', never as an equality against a static method count — because skipped tests make methods != passes"
    - "Environmental skips are excluded from a gate BY NAME in the baseline document, not silently absorbed into the number"
    - "Working-tree sha256 (Get-FileHash, CRLF bytes) is recorded with an explicit warning not to compare against git-normalised LF hashes"

key-files:
  created:
    - .planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md
  modified:
    - .planning/REQUIREMENTS.md

key-decisions:
  - "The gate is 159, not the researched 161. 161 was a correct static count of test METHODS; two of them self-skip on missing ext-imagick, so the executable pass count is 159. Later plans assert >= 159 with 0 failed."
  - "The two ext-imagick skips are NOT to be fixed during Phase 45 — installing the extension mid-phase would move the gate to 161 and destroy the before/after comparison."
  - "VIS-02 is deliberately written narrower than ROADMAP criterion 2 (signed worksheets only), with D-01 recorded in the requirement text as authoritative over the criterion wording."

patterns-established:
  - "Serialising a lone wave-1 measurement plan ahead of all code plans, so a phase's safety-rail number is taken against a fixed tree rather than a moving one"

requirements-completed: []

# Metrics
duration: 18min
completed: 2026-09-19
---

# Phase 45 Plan 01: Requirements Minting + Measured D-06 Baseline Summary

**Minted VIS-01..VIS-10 into REQUIREMENTS.md, then EXECUTED the programme-lifecycle subset on a tree with zero Phase 45 code and found the phase's gate is 159 passing, not the 161 that `45-RESEARCH.md` had counted statically and never run — 161 is the correct count of test *methods*, but two self-skip on missing `ext-imagick`, so a literal `161 passed` assertion in 45-04/45-08 would have read red on a perfectly healthy tree.**

## Performance

- **Duration:** ~18 min
- **Tasks:** 2/2 completed
- **Files modified:** 1 created, 1 modified
- **Test run:** 88.82s wall clock

## Accomplishments

- Ten `- **VIS-nn** — ...` statements added as `### Group VIS — Visit model + read-only cockpit (Phase 45)`, positioned exactly between the `### Group LR` block and `### Out of scope for v4.0`, in the LR block's shape (framing sentence then bullet list)
- Ten traceability rows appended after the `LR-05` row, each mapped to its owning plan per the plan's mapping table (VIS-01→45-02, VIS-02/07→45-05, VIS-03→45-05+45-08, VIS-04/06→45-06+45-07, VIS-05→45-08, VIS-08→45-02+45-05+45-07, VIS-09→45-04, VIS-10→45-03+45-06)
- Both stale lines corrected: totals now `15 defined so far (LR-01..LR-05, Phase 44; VIS-01..VIS-10, Phase 45)`, and the closing italic note narrowed from `Phases 45–51` to `Phases 46–51` with the `LR-xx / VIS-xx` pattern named
- The enumerated 12-path baseline subset was **actually executed** through PowerShell with the explicit Herd php84 binary — not the `--filter` shorthand, so the subset membership is fixed and reproducible for 45-04 and 45-08
- The real numbers are recorded verbatim in `45-BASELINE.md`, with the producing command, HEAD SHA, and an explicit statement of the gate and its stop condition
- Both skipped tests named, classified as pre-existing environmental, and excluded from the gate **by name**
- The three pre-phase sha256 hashes recorded under `## Pre-phase file hashes`, taken on files that `git status --porcelain` confirmed clean

## Task Commits

1. **Task 1: Mint VIS-01..VIS-10 into REQUIREMENTS.md** — `dc0c19f7` (docs)
2. **Task 2: MEASURE and record the D-06 behaviour-preservation baseline** — `3ec953d1` (docs)

## Files Created/Modified

- `.planning/REQUIREMENTS.md` — Group VIS block (10 statements), 10 traceability rows, totals line 5→15, closing note 45–51→46–51
- `.planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md` — the measured gate, the copy-pasteable command, HEAD SHA, named skips with per-file counts, three pre-phase sha256 hashes, and the D-06 stop-condition statement

## Test Results

Measured at clean HEAD `4abd2b24` (no `visits` migration, no `install_records`, no brand tokens, no `npm install`):

```
  Tests:    2 skipped, 159 passed (396 assertions)
  Duration: 88.82s
```

| Metric | Measured |
|---|---|
| Passed | **159** |
| Skipped | 2 (environmental) |
| **Failed** | **0** |
| **Errors** | **0** |
| Assertions | 396 |

Cross-checked independently against the raw output: exactly 159 pass marks, 0 failure/error markers. Per-file pass counts matched `45-RESEARCH.md` §5's table exactly.

## THE FINDING — the gate is 159, not 161

This is the discovery Plan 45-01 exists to make, and it is a finding, not a problem.

`45-RESEARCH.md` §5 counted **161 test methods across 29 files** statically via `grep`, flagged it as **assumption A1 — never executed** (`:488-489`), and predicted "the subset should be 161/161 clean — **unverified**".

**The static count of 161 was exactly right as a count of test methods. The wrong step was inferring that 161 methods means 161 passes.** Two methods call `markTestSkipped()` because HEIC→JPEG conversion needs the `imagick` PHP extension, absent from this Herd php84 build. 159 executed and passed; 2 skipped; 0 failed.

Consequence for the phase: **45-04 and 45-08 must assert `>= 159 passed` AND `0 failed`**, never a literal equality against 161. Had they been written against 161, both would have failed on a clean, entirely healthy tree — the worst failure mode for a gate, because a gate that starts red teaches everyone to ignore it, and D-06's stop condition would then have been unenforceable for the rest of the phase.

## Pre-existing failures / skips — named and excluded

| # | Test | File | Reason | Classification |
|---|---|---|---|---|
| 1 | `heic converts to jpeg` | `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php` | `ext-imagick not loaded; HEIC conversion cannot be verified.` | Pre-existing, environmental, **not a failure** |
| 2 | `upload heic converts to jpeg` | `tests/Feature/Commissioning/ItemPhotoUploadTest.php` | `ext-imagick not loaded; HEIC conversion cannot be verified.` | Pre-existing, environmental, **not a failure** |

**No test in the subset FAILED.** These two are *skips*, not failures, and they skip identically on a tree with zero Phase 45 code, so no later plan in this phase can be blamed for them. Neither is to be fixed in Phase 45 — installing `ext-imagick` would raise the gate mid-phase and void the comparison.

The documented `QueueRecoverCommandTest` full-suite failure (`.planning/STATE.md`) is **not in this subset** and did not appear. It is recorded in `45-BASELINE.md` only so a later reader does not conflate it with the two skips.

## Pre-phase file hashes (for Plan 45-08 criterion 4)

Taken at HEAD `4abd2b24`, all three files confirmed clean via `git status --porcelain`:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` |
| `resources/css/app.css` | `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` |
| `tailwind.config.js` | `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` |

⚠️ These are **working-tree** hashes (CRLF on this Windows checkout). 45-08 must re-run the same `Get-FileHash` command; a `git hash-object` or LF-normalised hash will not match and would read as a false positive change. `45-BASELINE.md` carries this warning inline.

## Decisions Made

- **Gate expressed as `>= 159 passed AND 0 failed`**, not `== 161`. Documented in `45-BASELINE.md` with the reason, so a future reader on an imagick-enabled host seeing `161 passed` understands why that is still a pass.
- **The two skips are excluded by name rather than by lowering the number silently** — following the precedent `.planning/STATE.md` already sets for `QueueRecoverCommandTest`.
- **VIS-02 keeps the narrower D-01 wording** (only worksheets with at least one signoff are wrapped), with the divergence from ROADMAP criterion 2 stated inside the requirement text and D-01 named as authoritative. A requirement that silently contradicted the roadmap would have surfaced as a bug report in 45-05.
- **17 PHPUnit doc-comment deprecation WARNs left untouched.** They are pre-existing noise in `InstallTaskGeneratorServiceTest.php`, and "tidying" them would edit the exact lifecycle test file this gate protects. Recorded as out of scope in `45-BASELINE.md`.

## Deviations from Plan

None — plan executed exactly as written. Both tasks matched their `<action>` blocks; the measured-number mismatch against 161 is not a deviation but the plan's own stated anticipated outcome ("If the measured number is not 161, that is a finding, not a problem").

Prohibitions honoured:
- No migration, model, config or CSS file created — the only file outputs are `.planning/REQUIREMENTS.md` and `45-BASELINE.md`, exactly as `files_modified` declares
- `migrate:fresh --env=testing` was **not** run (it would fall back to `.env` and wipe `database/database.sqlite`)
- No package installs (T-45-02); the phase's one install is 45-03's already-audited `@fontsource/poppins`
- Every PHP invocation went through PowerShell with the explicit Herd binary, never Bash — a piped `php ... | tail` in Bash exits 0 while running nothing

## Known Stubs

None. This plan produced only planning documents; nothing renders or consumes data.

## Threat Flags

None beyond the plan's own `<threat_model>`. T-45-01 (repudiation of the gate number) is now mitigated in fact rather than in intent: `45-BASELINE.md` records the producing command and HEAD SHA, so "it was always like that" is checkable. No trust boundary was crossed and no runtime surface changed.

## Issues Encountered

One tooling friction, no impact on results: a Bash heredoc failed to write `45-BASELINE.md` (unmatched-quote parse error on content containing PowerShell backticks and nested quotes), so the file was written with the Write tool instead. Content and verification unaffected.

## Verification

- Plan's Task 1 automated check, run verbatim through PowerShell: `OK 10 statements / 10 traceability rows`
- Section ordering confirmed: `Group LR` (:34) → `Group VIS` (:56) → `Out of scope for v4.0` (:85) → `Traceability` (:102), with VIS rows at :111-120 directly after LR-05 at :110
- `45-BASELINE.md` contains 1 `Tests:` line, 1 `## Pre-phase file hashes` heading, 3 sha256 values

## Next Steps

Wave 2 unblocks: 45-02 (`visits` migration), 45-03 (brand tokens + `@fontsource/poppins`), and the rest of the phase. **45-04 and 45-08 must read `45-BASELINE.md` and assert `>= 159 passed AND 0 failed` against the enumerated command recorded there — not the 161 figure still stated throughout `45-RESEARCH.md`.** `45-RESEARCH.md` has deliberately not been edited; it is a point-in-time research artefact, and `45-BASELINE.md` supersedes its §5 numbers.

## Self-Check: PASSED

- `.planning/REQUIREMENTS.md` — FOUND (10 VIS statements, 10 VIS traceability rows, totals line reads 15)
- `.planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md` — FOUND (`Tests:` line, `## Pre-phase file hashes`, 3 sha256 values)
- `.planning/phases/45-visit-model-read-only-cockpit/45-01-SUMMARY.md` — FOUND
- Commit `dc0c19f7` (Task 1) — FOUND in git log
- Commit `3ec953d1` (Task 2) — FOUND in git log
- `git diff --name-only 4abd2b24..HEAD` returns **exactly two paths**, both under `.planning/` — confirming the plan created no migration, model, config or CSS file, as required of the sole wave-1 plan

## State Updates — and why STATE.md was deliberately NOT touched

- `gsd-sdk query roadmap.update-plan-progress 45` — **applied**: ticked `45-01-PLAN.md` to `[x]` in ROADMAP.md (`plan_count: 8, summary_count: 1, status: In Progress`). This was the only state write made.
- `gsd-sdk query state.advance-plan` — **no-op** (`advanced: false, reason: last_plan`).
- `gsd-sdk query state.update-progress` — **no-op** (`Progress field not found in STATE.md`).
- `gsd-sdk query state.record-metric` — errored on argument handling.

**`.planning/STATE.md` was left unmodified, deliberately.** Two reasons, both recorded so the next executor does not read this as an omission:

1. **STATE.md is tracking a different milestone.** Its frontmatter reads `milestone: v2.0 / Engineering-Grade AV Drawings`, `status: verifying`, and its Current Position narrates Phase 30. It carries no Phase 44/45 or v4.0 position at all, which is why all three `state.*` handlers no-op'd. Grafting a Phase 45 position into a v2.0-shaped document is a coordinator-level decision about milestone bookkeeping, not this plan's to make.
2. **STATE.md already carries uncommitted changes from another session** (`git diff --stat` = 8 insertions / 8 deletions, `last_updated: 2026-09-19T17:57:46Z`, flipping `status: executing`→`verifying` and `completed_phases: 8`→`7` for Phase 30). Staging STATE.md would have swept that unrelated work-in-progress into a Phase 45 commit. Per the scope boundary, foreign WIP is not mine to commit.

`requirements.mark-complete` was **not** run: VIS-01..VIS-10 are minted as `Planned`, not delivered. Nothing in this plan implements them, so marking any complete would be false. `requirements-completed: []` in this summary's frontmatter reflects that.

ROADMAP.md needed no other edit — its Phase 45 § already forward-referenced "VIS-01..VIS-10, minted at planning time (2026-09-19) into `.planning/REQUIREMENTS.md` § Milestone v4.0 › Group VIS" (`ROADMAP.md:359-361`). Task 1 makes that reference resolve; before it, the roadmap pointed at a section that did not exist.

# Phase 45 — D-06 Behaviour-Preservation Baseline (MEASURED)

**Status:** MEASURED, not estimated. Executed 2026-09-19 by Plan 45-01, Task 2.
**Taken at:** `git rev-parse --short HEAD` = **`4abd2b24`** — a clean tree with **ZERO Phase 45
code**. No `visits` migration, no `install_records`, no brand tokens, no `npm install` had run.
**Purpose:** D-06 in `45-CONTEXT.md` says that if the `InstallProgramme` split cannot be made
behaviour-preserving, stop and raise it. That constraint is only enforceable against a number that
was actually produced by running the suite. This file is that number.

---

## The gate

> **Plans 45-04 and 45-08 must re-run the exact command below and assert the same-or-greater pass
> count with ZERO failures. A reduction in pass count is a D-06 stop condition — raise it, do not
> relax criterion 4.**

**Gate: 159 passing, with these named exclusions — the 2 environmental skips listed below.**
Zero failures. Zero errors.

The two skips are **not** excluded because they are broken; they are excluded because they
self-skip on this machine for a missing PHP extension. On a host with `ext-imagick` installed the
same command will print `161 passed`. Later plans must therefore assert **`>= 159 passed` AND
`0 failed`**, never a hard equality against 161.

---

## The command (copy-pasteable, PowerShell, from the repo root)

`php` is **not** on the Bash tool's PATH on this machine. A piped `php ... | tail` through Bash
exits 0 while executing nothing, which fakes a green gate. Always use the explicit Herd binary
through PowerShell.

```powershell
cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com"
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan test `
  tests/Unit/InstallTaskGeneratorServiceTest.php `
  tests/Feature/InstallTasks `
  tests/Feature/FieldView `
  tests/Feature/Commissioning `
  tests/Unit/Services/CommissioningItemGeneratorTest.php `
  tests/Unit/Services/CommissioningPdfServiceTest.php `
  tests/Unit/Services/CommissioningServiceTest.php `
  tests/Unit/Services/CommissioningSyncServiceTest.php `
  tests/Unit/Models/CommissioningItemTest.php `
  tests/Unit/Models/CommissioningSignoffTest.php `
  tests/Feature/Projects/ProjectDeliverableAutoFlipTest.php `
  tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php
```

**Use the enumerated file list, not the `--filter=` shorthand** that `45-RESEARCH.md` §5 offers as
an alternative. The shorthand's membership changes as tests are added, which would silently move
the gate. The enumerated form fixes the subset. 45-04 and 45-08 must run this
**character-identical**.

WARNING — **Do NOT run `migrate:fresh --env=testing`.** There is no `.env.testing` in this repo; it
falls back to `.env`, where `DB_DATABASE` is commented out, and would wipe
`database/database.sqlite` — live local dev data. The subset needs no database preparation:
`phpunit.xml:37-38` uses sqlite `:memory:`, so each run is self-contained.

---

## Measured result — verbatim

```
  Tests:    2 skipped, 159 passed (396 assertions)
  Duration: 88.82s
```

| Metric | Measured |
|---|---|
| Passed | **159** |
| Skipped | **2** (both environmental — named below) |
| **Failed** | **0** |
| **Errors** | **0** |
| Assertions | 396 |
| Duration | 88.82s |
| Test methods present | 161 (159 executed + 2 skipped) |
| HEAD at measurement | `4abd2b24` |

Independent cross-check: the raw output contains exactly **159** pass marks and **0**
failure/error markers.

---

## Delta from `45-RESEARCH.md`'s static count — a FINDING, not a problem

`45-RESEARCH.md` §5 stated **161 test methods across 29 files**, counted statically by
`grep -cE "public function test_|#[Test]|* @test"`, and flagged as **assumption A1 —
never executed** (`45-RESEARCH.md:488-489`). Its §5 closing note predicted "the subset should be
161/161 clean — **unverified**".

**The static count of 161 was exactly right as a count of test _methods_. What was wrong was the
inference that 161 methods means 161 _passes_.** Two of the 161 self-skip on this machine, so the
real executable pass count is **159**.

This is precisely the discrepancy Plan 45-01 exists to surface. Had 45-04 or 45-08 been written to
assert a literal `161 passed`, both would have failed on a clean, entirely healthy tree, and the
phase's safety rail would have read as red from the very first task — the worst possible failure
mode for a gate, because a gate that starts red teaches everyone to ignore it.

---

## The 2 skipped tests — named, classified, excluded explicitly

Both are **environmental**, both are **pre-existing**, and neither is a failure. Each calls
`markTestSkipped()` because the HEIC-to-JPEG conversion path needs the `imagick` PHP extension,
which is not loaded in this Herd php84 build. They skip identically at `4abd2b24` with no Phase 45
code present, so **no later plan in this phase may be blamed for them.**

| # | Test | File | Skip reason (verbatim from the run) | Classification |
|---|---|---|---|---|
| 1 | `heic converts to jpeg` | `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php` | `ext-imagick not loaded; HEIC conversion cannot be verified.` | Pre-existing, environmental. Not a defect. |
| 2 | `upload heic converts to jpeg` | `tests/Feature/Commissioning/ItemPhotoUploadTest.php` | `ext-imagick not loaded; HEIC conversion cannot be verified.` | Pre-existing, environmental. Not a defect. |

**Do NOT fix these in Phase 45.** Installing `ext-imagick` would raise the gate to 161 mid-phase
and make the before/after comparison meaningless. If the extension ever is installed, the gate
number must be re-measured on a clean tree and this file amended, not silently adjusted.

### Per-file counts for the two files that reported a skip

| File | Methods | Passed | Skipped | Failed |
|---|---|---|---|---|
| `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php` | 8 | 7 | 1 | 0 |
| `tests/Feature/Commissioning/ItemPhotoUploadTest.php` | 6 | 5 | 1 | 0 |

Every other file in the subset reported `PASS` with no skips and no failures. Per-file pass counts
matched `45-RESEARCH.md` §5's table exactly.

### On `QueueRecoverCommandTest`

`.planning/STATE.md` and `45-RESEARCH.md` §5 both document a pre-existing
`QueueRecoverCommandTest` failure in _full-suite_ runs (a memory-threshold interaction, described
in the test's own comment). **It is not in this subset and did not appear in this run.** It is
recorded here only so that a later reader does not conflate it with the two skips above.

### Deprecation notices (noise, not findings)

The run emitted 17 PHPUnit `WARN Metadata found in doc-comment` notices for
`tests/Unit/InstallTaskGeneratorServiceTest.php` (doc-comment metadata deprecated in favour of
attributes from PHPUnit 12). These are pre-existing, affect no result, and are explicitly **out of
scope** for Phase 45. Do not "tidy" them — doing so would edit the very lifecycle test file this
gate protects.

---

## Pre-phase file hashes

Plan 45-08 criterion 4 asserts these three files are byte-identical at end of phase — i.e. that
Phase 45 introduced brand tokens without retoning any existing page (VIS-10) and without touching
the shared layout. Without these values recorded pre-phase, that assertion has nothing to compare
against.

Taken at HEAD **`4abd2b24`**, on a working tree where `git status --porcelain` reported all three
files **clean** (no local modifications), so these hashes correspond to committed state.

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` |
| `resources/css/app.css` | `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` |
| `tailwind.config.js` | `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` |

Command that produced them (PowerShell, repo root):

```powershell
Get-FileHash -Algorithm SHA256 resources/views/layouts/app.blade.php, resources/css/app.css, tailwind.config.js | Format-List Path,Hash
```

WARNING — **45-08 must re-run this same `Get-FileHash` command on the working tree.** These are
hashes of **working-tree bytes**, which on this Windows checkout carry CRLF line endings. A
`git hash-object` value, or a hash taken on an LF-normalised copy, will **not** match and would
read as a false positive change. Compare like with like.

---

## Provenance

| Item | Value |
|---|---|
| Measured by | Plan 45-01, Task 2 |
| Date | 2026-09-19 |
| HEAD | `4abd2b24` |
| PHP binary | `%USERPROFILE%\.config\herd\bin\php84\php.exe` |
| Tree state | Clean of all Phase 45 code — 45-02 (migration) and 45-03 (`npm install`) deliberately deferred to wave 2 so this number is measured against a fixed tree |
| Shell | PowerShell (mandatory — `php` is absent from the Bash PATH) |

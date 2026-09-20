# Phase 45 — Flag-Off Proof and Whole-Phase Gate Re-run (MEASURED)

**Status:** MEASURED, not asserted. Executed 2026-09-19 by Plan 45-08, Tasks 1 and 2.
**Amended 2026-09-20 by Plan 45-14** — the whole-phase gate was re-run against the **rebuilt**
page (sketch 004, D-08..D-16, Plans 45-09..45-13). Nothing in 45-08's reasoning is withdrawn:
§ 5 (the flag-off `install_records` write and why criterion 5 still holds) and § 4's ruling that
the 404-body assertion is vacuous both stand unchanged. The new measurements are § 9; the two
flag-state facts that changed since 45-08 are recorded in § 0.
**Purpose:** ROADMAP criterion 4 says the application "behaves exactly as it does today with the
flag off — proven by a test, not by inspection". This document records that proof, the end-of-phase
re-run of the D-06 baseline, and the two pieces of reasoning that exist nowhere in code.

## 0. Two things that changed after 45-08 — read these before § 1-§ 7

1. **`COCKPIT_ENABLED` is now `true`.** It was set in the live `.env` on **2026-09-20** and is
   also `true` in this checkout's `.env`. Every § 1-§ 7 measurement below was taken with the
   flag **absent**; the § 9 re-run was taken with it **present and true**, and the flag-off
   tests still pass because they flip `cockpit.enabled` to `false` in-process rather than
   reading `.env`. The rollback in § 7 is therefore now a real one-line revert, not a no-op:
   remove the line, `config:clear`.
2. **The page § 1-§ 7 measured has been replaced.** The teal accordion is deleted. The live
   design contract is `.planning/sketches/004-delivery-cockpit/README.md`. The **server is
   still serving the old page** — the rebuild is committed locally and not pushed — so the
   human check in § 8 is to be done **locally**, before any redeploy.

---

**The flag state at the time of every § 1-§ 7 measurement:** `COCKPIT_ENABLED` is **absent from
`.env`**, so `config('cockpit.enabled')` resolves to its literal default `false`
(`config/cockpit.php:43`). It was never flipped during this phase. Tests that need the cockpit ON
flip it in-process with `config(['cockpit.enabled' => true])`.

---

## 1. The D-06 baseline, at end of phase

Run **character-identical** to the 12-path command in `45-BASELINE.md` — the same enumerated file
list Plan 45-01 measured with and Plan 45-04 re-asserted. The full suite is **not** a substitute:
its one aggregate number says nothing about this subset, and it carries a known pre-existing
failure that would mask a real regression inside the gate.

```
  Tests:    2 skipped, 159 passed (396 assertions)
  Duration: 37.52s
```

| Point in the phase | Measured | Failed | Errors | Assertions |
|---|---|---|---|---|
| **Baseline** — Plan 45-01, clean tree at HEAD `4abd2b24` | 159 passed, 2 skipped | 0 | 0 | 396 |
| **After the `InstallProgramme` split** — Plan 45-04 | 159 passed, 2 skipped | 0 | 0 | 396 |
| **End of phase** — Plan 45-08 (this run) | **159 passed, 2 skipped** | **0** | **0** | **396** |

**Verdict: PASS.** Same-or-greater pass count with zero failures. Not merely same-or-greater —
**identical**, assertion count included. D-06 holds: the `install_programmes` → `install_records`
split is behaviour-preserving.

**The gate is `>= 159 passed` AND `0 failed`. Never a hard equality against 161.** The 161 in
`45-RESEARCH.md` §5 was a static count of test *methods*; two of them self-skip on this machine
because `ext-imagick` is not loaded (both HEIC conversion tests, named in `45-BASELINE.md`). Both
skips are pre-existing and environmental, and they skipped identically on the clean pre-phase tree.
**Do not install `ext-imagick` to "fix" them** — that would move the gate to 161 and void the
before/after comparison.

---

## 2. The full suite

Run so the phase is not green only in its own corner.

```
  Tests:    2 deprecated, 1 failed, 10 warnings, 6 skipped, 2832 passed (11112 assertions)
  Duration: 563.32s
```

**The single failure is the documented pre-existing one** and is not a Phase 45 finding:

| Failure | File | Classification |
|---|---|---|
| `unhealthy queue runs restart and drain plan` | `tests/Feature/Queue/QueueRecoverCommandTest.php:163` | **Pre-existing.** A full-suite-only memory-threshold interaction (`EXIT_MEMORY_LIMIT` conflated with `EXIT_RECOVERY_FAILED`), described in that test's own comment at `:159-162` and in `.planning/STATE.md`. Out of scope — do not chase it. |

**No other new failure.** Phase 45's own tests are inside the 2,832 passes. The 4 additional skips
beyond the subset's 2 are elsewhere in the suite and are likewise pre-existing.

---

## 3. Build

The cockpit is a Vite entry (`resources/css/cockpit.css`, added by Plan 45-03). A deploy that skips
the build ships the page unstyled, and the cockpit page tests fail with a Vite manifest exception
rather than a Blade fault.

```
public/build/assets/cockpit-BLrY6Sj8.css   11.98 kB │ gzip: 2.54 kB
✓ built in 28.81s
```

**Clean.** `cockpit.css` is present in the manifest.

> Note for anyone re-running this: `npm run build` must go through the **Bash** tool on this
> machine. Via PowerShell it dies with `npm.ps1 cannot be loaded because running scripts is disabled
> on this system`. The reverse applies to PHP — see § 7.

---

## 4. The flag-off proof — `FlagOffBehaviourUnchangedTest`

`tests/Feature/Cockpit/FlagOffBehaviourUnchangedTest.php` — **11 passed, 66 assertions, 0 failed.**

| What is proven | How |
|---|---|
| The cockpit route 404s with the flag off | `GET projects/{project}/cockpit` → `assertNotFound()` |
| `route('projects.cockpit', …)` still resolves | Asserted explicitly, so nobody "tidies up" by wrapping `Route::get()` in an `if` — that would throw `RouteNotFoundException` instead of 404ing |
| No cockpit style reaches the eleven-tab project page | Page renders 200 with `ws-tab` present and none of `cav-cockpit`, `cav-brand`, `cockpit.css`, `--cav-`, `class="cav-` |
| No cockpit style reaches the dashboard | Page renders 200 with `dash-health-grid` present, same marker scan |
| The public token routes are unchanged (VIS-03, re-asserted at end of phase) | `GET /survey/{token}` and `GET /worksheet/{token}` → 200 with their key page markers; **both access tokens byte-identical afterwards** |
| The new data is **inert** | 3 `visits` rows seeded, then the eleven-tab page compared **byte-for-byte** against its pre-seed render (per-request noise normalised), and the cockpit still 404s |
| The three shared-presentation files are byte-identical to pre-phase | `hash_file('sha256', …)` vs the hashes recorded in `45-BASELINE.md` at HEAD `4abd2b24` |

### The three sha256 comparisons — the real teeth of criterion 4

Re-measured on the **working tree** with the same `Get-FileHash -Algorithm SHA256` command Plan
45-01 used, and independently re-asserted inside the test via `hash_file()`.

| File | Pre-phase (HEAD `4abd2b24`) | End of phase | Result |
|---|---|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…FF0557` | `9ED63C4C…FF0557` | **PASS — identical** |
| `resources/css/app.css` | `EDAD1982…BE2133` | `EDAD1982…BE2133` | **PASS — identical** |
| `tailwind.config.js` | `73BB8AD6…8E74BB` | `73BB8AD6…8E74BB` | **PASS — identical** |

Full values are in `45-BASELINE.md` § Pre-phase file hashes and hard-coded in the test's
`PRE_PHASE_HASHES` constant with the SHA they came from.

These three files are the only ones that could retone **every** page in the application at once.
Pinning them is what actually makes "with the flag off nothing changes" true rather than hopeful:
the 21CAV brand tokens live in the cockpit's own scoped stylesheet (D-07), not in the shared
`:root`.

**COMPARE LIKE WITH LIKE.** These are hashes of **working-tree bytes on a CRLF Windows checkout**.
A `git hash-object` value, or a hash of an LF-normalised copy, will **not** match and would read as
a false-positive change.

**A MISMATCH IS A STOP CONDITION, NOT A HASH TO UPDATE.** If a future run fails one of these,
Phase 45 (or a later phase) has retoned the whole app. Revert the file. Do not edit the constant.

### One assertion that is knowingly vacuous — do not count it as coverage

The test asserts that the flag-off **404 response body** contains no `cav-` class. A 404 page
contains no cockpit markup by construction, so **that assertion can never fail**. It is harmless,
it is cheap, and it documents intent, so it stays — but it proves nothing.

**Criterion 4's real protection is the three sha256 comparisons above plus the D-06 baseline in
§ 1.** Both are recorded as such in the test's own class docblock so a later reader cannot
over-credit the 404 check.

---

## 5. Why this phase writes `install_records` rows with the flag off

Carried forward verbatim in substance from `45-04-SUMMARY.md` § "Why this plan writes rows with the
flag off", so a Phase 46 verifier finds it in the proof document rather than re-deriving it.

**The fact:** after Plan 45-04, a user POSTing to `install-programmes.generate` inserts an
`install_records` row **while `COCKPIT_ENABLED` is false**. Read cold, that looks like a violation
of `45-CONTEXT.md`'s "the only writes are the backfill command and the split migration" and of
ROADMAP criterion 5's "this phase adds no new writes".

**It is not a violation, for four reasons:**

1. **No new user action, surface or capture.** The write sits on a **pre-existing** user-initiated
   code path (`InstallProgrammeService::createForProject()`), triggered by an action the user could
   already take before Phase 45. Criterion 5 and VIS-06 are about **new engineer-facing capture**;
   none was added. No new route, no new form, no new field.
2. **Derived bookkeeping, not captured content.** The row is one durable parent per project holding
   `id`, `project_id` and timestamps — nothing else. It records parentage that was already implicit
   in `install_programmes.project_id`. It exists because `archiveExisting()` + `createForProject()`
   replace the whole programme record on every regenerate, which would orphan visits filed under it
   (the D-06 problem the ROADMAP names).
3. **Inert with the flag off.** Nothing outside the cockpit reads `install_records`. Proven, not
   claimed: `FlagOffBehaviourUnchangedTest` renders the eleven-tab page and the dashboard with the
   new rows present and compares byte-for-byte against the pre-seed render.
4. **CONTEXT.md's "only writes" sentence is about new write *surfaces*.** This is an existing
   surface gaining a column's worth of parentage, not a new surface.

**Criterion 5 verdict: MET.** Additionally proven by Plan 45-07's five-table row-count invariance
test — rendering the cockpit writes nothing at all.

---

## 6. Why the cockpit is deliberately absent from `LabourResourceClientSurfacePrivacyTest`

`tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` scans a `CLIENT_FACING_PATHS`
list for leaked labour-resource contact details. The cockpit shows labour-resource contact details
and is **not** in that list. That silence looks like an oversight. **It is a decision.**

- The cockpit is a **staff-auth PM surface**: `ProjectCockpitController::show()` calls
  `abort_unless(auth()->check(), 403)` and its route lives inside the authenticated block
  (`routes/web.php:245-253`), not in the `survey/{token}` / `worksheet/{token}` public prefixes.
- That test file's own docblock at **`:55-61`** explicitly forbids adding staff-auth surfaces to the
  scan, using the `worksheets/{worksheet}/engineer-report.pdf` route as its worked example:
  *"Do not 'fix' this by adding it to the scan; that would misclassify a staff-only surface as
  client-facing."*
- Contact details are **legitimately visible to the PM** — exactly what LR-04 / D-04 sanction
  ("visible to the PM and admin only").

**The enumerated list is therefore correct and current, not stale.** Phase 45 adds **no**
client-facing surface at all. Phase 46 onward adds the client-facing surfaces that do belong in it.

**Phase 45 did not modify that test file, and a later agent must not add the cockpit path to it.**
The reasoning is also recorded at the top of
`tests/Feature/Visits/PublicTokenRoutesUnaffectedTest.php`.

---

## 7. Operator deploy checklist

`.env` and the production database both hold state the repo does not, so the order matters and the
flag flip is its **own decision, in its own deploy**. Never arm the flag in the same deploy as the
backfill: the cockpit renders against `visits`, which is empty until the backfill runs, and an
armed flag would show every PM an empty spine — reproducing verbatim the failure already written
down in this repo at `config/rams_tier1.php:119-134` ("a content gate defaulting ON is a
deploy-order trap").

1. **Ship the code** with `COCKPIT_ENABLED` **absent** from the live `.env`. The literal default is
   `false` (`config/cockpit.php:43`), pinned by `tests/Unit/CockpitFlagDefaultTest.php`.
2. `npm run build` — the cockpit is a Vite entry; skipping this ships the page unstyled.
3. `php artisan migrate` — `visits`, `install_records`, and the nullable FK on
   `install_programmes`.
4. `php artisan install-records:backfill` — **dry-run by default.** Read the counts.
5. `php artisan install-records:backfill --apply`
6. `php artisan visits:backfill` — **dry-run by default.** Read the counts.
7. `php artisan visits:backfill --apply`
8. **Verify row counts** before going further. Both commands are idempotent; a second `--apply`
   creates nothing (proven by `PublicTokenRoutesUnaffectedTest`). Both accept an optional project id
   to scope a cautious first run: `php artisan visits:backfill 123 --apply`.
9. **Only then**, as a separate one-line `.env` change: `COCKPIT_ENABLED=true`
10. `php artisan config:clear`

**To roll back:** remove the `COCKPIT_ENABLED` line, `php artisan config:clear`. The page 404s
again and everything else is untouched — that is precisely what § 4 proves. No migration rollback
and no data deletion is needed, because the `visits` rows are inert with the flag off.

> **PHP is not on the Bash tool's PATH on this machine.** A piped `php … | tail` through Bash exits
> 0 while executing nothing, faking a green gate. Every PHP/artisan command in this phase went
> through PowerShell with the explicit Herd binary:
> `& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan …`

---

## 8. Open — the human checkpoint (Task 3)

Two properties of the flag-**on** cockpit cannot be settled by any automated check, and
`45-UI-SPEC-v1-superseded.md` § Open Items 3 and 4 flag both as formally open (that file was the
unlabelled `45-UI-SPEC.md` until Plan 45-14 deleted the duplicate). They are the phase's **only**
human gate and remain **OPEN** at the time of writing:

1. **Greyscale state distinctness** — ***amended 2026-09-20 (D-10).*** The four pip/tick-box states
   this item was written about no longer exist: the pip and the hand-tick box were deleted in Plan
   45-11. The property is unchanged and now applies to the **three module status chips** — `Not
   started` (hollow ring), `In progress` (filled dot), `On file` (filled square). Each carries a
   distinct glyph SHAPE and its state in WORDS as well as its colour
   (`resources/views/components/cockpit/status-chip.blade.php`). Plus the provenance chips
   (`Reconstructed` / `Superseded` / `Not required` / `Record unavailable`), which are a separate
   vocabulary in a separate component by deliberate decision. If any two are confusable with colour
   removed, colour is doing work it must not do.
2. **The 320px count slot** — the count must remain visible at 320px. It is the only at-rest
   disclosure that a visit is reconstructed or superseded; hiding it would present an inferred
   visit as an unqualified fact. The sketch's `@media(max-width:480px){ .s,.c{display:none} }` must
   **not** be in effect (deviation signed off by the user on 2026-09-19).
   ***Reinforced 2026-09-20:*** Plan 45-13 found this exact disclosure had been **lost** in the
   rebuild — the module row had reverted to a bare `1 visit`. It was fixed in
   `CockpitModulePresenter`, and the row now reads `1 visit · reconstructed` /
   `3 visits · 2 superseded`. That is the string that must survive 320px. A regression that once
   shipped is worth looking at twice.

3. **The design comparison itself** — does the rebuilt page match
   `.planning/sketches/004-delivery-cockpit/delivery-cockpit.png`? No test can answer this, and the
   page is the one a project is delivered from.

### The differences from the design image that are DELIBERATE — not bugs

Enumerated so the checker can tell a decision from a defect at a glance.

| What the image shows | What the page shows | Why |
|---|---|---|
| Eight module rows | **Nine** — a Snagging row is added | **D-16.** The image showed eight rows but "1 of 9 complete". `Visit::TYPE_SNAG` already exists, so with eight rows a snag visit would sit in the database and appear on no screen. |
| `Actions ▾` dropdown | absent | A write affordance. Phase 46/48. |
| Quick actions tiles — Create visit / Add note / Upload files | absent | **D-15.** Writes: Phase 46 (visits, notes), Phase 48 (files). |
| `…` overflow control on activity rows | absent | A menu trigger, i.e. a write entry point. |
| Two stage chips | **one** | A project has exactly one `Project::status`. Two would mean inventing a second. |
| Site contact with an email | name and phone only | `SiteSurvey` stores no contact email. `pm_email` is the PM's; labelling it "Site contact" would be a lie. |
| "Proposed install date" | **"Planned start"** | No proposed-install-date field exists. The nearest real value is `InstallProgramme::planned_start_date`, which is a different fact and is labelled as itself. |
| Programming row with a count | chip, **no count phrase** | No Programming model or storage exists. A count would be fabricated. |
| Activity feed implied per-module | **project-wide** | `ProjectActivityLog` has no module column. |
| A JS slide-in panel | a **full page load** (`?module=…&tab=…`) | Phase 45 ships zero JavaScript. The trade, accepted at planning time: bookmarkable panel state and a working browser Back button. |

**Everything in the left-hand column is a decision with an owner.** Anything NOT in this table that
differs from the image is a finding — report it.

Steps are in `45-14-PLAN.md`'s checkpoint task, restated against the rebuilt page. `45-08-PLAN.md`
Task 3 is the original wording and describes a page that no longer exists. This document is to be updated with the observation once the
checkpoint is answered.

---

## 9. The 45-14 whole-phase re-run — MEASURED 2026-09-20, against the REBUILT page

Every number below was produced on the rebuilt cockpit (Plans 45-09..45-13), at a clean working
tree, with `COCKPIT_ENABLED=true` present in `.env`.

| Gate | Command | Result |
|---|---|---|
| **D-06 baseline** | the 12-path enumerated command from `45-BASELINE.md`, character-identical | **`2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed` → **PASS**. Identical to 45-01, 45-04 and 45-08. |
| **Cockpit suite** | `artisan test tests/Feature/Cockpit tests/Unit/Cockpit` | **`157 passed (1646 assertions)`**, 8 files, zero failures |
| **Full suite** | `artisan test` | **`2934 passed (12345 assertions)`, 1 failed, 6 skipped, 679s** |
| **Build** | `npm run build` (Bash — npm is blocked by PowerShell execution policy) | **`assets/cockpit-B6wRFpLO.css  16.12 kB`**, present in `public/build/manifest.json` under `resources/css/cockpit.css`. Clean. |
| **The three sha256 pins** | `Get-FileHash -Algorithm SHA256` on the working tree, the same command 45-01 used | **all three identical to `45-BASELINE.md`** |

The single full-suite failure is the **same pre-existing one named in § 2** —
`QueueRecoverCommandTest > unhealthy queue runs restart and drain plan`, the full-suite-only
memory-threshold interaction described in that test's own comment. It is not a Phase 45 finding and
was not touched. Naming it here rather than absorbing it silently is the point: an unexplained red
line in a close-out run is how a real regression gets waved through next time.

Pass count moved from 2,832 (45-08) to 2,934 — the +102 are Plans 45-09..45-13's own tests.

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

### The local database was EMPTY — a fixture was built for the human check

`database/database.sqlite` held **0 projects, 0 users, 0 visits, 0 surveys**. A visual check against
an empty database would have shown nine identical `Not started / 0 visits` rows and **would never
have rendered the one thing § 8 item 2 exists to protect** — the reconstructed / superseded count
phrase. So a fixture was seeded from the scratchpad (not a repo seeder, not committed):

- **Project 3, "Riverside Media Suite"**, status `installing`
- a submitted `SiteSurvey` (site contact **Marie Okafor**, `07700 900312`) so the masthead contact
  line has real content
- an `InstallProgramme` with `planned_start_date = 2026-09-08` so **"Planned start"** renders — the
  label that is deliberately NOT "Proposed install date"
- **five visits**: a survey visit reconstructed from the survey, an install visit reconstructed from
  a signed worksheet, a **superseded** install visit (its worksheet soft-deleted), a future
  commissioning visit so the "Next visit" KPI card has something to say, and a **snag** visit so the
  D-16 ninth row has a reason to exist on screen
- a worksheet that is **ready for signing but not signed** — the ONLY state in
  `CockpitSectionPresenter` that yields pip `attention`, i.e. the `In progress` chip. Without it the
  greyscale check would only ever have seen two of the three chips.
- two RAMS documents, an O&M manual, a cable schedule, and four `ProjectActivityLog` entries

Measured on the rendered page (HTTP 200, authenticated, via the real HTTP kernel):

| Rendered | Count |
|---|---|
| `Not started` / `In progress` / `On file` chips | **4 / 1 / 4** — nine rows, all three chip variants on screen at once |
| `Open drawer` controls | 18 (nine rows x aria-label + text) |
| At-rest count phrases | **`1 visit · reconstructed`**, **`2 visits · reconstructed · superseded`**, `1 visit` |
| `Planned start` | 1 |
| `Marie Okafor` | 1 |
| `Snagging` | 2 |
| `?module=worksheet` / `&tab=files` / `?module=rams&tab=files` / `?module=site_survey&tab=notes` | all **200**, panel rendered |
| the bare URL | 200, **no panel element in the DOM** |

**The module key for "First fix and install" is `worksheet`, not `install`.** `?module=install` is
not a module key: it renders the page with no panel open. Anyone writing checkpoint steps by
guessing keys from the row titles will get a closed panel and report a bug that is not there.

**To remove the fixture:** it is one project. `Project::find(3)` and its rows; or delete
`database/database.sqlite` and re-migrate, since it held nothing else.

---

## Provenance

| Item | Value |
|---|---|
| Measured by | Plan 45-08, Tasks 1 and 2 (§ 1-§ 7); Plan 45-14 (§ 0, § 8's amendments, § 9) |
| Date | 2026-09-19, amended 2026-09-20 |
| Baseline reference | `45-BASELINE.md` — HEAD `4abd2b24`, 159 passed / 2 skipped / 0 failed |
| PHP binary | `%USERPROFILE%\.config\herd\bin\php84\php.exe` (PowerShell — mandatory) |
| Build | `npm run build` via Bash (npm is blocked by PowerShell execution policy here) |
| Flag state | § 1-§ 7: `COCKPIT_ENABLED` absent from `.env` → `false`. § 9: present and `true`, in `.env` and in production since 2026-09-20. |

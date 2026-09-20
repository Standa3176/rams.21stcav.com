---
phase: 45-visit-model-read-only-cockpit
plan: 14
subsystem: phase-close-out
tags: [gate, documentation, checkpoint-open, sketch-004, d-06, sha256-pins]
status: AUTOMATABLE WORK COMPLETE — THE HUMAN CHECKPOINT IS OPEN
requires:
  - "The rebuilt cockpit and its reconciled tests (Plans 45-09..45-13)"
  - "The measured D-06 baseline and the three pre-phase hashes (45-BASELINE.md)"
provides:
  - "The whole-phase gate re-run against the REBUILT page — D-06 159/0, cockpit 157, full suite 2934/1 known, three sha256 pins held, build clean"
  - "One UI spec in the phase folder instead of two, and the surviving one says SUPERSEDED in its own text"
  - "A ROADMAP that records the 2026-09-20 replacement with its cause, not just the original eight plans"
  - "A seeded local fixture so the human check has a page with real data on it"
affects:
  - "The user — the blocking visual check is theirs and is not self-approvable"
  - "Phase 46 — inherits an accurate planning record and the 18-affordance fence"
tech-stack:
  added: []
  patterns:
    - "an empty verification environment is a checkpoint that cannot be answered — prepare the data, not just the steps"
    - "amend a proof document, never rewrite it: its reasoning outlives its measurements"
key-files:
  created:
    - .planning/phases/45-visit-model-read-only-cockpit/45-14-SUMMARY.md
  modified:
    - .planning/ROADMAP.md
    - .planning/REQUIREMENTS.md
    - .planning/phases/45-visit-model-read-only-cockpit/45-FLAG-OFF-PROOF.md
    - .planning/phases/45-visit-model-read-only-cockpit/45-UI-SPEC-v1-superseded.md
  deleted:
    - .planning/phases/45-visit-model-read-only-cockpit/45-UI-SPEC.md
decisions:
  - "The checkpoint was NOT self-approved. Greyscale, 320px and 'does it match the design' are judgements, and no test in this repo settles any of them."
  - "VIS-04 and VIS-10 are recorded as awaiting the human check rather than Complete — the same discipline 45-13 enforced on the Programming row"
  - "The local sqlite DB was EMPTY, so a fixture was seeded; without it the count phrase and the In-progress chip would never have rendered and the check would have passed vacuously"
  - "The 45-08 entry in the ROADMAP still calls itself the only human checkpoint; it was left as written and the move is recorded alongside it, because executed history is not tidied"
metrics:
  duration: ~75 min
  completed: 2026-09-20 (automatable tasks only)
  tasks: 1 of 2 — the checkpoint is open
  commits: 2
---

# Phase 45 Plan 14: Whole-phase gate, documentation close-out, and the open checkpoint — Summary

Everything a machine can settle about Phase 45 is settled and green. The one thing it cannot —
whether the page a PM now opens is the design the user accepted — is deliberately left open.

## The gates, verbatim

| Gate | Result |
|---|---|
| **D-06 baseline**, the 12-path enumerated command from `45-BASELINE.md`, character-identical | **`Tests: 2 skipped, 159 passed (396 assertions)`** → `>= 159 passed AND 0 failed` **PASS** |
| `artisan test tests/Feature/Cockpit tests/Unit/Cockpit` | **`Tests: 157 passed (1646 assertions)`** — 8 files, zero failures |
| `artisan test` (full suite) | **`Tests: 2 deprecated, 1 failed, 10 warnings, 6 skipped, 2934 passed (12345 assertions)` — 679.09s** |
| `npm run build` | **`assets/cockpit-B6wRFpLO.css  16.12 kB`**, present in `public/build/manifest.json` |
| The three sha256 pins, working-tree `Get-FileHash` | **all three identical to `45-BASELINE.md`** |

The one full-suite failure is `QueueRecoverCommandTest > unhealthy queue runs restart and drain
plan` — the documented pre-existing full-suite-only memory-threshold interaction, described in that
test's own comment and in `45-BASELINE.md`. **Named, not absorbed.** It was not touched.

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

Compared like with like — working-tree bytes on a CRLF checkout, the same `Get-FileHash` command
Plan 45-01 used. A `git hash-object` value would not have matched and would have read as a false
positive.

## The finding that mattered: the verification environment was empty

`database/database.sqlite` held **0 projects, 0 users, 0 visits, 0 surveys**. The user could not have
logged in, let alone compared anything.

That is not a small inconvenience. An empty database renders **nine identical `Not started / 0
visits` rows**. The reconstructed/superseded count phrase — the one at-rest disclosure the 320px
check exists to protect, and the exact thing Plan 45-13 found had regressed — would never have
appeared. **The checkpoint would have been answerable, and the answer would have been meaningless.**

So a fixture was seeded from the scratchpad (not a repo seeder, nothing committed): project **3,
"Riverside Media Suite"**, with a submitted survey, a programme with a planned start, five visits
(one reconstructed from the survey, one from a signed worksheet, one superseded, one future
commissioning visit, one snag), two RAMS documents, an O&M manual, a cable schedule and four
activity entries.

One detail took a second pass. `In progress` is reachable from exactly **one** state in
`CockpitSectionPresenter` — a worksheet that is ready for signing and not yet signed. The first
fixture had none, so only two of the three chips rendered, and a greyscale check on two chips proves
nothing about the third. A ready-for-signing worksheet was added.

Measured on the rendered page, authenticated, through the real HTTP kernel:

| Rendered | Count |
|---|---|
| `Not started` / `In progress` / `On file` | **4 / 1 / 4** — nine rows, all three variants on screen at once |
| At-rest count phrases | **`1 visit · reconstructed`**, **`2 visits · reconstructed · superseded`**, `1 visit` |
| `Planned start` / `Marie Okafor` / `Snagging` | 1 / 1 / 2 |
| bare URL | 200, **no panel element in the DOM** |
| `?module=worksheet`, `&tab=files`, `?module=rams&tab=files`, `?module=site_survey&tab=notes` | all **200**, panel rendered |

**`?module=install` is not a module key.** "First fix and install" is keyed `worksheet`. It renders
200 with no panel, which reads exactly like a broken panel to anyone who guessed the key from the
row title.

## Documentation close-out

**`45-UI-SPEC.md` deleted.** It and `45-UI-SPEC-v1-superseded.md` were byte-identical 43,150-byte
copies (sha1 `11d3bf6e…`) and only one of them was labelled. Verified before deleting: the twin
exists, and `.planning/sketches/004-delivery-cockpit/README.md` is ACCEPTED as the live contract.

**The surviving copy is retained deliberately, and it now says so in its own text** rather than only
in its filename. Its constraint analysis is still factually correct and is cited by Plans
45-09..45-13: the `.cav-brand` token-scoping mechanism, the `.btn`/`.card` collision hazards in the
2,154-line layout, the `@stack('styles')` seam, the read-only fence enumeration. The banner points
readers at those and away from its palette, typography and layout.

**ROADMAP.** The Phase 45 entry gained a dated paragraph recording that the user supplied and
accepted a new design on 2026-09-20, that sketch 004 supersedes 002 and reverses D-07, and that
45-09..45-14 replace the page rather than the model. The six new plans are listed with waves and
objectives. The original eight entries are untouched. Criterion 3 gained a dated parenthetical
naming D-09 as a change in **form** — the spine is a module list and the drawer is a side panel —
while its substance (read-only, flag-gated, own route, nothing open at rest) is intact and named to
the test that asserts it.

**REQUIREMENTS.** VIS-01..VIS-10 traceability now names the new plans. **VIS-04 and VIS-10 are
recorded as awaiting the 45-14 human check, not Complete.** Marking them complete would claim a
milestone no test in this repo evidences.

**`45-FLAG-OFF-PROOF.md` amended, not rewritten.** 45-08's reasoning is carried intact — § 5's
criterion-5 reconciliation of the flag-off `install_records` write, and § 4's ruling that the
"404 body has no `cav-` class" assertion is **vacuous** and that criterion 4's real protection is
the three sha256 pins plus the D-06 baseline. Added: a new § 0 recording the two facts that changed
since 45-08 (the flag is now `true`; the measured page has been replaced), a § 8 restated against
the three status chips instead of the deleted pip/tick-box vocabulary plus the
deliberate-differences table, and § 9 with the 45-14 measurements.

## Deviations from Plan

### 1. [Rule 3 — the task could not be completed as written] The empty database

The plan's checkpoint says "open a project that has a survey, a signed worksheet and at least one
RAMS document". No such project existed; no project existed at all. Seeding a fixture was the only
way to make the checkpoint answerable. Confined to the scratchpad and to the local dev DB — no
seeder, no migration, nothing committed, and removable by deleting one project.

### 2. [Plan text corrected] The requirement IDs in Task 1

Task 1's action says to update "VIS-04, VIS-06 and VIS-10"; the plan's own frontmatter says
`[VIS-04, VIS-05, VIS-10]`. All ten rows were updated, which satisfies both readings and leaves no
row claiming a status the phase cannot evidence.

### 3. [Scope — added] `45-UI-SPEC-v1-superseded.md` given a SUPERSEDED banner

The plan asked only that the unlabelled copy be deleted. A file whose only warning is its filename
still opens as a confident 43KB design contract. The banner makes the document self-describing and
directly serves threat T-45-14-01.

## Still OPEN — the blocking human checkpoint

**Not self-approved, and not approvable by any agent.** Greyscale distinctness, usability at 320px
and "does this match the accepted design" are judgements. The steps, the URL, the deliberate
differences and the fixture are all prepared; the answer is the user's.

## Known Stubs

None introduced. This plan changed no application code.

## Threat Flags

None. No rendering surface, route or input was added.

## Self-Check: PASSED

`45-UI-SPEC.md` is gone and `45-UI-SPEC-v1-superseded.md` is present. The ROADMAP names 45-09..45-14
and cites `004-delivery-cockpit`. REQUIREMENTS names the new plans. Both commits (`5c2262ac`,
`5b8293b0`) are in `git log`. The only tracked file deleted is the duplicate spec this plan
deliberately removed. `.planning/STATE.md` was not touched — neither `state.advance-plan` nor
`state.update-progress` was run.

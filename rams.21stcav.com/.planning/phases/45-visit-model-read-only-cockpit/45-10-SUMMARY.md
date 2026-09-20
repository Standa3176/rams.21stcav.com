---
phase: 45-visit-model-read-only-cockpit
plan: 10
subsystem: presentation-derivation
tags: [presenter, read-only, d-10, d-11, d-12, d-16, no-fabricated-data]
requires:
  - "CockpitSectionPresenter (Plan 45-07) — the nine sections and their pip state"
  - "Visit model + TYPES/STATUSES (Plan 45-02)"
provides:
  - "CockpitModulePresenter::modules() — the nine delivery-cockpit module rows (D-10, D-11 as amended by D-16)"
  - "CockpitModulePresenter::progress() — per-module visit progress, null when nothing is planned"
  - "CockpitHeaderPresenter::masthead() / kpis() / stageChips() — the page header (D-12)"
  - "A computed documents denominator, pinned by test to the rendered module count"
affects:
  - "Plans 45-11..45-14 — the Blade layer renders from these two presenters"
  - "Phase 46 (visits) / 47 (snags) — the Snagging row is the home D-16 gave them"
tech-stack:
  added: []
  patterns:
    - "composition over extension — the 45-07 derivations are translated, never re-derived"
    - "const MODULE_MAP as data, so invariants are provable by iteration rather than sampleable by a match arm"
    - "absent-key masthead — a sourceless fact is omitted, never nulled or zeroed"
key-files:
  created:
    - app/Support/Cockpit/CockpitModulePresenter.php
    - app/Support/Cockpit/CockpitHeaderPresenter.php
    - tests/Unit/Cockpit/CockpitModulePresenterTest.php
    - tests/Unit/Cockpit/CockpitHeaderPresenterTest.php
    - .planning/phases/45-visit-model-read-only-cockpit/45-10-SUMMARY.md
  modified: []
decisions:
  - "D-16 applied as given — nine module rows with Snagging last; the checkpoint was pre-answered by the user on 2026-09-20 and was not re-opened"
  - "The documents denominator is modules()->count(), asserted by test; a literal 9 would be a defect"
  - "No contact_email key exists in any project state — pm_email is the project manager's address, not the site contact's"
  - "planned_start is InstallProgramme::planned_start_date under the label 'Planned start'; the design phrase 'Proposed install date' appears nowhere"
  - "stageChips() returns exactly one chip — a project has exactly one Project::status; the design's second chip has no source"
  - "Programming's count phrase is the empty string — a '0 files' would claim a file store that ProjectDeliverable.php:14-26 says does not exist and forbids building"
  - "STAGE_LABELS is a private map rather than a reuse of Project::STATUS_LABELS — coupling the cockpit's copy to the dashboard's badge copy would make either change move the other (criterion 4)"
metrics:
  duration: ~50 min
  completed: 2026-09-20
  tasks: 2
  commits: 4
---

# Phase 45 Plan 10: Cockpit module and header presenters — Summary

Two `final` read-only presenters that turn sketch 004's module list and KPI cards into pure PHP
derivations: nine module rows whose chip is a strict translation of `CockpitSectionPresenter`'s
existing `pip`, and a header whose documents denominator is computed from the rendered module count
while its three sourceless design values are omitted and pinned absent by test.

## Tasks

| # | Task | Commits |
|---|------|---------|
| 0 | `checkpoint:decision` — eight rows or nine | **Pre-answered by the user (D-16). Not re-opened.** |
| 1 | `CockpitModulePresenter` + unit tests | `ff778e58` (RED), `4cd08b47` (GREEN) |
| 2 | `CockpitHeaderPresenter` + unit tests | `9968d46c` (RED), `fb994f9c` (GREEN) |

No REFACTOR commit: neither class needed cleanup after GREEN, and a no-op refactor commit would
have been noise in the TDD gate sequence.

## The checkpoint, and why it did not stop the run

The plan opens with a `checkpoint:decision` asking whether the module list is eight rows or nine.
The user ruled on 2026-09-20 in favour of **nine — `nine-modules`** — recorded as **D-16** in both
`.planning/sketches/004-delivery-cockpit/README.md` and `45-CONTEXT.md`. The executor was told so in
its prompt, so the checkpoint was treated as already resolved rather than re-litigated.

The deciding argument, preserved verbatim in the `CockpitModulePresenter` class docblock, was **not
the arithmetic**. `Visit::TYPE_SNAG` already exists, so with eight rows a snag visit would sit in the
database and appear on no screen — the exact "collected but never turned into work" failure this
milestone exists to fix. Nine rows also preserve the invariant that every visit type reaches exactly
one module row, and give Phase 47 a home to build into.

## Task 1 — `CockpitModulePresenter`

### Nine rows, in D-11's order as amended by D-16

`site_survey` · `worksheet` · `install_programme` · `rams` · `drawings` · `om` · `cable_schedule` ·
`programming` · **`snagging`**

Two tests hold that order down: `test_nine_modules_render_in_the_designed_order()` asserts the exact
sequence, and `test_the_nine_module_keys_are_the_canonical_deliverable_vocabulary()` asserts the set
equals `ProjectDeliverable::ALL_KEYS` — so the cockpit and the deliverables selection screen can
never drift into naming different things.

### The chip is a translation, not a second opinion

`chip` is a const-map lookup over the section's existing `pip`:

| `pip` | `chip` |
|---|---|
| `waiting` | `not-started` |
| `attention` | `in-progress` |
| `done` | `on-file` |
| `null` (Programming — no derivation exists) | `not-started` |

`test_chip_is_a_pure_translation_of_the_section_pip()` walks every rendered row and asserts the chip
equals the translation of that row's own `section['pip']`, so a future edit that re-derives state
here instead of translating it fails immediately.

### Where every count comes from

| Module(s) | Count mode | Source |
|---|---|---|
| Site survey, First fix and install, Snagging | `visits` | the section's own visit collection |
| Programme and commissioning | `tasks` | `InstallTask` rows under the project's install programmes |
| RAMS, Drawings, O&M manual, Cable schedule | `documents` | the project relation named in `MODULE_MAP` |
| **Programming** | `none` | **nothing — the count phrase is `''`** |
| any module marked not required | — | the phrase `Not required`, mirroring what 45-07 already decided |

### The visit-type invariant, proven over the constant

```php
foreach (Visit::TYPES as $type) {
    $owners = [];
    foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
        if (in_array($type, $definition['visit_types'], true)) {
            $owners[] = $key;
        }
    }
    $this->assertCount(1, $owners, "Visit type '{$type}' must reach exactly one module row…");
    $this->assertNotNull($modules->firstWhere('key', $owners[0]), …);
}
```

`MODULE_MAP` is a `const` array rather than a `match`, precisely so this can iterate it as data. A
seventh visit type added in Phase 46 fails this test loudly instead of rendering nowhere.

### `progress()` returns null rather than a 0-of-0 ring

A module with no visits returns `null`, so the panel omits the ring. Drawing an empty circle would
read as "nothing done" when the truth is "nothing planned" — a different fact about a project, and
the more alarming of the two.

## Task 2 — `CockpitHeaderPresenter`

### What was rendered honestly rather than invented

| Design element | What the design asked for | What shipped, and why |
|---|---|---|
| Site contact email | an email beside the contact name | **Omitted.** `SiteSurvey` has `site_contact_name` and `site_contact_phone` only. `pm_email` is the project manager's address; under a "Site contact" label it would be a lie. There is **no `contact_email` key in any project state**, and a test asserts the literal `pm@example.com` does not appear anywhere in the masthead. |
| "Proposed install date" | a date labelled that way | **Relabelled, not faked.** The nearest value is `InstallProgramme::planned_start_date` — a different fact, set by a different act. It ships as `planned_start` under "Planned start" only. A test json-encodes all three public outputs and asserts the phrase "Proposed install" appears nowhere. |
| Two stage chips | "Survey Pending" **and** "Installation phase" | **One chip.** A project has exactly one `Project::status` and no second source exists. A test iterates all eight `Project::STATUS_*` values and asserts `count === 1` each time; a separate test asserts an unrecognised status yields `[]` rather than a raw enum on screen. |
| "Documents n of 9 complete" | a 9 | **A computed denominator** — see below. |
| Programming count | "0 files" in D-10's vocabulary | **No count phrase at all.** `ProjectDeliverable.php:14-26` states there is no Programming model, generator or storage type, and forbids building one. "0 files" would claim a file store exists. |
| Overall status when health cannot be derived | — | **Never "On track".** The card reports "The status summary could not be read." A null health and a healthy project are different facts, and conflating them would hide exactly the projects a PM most needs to look at. |
| Next visit when none is planned | — | **"None planned"**, with no `date` key at all — a completed visit is never shown as the next one, and a planned visit with a null `scheduled_date` is unscheduled, not next. |

### The denominator is computed, and the test proves it

```php
private function documentsCard(Project $project): array
{
    $modules  = $this->modules->modules($project);
    $total    = $modules->count();
    $complete = $modules
        ->filter(fn (array $row): bool => $row['chip'] === CockpitModulePresenter::CHIP_ON_FILE)
        ->count();

    return [
        'complete' => $complete,
        'total'    => $total,
        'percent'  => $total === 0 ? 0 : (int) floor(($complete / $total) * 100),
    ];
}
```

The test that pins it (threat `T-45-10-01`):

```php
public function test_the_documents_denominator_is_the_rendered_module_count(): void
{
    $project = $this->project();
    $modules = new CockpitModulePresenter(new CockpitSectionPresenter());

    $card = $this->presenter()->kpis($project->fresh(), null)['documents'];

    $this->assertSame(
        $modules->modules($project->fresh())->count(),
        $card['total'],
        'The denominator must be the number of module rows actually rendered, never a literal.'
    );
}
```

"Complete" means the module's chip is `on-file`, which is `pip === 'done'` from the existing section
presenter. `ProjectDeliverable` has no complete state — only `required` / `not_required` /
`not_yet_decided` — so that is the only defensible reading in this codebase today.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 3 — Blocking] `deliverableState()` needs `deliverables` eager-loaded, so the not-required test wired it**

- **Found during:** Task 1, GREEN (19/20 passing — the not-required row read `0 documents`).
- **Issue:** `Project::deliverableState()` returns `null` unless the `deliverables` relation is
  loaded (`Project.php:481-492`), by deliberate design — its docblock makes eager-loading the
  caller's job, and `ProjectCockpitController::show()` does it. The first draft of the test passed a
  bare `$project->fresh()`, so the section presenter saw `not_required === false`.
- **Fix:** the test now calls `loadMissing('deliverables')` exactly as the controller does, with a
  comment citing the docblock line numbers. **The presenter was not changed to query** — adding a
  lazy load there would have put a query inside a presenter whose whole contract is that the
  controller wires and the presenter derives.
- **Files modified:** `tests/Unit/Cockpit/CockpitModulePresenterTest.php`
- **Commit:** `4cd08b47`

**2. [Scope note] The plan's verify command for the guarded files is mis-specified, and was re-run against the correct ref**

- **Issue:** the plan asks that
  `git diff --name-only 4abd2b24 -- ProjectHealthService.php ProjectHealth.php CockpitSectionPresenter.php`
  be **empty**. It cannot be: `CockpitSectionPresenter.php` did not exist at `4abd2b24` (it was
  created in Plan 45-07), so it shows as an addition regardless of what this plan does. Asserting it
  as written would have produced a permanently red gate — the failure mode `45-BASELINE.md` warns
  about at length.
- **Resolution:** run as two checks. Against `4abd2b24`, `ProjectHealthService.php` and
  `ProjectHealth.php` are **empty** — untouched since the phase baseline. Against `27877b06` (HEAD
  immediately before this plan), **all three are empty** — this plan modified none of them. Results
  in the verification table below.

### Not a deviation — the checkpoint

The `checkpoint:decision` was not returned to the user because it had already been answered (D-16,
2026-09-20). Executing straight through was the instructed behaviour, not an auto-approval.

## Deferred Issues

- **`VIS-04` and `VIS-06` were deliberately NOT marked complete.** Both describe what the *cockpit
  page renders*, and this plan ships no Blade — the markup is 45-11 onward. Marking them here would
  claim delivery of a page that does not exist yet. Same reasoning 45-09 applied to VIS-10.
- **`cockpit.css` still carries superseded literals and a dead `@fontsource/poppins` import** (noted
  in 45-09's deferred list). Untouched here — this plan ships no CSS and `cockpit.css` is not in its
  `files_modified`.

## Verification

| Check | Result |
|---|---|
| `artisan test tests/Unit/Cockpit/CockpitModulePresenterTest.php` | `Tests: 20 passed (124 assertions)` |
| `artisan test tests/Unit/Cockpit/CockpitHeaderPresenterTest.php` | `Tests: 16 passed (48 assertions)` |
| `artisan test tests/Unit/Cockpit tests/Feature/Cockpit` | **`Tests: 91 passed (585 assertions)`** |
| **D-06 baseline re-run** (the 12-path enumerated command, character-identical) | **`Tests: 2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed` **PASS** |
| `git diff --name-only 4abd2b24 -- ProjectHealthService.php ProjectHealth.php` | **empty — PASS** |
| `git diff --name-only 27877b06 -- ProjectHealthService.php ProjectHealth.php CockpitSectionPresenter.php` | **empty — PASS** (this plan modified none of the three) |
| `grep -nE '#[0-9A-Fa-f]{6}' app/Support/Cockpit/*.php` | **no match — PASS** (no colour literal in a presenter) |
| `Get-FileHash -Algorithm SHA256` on the three protected presentation files | **all three match `45-BASELINE.md` exactly** |
| `git diff --name-only 27877b06 HEAD` | exactly the four files in `files_modified` |
| `.planning/STATE.md` | **clean** — neither `state.advance-plan` nor `state.update-progress` was run |

Protected-file hashes (working-tree bytes, compared like-with-like per the baseline's CRLF warning):

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

No migration was run. No package was installed. `migrate:fresh --env=testing` was never invoked.

## TDD Gate Compliance

Both tasks ran RED → GREEN with the gates as separate commits:

| Task | RED (`test(...)`) | GREEN (`feat(...)`) |
|---|---|---|
| 1 | `ff778e58` — `20 failed (0 assertions)`, `Class "App\Support\Cockpit\CockpitModulePresenter" not found` | `4cd08b47` — `20 passed` |
| 2 | `9968d46c` — `16 failed (0 assertions)` | `fb994f9c` — `16 passed` |

No test passed unexpectedly during either RED phase.

## Known Stubs

None. Both classes are complete derivations over existing data. The values the design asked for that
have no source are **omitted and asserted absent**, which is the opposite of a stub — there is no
placeholder for a later plan to "fill in", because filling one in would be the fabrication these
tests exist to prevent.

## Threat Flags

None. No route, controller, input path, auth path or query surface is added by this plan. The two
classes are project-scoped Eloquent reads with no user input reaching them.

## Self-Check: PASSED

All five files exist on disk. All four task commits (`ff778e58`, `4cd08b47`, `9968d46c`, `fb994f9c`)
are present in `git log --all`. No tracked file was deleted by any of them.

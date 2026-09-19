---
phase: 45-visit-model-read-only-cockpit
plan: 06
subsystem: cockpit
tags: [route, controller, blade, feature-flag, read-only, accessibility]
requires: [45-02, 45-03]
provides:
  - "GET projects/{project}/cockpit — route name projects.cockpit, flag-gated, GET-only"
  - "app/Http/Controllers/ProjectCockpitController — show() only, 404 then 403 gates"
  - "resources/views/projects/cockpit.blade.php — the cav-brand cav-cockpit page shell"
  - "x-cockpit.masthead / attention / section-group — page furniture"
  - "x-cockpit.pip / tick-box / chip / tag / hint — the state primitives 45-07 consumes"
affects: [45-07, 45-08, 46, 47, 48, 51]
tech-stack:
  added: []
  patterns:
    - "route registered unconditionally, flag enforced in the controller (SpikeSchematicController precedent)"
    - "read-only fence asserted against the DOM-extracted cav-cockpit subtree, not the whole document"
    - "state carried on three channels — shape, visible text, accessible name"
key-files:
  created:
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/projects/cockpit.blade.php
    - resources/views/components/cockpit/masthead.blade.php
    - resources/views/components/cockpit/attention.blade.php
    - resources/views/components/cockpit/section-group.blade.php
    - resources/views/components/cockpit/pip.blade.php
    - resources/views/components/cockpit/tick-box.blade.php
    - resources/views/components/cockpit/chip.blade.php
    - resources/views/components/cockpit/tag.blade.php
    - resources/views/components/cockpit/hint.blade.php
    - tests/Feature/Cockpit/CockpitPageTest.php
  modified:
    - routes/web.php
decisions:
  - "The health-unavailable copy is split across the attention line's required <h2> and its one paragraph rather than rendered as one run of text — the structure is mandated by the layout contract, the words are verbatim"
  - "The attention line's plural branch is implemented although ProjectHealth returns one reason, so a later phase inherits settled copy instead of a guess"
  - "tick-box ships the ticked branch unreachable and says so in its own comment, per the UI-SPEC ruling"
metrics:
  duration: ~50 min
  completed: 2026-09-19
---

# Phase 45 Plan 06: Cockpit Route, Shell and State Primitives Summary

The read-only project cockpit now exists as a page: one GET route behind `COCKPIT_ENABLED`, a
controller with no write method, a Blade shell that loads its own stylesheet through the layout's
`@stack('styles')` seam without touching the layout, and the five presentational primitives Plan
45-07's drawers will consume.

## What Was Built

| Task | What | Commit |
|------|------|--------|
| — | Failing test first (RED, 22 failed) | `c362f307` |
| 1 | Route + `ProjectCockpitController` | `9f563920` |
| 2 | Page shell, masthead, attention line, section groups | `1abf21d2` |
| 3 | State primitives — pip, tick-box, chip, tag, hint | `8f4f89e6` |
| — | Test copy assertion matched to the rendered structure | (follow-up test commit) |

### Task 1 — route and controller

`GET projects/{project}/cockpit` → `ProjectCockpitController@show`, named `projects.cockpit`,
registered **unconditionally** inside the existing `Route::middleware('auth')` group at
`routes/web.php:169`, immediately after `projects.reopen`. `artisan route:list --name=projects.cockpit`
reports `GET|HEAD` and nothing else.

The controller gates in the order the plan specifies — `abort_unless(config('cockpit.enabled'), 404)`
then `abort_unless(auth()->check(), 403)` — matching `SpikeSchematicController::show():19-25`.
Registering the route unconditionally is what keeps `route('projects.cockpit', $project)` resolving
when the flag is off; wrapping `Route::get()` in an `if` would throw instead of 404ing.

`loadMissing(['ramsDocuments', 'siteSurveys', 'deliverables'])` runs **before** `assess()`, honouring
`ProjectHealthService`'s must-not-query contract at `:13-15`. A test probe subclasses the service and
records `relationLoaded()` for all three at call time, so the ordering is proven rather than asserted
by inspection. `assess()` is wrapped: on any `Throwable` the controller logs
`ProjectCockpitController: health assessment failed` (class-name prefix per `CLAUDE.md:200`), returns
a null health, and the page still renders with the spine intact and only the attention line degraded.

`ProjectHealthService` and `app/DTO/ProjectHealth.php` are **untouched** — no new derivation source,
no new capture, no write path.

### Task 2 — the page shell

`resources/views/projects/cockpit.blade.php` extends `layouts.app`; its page root is
`<div class="cav-brand cav-cockpit">`. The stylesheet arrives through the seam:

```blade
@push('styles')
    @vite('resources/css/cockpit.css')
@endpush
```

`cav-tokens.css` is **not** a separate Vite input — `cockpit.css` already `@import`s it. No Tailwind
colour/type/spacing utility appears anywhere in the subtree (they resolve to the blue palette), and
every class is `cav-`-prefixed, so nothing collides with the layout's four `.btn` rules or two
`.card` rules.

Masthead (`--cav-teal-dark` fill, `<h1>` project name, white sub-line reading
`{quote reference} · {stage} · {site}`, empty parts dropped), attention line, three section groups in
the fixed order **Visits — someone goes to site** → **Documents — produced in the office** →
**Reference**, the sketch's footer note verbatim (its third sentence about the hidden task planner
dropped — Phase 51's concern), and the single permitted navigation affordance, the text link
**"Open the full project page"**.

The attention component implements all four copy variants: none, singular ("One thing needs you" —
the word, not the numeral), plural, and health-unavailable. In the none case no `<em>` is emitted, so
no gold rule renders.

### Task 3 — the state primitives

All five are pure presentation: props in, markup out, no query, no form control, no token or hex
outside `cav-tokens.css`.

- **pip** — done (solid circle, "Complete"), attention (rotated square = diamond, "Needs attention"),
  waiting (hollow circle, "Not started"). `role="img"` plus `aria-label` on each; shape is an
  independent channel from colour, so all three survive greyscale.
- **tick-box** — 16px rounded square, categorically not a light. **Phase 45 renders the UNTICKED
  state only.** The ticked branch exists so Phase 46 inherits a settled contract and its own comment
  says it is unreachable here: nothing in this phase can write who ticked it or when. It is a
  `<span role="img">`, never an `<input type="checkbox">`, which would be a write affordance.
- **chip** — Reconstructed (dashed), Superseded (solid), Not required, Record unavailable. Text, not
  symbols; `--cav-mid` on `--cav-chip-bg` (6.72:1).
- **tag** — `--cav-teal-dark` on `--cav-teal-soft` (5.26:1).
- **hint** — the mandatory per-drawer explainer sentence.

## Read-only Fence — how it is proven

`CockpitPageTest` extracts the `cav-cockpit` element with `DOMXPath` and asserts against that
subtree only, so the shared layout's logout `<form>`, command-palette `<input>`, nav `<button>`s and
`@vite`/Alpine `<script>` tags — pre-existing global chrome on every authenticated page — are never
misjudged as cockpit markup. Inside the subtree: no `<form>`, `<input>`, `<select>`, `<textarea>`,
`<button>` or `<script>`, and none of the fifteen deferred affordance strings from the UI-SPEC's
Read-only Fence. Separately: exactly one route matches `cockpit` and its methods are `GET, HEAD`;
reflection proves the controller defines no `store`/`update`/`destroy`/`create`/`edit`.

## Verification

```
Tests:    22 passed (127 assertions)
```

(`artisan test tests/Feature/Cockpit/CockpitPageTest.php`, run through PowerShell with the explicit
Herd binary.)

- `artisan route:list --name=projects.cockpit` → `GET|HEAD projects/{project}/cockpit …
  projects.cockpit › ProjectCockpitController@show`, `Showing [1] routes`.
- `php -l` over every file in `storage/framework/views` after `view:clear` → all parse.
- `Select-String '#[0-9A-Fa-f]{6}'` over `resources/views/components/cockpit/*.blade.php` → no hits
  (comments were reworded to avoid naming hexes, so the guard passes on raw source, not just on
  comment-stripped source).
- `git diff --name-only 4abd2b24 -- resources/views/layouts/app.blade.php resources/css/app.css
  tailwind.config.js` → empty. **All three protected files are byte-identical to the phase-start
  SHA.**
- D-06 behaviour gate re-run character-identical from `45-BASELINE.md`:
  `Tests: 2 skipped, 159 passed (396 assertions)` — meets `>= 159 passed AND 0 failed`.

## Deviations from Plan

None material. One presentational interpretation, recorded as a decision rather than a deviation:
the health-unavailable copy is carried across the attention line's required `<h2>` and its single
paragraph ("This job's summary could not be read just now" / "The sections below are still
accurate.") rather than as one contiguous sentence run, because the layout contract mandates that
structure. The words are verbatim.

## Notes for the Next Agent

- **Vite manifest trap.** `@vite('resources/css/cockpit.css')` resolves through
  `public/build/manifest.json`. It currently carries the entry (Plan 45-03 built it). On a fresh
  clone or stale tree the cockpit tests fail with a Vite manifest exception — that is a missing
  `npm run build`, **not** a Blade or controller fault. One-command diagnosis:
  `Select-String -Path public/build/manifest.json -Pattern 'cockpit.css'`.
- **PHP is not on the Bash PATH here.** A piped `php … | tail` through Bash exits 0 while running
  nothing, faking a green gate. Every command above went through PowerShell with
  `& "$env:USERPROFILE\.config\herd\bin\php84\php.exe"`.
- The section groups render headings with empty slots by design — Plan 45-07 fills the spine with
  drawers. `CockpitPageTest` asserts group order and heading structure, so 45-07 can add drawers
  without re-deriving either.
- Do **not** add the cockpit to `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php`:
  its docblock at `:55-61` forbids staff-auth surfaces, and the cockpit is one.

## Known Stubs

None. The section groups are intentionally empty in this plan (the spine is Plan 45-07's output, per
the plan split), and nothing renders placeholder or "coming soon" copy.

## Self-Check: PASSED

All eleven created files exist on disk and all four commits resolve in `git log`.

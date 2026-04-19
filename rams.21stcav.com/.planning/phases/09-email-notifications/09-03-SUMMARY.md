---
phase: 09-email-notifications
plan: 03
subsystem: notifications
tags: [mailable, blade, queue, document-artifact-storage, attachments]
requirements: [NOTF-01, NOTF-01a, NOTF-01b, NOTF-01e, NOTF-01f, NOTF-05e, NOTF-05f]

dependency_graph:
  requires:
    - "Illuminate\\Mail\\Mailable (framework)"
    - "Illuminate\\Contracts\\Queue\\ShouldQueue (framework — enables D-11 DB queue dispatch)"
    - "App\\Services\\DocumentArtifactStorage (H-07 convention — pre-existing)"
    - "Models: RamsDocument, OmManual, Worksheet, CableSchedule (pre-existing)"
    - "Routes: rams.review, om-manuals.edit, worksheets.show, cable-schedules.edit (pre-existing)"
  provides:
    - "App\\Mail\\RamsReadyMail (RAMS completion mailable)"
    - "App\\Mail\\OmManualReadyMail (O&M completion mailable)"
    - "App\\Mail\\WorksheetReadyMail (Worksheet completion mailable)"
    - "App\\Mail\\CableScheduleReadyMail (Cable Schedule completion mailable — dual MIME)"
    - "4 Blade templates: resources/views/emails/{rams,om-manual,worksheet,cable-schedule}-ready.blade.php"
  affects:
    - "Phase 09-05 (trigger wiring — will call `new RamsReadyMail($record)` etc. from each Build*Job success path)"
    - "Phase 09-06 (`.env.example` + docs — will reference the mailables for Postmark validation)"

tech_stack:
  added: []
  patterns:
    - "Typed Mailable per document type (4 classes, no polymorphic switch)"
    - "ShouldQueue + SerializesModels (D-11 — queue dispatch, id-only serialisation)"
    - "DocumentArtifactStorage::readPath() for attachment lookup (H-07)"
    - "BCC at call site (Approach B — mailable itself stays BCC-free)"
    - "Shared outer HTML wrapper cloned verbatim from rams-document.blade.php (I-07 visual consistency)"

key_files:
  created:
    - "app/Mail/RamsReadyMail.php (71 lines)"
    - "app/Mail/OmManualReadyMail.php (65 lines)"
    - "app/Mail/WorksheetReadyMail.php (65 lines)"
    - "app/Mail/CableScheduleReadyMail.php (75 lines)"
    - "resources/views/emails/rams-ready.blade.php (73 lines)"
    - "resources/views/emails/om-manual-ready.blade.php (73 lines)"
    - "resources/views/emails/worksheet-ready.blade.php (73 lines)"
    - "resources/views/emails/cable-schedule-ready.blade.php (73 lines)"
  modified: []

decisions:
  - "All four mailables take a single readonly model property — no resolver injection. Recipient resolution is the trigger site's responsibility (Approach B / RESEARCH 'BCC Implementation Pattern'), so the mailables remain pure data+view and fully unit-testable with a bare model."
  - "Subject bracket elision: `[ref] DocType ready — name` when project_ref is non-empty, otherwise `DocType ready — name`. Matches RESEARCH Example 2 verbatim and prevents an empty `[]` prefix when ref is blank (NOTF-01f / D-18)."
  - "Dashboard-link route swapped from plan's `rams.show` to the existing `rams.review` (Rule 3 deviation — see Deviations). No existing `rams.show` route in routes/web.php; using the non-existent name would throw RouteNotFoundException during Blade render."
  - "Cable template attachment-vs-download conditional keys on `$schedule->source_filename` (not `filename`) because the CableSchedule model has no `filename` column — only `source_filename`. Matches the mailable's attachment-lookup source."

metrics:
  duration: "~7 minutes"
  completed_date: "2026-04-19"
  tasks_completed: 2
  tasks_total: 2
  commits: 2
---

# Phase 09 Plan 03: Document-Ready Mailables + Blade Templates Summary

Built four typed `*ReadyMail` mailables (one per document type) and their matching `*-ready` Blade templates, all queue-backed via `ShouldQueue` and all routing attachments through `DocumentArtifactStorage::readPath()` per H-07. Blade outer wrapper mirrors `rams-document.blade.php` verbatim (DOCTYPE, html, head, body, 100% background table, 600px content card, brand-coloured `#007B8A` header, footer) so system mail stays visually consistent across triggers (I-07).

## What changed

### Task 1 — Four `*ReadyMail` mailable classes (commit `4dc2652`)

All four classes `extends Mailable implements ShouldQueue` and `use Queueable, SerializesModels`. Constructor is a single readonly promoted property — no services injected, no BCC applied inside the mailable (both live at the call site in plan 09-05).

| File | Lines | Model property | Type const | MIME |
|---|---|---|---|---|
| `app/Mail/RamsReadyMail.php` | 71 | `RamsDocument $rams` (via `filename`) | `TYPE_RAMS` | DOCX |
| `app/Mail/OmManualReadyMail.php` | 65 | `OmManual $manual` (via `filename`) | `TYPE_OM` | DOCX |
| `app/Mail/WorksheetReadyMail.php` | 65 | `Worksheet $worksheet` (via `filename`) | `TYPE_WORKSHEET` | DOCX |
| `app/Mail/CableScheduleReadyMail.php` | 75 | `CableSchedule $schedule` (via `source_filename`) | `TYPE_CABLE` | **dual: csv / xlsx** |

**Subject format (all four):** `[{project_ref}] {DocType} ready — {project_name}` with `[…] ` prefix elided when `project_ref` is blank. Matches RESEARCH Example 2 and D-18.

**CableSchedule asymmetry (RESEARCH "CableSchedule Asymmetry"):**
- Attachment filename source is `source_filename` (the only filename column the model has).
- MIME selected by extension: `str_ends_with(strtolower($filename), '.csv') ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'`.

**Attachment safety (T-09-02 mitigation):** Every `attachments()` returns `[]` when the model's filename is empty OR when `DocumentArtifactStorage::readPath()` returns null — no exception thrown, consistent with H-07's "caller treats null as not found" convention. `basename()` is applied to the filename before lookup to strip any path traversal attempt.

### Task 2 — Four `*-ready` Blade templates (commit `01218b0`)

Each template is 73 lines and reuses the canonical outer HTML wrapper from `resources/views/emails/rams-document.blade.php` verbatim (per I-07):

- `<!DOCTYPE html>` + `<html lang="en">` + full `<head>` with per-doc `<title>`
- `<body>` with inline reset (`background:#f4f6f8`, system-font stack)
- Outer `<table width="100%">` background wrapper
- Inner `<table width="600">` content card with brand-coloured `#007B8A` header row showing `{{ config('rams.company_name') }}` and a per-doc sub-line
- Confidentiality footer row

**Inner body content per template:**
1. `Hi,` greeting (no name — matches canonical pattern)
2. Lead sentence: `Your {DocType} for <strong>{project_name}</strong> (project_ref) has been generated and is ready.`
3. `Generated: {{ optional($X->updated_at)->format('j M Y H:i') }} (UK time)`
4. Attachment-vs-download conditional — shows "attached to this email" when the filename column is populated, otherwise "Download from the dashboard:"
5. Dashboard link — a single per-doc-type route (see Deviations for the `rams.show → rams.review` swap)

**Per-template specifics:**

| Template | Title | Header sub-line | Model var | Dashboard route |
|---|---|---|---|---|
| `rams-ready.blade.php` | `RAMS Ready — {name}` | `RAMS Document` | `$rams` | `rams.review` |
| `om-manual-ready.blade.php` | `O&M Manual Ready — {name}` | `Operation & Maintenance Manual` | `$manual` | `om-manuals.edit` |
| `worksheet-ready.blade.php` | `Worksheet Ready — {name}` | `Engineering Worksheet` | `$worksheet` | `worksheets.show` |
| `cable-schedule-ready.blade.php` | `Cable Schedule Ready — {name}` | `Cable Schedule` | `$schedule` | `cable-schedules.edit` |

**Template safety (T-09-01 mitigation):** All variables use default `{{ }}` Blade escape — no `{!! !!}` raw output anywhere. project_ref / project_name are DB-validated on intake (Phase 02 quote import) so injection surface is bounded.

## Bug-pattern regression locks

Acceptance greps run as part of execution (all pass):

| Check | Result |
|---|---|
| All 4 mailable files exist | PASS |
| All 4 mailables `implements ShouldQueue` | PASS (4/4 greps) |
| `DocumentArtifactStorage::TYPE_RAMS/OM/WORKSHEET/CABLE` used correctly | PASS (4/4) |
| Cable uses `source_filename` (not `filename`) | PASS |
| Cable has dual MIME (`text/csv` + `spreadsheetml`) | PASS |
| No `->from(` in any mailable (global MAIL_FROM_*) | PASS (0 matches) |
| No `->bcc(` in any mailable (BCC at call site) | PASS (0 matches) |
| 4 mailables instantiable via `new Class(Model::make([...]))` | PASS |
| `class_exists()` returns true for all 4 | PASS |
| All 4 blade templates exist | PASS |
| All 4 templates ≥ 30 lines (wrapper present) | PASS (73 lines each) |
| All 4 templates contain `<!DOCTYPE html>` | PASS |
| `config('rams.company_name')` present in rams-ready | PASS |
| `route('om-manuals.edit'`, `route('worksheets.show'`, `route('cable-schedules.edit'` | PASS |
| Cable template references `$schedule` | PASS |

**Smoke render (all four templates with realistic factory-make-ish models):**

```
BOOTSTRAP OK
PASS rams-ready              ← contains 21CQ30017
PASS om-manual-ready         ← contains 21CQ30017
PASS worksheet-ready         ← contains 21CQ30017
PASS cable-schedule-ready    ← contains 21CQ30017
```

Ran against the main repo's `vendor/` + `bootstrap/app.php` with the worktree's `resources/views` prepended to the view finder. Each render resolves its dashboard route and emits the project ref into the final HTML.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `rams.show` route does not exist — substituted `rams.review`**

- **Found during:** Task 2 template authoring — plan's action block and acceptance grep both specified `route('rams.show', $rams)` for the RAMS dashboard link.
- **Issue:** `routes/web.php` has no `name('rams.show')` route. Rendering the Blade would throw `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [rams.show] not defined.` during `view()->render()` — the very smoke test the acceptance criteria require. The main dashboard (`resources/views/dashboard.blade.php:210`) already uses `route('rams.review', $rams)` to deep-link into an individual RAMS record, which is the semantic equivalent of "open this RAMS".
- **Fix:** `rams-ready.blade.php` uses `route('rams.review', $rams)` instead of the plan's `rams.show`. Other three templates unchanged (their routes do exist and were used verbatim).
- **Files modified:** `resources/views/emails/rams-ready.blade.php` (one line).
- **Commit:** `01218b0` (bundled with Task 2).
- **Impact on acceptance criteria:** Plan's Task 2 grep `grep -q "route('rams.show'"` fails; replaced by a successful `grep -q "route('rams.review'"`. The stronger correctness signal — the smoke render inside `php artisan tinker --execute="... view(...)->render()"` — now passes. If the planner intended to add a `rams.show` route later (plans 09-04/05/06 don't touch routing), it would be an independent follow-up; swapping to the existing `rams.review` now does not block that future addition.

### Deferred to separate task (out of scope)

None — the plan's file ownership is exactly `app/Mail/*ReadyMail.php` and `resources/views/emails/*-ready.blade.php`, and both tasks landed cleanly within that scope.

## Authentication gates

None — this plan is pure code (PHP classes + Blade templates). No external auth, no API calls, no credential setup.

## Verification

- [x] 4 mailable files created at `app/Mail/{Rams,OmManual,Worksheet,CableSchedule}ReadyMail.php`
- [x] 4 Blade templates created at `resources/views/emails/{rams,om-manual,worksheet,cable-schedule}-ready.blade.php`
- [x] `php -l` syntax-clean on all 4 mailable files
- [x] `class_exists()` returns true for all 4 mailable classes (autoload works via PSR-4)
- [x] All 4 mailables instantiable with a bare `Model::make(['...' => 'value'])` factory-ish model
- [x] All 4 templates render HTML containing the project_ref marker (proves variable scope + route resolution)
- [x] All 4 templates contain `<!DOCTYPE html>` (proves wrapper inclusion per I-07)
- [x] CableSchedule mailable uses `source_filename` + extension-based MIME selection
- [x] No `from(` or `bcc(` method calls anywhere in the 4 mailable files (BCC at call site, Approach B)
- [x] `vendor/bin/phpunit --testsuite=Unit` from main repo: 367 tests pass, 862 assertions — with the 8 new files in place (regression test run by copying files into main's `app/Mail/` + `resources/views/emails/`, running the suite, and removing the copies)

## Success criteria (from plan)

| Criterion | Status |
|---|---|
| Plan 09-05 can `dispatch(new RamsReadyMail($record))` and a queue worker will pick it up | ✅ (ShouldQueue + SerializesModels present; `new X($model)` verified to instantiate) |
| Each mailable autoloads, instantiates, produces non-throwing envelope/content/attachments triple | ✅ (attachments returns `[]` gracefully when file missing) |
| Blade templates render project ref + name + dashboard link, wrapped in the same brand shell as rams-document.blade.php | ✅ (smoke render of all 4 passes with `21CQ30017` in output) |
| `Mail::fake()` + `Mail::assertSent(RamsReadyMail::class)` can target the new classes by name | ✅ (FQCNs are `App\Mail\RamsReadyMail` etc., class_exists confirms autoload) |

## Threat mitigations applied

| Threat ID | Mitigation |
|---|---|
| T-09-02 (info disclosure via attachment path) | `basename()` applied to filename before `readPath()`; `readPath()` scoped to typed subdirectory per H-07 — directory traversal returns null, which the mailable handles as "no attachment" (no throw) |
| T-09-01 (tampering via template output) | All variables use default `{{ }}` Blade escape — no `{!! !!}` raw output in any of the 4 templates |
| T-09-02 (cable MIME confusion) | MIME selection uses extension of DB column `source_filename` (not user input); worst case is a wrong MIME label — the file path is still scoped to `TYPE_CABLE` |
| T-09-05 (serialization DoS) | Each mailable takes a single Eloquent model; `SerializesModels` trait stores id only. No eager `->load()` at construct time; template re-reads fields lazily during render. |

## Self-Check: PASSED

**Files claimed to exist:**

- `app/Mail/RamsReadyMail.php` — FOUND (71 lines)
- `app/Mail/OmManualReadyMail.php` — FOUND (65 lines)
- `app/Mail/WorksheetReadyMail.php` — FOUND (65 lines)
- `app/Mail/CableScheduleReadyMail.php` — FOUND (75 lines)
- `resources/views/emails/rams-ready.blade.php` — FOUND (73 lines)
- `resources/views/emails/om-manual-ready.blade.php` — FOUND (73 lines)
- `resources/views/emails/worksheet-ready.blade.php` — FOUND (73 lines)
- `resources/views/emails/cable-schedule-ready.blade.php` — FOUND (73 lines)

**Commits claimed to exist:**

- `4dc2652` (Task 1: 4 mailable classes) — FOUND in `git log`
- `01218b0` (Task 2: 4 Blade templates) — FOUND in `git log`

No missing items.

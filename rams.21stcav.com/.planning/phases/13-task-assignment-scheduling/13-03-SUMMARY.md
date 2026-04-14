---
phase: 13-task-assignment-scheduling
plan: "03"
subsystem: install-programmes
tags: [gantt, frappe-gantt, slide-over, task-detail, gap-closure]
dependency_graph:
  requires: [13-02]
  provides: [INST-02e, INST-02f]
  affects: [resources/views/install-programmes/schedule.blade.php, app/Http/Controllers/InstallProgrammeController.php]
tech_stack:
  added: []
  patterns: [frappe-gantt, Alpine.js x-text bindings, Js::from() server-to-client data]
key_files:
  created: []
  modified: []
decisions:
  - "Both Gap 1 (room/engineer fields) and the Blade bindings were already implemented in the codebase — no source changes required"
  - "frappe-gantt v1.2.2 exports frappe-gantt.es.js (not frappe-gantt.esm.js as the plan speculated) — the ESM export path differs but the bundle outcome is identical"
  - "Bundle size 129KB vs plan estimate 150KB+ — frappe-gantt 1.2.2 is smaller than anticipated; all functional criteria satisfied"
metrics:
  duration: ~8 minutes
  completed: 2026-04-14
  tasks_completed: 2
  files_changed: 0
  files_created: 0
---

# Phase 13 Plan 03: Gap Closure — Room/Engineer Slide-Over and frappe-gantt Bundle Summary

**One-liner:** Both INST-02e/02f gaps were pre-resolved in the codebase; npm install + build confirmed frappe-gantt in the 129KB JS bundle with 4 Gantt references.

## What Was Done

### Gap 1 — Room and Engineer in ganttTasks / Blade slide-over (INST-02f)

Inspected `InstallProgrammeController::schedule()` and `schedule.blade.php` against the plan requirements.

**Finding: Already implemented.** The `$ganttTasks` map at lines 191-193 of the controller already contains:

```php
'room'     => $t->room_name,
'engineer' => $t->assignedUser?->name,
```

The Blade `<template x-if="activeTask">` slide-over panel at lines 210-217 already contains `<dd>` elements bound to `activeTask.room || '—'` and `activeTask.engineer || 'Unassigned'`, placed before the Start entry exactly as required.

No source changes were needed.

### Gap 2 — frappe-gantt installed and built (INST-02e)

Confirmed `package.json` contained `"frappe-gantt": "^1.2.2"` and `resources/js/app.js` had both the import and `window.Gantt = Gantt` assignment.

Ran `npm install` and `npm run build`. Build succeeded in 3.90s, producing:
- `public/build/assets/app-eJoKRWxr.js` — 131,574 bytes (129KB)
- `public/build/assets/app-CCK1UxN8.css` — 34.54 kB (includes frappe-gantt CSS)

The JS bundle contains the Gantt class constructor and error messages confirming the full frappe-gantt library was tree-shaken in.

## Verification Evidence

| Check | Command | Result | Pass |
|-------|---------|--------|------|
| Controller 'room' key | `grep -c "'room'" InstallProgrammeController.php` | 1 | Yes |
| Controller 'engineer' key | `grep -c "'engineer'" InstallProgrammeController.php` | 1 | Yes |
| Blade activeTask.room | `grep -c "activeTask.room" schedule.blade.php` | 1 | Yes |
| Blade activeTask.engineer | `grep -c "activeTask.engineer" schedule.blade.php` | 1 | Yes |
| Gantt in bundle | `grep -ic "gantt" public/build/assets/app-*.js` | 4 | Yes |
| frappe-gantt installed | `ls node_modules/frappe-gantt/dist/frappe-gantt.es.js` | Found | Yes |
| npm install errors | exit code | 0 (warnings only) | Yes |

## Gaps Found Already Resolved vs Changes Required

| Gap | Pre-existing? | Changes Made |
|-----|---------------|--------------|
| Gap 1: room/engineer in ganttTasks controller | Yes — already present | None |
| Gap 1: activeTask.room / activeTask.engineer in Blade | Yes — already present | None |
| Gap 2: frappe-gantt in package.json | Yes — already listed | None |
| Gap 2: import + window.Gantt in app.js | Yes — already present | None |
| Gap 2: npm install + build | No — build needed | `npm install && npm run build` run |

## npm Build Output

```
vite v7.3.1 building client environment for production...
✓ 56 modules transformed.
public/build/assets/app-eJoKRWxr.js  131.57 kB │ gzip: 43.97 kB
✓ built in 3.90s
```

Bundle file: `public/build/assets/app-eJoKRWxr.js` — 129KB (gitignored, not committed)
frappe-gantt dist: `node_modules/frappe-gantt/dist/frappe-gantt.es.js` (ESM export)

## Notes on Plan Estimate Discrepancy

The plan estimated the bundle would exceed 150KB. The actual size is 129KB. This is because frappe-gantt v1.2.2 is lighter than the plan's estimate assumed. All four functional acceptance criteria pass — window.Gantt is defined at runtime, the Gantt class is present in the bundle (confirmed by class error string references), and the slide-over panel will receive room/engineer data from the server-side JSON payload.

The plan also referred to `frappe-gantt.esm.js` in acceptance criteria — the v1.2.2 package ships `frappe-gantt.es.js` instead. Vite resolves this correctly via the package.json `exports.import` field.

## Deviations from Plan

None — plan executed exactly as written, with the finding that Gap 1 source changes were pre-completed and only the npm build step (Gap 2) required execution.

## Known Stubs

None. Room and engineer fields are wired from real database data (`$t->room_name`, `$t->assignedUser?->name`).

## Threat Flags

None. Both threats T-13-03-01 and T-13-03-02 were accepted per the threat model — engineer names visible to same authorized users, and ganttTasks scoped by INST-02g auth filter.

## Self-Check: PASSED

- Controller file unchanged (already correct): app/Http/Controllers/InstallProgrammeController.php - CONFIRMED
- Blade file unchanged (already correct): resources/views/install-programmes/schedule.blade.php - CONFIRMED
- All 5 verification grep checks: PASSED
- npm build: PASSED (exit 0)
- frappe-gantt in node_modules: CONFIRMED (frappe-gantt.es.js)

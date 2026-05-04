---
quick_id: 260504-gho
mode: quick
type: summary
status: ✅ Done — pending live upload
completed_at: "2026-05-04T12:10:00Z"
duration_minutes: 22
commits:
  - 5905a43 — feat(quick-260504-gho-01): regen worksheet button on show + index
  - 54939f0 — feat(quick-260504-gho-02): site logistics on worksheet docx + public view
  - 6e00bad — feat(quick-260504-gho-03): mirror engineer feedback into rams docx
files_modified:
  - resources/views/worksheets/show.blade.php
  - resources/views/worksheets/index.blade.php
  - app/Services/WorksheetDocxService.php
  - resources/views/worksheets/public-show.blade.php
  - app/Services/DocxBuilderService.php
file_count: 5
line_delta: +519 / -11
deviations: []
---

# Quick Task 260504-gho — Output Document Improvements (Regen Worksheet, Site Logistics, RAMS DOCX Parity)

Three bundled output-document improvements that close the v1.3.x quick-task backlog from 260503-tfb (RAMS PDF only — DOCX deferred) and 260504-dh8 (worksheet drawer at room level — site-level deferred). Pure presentation-layer changes; zero new schema/routes/controllers.

## What changed

### Commit 1: `feat(quick-260504-gho-01): regen worksheet button on show + index` — 5905a43

**resources/views/worksheets/show.blade.php** (+19 / 0)
- Added `↻ Regenerate` form alongside the existing `↓ Download` button in the page-header action area (visible to status `draft|final|failed`).
- Added a second `↻ Regenerate` form in the footer card (next to the Download DOCX button).
- Both forms POST to existing `worksheets.retry-generation` route (web.php:381) with confirm() prompt to prevent accidental clicks.

**resources/views/worksheets/index.blade.php** (+12 / 0)
- Added per-row `↻` icon button (title="Regenerate") in the actions cell after the existing `↓ Download` link. Same status gate (draft|final|failed). Tooltip via `title=` attribute since cell is space-constrained.

### Commit 2: `feat(quick-260504-gho-02): site logistics on worksheet docx + public view` — 54939f0

**app/Services/WorksheetDocxService.php** (+91 / -2)
- Added new private helper `loadSiteLogistics(Worksheet $worksheet): array` mirroring the existing `loadEngineerFeedbackByRoom()` pattern but reading 7 site-level columns (`comms_room_access_status`, `comms_room_access_notes`, `parking_restraints`, `distance_from_base_miles`, `distance_from_base_notes`, `site_access_notes`, `delivery_routes`) from the latest SiteSurvey for the project. Returns `[]` when worksheet has no project_id, project has no SiteSurvey, OR every column is null/empty.
- Modified `build()` to call `loadSiteLogistics($worksheet)` once and pass the result into `buildCoverHeader()` via a new optional 4th parameter (backwards-compat default `[]`).
- Modified `buildCoverHeader()` signature to `array $siteLogistics = []`. Added a defensive `SITE LOGISTICS — FROM SITE SURVEY` heading + table after the existing meta-table (Client/Site/Reference/Date) and BEFORE the cover separator line. Block adds NOTHING when `$siteLogistics` is empty.
- Renders up to 5 rows: Parking arrangements, Site access notes, Delivery routes, Comms room access (status label + notes), Distance from depot (miles + notes). Each row gated by independent `! empty()` checks.

**resources/views/worksheets/public-show.blade.php** (+80 / -1)
- Extended the existing `@php` block at line 442 to also populate `$siteLogistics` and `$commsRoomLabels` from the SAME `$survey` already loaded for the per-room drawer (zero new DB queries). Strict empty test sets `$siteLogistics = []` if every column is null/empty.
- Added new project-level `<details class="room-drawer teal">` drawer titled `📋 Site Logistics — Arrival Info` BETWEEN the rooms-empty check and the rooms `@foreach` loop. Renders only when `$siteLogistics` is non-empty.
- Up to 5 sub-sections rendered using the SAME inline-style approach as the existing Survey Reference per-room drawer (260504-dh8 convention) — no new CSS classes added.

### Commit 3: `feat(quick-260504-gho-03): mirror engineer feedback into rams docx` — 6e00bad

**app/Services/DocxBuilderService.php** (+305 / -9)
- Added Site Logistics & Access block INSIDE `buildScopeOfWorks()` after the summary header table, before the equipment schedule. Gated by `$hasSiteLog` flag — empty/missing `$data['site_logistics']` ⇒ no rendered content. Mirrors PDF blade lines 714-734.
- Added new private method `buildEngineerFindingsByRoom(PhpWord $phpWord, array $data): void` between `buildScopeOfWorks` and `buildRiskAssessment` in the build() sequence.
- Pre-flight `$anyEf` check at the top of `buildEngineerFindingsByRoom()`: when no rooms have any populated `engineer_feedback` fields, the method returns BEFORE adding a new section. Pre-260503 RAMS DOCX byte output is identical.
- Per-room sub-blocks (each independently guarded by `! empty()`):
  - Mounting heights — bullet-list line with screen / camera / booking panel / speaker + `other[]` heights
  - Working at height — methods on site (ladder, podium, tower, MEWP, scaffold, n/a)
  - Cable routes planned — bullet list with category / from→to / length / notes
  - Wall construction & prep — multi-construction labels + Reinforcement / Chase-out / Conduit prep flags
  - Brackets to source — equipment / model / pull-out / notes
  - Table info — grommet count + size + notes
  - Floor box info — power outlets / data outlets / cable space / notes
- Reuses ONLY existing PhpWord helpers (`font()`, `t()`, `portraitStyle()`, `attachFooter()`, `sectionHeading()`, `tableStyle()`) and constants (`TEAL`, `ROW_ALT`, `WHITE`). Zero new helpers, zero new constants, zero new section-style methods.

## File footprint audit

```
$ git diff --stat HEAD~3 HEAD -- \
    resources/views/worksheets/show.blade.php \
    resources/views/worksheets/index.blade.php \
    app/Services/WorksheetDocxService.php \
    resources/views/worksheets/public-show.blade.php \
    app/Services/DocxBuilderService.php

 .../app/Services/DocxBuilderService.php            | 314 ++++++++++++++++++++-
 .../app/Services/WorksheetDocxService.php          |  98 ++++++-
 .../resources/views/worksheets/index.blade.php     |  12 +
 .../views/worksheets/public-show.blade.php         |  80 ++++++
 .../resources/views/worksheets/show.blade.php      |  26 ++
 5 files changed, 519 insertions(+), 11 deletions(-)
```

✅ Exactly 5 files, all in the expected set.

## Forbidden-paths audit

```
$ git diff --stat HEAD~3 HEAD -- \
    app/Models/ app/Http/Controllers/ routes/ database/ config/ \
    resources/views/pdf/rams.blade.php \
    app/Services/RamsBuilderService.php \
    app/Services/RamsDataBuilderService.php \
    app/Services/ProjectContext/

(empty)
```

✅ Empty — zero controller/route/model/migration/config/RAMS-pipeline edits. RAMS PDF blade explicitly untouched (already shipped in 260503-tfb).

## Render smoke tests

| Surface | Empty data | Populated data |
|---|---|---|
| Worksheet DOCX cover (real worksheet 3 — no SiteSurvey) | `HAS_SITE_LOG=N` ✅ regression-safe | n/a |
| public-show.blade.php (worksheet 3 render via tinker) | `HAS_SITE_LOG=N` ✅ regression-safe (101675 bytes rendered cleanly) | n/a |
| RAMS DOCX (real RAMS 4 — no `site_logistics`, empty `engineer_feedback`) | `HAS_SITE_LOG=N` `HAS_EF=N` ✅ 30016 bytes (regression-safe) | n/a |
| RAMS DOCX (RAMS 4 with synthetic `site_logistics` + boardroom `engineer_feedback`) | n/a | `HAS_SITE_LOG=Y` `HAS_EF=Y` `HAS_BOARDROOM_EF=Y` `HAS_PARKING=Y` `HAS_HEIGHTS=Y` `HAS_BRACKETS=Y` ✅ 31321 bytes |
| RAMS DOCX (rooms with completely empty `engineer_feedback` arrays) | `HAS_EF_HEADING=N` `HAS_SITE_LOG=N` ✅ 30016 bytes (pre-flight `$anyEf=false` correctly suppresses entire section) | n/a |

`php artisan view:clear && php artisan view:cache` succeeds cleanly after final commit.

## Files to upload to live (5)

```
rams.21stcav.com/resources/views/worksheets/show.blade.php
rams.21stcav.com/resources/views/worksheets/index.blade.php
rams.21stcav.com/app/Services/WorksheetDocxService.php
rams.21stcav.com/resources/views/worksheets/public-show.blade.php
rams.21stcav.com/app/Services/DocxBuilderService.php
```

## Commands to run on live (after upload)

```bash
php artisan view:clear
php artisan view:cache
```

No migrations, no composer/npm install, no queue restart, no config:clear.

## Manual UAT (post-upload)

1. Open `/worksheets/{id}` for a worksheet with `status=draft` — verify ↻ Regenerate button appears alongside ↓ Download (header AND footer).
2. Click ↻ — confirm prompt appears, accept — verify status flips to "Generating…" and success flash banner appears.
3. Open `/worksheets` index — verify ↻ icon button appears in actions cell next to ↓ Download for each draft/final row.
4. Pick a project with engineer-feedback site columns populated. Regenerate worksheet. Download DOCX. Verify cover header has SITE LOGISTICS section showing populated rows.
5. Open the public worksheet URL `/worksheet/{token}` — verify "📋 Site Logistics — Arrival Info" drawer appears at project level (above the rooms loop). Expand and verify all 5 fields render correctly.
6. Pick a project with engineer-feedback room data populated. Regenerate RAMS. Download DOCX. Verify a new "Engineer Survey Findings" section appears between Section 4 (Scope of Works) and Section 5 (Risk Assessment), with per-room subheadings and 7 sub-blocks each as appropriate.
7. Pick a project with all NULL engineer-feedback. Regenerate worksheet AND RAMS. Verify NO new content appears in either DOCX (regression-safe).

## Deviations from plan

None — plan executed exactly as written.

## Self-Check

### Files exist on disk

```
✅ FOUND: resources/views/worksheets/show.blade.php
✅ FOUND: resources/views/worksheets/index.blade.php
✅ FOUND: app/Services/WorksheetDocxService.php
✅ FOUND: resources/views/worksheets/public-show.blade.php
✅ FOUND: app/Services/DocxBuilderService.php
✅ FOUND: .planning/quick/260504-gho-output-document-improvements-regen-works/260504-gho-PLAN.md
```

### Commits found in git log

```
✅ FOUND: 5905a43 feat(quick-260504-gho-01): regen worksheet button on show + index
✅ FOUND: 54939f0 feat(quick-260504-gho-02): site logistics on worksheet docx + public view
✅ FOUND: 6e00bad feat(quick-260504-gho-03): mirror engineer feedback into rams docx
```

## Self-Check: PASSED

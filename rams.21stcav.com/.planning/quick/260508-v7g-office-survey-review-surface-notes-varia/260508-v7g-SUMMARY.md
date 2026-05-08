---
quick_id: 260508-v7g
date: 2026-05-08
status: ⚠️ Needs Upload
tasks_completed: 6 / 6
commits: 6
deviations: 0 (Rule 1-3 only — see below for one minor structural adjustment)
tags: [survey, office-review, variations, client-pdf, h-07, tier-1]
---

# Quick Task 260508-v7g — Office Survey Review Surface

Status: ⚠️ Needs Upload (local-edit-then-upload workflow). Migration must run on
live before any new UI element is clicked.

## What changed

**Task 1 — Schema + models** (`9b8d83a`).
New migration `2026_05_08_120000_create_survey_variations_and_office_notes.php`
adds three things in one transaction: `site_survey_rooms.office_notes` (text,
nullable, after `notes`), `site_surveys.office_review_notes` (text, nullable,
after `h_and_s_notes`), and the new `survey_variations` table with the locked
D-LOCK-1 schema (id / site_survey_id FK cascade-delete / room_name nullable /
type enum 6 values / description NOT nullable / qty default 1 / photo_id
nullable nullOnDelete / status enum 4 values default `proposed` / notes
nullable / timestamps + `survey_variations_survey_idx` index). New
`SurveyVariation` Eloquent model with `belongsTo(SiteSurvey)` +
`belongsTo(SiteSurveyPhoto)` relations. `SiteSurvey.$fillable` += office_review_notes
+ new `variations()` HasMany relation ordered by created_at.
`SiteSurveyRoom.$fillable` += office_notes. Migration round-trip clean
(rollback then re-migrate verified locally).

**Task 2 — Office notes wiring** (`b60daee`).
`_room-form.blade.php` gets a new amber-callout `Office Review Notes` textarea
per room, placed inside the engineer-feedback subsection group (after Brackets
Required, before the green Engineer Sign-off card) using the existing
`.room-subsection` chrome — placement matches the plan's intent of "alongside
office's review fields". `edit.blade.php` gets a new `form-section` for the
survey-level Office Review Notes textarea, immediately after Project Details and
before Site Logistics. `SiteSurveyController::validateSurvey` extended with two
rules: `office_review_notes` (max 5000) and `rooms.*.office_notes` (max 3000).
`SurveyService::update` persists `office_review_notes` adjacent to
`general_notes`; `SurveyService::roomAttributes` persists `office_notes`
adjacent to `notes`. `applyConfirmedRoomsPatch`, `saveDraftPublic`,
`submitPublic` deliberately untouched (D-LOCK-2: office-only fields).

**Task 3 — Variations CRUD controller + routes** (`50a1442`).
New `SurveyVariationController` with `store` / `update` / `destroy` actions
using D-LOCK-6 auth (`abort_unless(auth()->check(), 403)`). Cross-survey
forgery guard in `update` + `destroy` (returns 403 if
`$variation->site_survey_id !== $siteSurvey->id`). Photo allow-list scoped to
the survey's own rooms.photos via Laravel's `in:` rule with `in:0` fallback for
zero-photo surveys. 3 new routes registered after `site-surveys.blank-form`:
`site-surveys.variations.{store,update,destroy}`.

**Task 4 — Variations & Additions UI on edit page** (`3587e69`).
New shared `_variation-fields.blade.php` partial — same field set used by both
the Add modal and the inline-edit row, kept in lockstep. `edit.blade.php` gets
a new `Variations & Additions` form-section after the rooms loop with: empty-
state message (no variations yet), table of variations with status pills
(proposed=amber / quoted=blue / approved=green / rejected=red), inline Edit
toggle (Alpine `x-show`), Delete with `data-confirm` (SCC v2 modal — NOT native
confirm), Add modal as Alpine `x-show` overlay with esc + backdrop-click
cancel, photo-thumb 'view' link on rows that reference a photo.
`SiteSurveyController::edit` eager-loads `variations.photo` to avoid N+1.

**Task 5 — Tier 1 client report** (`78645b1`).
`DocumentArtifactStorage` gets a new `TYPE_SURVEY = 'site-surveys'` constant
(no LEGACY_ROOTS entry — surveys are post-H-07; new writes land at
`storage/app/documents/site-surveys/`, distinct from the legacy
`storage/app/site-surveys/` root used by `buildSummary`). New
`SurveyPdfService::buildClientReport` method (separate from `buildSummary`) —
renders `pdf.site-survey.client-report` via `PdfRenderService::fromBlade` and
persists via `DocumentArtifactStorage::TYPE_SURVEY` with filename pattern
`client-survey-{id}-{slug}-{timestamp}.pdf`. New
`pdf/site-survey/client-report.blade.php` (~280 lines) — Tier 1 visual
language matching Mini O&M (260506-qa9): brand teal `#1B7A7A` + brand orange
`#C07000` (D-LOCK-3 locked palette), `.cover-accent-bar` cover chrome,
`@page A4` 18/14mm margins; renders cover meta-table + survey-level office-
note callout (when present) + per-room block (overview + office notes callout
+ photo grid + per-room variations) + survey-wide variations summary table +
footer note. Existing `buildSummary` / `buildBlank` / `buildFieldFormContents`
/ `browsershotOptions` method bodies all byte-untouched (D-LOCK-4).

**Task 6 — Trigger buttons + variations CSV** (`03fe918`).
New `SiteSurveyController::clientReport` action — auth via `authorizeSurvey`
(same guard as `site-surveys.pdf`), delegates to `buildClientReport`, streams
the PDF back with user-facing filename `client-survey-{id}-{slug}.pdf`. New
`SiteSurveyController::variationsCsv` action — `streamDownload` of a
9-column CSV (Room / Type / Description / Qty / Status / Photo Filename /
Notes / Created / Last Updated) with UTF-8 BOM (Excel-safe). 2 new routes:
`site-surveys.client-report` + `site-surveys.variations.csv`.
`SiteSurveyController::show` eager-loads `variations` so the Show page can
display the CSV-button count badge without N+1.
`resources/views/site-survey/show.blade.php` gets two buttons next to
`Download PDF`: "📄 Client Survey Report" (teal CTA) + "📊 Variations CSV (N)"
(disabled with grey-out when N=0 — graceful UX, no empty-CSV downloads).
`resources/views/projects/show.blade.php` gets a "📄 Client Report" row-
action under the Surveys tab dropdown menu, between Download PDF and Delete.

## Files changed

### NEW
- database/migrations/2026_05_08_120000_create_survey_variations_and_office_notes.php
- app/Models/SurveyVariation.php
- app/Http/Controllers/SurveyVariationController.php
- app/Services/SurveyPdfService.php (new public method `buildClientReport` — file pre-existed but method is new)
- resources/views/pdf/site-survey/client-report.blade.php
- resources/views/site-survey/_variation-fields.blade.php

### MODIFY
- app/Models/SiteSurvey.php — fillable += office_review_notes + new variations() HasMany
- app/Models/SiteSurveyRoom.php — fillable += office_notes
- app/Core/Modules/Survey/SurveyService.php — update() persists office_review_notes; roomAttributes() persists office_notes
- app/Http/Controllers/SiteSurveyController.php — validation rules; new clientReport + variationsCsv actions; eager-load variations
- app/Services/DocumentArtifactStorage.php — new TYPE_SURVEY constant + types() entry
- routes/web.php — 5 new auth-scoped routes (3 variations CRUD + 2 reports)
- resources/views/site-survey/_room-form.blade.php — per-room office_notes textarea
- resources/views/site-survey/edit.blade.php — survey-level office_review_notes form-section + Variations & Additions UI block
- resources/views/site-survey/show.blade.php — Client Survey Report + Variations CSV buttons
- resources/views/projects/show.blade.php — Client Report row-action under Surveys tab

## Decision rationale recap (D-LOCK-1..6)

| D-LOCK | Decision | How honoured |
|--------|----------|--------------|
| 1 | Variations are flat capture-and-export — single table, no workflow / events / notifications | `survey_variations` table + 6-value type enum + 4-value status enum (free transitions, no enforced order). Zero `event(`/`Mail::`/listener references in `SurveyVariationController`. |
| 2 | Per-room field is `site_survey_rooms.office_notes`; survey-level is `site_surveys.office_review_notes`; both validated at `validateSurvey()` | Migration adds exactly those two text/nullable columns. `validateSurvey()` extended with both rules at the existing choke point (used by both store + update). |
| 3 | Client PDF Blade is `pdf/site-survey/client-report.blade.php`, matches Mini O&M Tier 1 visual language; persists via `DocumentArtifactStorage::TYPE_SURVEY` | New Blade has `#1B7A7A` teal + `#C07000` orange + `.cover-accent-bar` chrome (4 colour matches via grep). New `TYPE_SURVEY = 'site-surveys'` constant + types() entry. Filename pattern matches spec. |
| 4 | Existing `buildSummary` + `pdf/site-survey/summary.blade.php` are NOT modified — new client report is a separate method + separate Blade | `summary.blade.php` not in `git diff --name-only HEAD~6..HEAD` (verified). The 4 `buildSummary` matches in the SurveyPdfService diff are docblock cross-references inside the **new** `buildClientReport` method — original method bodies byte-untouched. |
| 5 | NO RAMS pipeline files touched | Strict check (`git diff --name-only HEAD~6..HEAD \| sed 's\|^rams\.21stcav\.com/\|\|' \| grep -iE "(rams\|RamsBuilder\|RamsDataBuilder)"`) returns 0. The path-prefix matches in `git diff --stat` are the project's directory name, not RAMS-pipeline files. |
| 6 | New variations CRUD endpoints authorise via `abort_unless(auth()->check(), 403)`, NOT ProjectPolicy | `SurveyVariationController` has 4 occurrences of `abort_unless(auth()->check`. The two report download endpoints (`clientReport` + `variationsCsv`) deliberately use `authorizeSurvey()` to match the existing `site-surveys.pdf` engineer-summary download — D-LOCK-6's looser auth governs MODIFICATION endpoints; downloads stay tighter without conflicting. |

## Verification log

### Lint sweep — all 10 PHP files

```
No syntax errors detected in database/migrations/2026_05_08_120000_create_survey_variations_and_office_notes.php
No syntax errors detected in app/Models/SurveyVariation.php
No syntax errors detected in app/Models/SiteSurvey.php
No syntax errors detected in app/Models/SiteSurveyRoom.php
No syntax errors detected in app/Http/Controllers/SiteSurveyController.php
No syntax errors detected in app/Http/Controllers/SurveyVariationController.php
No syntax errors detected in app/Core/Modules/Survey/SurveyService.php
No syntax errors detected in app/Services/SurveyPdfService.php
No syntax errors detected in app/Services/DocumentArtifactStorage.php
No syntax errors detected in routes/web.php
```

### Route registration

```
$ php artisan route:list --name=site-surveys 2>&1 | grep -E "variations|client-report" | wc -l
5
```

5 new routes registered:
- `POST   site-surveys/{siteSurvey}/variations             site-surveys.variations.store`
- `PATCH  site-surveys/{siteSurvey}/variations/{variation} site-surveys.variations.update`
- `DELETE site-surveys/{siteSurvey}/variations/{variation} site-surveys.variations.destroy`
- `GET    site-surveys/{siteSurvey}/client-report          site-surveys.client-report`
- `GET    site-surveys/{siteSurvey}/variations.csv         site-surveys.variations.csv`

### Migration round-trip

```
$ php artisan migrate:rollback --step=1
  2026_05_08_120000_create_survey_variations_and_office_notes ........ 77.05ms DONE

$ php artisan migrate
  2026_05_08_120000_create_survey_variations_and_office_notes ........ 56.27ms DONE

$ php artisan migrate:status | grep 2026_05_08
  2026_05_08_120000_create_survey_variations_and_office_notes ........ [11] Ran
```

### Schema columns live

```
office_notes: YES
office_review_notes: YES
survey_variations table: YES
```

### Smoke render — client-report Blade across 3 local surveys

```
survey id=1 rooms=1 bytes=5547
survey id=2 rooms=5 bytes=10437
survey id=3 rooms=4 bytes=9445
```

All ≥ 5000 bytes (the plan's pass threshold). PDF print verification deferred to
live UAT — Browsershot puppeteer is NOT installed on this Windows dev machine
(see Phase 20 runbook); on the live server it is correct and `buildClientReport`
will produce a real PDF via the standard `PdfRenderService::fromBlade` path.

### HTML preview artifact for spot-check

```
storage/app/private/client-survey-preview-260508-v7g.html  (11409 bytes)
```

Visual check: `#1B7A7A` teal headings present, `#C07000` orange accents
present, `.cover-accent-bar` chrome present.

### TYPE_SURVEY storage constant

```
$ php artisan tinker --execute="echo app(DocumentArtifactStorage::class)->writePath(DocumentArtifactStorage::TYPE_SURVEY, 'test.pdf');"
…/storage/app/documents/site-surveys/test.pdf
```

Confirms TYPE_SURVEY = `'site-surveys'`, write path lands under
`storage/app/documents/site-surveys/` — distinct from the legacy
`storage/app/site-surveys/` used by `buildSummary` (D-LOCK-3).

### D-LOCK-5 RAMS-untouched proof

```
$ git diff --name-only HEAD~6..HEAD | sed 's|^rams\.21stcav\.com/||' | grep -iE "(rams|RamsBuilder|RamsDataBuilder)" | wc -l
0
```

(The 5 `git diff --stat` matches are the project's directory name `rams.21stcav.com/` — not RAMS-pipeline files.)

## D-LOCK fidelity audit

- **D-LOCK-1 honoured:** Single `survey_variations` table (no separate workflow tables). Zero `event(` / `Mail::` / listener references in `SurveyVariationController`. Status enum has no enforced order (the dropdown can flip freely).
- **D-LOCK-2 honoured:** Per-room field is `site_survey_rooms.office_notes`; survey-level field is `site_surveys.office_review_notes`. Both text/nullable. Both validated at `SiteSurveyController::validateSurvey()` — the single choke point used by both `store` and `update`.
- **D-LOCK-3 honoured:** Client PDF Blade is `resources/views/pdf/site-survey/client-report.blade.php`. Brand palette `#1B7A7A` teal + `#C07000` orange + `.cover-accent-bar` chrome all present (grep returns ≥2). New `TYPE_SURVEY = 'site-surveys'` constant added (D-LOCK-3 storage requirement) — no LEGACY_ROOTS entry, file persistence verified via tinker writePath.
- **D-LOCK-4 honoured:** `pdf/site-survey/summary.blade.php` is NOT in `git diff --name-only HEAD~6..HEAD`. The diff for `app/Services/SurveyPdfService.php` only adds the new `buildClientReport` method — original `buildSummary` / `buildBlank` / `buildFieldFormContents` / `browsershotOptions` method bodies are byte-untouched. The 4 grep hits for those names in the diff are all docblock cross-references inside the new method.
- **D-LOCK-5 honoured:** `git diff --name-only HEAD~6..HEAD | sed 's|^rams\.21stcav\.com/||' | grep -iE "(rams|RamsBuilder|RamsDataBuilder)"` returns 0. Zero RAMS pipeline files touched (RamsBuilderService / RamsDataBuilderService / RamsDocument / RamsDocumentPolicy / pdf/rams.blade.php / app/Services/Rams/* — none of these in the modified files list).
- **D-LOCK-6 honoured:** All 3 variations CRUD endpoints in `SurveyVariationController` use `abort_unless(auth()->check(), 403)` — grep returns 4 lines (one per CRUD action × 3 + the validate helper does NOT have it, just the 3 CRUD entries plus 1 in update for the cross-survey check). The two report-download endpoints intentionally use `authorizeSurvey` to match `site-surveys.pdf` — D-LOCK-6's looser pattern only governs MODIFICATION endpoints.

## Deviations from plan

**None blocking. One structural adjustment auto-applied (Rule 3 — blocking discovery, fix inline):**

The plan instructed inserting the per-room `office_notes` textarea "after the
engineer-feedback block and BEFORE the type-specific panels". In the actual
`_room-form.blade.php` the type-specific panels (PA / Signage / Upgrade) appear
ABOVE the engineer-feedback block (lines 271–366 then 525–898), so the
plan's "before type-specific panels" placement is impossible — those panels
are already higher up on the page. The textarea was placed at the natural
"end of the engineer-feedback group" position: after the last engineer-
feedback subsection (Brackets Required) and before the green Engineer Sign-
off card. This honours the plan's INTENT (visually grouped with engineer-
feedback subsections, uses `.room-subsection` chrome with amber accent to
visually distinguish "office" from "engineer" subsections) without
contradicting any D-LOCK.

## Self-Check: PASSED

- All claimed files exist (verified via `ls`).
- All 6 commits exist on the branch (verified via `git log --oneline HEAD~6..HEAD`).
- Migration ran on local DB (`php artisan migrate:status` shows `[11] Ran`).
- Round-trip verified (rollback then re-migrate clean).
- Render smoke test passes for 3 surveys (5547 / 10437 / 9445 bytes).
- HTML preview artifact saved at `storage/app/private/client-survey-preview-260508-v7g.html`.

## 🚨 Files to upload to live (NON-NEGOTIABLE)

Local-edit-then-upload workflow per memory `feedback_local_then_upload.md`.
This task added a database table + 2 columns — **migration MUST run on live
before any new UI element is clicked**, otherwise the page will 500 on the
first variation save.

### Code + Blade (upload via SFTP / git pull):

- database/migrations/2026_05_08_120000_create_survey_variations_and_office_notes.php
- app/Models/SurveyVariation.php
- app/Models/SiteSurvey.php
- app/Models/SiteSurveyRoom.php
- app/Http/Controllers/SiteSurveyController.php
- app/Http/Controllers/SurveyVariationController.php
- app/Core/Modules/Survey/SurveyService.php
- app/Services/DocumentArtifactStorage.php
- app/Services/SurveyPdfService.php
- resources/views/site-survey/_room-form.blade.php
- resources/views/site-survey/_variation-fields.blade.php
- resources/views/site-survey/edit.blade.php
- resources/views/site-survey/show.blade.php
- resources/views/pdf/site-survey/client-report.blade.php
- resources/views/projects/show.blade.php
- routes/web.php

### After upload — RUN ON THE SERVER, IN ORDER:

1. **Migration (mandatory — schema changes will not appear without this. Do this BEFORE clicking any new UI element):**

   ```
   php artisan migrate
   ```

   Expected output: 1 migration ran (`2026_05_08_120000_create_survey_variations_and_office_notes`).
   Without this step the new "+ Add Variation" / "Office Review Notes" /
   "Client Survey Report" actions will produce 500 errors because the
   columns/table do not exist in the database yet.

2. **Cache clears (mandatory — Blade + route + view caches all touched):**

   ```
   php artisan view:clear
   php artisan route:clear
   php artisan config:clear
   ```

3. **Smoke test (open in browser, in this order):**

   - Open `/site-surveys/{id}/edit` — both new textareas (per-room amber
     "Office Review Notes" inside each room card AND survey-level "Office
     Review Notes" form-section after Project Details) are visible. The
     "Variations & Additions" form-section is visible after the rooms loop
     with empty-state message.
   - Add a variation via "+ Add Variation" modal (type=extra_hardware,
     description=test, qty=1, status=proposed) → row appears.
   - Click row's Edit → inline form replaces row → change status to
     `quoted` → Save → status pill colour changes amber → blue.
   - Click row's Delete → SCC v2 modal opens (NOT native browser confirm) →
     confirm → row removed.
   - Open `/site-surveys/{id}` show page — "📄 Client Survey Report" (teal)
     + "📊 Variations CSV (N)" buttons next to existing Download PDF.
     Click Client Survey Report → PDF opens in new tab.
     Click Variations CSV → CSV downloads with UTF-8 BOM + 9-column header
     row + variation rows.
   - Open `/projects/{id}` Surveys tab → expand row-actions menu on a
     survey row → "📄 Client Report" entry between "Download PDF" and
     "Delete survey".

4. **Regression sanity check (D-LOCK-4 + D-LOCK-5 live verification):**

   - Open existing engineer summary PDF on a survey
     (`/site-surveys/{id}/pdf`) — must look byte-identical to pre-deploy
     (D-LOCK-4: `buildSummary` byte-untouched).
   - Regenerate RAMS on a project with this survey — output must be byte-
     identical to pre-deploy. Survey notes / variations are NOT pulled into
     RAMS yet (deferred to a separate quick task — gap b in the prior
     discussion).

If any of the smoke checks fails, rollback with:

```
php artisan migrate:rollback --step=1   # drops the new table + 2 columns
```

The Blade/controller files can be reverted via
`git checkout HEAD~6 -- <file>` for the 6 task commits.

### HTML preview artifact (for desktop spot-check before live UAT):

Local-only artifact (do NOT upload):
`storage/app/private/client-survey-preview-260508-v7g.html`

Open in a browser to inspect the Tier 1 client-report layout, brand colour
chrome, photo grid behaviour, etc. before live PDF print on the production
server.

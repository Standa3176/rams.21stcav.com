---
quick_id: 260504-hqe
mode: quick
type: summary
status: complete
completed_at: 2026-05-04T12:35:00Z
duration_minutes: 35
commits:
  - hash: b4329f9
    type: feat
    description: "add pre_install_confirmations JSON column"
    files: 2
  - hash: ecbe80b
    type: feat
    description: "serveSurveyPhoto + markSurveyReviewed routes"
    files: 2
  - hash: 3df113c
    type: feat
    description: "survey photos drawer + per-room review gate"
    files: 1
files_modified:
  - database/migrations/2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php
  - app/Models/Worksheet.php
  - app/Http/Controllers/PublicWorksheetController.php
  - routes/web.php
  - resources/views/worksheets/public-show.blade.php
file_count: 5
line_delta: "+265 / -8"
deviations:
  - rule: 1
    type: bug
    description: "Plan said \\$roomsRequiringReview = array_keys(\\$efByRoom). But the existing teal Survey Reference drawer is gated by \\$hasEF (EF data exists). When a survey room has rooms with photos but NO EF data, the page-level gate would fire (warning banner + disabled button) but the drawer would remain hidden — engineer is blocked from sign-off with no UI to unblock. Fix: extend \\$hasEF to also open the drawer when photos exist; align \\$roomsRequiringReview with the same condition (EF data OR photos). Result: gate only fires for rooms whose drawer is visible."
  - rule: 3
    type: blocking
    description: "Existing Sign-Off submit button has hardcoded `disabled` attribute and signature-pad JS re-enables it on first signature stroke. With our @disabled(true) the button stays disabled even after signing. Fix: keep button always disabled at server-render and add data-signoff-blocked='0|1'; in the JS unlock branch, only re-enable when data-signoff-blocked !== '1'. Pure visual gate preserved; behaviour identical to pre-260504-hqe when \\$signOffBlocked is false."
---

# Quick Task 260504-hqe: Engineer link — survey photos + per-room review gate Summary

Engineer link `/worksheet/{token}` now surfaces site-survey photos as 80×80 clickable thumbnails inside the existing teal Survey Reference drawer, gates Sign-Off behind a per-room "I have reviewed the survey" confirmation, and persists the stamps in a new nullable JSON column. Pure additive — DOCX worksheet, admin show page, RAMS, O&M, and drawings are unchanged.

## What changed

- **`database/migrations/2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php`** — new migration adds nullable `pre_install_confirmations` JSON column on `worksheets` after `access_token`. Legacy rows stay NULL (= no rooms reviewed yet — backward compatible). `down()` cleanly drops the column.

- **`app/Models/Worksheet.php`** — adds `'pre_install_confirmations'` to `$fillable` immediately after `'access_token'`; adds `'pre_install_confirmations' => 'array'` cast immediately after the existing `'generated_data' => 'array'`. No helper methods added — controller + view handle lookup directly via the array cast.

- **`app/Http/Controllers/PublicWorksheetController.php`** — adds `use App\Models\SiteSurveyPhoto;` and two new methods after the existing `servePhoto()`:
  - `serveSurveyPhoto(string $token, SiteSurveyPhoto $photo)` — token-resolves the worksheet, then `abort_unless($photo->room?->survey?->project_id === $worksheet->project_id, 403)` blocks any cross-project leak; the defensive `?->` chain returns `null` for any orphaned record (photo with no room, room with no survey, missing project_id) and trips the same 403. Streams the file via `Storage::disk('local')->path($photo->storagePath())`.
  - `markSurveyReviewed(Request $request, string $token, string $roomName)` — builds the inclusion list from `$worksheet->generated_data['rooms'][*]['name']`, returns 422 on empty rooms, returns 422 if `$roomName` is forged (not in the list), then writes `pre_install_confirmations[$roomName] = ['reviewed_at' => ISO-8601, 'reviewed_by' => substr($token, 0, 8)]` and redirects to `public-worksheet.show` with flash success.

- **`routes/web.php`** — adds two routes inside the existing public-worksheet block, between the photos.delete route and the Device-label-photo block:
  - `GET worksheet/{token}/survey-photos/{photo}` → `serveSurveyPhoto` (`public-worksheet.survey-photos.serve`, throttle 120/min)
  - `POST worksheet/{token}/rooms/{roomName}/survey-reviewed` → `markSurveyReviewed` (`public-worksheet.survey-reviewed`, throttle 60/min, `where('roomName', '.*')` to permit room names with spaces)

- **`resources/views/worksheets/public-show.blade.php`** — three coordinated edits:
  - **Page-level `@php` lookup**: eager-load `rooms.photos` on the existing latest-SiteSurvey query (no second DB hit); build `$photosByRoom` keyed by lowercase trimmed room_name; build `$roomsRequiringReview` from rooms whose drawer is visible (EF data OR photos exist).
  - **Per-room block**: extend `$hasEF` to also open the drawer when survey photos exist (Rule-1 fix); add a Survey-photos thumbnail strip at the top of the drawer body (80×80 clickable thumbnails OR muted "No survey photos for this room" line); add the per-room review form (checkbox + Mark Reviewed) OR green ✓ Reviewed badge at the bottom of the drawer body, depending on `pre_install_confirmations[$room['name']]`.
  - **Sign-Off gate**: compute `$signOffBlocked` once from rooms missing review confirmations; render an amber warning banner listing unreviewed rooms above the existing Sign-Off form; add `data-signoff-blocked` + `title` to the submit button; update the signature-pad JS to skip re-enabling the button when `data-signoff-blocked === '1'` (Rule-3 fix to existing JS unlock path).

## File footprint audit

```
$ git diff --stat HEAD~3 HEAD
 .../Http/Controllers/PublicWorksheetController.php |  75 +++++++++++
 rams.21stcav.com/app/Models/Worksheet.php          |   8 +-
 ...e_install_confirmations_to_worksheets_table.php |  41 ++++++
 .../views/worksheets/public-show.blade.php         | 141 ++++++++++++++++++++-
 rams.21stcav.com/routes/web.php                    |   8 ++
 5 files changed, 265 insertions(+), 8 deletions(-)
```

Exactly 5 files, no overage.

```
$ git diff --stat HEAD~3 HEAD -- app/Services/ resources/views/site-survey/ resources/views/pdf/ \
    resources/views/worksheets/show.blade.php app/Models/SiteSurvey.php \
    app/Models/SiteSurveyRoom.php app/Models/SiteSurveyPhoto.php \
    app/Http/Controllers/PublicSurveyController.php
(empty)
```

Forbidden-paths diff is empty — no out-of-scope files touched.

## Render smoke tests

Performed in tinker against the local `laravel_rams` database (3 worksheets, 1 with project + survey).

| # | Scenario | Result |
|---|----------|--------|
| 1 | Worksheet whose project has NO SiteSurvey row | Drawer hidden (existing `@if($hasEF)` false), Sign-Off enabled, no banner. Render = 103,083 bytes. **PASS** |
| 2 | Survey rooms exist, **no** photos and **no** EF data (project 3 default state) | Survey Reference drawer remains hidden, Sign-Off button stays enabled, no warning banner. `data-signoff-blocked="0"`. **PASS** (legacy backward-compat preserved) |
| 3 | One survey room has a SiteSurveyPhoto seeded, room not yet reviewed | Drawer opens with 80×80 thumb strip, "Mark Reviewed" form renders inside drawer, amber warning banner appears above sign-off, Sign-Off button has `data-signoff-blocked="1"`. **PASS** |
| 4 | Same scenario, after `pre_install_confirmations` written for that room | Drawer body now ends with green "✓ Reviewed by … at …" badge instead of the form; warning banner gone; `data-signoff-blocked="0"` (sign-off unlocks). **PASS** |
| 5 | Cross-project guard logic | Verified in tinker: photo's `room->survey->project_id` matched against `$worksheet->project_id`; mismatched IDs would trip `abort_unless(... === ..., 403)`. The defensive `?->` chain returns `null` for any broken link → triggers same 403. **PASS** |
| 6 | Forged room name guard | `in_array('Atlantis', $validRoomNames, true)` returns false → controller calls `abort(422, 'Unknown room name.')`. Real room names pass. **PASS** |

Migration sanity verified: `php artisan migrate` ran the new migration in 311 ms; `Schema::hasColumn('worksheets', 'pre_install_confirmations')` returns `true`. View cache compiles clean (`php artisan view:cache` exits 0). Routes register: `php artisan route:list --path=worksheet` shows both `public-worksheet.survey-photos.serve` (GET) and `public-worksheet.survey-reviewed` (POST).

PHP lint clean on all 4 modified PHP files (`php -l`).

## Files to upload to live

```
database/migrations/2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php
app/Models/Worksheet.php
app/Http/Controllers/PublicWorksheetController.php
routes/web.php
resources/views/worksheets/public-show.blade.php
```

## Commands to run on live

```bash
# CRITICAL — schema change. Run first.
php artisan migrate

# Pick up the route + view changes.
php artisan view:clear
php artisan route:clear
```

No queue restart needed (no jobs touched). No npm rebuild needed (Blade-only frontend changes; no new CSS classes).

## Deviations from plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Aligned `$roomsRequiringReview` with drawer visibility**

- **Found during:** Smoke testing Task 3 (rendered scenario B with survey rooms but no EF data)
- **Issue:** The plan specified `$roomsRequiringReview = array_keys($efByRoom)` — i.e. every survey room blocks sign-off. But the existing 260504-dh8 Survey Reference drawer is `@if($hasEF)`-gated, requiring actual engineer-feedback data (mounting heights, cable routes, etc.). A worksheet whose survey has rooms but no EF data captured would have the Sign-Off button blocked AND the drawer hidden — the engineer would have no UI surface to clear the gate. Pure deadlock.
- **Fix:** (a) extend `$hasEF` to ALSO open the drawer when `$photosByRoom[$key]->isNotEmpty()` so engineers see photos in the drawer even when EF data is empty; (b) compute `$roomsRequiringReview` from the same condition (EF data OR photos) so the page-level gate matches drawer visibility. Surveyed rooms with neither EF data nor photos are correctly ignored — the drawer doesn't render and the gate doesn't fire.
- **Files modified:** `resources/views/worksheets/public-show.blade.php`
- **Commit:** 3df113c

**2. [Rule 3 — Blocking] Signature-pad JS respects soft-gate flag**

- **Found during:** Task 3 button-disable wiring
- **Issue:** The existing Sign-Off submit button always renders with hardcoded `disabled` (signature-pad JS unlocks it on the first signature stroke via `submit.disabled = false`). Adding `@disabled($signOffBlocked)` on the server side does not survive that JS re-enable — even when `$signOffBlocked` is true, drawing a signature still unlocks the button.
- **Fix:** Keep the server-side `@disabled(true)` always-on, add a `data-signoff-blocked="{0|1}"` attribute, and gate the JS unlock branch on `if (submit.dataset.signoffBlocked !== '1') { submit.disabled = false; }`. When `$signOffBlocked === false` the behaviour is identical to pre-260504-hqe (button unlocks on signature). When true, the button stays disabled regardless of signature input. Tooltip remains intact.
- **Files modified:** `resources/views/worksheets/public-show.blade.php`
- **Commit:** 3df113c

These two deviations are tied to the same task and shipped in the same commit (3df113c). Tasks 1 and 2 executed exactly as written.

## Self-Check: PASSED

```
$ ls database/migrations/2026_05_04_120000_add_pre_install_confirmations_to_worksheets_table.php
FOUND
$ git log --oneline HEAD~3..HEAD
3df113c feat(quick-260504-hqe): survey photos drawer + per-room review gate
ecbe80b feat(quick-260504-hqe): serveSurveyPhoto + markSurveyReviewed routes
b4329f9 feat(quick-260504-hqe): add pre_install_confirmations JSON column
$ git diff --stat HEAD~3 HEAD | tail -1
5 files changed, 265 insertions(+), 8 deletions(-)
```

All 3 commits present. All 5 files in footprint. Forbidden-paths diff empty. Migration applied locally; view cache compiles; both new routes registered; 6 smoke scenarios pass.

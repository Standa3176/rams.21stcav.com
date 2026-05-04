---
quick_id: 260504-iy4
mode: quick
type: summary
status: complete
completed: 2026-05-04
requirements_closed: [H1, H3, H4, L3]
files_modified:
  - app/Models/Worksheet.php
  - app/Http/Controllers/PublicWorksheetController.php
  - routes/web.php
  - resources/views/worksheets/public-show.blade.php
commits:
  - 1a54535  feat(quick-260504-iy4): add namespaced JSON accessors + flip survey-review write path
  - 8235ba8  feat(quick-260504-iy4): add markRoomComplete controller method + route
  - 42c90b9  feat(quick-260504-iy4): wire H1 CTA + auto-collapse + H3 scroll-restore + L3 lock into engineer view
metrics:
  files: 4
  commits: 3
  insertions: 263
  deletions: 17
---

# Quick 260504-iy4 — Engineer Link Finish (H1 / H3 / H4 / L3) Summary

One-liner: Closes the four deferred items from the engineer-link audit (mark-room-complete CTA + auto-collapse, scroll/drawer-state restore, namespaced pre_install_confirmations JSON, signaled read-only lock after sign-off) with pure additive changes across 4 files.

## What changed (file-by-file)

### `app/Models/Worksheet.php` (+35 / −0)

Appended four accessor helpers after `statusBadgeClass()`:

- `surveyReviewedAt(string $roomName): ?string` — reads `pre_install_confirmations.survey_review.{room}.reviewed_at`
- `surveyReviewedBy(string $roomName): ?string` — reads `pre_install_confirmations.survey_review.{room}.reviewed_by`
- `roomCompletedAt(string $roomName): ?string` — reads `pre_install_confirmations.room_complete.{room}.completed_at`
- `roomCompletedBy(string $roomName): ?string` — reads `pre_install_confirmations.room_complete.{room}.completed_by`

All four use `data_get($attr, [string, string, string])` array-path form so room names containing literal dots (e.g. `Floor 2.5`) are treated as opaque single segments and not parsed as nested keys. Returns `null` cleanly when:

- `$pre_install_confirmations === null` (fresh worksheet)
- The legacy flat shape is in place (260504-hqe shape, never deployed) — gate degrades to "unreviewed" so engineer simply re-marks
- The new namespaced shape exists but the room key is missing

NO changes to fillable / casts / boot — those were already shipped by 260504-hqe.

### `app/Http/Controllers/PublicWorksheetController.php` (+53 / −4)

Two changes only:

1. **`markSurveyReviewed`** docblock + write block flipped to namespaced shape:
   ```php
   $confirmations['survey_review'][$roomName] = [
       'reviewed_at' => $now->toIso8601String(),
       'reviewed_by' => substr($token, 0, 8),
   ];
   ```
   (was `$confirmations[$roomName] = ...`)

2. **New `markRoomComplete(Request, $token, $roomName): RedirectResponse`** — added immediately after `markSurveyReviewed`. Mirrors the survey-reviewed forged-room-name guard exactly (rejects unknown room names with 422). NO server-side enforcement of the gate (engineers on flaky networks must be able to POST regardless of partial state). Writes `pre_install_confirmations.room_complete.{roomName} = {completed_at, completed_by}`.

`serveSurveyPhoto` cross-project guard untouched. No other methods changed.

### `routes/web.php` (+7 / −0)

One new route inside the public-worksheet block, immediately after `survey-reviewed` POST and before the device-label-photo block:

```php
Route::post('worksheet/{token}/rooms/{roomName}/complete', [PublicWorksheetController::class, 'markRoomComplete'])
    ->name('public-worksheet.room-complete')->middleware('throttle:60,1')
    ->where('roomName', '.*');
```

`where('roomName', '.*')` matches the survey-reviewed pattern so room names with spaces (e.g. `Test Room`) URL-encode and resolve cleanly.

### `resources/views/worksheets/public-show.blade.php` (+168 / −13)

Six coordinated sub-edits in one commit:

**Sub-edit A — Page-level gate compute extended.** In the existing `@php` block at ~lines 615-636 the legacy `$confirmations[$rName]` lookup flipped to `$worksheet->surveyReviewedAt($rName) === null`. Appended a new compute block that walks `$rooms` and finds `$firstIncompleteIdx` — the index of the first room not yet marked complete (drives the auto-collapse logic).

**Sub-edit B — Per-room block extended.** Added `$roomCompletedAt`, `$roomCompletedBy`, `$isRoomComplete`, `$roomCompletedDisplay` (Carbon-formatted), `$markCompleteGateOk` (frontend-only soft gate: photos ≥ 1 AND survey-reviewed-if-applicable), `$skipRestoreAttr`. Replaced the `$confirmations[$room['name']]` lookup with `$worksheet->surveyReviewedAt(...)`. The `<details class="card">` element now uses `$idx === $firstIncompleteIdx ? 'open' : ''` (was `$idx === 0`) and emits `data-skip-restore="1"` for completed rooms. New `✓ Complete` pill on the summary alongside existing pills.

**Sub-edit C — Mark Room Complete CTA.** New block between photo tray and Survey Reference drawer. Conditional render: when complete shows green `✓ Room Complete by {by} at {time}` badge; otherwise renders form with `<button @disabled(! $markCompleteGateOk) title="{$gateMsg}">✅ Mark Room Complete</button>` plus an inline gate-message hint. Skipped entirely when no photos AND no survey (nothing meaningful to confirm).

**Sub-edit D — Survey Reference drawer's per-room review block** flipped from direct `$confirmations[]` reads to `$worksheet->surveyReviewedAt() / surveyReviewedBy()` accessors. Downstream `$thisRoomReview` shape unchanged so the existing `@if($thisRoomReview)` branch keeps working.

**Sub-edit E — H3 scroll + drawer-state restore.** New `<script>` block before `</body>` (after the existing label-capture script). `sessionStorage` key `wsState_{worksheetId}`. Save on submit (capture phase) — captures `scrollY`, open `<details[id]>` IDs, timestamp. Restore on `DOMContentLoaded` — reopens drawers (skipping `data-skip-restore="1"` rooms so auto-collapse wins), then `requestAnimationFrame(() => scrollTo(...))` for layout-settled positioning. 5-minute stale guard. Try/catch around `sessionStorage` so disabled storage silently no-ops.

**Sub-edit F — L3 read-only fieldset wrap.** When `$latestSignoff` is set: `<fieldset disabled>` opens immediately before the `@if(empty($rooms))` block and closes immediately before the `</div>` of `.wrap`. The fieldset HTML attribute cascades `disabled` to every nested form / button / input so engineers and clients cannot accidentally re-submit. New blue info banner above the sign-off card: `🔒 This worksheet was signed by {client} on {time}. Photo uploads, label captures, and review actions are now disabled. ...` The existing top green `signed-banner` is untouched (different purpose — shows "Thank you for signing", outside the fieldset).

## File footprint audit

```
git diff --stat HEAD~3 HEAD
 .../Http/Controllers/PublicWorksheetController.php |  57 ++++++-
 rams.21stcav.com/app/Models/Worksheet.php          |  35 ++++
 .../views/worksheets/public-show.blade.php         | 181 +++++++++++++++++++--
 rams.21stcav.com/routes/web.php                    |   7 +
 4 files changed, 263 insertions(+), 17 deletions(-)
```

EXACTLY 4 files modified — matches plan's required footprint.

## Forbidden-paths diff (must be empty)

```
git diff HEAD~3 HEAD -- app/Services/ database/ resources/views/site-survey/ \
    resources/views/pdf/ resources/views/worksheets/show.blade.php \
    app/Models/SiteSurvey.php app/Models/SiteSurveyRoom.php \
    app/Models/SiteSurveyPhoto.php app/Http/Controllers/PublicSurveyController.php \
    database/migrations/
```

Result: empty. No services, no migrations, no other view, no SiteSurvey* models, no PublicSurveyController, no `worksheets/show.blade.php` touched.

## Smoke test results

### `php -l` on all PHP files

```
No syntax errors detected in app/Models/Worksheet.php
No syntax errors detected in app/Http/Controllers/PublicWorksheetController.php
No syntax errors detected in routes/web.php
```

### `php artisan route:clear && php artisan route:list --path=worksheet`

```
POST  worksheet/{token}/rooms/{roomName}/complete         public-worksheet.room-complete
POST  worksheet/{token}/rooms/{roomName}/survey-reviewed  public-worksheet.survey-reviewed
```

Both routes resolved with `where('roomName', '.*')` — confirmed by tinker URL-build:
`route('public-worksheet.room-complete', ['token' => 'abc', 'roomName' => 'Test Room'])` → `…/worksheet/abc/rooms/Test%20Room/complete` (URL-encoded space).

### `php artisan view:clear && php artisan view:cache`

Both succeeded:
```
Compiled views cleared successfully.
Blade templates cached successfully.
```

### Tinker render — 4 scenarios

| Scenario | Length | fieldset | Mark Room Complete CTA | ✓ Complete pill | wsState_ JS | Lock banner | Open room |
|----------|--------|----------|----------------------|----------------|-------------|-------------|-----------|
| 1 — Fresh worksheet (no confirmations, no signoff)             | 44243 B | NO  | NO (no photos+no EF — correct) | NO  | YES | NO  | Boardroom open (firstIncompleteIdx=0) |
| 2 — Boardroom marked complete (new namespace)                  | 45204 B | NO  | YES (✓ Room Complete by abcd1234 at 04 May 2026 11:00 — green badge) | YES | YES | NO  | Boardroom NO open + data-skip-restore="1"; Reception OPEN (firstIncompleteIdx=1) |
| 3 — Signed worksheet (latestSignoff present)                    | 45445 B | YES (1 open / 1 close) | (inside fieldset, disabled) | (inside fieldset) | YES | YES (blue alert above sign-off card with Jane Client) | (inside fieldset) |
| 4 — Legacy flat-shape (`{Boardroom: {reviewed_at:...}}`)        | 44243 B | NO  | NO  | NO  | YES | NO  | Boardroom open (defensive null returns from accessors) |

All four scenarios rendered cleanly — no PHP warnings, no exceptions, no missing variables.

### Other regression checks

- `grep '$confirmations[$rName]' public-show.blade.php` → 0 matches (old flat-shape lookup removed)
- `grep "$confirmations[$room['name']]" public-show.blade.php` → 0 matches
- `grep wsState_ public-show.blade.php` → 1 match (line 1573)
- `grep survey_review PublicWorksheetController.php` → 3 matches (docblock + comment + write line)
- `grep room_complete PublicWorksheetController.php` → 2 matches (docblock + write line)

## Files to upload to live

After 260504-hqe upload (which carries the `pre_install_confirmations` migration), upload:

1. `app/Models/Worksheet.php`
2. `app/Http/Controllers/PublicWorksheetController.php`
3. `routes/web.php`
4. `resources/views/worksheets/public-show.blade.php`

## Commands to run on live

```bash
php artisan view:clear
php artisan route:clear
```

NO migration to run — the `pre_install_confirmations` JSON column was already shipped with 260504-hqe (which is also Pending Upload). The 260504-iy4 changes write into the same column with a new namespaced shape; existing rows with the legacy flat shape (none in production yet, since 260504-hqe hasn't been uploaded) would still render cleanly via the defensive accessors.

**Coordinate:** ensure 260504-hqe goes live BEFORE this iy4 (or together in the same deploy) — iy4's view file references `route('public-worksheet.survey-reviewed', ...)` which depends on the hqe controller method + route. Same column dependency.

## Deviations encountered

**None.** Plan executed exactly as written. The six sub-edits in Task 3 applied cleanly. PHP and route lints clean on first pass, view:cache clean, tinker render clean for all 4 scenarios on first pass.

## Self-Check: PASSED

- ✓ All 4 files modified exist and contain expected text:
  - `app/Models/Worksheet.php` — `surveyReviewedAt`/`surveyReviewedBy`/`roomCompletedAt`/`roomCompletedBy` accessors found
  - `app/Http/Controllers/PublicWorksheetController.php` — `markRoomComplete` method found, `survey_review` write line found
  - `routes/web.php` — `public-worksheet.room-complete` route found
  - `resources/views/worksheets/public-show.blade.php` — `wsState_`, `Mark Room Complete`, `firstIncompleteIdx`, `roomCompletedAt`, `<fieldset disabled>` all found
- ✓ All 3 commits exist on current branch (`feat/worksheet-classifier-universal`):
  - `1a54535` — feat(quick-260504-iy4): add namespaced JSON accessors + flip survey-review write path
  - `8235ba8` — feat(quick-260504-iy4): add markRoomComplete controller method + route
  - `42c90b9` — feat(quick-260504-iy4): wire H1 CTA + auto-collapse + H3 scroll-restore + L3 lock into engineer view
- ✓ File footprint exactly 4 (matches plan).
- ✓ Forbidden-paths diff empty (no services, no migrations, no other views).
- ✓ All 4 must-have truths from the plan render correctly in tinker scenarios.

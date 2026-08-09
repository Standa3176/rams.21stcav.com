---
quick_id: 260809-so1
slug: survey-offline-photo-capture
status: complete
date: 2026-08-09
branch: feat/worksheet-classifier-universal
deployed: false
---

# Quick Task 260809-so1 — Survey offline-first photo capture (SUMMARY)

## What shipped
Ported the Worksheet's proven offline photo machinery onto the **Site Survey wizard** via a new **shared inline Blade
partial**, so a room photo taken onsite survives flaky/offline networks and iOS **HEIC** is re-encoded before upload.
The Worksheet was left **byte-identical** (deferred dedupe step). This partial is the **seed of a shared
`21stcav/engineer-capture` package**.

## Files
- **NEW** `resources/views/partials/_engineer-offline-capture.blade.php` — inline `<script>` exposing on `window`:
  `convertToJpegBlob` / `convertToJpegBlobSafe` (HEIC→JPEG canvas re-encode, copied faithfully from the Worksheet),
  `OfflineQueue` (IndexedDB **`engineer-capture`** / `pending_uploads`; per-record `endpoint` + generic `fields`;
  `enqueue/list/count/remove/drain/subscribe`), and `mountOfflineChip`. Auto-drain on `online` + 60s + on load;
  self-mounts a fixed pending chip. All DOM via `textContent`.
- **EDIT** `resources/views/surveys/show.blade.php` — `@include('partials._engineer-offline-capture')` before `</body>`;
  `uploadPhoto()` rewritten (see below).
- **NEW** `tests/Feature/Surveys/SurveyOfflinePhotoCaptureTest.php` — 6 static-source tests (23 assertions).

## The uploadPhoto rewrite (exact behaviour)
1. `const blob = await window.convertToJpegBlobSafe(file)` (falls back to the raw File if conversion fails).
2. Build `endpoint = '/survey/'+this.token+'/rooms/'+roomId+'/photos'`, `fields = { category }`, `originalName` = file
   name with a `.jpg` extension.
3. If `navigator.onLine`: POST `photo`(blob)+`category` with the `X-CSRF-TOKEN` meta header (endpoint/field/header
   **unchanged**). On `resp.ok` → the **exact original** Alpine success push
   `{ id, type:res.category??category, caption:res.caption??'', file_path:res.url??'' }`. On a thrown/`!ok` response →
   `OfflineQueue.enqueue({...})` (don't lose it).
4. If offline: `OfflineQueue.enqueue({...})` immediately.
5. `input.value = ''`.
- Comment documents that a **background-drained** photo (from a prior offline session) won't appear in Alpine `photos`
  until reload — acceptable, matches the Worksheet's reload model.

## Untouched (verified)
- `resources/views/worksheets/public-show.blade.php` — **byte-identical** (`git status` clean for the file; test asserts
  its `OfflineQueue` + `convertToJpegBlob` + `engineer-worksheet` DB name still present).
- Survey text autosave `autosave()/autosaveSite()` → `POST /survey/{token}/step-save` — unchanged (queue wraps the binary
  photo path only).
- `savePhotoCaption` PATCH, the 6 `x-survey.photo-upload` components, `components/survey/photo-upload.blade.php`
  (`accept="image/*"`, **no `capture=`**).

## Documented deviation from the literal spec
Chip is a **fixed always-visible** element rather than an inline container inside step 3 — the wizard hides inactive
steps, so an inline step-3 chip would vanish when the engineer advances to step 4+. `window.mountOfflineChip(target)` is
still exposed for explicit inline placement.

## Tests / baseline
- New test: **6 passed, 23 assertions**.
- Regression isolation (survey edit stashed vs applied), same non-Survey suites:
  - Baseline: **5 failed / 87 passed** — all 5 in `PublicSurveyControllerTest` (complete-room questions + submission
    routing; **pre-existing** on this branch, unrelated to photo capture).
  - With change: **5 failed / 93 passed** (+6 = the new test). **No NEW failures introduced.**
- No `npm run build` needed (inline Blade partial, no `@vite` entry). No migration. No DB writes.

## Operator test (manual)
On the Survey wizard, step 3, with DevTools **Network → Offline**: take a room photo → it does **not** error; a fixed
"1 photo saved on this device" chip appears (Retry button). Restore the network → within ≤60s (or on Retry) the photo
**auto-uploads** and the chip clears. On iPhone, a HEIC photo now uploads successfully (re-encoded to JPEG).

## Commits
- `feat(survey): offline-first photo capture via shared engineer-capture partial` — partial + survey blade + test.
- `docs(quick-260809-so1): plan + summary + state` — planning artifacts.

## NOT DEPLOYED — follow-up
Committed on `feat/worksheet-classifier-universal`. Not pushed, not deployed. Cherry-pick to the deploy branch + run the
RAMS deploy per repo conventions as a separate step. **Deferred package step:** migrate the Worksheet onto this shared
partial (dedupe), then lift `_engineer-offline-capture` into the shared `21stcav/engineer-capture` package.

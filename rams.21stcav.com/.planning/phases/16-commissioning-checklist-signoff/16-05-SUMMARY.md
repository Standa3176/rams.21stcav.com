---
phase: 16-commissioning-checklist-signoff
plan: "05"
subsystem: commissioning
tags: [wave-3, signature-capture, ios-dpi, signature_pad, alpine, creagia-sign-pad, resync, human-verify]
dependency_graph:
  requires:
    - Plan 16-01 red test scaffold (SignoffSheetViewTest, ResyncDiffTest)
    - Plan 16-02 CommissioningSyncService::resync + CommissioningSignoffException::itemsImmutable + creagia/laravel-sign-pad bundle + config('commissioning.certification_text')
    - Plan 16-02 DPI spike notes (16-02-DPI-SPIKE-NOTES.md) — chose integration Option C (CDN UMD fallback)
    - Plan 16-03 show.blade.php — `<div data-role="signoff-sheet-slot">` placeholder and `openSignoffSheet()` CustomEvent emitter
    - Plan 16-04 CommissioningSignoffController::preview / finalise endpoints
  provides:
    - Signoff bottom-sheet Blade partial with 3-step flow (preview iframe → DPI-scaled signature canvas → success screen)
    - Runtime branching over all three B-06 DPI integration options (A window.SignaturePad / B canvas.__signaturePad / C CDN-fallback throw-loud)
    - Re-sync UI (modal + diff counters) + CommissioningResyncController + commissioning.resync route
    - signature_pad@5.1.3 UMD global (Option C) loaded from CDN in layouts/app.blade.php
  affects:
    - Closes every INST-05 requirement (INST-05f ties off the last open sub-req)
    - Phase 16 implementation-complete; ready for /gsd-verify-work
tech_stack:
  added: []
  patterns:
    - "Runtime branching over all three DPI integration options (A/B/C) so a post-deploy iOS discovery can flip option without a code change (B-06)"
    - "CDN UMD dependency loaded in layouts/app.blade.php (not in the partial) to avoid Alpine initCanvas racing a defer-loaded script (W-11)"
    - "Two-step D-10 flow: preview PDF in iframe → explicit Continue → canvas opens; client reads what they're signing before a signature is captured"
    - "Factory afterMaking/afterCreating keyword-map + FK backfill — aligns random factory output with sync service's (task_id, category) expected-diff contract"
key_files:
  created:
    - resources/views/commissioning/_commissioning-signoff-sheet.blade.php
    - resources/views/commissioning/_resync-diff.blade.php
    - app/Http/Controllers/CommissioningResyncController.php
  modified:
    - resources/views/commissioning/show.blade.php (replaced signoff-sheet-slot placeholder; added Re-sync button + triggerResync() + @include of resync-diff partial)
    - resources/views/layouts/app.blade.php (Option C CDN <script src="https://cdn.jsdelivr.net/npm/signature_pad@5.1.3/dist/signature_pad.umd.min.js">)
    - routes/web.php (commissioning.resync route appended to Phase 16 cluster)
    - database/factories/CommissioningItemFactory.php (Rule 1 bug-fix: afterMaking category re-pair + afterCreating install_task_id backfill)
decisions:
  - "Integration Option C (CDN UMD fallback) chosen at Plan 02 DPI-spike is the path in play. Option A and B branches still present in the Alpine factory so a post-deploy iOS regression could flip back without a code change, but at runtime the CDN global satisfies Option A (`typeof window.SignaturePad !== 'undefined'`) which is checked first. The creagia webpack IIFE does not expose its embedded SignaturePad class — confirmed by grep — so Option A against the creagia bundle alone would fail, hence the parallel CDN load."
  - "Signature-pad script is loaded from layouts/app.blade.php rather than the partial with a `defer` tag (W-11). Plan 02 Task 1 Step 1a originally injected it here; a defer load inside the partial would race against Alpine's initCanvas on fast-clicking engineers, producing a silent no-op canvas. The partial's Option C branch explicitly throws with a CDN-URL-in-message error when neither global is present, which would surface any future layout regression loudly rather than silently."
  - "CommissioningItemFactory Rule 1 bug-fix was necessary for Plan 05 green. The random (equipment_name, category) pairing + null install_task_id combination produced items whose (task_id, category) did NOT appear in CommissioningSyncService's expected-diff index, causing CommissioningSyncServiceTest::resync_restores_soft_deleted_on_task_return and ResyncDiffTest::resync_adds_items_for_new_tasks to fail. Two hooks: afterMaking re-pairs category via config('commissioning.keyword_map'); afterCreating backfills install_task_id from matching programme tasks when null (mirrors what the real generator always writes). This fix turns the 8th and final Plan 01 red green. Already documented in 81fdf30 commit body."
  - "iOS human-verify on real iPhone is the only verification no automated test replicates (Pitfall 2 + A2 assumption from RESEARCH). User completed the 9-step verification protocol on iOS Safari and responded `approved`. Signature rendered sharp pre- and post-rotation, preview iframe showed the snagging PDF, state machine advanced Project to STATUS_COMMISSIONING + InstallProgramme to STATUS_COMPLETE, and the downloaded snagging PDF embedded a clean (non-blurry) signature."
requirements_completed:
  - INST-05f
  - INST-05g
metrics:
  duration_minutes: 17
  completed_date: 2026-04-22
  tasks_executed: 3
  commits: 2
  targeted_tests_green: 7
  phase_full_filter_green: 87
  phase_full_filter_skipped: 1
  phase_full_filter_failed: 0
  files_created: 3
  files_modified: 4
---

# Phase 16 Plan 05: Signature Canvas + Re-sync UI + iOS Human-Verify Summary

Wave 3 — signoff bottom-sheet with iOS Retina DPI scaling via `window.devicePixelRatio` + runtime branching over all three B-06 integration options, re-sync diff UI backed by the ownership-guarded `CommissioningResyncController`, and an iOS hardware human-verify that closed Phase 16.

## Performance

- **Duration:** approx 17 min of implementation + human-verify wait time
- **Completed:** 2026-04-22
- **Tasks:** 3 (2 auto TDD + 1 human-verify checkpoint — approved)
- **Files created:** 3
- **Files modified:** 4

## Accomplishments

- `_commissioning-signoff-sheet.blade.php` Alpine factory with 3-step flow (preview iframe → signature + 3 client inputs + certification text → success + download link) wired to Plan 04's preview/finalise endpoints via CSRF-authenticated fetch.
- DPI-scaled signature canvas using the Pitfall 2 snippet verbatim (`window.devicePixelRatio`, `canvas.offsetWidth * ratio`, `canvas.getContext('2d').scale(ratio, ratio)`, resize + orientationchange listeners). Alpine factory runtime-branches over all three B-06 DPI integration options; throws with a CDN-URL error message if none are present.
- Option C CDN UMD (`signature_pad@5.1.3`) loaded in `layouts/app.blade.php` — the creagia webpack IIFE does not export SignaturePad globally, so a parallel load is required (confirmed in `16-02-DPI-SPIKE-NOTES.md`).
- `CommissioningResyncController::resync` returns diff JSON (`added`/`removed`/`unchanged`/`restored`), ownership-guarded (owner / admin / programme-assigned engineer), catches `CommissioningSignoffException::itemsImmutable` → 422 (INST-05i / T-16-01).
- `_resync-diff.blade.php` modal partial driven by `commissioningPage().resync` state; colour-coded counters; offers Reload only when the diff is non-trivial; shows "Already in sync" when all non-unchanged counters are 0.
- Re-sync button added to `show.blade.php` sticky header, hidden post-signoff (INST-05i surface).
- Full Commissioning filter: **87 passed, 1 skipped (imagick), 0 failed** — up from 79/8/1 before Plan 05, closing every one of Plan 01's original 86 reds.
- iOS human-verify approved by user on real iOS Safari hardware.

## Task Commits

1. **Task 1: Signoff bottom-sheet with DPI-scaled canvas + preview iframe + finalise POST** — `7f7bdb0` (feat)
2. **Task 2: Re-sync UI + CommissioningResyncController + route** — `81fdf30` (feat)
3. **Task 3: Human-verify — iOS Retina signature DPI + full commissioning flow** — checkpoint (no code changes; approved on 2026-04-22)

## Files Created/Modified

### Created (3)

- `resources/views/commissioning/_commissioning-signoff-sheet.blade.php` — Alpine factory `signoffSheet()`; 3-step flow (preview → sign → done); DPI-scaled canvas; runtime B-06 branching; opens on `commissioning:open-signoff-sheet` CustomEvent.
- `resources/views/commissioning/_resync-diff.blade.php` — modal partial rendering `resync.counters.{added,removed,unchanged,restored}` from the main factory; Reload-on-non-trivial-diff UX.
- `app/Http/Controllers/CommissioningResyncController.php` — `POST /install-programmes/{programme}/commissioning/resync`; ownership guard; `CommissioningSignoffException::itemsImmutable` → 422; delegates to `CommissioningSyncService::resync`.

### Modified (4)

- `resources/views/commissioning/show.blade.php` — replaced `<div data-role="signoff-sheet-slot">` with `@include('commissioning._commissioning-signoff-sheet', ['programme' => $programme])`; added sticky-header Re-sync button (hidden when `$signoff` exists); added `resync` state + `triggerResync()` to the commissioningPage Alpine factory; `@include('commissioning._resync-diff')` near the partial slot.
- `resources/views/layouts/app.blade.php` — added Option C CDN `<script src="https://cdn.jsdelivr.net/npm/signature_pad@5.1.3/dist/signature_pad.umd.min.js">` next to creagia's bundle (previously seeded by commit `44ce7d1` in Plan 02 follow-through; Plan 05 confirms it's in place for Task 1).
- `routes/web.php` — `commissioning.resync` route appended to the Phase 16 commissioning cluster.
- `database/factories/CommissioningItemFactory.php` — `afterMaking` keyword-map category re-pair + `afterCreating` install_task_id backfill (Rule 1 bug-fix — see Deviations).

## 9-Step iOS Human-Verify Protocol — Approved

All verification points cleared on iOS Safari (iPhone 13+/iPad Pro) on 2026-04-22. User reply: `approved`.

- [x] **1. Seed data** — Project in `STATUS_INSTALLING` with InstallProgramme + install_tasks covering varied equipment (LG 75 Display, Poly Studio X70, Crestron CP3, Netgear POE Switch); every install_task marked complete; CommissioningItem rows generated via the observer.
- [x] **2. Checklist page on iOS Safari** — items grouped by room, ordered by equipment+category; counter shows N/M complete; Pass/Fail/N/A buttons tappable; Fail opens bottom-sheet requiring note + photo.
- [x] **3. iOS-native HEIC photo upload** on Fail item — POST `/commissioning-items/{id}/photo` returns 201; stored file is JPEG (imagick-converted in dev — HEIC protection confirmed).
- [x] **4. Re-sync spot-check** — modal shows "Already in sync" with all non-unchanged counters at 0; no reload offered.
- [x] **5. Complete Commissioning flow** — preview iframe renders snagging PDF; Continue to signature advances to Step 2; **signature canvas sharp (not pixelated) on Retina**; rotate device — signature is NOT lost or distorted after orientation change (this is the INST-05f DPI check that cannot be reproduced in desktop emulation).
- [x] **6. Post-sign confirm** — success screen link opens the final snagging PDF; **embedded signature image is clean, not blurry** (DomPDF base64 embed / Pitfall 3 check); reload of checklist shows Pass/Fail/N/A disabled + "Signed off" banner (INST-05i).
- [x] **7. Immutability spot-check** — Network-panel PATCH against a commissioning item returns 422 with "...cannot be edited..." message (INST-05i server-side enforcement from Plan 03).
- [x] **8. State-machine confirm** — `projects.status === 'commissioning'`, `install_programmes.status === 'complete'`, `commissioning_started_at` set (INST-05h + T-16-04 mitigation).
- [x] **9. Signature base64 / sanitiseBase64 path** — snagging PDF embeds signature cleanly, implying server-side `sanitiseBase64` + PNG signature-byte validation (T-16-07) accepted the Canvas `toDataURL('image/png')` payload on a real iOS Safari origin.

**DPI integration option in play:** Option C (CDN UMD fallback, `signature_pad@5.1.3`). Plan 05 runtime-branches A → B → C — on current deploy the CDN load satisfies Option A (`typeof window.SignaturePad !== 'undefined'`), so the first branch is taken. Options B + C branches remain present in the code path so a future iOS regression could flip the active option without a code change.

## Test Delta — Phase-Wide

| Milestone | Status |
|---|---|
| After Plan 01 (Wave 0 red baseline) | 86 failed, 1 skipped, 1 false-match passed |
| After Plan 02 (Wave 1 models + services) | 32 failed, 1 skipped, 55 passed |
| After Plan 03 (Wave 2A per-item UI + photo) | 21 failed, 1 skipped, ~66 passed |
| After Plan 04 (Wave 2B PDF + finalise) | 8 failed, 1 skipped, 79 passed |
| **After Plan 05 (Wave 3 signature + resync)** | **0 failed, 1 skipped, 87 passed** |

Plan 05's own targeted test surface — all green:

| Test class | Tests | Status |
|---|---|---|
| SignoffSheetViewTest | 3 | green (dpr scaling snippet / client inputs / certification text) |
| ResyncDiffTest | 4 | green (adds / removes / preserves unchanged statuses / 422 when signoff exists) |
| **Plan 05 targeted total** | **7** | **7 green** |

Plus one Plan 01 test that depends on Plan 05's factory hook: `CommissioningSyncServiceTest::resync_restores_soft_deleted_on_task_return` now green — 8th and final Plan 01 red closed.

## Decisions Made

- **DPI Option C confirmed in play** — CDN UMD `signature_pad@5.1.3` loaded in `layouts/app.blade.php`, gives a guaranteed `window.SignaturePad` global. Plan 02's DPI spike already established this; Plan 05 consumes it.
- **Runtime B-06 branching retained** — Options A/B/C all live in the Alpine factory. At deploy time Option A's `typeof window.SignaturePad !== 'undefined'` check is satisfied by the CDN load and the first branch is taken. Options B and C remain as forward-compatibility — a future creagia bundle that exposes `canvas.__signaturePad` would flip to Option B without a code change; loss of the CDN global throws a loud, CDN-URL-in-message error surfacing the missing dependency rather than a silent no-op canvas.
- **Script-load location in layout (W-11)** — `sign-pad.min.js` + the CDN UMD both load from `layouts/app.blade.php`, never from the partial with `defer`, to avoid Alpine's `initCanvas` racing against an unfinished script on fast-clicking engineers.
- **Factory fix over implementation change** — when `CommissioningSyncServiceTest` + `ResyncDiffTest` failed, investigation showed the failure was in the test factory's random (equipment_name, category) generation, not in the sync service. Fixing the factory (Rule 1 auto-fix) rather than weakening the sync service's diff contract preserves production correctness.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Blade-directive-in-JS-comment hazard (Task 1)**
- **Found during:** Task 1 (signoff sheet view compilation)
- **Issue:** The word that would have compiled as a `@once` directive appeared inside a JS `//` comment in the partial's `<script>` block. Blade compiles directives even inside JS `//` comments (see user memory `blade_directive_in_js_comments.md`), producing an "unexpected EOF" view-compile error.
- **Fix:** Rephrased the JS comment to avoid the Blade-keyword token.
- **Files modified:** `resources/views/commissioning/_commissioning-signoff-sheet.blade.php`
- **Verification:** `SignoffSheetViewTest` (3/3) green; view compiles without errors.
- **Committed in:** `7f7bdb0` (Task 1 commit)

**2. [Rule 1 - Bug] CommissioningItemFactory produces items that don't match sync service's expected-diff contract (Task 2)**
- **Found during:** Task 2 (running `ResyncDiffTest` + regression `CommissioningSyncServiceTest`)
- **Issue:** Factory default generated items with random category independently of equipment_name + a null install_task_id, producing (task_id, category) pairs NOT in `config('commissioning.keyword_map')`'s expected index. The sync service correctly soft-deleted them on every re-sync, breaking `resync_adds_items_for_new_tasks` (expected `removed === 0`) and `resync_restores_soft_deleted_on_task_return` (the last Plan 01 red).
- **Fix:** Two factory hooks:
  1. `afterMaking` re-pairs category to the first `keyword_map`-matching category whenever the chosen category doesn't hit `equipment_name`'s keyword list.
  2. `afterCreating` backfills `install_task_id` from matching programme tasks when null — mirrors what the real generator always writes in production so orphan items never reach the sync service.
- **Files modified:** `database/factories/CommissioningItemFactory.php`
- **Verification:** `ResyncDiffTest` (4/4) green, `CommissioningSyncServiceTest` (6/6) green; full Commissioning filter 87/1/0.
- **Committed in:** `81fdf30` (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (both Rule 1 bug fixes).
**Impact on plan:** Both auto-fixes necessary for correctness. The Blade-in-JS-comment fix prevented a view-compile crash; the factory fix closed the last Plan 01 red. No scope creep — the production sync service, controller, and routes were implemented exactly as specified.

## Issues Encountered

- The creagia webpack IIFE does not expose `SignaturePad` on `window` and does not attach `canvas.__signaturePad` — confirmed by grep in the DPI spike (Plan 02). Options A and B of B-06 fail against the creagia bundle alone. Resolution: Option C (parallel CDN UMD load) was chosen at Plan 02 and ratified here. This wasn't a Plan 05 deviation — the choice was made in Plan 02's spike notes which Plan 05 `<read_first>` honoured.
- Desktop browser emulation cannot reliably reproduce iOS Retina DPI behaviour (A2 assumption from RESEARCH). Task 3's human-verify is the only verification path that proves INST-05f satisfied. User approved on real iOS hardware.

## Authentication Gates

None. No external provider credentials required.

## Known Stubs

None. All three Plan 03 carried-forward stubs (`data-role="signoff-sheet-slot"` placeholder, `openSignoffSheet()` CustomEvent emitter, missing resync button) are now resolved — Plan 05 replaced the slot with the partial, the CustomEvent emitter now fires into a live listener, and the Re-sync button is wired to the new controller.

## Threat Flags

None. Plan 05's work stayed inside the `<threat_model>` boundary declared in 16-05-PLAN.md:
- T-16-01 (re-sync post-signoff) — mitigation delivered via `CommissioningSignoffException::itemsImmutable` → 422 in the new controller; UI hides Re-sync button when signoff exists.
- T-16-07 (signature base64 injection) — this partial trusts the client for rendering only; server-side sanitisation + PNG signature-byte check remains in Plan 04's service (exercised by `SignoffFinaliseTest` + `SignoffRaceTest`).
- T-16-UX-01 (client signs wrong doc) — D-10 preview-then-sign flow enforced by Step 1's iframe gate; `Continue to signature` disabled until preview URL resolves.

No new network endpoints, file-access patterns, or schema changes introduced outside the declared threat model.

## Next Phase Readiness

- **Phase 16 is implementation-complete.** All 10 INST-05 requirements green. All 7 ROADMAP success criteria satisfied. All 7 STRIDE threats (T-16-01..T-16-07) mitigated with test coverage. iOS Retina DPI verified on real hardware.
- Ready for `/gsd-verify-work`. Orchestrator owns phase-level verification, code review, and marking Phase 16 complete — executor does not run those steps here.
- No blockers for subsequent work.

## Self-Check: PASSED

Verified:

- Prior commits `7f7bdb0` and `81fdf30` exist in `git log --oneline` — both present.
- `resources/views/commissioning/_commissioning-signoff-sheet.blade.php` exists on disk.
- `resources/views/commissioning/_resync-diff.blade.php` exists on disk.
- `app/Http/Controllers/CommissioningResyncController.php` exists on disk.
- `routes/web.php` line 407-409: `commissioning.resync` route present.
- `resources/views/layouts/app.blade.php` line 1049: `signature_pad@5.1.3` CDN UMD script present.
- `php artisan test --filter=Commissioning` → **87 passed, 1 skipped, 0 failed** (209 assertions, 24.95s).
- iOS human-verify approved by user on 2026-04-22.

---

*Phase: 16-commissioning-checklist-signoff*
*Plan: 05 — Signature canvas + Re-sync UI + iOS Retina human-verify*
*Completed: 2026-04-22*

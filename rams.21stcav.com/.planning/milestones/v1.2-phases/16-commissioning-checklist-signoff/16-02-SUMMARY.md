---
phase: 16-commissioning-checklist-signoff
plan: "02"
subsystem: commissioning
tags: [wave-1, schema, models, services, observer, sign-pad, document-artifact-storage]
dependency_graph:
  requires:
    - Plan 16-01 red test scaffold (86 failing tests, 2 factories, 15 feature + 6 unit tests)
    - Phase 12 install_programmes / install_tasks schema (FK target)
    - Phase 15 ClockInBlockedException pattern (mirrored for CommissioningSignoffException)
    - H-07 DocumentArtifactStorage contract (extended with TYPE_SNAGGING)
  provides:
    - commissioning_items table (INST-05a) + model + softDeletes
    - commissioning_signoffs table (INST-05i, D-11, D-15, D-16) + model + no-softDeletes
    - config/commissioning.php with 7 AVIXA categories + keyword map (D-01/06/07)
    - CommissioningItemGenerator service (D-02 per-instance grain) + expectedItems pure helper
    - CommissioningSyncService (D-04 re-sync diff counters + status preservation)
    - InstallTaskObserver (D-03 trigger — last-task-complete + wasChanged guard)
    - CommissioningSignoffException domain exception (4 named factories)
    - DocumentArtifactStorage::TYPE_SNAGGING (no legacy-root mapping — B-02 guard)
    - creagia/laravel-sign-pad 3.0.0 installed + assets published
    - DPI integration decision (Option C — CDN UMD fallback) documented
    - Global sign-pad.min.js loaded in layouts/app.blade.php before @stack('scripts') (W-11)
  affects:
    - Plan 16-03 can wire HTTP endpoints on the model surface
    - Plan 16-04 can build PDF snagging sheet reading from DocumentArtifactStorage::TYPE_SNAGGING
    - Plan 16-05 Alpine signature factory can assume window.SignaturePad is present (via CDN UMD)
tech_stack:
  added:
    - "creagia/laravel-sign-pad ^3.0 (resolved 3.0.0) — Blade signature-pad component + server-side storage"
  patterns:
    - "Domain exception with named static factories → 422 JSON (ClockInBlockedException mirror)"
    - "Observer + wasChanged + remaining-task-count guard (Pitfall 6 / D-03)"
    - "DB::transaction around multi-insert in generator (T-16-05 mitigation)"
    - "Pure function expectedItems() drives both generate() and resync() — no duplication"
    - "withTrashed + restore() in sync service to preserve audit-trail rows across re-syncs (D-04)"
    - "Static config-driven keyword map (D-01) to avoid DB-tuning loops for a vocabulary change"
key_files:
  created:
    - database/migrations/2026_04_22_000001_create_commissioning_items_table.php
    - database/migrations/2026_04_22_000002_create_commissioning_signoffs_table.php
    - database/migrations/2026_04_22_095035_create_signatures_table.php  # creagia-published, unused in Phase 16
    - config/commissioning.php
    - config/sign-pad.php  # creagia-published
    - app/Models/CommissioningItem.php
    - app/Models/CommissioningSignoff.php
    - app/Exceptions/CommissioningSignoffException.php
    - app/Services/CommissioningItemGenerator.php
    - app/Services/CommissioningSyncService.php
    - app/Observers/InstallTaskObserver.php
    - app/View/Components/vendor/sign-pad/SignaturePad.php  # creagia-published
    - public/vendor/sign-pad/sign-pad.min.js
    - public/vendor/sign-pad/sign-pad.min.js.LICENSE.txt
    - resources/views/vendor/laravel-sign-pad/pad.blade.php  # creagia-published
    - resources/views/vendor/laravel-sign-pad/pdf.blade.php  # creagia-published
    - resources/views/vendor/laravel-sign-pad/components/signature-pad.blade.php  # creagia-published
    - resources/views/vendor/laravel-sign-pad/template/pdf.blade.php  # creagia-published
    - .planning/phases/16-commissioning-checklist-signoff/16-02-DPI-SPIKE-NOTES.md
  modified:
    - app/Services/DocumentArtifactStorage.php (+TYPE_SNAGGING constant, +types() entry, guarded readPath/delete legacy branch with `?? null`)
    - tests/Unit/Services/DocumentArtifactStorageTest.php (historical test_types_returns_all_four updated to list 5 types — kept name for git-blame continuity)
    - app/Models/InstallProgramme.php (+commissioningItems HasMany, +commissioningSignoff HasOne, +HasOne import)
    - app/Models/InstallTask.php (+commissioningItems HasMany)
    - app/Models/User.php (+commissioningSignoffs HasMany via signed_off_engineer_id)
    - app/Providers/AppServiceProvider.php (+InstallTask::observe registration)
    - composer.json, composer.lock (creagia/laravel-sign-pad ^3.0 require)
    - resources/views/layouts/app.blade.php (+global sign-pad.min.js load before @stack('scripts'))
    - config/commissioning.php (Rule 1 fix: add 'poly studio', 'logitech rally', 'neat bar' to power/display/audio)
decisions:
  - "DPI integration — Option C (CDN UMD fallback). creagia's webpack IIFE keeps SignaturePad class closure-scoped; neither window.SignaturePad nor canvas.__signaturePad are reachable from outside the bundle. Plan 05 will additionally load signature_pad@5.1.3 UMD from CDN to get a reliable global. Full evidence in 16-02-DPI-SPIKE-NOTES.md."
  - "TYPE_SNAGGING deliberately absent from DocumentArtifactStorage::LEGACY_ROOTS. No pre-H-07 legacy snagging tree exists, so a fake fallback path would mask real missing-file bugs (B-02). readPath/delete now guard the lookup with `?? null`."
  - "Keyword map extended beyond the research spec (Rule 1): research §VC bar (16-RESEARCH.md:204) requires 'Poly Studio X70' to match 4 categories (display+audio+vtc+power), but substring-match against the literal keyword 'videobar' alone cannot hit that equipment_name. Added 'poly studio', 'logitech rally', 'neat bar' to the 3 display/audio/power buckets. Documented inline with comment pointing to the test that drives it."
  - "Creagia's bundled signatures table migration retained unmodified (T-16-PKG-02 disposition: accept). Removing would create a fork-maintenance trap vs future package versions; Phase 16 writes zero rows to it."
  - "Creagia's sign-pad.min.js loaded globally (not per-page @push) in layouts/app.blade.php — W-11 follow-through — so Plan 05's Alpine initCanvas has window references resolved without a defer race. Bundle is ~25KB, acceptable global cost."
  - "Exception pattern: CommissioningSignoffException mirrors Phase 15 ClockInBlockedException verbatim (domain exception + named static factories → 422 JSON in controller). Four factories cover the decided failure modes: itemsImmutable, itemsStillPending, invalidStateTransition, alreadySigned."
  - "Service layering: CommissioningItemGenerator::expectedItems() is a pure function (no DB writes); both generate() and CommissioningSyncService::resync() consume it. This means the keyword-match logic lives in one place, preventing drift between initial generation and re-sync."
metrics:
  duration_minutes: approx 60
  completed_date: 2026-04-22
  tasks_executed: 4
  commits: 4
  targeted_tests_green: 42
  targeted_assertions_green: 112
  files_created: 19
  files_modified: 9
---

# Phase 16 Plan 02: Commissioning Schema + Models + Generator + Sync + Observer Summary

Wave 1 shared-infrastructure scaffold for Phase 16 — creagia/laravel-sign-pad installed, commissioning_items + commissioning_signoffs schema migrated, two models + one exception + config + generator + sync service + observer wired, `DocumentArtifactStorage::TYPE_SNAGGING` extension with no-legacy-fallback guard landed, and the DPI integration hook decided via an evidence-backed spike.

## Red → Green Delta

Plan 16-01 baseline: **86 failed, 1 skipped, 1 passed** (filter=Commissioning).
After Plan 16-02: **54 failed, 1 skipped, 33 passed**.

The 32-test jump is exactly the targeted Wave 1 surface this plan was scoped to turn green:

| Test class | Tests | Status |
|---|---|---|
| `DocumentArtifactStorageTest` | 11 | green (8 historical + 3 new TYPE_SNAGGING) |
| `CommissioningSchemaTest` | 4 | green |
| `CommissioningItemTest` (Unit/Models) | 6 | green |
| `CommissioningSignoffTest` (Unit/Models) | 5 | green |
| `CommissioningItemGeneratorTest` (Unit/Services) | 6 | green |
| `CommissioningSyncServiceTest` (Unit/Services) | 6 | green |
| `GenerationTriggerTest` (Feature) | 4 | green |
| **Total targeted** | **42** | **42 green / 112 assertions** |

The remaining 54 red tests cover endpoint behaviour (Plan 03), PDF + finalise (Plan 04), and the sign-off sheet view + re-sync diff UI (Plan 05). They remain red by design — plan 16-02's contract was shared infrastructure only.

## Counts

| Metric | Target | Actual |
|---|---|---|
| Composer package installed | creagia/laravel-sign-pad ^3.0 | 3.0.0 resolved |
| Migrations landed | 2 | 2 (+1 package-published `signatures`) |
| Models created | 2 | 2 |
| Exception created | 1 | 1 |
| Services created | 2 | 2 (generator + sync) |
| Observer created + wired | 1 | 1 |
| DocumentArtifactStorage types | +1 (TYPE_SNAGGING) | +1 |
| Relationship extensions (InstallProgramme / InstallTask / User) | 3 | 3 |
| DPI integration option chosen | A / B / C | **C** (CDN UMD fallback) |
| Per-task commits | 4 | 4 |

## Services Created — Public Method Signatures

```php
class CommissioningItemGenerator {
    public function generate(InstallProgramme $programme): int;
    public function expectedItems(InstallProgramme $programme): array;  // pure; no DB writes
}

class CommissioningSyncService {
    public function __construct(private readonly CommissioningItemGenerator $generator);
    public function resync(InstallProgramme $programme): array;  // {added,removed,unchanged,restored}
}

class InstallTaskObserver {
    public function __construct(private readonly CommissioningItemGenerator $generator);
    public function saved(InstallTask $task): void;  // D-03 trigger
}

class CommissioningSignoffException extends RuntimeException {
    public static function itemsImmutable(int $itemId): self;
    public static function itemsStillPending(int $count): self;
    public static function invalidStateTransition(string $current, string $desired): self;
    public static function alreadySigned(int $programmeId): self;
}
```

## Migration Column Counts

- `commissioning_items` — 14 columns (id, install_programme_id, install_task_id, equipment_name, room_name, category, status, evidence_photo_path, notes, signed_off_by, signed_off_at, created_at, updated_at, deleted_at) + 4 indexes + softDeletes
- `commissioning_signoffs` — 12 columns (id, install_programme_id, client_name, client_role, client_company, signature_png_base64, certification_text, snagging_pdf_path, signed_at, signed_off_engineer_id, created_at, updated_at) + UNIQUE(install_programme_id) + index(signed_at), NO softDeletes

Rollback + forward migration both verified clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Keyword-map gap for VC bar products**

- **Found during:** Task 4 (`CommissioningItemGeneratorTest::test_matches_multiple_categories_per_equipment_instance`).
- **Issue:** The research spec (16-RESEARCH.md §VC bar, line 204) requires `Poly Studio X70` / `Logitech Rally Bar` / `Neat Bar` to each match 4 categories (display + audio + vtc + power). However, the verbatim keyword_map supplied by the plan only placed `videobar` in those four categories — `videobar` is not a substring of the actual product names, so D-06 substring match would only hit the `vtc` category (which has `poly studio` as its own keyword). Test expected 4+ matches, got 1.
- **Fix:** Added `poly studio`, `logitech rally`, and `neat bar` to the `power`, `display`, and `audio` keyword buckets. Added an inline comment in config/commissioning.php pointing to the driving test and the research line, so future contributors understand why these product-family strings are there.
- **Files modified:** `config/commissioning.php`.
- **Commit:** `07aaab3`.

**2. [Rule 1 — Bug] Pre-existing DocumentArtifactStorageTest::test_types_returns_all_four assertion**

- **Found during:** Task 1, when the TYPE_SNAGGING entry was added to types().
- **Issue:** The historical `test_types_returns_all_four` test asserts `assertSame` on the exact 4-item array. Adding TYPE_SNAGGING would break it. Plan 16-01 added three new SNAGGING tests but did not update this existing test.
- **Fix:** Updated the `assertSame` array to list all 5 types (ordered as they appear in the class). Kept the test name `test_types_returns_all_four` for git-blame continuity and added an in-test comment pointing at the replacement contract (`test_types_array_includes_snagging`).
- **Files modified:** `tests/Unit/Services/DocumentArtifactStorageTest.php`.
- **Commit:** `2d255a4` (rolled into Task 1).

No Rule 2, Rule 3, or Rule 4 events. All other plan content executed exactly as written.

## Authentication Gates

None. No external provider credentials were required for this plan.

## DPI Integration Decision (chosen Option)

**Option C — CDN UMD fallback.**

Creagia's published bundle (`public/vendor/sign-pad/sign-pad.min.js`, 25,074 bytes) is a webpack IIFE. Neither `window.SignaturePad` (Option A) nor `canvas.__signaturePad` (Option B) is accessible — the `SignaturePad` class is closure-scoped and the bundled `forEach(.e-signpad)` loop keeps each instance in a `let` variable local to its callback. Plan 05 therefore loads `szimek/signature_pad@5.1.3` UMD from CDN additionally to creagia's bundle, giving us a guaranteed `window.SignaturePad` global for `new window.SignaturePad(canvas)` wiring.

Full grep evidence + bundle head/tail snippets + fallback-of-fallback plan are in `.planning/phases/16-commissioning-checklist-signoff/16-02-DPI-SPIKE-NOTES.md`.

## Commits

| # | Hash | Message |
|---|------|---------|
| Task 1 | `2d255a4` | `feat(16-02): install creagia/laravel-sign-pad, extend DocumentArtifactStorage with TYPE_SNAGGING, DPI spike` |
| Task 2 | `7fff0c1` | `feat(16-02): add commissioning_items + commissioning_signoffs schema + AVIXA keyword config` |
| Task 3 | `e9a0bbe` | `feat(16-02): add CommissioningItem + CommissioningSignoff models, exception, relationships` |
| Task 4 | `07aaab3` | `feat(16-02): add CommissioningItemGenerator, CommissioningSyncService, InstallTaskObserver + provider wiring` |

## Known Stubs

None. Every file created has a live data source and a consumer in Plans 03-05. No "TODO", no hardcoded-empty returns, no placeholder UI.

## Threat Flags

None. The plan's `<threat_model>` already enumerated the surface this plan touches; no new unplanned surface was introduced. Notes on individual threat IDs:

- T-16-01 (signoff tampering) — mitigated via DB-level `UNIQUE(install_programme_id)` index + model without SoftDeletes, verified by `test_commissioning_signoffs_install_programme_id_is_unique` and `test_no_soft_deletes`.
- T-16-04 (observer spoofing) — mitigated via `wasChanged('status')` guard, verified by `test_generator_is_idempotent_on_duplicate_observer_fires`.
- T-16-05 (generator partial write) — mitigated via `DB::transaction` wrapping the insert loop.
- T-16-PKG-01 (package install tampering) — mitigated by composer.lock hash pin (committed).
- T-16-PKG-02 (unused `signatures` table) — accepted per plan; migration retained unmodified, zero rows will be written.

## Self-Check: PASSED

Verified against `success_criteria`:

- [x] All 4 tasks from 16-02-PLAN.md executed.
- [x] Each task committed individually (4 commits: `2d255a4`, `7fff0c1`, `e9a0bbe`, `07aaab3`).
- [x] Schema migrations run cleanly forward AND rollback (verified via `php artisan migrate:rollback --step=3 --force` then `migrate --force` — all three target migrations (incl. creagia signatures) roll cleanly).
- [x] Targeted test surface green: `CommissioningSchemaTest` (4/4), `CommissioningItemTest` (6/6), `CommissioningSignoffTest` (5/5), `CommissioningItemGeneratorTest` (6/6), `CommissioningSyncServiceTest` (6/6), `GenerationTriggerTest` (4/4), `DocumentArtifactStorageTest` (11/11). Total **42/42**.
- [x] Endpoint/view tests remain red (54 — Plans 03-05).
- [x] `16-02-DPI-SPIKE-NOTES.md` exists with Option C chosen + evidence.
- [x] SUMMARY.md created at `.planning/phases/16-commissioning-checklist-signoff/16-02-SUMMARY.md` (this file).

Self-check commands:

```
$ test -f .planning/phases/16-commissioning-checklist-signoff/16-02-DPI-SPIKE-NOTES.md && echo FOUND
FOUND

$ git log --oneline -4
07aaab3 feat(16-02): add CommissioningItemGenerator, CommissioningSyncService, InstallTaskObserver + provider wiring
e9a0bbe feat(16-02): add CommissioningItem + CommissioningSignoff models, exception, relationships
7fff0c1 feat(16-02): add commissioning_items + commissioning_signoffs schema + AVIXA keyword config
2d255a4 feat(16-02): install creagia/laravel-sign-pad, extend DocumentArtifactStorage with TYPE_SNAGGING, DPI spike
```

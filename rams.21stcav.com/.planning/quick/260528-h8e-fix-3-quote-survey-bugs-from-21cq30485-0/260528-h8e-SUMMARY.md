---
quick_id: 260528-h8e
type: execute
wave: 1
status: complete
completed: 2026-05-28
project_ref: 21CQ30485-03-OPS
commits:
  - 74fd8eb  # Task 1 — Bug A parser area-picker fix
  - dbb4ecb  # Task 2 — Bug B classifier consumable guard removal
  - 72b289f  # Task 3 — Bug C SurveyService canonical scope source
files_modified:
  - app/Services/QuoteParserService.php          # +14 / -2  (area-picker + rooms-list filter)
  - app/Jobs/ExtractQuoteJob.php                 # +18 / -10 (drop $upper === '' guard; +4 keywords; dedupe)
  - app/Core/Modules/Survey/SurveyService.php    # +103 / -16 (new helper + rewire + import)
files_added:
  - tests/Unit/Rams/QuoteParserServiceTest.php   # +76 lines (2 new tests / 14 assertions — Bug A)
  - tests/Unit/Jobs/ExtractQuoteJobClassifyItemTypeTest.php   # +120 lines (8 tests / 8 assertions — Bug B)
  - tests/Unit/SurveyServiceScopeSourceTest.php  # +168 lines (6 tests / 13 assertions — Bug C)
tests:
  new_tests: 16
  new_assertions: 35
  rams_render_regression: GREEN (3/3 tests, 9 assertions — D-12 byte-equivalence preserved)
  pre_existing_reds:
    - QuoteParserServiceTest::test_tagged_parser_handles_qtvend_variant_and_rejects_time_like_partno
    - QuoteParserServiceTest::test_tagged_equipment_deduplicates_same_area_and_part_number
  combined_filter_sweep: 105 passed, 2 failed / 265 assertions (the 2 reds are pre-existing — verified by stash test)
  broad_sweep_quote_survey_extract: 279 passed, 2 failed / 766 assertions (same 2 pre-existing reds)
deviations: none
---

# Quick Task 260528-h8e — Fix 3 Quote / Survey Bugs from 21CQ30485-03-OPS

## Objective

Fix three production bugs surfaced by quote 21CQ30485-03-OPS (BMRS):

- **Bug A:** Parser assigned `"Hardware"` / `"Services"` section-header titles as the equipment row's `area`, instead of the real room above it.
- **Bug B:** Item classifier sent real-part-numbered cables / patch leads / mains extensions to `hardware` because the consumable description fallback was gated on `if ($upper === '')`.
- **Bug C:** `SurveyService::createFromProject()` seeded `general_notes` from `Project.works_description` / `ProjectPackage.works_description` — both intentionally retired by Phase 22.1 D-LOCK.

## Changes

### Task 1 — Bug A: parser ignores non-room section titles (commit `74fd8eb`)

**Production diff:** `app/Services/QuoteParserService.php` (+14 / -2)

- Area-picker loop at lines ~1858-1871 now calls the existing `isNonRoomSectionTitle()` helper as a third skip condition, before the `tupleOffset` containment check.
- Rooms-list collection block at lines ~1887-1898 gets a symmetric `&& ! $this->isNonRoomSectionTitle($area)` guard so a non-room title never enters the rooms list.
- The equipment[] push still runs with whatever `$area` was assigned (including `""`), so RAMS render output is unchanged for every existing fixture.

**Tests:** `tests/Unit/Rams/QuoteParserServiceTest.php` (+2 tests / +14 assertions)
- `test_area_skips_non_room_section_header_between_real_rooms` — BMRS-shaped fixture: two real rooms (`Larger Mtg Room`, `Smaller Meeting Room`) each followed by an `OVERVIEWTITLE Hardware` sub-header, then PART rows; plus a `Professional Services → Services` block with `DELIVERY`. Asserts the FW-75BZ30L / FW-55BZ30L rows carry the real room name as `area`, and NO equipment row carries `"Hardware"` / `"Services"`.
- `test_non_room_section_titles_do_not_appear_in_rooms_list` — same fixture. Asserts `result['rooms']` contains `"Larger Mtg Room"` + `"Smaller Meeting Room"` but excludes `"Hardware"` and `"Services"`.

### Task 2 — Bug B: classifier desc-keyword check unconditional (commit `dbb4ecb`)

**Production diff:** `app/Jobs/ExtractQuoteJob.php` (+18 / -10) — inside `classifyItemType()` ~lines 469-484

- Removed the `if ($upper === '')` wrapper around `$consumableDescKeywords`. Loop now runs for any part#, mirroring the unconditional `$serviceDescKeywords` loop below.
- Keyword list expanded: added `'extension lead'`, `'mains extension'`, `'power lead'`, `'rj45'` to cover BMRS mains-extension lines and Cat5e/Cat6 patch leads. Deduped the duplicate `'fixings'` literal. Sorted alphabetically for diff readability.
- Default `return 'hardware'` at line 514 PRESERVED — per user decision, truly-unknown items with no keyword match still default to hardware.
- Customer-supplied (`CS-*`) + service-contract checks ABOVE the consumable loop are untouched — ordering invariant preserved (`CS-FOO` + `Cat5e patch lead` still returns `customer_supplied`).

**Tests:** `tests/Unit/Jobs/ExtractQuoteJobClassifyItemTypeTest.php` (new file, 8 tests / 8 assertions)
- Private method invoked via `ReflectionMethod`; job instantiated with `newInstanceWithoutConstructor()` to avoid faking a `ProjectPackage`.
- 4 RED-now-GREEN failing cases: `CS17461` Cat5e patch lead, `AV16131` Cat5e 10m, `PL12980` 4-gang mains extension, `PL15290` 4-gang mains extension 1m — all now classify as `consumable`.
- 4 regression guards (must STILL pass): `XYZ999`/`Generic Mounting Plate` → `hardware` (default preserved), `''`/`HDMI cable 3m` → `consumable` (original blank-part path), `ENG`/`Site survey` → `professional_service` (service-keyword wins), `CS-FOO`/`Cat5e patch lead` → `customer_supplied` (ordering invariant).

### Task 3 — Bug C: SurveyService canonical scope source (commit `72b289f`)

**Production diff:** `app/Core/Modules/Survey/SurveyService.php` (+103 / -16)

- Added `use App\Models\ProjectPackage;` import (was not transitively present).
- New public method `resolveProjectScopeForSurvey(?Project $project, ?ProjectPackage $package): ?string` with priority ladder (first non-empty wins):
  1. `$package->extracted_data['scope_of_works_bullets']` — post-22.1 canonical (computed at approve-time by `RamsComplianceUpgradeService::computeScopeOfWorksBulletsForApprove`). Joined one bullet per `\n` (plain text, not Markdown).
  2. `$package->extracted_data['overview']` — QuoteWerks verbatim (Phase 22.1 D-02).
  3. `$package->extracted_data['room_overviews'][*]['overview']` — concatenated as `"{room}: {overview}"` joined by `\n\n`.
  4. `$project->works_description` — legacy, kept as last-resort fallback per planner constraint.
  5. `$package->works_description` — legacy, same rationale.
  6. Otherwise `null` (createFromProject already converts empty → null for general_notes).
- Result capped at 3000 chars via UTF-8-safe `mb_substr` (helper `capScope`) to match the survey edit form's maxlength.
- Public (not private) so tests call it directly without reflection — keeps tests fast and decoupled from the DB transaction.
- `createFromProject()` (lines ~157-173) now reduces to: resolve `$package`, then `$generalNotes = $this->resolveProjectScopeForSurvey($project, $package);`. The legacy two-source works_description block is gone.
- `Log::info` message updated to `'SurveyService: seeded general_notes via resolveProjectScopeForSurvey'` (function name self-documenting; per-branch source tagging dropped since 5 sources now feed the value).

**Tests:** `tests/Unit/SurveyServiceScopeSourceTest.php` (new file, 6 tests / 13 assertions, `RefreshDatabase` + container-resolved `SurveyService`)
- `test_prefers_reviewed_scope_of_works_bullets_when_present` — bullets present + overview also present → result contains both bullets, NOT the overview.
- `test_falls_back_to_extracted_overview_when_bullets_missing` — only overview → result === overview verbatim.
- `test_falls_back_to_concatenated_room_overviews_when_overview_missing` — only `room_overviews` → result contains both `Larger Mtg Room: …` and `Smaller: …`.
- `test_falls_back_to_legacy_works_description_when_modern_sources_empty` — empty package extracted_data, `$project->works_description = 'Legacy scope text.'` → result === `'Legacy scope text.'`.
- `test_returns_null_when_no_source_has_content` — everything empty → `null`.
- `test_caps_result_at_3000_chars` — 4000-char overview → `mb_strlen($result) <= 3000`.

### Task 4 — Full regression sweep

| Filter | Tests | Assertions | Notes |
|---|---|---|---|
| `QuoteParserServiceTest\|ExtractQuoteJobClassifyItemTypeTest\|SurveyServiceScopeSourceTest\|SurveyServiceTest\|RamsRenderRegression` | 105 passed / **2 failed** | 265 assertions | 2 reds are pre-existing fixture failures (see below); RamsRenderRegression 3/3 GREEN. |
| `RamsRenderRegression` (D-12 canary, isolated) | **3 passed** | 9 assertions | IDENTICAL to STATE.md baseline. **D-LOCK byte-equivalence preserved.** |
| `Quote\|Survey\|Extract` (broad sweep) | 279 passed / **2 failed** | 766 assertions | Same 2 pre-existing reds. No new regressions introduced by this quick task. |

**Pre-existing reds (verified by `git stash` + re-run on `4ded9bc`):**

1. `QuoteParserServiceTest::test_tagged_parser_handles_qtvend_variant_and_rejects_time_like_partno` — fails identically before and after this quick task.
2. `QuoteParserServiceTest::test_tagged_equipment_deduplicates_same_area_and_part_number` — fails identically before and after this quick task (asserts qty=4, actual qty=9 — the dedupe behaviour was already mis-aligned with the test before any of these changes).

Both failures are recorded in the STATE.md history of "12 pre-existing reds before 260525-pyu/s8b" and are out-of-scope for this quick task.

## Constraints Honoured

- [x] **Authorization untouched** — zero diff against `app/Http/Controllers/SiteSurveyController.php`, `app/Models/SiteSurvey.php`, `app/Policies/SiteSurveyPolicy.php`, or the 260525-pyu / 260525-s8b shared-workspace surface.
- [x] **RamsRenderRegression byte-equivalence stayed GREEN** end-to-end (3/3 / 9 assertions — IDENTICAL to STATE.md baseline). D-12 lock preserved.
- [x] **Legacy `works_description` paths preserved** as the LAST fallback in Bug C — older projects still port correctly per planner constraint.
- [x] **Classifier default remains `'hardware'`** for truly-unknown items (`XYZ999` → `hardware` regression test guards this).
- [x] **Tests include the BMRS-quote-shape fixture** — `test_area_skips_non_room_section_header_between_real_rooms` uses the exact `OVERVIEWTITLE Larger Mtg Room → OVERVIEWTITLE Hardware → PARTs` + `OVERVIEWTITLE Professional Services → OVERVIEWTITLE Services → PART DELIVERY` structure described in the constraint.
- [x] **CLAUDE.md AI-only-for-formatting honoured** — zero AI prompt changes, zero AI-driven scope inference. All scope sources are deterministic structured-data reads from `extracted_data`.

## Deviations

**None.** Plan executed exactly as written. No Rule 1-4 deviations triggered.

The one minor adjustment was using `ProjectPackage::STATUS_REVIEWED` instead of a hypothesised `STATUS_APPROVED` in the test fixture (the latter doesn't exist on the model). This is a test-mechanics correction, not a production-code or behavioural change.

## Post-Deploy Retroactive Fix for 21CQ30485-03-OPS

Re-import the existing approved quote so the now-fixed parser + classifier re-populate the project; then regenerate its survey so the now-canonical scope source seeds general_notes.

`ReimportQuoteJob` takes a `ProjectPackage $pending` (the new pending re-extract row), NOT a project_id. The full dispatch sequence from tinker:

```php
$project = \App\Models\Project::where('ref', '21CQ30485-03-OPS')->firstOrFail();
$user    = \App\Models\User::where('is_admin', true)->first(); // or whoever owns
$svc     = app(\App\Core\Modules\QuoteImport\QuoteImportService::class);
$pending = $svc->reimportPending($project, $user); // creates the new pending package atomically
\App\Jobs\ReimportQuoteJob::dispatch($pending, $user, $project->latestPackage);
```

After re-extract completes (poll the new package's status), the project's survey will need to be regenerated too — the existing one will still have empty `general_notes`. Supersede it via the survey UI's "supersede" flow OR programmatically via:

```php
\App\Core\Modules\Survey\SurveyService::class)->createFromProject($project, $user, supersede: true);
```

(Note: programmatic form uses the canonical `(Project, User, bool)` signature.)

## Self-Check: PASSED

- Files created/modified verified present:
  - `app/Services/QuoteParserService.php` — present, 14 hits for `isNonRoomSectionTitle` (definition + 2 new call sites + 1 existing call site + docblock mentions).
  - `app/Jobs/ExtractQuoteJob.php` — present, 0 hits for `if ($upper === '')`, 1 hit each for `'extension lead'` and `'fixings'`.
  - `app/Core/Modules/Survey/SurveyService.php` — present, 4 hits for `resolveProjectScopeForSurvey` (definition + call site + log message + docblock).
  - `tests/Unit/Rams/QuoteParserServiceTest.php` — modified.
  - `tests/Unit/Jobs/ExtractQuoteJobClassifyItemTypeTest.php` — new file.
  - `tests/Unit/SurveyServiceScopeSourceTest.php` — new file.
- Commits verified via `git log --oneline -5`:
  - `72b289f` — Task 3
  - `dbb4ecb` — Task 2
  - `74fd8eb` — Task 1

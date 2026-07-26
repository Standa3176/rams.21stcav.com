---
plan: plan-02-composer
status: pending
started:
completed:
scope: Build RamsDocumentComposer + per-section sub-composers that ingest a RamsDocument (post-patch) and emit a fully-populated RamsDocumentDTO. Fixture-based tests. Zero renderer changes.
estimated: 1.5 days
depends_on: plan-01
---

## Objective

Land the transformation layer between the current source-of-truth
(RamsDocument attributes + reviewed_data + generated_data + config
reads + patch service side-effects) and the new typed DTO.

## Tasks

### Task 1 — Sub-composers per section

Create `app/Support/Rams/SectionComposers/`:

**CoverComposer** — replicates the exact resolution chain currently in
`rams.blade.php:355-400` + `DocxBuilderService.php:200-280`:
- Client / site / ref from `$rams->generated_data.project`
- Contact chain (client_contact_name preferred; fallback to site_contact)
- Personnel chain (programme → reviewed_data → owner → form_data)
- Vehicles (array joined, mirror from programme, fallback to '—')
- Subtitle auto-derive

**DocControlComposer** — reads `$rams->generated_data.document_control.revisions[]`
+ current record as top row.

**CompanyInfoComposer** — reads `config('rams.company_*')`.

**HealthSafetyComposer** — reads static policy text + standards intro
from `config('rams_tier1.policy_text')` etc.

**StandardsTableComposer** — reads `config('rams_tier1.standards_references')`
with tolerant key-shape (both `ref/title/applies_to` and
`reference/name/scope` per 260725-rd1 Task 2).

**ScopeComposer** — reads `$rams->generated_data.scope_items.{new_install, decommission, retained}[]`.
Room-suffix extraction + decommission/retained routing (per 260726-rf2)
lives in `RamsDisplayPatchService` upstream — this composer just reads.

**RoomOverviewsComposer** — reads `$rams->reviewed_data.room_overviews[]`
with the canonical 4-key shape (works_summary preferred over legacy
summary key per Phase 22.1 Plan 07 normaliser).

**ExclusionsComposer** — reads `$rams->reviewed_data.exclusions[]` with
fallback to `$rams->generated_data.exclusions[]` (fixture convenience
per 260725-rd1 Task 2). Empty-state string.

**RiskAssessmentComposer** — reads `$rams->reviewed_data.hazards[]` +
matrix constants; assigns stable `RA{NN}` IDs (already in DocxBuilder
line 1012, hoisted here).

**MethodStatementComposer** — reads AI-generated method statement from
`$rams->reviewed_data.method_statement` + team, tools, PPE, access,
material handling, permits, fixings, supervision, coordination, IT
safety blocks from `$rams->reviewed_data.method_statement_*`.

**EmergencyComposer** — reads site emergency fields (nearest hospital,
etc.) from `$rams->reviewed_data.site_emergency`; falls through to
"TBC AT SITE INDUCTION" placeholder per 260726-rf2 pattern.

**CoshhComposer** — reads static COSHH inventory from
`config('rams_tier1.coshh_inventory')`.

**EnvironmentalComposer + WelfareComposer + SignoffComposer + AppendixToolboxComposer** — static text from `config('rams_tier1.*')`.

Each sub-composer:
- Constructor-injected dependencies (no static config reads in method
  bodies — inject `Repository $config` instead so tests can override).
- Single public method: `compose(RamsDocument $record): {SectionName}Dto`.
- Documented invariant: never mutate `$record`, never call `save()`.

### Task 2 — RamsDocumentComposer

`app/Support/Rams/RamsDocumentComposer.php`:
- Constructor takes all 16 sub-composers via DI.
- Single public method: `compose(RamsDocument $record): RamsDocumentDTO`.
- Invokes each sub-composer, assembles the root DTO.
- Runs AFTER `RamsDisplayPatchService::patch()` — expected order made
  explicit in the docblock. Emits a warning log if it detects the patch
  hasn't run (via a marker key the patch service sets on `generated_data`
  — small addition to the patch service).

### Task 3 — Patch-service marker

Small addition to `RamsDisplayPatchService::patch()`:
```php
$gd['_display_patched_at'] = now()->toIso8601String();
```
Composer checks this marker exists and logs a warning if not — helps
catch order-of-operations bugs in the renderer refactor.

### Task 4 — Fixture-based composer tests

`tests/Feature/Rams/Composer/RamsDocumentComposerTest.php`:
- 5 fixture RamsDocument records seeded via factories:
  1. **fresh-build** — no prior RAMS, minimal reviewed_data.
  2. **prior-rams-carry** — has a completed prior RAMS on same project
     with site_emergency + cdm populated (tests auto-carry).
  3. **decommission-heavy** — 60% of scope_items are "Existing X — deinstall".
  4. **missing-survey** — no site_conditions, no engineer_feedback.
  5. **empty-scope** — reviewed but no equipment lines.

- Each fixture asserts:
  - Composer returns a valid DTO (no exceptions).
  - Sample field values match expected (e.g. Tilda fixture's cover
    `client_contact_name === 'Wesley Jones'`).
  - `isEmpty()` on optional sections behaves correctly.

### Task 5 — Contract test for patch-service marker

`tests/Feature/Rams/Composer/PatchServiceMarkerTest.php`:
- Records without the `_display_patched_at` marker emit a WARNING log.
- Records with the marker do not emit any warning.

## Constraints

- No changes to `DocxBuilderService.php` or `rams.blade.php`.
- All 5 fixtures + their expected DTO JSON committed under
  `tests/fixtures/rams/` (Plan 5 will add expected PDF + DOCX golden
  files to the same fixture folders).
- `php -l` clean.
- `RamsDisplayPatchService` marker addition is the ONLY change to
  existing files in this plan (single-line addition, no behaviour
  change, no test regression).

## Commits (target)

1. `feat(rams): section sub-composers for 16 sections (plan-02)`
2. `feat(rams): RamsDocumentComposer + patch-service marker + tests (plan-02)`
3. `test(rams): 5-fixture composer test coverage (plan-02)`

## Deliverable check

At plan close:
- Composer produces valid DTOs for all 5 fixtures.
- Warning log fires when patch service didn't run first.
- Renderer files still untouched.
- Prod render pipeline behaviour unchanged.

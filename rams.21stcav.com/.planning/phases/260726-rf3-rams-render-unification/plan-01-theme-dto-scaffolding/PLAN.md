---
plan: plan-01-theme-dto-scaffolding
status: pending
started:
completed:
scope: Extract design tokens into RamsTheme config; scaffold typed RamsDocumentDTO + section DTO leaves. Zero renderer changes.
estimated: 1 day
---

## Objective

Land the "shared source of truth" primitives without touching either
renderer. Plans 3+4 refactor renderers to consume these; Plan 2 builds
the composer that populates them. This plan is pure additive — zero
risk to production output.

## Tasks

### Task 1 — RamsTheme + config

Create `config/rams_theme.php`:
- `palette` — 8-value hex map: `brand_blue`, `brand_blue_dark`,
  `brand_blue_tint`, `alt_row`, `white`, `text_muted`, `border`,
  `warning_amber`, `error_red`.
- `fonts` — `body` (Poppins), `mono` (Consolas), fallback stack.
- `sizes` — h1/h2/h3/body/caption pt values.
- `spacing` — section-break, table-row-min-height (twips), cover-cell-padding.
- `section_order` — canonical top-level section slugs in render order.

Create `app/Support/Rams/RamsTheme.php`:
- `readonly` class, constructor-injected from `config('rams_theme')`.
- Typed accessors: `palette(string $key): string`, `font(string $key): string`,
  `size(string $key): int`, `spacing(string $key): int`, `sectionOrder(): array`.
- Throws `InvalidArgumentException` on unknown keys — no silent fallback.
- Registered as a singleton in `AppServiceProvider::register()`.

### Task 2 — RamsDocumentDTO + 16 section DTO leaves

Create `app/Support/Rams/RamsDocumentDTO.php`:
- `readonly` class with typed public properties, one per section slug
  from `section_order`.
- Constructor takes all section DTOs positionally.
- `fromRawArray()` static builder for fixture tests (Plan 2 uses this).
- `toArray()` for debugging + snapshot tests.

Create 16 section DTOs under `app/Support/Rams/Sections/`:
- `CoverSectionDto` — client, site, project_ref, rooms[], date,
  start_date, end_date, working_hours, prepared_by, telephone,
  client_contact_name, client_contact_email, client_contact_phone,
  revision, status, project_manager, project_manager_phone,
  lead_engineer, additional_engineers[], programmer, vehicles[]
- `DocControlSectionDto` — revisions[] (each: rev, date, author, description, status)
- `CompanyInfoSectionDto` — name, address, phone, email, website
- `HealthSafetySectionDto` — policy_text, standards_intro_text
- `StandardsTableSectionDto` — rows[] (each: ref, title, applies_to)
- `ScopeSectionDto` — activities[], per_room_scope (map<room, string[]>),
  new_install[], decommission[], retained[]  (each item: item_name, qty, room, notes)
- `RoomOverviewsSectionDto` — rooms[] (each: room, overview, summary,
  solution_type, works_summary, room_type)
- `ExclusionsSectionDto` — items[] (strings)
- `RiskAssessmentSectionDto` — matrix (5×5 scoring grid), hazards[]
  (each: ref RA01..RAxx, hazard, persons_at_risk[], initial_l, initial_s,
  initial_r, controls[], residual_l, residual_s, residual_r)
- `MethodStatementSectionDto` — team[] (role, qty, requirements),
  tools[], ppe (map<task, ppe_list>), access_equipment[],
  access_requirements[], client_responsibilities[], steps[]
  (each: title, bullets[], associated_risks[]), material_handling[],
  permits[], fixings_controls[], supervision[], coordination[], it_safety[]
- `EmergencySectionDto` — nearest_hospital, fire_assembly_point,
  fire_warden, first_aider, defibrillator, isolation_switch,
  emergency_contacts[], accident_procedure[], fire_procedure[], riddor_matrix[]
- `CoshhSectionDto` — inventory[] (each: product, use, ghs_codes[], controls[])
- `EnvironmentalSectionDto` — waste_disposal[], noise_dust_vibration[]
- `WelfareSectionDto` — toilets, washing, rest_area, first_aid, drinking_water
- `SignoffSectionDto` — company (name/position/date/sig), client (blanks for accept)
- `AppendixToolboxSectionDto` — instruction_text, row_count (default 5)

Each DTO:
- `readonly` class, promoted-property constructor
- `fromArray(array $data): self` static builder tolerant of missing keys
- `isEmpty(): bool` helper (used by renderers to conditionally skip sections)

### Task 3 — Unit tests

`tests/Unit/Support/Rams/RamsThemeTest.php`:
- `palette()` returns hex for known key
- `palette()` throws on unknown key
- `sectionOrder()` returns array
- Singleton binding resolves to same instance

`tests/Unit/Support/Rams/RamsDocumentDtoTest.php`:
- Construction from all 16 section DTOs succeeds
- `toArray()` round-trips shape
- `fromRawArray()` builds from fixture map
- Missing section → the constructor requires it (compile-time / TypeError)

Per-section DTO tests (16 files):
- Construction succeeds with typical data
- `fromArray()` tolerant of missing keys
- `isEmpty()` returns true on blank instance

## Constraints

- No changes to `DocxBuilderService.php` or `rams.blade.php` in this
  plan. `grep -r 'RamsTheme\|RamsDocumentDTO' resources/` returns zero.
- `php -l` clean on every new file.
- All new tests green.
- Existing 806-test suite still green (no regressions).

## Commits (target)

1. `feat(rams): RamsTheme config + typed accessor + service provider binding (plan-01)`
2. `feat(rams): RamsDocumentDTO + 16 section DTO leaves + fromArray builders (plan-01)`
3. `test(rams): unit tests for RamsTheme + all 16 section DTOs (plan-01)`

## Deliverable check

At plan close:
- New files exist, wired into container.
- 40+ new unit tests pass.
- Zero renderer files touched.
- Prod render pipeline behaviour unchanged.

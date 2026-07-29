# How to change RAMS document output

Phase `260726-rf3-rams-render-unification` moved RAMS PDF + DOCX
rendering onto a shared design-token + typed-DTO backbone. This is the
one-page reference for "I want to change the RAMS output — where do
I edit it?"

## The pipeline in one paragraph

Every RAMS render call flows: `RamsDocument` → `RamsDisplayPatchService`
(transient patches for personnel / scope / rooms) → `RamsDocumentComposer`
(builds a typed `RamsDocumentDTO` of 16 section leaves) → renderer.
Renderer choice is gated by `RAMS_UNIFIED_COMPOSER` env flag:

- **Off (default)**: legacy `resources/views/pdf/rams.blade.php` +
  `App\Services\DocxBuilderService::buildLegacy` — original code
  paths, byte-for-byte unchanged from pre-phase.
- **On**: `resources/views/pdf/rams-v2.blade.php` +
  `App\Services\DocxBuilderServiceV2` — DTO/Theme-consuming paths.
  Partial adoption today (cover + doc-control + company-info +
  sign-off + exclusions in both; standards + emergency in PDF only).
  Everything else delegates to legacy for byte-identical output.

## Common change types

### "I want to change a colour or font"

Edit `config/rams_theme.php`. Every hex + font-name in either renderer
resolves through the `RamsTheme` accessor. Change once, both formats
update in lockstep.

**Do not** hard-code hex values in `DocxBuilderService`, `DocxBuilderServiceV2`,
`rams.blade.php`, or `rams-v2.blade.php`. Legacy paths still hold some
hard-coded values from pre-phase; those will migrate to the theme as
they're touched.

### "I want to change a section's data source"

Edit the composer method for that section in
`app/Support/Rams/SectionComposers/` (16 files, one per section). The
composer converts raw `RamsDocument` attributes into the typed DTO
shape. Both renderers pick up the change automatically when the flag
is on.

For legacy-flag-off callers, you'll need to duplicate the change in the
V1 renderer too. Document any known drift in `deferred-items.md`.

### "I want to add a new section to the RAMS"

1. Add a new `App\Support\Rams\Sections\{Name}SectionDto` (readonly
   class + `fromArray()` + `isEmpty()`).
2. Add it to `RamsDocumentDTO` (typed property + constructor arg).
3. Add its slug to `config/rams_theme.php`'s `section_order`.
4. Add an `App\Support\Rams\SectionComposers\{Name}Composer` that
   builds it from `RamsDocument`.
5. Wire the composer into `RamsDocumentComposer::compose()`.
6. Render in `resources/views/pdf/rams-v2.blade.php` +
   `App\Services\DocxBuilderServiceV2` — both consume from
   `$dto->{name}` / `$theme->palette(...)`.
7. Add unit test in `tests/Unit/Support/Rams/Sections/` covering
   construction + fromArray + isEmpty.
8. Add a snapshot regen: `php artisan rams:regenerate-snapshots
   tilda-21cq29531` + review the diff.

### "I want to change how a field renders visually"

Edit the specific section in `rams-v2.blade.php` (for PDF) +
`DocxBuilderServiceV2` (for DOCX). Both consume the same DTO/Theme so
the underlying value stays consistent — only the presentation differs.

### "I found drift between PDF and DOCX"

Two possibilities:
- **Data drift** — one format reads from a different source. Fix by
  routing both through the DTO (i.e. port that section to V2 if not
  already done).
- **Style drift** — one format has hard-coded chrome the other doesn't.
  Fix by moving the style token into `RamsTheme` and using it in both
  paths.

Check `deferred-items.md` first — known drift is logged there with
fix paths.

## Kill switch — flipping between old and new

Environment variable in `.env`:

```
RAMS_UNIFIED_COMPOSER=false   # default; legacy V1 paths only
RAMS_UNIFIED_COMPOSER=true    # DTO/Theme-consuming V2 paths where available
```

Then `php artisan config:cache && php artisan queue:restart` — worker
picks up the change immediately.

**Snapshot tests** verify parity between the two paths on the Tilda
fixture. Run: `php artisan test --group snapshot`. Any deliberate
output change requires regenerating goldens:
`php artisan rams:regenerate-snapshots tilda-21cq29531`.

## Files worth knowing about

| File | Purpose |
|------|---------|
| `config/rams_theme.php` | Design tokens — palette, fonts, spacing, section order |
| `app/Support/Rams/RamsTheme.php` | Typed accessor for the config |
| `app/Support/Rams/RamsDocumentDTO.php` | Root DTO (16 section leaves) |
| `app/Support/Rams/Sections/*.php` | Per-section DTOs (16 files) |
| `app/Support/Rams/SectionComposers/*.php` | RamsDocument → DTO builders |
| `app/Support/Rams/RamsDocumentComposer.php` | Orchestrator that composes the DTO |
| `resources/views/pdf/rams.blade.php` | Legacy PDF (flag off) |
| `resources/views/pdf/rams-v2.blade.php` | New PDF (flag on) |
| `app/Services/DocxBuilderService.php` | Legacy DOCX (flag off) — has public seams `buildCoverSection` + `buildRestOfDocument` V2 delegates to |
| `app/Services/DocxBuilderServiceV2.php` | New DOCX (flag on) |
| `tests/Fixtures/rams/tilda-21cq29531/` | Snapshot fixture |
| `tests/Snapshot/Rams/*.php` | Byte-parity tests |
| `.planning/phases/260726-rf3-rams-render-unification/deferred-items.md` | Known gaps + fix paths |

## Ownership of legacy paths

Legacy `rams.blade.php` + `DocxBuilderService::buildLegacy` are
**maintenance mode** — bug fixes only, no new features. Any new field
or section lands in the V2 path (blade + DocxBuilderServiceV2). When
Plan 05b Part 1's 3 remaining deferrals are resolved + Tilda fixture
comes from live VPS data, we can flip `RAMS_UNIFIED_COMPOSER=true` as
the default, run one week of live soak, then delete the V1 paths in
a follow-up quick task (`260805-rf3-remove-old-render-paths`).

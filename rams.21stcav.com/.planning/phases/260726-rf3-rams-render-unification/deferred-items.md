# Deferred Items — Phase 260726-rf3

Items discovered during phase execution that are out of scope for the current
plan. Not fixed by the executor — logged here for later triage.

---

## Pre-existing blade bugs surfaced during Plan 03 smoke-testing

### `pdf.rams` blade emits PHP warning when `site_emergency` is partial

**Discovered:** Plan 03 executor, 2026-07-26, while smoke-testing rams-v2.

**Where:** `resources/views/pdf/rams.blade.php:1979` (and mirrored in the new
`rams-v2.blade.php` at the equivalent line — same code path).

**Symptom:** When `reviewed_data.site_emergency` is set but does NOT contain
`fire_warden_contact` / `first_aider_contact` / `electrical_isolation_switch`
/ `fire_extinguisher_class`, Blade throws `Undefined array key` on render.

The guard is:
```blade
@if($hasSiteEmerg)  {{-- fires when ANY key is non-empty --}}
    ...
    <td class="e-val">{{ $siteEmerg['fire_warden_contact'] ?: '—' }}</td>
    ...
```

The `?:` (falsy-check) on an undefined key triggers the warning under PHP 8.4.
The `empty-scope` composer fixture (added by Plan 02) exhibits this — it sets
5 of the 9 emergency keys, triggering the guard but not populating the row.

**Not fixed in Plan 03:** Fix would touch the LEGACY blade and add null-safety
to every unguarded `$siteEmerg[...]` read. Scope-bounded to Plan 03 changes
only (SCOPE BOUNDARY rule) — this is a pre-existing bug that predates the
phase. Plan 05 parity sweep should either fix both blades or extend the DTO's
EmergencySectionDto to always emit the full 9-key shape (preferred: fail-safe
default in the DTO).

**Fix suggestion:** In `EmergencySectionDto::fromArray`, default every field
to `''` (already done) and change the blade guards to `$siteEmerg['fire_warden_contact'] ?? ''`
in both `rams.blade.php` and `rams-v2.blade.php`.

---

## Composer material_handling shape mismatch

**Discovered:** Plan 03 executor, 2026-07-26, building the Tilda fixture.

**Where:** `app/Support/Rams/SectionComposers/MethodStatementComposer.php:134`
reads `$rd['material_handling']` through `$stringList()`, which explodes on
arrays (`Array to string conversion`).

**Symptom:** Prod records store `reviewed_data.material_handling` as an
OBJECT: `{ "large_items": [{"item":"...", "weight_kg":55, ...}], "handling_notes": "..." }`.
The legacy `pdf.rams` blade reads that object shape at line 428:
`$mhItems = is_array($matHandling['large_items'] ?? null) ? $matHandling['large_items'] : [];`.

`MethodStatementComposer` treats the same key as a **string list** and
casts each `large_items` entry through `(string) $item` — throws under
PHP 8.4 error-mode.

**Not fixed in Plan 03:** Composer contract belongs to Plan 02 / Plan 05.
The Tilda fixture omits `material_handling` for now so composer runs
cleanly. Plan 05 should either:

1. Extend `MethodStatementSectionDto` with a structured `materialHandlingItems`
   field (array of `{item, weight_kg, handling_method}` maps) alongside the
   existing bullet-list `materialHandling`, and update the composer to read
   both shapes, or
2. Move material_handling out of `MethodStatementSectionDto` into its own
   `MaterialHandlingSectionDto`.

Once resolved, restore the material_handling block in the Tilda fixture and
re-capture snapshot goldens.

**RESOLVED** — Plan 05a Commit B (`a743af5`, 2026-07-26) landed shape
detection in `MethodStatementComposer`. Object vs string-list detected via
`is_array($rawMh) && (array_key_exists('large_items', $rawMh) || array_key_exists('handling_notes', $rawMh))`.

---

## Plan 05b Part 1 deferrals — DOCX DTO adoption gaps

**Discovered:** Plan 05b Part 1 executor, 2026-07-29, when the exclusions
port to DOCX succeeded cleanly but the 3 other candidate sections
(Standards & Guidance, Emergency Procedures, Welfare) failed the
"same content, different code path" invariant.

### DOCX Standards & Guidance table

**Where:** `DocxBuilderService::buildRestOfDocument` renders the Standards
table only when `$data['standards_references']` is populated. Populated by
`Tier1RamsDefaultsService` at **build time**, not render time — so a
render-only refactor sees the field as empty and skips the table.

**Symptom:** Naive port from `$dto->standardsTable->rows[]` (which falls
back to config) added ~14KB of legitimate but NEW content to Tilda's DOCX,
because config-based rendering fires even when the source RamsDocument
never got the standards seeded.

**Fix path:** Either (a) run `Tier1RamsDefaultsService` at render time on
V2's path when the DTO field is empty, or (b) reproduce V1's presence-gate
in V2 so the section only renders when populated. Option (b) is safer —
V1 gate is the source of truth.

### DOCX Emergency Procedures

**Where:** `DocxBuilderService::buildRestOfDocument` renders only the
static contact / accident / fire prose. No `site_emergency` table is
rendered in V1 output despite the DTO carrying the 9 keys (per Plan 05a).

**Symptom:** DTO port would ADD a new site_emergency section to DOCX
output that doesn't exist today.

**Fix path:** Add the section to V1 first (mirroring the PDF blade's
§7.0 Site-Specific Emergency Details), then port V2 to the DTO. That's
a genuine feature addition, not a refactor — belongs in a fresh quick
task, not a plan-05 close.

### DOCX + PDF Welfare Arrangements

**Where:** V1 renders fixed welfare prose keyed off
`programme.welfare_notes` (single free-text field). `WelfareSectionDto`
models 5 separate descriptors (toilets / washing / rest_area / first_aid
/ drinking_water) with generic fallback text.

**Symptom:** Shape mismatch — V1 renders "programme notes"; DTO has "5
descriptors". Neither is wrong; they're modelling the same concept
differently.

**Fix path:** Reconcile the shape — pick one (probably the DTO's 5-key
model as more useful for downstream O&M/handover docs), migrate
`programme.welfare_notes` into the 5-key shape via a data-migration
command, then port both renderers. Non-trivial semantic change.

---

## Real Tilda fixture from live VPS

**Not blocking phase close but limits parity confidence.**

Current `tests/Fixtures/rams/tilda-21cq29531/record.json` is hand-crafted
per Plan 03 (executor had no VPS DB access). Snapshot tests prove
byte-parity on THIS fixture but don't cover the shape variance a real
project brings.

**Fix path:** On a machine with VPS DB access:
```bash
php artisan tinker --execute="echo App\Models\RamsDocument::find(92)?->toJson();"
```
Save output to `tests/Fixtures/rams/tilda-21cq29531/record.json`, then
`php artisan rams:regenerate-snapshots tilda-21cq29531` to re-capture
goldens. Commit both.

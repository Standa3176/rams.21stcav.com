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

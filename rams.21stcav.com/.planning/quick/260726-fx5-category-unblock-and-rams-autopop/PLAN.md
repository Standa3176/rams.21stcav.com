---
name: 260726-fx5-category-unblock-and-rams-autopop
status: planning
started: 2026-07-26
branch: feat/worksheet-classifier-universal
scope: 2 bundled fixes — (A) unblock package approve when categories are service_contracts/customer_supplied, (B) auto-populate RAMS form fields from project data
---

## Problem — Bundle A (blocker)

`/project-packages/{id}/review` **Approve** fails with dozens of
`equipment.N.category is invalid` errors whenever any row has been
classified as `service_contracts` or `customer_supplied` by the
QuoteWerks importer (260725-qw3 canonical vocab).

Root cause: `RamsReviewValidatorService.php:53` accepts only 5 values —
`hardware,cables,consumables,services,option` — but the classifier + the
review-page dropdown use **7** values including `service_contracts` and
`customer_supplied`. Every QW package with a warranty / care pack line
now dead-ends at approve.

Extra: user reports the dropdown "doesn't look like a dropdown" — the
category `<select>` is styled with `color: var(--text-muted)` + no
border, so it visually reads as static text.

User directive: "if category is not used in RAMS, default category to
Unknown so they are populated at site survey but do not hold up RAMS."

## Problem — Bundle B (RAMS form auto-populate)

On `/rams/{id}/review`, several fields that SHOULD auto-populate from
existing project / quote / prior-RAMS data start blank, forcing PMs to
retype the same info every revision:

- **`site_contact`** — form reads `$project['site_contact']` but
  `RamsDisplayPatchService` writes it to `client_contact_name`. Naming
  asymmetry means the form field never receives the resolved value.
- **`site_vehicles`** — sits in `reviewed_data.programme.site_vehicles`
  but is never mirrored onto `$project[]` for the form to read.
- **`subtitle`** — no auto-derive at all; PM types it every time.
- **`site_emergency` block (10 fields) + CDM roles** — retyped on every
  revision even though the same project's prior RAMS already has them.

## Tasks

### Task A1 — Widen validator + add `unknown` fallback

- `EquipmentCategoryClassifier::CATEGORIES` — add `'unknown'` as the
  8th canonical value.
- `RamsReviewValidatorService.php` line 53 — replace the 5-value enum
  with a `Rule::in(EquipmentCategoryClassifier::CATEGORIES)` so the
  validator can never fall behind the classifier again.
- `parseReviewPayload` / `normaliseEquipmentCategory` — if the incoming
  category isn't in the canonical set, coerce to `unknown` (currently
  falls to `hardware`, which silently mis-labels). Preserves the
  "unknown → PM decides later" contract.
- `review.blade.php` — `$categoryOptions` array + JS row-template gain
  the `'unknown' => 'Unknown (set later)'` entry.

**Files:**
- `app/Services/Imports/EquipmentCategoryClassifier.php`
- `app/Services/RamsReviewValidatorService.php`
- `app/Http/Controllers/ProjectPackageReviewController.php` (fallback default)
- `resources/views/project-packages/review.blade.php` (2 spots)

**Test guard:** add unit test asserting all values in
`EquipmentCategoryClassifier::CATEGORIES` pass validation — locks the
two enums together forever.

### Task A2 — Category dropdown visibility

Add subtle border + native chevron cue to
`select[data-equip-category]` so it visually reads as a picker:

```css
#s-equipment .repeater-table select[data-equip-category] {
    border: 1px solid rgba(0, 0, 0, .12);
    background-color: #fafafa;
    padding-right: 1.2rem;
    /* keep existing small-caps sizing */
}
```

CSS-only. Zero blade / logic change.

### Task B1 — RAMS form auto-populate mirrors

`app/Services/Rams/RamsDisplayPatchService.php` — extend the section 4
(client contact) + section 5 (programme) blocks:

```php
// Reverse-mirror: keep the form's `site_contact` field in sync with
// the resolved client_contact_name so blank RAMS pre-fill from quote data.
if (empty($p['site_contact'])) {
    $p['site_contact'] = $p['client_contact_name'] ?? '';
}

// Programme → project mirror for the review form's Site Vehicles field.
if (empty($p['site_vehicles'])) {
    $vehicles = $prog['site_vehicles'] ?? [];
    $p['site_vehicles'] = is_array($vehicles)
        ? implode("\n", array_filter(array_map('trim', $vehicles)))
        : (string) $vehicles;
}

// Auto-derive subtitle when the PM hasn't set one.
if (empty($p['subtitle'])) {
    $siteFirstLine = trim(strtok((string) ($p['site_address'] ?? ''), "\r\n"));
    $client        = trim((string) ($p['client'] ?? ''));
    $parts         = array_filter([$siteFirstLine, $client, 'AV Installation']);
    $p['subtitle'] = count($parts) > 1 ? implode(' | ', $parts) : '';
}
```

### Task B2 — Prior-RAMS auto-carry (site_emergency + CDM)

In `RamsDisplayPatchService::patch()` section 6, after `$rd` is
mutated, look up the most recent **completed** RAMS on the same project
(excluding the current one) and seed empty sub-keys from it:

```php
if ($rams->project_id && (empty($rd['site_emergency']) || empty($rd['cdm']))) {
    $prior = RamsDocument::query()
        ->where('project_id', $rams->project_id)
        ->where('id', '!=', $rams->id)
        ->where('status', RamsDocument::STATUS_COMPLETED)
        ->orderByDesc('generated_at')
        ->first();
    if ($prior) {
        $priorRd = $prior->reviewed_data ?? [];
        if (empty($rd['site_emergency']) && ! empty($priorRd['site_emergency'])) {
            $rd['site_emergency'] = $priorRd['site_emergency'];
        }
        if (empty($rd['cdm']) && ! empty($priorRd['cdm'])) {
            $rd['cdm'] = $priorRd['cdm'];
        }
    }
}
```

Non-destructive — only fills blanks. Transient (no save) — PM can
still override before submitting.

## Non-goals

- Extra visible form fields (client_contact_email/phone,
  project_manager_phone) — data already renders on the PDF, adding
  editable form fields is a separate scope call.
- Renaming `site_contact` → `client_contact_name` in the form —
  breaking rename, defer.
- Migration or `.env` change — none needed.

## Commits (target)

1. `fix(validator): widen equipment category enum + add 'unknown' fallback (260726-fx5)`
2. `feat(review): visible category dropdown affordance (260726-fx5)`
3. `feat(rams): auto-populate site_contact/site_vehicles/subtitle from project data (260726-fx5)`
4. `feat(rams): prior-RAMS auto-carry for site_emergency + CDM (260726-fx5)`

## Test plan

- Unit: `EquipmentCategoryClassifierTest` — every constant value passes
  validator (locks the two enums).
- Unit: `RamsDisplayPatchServiceTest` — `site_contact` mirrors from
  `client_contact_name`; `site_vehicles` joins array; `subtitle`
  derives; prior-RAMS carry seeds empty blocks only.
- Manual live: submit package 156 (Tilda) approve — no
  "category invalid" errors; check dropdown visibility.

## Deploy

`git pull` + `php artisan optimize:clear` + `php artisan config:cache`.
No migration. No npm build.

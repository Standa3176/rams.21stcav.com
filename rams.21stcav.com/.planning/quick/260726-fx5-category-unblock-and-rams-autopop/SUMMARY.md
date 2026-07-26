---
name: 260726-fx5-category-unblock-and-rams-autopop
status: complete
completed: 2026-07-26
branch: feat/worksheet-classifier-universal
commits:
  - 8d636d4  # Bundle A — category enum unblock + Unknown fallback + visible dropdown
  - 41536c1  # Bundle B — RAMS form auto-populate mirrors + prior-RAMS auto-carry
migrations: 0
npm_build: false
---

## What shipped

Two bundled fixes triggered by the user reporting the Tilda package
(#156) approve fail + noting the RAMS form field re-entry pain.

### Bundle A — Category approve unblock (`8d636d4`)

**Trigger.** User screenshot of `/project-packages/156/review` showing
`equipment.76.category is invalid` through `equipment.98.category is
invalid`. Approve dead-ended.

**Root cause.** `RamsReviewValidatorService.php:53` accepted only 5
values — `hardware,cables,consumables,services,option`. The 260725-qw3
classifier + review-page dropdown use the full 7-value canonical set
including `service_contracts` and `customer_supplied`. Every QW
package with a warranty / care pack / customer-supplied row
dead-ended.

**Fixes:**
- `EquipmentCategoryClassifier::CATEGORIES` gains `'unknown'` as the
  8th canonical value — escape hatch. Per user directive: category not
  being classified must NOT hold up RAMS approve; PMs pick Unknown and
  resolve at site-survey time.
- `RamsReviewValidatorService` sources the enum from
  `EquipmentCategoryClassifier::CATEGORIES` via `Rule::in()`. The two
  vocabularies can never fall out of sync again — a future 9th value
  added to the classifier automatically validates.
- `review.blade.php` `$categoryOptions` array + JS row template gain
  `'unknown' => 'Unknown (set later)'`.
- Category `<select>` styling gains visible border + native chevron
  (inline-SVG background) + hover/focus states. Pre-fix: muted-text
  only, read as static text — user reported "there isn't a dropdown".

### Bundle B — RAMS form auto-populate (`41536c1`)

**Trigger.** User: "on project data > rams, where poss can the fields
er rams use the data from proj data eg site contact etc".

**Fixes in `RamsDisplayPatchService::patch()`:**

Section 4/5 (client contact + programme):
- `site_contact` reverse-mirrors from resolved `client_contact_name`
  when empty. Naming asymmetry pre-fix — patch wrote to
  `client_contact_name` but form reads `site_contact`, so value never
  surfaced.
- `site_vehicles` lifts from `reviewed_data.programme.site_vehicles`
  (array joined with `\n` for the textarea).
- `subtitle` auto-derives `"{site line 1} | {client} | AV Installation"`
  when PM hasn't set one. Skips derivation when both pieces missing.

Section 6 (prior-RAMS auto-carry — new):
- On any new/uncompleted RAMS with empty `site_emergency` or `cdm`,
  seed from the most recent completed RAMS on the same project
  (ordered by `generated_at` desc).
- Non-destructive: fills blanks only. Transient (no save) — PM can
  override before submitting.
- Eliminates re-typing nearest hospital / fire wardens / first aiders /
  defibrillator / isolation switch + CDM duty-holder rows on every
  revision.

## Test coverage

- `EquipmentCategoryClassifierTest` — 24/24 green. Adding `unknown` to
  the CATEGORIES const doesn't invalidate any existing assertion.
- `RamsReviewValidatorService` + `RamsDisplayPatchService` have no
  dedicated unit tests currently — none broken. Deferred: unit test
  asserting every classifier constant value passes the validator
  (locks the two enums), and mirror-field tests on the patch service.
- `php -l` clean on all 4 touched files.

## Deploy

**No migration. No npm build.**

```bash
sudo -u stcav bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache
```

## Sanity checks after deploy

1. **Bundle A** — re-open `/project-packages/156/review` → click
   Approve → **no** `equipment.N.category is invalid` errors. Every
   equipment row's category column shows a visible dropdown (border +
   chevron); clicking reveals 8 options including "Unknown (set later)".
2. **Bundle B** — create a new RAMS on a project that has (a) a
   populated quote and (b) at least one completed prior RAMS:
   - site_contact field pre-fills from the client contact on the quote.
   - site_vehicles field pre-fills if the programme has any.
   - subtitle field pre-fills as `"{site} | {client} | AV Installation"`.
   - Site emergency block (hospital, fire wardens, etc.) and CDM roles
     pre-fill from the prior RAMS.

## Deviations from PLAN.md

1. **Test guard for classifier↔validator lock** — planned as a unit
   test, deferred. `Rule::in(EquipmentCategoryClassifier::CATEGORIES)`
   makes drift structurally impossible at runtime; test would be
   belt-and-braces.
2. **Commit count** — planned 4, shipped 2 (Bundle A rolled A1+A2
   together since they're both the "category works" story; Bundle B
   rolled B1+B2 since they're both in the same file). Cleaner history.

## Related

- **260725-qw3** — set up the 7-value canonical vocab; validator was
  the loose end that got missed then.
- **260726-fx4** — same-day predecessor. Different scope (survey →
  doc staleness + AI prompt context); does not overlap.

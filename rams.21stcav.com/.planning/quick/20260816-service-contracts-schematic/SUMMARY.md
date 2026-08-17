---
quick_id: 260816-uzh
slug: service-contracts-schematic
date: 2026-08-16
status: complete
---

# Quick Task 260816-uzh — Warranties render as device nodes in the D2 schematic

## What changed

`service_contracts` line items (warranties, support contracts) were
surviving `DrawingDataResolverService::filterHardware()` and rendering as
device nodes in the v1.3 D2 system schematic. A support contract is not a
physical device and carries no signal connections, so it cannot belong in a
signal-flow drawing under any reading — this was a defect, not a product
decision.

**Root cause:** `EXCLUDED_CATEGORIES` in `app/Services/Drawings/DrawingDataResolverService.php`
is inclusive-by-default (any category NOT listed still renders as a device
node). `services` was already excluded, but `service_contracts` — a
distinct, adjacent category in the canonical vocabulary
(`App\Services\Imports\EquipmentCategoryClassifier::CATEGORIES`) — was
missed when the vocabulary grew.

## Task 1 — Fix

Added `service_contracts` to `EXCLUDED_CATEGORIES`, beside the existing
`services` entry. Extended the class comment to record that the list is
inclusive-by-default and that `services`/`service_contracts` are both
non-physical and must stay excluded.

`EXCLUDED_KEYWORDS` was deliberately left untouched per plan constraint —
category-level exclusion is the correct mechanism, and adding
warranty/support keywords risks excluding genuine hardware whose name
happens to contain "support" (already covered generically by the `bracket`
keyword for things like a "support bracket").

**File:** `app/Services/Drawings/DrawingDataResolverService.php`
**Commit:** `c0ba41a` — `fix(drawings): exclude service_contracts from D2 schematic device nodes`

## Task 2 — Regression test

Extended `tests/Unit/Services/Drawings/RackStackForProjectTest.php` (the
established home for `DrawingDataResolverService::filterHardware()`
coverage — it already table-drove category-based filtering for the rack
palette) with `test_filter_hardware_survives_or_excludes_by_canonical_category()`.

The test:
- Drives one row per canonical category (`EquipmentCategoryClassifier::CATEGORIES`)
  through `filterHardware()` via reflection (the same technique used to
  originally prove the bug).
- Asserts a guard that the table's key list matches
  `EquipmentCategoryClassifier::CATEGORIES` exactly, so a future 10th
  category fails loudly instead of silently defaulting to "renders as a
  device."
- Asserts the full survives/excluded matrix for all 9 categories.

**Proved the test is real** (not a green-either-way tautology): removed
`service_contracts` from `EXCLUDED_CATEGORIES`, re-ran the test, confirmed
it failed (`1 failed (7 assertions)`), then restored the fix and confirmed
green again (`1 passed (10 assertions)`).

**File:** `tests/Unit/Services/Drawings/RackStackForProjectTest.php`
**Commit:** `4e99960` — `fix(drawings): regression test for filterHardware category matrix`

## Open question — `customer_supplied` (NOT changed)

`customer_supplied` also currently survives `filterHardware()` and renders
as a device node in the schematic. **This quick task deliberately left it
untouched.** Unlike a warranty, client-supplied kit may legitimately be
part of the system topology — the client provides the display, 21CAV wires
it into the rack. Whether that should render as a schematic device node (it
IS a real physical node in the signal chain) or be excluded (21CAV doesn't
own/install it) is a genuine product decision the user has not made.

The regression test documents `customer_supplied`'s **current** (surviving)
behaviour with an inline comment noting the decision is pending — it
records reality, it does not pre-empt the call. If/when the user decides,
update both the production filter and this test's expectation together.

## Verification

- Lint: `php -l` clean on both touched files.
- `php artisan test --filter="Drawing|Schematic"` — 313 passed, 2 skipped
  (D2-binary-dependent tests, expected on this machine), 0 failed.
- The 2 `DrawIoSpikeController` lock-tests repaired earlier today (quick
  task 260816-t5c) pass — no regression.

## Deviations from Plan

None — plan executed exactly as written.

## 🚨 Files to upload to live

- `app/Services/Drawings/DrawingDataResolverService.php`

After upload: run `php artisan optimize:clear` on the server. **No
migration required** — this is a pure PHP logic change (one line added to a
class constant array plus a doc comment).

Test file (`tests/Unit/Services/Drawings/RackStackForProjectTest.php`) does
not need deploying to the live server — dev/CI only.

---
quick_id: 260816-uzh
slug: service-contracts-schematic
date: 2026-08-16
status: planned
---

# Quick Task 260816-uzh — Warranties render as device nodes in the D2 schematic

## Evidence (proven, not inferred)

Invoked `DrawingDataResolverService::filterHardware()` directly via reflection with one row per category:

```
SURVIVES: Samsung 65in Display        (hardware)              ← correct
SURVIVES: 3 Year Extended Warranty    (service_contracts)     ← BUG
SURVIVES: Client Supplied Laptop      (customer_supplied)     ← see "not in scope"
        (hardware_supply_only excluded — 260816-rdz fix confirmed working)
```

A `service_contracts` line reaches the v1.3 D2 system schematic as a **device node**. A support contract is not a physical device and has no signal connections — it cannot belong in a signal-flow drawing under any reading. This is a defect, not a product decision.

## Why it escapes both filters

`app/Services/Drawings/DrawingDataResolverService.php` filters devices two ways, and `service_contracts` slips past both:

1. `EXCLUDED_CATEGORIES = ['cables','consumables','services','option','hardware_supply_only']` — an inclusive-by-default allowlist. `service_contracts` is absent.
2. `EXCLUDED_KEYWORDS = ['cable','cat5','cat6','hdmi','usb-a to usb-b','install','commission','project management','mount','bracket','caddy','tray']` — contains no warranty/support/contract/licence term.

Note `services` IS excluded but `service_contracts` is not — almost certainly an oversight when the canonical vocabulary grew, since the two are adjacent concepts.

## Task 1 — Exclude service_contracts from schematic device nodes

**File:** `app/Services/Drawings/DrawingDataResolverService.php`

**Action:** Add `service_contracts` to `EXCLUDED_CATEGORIES`, beside the existing `services` entry. Extend the existing comment to record that this list is inclusive-by-default, so any category absent from it renders as a device — and that `services` and `service_contracts` are both non-physical and must stay excluded.

Do **not** touch `EXCLUDED_KEYWORDS` — a category-level exclusion is the correct mechanism here, and adding warranty keywords risks excluding genuine hardware whose name happens to contain "support" (e.g. a "support bracket", already covered by `bracket`).

**Acceptance criteria:**
- A `service_contracts` row does not survive `filterHardware()`
- A `hardware` row is unaffected
- `hardware_supply_only` remains excluded (no regression on 260816-rdz)
- Existing Drawings/Schematic tests pass unchanged

## Task 2 — Regression test

**File:** the established home for `DrawingDataResolverService` tests (find it first; extend rather than create a parallel file)

**Action:** Add a test driving one row per canonical category through `filterHardware()` and asserting exactly which survive. Table-drive it so the next category added to the vocabulary forces an explicit decision here rather than silently defaulting to "renders as a device".

Include `customer_supplied` in the table with its **current** (surviving) behaviour, and a comment stating that this is pending a product decision — so the test documents reality rather than pre-empting the user's call.

**Acceptance criteria:**
- Test fails if `service_contracts` is removed from `EXCLUDED_CATEGORIES`
- Test documents the full category → survives/excluded matrix

## Explicitly NOT in scope

**`customer_supplied` remains excluded from this fix.** It also currently survives into schematics, but unlike a warranty it is genuinely arguable — client-supplied kit may be a real part of the system topology (the client provides the display, 21CAV wires it into the rack). That is a product decision the user has not made. Record it in the SUMMARY as an open question; do not change its behaviour.

## Constraints

- One-line production change plus a test. No migration, no new packages.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Local-edit-then-upload (Phase 21 D-13) → `php artisan optimize:clear` after upload. No migration.

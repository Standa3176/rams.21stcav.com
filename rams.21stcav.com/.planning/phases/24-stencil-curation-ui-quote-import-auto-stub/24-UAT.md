---
status: partial
gaps_closed: 2026-08-14 (plans 24-10, 24-11, 24-12)
phase: 24-stencil-curation-ui-quote-import-auto-stub
source: [24-01-SUMMARY.md, 24-02-SUMMARY.md, 24-03-SUMMARY.md, 24-04-SUMMARY.md, 24-05-SUMMARY.md, 24-06-SUMMARY.md, 24-07-SUMMARY.md, 24-08-SUMMARY.md]
started: 2026-08-14
updated: 2026-08-14
---

## Current Test

number: 1
name: Data-state verification (pre-browser)
expected: |
  The seeded catalogue matches the phase's stated assumptions about stencil
  `source` values and review-queue population.
awaiting: user decision on the two confirmed defects below

## Environment

Local, `APP_ENV=local`, `DB_CONNECTION=sqlite` (`database/database.sqlite`). Nothing on the VPS was touched.

Setup performed for UAT:
- `php artisan migrate` — applied `2026_08_13_140000_...device_stencil_audits` **and** the unrelated pre-existing `2026_07_26_101000_add_signed_notification_sent_at_to_worksheets`
- `php artisan db:seed --class=DeviceStencilSeeder` → 96 stencils, 40 ports
- Local admin created: `uat-local@example.test` (role `admin`) — **throwaway, local only**

Resulting catalogue: **96 stencils · 40 ports · 91 with zero ports · 96 `engineer-curated` · 0 `auto-generated`**

---

## Tests

### 1. Migration applies cleanly
expected: `needs_review` (indexed), `logo_path`, and `device_stencil_audits` are created; existing `metadata.needs_phase_24_curation` flags carry into `needs_review`.
result: pass
notes: Migration ran in 50.48ms. Backfill logic verified correct — replayed against the seeded rows it flagged exactly 91. (In this local run the migration executed against an empty DB *before* seeding, so the backfill legitimately found nothing; on the VPS the rows pre-exist and it will populate. Not a defect — a local ordering artifact.)

### 2. `stencils:coverage-report` runs
expected: Ranks part_numbers by real quote volume, reports Tier 1/2 split, never derives from the seed pack (Phase 21 D-15).
result: pass

### 3. `stencils:reapply-templates` dry-run is safe
expected: Dry-run by default, no writes.
result: pass
notes: Correctly refused to write. Output: `── DRY-RUN MODE (default) — no writes ──`

### 4. `stencils:reapply-templates` fills the zero-port stubs
expected: Per CONTEXT D-11, the ~92 zero-port seed stubs qualify under D-08's rule and get templated by this command.
result: **issue**
severity: **major**
reported: |
  Command output, with 91 zero-port stubs present in the catalogue:
  "No eligible stencils (source=auto-generated with zero device_stencil_audits rows). Nothing to do."
diagnosis: See **Gap 1**.

### 5. Review queue populates
expected: `/admin/device-stencils?source=auto-generated&needs_review=1` lists the stubs awaiting curation.
result: **issue**
severity: **major**
reported: |
  0 stencils have `source = auto-generated`. The documented filter combination returns an
  empty list even after 91 rows are correctly flagged `needs_review = true`.
diagnosis: See **Gap 1** — same root cause.

### 6. Stub curation is a zero-friction single-click save
expected: Per CONTEXT D-17 as amended, the ordinary `auto-generated` stub-curation path saves with no banner and no confirmation — the guard applies only to genuinely hand-built artwork.
result: **issue**
severity: **major**
reported: |
  The D-17 guard keys off `source === engineer-curated`. All 96 seeded stencils carry that
  value, so the "this will replace existing artwork" warning + explicit confirm fires on
  96 of 96 — including the 91 stubs that have no artwork to protect.
diagnosis: See **Gap 2**.

### 7. List view renders, filters, and searches
expected: Filter chips by source / needs_review / manufacturer; part_number search; both empty states.
result: pass
notes: Verified in-browser 2026-08-14. 96 results paginated 15/page; Search (part number), Source, Needs review and Manufacturer filters all present; Manufacturer dropdown populated from real data (23 entries); "Needs review" badges render on the stubs; columns Part Number / Manufacturer-Model / Source / Ports / Logo / Updated / Edit.

### 8. Edit screen — port table + live preview
expected: Reactive port rows; 600ms-debounced server-rendered SVG preview; failure keeps last good render.
result: **issue → FIXED** (`ac9501c`)
severity: **critical**
reported: |
  On any stencil WITH ports, the entire Alpine component was dead. 15 console errors,
  root cause `SyntaxError: Unexpected token ';' at new AsyncFunction` — the x-data
  expression failed to PARSE, so every downstream binding (ports, previewState,
  previewSvg, promotionBlockingReasons) threw ReferenceError. Rendered result: no port
  table, no live preview, no promote-button state. A static shell.
diagnosis: |
  `edit.blade.php:275` used `x-data="stencilPortEditor(@json($stencil->ports->toArray()), …)"`.
  `@json` emits raw double quotes, which terminate the double-quoted HTML attribute the
  moment the stencil has any ports — malformed HTML, unparseable Alpine expression.
  It looked fine on zero-port stencils only because `@json([])` renders `[]`, which
  contains no quotes. **Every one of the 12 PHPUnit tests passed throughout**, because
  feature tests assert server-rendered HTML and never execute JavaScript.
fix: Replaced with `Js::from()`, which escapes for exactly this context. Re-verified in
  browser: port table renders, live preview shows all 7 ports (HDMI IN, USB-C 1, USB-C 2,
  PWR, HDMI OUT, LAN, AUDIO), "Up to date" state indicator, Promote correctly enabled.

### 8b. D-17 banner suppressed on artwork-less stubs
expected: Per Gap 2's fix, a zero-port engineer-curated stub shows NO warning banner.
result: **issue → FIXED** (`ac9501c`)
severity: major
reported: |
  Plan 24-11 narrowed the SERVER-side guard in DeviceStencilController::update() to add
  `ports()->exists()`, but the Blade banner still keyed off `$isCurated` (source only),
  so the "this will replace your artwork" warning still rendered on all 91 stubs. Gap 2
  was only half closed — the server stopped blocking, but the friction remained visible.
  The comment directly above the banner even claimed "the ordinary stub-curation path
  stays a zero-friction single-click save".
fix: `$isCurated` now also requires `$stencil->ports->isNotEmpty()`, matching the server
  condition exactly. Verified both directions in-browser: zero-port stub → no banner;
  ClickShare Bar Pro (7 ports) → banner correctly still fires.

### 9. Logo upload sanitises SVG
expected: Malicious SVG stripped by `SvgSanitizerService`; PNG stored; `logo_path` set.
result: pending
notes: Automated coverage already proves the sanitisation (9/9 in `DeviceStencilLogoUploadTest`, incl. a `<script>`-bearing SVG). Browser check is confirmatory only.

### 10. Promote gate — hard-block vs soft-warn
expected: Zero ports / missing required fields / duplicate `port_id` blocked; missing logo / unclassified signal / absent positions warn but allow.
result: pending
notes: Server-side bypass already proven refused in `DeviceStencilPromotionTest` (9/9).

### 11. Provisional rails render distinctly in a real drawing
expected: Template-derived ports render dashed/muted in the actual draw.io canvas, visually distinct from verified ports.
result: pending
severity_if_failed: major
notes: **Cannot be verified locally or by any automated check.** Requires opening a real project drawing in the draw.io canvas. This is the check that would catch a regression of the mxGraph-grammar defect that took three review cycles — the emitter now uses the verified `<dashed dashed="1"/>` / `<strokealpha alpha="0.6"/>` grammar, but only a human looking at a rendered drawing confirms it.

---

## Gap closure — both CLOSED 2026-08-14

Plans **24-10** (Gap 1), **24-11** (Gap 2) and **24-12** (CONTEXT.md correction) were planned, plan-checker-verified with no blockers, and executed.

**Gap 1 — CLOSED.** `StencilsReapplyTemplatesCommand` eligibility changed from `source = auto-generated` to `needs_review = true`; `whereDoesntHave('audits')` left byte-for-byte intact as the now-sole safety boundary (verified at `StencilsReapplyTemplatesCommand.php:78`). Dry-run against the real seeded catalogue went from *"No eligible stencils. Nothing to do."* to *"Scanning 91 eligible stencil(s)… 52 stencil(s) affected"*. Tests 4 and 5 above now pass.

**Gap 2 — CLOSED.** The D-17 guard now requires `ports()->exists()` alongside `source === SOURCE_ENGINEER_CURATED` (verified at `DeviceStencilController.php:169`). Guard trigger count on the 96 seeded stencils: **96/96 → 5/96** — exactly the five genuinely hand-built stencils. Test 6 above now passes.

**Regression coverage added.** Both plans added tests built on the *realistic* catalogue shape (`engineer-curated`, zero-port stubs) rather than the disproven `auto-generated` fixture, and both were confirmed to fail against the pre-fix code. The plan-checker also corrected `DeviceStencilEditTest` Test 6, whose zero-port fixture would otherwise have passed for the wrong reason under the new predicate — i.e. it would have stopped testing the guard at all.

**Known consequence of Gap 2's fix (intended):** a stub's FIRST port-add is frictionless; once it has ports, later edits are guarded like real artwork.

**Accepted edge case (documented, not fixed):** `UpdateDeviceStencilPortsRequest` permits a zero-port save, so an admin could reduce a curated stencil to zero ports and the guard would not re-fire on the next edit. Self-inflicted and fully audit-logged.

---

## Gaps (original diagnosis, retained for the record)

### Gap 1 — CONTEXT D-11's premise is false; the 91 stubs are unreachable by the fill mechanism
severity: major
status: **CLOSED by plan 24-10**
requirement: criterion 5 (top-10 Tier 1 fill), DRAW-50 filter semantics

**Claimed (CONTEXT.md D-11):** *"They are all `source = auto-generated` with no audit rows, so they already qualify under D-08's re-apply rule. Do not build a second one-shot backfill command."*

**Actual:** all 91 zero-port stubs are `source = engineer-curated`, carrying `metadata.needs_phase_24_curation = true`. Zero are `auto-generated`.

**Root cause:** Phase 21 D-05 seeded the promoted v1.3 + top-50-gap entries as `engineer-curated` (they had hand-derived manufacturer/model/XML), tagging the ones still needing port work via `metadata.needs_phase_24_curation` rather than by `source`. Phase 24 then assumed `source` was the marker.

**Consequences:**
1. `stencils:reapply-templates` skips all 91 — the bulk-fill path does nothing.
2. `?source=auto-generated&needs_review=1` (criterion 3's literal filter) returns empty.

**Note:** `needs_review` is *correct* on all 91 — the migration backfill keyed off the metadata flag, not `source`, and works. The defect is confined to anything keying off `source`.

**Candidate fixes** (needs a decision, not an assumption):
- (a) Make D-08 eligibility `needs_review = true AND no audit rows`, dropping the `source` condition. Matches intent, and `needs_review` is already the accurate marker.
- (b) Add `metadata.needs_phase_24_curation = true` as an additional eligibility branch alongside `auto-generated`.
- (c) Re-classify the 91 to `auto-generated` via a one-shot command — but this contradicts Phase 21 D-05's deliberate `engineer-curated` labelling and would lose information.

### Gap 2 — the D-17 guard fires on 96 of 96 stencils, including 91 with no artwork
severity: major
status: **CLOSED by plan 24-11**
requirement: D-17 (as amended), phase main workflow

The guard's trigger (`source === engineer-curated`) was chosen as a proxy for "has hand-built artwork". In the real catalogue it is not: only 5 stencils (ClickShare Bar Pro, Neat Bar Pro, Netgear GS312TP, Samsung QM65C-T, Sennheiser TCC2) have genuine artwork; the other 91 carry the same `source` but are bare stubs.

This directly contradicts the amendment's own stated requirement: *"the ordinary stub-curation path (the phase's main workflow) must stay a single-click save with zero added friction."* As shipped, the phase's main workflow hits a destructive-sounding confirmation 91 times out of 96.

**Candidate fixes:**
- (a) Trigger on `source === engineer-curated AND ports()->exists()` — a stencil with no ports has no artwork worth guarding. Smallest change, directly matches intent.
- (b) Trigger on `metadata.needs_phase_24_curation !== true` — i.e. guard only stencils NOT tagged as awaiting curation.
- (c) Introduce an explicit `has_custom_artwork` flag rather than inferring from `source`. Most correct long-term, costs a migration.

Note (a) and Gap 1's (a) are consistent with each other: both stop treating `source` as a proxy for state it does not actually encode.

---

## Verified without a browser

- Full `tests/Feature/Drawings` suite: **245 passed**, 2 pre-existing `DrawIoSpikeController` failures (logged in `deferred-items.md`, predate Phase 24), 2 skipped (D2 binary unavailable).
- All routes registered inside the existing `Route::middleware('admin')` group.
- Promote-bypass (T-24-17) refused server-side, independent of client state.
- SVG upload sanitisation proven against a `<script>`-bearing payload.
- mxGraph emitter uses the verified `<dashed dashed="1"/>` / `<strokealpha alpha="0.6"/>` grammar with zero forbidden `stroke-dasharray` / `opacity=` spellings.

## Local cleanup pending

The UAT admin `uat-local@example.test` and the seeded local SQLite data remain in place. Remove or reset if this local DB is used for anything else.

---
phase: 23-xten-av-style-renderer
plan: 06
subsystem: ui
tags: [review-form, zone, blade, alpine, validation, xss-mitigation, v2.0, draw-46]

# Dependency graph
requires:
  - phase: 23-01
    provides: "config('drawings.zone_vocab') — canonical zone label list + D-01 category→zone defaults"
  - phase: 23-02
    provides: "ZoneGrouper + XtenAvLayoutEngine.xml() escape — the read side that consumes equipment[N][zone] and the defence-in-depth XML escape"
  - phase: 22-p02
    provides: "Precedent for @js(config(...)) publishing to window + Alpine row-template pattern (portPicker)"
provides:
  - "DRAW-46 D-03 zone-dropdown column on the quote-review equipment table (UI write side)"
  - "DRAW-46 D-04 free-text escape hatch with 50-char pattern-restricted input"
  - "Server-side validateEquipmentZones() helper enforcing /^[\\p{L}\\p{N} _\\-]+$/u allowlist on update() + approve()"
  - "parseReviewPayload() persistence of equipment[N][zone] into latestPackage->extracted_data['equipment']"
  - "Empty-zone-OMITTED convention (D-01 fall-through) — zone key only set when non-empty"
  - "window.__zoneVocab + zonePicker() Alpine factory consumed by both static rows and addRow() JS template"
affects:
  - "23-07 final phase verification (zone end-to-end smoke)"
  - "Future v2.0 plans that want to widen zone vocab — single config source"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Alpine.js factory function published to window for Blade + JS row-template reuse (mirrors Phase 22 portPicker)"
    - "Defence-in-depth XSS — server-side regex allowlist + renderer-side htmlspecialchars (Pitfall 8)"
    - "Validation helper extracted to private method called from BOTH update() and approve() (DRY across mirror paths)"
    - "Empty-string → key-omitted persistence (D-01 default fall-through semantics)"

key-files:
  created:
    - "tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php"
  modified:
    - "app/Http/Controllers/ProjectPackageReviewController.php"
    - "resources/views/project-packages/review.blade.php"

key-decisions:
  - "Broadened zone regex from /^[A-Za-z0-9 _\\-]+$/u (plan) to /^[\\p{L}\\p{N} _\\-]+$/u — Unicode-letter friendly per checker warning #6; enables non-ASCII zone labels (e.g. 'Régie' for a French control room) while preserving the Pitfall 8 XSS allowlist"
  - "Extracted validation into private validateEquipmentZones() helper called from BOTH update() and approve() — avoids duplication on mirror code paths"
  - "Empty / whitespace-only zone OMITTED from persisted JSON (not stored as '') — falls through to D-01 category default in renderer"
  - "window.__zoneVocab published once via @js(config(...)) so addRow() JS template doesn't re-render Blade"
  - "Defence-in-depth: kept Plan 02 XtenAvLayoutEngine::xml() htmlspecialchars escape — server validation is layer 1, XML escape is layer 2"
  - "Manual browser UAT approved inline by user based on 7/7 feature test coverage — full UAT deferred to 23-07"

patterns-established:
  - "Alpine factory + window.__vocab: factory function (zonePicker) defined once in the page <script>, vocab published once via @js(config(...)), consumed by both static @foreach rows AND the addRow() JS row template literal"
  - "Mirror-path validation helper: when a controller has two methods that both accept the same form payload (update + approve), extract validation into a private void helper rather than duplicating $request->validate() blocks"
  - "Defence-in-depth XSS: free-text user input that flows into XML/HTML emit gets validated at the network boundary (regex allowlist) AND escaped at the emit site (htmlspecialchars) — never trust either layer alone"

requirements-completed: [DRAW-46]

# Metrics
duration: ~9min
completed: 2026-05-14
---

# Phase 23 Plan 06: Review Zone Dropdown UX Summary

**Per-equipment-row zone dropdown on the quote-review form, with D-04 free-text escape hatch, server-side Unicode-friendly regex validation, and empty-zone fall-through to D-01 category default.**

## Performance

- **Duration:** ~9 min
- **Started:** 2026-05-14T09:52:47+0100 (Task 1 RED commit)
- **Completed:** 2026-05-14T09:57:11+0100 (Task 2 commit) + SUMMARY commit
- **Tasks:** 3 (Task 1 controller TDD, Task 2 Blade UI, Task 3 manual UAT — approved inline)
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments

- Engineers can now pick a zone per equipment row from a dropdown sourced from `config('drawings.zone_vocab')` OR type a free-text override via the "Other (free text)…" escape hatch
- Server enforces a Unicode-letter regex allowlist `/^[\p{L}\p{N} _\-]+$/u` (max 50 chars) on every posted `equipment[N][zone]` — blocks `<script>` payloads at the network boundary (Pitfall 8 / T-23-06-A1/A2 mitigation)
- Zone persists into `latestPackage->extracted_data['equipment'][N]['zone']` — read back by Plan 02 ZoneGrouper as the D-02 per-device override
- Round-trip preserved: re-rendering the review form after save shows the persisted zone selected in the dropdown (or in the free-text input for non-vocab values)
- 7 feature tests green covering: vocab persistence, free-text within regex, Unicode "Régie" accept, XSS reject, 50-char length cap reject, empty-zone omission, existing-field preservation
- Strictly additive: existing review form fields (`part_number`, `name`, `category`, `area`, `quantity`) untouched
- v1.3 invariant: zero diff on the 5 schematic-renderer files (`SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, `BoundPdfBuilderService`, `DrawingExportRendererService`)

## Task Commits

Each task was committed atomically:

1. **Task 1 RED — failing tests** — `820d6d1` (test)
2. **Task 1 GREEN — controller persists + validates `equipment[N][zone]`** — `b307e07` (feat)
3. **Task 2 — zone dropdown column on review form (static row + addRow template + zonePicker Alpine factory)** — `e5bd170` (feat)
4. **Task 3 — manual UAT (approved by user inline based on 7/7 test coverage; full browser UAT deferred to 23-07)** — no code commit

**Plan metadata:** (this SUMMARY commit) — `docs(23-06): complete review zone dropdown plan (UAT approved on tests)`

## Files Created/Modified

- `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` *(created — 7 feature tests, 252 lines)* — DRAW-46 D-03 write-side coverage: vocab value, free-text within regex, Unicode `Régie`, XSS reject, 51-char reject, empty-omit, full-field preservation
- `app/Http/Controllers/ProjectPackageReviewController.php` *(modified — +50/-1)* — added `validateEquipmentZones(Request)` helper called from both `update()` and `approve()`; `parseReviewPayload()` now persists `zone` key when non-empty
- `resources/views/project-packages/review.blade.php` *(modified — +100/-3)* — new `<th>Zone</th>` between Category and Title/Section; per-row `zonePicker` Alpine block (dropdown + free-text + ↩-revert button + help text + `@error` rendering); equivalent block injected into `equipmentRowTemplate()` JS literal; `window.__zoneVocab` published via `@js(config('drawings.zone_vocab', []))`; `colspan` on room-spacer and empty rows bumped 6→7

## Validation rule verbatim

```php
private function validateEquipmentZones(Request $request): void
{
    $request->validate([
        'equipment.*.zone' => [
            'nullable',
            'string',
            'max:50',
            'regex:/^[\p{L}\p{N} _\-]+$/u',
        ],
    ]);
}
```

## Alpine zonePicker() factory pattern

```javascript
window.__zoneVocab = @js(config('drawings.zone_vocab', []));

function zonePicker(initial, vocab, isFreeTextInitial) {
    const initialIsVocab = !!initial && Array.isArray(vocab) && vocab.includes(initial);
    return {
        vocab: Array.isArray(vocab) ? vocab : [],
        selected: initial === '' ? '' : (initialIsVocab ? initial : '__other__'),
        freeText: initialIsVocab ? '' : (initial || ''),
        isFreeText: !!isFreeTextInitial,
        onChange() {
            if (this.selected === '__other__') { this.isFreeText = true; }
            else { this.isFreeText = false; this.freeText = ''; }
        },
        cancelFreeText() {
            this.isFreeText = false;
            this.freeText = '';
            this.selected = '';
        },
    };
}
```

- Static Blade row: `<div x-data="zonePicker(@js($currentZone), @js($zoneVocab), {{ $isFreeText ? 'true' : 'false' }})">`
- JS `equipmentRowTemplate()`: `<div x-data="zonePicker('', window.__zoneVocab, false)">` — vocab pulled from the window-published source
- Only ONE input is `name`-bound at any given time (toggled by `:name="isFreeText ? '' : 'equipment[N][zone]'"` and inverse on the input) — single field POSTed per row

## Decisions Made

- **Unicode-letter regex broadening** — the plan specified `/^[A-Za-z0-9 _\-]+$/u` (ASCII only). The earlier agent broadened to `/^[\p{L}\p{N} _\-]+$/u` per checker warning #6, so engineers can label French ("Régie"), German, or any UTF-8 zone names. Defence-in-depth via Plan 02's `htmlspecialchars(ENT_XML1|ENT_QUOTES)` keeps the XSS attack surface closed — the regex's job is structural sanity, not the last line of defence.
- **Validation helper extracted** — both `update()` and `approve()` accept the same review payload and both call `parseReviewPayload()`. Plan suggested an optional helper "to avoid duplication" — taken.
- **Empty zone OMITTED from JSON** — per D-01 the renderer falls back to the category default when no per-device zone is set. Persisting `'zone' => ''` would defeat that fallback (the key would be present-but-empty). Trimming + key-omit is the cleanest contract.
- **window.__zoneVocab published once** — avoids re-rendering Blade inside the JS template literal and keeps the vocab source single (Phase 22 P02 portPicker precedent).
- **Manual UAT approved inline** — user opted to approve Task 3 based on 7/7 feature test coverage rather than running the full 7-step browser smoke; full UAT deferred to Plan 23-07 final phase verification where the end-to-end XML emit will be visually inspected anyway.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing Critical / i18n correctness] Unicode-letter regex broadening**
- **Found during:** Task 1 (Controller TDD — validateEquipmentZones)
- **Issue:** Plan specified `/^[A-Za-z0-9 _\-]+$/u` (ASCII letters only). UK AV installs occasionally service French (e.g. "Régie") or other non-ASCII labelled rooms; the plan regex would 422-reject those at the form boundary — broken UX for valid use cases.
- **Fix:** Broadened to `/^[\p{L}\p{N} _\-]+$/u` (Unicode letter + Unicode number, same separator allowlist). Pitfall 8 XSS mitigation preserved — `<`, `>`, `/`, `script`, `&`, `'`, `"` still rejected. Defence-in-depth via Plan 02's `XtenAvLayoutEngine::xml()` `htmlspecialchars(ENT_XML1|ENT_QUOTES)` covers any residual structural concerns at emit time.
- **Files modified:** `app/Http/Controllers/ProjectPackageReviewController.php` (`validateEquipmentZones`), `resources/views/project-packages/review.blade.php` (free-text `pattern` attribute), `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` (added `test_review_form_persists_unicode_zone_label`)
- **Verification:** New test `test_review_form_persists_unicode_zone_label` asserts `Régie` round-trips through the form into JSON. XSS reject test still red on `<script>alert(1)</script>`.
- **Committed in:** `b307e07` (Task 1 GREEN), `e5bd170` (Blade `pattern` attribute updated to match)

---

**Total deviations:** 1 auto-fixed (1 missing critical / i18n correctness)
**Impact on plan:** Strict broadening of an allowlist — strengthens UX without weakening security. Validation rule remains stricter than HTML escaping alone (the renderer-side defence-in-depth layer). No scope creep — same one-column-on-the-review-form deliverable.

## Threat Model Verification

| Threat | Mitigation | Status |
|--------|------------|--------|
| T-23-06-A1 (XSS via free-text zone persisted into mxGraph value) | Server regex `/^[\p{L}\p{N} _\-]+$/u` rejects HTML/script chars; renderer-side `htmlspecialchars(ENT_XML1\|ENT_QUOTES)` escapes on emit | mitigated — `test_review_form_rejects_xss_payload_in_zone` green |
| T-23-06-A2 (form bypass posting arbitrary zone via curl/Postman) | Same server regex on `equipment.*.zone` — UX dropdown is hint only | mitigated — same test covers payload bypass |
| T-23-06-A3 (DoS via 200-char zone string) | `max:50` Laravel rule + HTML `maxlength="50"` attribute | mitigated — `test_review_form_rejects_zone_over_50_chars` green |
| T-23-06-A4 (cross-project tampering) | `authorizePackage()` middleware already on `update()` + `approve()` | inherited — no new code |

## Decision IDs Implemented

- **D-02** — Per-device zone override (write side). `equipment[N][zone]` persists into `extracted_data` JSON; Plan 02 ZoneGrouper reads it back per existing `Project::devicesWithStencils()` accessor.
- **D-03** — UI lands in Phase 23, not deferred to a separate phase. One additive column on the existing quote-review table.
- **D-04** — Free-text escape hatch with help text surfacing the tradeoff: *"Free text creates a separate group on the diagram — use the dropdown for consistency."*
- **D-09** — Generic naming. The field is `zone` (no `rams_zone` or `schematic_zone` prefix) so any consumer — current or future v2.0 module — can read it without coupling to a feature flag.

## Issues Encountered

- None. View cache cleared post-Blade-edit by the orchestrator (`php artisan view:clear`) to drop the stale compiled view that was preventing the new `<th>Zone</th>` from appearing.

## Self-Check: PASSED

- FOUND: `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php`
- FOUND: `app/Http/Controllers/ProjectPackageReviewController.php` (validateEquipmentZones + zone persist confirmed via `git show b307e07`)
- FOUND: `resources/views/project-packages/review.blade.php` (Zone column + zonePicker factory confirmed via `git show e5bd170`)
- FOUND: commit `820d6d1` — `test(23-06): RED - review form zone dropdown D-03 write side`
- FOUND: commit `b307e07` — `feat(23-06): controller persists equipment[N][zone] with validation`
- FOUND: commit `e5bd170` — `feat(23-06): zone dropdown column on review form`

## Next Phase Readiness

- Plan 23-07 (final phase verification) can now run the end-to-end smoke: pick a zone on the review form → save → open the generated mxGraph XML → confirm `<mxCell value="ZONE-NAME">` zone-container per Plan 02 ZoneGrouper.
- Phase 23 functionally complete pending 23-07 verification.
- No outstanding blockers.

---

## 🚨 Files to upload to live

Per `feedback_php_lint_before_push.md` the RAMS deploy path is `git push → SSH → cd /home/stcav/rams.21stcav.com → sudo -u stcav git pull` (NOT manual file upload — the older memory was superseded).

**Changed files this plan:**

- `resources/views/project-packages/review.blade.php`
- `app/Http/Controllers/ProjectPackageReviewController.php`
- `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` *(test file — required only if CI/QA runs server-side)*

**Deploy commands:**

```bash
# Local — push the feature branch
git push origin feat/worksheet-classifier-universal

# On live (ssh to the RAMS box, then):
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav php artisan view:clear
sudo -u stcav php artisan config:clear
```

`view:clear` is non-optional — Blade compiles the new Zone column + `window.__zoneVocab` script block, and a stale compiled view will hide the column entirely (this was the bug the orchestrator caught locally before this SUMMARY).

`config:clear` is recommended in case `config/drawings.php` `zone_vocab` was re-cached.

No DB migration. No queue restart. No `.env` change.

---
*Phase: 23-xten-av-style-renderer*
*Completed: 2026-05-14*

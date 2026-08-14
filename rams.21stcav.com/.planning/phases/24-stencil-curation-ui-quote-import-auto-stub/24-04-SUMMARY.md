---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 04
subsystem: drawings
tags: [laravel, mxgraph, drawio, svg, dom, phpunit]

# Dependency graph
requires:
  - phase: 24-01
    provides: AutoGenericStencilGenerator D-05 extension (provisional <connections> constraints + dashed/strokealpha rail styling), verified against vendored mxStencil.js grammar
  - phase: 24-03
    provides: DeviceStencilController class + /admin/device-stencils route group (this plan appends to both, in-place)
provides:
  - StencilXmlToSvgRenderer — bounded mxGraph <shape> stencil-XML to <svg> state-machine translator
  - admin.device-stencils.preview route + DeviceStencilController::preview() action
affects: [24-05, 24-06, 24-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "mxGraph stencil-XML state elements (dashed/strokealpha) tracked as SCOPED PARSER STATE across sibling elements during a DOM walk — same pattern AutoGenericStencilGenerator uses to EMIT them, now mirrored to CONSUME them, verified against the vendored engine (public/vendor/drawio/mxgraph/src/shape/mxStencil.js), never the seed pack"
    - "Output SVG built via a second DOMDocument (createElement/createTextNode/saveXML) rather than string concatenation — gets correct single-round-trip escaping for free (decode-on-read, encode-on-write), avoiding the double-escape bug class entirely"
    - "Preview endpoint regenerates through the SAME single generator (AutoGenericStencilGenerator) Save will use — no throwaway Project fixture synthesised just to satisfy DrawIoBuilderService's signature"

key-files:
  created:
    - app/Services/Drawings/StencilXmlToSvgRenderer.php
    - tests/Feature/Drawings/DeviceStencilPreviewTest.php
  modified:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - routes/web.php

key-decisions:
  - "Settled RESEARCH.md Open Question 3: preview uses AutoGenericStencilGenerator::build() ONLY, never DrawIoBuilderService — the edit screen curates one stencil's ports in isolation with no project/other-devices/cables context to synthesise a throwaway Project fixture for. This was the plan's own settled decision 1, carried through unchanged; no deviation from it was needed during execution."
  - "Settled RESEARCH.md Open Question 1 (D-16, locked in CONTEXT.md): the preview endpoint returns rendered SVG, not mxGraph XML to a client-side draw.io embed. No such server-side XML-to-SVG renderer existed anywhere in this codebase before this plan (DrawIoSpikeController::exportSvg only persists a client-submitted SVG string — zero server-side rendering); StencilXmlToSvgRenderer is the purpose-built, zero-new-package answer."
  - "dashed/strokealpha are consumed as parser STATE (mirroring exactly how AutoGenericStencilGenerator emits them as state), never read as attributes on <line> itself — carried through from Plan 24-01's hand-corrected grammar without modification, per the plan's explicit instruction not to re-derive it."
  - "strokealpha out-of-range values (e.g. a percentage-scale 60) are clamped back to fully opaque (1.0) rather than propagated into stroke-opacity/fill-opacity output — regression guard against the exact defect (0-100 vs 0-1 scale confusion) that took three review cycles to correct in Plan 24-01's emitter."
  - "roundrect always translates fill+stroke, bare rect always translates fill-only — matches the plan's literal per-element-type translation table exactly, rather than building a general commit-tracking renderer that looks ahead to <fill/>/<fillstroke/>/<stroke/> markers. Those three markers are no-ops in this renderer; the primitive already emitted itself using current state at the point it was encountered, which is provably equivalent given AutoGenericStencilGenerator never changes fillcolor/strokecolor between a shape primitive and its commit marker."
  - "mapAlign() accepts BOTH the true mxGraph vocabulary (left/center/right) AND the SVG-native vocabulary AutoGenericStencilGenerator's own port-label geometry actually emits (start/center/end, see sideGeometry()) — the plan's action text specified only the left/center/right mapping, but the real generator output never contains 'left'/'right' literally, so a pass-through for start/end was added to keep the round-trip-against-real-output acceptance criterion honest. This is the only addition beyond the plan's literal action text, and it is purely additive (no change to the specified center->middle/left->start/right->end formula)."
  - "Inline duplicated validation rule set in preview(), with a TODO(24-05) comment, per the plan's explicit instruction — Plan 24-05's UpdateDeviceStencilPortsRequest will later extract this into a shared FormRequest."

patterns-established:
  - "Building output XML/SVG through a second DOMDocument instance (rather than sprintf/string concatenation) is the correct way to consume an already-escaped upstream XML value without double-escaping it: DOMElement::getAttribute() decodes once on read, DOMDocument::createTextNode()+saveXML() encodes once on write."

requirements-completed: []

# Metrics
duration: ~50min
completed: 2026-08-14
---

# Phase 24 Plan 04: Server-Rendered Stencil Preview Pipeline Summary

**StencilXmlToSvgRenderer (bounded mxGraph-grammar state-machine to SVG) + `admin.device-stencils.preview` endpoint — settles D-16 and Research Open Questions 1 and 3, carrying Plan 24-01's hand-corrected dashed/strokealpha grammar through into the CONSUMING side.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-14 (see git commit timestamps)
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments

- `StencilXmlToSvgRenderer` translates AutoGenericStencilGenerator's FULL bounded mxGraph grammar (roundrect/rect/line/text/fillcolor/strokecolor/fontcolor/fontsize/fontstyle/strokewidth/dashed/strokealpha/fill/fillstroke/stroke/connections) into equivalent `<svg>` primitives via a proper state machine — `dashed` and `alpha` tracked as scoped parser state across sibling elements, exactly mirroring how the real vendored `mxStencil.js` engine consumes them, and exactly how `AutoGenericStencilGenerator` (Plan 24-01) already emits them.
- Grammar is verified directly against `public/vendor/drawio/mxgraph/src/shape/mxStencil.js` (not the seed pack, which has zero `dashed`/`strokealpha` precedent): the `dashed` attribute is read off `<dashed>` (never `value`), and `alpha` is read off `<strokealpha>` as a 0.0-1.0 fraction applied GLOBALLY — muting `<text>` fill-opacity as well as `<line>` stroke-opacity, with out-of-range (percentage-scale) values clamped back to fully opaque rather than propagated into SVG output.
- `DeviceStencilController::preview()` + `admin.device-stencils.preview` route regenerate `mxgraph_xml` for an UNSAVED posted `ports` array through `AutoGenericStencilGenerator` (settled: never `DrawIoBuilderService`), pipe it through the renderer, and return rendered SVG with `Content-Type: image/svg+xml` — persisting nothing on `device_stencils`/`device_ports` across any number of repeated calls.
- 13 feature tests, including a round-trip smoke test that renders `AutoGenericStencilGenerator::build()`'s REAL output (not a hand-authored fixture) for both the zero-port and 4-port templated cases, and a grep-verifiable guard proving the renderer never reads `dashed`/`alpha` off a `<line>` DOM node directly.

## Task Commits

Each task was committed atomically:

1. **Task 1: StencilXmlToSvgRenderer service** - `da02128` (feat)
2. **Task 2: DeviceStencilController::preview() action + route** - `e42888e` (feat)

_No separate test/refactor commits — Task 1 was `tdd="true"` in name only per the plan's own gate; tests and implementation were written and verified together against the plan's literal grammar spec, then committed as one atomic unit per this project's established Phase 24 pattern (see 24-01-SUMMARY.md: "each task commit bundles its implementation + tests together")._

## Files Created/Modified

- `app/Services/Drawings/StencilXmlToSvgRenderer.php` - Bounded-grammar mxGraph `<shape>` stencil-XML to `<svg>` translator. Public `render(string $shapeXml, int $width, int $height): string`. State machine tracks fillcolor/strokecolor/fontcolor/fontsize/fontstyle/strokewidth/dashed/alpha; `<connections>` skipped entirely (invisible markers).
- `tests/Feature/Drawings/DeviceStencilPreviewTest.php` - 13 tests: 9 for the renderer (zero-port placeholder, dashed+muted rail lines, state reset after `<dashed dashed="0"/>`, global alpha on text, percentage-scale alpha rejection, well-formed-XML output, no-double-escape, real-generator-output round-trip for both zero-port and templated cases, grep-verifiable no-line-attribute guard) + 4 for the controller/route (200 + `image/svg+xml`, persist-nothing across 5 repeated calls, 422-never-500 on invalid `ports`, SVG rail-line count matches posted port count).
- `app/Http/Controllers/Admin/DeviceStencilController.php` - Adds `preview()` action: validates posted `ports` inline (TODO note for Plan 24-05's shared FormRequest), builds via `AutoGenericStencilGenerator`, renders via `StencilXmlToSvgRenderer`, returns raw SVG response. `index()` untouched.
- `routes/web.php` - Adds `Route::post('/admin/device-stencils/{deviceStencil}/preview', ...)->name('admin.device-stencils.preview')` inside the existing admin route group, immediately after the index route.

## Decisions Made

- **`AutoGenericStencilGenerator` only, never `DrawIoBuilderService`** — the plan's own settled decision (Research Open Question 3), executed unchanged. The edit screen's scope never exceeds a single `<shape>`; synthesising a throwaway single-device `Project` fixture just to satisfy `DrawIoBuilderService::build(Project $project)`'s signature would be unjustified complexity.
- **Output SVG built via a second `DOMDocument`, not string concatenation** — `DOMElement::getAttribute()` on the parsed input DOM-decodes an already-escaped `str="&lt;script&gt;..."` value back to its literal form exactly once; `createTextNode()` + `saveXML()` on the OUTPUT document re-encodes it exactly once more for the new SVG document. This is a single safe round-trip, never a double-escape, and structurally impossible to get wrong the way a hand-written `sprintf('<text>%s</text>', $str)` could.
- **`roundrect`/`rect` translate fill+stroke / fill-only unconditionally**, per the plan's literal per-element-type table, rather than building a general "look ahead to the next commit marker" renderer. `<fill/>`/`<fillstroke/>`/`<stroke/>` are treated as no-ops — the primitive already emitted itself using current state at the point it was encountered. This is provably equivalent for this bounded grammar because `AutoGenericStencilGenerator` never changes `fillcolor`/`strokecolor` between a shape primitive and its own commit marker.
- **`strokealpha` out-of-range values are clamped to `1.0` (fully opaque), not propagated** — the exact regression guard the plan's Behaviour Test 5 calls for: a percentage-scale `alpha="60"` never reaches SVG output as `stroke-opacity="60"` (which real browsers would clamp to fully opaque anyway, silently losing the intended mute — the original three-review-cycle defect this plan's grammar correction exists to prevent).
- **`mapAlign()` additively accepts SVG-native `start`/`end` alongside the plan's specified `left`/`center`/`right` mapping** — the only deviation from the plan's literal action text (Rule 1/2 territory: without it, the round-trip-against-real-generator-output acceptance criterion would silently mis-render every port label, since `AutoGenericStencilGenerator::sideGeometry()` emits `align="start"`/`"end"` directly for left/right ports, never the literal strings `"left"`/`"right"`). The plan's specified `center->middle`/`left->start`/`right->end` formula is preserved unchanged; `start`/`end` simply pass through as already-valid SVG keywords.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `mapAlign()` needed to accept `start`/`end` in addition to the plan's specified `left`/`right`**
- **Found during:** Task 1, writing the round-trip smoke test against `AutoGenericStencilGenerator`'s real output.
- **Issue:** The plan's action text specifies the translation `center->middle, left->start, right->end`, implying the real mxGraph `align` vocabulary (`left`/`center`/`right`). But `AutoGenericStencilGenerator::sideGeometry()` (Plan 24-01, already shipped and locked) emits SVG-native `start`/`center`/`end` directly for port-label `align` values — it never emits the literal strings `left`/`right`. A renderer that only recognised `left`/`right` would silently fall through to a default for every real port label.
- **Fix:** `mapAlign()` maps `center->middle` (per plan), `left->start`/`right->end` (per plan, defensive/future-proofing for any other bounded-grammar caller), AND passes `start`/`end` through unchanged (matching what the real generator actually emits today).
- **Files modified:** `app/Services/Drawings/StencilXmlToSvgRenderer.php`
- **Commit:** `da02128`

No other deviations — every other element of the plan's grammar section (Task 1's hand-corrected, orchestrator-verified `dashed`/`strokealpha` rules) was implemented exactly as specified, with no re-derivation.

## Issues Encountered

None beyond the `mapAlign()` addition above — both tasks proceeded without blockers. The Task 1 test suite caught the `align` vocabulary mismatch immediately via the round-trip-against-real-output test, before it could reach the controller layer.

## User Setup Required

None — no external service configuration required. No migration in this plan.

## Next Phase Readiness

- Plan 24-05 (curation UI edit/save) can now wire its port-editing form's debounced preview calls (600ms pattern, `resources/views/surveys/show.blade.php:1761-1799`) directly against `admin.device-stencils.preview` — the endpoint contract (SVG in, `ports` array out, persists nothing) is proven and tested.
- Plan 24-05's `UpdateDeviceStencilPortsRequest` should extract the inline validation rule set duplicated in `DeviceStencilController::preview()` into a shared FormRequest, then update `preview()` to type-hint it (see the `TODO(24-05)` comment left in the controller).
- `StencilXmlToSvgRenderer` is generic enough to be reused unchanged by any future preview surface that needs to render this same bounded grammar (e.g. a future promotion-review screen) — no dependency on `DeviceStencil`/`DeviceStencilController` beyond its public `render(string, int, int): string` signature.
- DRAW-51 remains split across 24-01/24-04/24-05 and is NOT marked complete by this plan — Plan 24-05 (Save) still needs to land before the full requirement closes.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Services/Drawings/StencilXmlToSvgRenderer.php`
- `app/Http/Controllers/Admin/DeviceStencilController.php`
- `routes/web.php`

No migration in this plan — no `php artisan migrate` step required for these files specifically. However, Plan 24-01's migration (`needs_review`/`logo_path`/`device_stencil_audits`) **remains a live-deploy prerequisite** for the wider Phase 24 surface per that plan's SUMMARY — this plan's `preview()` action reads `manufacturer`/`model`/`display_name`/`part_number` off `DeviceStencil` only, none of which depend on that migration, so this specific endpoint is safe to deploy independently of it. Confirm Plan 24-01's migration has already run on live before deploying Plan 24-05 (which will need the full column set for Save).

Test files (`tests/Feature/Drawings/DeviceStencilPreviewTest.php`) are not required on live — they exist for the local/CI test suite only.

## Self-Check: PASSED

All 4 `key-files` (2 created, 2 modified) verified present on disk. Both task commit hashes (`da02128`, `e42888e`) verified present in `git log`. `php artisan test --filter=DeviceStencilPreviewTest` — 13/13 passed. Broader `tests/Feature/Drawings` regression run — 215 passed, 2 failed (both the pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md`, predating Phase 24, out of scope), 2 skipped.

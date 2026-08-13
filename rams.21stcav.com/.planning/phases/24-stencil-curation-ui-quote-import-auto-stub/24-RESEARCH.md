# Phase 24: Stencil Curation UI + Quote-Import Auto-Stub - Research

**Researched:** 2026-08-13
**Domain:** Laravel admin CRUD + mxGraph/draw.io custom-shape XML + Alpine.js reactive repeaters + deterministic keyword classification
**Confidence:** HIGH — every claim below is grounded in code actually read in this repo (file:line cited), not training-data guesses about mxGraph or Laravel in general.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 — Hybrid editor: port table is the source of truth, live preview confirms.** Editable table (label, side, connector_type, signal_type, direction, sort_order, port_id, x_pct/y_pct) beside a read-only preview pane. Explicitly NOT full drag-on-canvas — `device_ports` stores position as two nullable decimals and Phase 23's renderer computes positions when null, so dragging is a convenience over two numbers, not the only way to express intent. No admin screen in this codebase does drag interaction today.

**D-02 — Preview renders server-side through the REAL builder.** Debounced POST to an admin preview route runs the same service that produces production stencils (`AutoGenericStencilGenerator` for stub shapes / `DrawIoBuilderService` for full render) against the unsaved port set, returning SVG. Explicitly NOT a client-side JS redraw — a second implementation would drift and silently teach engineers the wrong thing about their own curation.

**D-03 — Curation audit trail in a dedicated `device_stencil_audits` table.** Columns: `device_stencil_id`, `user_id`, `action` (`promote`/`edit`/`discard-regenerate`), before/after port snapshot (json), `created_at`. Generic-named per 21 D-09 (SCC-merge readiness). NOT `ProjectActivityLog` (project-scoped; stencils are deliberately global). NOT `metadata` json alone (only holds the last edit, can't answer "who curated what, when" across ~100 stencils).

**D-04 — "Promote to engineer-curated" hard-gates structure, soft-warns quality.** BLOCK on: zero ports; any port missing `label`/`connector_type`/`signal_type`/`direction`; duplicate `port_id` within the stencil. WARN but allow on: no manufacturer logo; `signal_type = unclassified`; missing `x_pct`/`y_pct`. This diverges from Phase 22's "warn, never hard block" precedent — promotion removes the stencil from the review queue AND starts propagating to every project using that part_number, so a zero-port promotion actively hides the gap it was created to expose.

**D-05 — Auto-stub emits PROVISIONAL port rails + mxGraph constraints.** When a category template seeds N ports, the generated `mxgraph_xml` includes rails and mxGraph constraints for them, styled distinctly (dashed/muted) to mark them template-derived rather than verified. This SUPERSEDES 21 D-04's "no port rails" for the stub-with-template-ports case (21 D-04's bare no-ports placeholder remains for zero-port stubs only). Rationale: `port_id` is the mxGraph constraint name used for cable termination in Phase 23 — ports with no constraint in the XML are invisible to Phase 23's port-to-port cable router. Planner note on criterion 6: read "Tier 2 devices continue to render with the placeholder" as no-regression/no-crash for uncatalogued devices, not as a prohibition on provisional rails for templated ones.

**D-06 — Template vocabulary lives in `config/drawings.php` under a new `port_templates` key.** Sits beside `signal_colours`/`zone_vocab`/`category_to_zone`. Version-controlled so criterion 2's determinism is enforced by git, not trusted DB state. Explicitly NOT a DB table. Device-type vocabulary is NEW — do NOT reuse `EquipmentCategoryClassifier`'s 7 commercial-axis values, and do NOT reuse `Device::ROLE_*` (too coarse). Reuse the MECHANISM (priority-ordered decision tree, deterministic keyword→role inference), not the vocabulary, from `EquipmentCategoryClassifier` and `DrawingDataResolverService` lines 444-464.

**D-07 — Multi-keyword conflicts resolve via an explicit precedence list; anything unenumerated → zero-port stub.** Known compound conflicts enumerated in config with a declared winner (e.g. `bracket` beats `display`, `mount` beats `screen`, `cable` beats everything) so "Samsung 65\" Display Bracket" deterministically resolves to `bracket`. ANY multi-match not covered by an explicit rule produces a zero-port stub flagged `needs_review`. Resolution signals limited to `part_number` prefix and a fixed description-keyword allowlist. No AI, ever, in this path.

**D-08 — Template changes never re-apply silently; opt-in artisan command.** New command `stencils:reapply-templates`, dry-run by default with `--commit`, mirroring `PackagesReclassifyEquipmentCommand` and `BackfillCablePortFksCommand`. Re-templates ONLY stencils that are still `source = auto-generated` AND have no rows in `device_stencil_audits` — so it can never touch anything an engineer has edited or promoted.

**D-13 — DRAW-54 belongs to Phase 25, not Phase 24.** ROADMAP's mapping of DRAW-54 to plan 24-03 is a scope-correction target for the roadmap handler, not something to hand-edit. Phase 24's requirement set is DRAW-50, DRAW-51, DRAW-52, DRAW-53 only. Plan 24-03 (bounded Tier 1 fill) has no requirement ID.

**D-14 — Admin route is `/admin/device-stencils`.** DRAW-50 wins over the goal text's `/admin/stencils` — matches the established convention (`admin/devices`, `admin/device-cable-rules`). Route names: `admin.device-stencils.index` / `.edit` / `.update` / `.promote` / `.preview`. Sits inside the existing `Route::middleware('admin')->group()` block in `routes/web.php` (~line 251).

### Claude's Discretion

The user delegated these four areas. Decisions recorded here are binding on the planner unless the user revisits them.

**D-09 — Auto-stub hooks all THREE import paths via one shared service.** New `QuoteImportStencilStubber` service, invoked from `app/Jobs/ExtractQuoteJob.php` (PDF path), `app/Core/Modules/QuoteImport/QuoteWerksImportService::buildExtractedData` (QuoteWerks direct import, now the DEFAULT import route), and `app/Jobs/ReimportQuoteJob.php` (re-import path). Each call site gets its own feature test — no shared choke point downstream because each path persists `ProjectPackage` separately. This CORRECTS a ROADMAP defect: ROADMAP 24-01 named only `ExtractQuoteJob`, which since 260725-qw4 is the fallback path, not the default. `Project::devicesWithStencils()`'s existing lazy bare-stub creation (21 D-07) is NOT replaced — Phase 24 moves stub creation earlier (import time) and richer (template ports).

**D-10 — `needs_review` is a real indexed boolean column, not a metadata json flag.** Migration adds `needs_review` (boolean, default false, indexed) to `device_stencils`, and carries existing `metadata.needs_phase_24_curation = true` values (written by Plan 21-02) into the new column in the same migration. MariaDB cannot index a json extract, so a `metadata`-based filter would table-scan every stencil on every list load.

**D-11 — The 92 existing zero-port stubs need no separate backfill mechanism.** They are all `source = auto-generated` with no audit rows, so they already qualify under D-08's re-apply rule. Do not build a second one-shot backfill command.

**D-12 — Logo upload MUST route through `SvgSanitizerService`.** DRAW-52 accepts PNG/SVG. Every uploaded SVG passes through `app/Services/Drawings/SvgSanitizerService.php` before persist — no exceptions, no new sanitiser.

### Deferred Ideas (OUT OF SCOPE)

- **AI port extraction from datasheets** — Phase 25 (DRAW-54, `DevicePortExtractorService`). Phase 24 stays deterministic; the `ai-extracted` source value is reserved but never written.
- **Full drag-on-canvas port positioning** — rejected for this phase (D-01), not for all time. If engineers find numeric `x_pct`/`y_pct` entry painful during the top-10 fill, revisit as a follow-up; the table stays the source of truth either way.
- **Bulk / keyboard affordances for working the review queue fast** — raised as a candidate, not discussed.
- **`stencils:coverage-report` fixture provenance** — the "top-10 by quote volume" input for criterion 5 was flagged but not discussed. Phase 21's D-15 independence rule (do not derive the reference list from the seed pack itself) applies here by analogy. If it needs a decision, raise it during `/gsd-plan-phase`.
- **Whether plan 24-03 belongs in this phase at all** — raised as a candidate, not discussed. Left in scope as ROADMAP has it, bounded to top-10 by criterion 5.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DRAW-50 | Admin route `/admin/device-stencils` — list view with filter by source (auto-generated / curated / ai-extracted) and search by part_number | Pattern 3 + `admin/devices/index.blade.php:112-130` filter-row precedent (Standard Stack / Architecture Patterns); route registration + preview-route-ordering pitfall (Pitfall 4); route group location confirmed at `routes/web.php:251-303` |
| DRAW-51 | Stencil edit screen — open the auto-generic placeholder in an editor, edit ports (per D-01: table not drag), label them, save — with regenerated `mxgraph_xml` carrying constraints per D-05 | Pattern 1 (mxGraph constraint emission, verified against live seed data) + Pattern 3 (Alpine reactive port-table repeater) + Pitfall 2 (port_id/constraint-name drift) + Pitfall 3 (determinism contract) |
| DRAW-52 | Manufacturer logo upload (PNG/SVG) per stencil — stored alongside the stencil's `mxgraph_xml` | Don't Hand-Roll (`SvgSanitizerService`, `ManufacturerLogoResolver`) + Open Question 2 (schema gap: `logo_svg` is SVG-text-only, no PNG-file-path column exists — must be resolved during planning) + Code Examples (`DeviceLabelPhotoService` PNG storage precedent) |
| DRAW-53 | "Promote to curated" action flips `source` enum, cross-project propagation automatic via cache lookup | Architectural Responsibility Map (promotion validation row) + Security Domain (server-side D-04 re-validation) + Don't Hand-Roll (`firstOrCreate` cache contract, zero new propagation code needed) |
</phase_requirements>

## Summary

Phase 24 is two features glued together by the existing `DeviceStencilCacheService::firstOrCreate` contract (21 D-03): a deterministic import-time stub generator, and an admin curation screen that promotes stubs to real stencils. Neither feature needs new architecture — both extend code that already exists and was explicitly left as a seam for this phase (21 D-01 "Phase 21 lays the data layer for Tier 2").

The single highest-risk unknown named in the task brief — mxGraph named-constraint syntax — is **not actually unknown**. It already ships in production: `resources/data/device-stencils-seed/neat-bar-pro.json` contains a real `<connections><constraint x="0" y="0.2" perimeter="0" name="hdmi-in"/>…</connections>` block, and `CableRouter::stencilHasConstraints()` (`app/Services/Drawings/CableRouter.php:271-279`) already detects and consumes it via `str_contains($xml, '<constraint')`. `AutoGenericStencilGenerator::emitShape()` just needs to grow the same block the curated stencils already carry. This de-risks D-05 substantially — it is "copy an existing pattern into the placeholder generator," not "invent new mxGraph syntax."

The second-highest risk — the live preview (D-02) — also has a directly transferable precedent: `resources/views/surveys/show.blade.php:1761-1769`'s `debouncedAutosave()` (600ms `setTimeout`, `X-CSRF-TOKEN` meta-tag header, `fetch()` POST) is the exact debounce/CSRF pattern the UI-SPEC already cites. The one gap is AbortController-based in-flight cancellation, which has no local precedent (the ⌘K palette does it per project memory but that file wasn't read here — flagged as LOW confidence, verify before relying on it as a copy source).

For the Alpine repeatable-row table (research question 3), the CONTEXT.md-suggested precedent (`project-packages/review.blade.php`'s `equipmentSection()`) is a **poor fit** — it is DOM-query-first (soft-delete/restore toggle hidden `<tr>` elements submitted via native form POST), not a reactive JS array. A better, directly-transferable precedent exists at `resources/views/components/survey/repeater-equipment.blade.php`: `x-for="(item, idx) in array" :key="idx"` with `x-model="item.field"` bindings and parent-scoped `addEquipment()`/`removeEquipment(idx)` methods. This is reactive-state-first, which is what D-02's live preview needs (the debounced POST body must read current in-memory port state, not scrape the DOM).

One real gap surfaced that CONTEXT.md/UI-SPEC did not anticipate: **the `device_stencils` schema has no column for an uploaded PNG file path.** `logo_svg` (`database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php:53`) is a `longText` column for *inline SVG markup only*. DRAW-52 requires PNG/SVG upload. The planner must decide between (a) adding a `logo_path` varchar column pointing at a `Storage::disk('public')` file (matches the `DeviceLabelPhotoService` precedent), or (b) rejecting PNG uploads at validation time and requiring SVG-only despite DRAW-52's wording. This is flagged as an open question below — CONTEXT.md D-12 assumed `SvgSanitizerService` covers upload safety but didn't address the storage-shape gap for the PNG half of "PNG/SVG."

**Primary recommendation:** Extend `AutoGenericStencilGenerator::emitShape()` to emit a `<connections>` block (copying the neat-bar-pro.json syntax) when hints carry a resolved port template; add a `logo_path` column via migration alongside `needs_review`; build the port-table editor as an Alpine `x-for` reactive array (repeater-equipment.blade.php pattern, not review.blade.php's DOM-toggle pattern); wire the debounced preview POST on the `600ms` `debouncedAutosave()` pattern from surveys/show.blade.php.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Category → port template resolution (deterministic classifier) | API/Backend (Laravel service) | — | Pure PHP decision tree, config-driven, no I/O — mirrors `EquipmentCategoryClassifier` (`app/Services/Imports/EquipmentCategoryClassifier.php`) |
| Auto-stub creation at import time | API/Backend (Laravel job/service) | Database | `QuoteImportStencilStubber` calls `DeviceStencilCacheService::resolveForPartNumber()`, which writes `device_stencils`/`device_ports` rows |
| mxGraph XML generation (provisional rails) | API/Backend (Laravel service) | — | `AutoGenericStencilGenerator` — pure string templating, no I/O, must stay deterministic (byte-identical output per hints) |
| Admin list/filter UI | Frontend Server (Blade, server-rendered) | Database | Plain GET + `Apply`/`Clear` form per `admin/devices/index.blade.php` precedent — no client-side filtering |
| Port-table editor (reactive add/edit/delete rows) | Browser/Client (Alpine.js) | — | In-memory reactive array; no server round-trip until Save/Promote — `repeater-equipment.blade.php` pattern |
| Live preview render | API/Backend (Laravel service, SAME renderer as production) | Browser/Client (debounce + fetch) | D-02 mandates server-side render through the real builder — client only debounces + POSTs + swaps SVG |
| Logo upload + sanitisation | API/Backend (Laravel controller + `SvgSanitizerService`) | Database/Storage | SVG parsed server-side before persist; PNG needs file storage (gap — see Summary) |
| Promotion validation (hard/soft gate) | API/Backend (Laravel FormRequest/service) | — | D-04's block/warn rules must be enforced server-side (client mirrors for UX, never trusted alone) |
| Cross-project propagation | Database (cache lookup) | — | Zero new code — `firstOrCreate(part_number)` already delivers this per 21 D-03; criterion 4 is a test, not an implementation task |

## Standard Stack

### Core
No new package installs. This phase extends the existing Laravel 12 + Blade + Alpine.js + mxGraph/draw.io stack already proven in Phases 21–23. No `npm install` / `composer require` line is needed.

### Supporting
| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| `DOMDocument` (PHP ext-dom, bundled) | PHP 8.4 built-in | SVG sanitisation via `SvgSanitizerService` | Already the project's mandated sanitiser (D-12); no alternative permitted |
| Alpine.js | Already loaded via `resources/js/app.js` | Reactive port-table state + debounced preview fetch | Project convention — no Vue/React per CLAUDE.md constraint |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Server-side preview render (D-02) | Client-side JS mxGraph redraw | Rejected explicitly in CONTEXT.md D-02 — would drift from the PHP builder and "teach engineers the wrong thing" |
| `logo_path` file-storage column for PNG | Base64-embed PNG into `logo_svg` as a data URI | Rejected here — corrupts the column's documented "inline SVG" semantics (21 D-02) and complicates the `ManufacturerLogoResolver` fallback contract; a dedicated `logo_path` column is cleaner and matches the `DeviceLabelPhotoService` precedent |

**Installation:** None required.

**Version verification:** Not applicable — no new packages.

## Package Legitimacy Audit

Not applicable — this phase installs zero external packages (no `npm install`, no `composer require`). Skipping the slopcheck gate; nothing to audit.

## Architecture Patterns

### System Architecture Diagram

```
Quote import (3 entry points)                    Admin curation UI
┌─────────────────────┐                          ┌──────────────────────────┐
│ ExtractQuoteJob      │                          │ GET /admin/device-stencils│
│ (PDF path)           │                          │  → filtered list         │
├─────────────────────┤                          └──────────┬───────────────┘
│ QuoteWerksImport      │   each hardware-category            │ click Edit
│ Service::             │   part_number, in the                ▼
│ buildExtractedData    ├──equipment array──────►┌──────────────────────────┐
├─────────────────────┤                          │ GET /admin/device-stencils│
│ ReimportQuoteJob      │                          │      /{id}/edit          │
│ (re-import path)      │                          │  → port table (Alpine)   │
└──────────┬───────────┘                          │  → preview pane (SVG)    │
           │                                       └──────┬───────────┬──────┘
           ▼                                              │           │
┌─────────────────────────┐                    port table │  POST     │ POST
│ QuoteImportStencilStubber│                    changes    │ /preview  │ /promote
│ (NEW — Phase 24)         │                     (debounce │  or       │  or
├─────────────────────────┤                      600ms)    │ /update   │ /discard
│ 1. CategoryPortTemplate  │                                ▼           ▼
│    Resolver (deterministic,                    ┌──────────────────────────┐
│    config-driven, D-06/D-07)                    │ AutoGenericStencilGenerator│
│ 2. DeviceStencilCacheService                    │  ::build() / ::emitShape()│
│    ::resolveForPartNumber()                     │  (same renderer prod uses)│
│    (21 D-03 — existing)                         └──────────┬───────────────┘
└──────────┬───────────────┘                                │
           │ firstOrCreate                                   │ SVG / mxgraph_xml
           ▼                                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ device_stencils (source=auto-generated, needs_review=true)              │
│ device_ports (provisional rails + <constraint name="port_id"> — D-05)   │
│ device_stencil_audits (NEW — promote/edit/discard trail, D-03)          │
└──────────────────────────┬────────────────────────────────────────────┘
                            │ cache lookup on next render (ANY project)
                            ▼
                  Phase 23 DrawIoBuilderService
                  → CableRouter::stencilHasConstraints()
                  → exitPortId/entryPortId cable termination
```

### Recommended Project Structure
```
app/
├── Console/Commands/
│   └── StencilsReapplyTemplatesCommand.php   # D-08, dry-run + --commit
├── Http/Controllers/Admin/
│   └── DeviceStencilController.php            # index/edit/update/promote/preview/discard
├── Http/Requests/
│   ├── UpdateDeviceStencilPortsRequest.php     # port-table batched save
│   └── UploadDeviceStencilLogoRequest.php      # PNG/SVG upload validation
├── Services/Drawings/
│   ├── AutoGenericStencilGenerator.php         # EXTEND — emit <connections> (D-05)
│   ├── CategoryPortTemplateResolver.php        # NEW — D-06/D-07 deterministic classifier
│   └── StencilPromotionValidator.php           # NEW — D-04 hard/soft gate
├── Services/QuoteImport/
│   └── QuoteImportStencilStubber.php           # NEW — 3 call sites (D-09)
└── Models/
    └── DeviceStencilAudit.php                  # NEW — D-03

database/migrations/
└── 2026_08_XX_XXXXXX_add_needs_review_and_logo_path_to_device_stencils_and_create_audits.php

config/drawings.php                             # EXTEND — new 'port_templates' key (D-06)

resources/views/admin/device-stencils/
├── index.blade.php
├── edit.blade.php
└── _port-table.blade.php                       # Alpine x-for repeater (see Pattern 3)

tests/Feature/Drawings/
├── QuoteImportStencilStubberTest.php            # one test class, 3 call-site scenarios (D-09)
├── DeviceStencilPromotionTest.php               # D-04 hard/soft gate
├── DeviceStencilPreviewTest.php                 # D-02 preview endpoint
└── StencilsReapplyTemplatesCommandTest.php      # D-08 dry-run/--commit
```

### Pattern 1: mxGraph named-constraint emission (D-05)

**What:** A `<shape>` document can declare named connection points via a `<connections>` block placed immediately after the opening `<shape>` tag and before `<background>`. Each `<constraint>` has `x`/`y` in the 0..1 range relative to the shape's own w/h, `perimeter="0"` (fixed point, not perimeter-following), and `name="{port_id}"` — this name is what `CableRouter::portToPortStyle()` (`app/Services/Drawings/CableRouter.php:228-235`) references via `exitPortId=...;entryPortId=...;` on the edge's style string.

**When to use:** Whenever `AutoGenericStencilGenerator::build()` receives hints that resolved to a non-empty port template (D-05/D-07). Zero-port stubs (ambiguous category, or genuinely portless items) keep the current no-`<connections>` placeholder unchanged (21 D-04 still applies to that case).

**Coordinate mapping to `device_ports` columns:**
- `side=left` → `x="0"`, `y="{y_pct}"`
- `side=right` → `x="1"`, `y="{y_pct}"`
- `side=top` → `x="{x_pct}"`, `y="0"`
- `side=bottom` → `x="{x_pct}"`, `y="1"`

**Example (verified from live seed data, not invented):**
```json
// Source: resources/data/device-stencils-seed/neat-bar-pro.json:17 (mxgraph_xml field, reformatted)
"<shape name=\"21cav.neat-bar-pro\" h=\"160\" w=\"240\" aspect=\"variable\" strokewidth=\"inherit\">
  <connections>
    <constraint x=\"0\" y=\"0.2\"  perimeter=\"0\" name=\"hdmi-in\"/>
    <constraint x=\"0\" y=\"0.45\" perimeter=\"0\" name=\"usb-c\"/>
    <constraint x=\"0\" y=\"0.85\" perimeter=\"0\" name=\"power\"/>
    <constraint x=\"1\" y=\"0.2\"  perimeter=\"0\" name=\"hdmi-out\"/>
    <constraint x=\"1\" y=\"0.45\" perimeter=\"0\" name=\"lan\"/>
    <constraint x=\"1\" y=\"0.7\"  perimeter=\"0\" name=\"audio-out\"/>
  </connections>
  <background>...</background>
  <foreground>...</foreground>
</shape>"
```

**Consumer contract (already live, not new code):**
```php
// Source: app/Services/Drawings/CableRouter.php:271-279
private function stencilHasConstraints(?object $stencil): bool
{
    if ($stencil === null) {
        return false;
    }
    $xml = (string) ($stencil->mxgraph_xml ?? '');
    return $xml !== '' && str_contains($xml, '<constraint');
}
```
This is a **substring check** — it does not parse XML or validate that constraint `name` values match `device_ports.port_id` values. `AutoGenericStencilGenerator` MUST keep the two in sync (every port row's `port_id` must have a matching `<constraint name="...">`) or `CableRouter` will silently attempt `exitPortId`/`entryPortId` on a constraint name that doesn't exist in the shape, and draw.io will fail to resolve the fixed point at render time (undefined behaviour, not a PHP-side error — the XML is well-formed, so no exception is thrown; the cable just doesn't terminate visibly). Recommend a feature test asserting port_id ↔ constraint-name parity for every generated stub.

**Provisional styling (UI-SPEC's dashed/muted requirement):** the `<connections>` block itself carries no visual styling — connection points are invisible markers, not rendered rails. The *visible* dashed/muted "provisional rail" glyphs (a short `<line>` + connector-type label per port, per the neat-bar-pro.json pattern's `<line x1="0" y1="60" x2="8" y2="60"/>` port-tick marks in `<foreground>`) are a SEPARATE emission the generator must add alongside the constraints — one drives cable termination (invisible), the other drives the "engineer knows this needs curating" visual signal (visible, dashed, muted per UI-SPEC point 5). Do not conflate the two; both are needed to satisfy D-05.

### Pattern 2: Debounced server-rendered preview (D-02)

**What:** POST current (unsaved) port-table state as JSON to an admin preview route; the controller builds a **transient** `DeviceStencil`-shaped array (never persisted), runs it through `AutoGenericStencilGenerator` (or the full `DrawIoBuilderService` single-device path if the UI-SPEC's "same renderer as production" requirement extends to full-canvas preview — CONTEXT.md D-02 says "AutoGenericStencilGenerator for stub shapes / DrawIoBuilderService for full render", implying the preview may need to pick between the two depending on whether the stencil is being edited as a stub vs a promoted stencil); the route returns raw SVG (or the XML for the client to hand to a draw.io read-only embed — clarify which during planning, see Open Questions).

**When to use:** Any port-table field change (add/delete/reorder rows, edit label/side/connector_type/signal_type/direction/x_pct/y_pct).

**Debounce + CSRF pattern (directly transferable, 600ms confirmed by UI-SPEC as the deliberate choice):**
```js
// Source: resources/views/surveys/show.blade.php:1761-1769 (debouncedAutosave)
debouncedAutosave() {
    if (this._autosaveTimer) clearTimeout(this._autosaveTimer);
    this._autosaveTimer = setTimeout(() => {
        if (this.screen === 'step' && this.currentRoom && !this.readonly) {
            this.autosave();
        }
    }, 600);
},
```
```js
// Source: resources/views/surveys/show.blade.php:1786-1799 (fetch + CSRF header pattern)
const resp = await fetch('/survey/' + this.token + '/step-save', {
    method:  'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        'Accept':       'application/json',
    },
    body: JSON.stringify({ /* ... */ }),
});
```

**Gap — in-flight request cancellation:** no verified local precedent was read for `AbortController` usage in this codebase during this research session (project memory references a ⌘K command palette using it, but that file was not opened here — treat as `[ASSUMED]`, verify `resources/js/app.js` or the search palette script before relying on it as a copy source). Standard `AbortController` usage is well-documented browser API, not project-specific, so this is low-risk to add net-new: store the previous request's `AbortController`, call `.abort()` before issuing a new preview fetch, and `catch` `AbortError` silently (a superseded preview response arriving late must not clobber a newer one).

**CSRF at the endpoint:** because this is a same-origin admin POST from a Blade-rendered page (not a public token-gated survey route), the standard `web` middleware group's CSRF verification applies automatically — no special-casing needed beyond including the `X-CSRF-TOKEN` header, exactly as shown above.

### Pattern 3: Alpine reactive port-table (repeater, not DOM-toggle)

**What:** A parent `x-data` component holding a `ports` array; `x-for="(port, idx) in ports" :key="idx"` for row rendering; `x-model="port.field"` for every editable cell; `addPort()` / `removePort(idx)` methods that push/splice the array. Every mutation is watched (`$watch('ports', () => this.debouncedPreview(), { deep: true })`) to drive D-02's preview.

**When to use:** The port-table card in the edit screen (UI-SPEC Component 4).

**Why NOT `project-packages/review.blade.php`'s `equipmentSection()` pattern** (the pattern CONTEXT.md's canonical_refs section names as the closest analog): that component is DOM-query-first — rows are real `<tr>` elements with named hidden inputs (`rows[{index}][field]`), soft-delete/restore/purge/split all toggle `data-deleted` attributes and CSS visibility rather than mutating a JS array, and submission is a native form POST reading the DOM at submit time (see `app/Services/Imports/EquipmentCategoryClassifier.php`'s consumer, `ProjectPackageReviewController`, which parses `$request->input('equipment')` as nested form-array data). This pattern has **no reactive JS state to serialize into a debounced preview POST body** — you would have to re-scrape the DOM into JSON on every keystroke, which is strictly worse than just holding the state in Alpine to begin with. It is the wrong precedent for this phase despite being named in CONTEXT.md's canonical refs (CONTEXT.md's canonical refs are correct that it's the closest *table CRUD* precedent in the codebase, but D-02's live-preview requirement changes which pattern actually transfers cleanly).

**Better precedent — reactive array repeater, directly transferable:**
```js
// Source: resources/views/components/survey/repeater-equipment.blade.php:38 (x-for shape)
<template x-for="(item, idx) in (currentRoom?.equipment ?? [])" :key="idx">
  <div>
    <button @click="removeEquipment(idx)">...</button>
    <select x-model="item.type">...</select>
  </div>
</template>
```
This gives Alpine ownership of the array; `addEquipment()`/`removeEquipment(idx)` mutate it directly; every `x-model` binding is already reactive and trivially watchable for the debounced preview trigger. Recommend building the port table on this shape, not review.blade.php's.

**Row-delete accessibility:** UI-SPEC point 4 mandates `aria-label="Remove port {label}"` on the icon-only delete button per the project's "a11y batch-6" convention (`git log` `ef981be` per STATE.md) — apply the same pattern used on the 10 icon-only `×` buttons that batch already fixed.

### Anti-Patterns to Avoid
- **Client-side XML/SVG re-render for preview** — explicitly rejected by D-02; would drift from the production builder.
- **Scraping DOM state for the preview POST body** — the review.blade.php pattern; wrong fit for a live-preview requirement (see Pattern 3).
- **Regenerating `mxgraph_xml` on every port-table keystroke against the DB** — the preview endpoint must NOT persist anything; only Save/Promote/Discard write.
- **Trusting client-side D-04 validation alone** — the hard-block gate must be re-enforced server-side in the promote controller action; the client-side disabled-button state is UX sugar, not the security/data-integrity boundary.
- **Wrapping `DeviceStencilCacheService::resolveForPartNumber` calls in a DB transaction** inside `QuoteImportStencilStubber` — 21 D-03's docblock explicitly documents why this would hurt, not help, concurrency; do not "fix" this.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SVG upload sanitisation | A new regex-based or custom DOM-walk sanitiser | `App\Services\Drawings\SvgSanitizerService` (`app/Services/Drawings/SvgSanitizerService.php`) | Already production-hardened (2026-07-09 security batch WR-04), strips `<script>`/`<foreignObject>`/`on*`/`javascript:` schemes with `DOMDocument` + `LIBXML_NONET`/`LIBXML_NOENT`. D-12 mandates it — no exceptions. |
| Manufacturer-name → logo lookup | A new curation-UI-local resolver | `App\Services\Drawings\ManufacturerLogoResolver` | Already exposes `resolveAssetPath()` explicitly documented as "Useful for Phase 24 curation UI (browser-side `<img src="..."/>`)" (`app/Services/Drawings/ManufacturerLogoResolver.php:114-116`) — this was pre-built for this exact phase. |
| Bulk stencil-by-part_number lookup with ports eager-loaded | A new query in the admin controller | `App\Services\Cable\StencilPortResolver::attachToDevices()` | Collapses the repeated normalise+whereIn+setRelation block already deduplicated once (T2-A) across 3 call sites; reuse for any admin-list bulk-stencil-with-ports fetch. |
| Case-insensitive part_number lookup | Ad-hoc `strtolower(trim(...))` scattered in new code | `DeviceStencil::normalisePartNumber()` | Single source of truth (21 D-02); the unique index is enforced at the app layer through this helper — bypassing it risks a duplicate-looking row that the DB won't catch. |
| Dry-run/--commit artisan scaffolding | A bespoke flag-parsing loop | Mirror `PackagesReclassifyEquipmentCommand` (`app/Console/Commands/PackagesReclassifyEquipmentCommand.php`) structure | Established convention: `{--commit}` flag, dry-run default, per-row `$this->table()` report, idempotency documented in the class docblock. |

**Key insight:** almost every "hard part" of this phase already has a production-proven implementation sitting one directory over. The actual net-new work is thinner than the ROADMAP's "drag-port handles" framing implied — D-01 already downgraded that to numeric fields, and this research found the mxGraph syntax, the debounce pattern, and the reactive-repeater pattern all pre-exist in the codebase too.

## Runtime State Inventory

Not applicable — Phase 24 is a net-new feature phase (no rename/refactor/migration of existing identifiers). The one schema-adjacent item (D-10's `needs_review` backfill from `metadata.needs_phase_24_curation`) is covered under Common Pitfalls below since it is a data migration, not a rename.

## Common Pitfalls

### Pitfall 1: Reading a JSON column inside a migration with raw SQL breaks portability
**What goes wrong:** Writing `WHERE JSON_EXTRACT(metadata, '$.needs_phase_24_curation') = true` (or MySQL's `->>`/`->` operators) directly in the migration's `up()` works against the production MySQL/MariaDB connection but breaks the test suite, which runs against SQLite in-memory (`phpunit.xml:37-38`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — SQLite's JSON1 extension syntax differs, and MariaDB vs MySQL also diverge on some JSON function names.
**Why it happens:** Production `.env.example:20` sets `DB_CONNECTION=mysql`, but project memory (`db-slowness-investigation.md`) confirms the actual server is MariaDB — three different SQL dialects are in play across dev/test/prod for JSON operations specifically.
**How to avoid:** Do the backfill in PHP, not SQL. In the migration's `up()`, `DB::table('device_stencils')->get()` (or chunk if the table is large — it's ~96 rows per the phase's own audit, so no chunking needed), decode `metadata` via `json_decode()`, check the `needs_phase_24_curation` key, and issue a plain `DB::table(...)->where('id', $id)->update(['needs_review' => true])`. This is portable across all three engines because it's PHP array logic, not SQL JSON functions.
**Warning signs:** A migration that passes locally against MySQL but fails in CI/test (SQLite) or vice versa.

### Pitfall 2: `<constraint name="...">` and `device_ports.port_id` silently drift apart
**What goes wrong:** `CableRouter::stencilHasConstraints()` only checks for the *literal substring* `<constraint` anywhere in `mxgraph_xml` (`app/Services/Drawings/CableRouter.php:278`) — it does not verify that a specific `port_id` referenced by a `cable_schedule_items` row actually has a matching `name="..."` in the XML. If `AutoGenericStencilGenerator` emits `<connections>` for ports A/B/C but a curation-UI edit adds port D without regenerating the XML (or regenerates it with a typo'd `port_id`), the cable_schedule_items row can carry `source_port_id` pointing at port D while the rendered stencil has no matching constraint. draw.io will not raise an error — it silently fails to resolve the fixed connection point.
**Why it happens:** The `device_ports` table (structured data) and `mxgraph_xml` (rendered XML) are two representations of the same information that must be regenerated together; nothing in the schema enforces this at the DB layer.
**How to avoid:** `AutoGenericStencilGenerator::build()` (or its D-05 extension) must be the SOLE writer of `mxgraph_xml` whenever `device_ports` rows change — never allow the port table to save without also regenerating and persisting a fresh `mxgraph_xml`. Add a feature test asserting every `port_id` in the saved `device_ports` rows has a corresponding `<constraint name="{port_id}">` substring in the saved `mxgraph_xml`.
**Warning signs:** A promoted stencil renders in Phase 23 but cables to/from it silently fall back to coordinate-style + ⚠ glyph (the D-07 fallback ladder in `CableRouter.php:167-173`) despite the port FK being populated.

### Pitfall 3: `AutoGenericStencilGenerator` determinism contract must survive the D-05 extension
**What goes wrong:** The class docblock (`app/Services/Drawings/AutoGenericStencilGenerator.php:28-32`) states "the same hints array produces byte-identical output across calls — no random IDs, no timestamps." Any D-05 extension that iterates an associative array of ports without a stable sort, or that generates a port_id via `Str::uuid()`/`fake()` when one isn't supplied, breaks this contract — and breaks `stencils:reapply-templates`' dry-run diffing (D-08), which presumably compares old vs new XML to report what would change.
**Why it happens:** Easy to reach for a UUID or `time()`-derived id when synthesising a `port_id` for a template-derived port that doesn't have an engineer-supplied one yet.
**How to avoid:** Derive template-generated `port_id` values deterministically from the connector_type + sort_order (e.g. `"hdmi-1"`, `"hdmi-2"`) — same pattern the hand-curated seed pack already uses (`neat-bar-pro.json`'s `hdmi-in`/`hdmi-out`/`usb-c`/`power`/`lan`/`audio-out`).
**Warning signs:** `stencils:reapply-templates --commit` run twice in a row produces a non-empty diff on the second run (should be zero, mirroring `PackagesReclassifyEquipmentCommand`'s stated idempotency guarantee).

### Pitfall 4: Route-registration order for `preview` under `Route::resource`
**What goes wrong:** If `admin.device-stencils.preview` (or `.promote`) is registered via `Route::resource('admin/device-stencils', ...)` conventions or registered AFTER the resource route, Laravel will try to model-bind the literal string `preview` as `{deviceStencil}` and 404.
**Why it happens:** This exact bug class is already documented and avoided once in this codebase — `routes/web.php:295-299`'s comment: "the preview endpoint MUST be registered BEFORE the resource route so Laravel doesn't try to bind `preview` as a `{deviceCableRule}` model-bound parameter and 404 on the string."
**How to avoid:** Register `GET admin/device-stencils/preview`-style literal routes (if any share the resource's URI prefix) before `Route::resource(...)`, exactly as `device-cable-rules` does. Since D-14 specifies distinct named actions (`.preview`, `.promote`) rather than a plain `Route::resource`, this may not apply verbatim — but the underlying trap (literal segments colliding with `{deviceStencil}` binding) still applies if any named route shares a URI prefix with the `{id}` wildcard routes.
**Warning signs:** Preview or Promote requests 404 or hit the wrong controller method.

### Pitfall 5: `firstOrCreate` in `QuoteImportStencilStubber` must not be wrapped in the ambient import transaction
**What goes wrong:** `ExtractQuoteJob::handle()` wraps its core persist logic in `DB::transaction(...)` (`app/Jobs/ExtractQuoteJob.php:99-173`). If `QuoteImportStencilStubber` is called from inside that block, `DeviceStencilCacheService::resolveForPartNumber()`'s `firstOrCreate` race-safety argument (21 D-03: "concurrent first-calls race the INSERT; the loser's UNIQUE-violation is caught and retried as a SELECT") gets nested inside an outer transaction. A nested transaction failure (unique-violation) inside `DB::transaction` can, depending on driver/isolation level, poison the whole outer transaction rather than being caught and retried cleanly by Eloquent's `firstOrCreate` internals — risking the entire quote-import persist rolling back because of an unrelated stencil race.
**Why it happens:** It's tempting to call the stubber inside the same `DB::transaction` closure that persists `ProjectPackage`, for tidiness.
**How to avoid:** Call `QuoteImportStencilStubber` AFTER the `DB::transaction(...)` block closes in `ExtractQuoteJob` (i.e., after the `equipment_list` is durably persisted), not inside it. For `QuoteWerksImportService::buildExtractedData`, since that method doesn't persist at all (persistence happens later in `QuoteImportService::importFromData`'s own `DB::transaction` at `app/Core/Modules/QuoteImport/QuoteImportService.php:347-390`), the stubber call should similarly sit outside any transaction — either right after `buildExtractedData` returns (operating on the computed `$equipment` array, independent of whether the package row exists yet) or after `importFromParsedShape` returns the persisted `ProjectPackage`. `ReimportQuoteJob` delegates to `QuoteImportService::completePendingReimport` (not read in this session — verify its transaction boundaries before wiring the third call site).
**Warning signs:** Flaky quote-import feature tests under concurrent test execution, or a production quote import failing entirely when it happens to race another import on a shared part_number.

## Code Examples

### mxGraph shape XML with named connection constraints (verified production data)
```json
// Source: resources/data/device-stencils-seed/neat-bar-pro.json:17
"<shape name=\"21cav.neat-bar-pro\" h=\"160\" w=\"240\" aspect=\"variable\" strokewidth=\"inherit\"><connections><constraint x=\"0\" y=\"0.2\" perimeter=\"0\" name=\"hdmi-in\"/><constraint x=\"0\" y=\"0.45\" perimeter=\"0\" name=\"usb-c\"/><constraint x=\"0\" y=\"0.85\" perimeter=\"0\" name=\"power\"/><constraint x=\"1\" y=\"0.2\" perimeter=\"0\" name=\"hdmi-out\"/><constraint x=\"1\" y=\"0.45\" perimeter=\"0\" name=\"lan\"/><constraint x=\"1\" y=\"0.7\" perimeter=\"0\" name=\"audio-out\"/></connections><background>...</background><foreground>...</foreground></shape>"
```

### Cable edge consuming named constraints (production code, unmodified by Phase 24)
```php
// Source: app/Services/Drawings/CableRouter.php:228-235
private function portToPortStyle(\App\Models\CableScheduleItem $item): string
{
    $srcPortId = (string) ($item->sourcePort?->port_id ?? '');
    $dstPortId = (string) ($item->destPort?->port_id ?? '');

    return 'exitPortId=' . $srcPortId . ';'
         . 'entryPortId=' . $dstPortId . ';';
}
```

### Device-cell style referencing the stencil XML (how `mxgraph_xml` reaches the canvas at all)
```php
// Source: app/Services/Drawings/XtenAvLayoutEngine.php:65-66, 173-175
private const DEVICE_STYLE_PREFIX = 'shape=stencil(';
private const DEVICE_STYLE_SUFFIX = ');whiteSpace=wrap;html=1;verticalLabelPosition=top;verticalAlign=bottom;fontSize=10;fontColor=#333333;';
// ...
'style' => self::DEVICE_STYLE_PREFIX
    . base64_encode((string) ($stencil->mxgraph_xml ?? ''))
    . self::DEVICE_STYLE_SUFFIX,
```
This confirms the preview endpoint's response contract question (see Open Questions): the production renderer never emits a standalone `<shape>` document to the browser directly — it always wraps it in `shape=stencil(<base64>)` inside a full `mxCell`/`mxGraphModel`. A "preview" that returns raw SVG (not mxGraph XML) implies an EXTRA rendering step (draw.io → SVG export) that doesn't exist yet in this codebase for a single ad-hoc shape; only `DrawIoSpikeController::exportSvg` handles persisted drawings. Planner must decide the preview response contract (see Open Questions).

### Deterministic decision-tree classifier pattern to mirror for `CategoryPortTemplateResolver` (D-06/D-07)
```php
// Source: app/Services/Imports/EquipmentCategoryClassifier.php:66-233 (structure, abbreviated)
public function classify(array $item): string
{
    // 1. Explicit short-circuit if already-canonical value present.
    // 2. Build lowercase haystack from name/description/part_number.
    // 3. Priority-ordered keyword groups, MOST SPECIFIC FIRST, each an early return.
    // 4. Unconditional default at the bottom (here: 'hardware').
    // ...
}
```
D-07 requires the port-template resolver to diverge from this pattern at step 4: instead of an unconditional default, ANY multi-keyword match not covered by an explicit precedence rule (e.g. `bracket` beats `display`) must fall through to a **zero-port stub**, not a best-guess category. The `EquipmentCategoryClassifier`'s unconditional-default pattern is explicitly the wrong shape for D-07's "ambiguous → zero-port, never a wrong guess" requirement — copy the priority-ordered-groups mechanism, not the "always return something" fallback behaviour.

### Existing PNG upload + storage precedent (for the D-12 PNG-storage gap)
```php
// Source: app/Services/DeviceLabelPhotoService.php:36-56 (pattern, not to be copied verbatim — different domain)
$extension = match ($file->getMimeType()) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    // ...
    default      => 'jpg',
};
$basename  = (string) Str::uuid() . '.' . $extension;
$directory = "projects/{$project->id}/labels";
$storedPath = "{$directory}/{$basename}";
Storage::disk('public')->putFileAs($directory, $file, $basename);
```
Adapt this shape for `device_stencils` logo PNG storage (e.g. `public/device-stencils/{id}/logo.png`) if the planner chooses the `logo_path` column approach.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Tier 1 placeholder with NO port rails (21 D-04) | Tier 1.5 provisional rails WITH mxGraph constraints (D-05) | This phase (24) | Existing zero-port stubs stay on the old placeholder; only template-matched stubs get the new provisional-rail treatment |
| `needs_phase_24_curation` as a `metadata` JSON flag (21 D-02/D-05) | `needs_review` as a real indexed boolean column (D-10) | This phase (24) | List-view filtering (`?source=auto-generated&needs_review=1`) becomes index-backed instead of a JSON-extract table scan |

**Deprecated/outdated:** none — this phase is purely additive to Phase 21/22/23 infrastructure; nothing existing is removed or replaced.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `AbortController`-based in-flight preview-request cancellation has a local precedent in the ⌘K command palette script (referenced only via project memory, not verified by reading the file in this session) | Pattern 2 | If no local precedent exists, the planner writes net-new (low-risk, standard browser API) rather than reusing an existing pattern — not a functional risk, only affects whether a "copy this" citation is accurate |
| A2 | `ReimportQuoteJob`'s delegate `QuoteImportService::completePendingReimport` has transaction boundaries similar to `ExtractQuoteJob`/`QuoteImportService::importFromData` (not read directly in this session) | Pitfall 5 | If `completePendingReimport` wraps its whole body in a transaction the stubber call is placed inside, the same nested-transaction race risk applies to the re-import path — planner/executor must read this method before wiring the third D-09 call site |
| A3 | The preview endpoint should return SVG (as UI-SPEC's copy contract implies: "swap returned SVG") rather than mxGraph XML for a client-side draw.io read-only embed | Pattern 2 / Open Questions | If SVG-export tooling doesn't already exist for ad-hoc (non-persisted) shapes, the planner may need to build a new XML→SVG conversion step, which is more work than swapping an `<img>`/`<embed>` src pointed at raw XML through an existing draw.io viewer widget |

## Open Questions

1. **What exact HTTP response shape does the preview endpoint return — raw SVG, or mxGraph XML for a client-side viewer?**
   - What we know: UI-SPEC's copy contract says "swap returned SVG" and the state-indicator language implies the pane displays a rendered image. The only existing SVG-export code path (`DrawIoSpikeController::exportSvg`) operates on a PERSISTED drawing exported by the draw.io embed itself (client round-trips through the draw.io iframe's postMessage API) — it is not a server-side "render this ad-hoc XML string to SVG" utility.
   - What's unclear: whether a server-side XML→SVG conversion utility exists anywhere in this codebase (unsearched in this session beyond `SvgSanitizerService`, which sanitises, not renders) or whether the intended approach is to return the raw `mxgraph_xml`/`mxCell` fragment and let a **client-side draw.io read-only embed** render it (which would NOT violate D-02's "server renders through the real builder" requirement, since the XML bytes themselves are 100% server-generated — only the pixel rasterisation happens client-side via the same draw.io renderer that renders the real drawings).
   - Recommendation: the planner should explicitly decide this during planning (not defer to the executor) since it changes the Task shape substantially — either (a) build a new headless XML→SVG render step (heavier), or (b) embed a read-only draw.io iframe in the preview pane fed the server-generated XML fragment wrapped in a minimal `<mxGraphModel>` (lighter, matches how `DrawIoSpikeController`'s existing admin route already displays a draw.io canvas).

2. **Should `device_stencils` gain a `logo_path` column for PNG storage, or should PNG upload be validated away despite DRAW-52's wording?**
   - What we know: `logo_svg` is `longText`, inline-SVG-only by its documented contract (21 D-02). DRAW-52 says "Manufacturer logo upload (PNG/SVG) per stencil." `SvgSanitizerService` only handles SVG.
   - What's unclear: whether "PNG/SVG" in DRAW-52 was written loosely (meaning "an image, in either common format") or is a hard requirement that both formats must be genuinely storable and renderable in the mxGraph header bar (which currently only supports inline SVG via `<image>`-shape mxGraph primitives or a resolved-at-render-time SVG glyph — PNG in an mxGraph shape's `<foreground>` would need a different embedding mechanism, e.g. `<image>` tag with a data URI or external href).
   - Recommendation: raise this explicitly during `/gsd-plan-phase` or `/gsd-discuss-phase` follow-up — it's a schema decision with cross-cutting effects on `ManufacturerLogoResolver`'s fallback contract, not something to silently resolve during execution. Given the existing `logo_svg`/`ManufacturerLogoResolver` pair is SVG-only end to end, the lower-risk path is: accept PNG at upload, convert-or-reject non-SVG uploads at validation time with a clear message, OR add `logo_path` for file-based storage and extend the render-time lookup to prefer `logo_path` (as an `<img>`-style asset reference resolved server-side) over `logo_svg`. Either way this is a genuine gap CONTEXT.md D-12 didn't fully close.

3. **Does D-02's preview need to run the FULL `DrawIoBuilderService` (zone grouping, cable routing, multi-page) or just `AutoGenericStencilGenerator`'s single-shape output?**
   - What we know: CONTEXT.md D-02 says "AutoGenericStencilGenerator for stub shapes / DrawIoBuilderService for full render" — implying a conditional choice, not "always the full builder."
   - What's unclear: the edit screen only ever curates ONE stencil's ports in isolation (no project context, no other devices, no cables) — `DrawIoBuilderService::build(Project $project)` takes a `Project`, not a `DeviceStencil`. Running the "full render" path would require synthesizing a throwaway single-device Project fixture, which seems like unnecessary complexity for previewing one shape's port rails.
   - Recommendation: default to `AutoGenericStencilGenerator::build()` alone for the preview (single-shape scope matches the edit screen's actual scope); the "DrawIoBuilderService for full render" clause in D-02 most likely refers to the DISTINCT scenario of previewing an already-`engineer-curated` stencil that has a hand-built (not auto-generated) `mxgraph_xml` — i.e., which generator class produced the ORIGINAL xml, not which one renders the PREVIEW. Confirm this reading during planning; it affects whether the preview controller needs one dependency or two.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 (`phpunit.xml`) — NOT Pest. All existing Phase 21/22/23 tests use `class FooTest extends Tests\TestCase` with `use RefreshDatabase;` and `public function test_*(): void` methods (verified in `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php`) |
| Config file | `phpunit.xml` (project root) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` for tests, regardless of production's MySQL/MariaDB |
| Quick run command | `php artisan test --filter=DeviceStencil` (or `vendor/bin/phpunit --filter=DeviceStencil`) |
| Full suite command | `php artisan test` (excludes the `snapshot` group per `phpunit.xml:21-25`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DRAW-50 | `/admin/device-stencils` list filterable by source + search by part_number | feature | `php artisan test --filter=DeviceStencilListTest` | ❌ Wave 0 |
| DRAW-51 | Stencil edit screen: port table add/edit/delete rows, save persists ports + regenerates `mxgraph_xml` | feature | `php artisan test --filter=DeviceStencilEditTest` | ❌ Wave 0 |
| DRAW-52 | Logo upload (PNG/SVG) stored + sanitised (SVG path) | feature | `php artisan test --filter=DeviceStencilLogoUploadTest` | ❌ Wave 0 |
| DRAW-53 | "Promote to curated" flips `source`, clears `needs_review`, writes audit row | feature | `php artisan test --filter=DeviceStencilPromotionTest` | ❌ Wave 0 |
| Criterion 1 (auto-stub on import) | Import creates `device_stencils` + N `device_ports` rows, idempotent across re-imports | feature | `php artisan test --filter=QuoteImportStencilStubberTest` | ❌ Wave 0 |
| Criterion 2 (deterministic template chooser) | Same import twice → byte-identical stub shape; "Display Bracket" ambiguity test (UI-SPEC's named test case) → zero-port stub | unit + feature | `php artisan test --filter=CategoryPortTemplateResolverTest` | ❌ Wave 0 |
| Criterion 3 (list → edit → promote flow) | Full admin flow, browse → edit ports → upload logo → promote | feature | `php artisan test --filter=DeviceStencilCurationFlowTest` | ❌ Wave 0 |
| Criterion 4 (cross-project propagation) | Render project A with stub → promote stencil → re-render project A → new ports surface | integration/feature | `php artisan test --filter=StencilPromotionPropagationTest` | ❌ Wave 0 |
| Criterion 5 (top-10 bounded fill) | Manual/engineer-driven — not a unit-testable assertion; `stencils:coverage-report` output is the audit trail | manual-only (with automated coverage-report command output as evidence) | `php artisan stencils:coverage-report` | ❌ Wave 0 (command itself is Plan 24-01 scope per ROADMAP) |
| Criterion 6 (Tier 2 no-regression) | Uncatalogued devices still render the bare 21 D-04 placeholder (no `<connections>`); D-07 NULL-FK cable fallback unchanged | feature (regression) | `php artisan test --filter=AutoGenericStencilGeneratorTest` (extend existing, don't create new if it already exists — verify during planning) | check existing `tests/Feature/Drawings/` first |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<TouchedArea>` — fast, scoped to the file(s) just changed
- **Per wave merge:** `php artisan test` (full suite, excludes `snapshot` group per project convention)
- **Phase gate:** Full suite green before `/gsd:verify-work`; additionally run `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file (project lint gate, CLAUDE.md constraint)

### Wave 0 Gaps
- [ ] `tests/Feature/Drawings/QuoteImportStencilStubberTest.php` — covers criterion 1, DRAW-50/51 hook points (3 call sites)
- [ ] `tests/Feature/Drawings/CategoryPortTemplateResolverTest.php` (or `tests/Unit/Services/Drawings/` if kept pure-unit) — covers criterion 2, MUST include the "Display Bracket" named ambiguity test case (UI-SPEC line 241)
- [ ] `tests/Feature/Drawings/DeviceStencilCurationFlowTest.php` — covers DRAW-50/51/52/53, criterion 3
- [ ] `tests/Feature/Drawings/DeviceStencilPromotionTest.php` — covers DRAW-53, D-04 hard/soft gate assertions, criterion 4's propagation half
- [ ] `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` — covers D-08's dry-run/--commit + D-11's "92 existing stubs need no separate backfill" claim
- [ ] Synthetic "Light Forms 21CQ30451-01-OPS" fixture builder — NOT an actual PDF file (none exists on disk; grepped and confirmed absent). The existing convention (`tests/Feature/Rams/DocxBuilderPdfParityTest.php:65-106`'s `makeRams()` helper) builds this fixture PROGRAMMATICALLY via `Project::factory()` + hand-specified `ref`/`client`/hardware line items (`FW-85BZ40L`, `BT9910/B`, `PA20` per ROADMAP's audit note), not by parsing a real PDF. Criterion 1's feature test should follow the same programmatic-fixture pattern — build a `ProjectPackage` with `extracted_data['equipment']` containing those three part_numbers directly, rather than attempting to source or fabricate an actual PDF file.
- [ ] Framework install: none — PHPUnit + `RefreshDatabase` already fully configured project-wide.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (new) | Inherits existing `admin` middleware gate on the whole route group — no new auth surface |
| V3 Session Management | no | No new session state introduced |
| V4 Access Control | yes | `Route::middleware('admin')->group()` (`routes/web.php:251`) — new `admin.device-stencils.*` routes MUST sit inside this existing group, matching `admin.device-cable-rules.*`/`admin.devices.*` |
| V5 Input Validation | yes | Laravel FormRequest classes for port-table batched save + logo upload; connector_type/signal_type/direction validated against the allowlist in `config/drawings.php`'s new `port_templates` key |
| V6 Cryptography | no | Not applicable — no secrets/crypto in this phase |

### Known Threat Patterns for {stack}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SVG upload stored-XSS (`<script>`, `on*` handlers, `javascript:` href, nested `data:image/svg+xml`) | Tampering / Elevation of Privilege | `SvgSanitizerService::sanitize()` — mandatory per D-12, already production-hardened against exactly this threat model (its own docblock documents the WR-04 audit finding) |
| XXE / external entity injection via malicious SVG XML | Information Disclosure | Already mitigated inside `SvgSanitizerService` via `LIBXML_NONET \| LIBXML_NOENT` on `DOMDocument::loadXML()` — reuse, do not re-implement XML parsing elsewhere for this upload |
| Untrusted `part_number`/`manufacturer`/`model`/`description` strings from QuoteWerks/PDF-extracted data reaching mxGraph XML text nodes | Tampering (stored XSS in the rendered drawing) | `AutoGenericStencilGenerator::xml()` (`htmlspecialchars(..., ENT_XML1 \| ENT_QUOTES, 'UTF-8')`) already escapes every interpolated value — the D-05 extension MUST route any new interpolated text (port labels, connector-type glyph text) through the same helper; do not add a second escaping path |
| Promote action bypassing server-side D-04 validation via direct POST (skipping the disabled client-side button) | Tampering | The promote controller action MUST re-run the full D-04 hard-block check server-side regardless of what the client sent — client-side disabled state is UX only, never the enforcement boundary |
| Logo file upload — oversized file / MIME-type spoofing | Denial of Service / Tampering | Mirror the existing image-upload FormRequest pattern (`'photo' => ['required', 'file', 'image', 'max:10240']` at `app/Http/Controllers/SiteSurveyController.php:456` and siblings) — `'file', 'image', 'max:<KB>'` Laravel validation rules, not a custom MIME sniff |

## Sources

### Primary (HIGH confidence — read directly in this repo during this session)
- `app/Services/Drawings/AutoGenericStencilGenerator.php` — Tier 1 placeholder emitter, D-05 extension target
- `app/Services/Drawings/DeviceStencilCacheService.php` — `firstOrCreate` cache contract (21 D-03)
- `app/Models/DeviceStencil.php`, `app/Models/DevicePort.php` — schema-backed model contracts
- `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` — actual column shape (confirms `logo_svg` is text-only, no PNG-path column)
- `app/Services/Drawings/CableRouter.php` — named-constraint consumption (`stencilHasConstraints`, `portToPortStyle`)
- `app/Services/Drawings/DrawIoBuilderService.php`, `app/Services/Drawings/XtenAvLayoutEngine.php` — full render pipeline, `shape=stencil(base64(...))` wrapping mechanism
- `resources/data/device-stencils-seed/neat-bar-pro.json` — verified production `<connections><constraint .../></connections>` XML syntax
- `config/drawings.php` — existing config-vocabulary pattern for the new `port_templates` key (D-06)
- `app/Services/Imports/EquipmentCategoryClassifier.php` — priority-ordered decision-tree mechanism to mirror (not vocabulary, per D-06)
- `app/Services/Drawings/DrawingDataResolverService.php:437-469` — deterministic keyword→role inference precedent
- `app/Console/Commands/PackagesReclassifyEquipmentCommand.php`, `app/Console/Commands/BackfillCablePortFksCommand.php` — dry-run/`--commit` artisan convention
- `app/Jobs/ExtractQuoteJob.php`, `app/Jobs/ReimportQuoteJob.php`, `app/Core/Modules/QuoteImport/QuoteWerksImportService.php`, `app/Core/Modules/QuoteImport/QuoteImportService.php` — the 3 D-09 hook points + their transaction boundaries
- `app/Services/Drawings/SvgSanitizerService.php` — mandatory sanitiser (D-12)
- `app/Services/Drawings/ManufacturerLogoResolver.php` — pre-built `resolveAssetPath()` explicitly for Phase 24
- `app/Services/Cable/StencilPortResolver.php` — bulk stencil-with-ports lookup helper
- `app/Services/DeviceLabelPhotoService.php` — PNG upload + `Storage::disk('public')` precedent
- `resources/views/admin/device-cable-rules/index.blade.php`, `edit.blade.php` — admin CRUD Blade precedent + route-ordering pitfall documentation
- `resources/views/surveys/show.blade.php:1740-1830` — 600ms debounce + CSRF fetch pattern
- `resources/views/components/survey/repeater-equipment.blade.php` — reactive `x-for`/`x-model` repeater pattern (better fit than review.blade.php)
- `resources/views/project-packages/review.blade.php:2005-2135` — DOM-toggle pattern (confirmed poor fit for D-02)
- `routes/web.php:251-303` — admin route group + preview-route-ordering precedent
- `phpunit.xml`, `.env.example` — test vs production DB engine divergence (Pitfall 1)
- `tests/Feature/Rams/DocxBuilderPdfParityTest.php:65-106` — confirms "Light Forms 21CQ30451-01-OPS" is a synthetic fixture pattern, not a real PDF on disk
- `resources/data/device-stencils-seed/_v1.3-promoted.json` — confirms `metadata.needs_phase_24_curation` flag exists in real seed data (D-10 backfill source)
- `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` — full Phase 21 foundation contract

### Secondary (MEDIUM confidence)
- Project memory note (`db-slowness-investigation.md`, referenced not re-read) — confirms production DB engine is MariaDB, corroborating `.env.example`'s `DB_CONNECTION=mysql` + `config/database.php`'s `mariadb` driver entry

### Tertiary (LOW confidence — flagged, not verified this session)
- ⌘K command palette's `AbortController` usage (referenced via STATE.md session log entry `3368fdb`, not opened/read directly) — see Assumption A1

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero new packages, every pattern cited from files read in this session
- Architecture: HIGH — mxGraph constraint syntax and preview-debounce pattern both confirmed against live production code/data, not inferred from training knowledge
- Pitfalls: HIGH — all 5 pitfalls derived from reading actual transaction boundaries, actual route-ordering comments already in the codebase, and actual test-vs-prod DB config divergence

**Research date:** 2026-08-13
**Valid until:** 30 days (stable — this is internal-codebase research, not third-party API/library research subject to upstream change)

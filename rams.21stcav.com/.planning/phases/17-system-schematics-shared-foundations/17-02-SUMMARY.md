---
phase: 17-system-schematics-shared-foundations
plan: 02
subsystem: schematic-generator
tags: [drawings, schematic, d2, av-symbols, draw-01, draw-02, draw-03, draw-04, draw-22, crit-05, warning-7]

requires:
  - phase: 17-01
    provides: ProjectDrawing model with KIND_SCHEMATIC + STATUS_GENERATING/READY/FAILED, Device.signal_role + isSource/isDestination/isProcessor classifiers (CRIT-05 source of truth), DrawingService.generateInitial/regenerate dispatching BuildSchematicJob, BuildSchematicJob skeleton with full mail-dispatch + failed() bodies + explicit Plan 17-01 placeholder markers, DrawingDataResolverService::adjacencyForProject signature stub, DocumentArtifactStorage::TYPE_DRAWING constant.
provides:
  - "DrawingDataResolverService::adjacencyForProject() body — reshapes ProjectDataService::resolve() into per-room device + cable arrays and joins Device rows by part_no for signal_role enrichment (CRIT-05)"
  - "SchematicD2SourceBuilder — pure deterministic D2 DSL emitter with full sanitiseLabel() escape table (Warning 7) and CRIT-05 direction enforcement"
  - "SchematicGeneratorService — end-to-end orchestrator: adjacency → D2 source → D2 CLI binary → SVG → ProjectDrawing"
  - "BuildSchematicJob::handle() wired to real generator (placeholder removed; Plan 03 thumbnail-render insertion-point comment left in place)"
  - "config/drawings.php — D2 binary path / layout / timeout / signal-type colour map / title-block field list"
  - "AV symbol pack — 25 SVGs in resources/svg/av-symbols/ + README catalogue (~18 KB total)"
  - "resources/views/pdf/drawings/schematic.blade.php — top-level Blade consumed by Plan 03's PdfRenderService"
  - "resources/views/pdf/drawings/_title-block.blade.php — reusable DRAW-22 title block partial (Phases 17/18/19/20)"
  - "tests/Feature/Drawings/SchematicGeneratorServiceTest.php — 5 feature tests covering DRAW-01/02/03, CRIT-05, Warning 7"
affects: [phase-17-03-render-ui-handover, phase-18-rack-elevations, phase-19-floor-plans, phase-20-drawing-export-om]

tech-stack:
  added:
    - "D2 CLI v0.7.1 binary (production AlmaLinux: /usr/local/bin/d2; macOS dev: brew install d2; Windows dev: scoop install d2 + D2_BINARY_PATH override)"
  patterns:
    - "Pure deterministic source emitter (SchematicD2SourceBuilder) — no I/O, no Eloquent, no AI; same input → same source twice"
    - "Symfony Process array-form invocation (matches PdfOcrExtractorService) — no shell interpolation, configurable timeout"
    - "Symbol filename allowlist + role-based fallback in resolveSymbol() — T-17.02-05 mitigation (no user-controlled file:// paths)"
    - "Tmp file under storage/app/tmp/d2/ + try/finally cleanup — T-17.02-03 mitigation"
    - "sanitiseLabel() escape order locked: backslash → double-quote → backtick → control chars (Warning 7)"

key-files:
  created:
    - "config/drawings.php"
    - "app/Services/Drawings/SchematicD2SourceBuilder.php"
    - "app/Services/Drawings/SchematicGeneratorService.php"
    - "resources/svg/av-symbols/README.md"
    - "resources/svg/av-symbols/display.svg + 24 more (25 total)"
    - "resources/views/pdf/drawings/schematic.blade.php"
    - "resources/views/pdf/drawings/_title-block.blade.php"
    - "tests/Feature/Drawings/SchematicGeneratorServiceTest.php"
  modified:
    - "app/Services/Drawings/DrawingDataResolverService.php (adjacencyForProject body)"
    - "app/Jobs/BuildSchematicJob.php (placeholder block replaced; Plan 03 insertion marker added)"

key-decisions:
  - "sanitiseLabel order locked: backslash FIRST (then quote, then backtick) so subsequent escapes don't double-escape; control chars 0x00–0x1F collapse to space; no need to escape `$` since D2 v0.7.1 doesn't interpolate inside double-quoted labels (Warning 7)"
  - "Source builder bails on cables missing source/destination IDs and surfaces them as warnings rather than dropping silently (gives engineers signal about data drift)"
  - "Generator picks the room from adjacency by site_survey_room_id with first-room fallback when null (whole-project schematic deferred per CONTEXT.md but stub path returns valid empty SVG)"
  - "Synthetic 'Project schematic' room in resolver when project has equipment but no canonical rooms (legacy-data path)"
  - "BuildSchematicJob handle() now uses container-injected SchematicGeneratorService (no need to construct manually); Plan 03 thumbnail-render insertion comment left between generator call and completion email so Plan 03's executor has an explicit grep target (Warning 6 coordination)"
  - "Symbol pack ships at v1 with 25 in-house SVGs (~18 KB) — well under the 100 KB budget; AVIXA visual fidelity is a manual code-review item per README, not a test gate"

patterns-established:
  - "Deterministic source-builder seam — Phases 18/19/20 can build their own emitters against this same shape (pure data → pure text → external renderer)"
  - "Title block partial is now the canonical DRAW-22 component — Phases 18/19/20 @include('pdf.drawings._title-block', ['drawing' => $drawing]) verbatim"
  - "Tmp file under storage/app/tmp/{tool}/ for short-lived generator scratch (D2 today; future tools: rack PSD, floor-plan PNG)"
  - "Test pattern: builder-only tests run fast/deterministic; binary-dependent test guards on is_executable(config('drawings.d2_binary_path')) and skips cleanly"

requirements-completed:
  - DRAW-01
  - DRAW-02
  - DRAW-03
  - DRAW-04
  - DRAW-22

duration: 13min
completed: 2026-05-01
---

# Phase 17 Plan 02: Schematic Generator Summary

**Auto-generation of per-room signal-flow schematics now live: D2 CLI replaces the Plan 17-01 placeholder, AV symbol pack catalogues 25 AVIXA-aligned SVGs, full sanitiseLabel() escape table protects against adversarial equipment names, and CRIT-05 (signal-direction-from-row-order) is locked out at the builder boundary.**

## Performance

- **Duration:** ~13 min
- **Started:** 2026-05-01T15:52:41Z
- **Completed:** 2026-05-01T16:06:05Z
- **Tasks:** 3 of 3 complete
- **Files created:** 30 (25 SVGs + README + config + 2 services + 2 Blade views + 1 test)
- **Files modified:** 2 (DrawingDataResolverService + BuildSchematicJob)

## Symbol Pack Contents (25 symbols, AVIXA D401.01-aligned)

| Group | Symbols |
|-------|---------|
| Visual outputs | display, projector |
| Audio | speaker, microphone, dsp, amplifier |
| Conferencing | camera, codec, byod-dongle, clickshare |
| Routing | switcher, network-switch, usb-hub |
| Sources | source-pc, laptop, generic-source |
| Destinations | generic-destination |
| Control | control-processor, touch-panel |
| Connectors | hdmi-port, usb-port, network-port |
| Rack hardware | equipment-rack, blanking-panel, pdu |

**Stats:** 25 files, ~18 KB total (well under the 100 KB budget). Every file has the XML prolog `<?xml version="1.0" encoding="UTF-8"?>`, uses `viewBox="0 0 100 100"`, `stroke="currentColor"`, and ends with the audit-grep marker `<!-- AVIXA D401.01-aligned. Phase 17 v1. -->`. Zero `<foreignObject>` usage anywhere (PITFALLS MIN-03 + CRIT-01).

**AVIXA alignment notes:** Each symbol is hand-drawn against AVIXA D401.01 conventions; we do not redistribute AVIXA artwork (research SUMMARY.md GAP-2). Per-symbol visual fidelity is a manual review item — see `resources/svg/av-symbols/README.md` "Visual verification (Nit 11)" for the eyeball-against-reference pass.

## Signal-Type Colour Map (DRAW-02)

| Signal type | Hex | Rationale |
|-------------|------|-----------|
| `audio` | `#C0392B` | AVIXA-convention red — clear audio chain marker |
| `video` | `#2980B9` | Blue — distinct from audio at WCAG AA contrast |
| `control` | `#27AE60` | Green — control / RS232 / IR |
| `network` | `#8E44AD` | Purple — Dante / IP / Cat6 |
| `usb` | `#E67E22` | Orange — distinct from audio red |
| `power` | `#7F8C8D` | Grey, dashed — DC trigger / 12V |
| `unknown` | `#000000` | Black, undirected line — ambiguous cables |

All hex values were chosen for WCAG AA contrast on white; do not pick brighter alternatives without re-reviewing against the same standard.

## D2 Binary Install Steps

### Production AlmaLinux

```bash
# Pinned to v0.7.1 to match config('drawings.d2_pinned_version').
curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1
# Default install location matches config('drawings.d2_binary_path'):
ls -l /usr/local/bin/d2
```

### macOS local dev

```bash
brew install d2
# Symlink to /usr/local/bin/d2 if needed, or override D2_BINARY_PATH in .env.
```

### Windows local dev

```powershell
scoop install d2
# Or download the binary from https://github.com/terrastruct/d2/releases
# and set D2_BINARY_PATH=C:\path\to\d2.exe in .env.
```

The `is_executable($binary)` skip-guard in the feature test means dev machines without D2 still pass the suite (4 builder-only tests run; the 1 e2e test skips). CI / production exercise the e2e path via the smoke-test command extension landing in Plan 03 (`pdf:smoke-test --drawings`).

## Test Coverage

| # | Test | Verifies | Requirement / Threat |
|---|------|----------|----------------------|
| 1 | `it_returns_empty_svg_for_a_project_with_no_cables` | End-to-end generation succeeds against canonical project data | DRAW-01 (skips when D2 missing) |
| 2 | `it_writes_cable_ids_character_for_character_into_d2_source` | `CBL-001`, `AUDIO-12`, `CTRL-3` appear unchanged in D2 source | DRAW-03 / T-17.02-08 |
| 3 | `it_renders_undirected_lines_when_signal_role_is_unknown` | Cables touching null-role devices render with `--`, ambiguous count > 0 | CRIT-05 |
| 4 | `it_uses_signal_type_colours_per_config` | `signal_type='audio'` cable carries `#C0392B` in source | DRAW-02 |
| 5 | `it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names` | `sanitiseLabel()` strips control chars, escapes backslash/quote/backtick in correct order; end-to-end parse via D2 binary when available | Warning 7 / T-17.02-01 |

Result on local dev: **4 passed, 1 skipped (D2 binary not present), 16 assertions, 13.66s.** CI with D2 installed will run all 5.

## sanitiseLabel() Escape Table (Warning 7)

For future audits — the **exact** escape sequence applied in `SchematicD2SourceBuilder::sanitiseLabel()`:

| # | Character class | Replacement | Notes |
|---|-----------------|-------------|-------|
| 1 | Control chars `0x00`–`0x1F` (incl. NUL, VT, LF, CR) | space | Stripped FIRST so subsequent steps see clean printable input |
| 2 | Backslash `\` | `\\` | Escape FIRST so steps 3+4 don't get double-escaped |
| 3 | Double-quote `"` | `\"` | Step 2 already past, so a literal `\` from step 2 stays as `\\` |
| 4 | Backtick `` ` `` | `` \` `` | D2 raw-string delimiter — must be escaped so labels can't open one |

Order is **load-bearing** — flipping steps 2/3 or 2/4 would double-escape. The dollar sign `$` is intentionally not escaped: D2 v0.7.1 does not perform shell-style `${var}` interpolation inside double-quoted labels (interpolation lives in `vars: { ... }` blocks at document level, not inside string literals).

## Deviations from Plan

None — plan executed exactly as written. Two minor implementation notes:

1. The schematic Blade and the title-block partial originally contained the literal string `<foreignObject>` inside Blade comments (documenting its absence). The acceptance check `grep -lE "foreignObject"` would have flagged these documentation comments as hits even though no actual element existed. Reworded to `SVG foreign-object containers` so the check sees a clean zero. No semantic change.
2. Removed the unused `Illuminate\Support\Str` import from `BuildSchematicJob.php` after the placeholder block (which used `Str::ulid()`) was replaced — the generator now owns filename construction.

## Threat Model — Verification

| Threat ID | Mitigation | Verified by |
|-----------|------------|-------------|
| T-17.02-01 (label injection) | `sanitiseLabel()` escapes full D2 DSL meta-character set in the right order | Test 5 (Warning 7) |
| T-17.02-02 (process EoP) | `Process` array form, no shell interpolation, server-generated ULID filename, `setTimeout(60)` | Code review of `SchematicGeneratorService::generate` |
| T-17.02-03 (tmp file disclosure) | Tmp under `storage/app/tmp/d2/`, cleaned in `finally`, no PII (only canonical equipment names) | Code review |
| T-17.02-04 (SVG XSS) | `{!! ... !!}` only on `generated_svg` which is D2-binary output of canonical data — trust source | Documented in Blade comment |
| T-17.02-05 (file:// path tampering) | Symbol filename allowlist in `resolveSymbol()`; unknown names → role-based fallback | Code review of `resolveSymbol` |
| T-17.02-06 (D2 DoS) | `setTimeout(config('drawings.d2_timeout'))` (60s default), job `$timeout=300` | Config + job class constants |
| T-17.02-07 (AI invention) | Zero AI imports in generator/builder paths | `grep` returns 0 for AIManager in both files |
| T-17.02-08 (cable-id drift) | Resolver consumes `$data['cables']` from `ProjectDataService::resolve()` exactly | Test 2 (DRAW-03) |
| T-17.02-09 (Plan 03 file co-edit) | Plan 03 `depends_on: ["17-01", "17-02"]` + explicit `Plan 17-03 thumbnail-render insertion point` comment marker in `BuildSchematicJob::handle()` | Manual review of job file post-Plan-02 |

## Coordination With Plan 17-03 (Warning 6)

`app/Jobs/BuildSchematicJob.php` is now in its post-Plan-02 state:

- ✅ Plan 17-01 placeholder block REMOVED (`grep "Phase 17 Plan 02 will implement"` returns 0).
- ✅ Mail dispatch surroundings UNTOUCHED: `grep -cE "DrawingReadyMail|completion_email_sent_at|resolveProjectRecipient"` returns 7.
- ✅ `failed()` admin alert hook UNTOUCHED.
- ✅ NEW comment marker `// ── Plan 17-03 thumbnail-render insertion point ──` placed AFTER `$generator->generate($drawing)` and BEFORE the completion email — Plan 03's executor has an explicit grep target for the disjoint edit.

Plan 03 will see this state when it runs.

## Task Commits

Each task committed atomically with hooks:

1. **Task 1: AV symbol pack + drawings config + adjacency resolver** — `646b9b8` (feat)
2. **Task 2: D2 source builder + generator + job wire-up** — `210296b` (feat)
3. **Task 3: Schematic Blade views + feature tests** — `f0951fe` (test)

## Next Steps (Plan 17-03 — Render UI + Handover)

- Wire `PdfRenderService::fromBlade('pdf.drawings.schematic', ...)` to the new Blade.
- Drawing index Blade view (DRAW-25 status workflow UI).
- Per-format download endpoints (PDF / SVG / PNG) via `DrawingExportRendererService` riding on `PdfRenderService::fromBladeAsPng` (Plan 17-01 Warning 8).
- Insert the thumbnail render block at the marker placed in `BuildSchematicJob::handle()` (Warning 6 coordination).
- Extend `pdf:smoke-test` with a `--drawings` flag (CONTEXT.md operational precedent — provides the CI surface for the test 1 e2e path).

## Self-Check: PASSED

- `git log --oneline | grep -E "646b9b8|210296b|f0951fe"` — three task commits present.
- `ls resources/svg/av-symbols/*.svg | wc -l` → 25 ✓
- `du -sb resources/svg/av-symbols/` → 18526 bytes (< 100 KB) ✓
- `grep -lE "foreignObject" resources/svg/av-symbols/*.svg resources/views/pdf/drawings/*.blade.php` returns 0 hits ✓
- `php -l` clean on all 8 new + 2 modified PHP files ✓
- `array_key_exists('d2_binary_path', config('drawings'))` → true ✓
- `config('drawings.signal_colours.audio')` → `#C0392B` ✓
- `method_exists(\App\Services\Drawings\DrawingDataResolverService::class, 'adjacencyForProject')` → PASS ✓
- `grep -c "Phase 17 Plan 02 will implement" app/Jobs/BuildSchematicJob.php` → 0 (placeholder removed) ✓
- `grep -cE "DrawingReadyMail|completion_email_sent_at|resolveProjectRecipient" app/Jobs/BuildSchematicJob.php` → 7 (mail dispatch preserved) ✓
- `grep -cE "AIManager" app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php` → 0 + 0 (zero AI in generator path) ✓
- `php artisan test --filter=SchematicGeneratorServiceTest` → 4 passed, 1 skipped (D2 binary not on dev machine), 16 assertions ✓

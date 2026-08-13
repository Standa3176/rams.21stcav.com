---
phase: 24
slug: stencil-curation-ui-quote-import-auto-stub
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-13
---

# Phase 24 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `24-RESEARCH.md` §Validation Architecture (line 451).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5.3 — **NOT Pest**. Convention: `class FooTest extends Tests\TestCase`, `use RefreshDatabase;`, `public function test_*(): void` (verified in `tests/Feature/Drawings/ProjectDevicesWithStencilsTest.php`) |
| **Config file** | `phpunit.xml` (project root) |
| **Test DB** | `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — **diverges from production MySQL/MariaDB** (see Pitfall note below) |
| **Quick run command** | `php artisan test --filter=<TouchedArea>` |
| **Full suite command** | `php artisan test` (excludes the `snapshot` group per `phpunit.xml:21-25`) |
| **Lint gate** | `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file (CLAUDE.md constraint) |
| **Estimated runtime** | Quick ~5-20s scoped; full suite minutes (800+ tests project-wide) |

> ⚠ **Portability constraint (RESEARCH Pitfall 1):** tests run SQLite in-memory while production is MySQL/MariaDB. The D-10 backfill migration (carrying `metadata.needs_phase_24_curation` into the new indexed `needs_review` column) MUST be written in PHP (loop + `json_decode`), **not** raw SQL JSON functions, or it will pass in CI and fail on the live DB.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=<TouchedArea>`
- **After every plan wave:** `php artisan test` (full suite)
- **Before `/gsd-verify-work`:** full suite green + lint clean on every touched PHP file
- **Max feedback latency:** ~20s at task granularity

---

## Per-Requirement Verification Map

Task IDs resolve during planning; this map binds each requirement and success criterion to its automated command now, so the planner can attach them to concrete tasks.

| Req / Criterion | Behavior | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---|---|---|---|---|---|---|---|
| DRAW-50 | `/admin/device-stencils` list filterable by source + `needs_review`, search by part_number | V4 Access Control | Route sits INSIDE the existing `Route::middleware('admin')->group()` (`routes/web.php:251`) | feature | `php artisan test --filter=DeviceStencilListTest` | ❌ W0 | ⬜ pending |
| DRAW-51 | Edit screen port table add/edit/delete; save persists ports + regenerates `mxgraph_xml` | V5 Input Validation | FormRequest validates connector_type/signal_type/direction against the `config/drawings.php` allowlist | feature | `php artisan test --filter=DeviceStencilEditTest` | ❌ W0 | ⬜ pending |
| DRAW-52 | Logo upload (PNG/SVG) stored + SVG sanitised | SVG stored-XSS; XXE; MIME spoof / oversized | `SvgSanitizerService::sanitize()` mandatory (D-12); `'file','image','max:<KB>'` rules mirroring `SiteSurveyController.php:456` | feature | `php artisan test --filter=DeviceStencilLogoUploadTest` | ❌ W0 | ⬜ pending |
| DRAW-53 | Promote flips `source`, clears `needs_review`, writes audit row | Promote bypass via direct POST | Controller **re-runs the full D-04 hard-block check server-side**; client-side disabled button is UX only, never the boundary | feature | `php artisan test --filter=DeviceStencilPromotionTest` | ❌ W0 | ⬜ pending |
| Criterion 1 | Import creates stencil + N ports, idempotent across re-imports, all 3 hook paths | Nested-transaction race (RESEARCH Pitfall) | Stubber respects existing transaction boundaries in each of the 3 import paths | feature | `php artisan test --filter=QuoteImportStencilStubberTest` | ❌ W0 | ⬜ pending |
| Criterion 2 | Deterministic template chooser; same import → identical stub shape; **"Display Bracket" → zero-port stub** | — | Determinism contract: allowlist only, no AI in this path (D-06/D-07) | unit + feature | `php artisan test --filter=CategoryPortTemplateResolverTest` | ❌ W0 | ⬜ pending |
| Criterion 3 | Full admin flow: browse → edit ports → upload logo → promote | V4 + V5 | — | feature | `php artisan test --filter=DeviceStencilCurationFlowTest` | ❌ W0 | ⬜ pending |
| Criterion 4 | Render project A with stub → promote → re-render → new ports surface | — | — | integration | `php artisan test --filter=StencilPromotionPropagationTest` | ❌ W0 | ⬜ pending |
| Criterion 5 | Top-10 bounded Tier 1 fill | — | — | **manual-only** (see below) | `php artisan stencils:coverage-report` (evidence only) | ❌ W0 | ⬜ pending |
| Criterion 6 | Tier 2 no-regression: uncatalogued devices still render the bare 21 D-04 placeholder (no `<connections>`); D-07 NULL-FK cable fallback unchanged | Untrusted strings → mxGraph text nodes | New interpolated text (port labels, connector glyphs) routes through the existing `AutoGenericStencilGenerator::xml()` escaper — **do not add a second escaping path** | feature (regression) | `php artisan test --filter=AutoGenericStencilGeneratorTest` | check `tests/Feature/Drawings/` first — extend, don't duplicate | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Drawings/QuoteImportStencilStubberTest.php` — criterion 1, all 3 D-09 hook points
- [ ] `tests/Feature/Drawings/CategoryPortTemplateResolverTest.php` (or `tests/Unit/Services/Drawings/` if kept pure-unit) — criterion 2; **MUST include the "Display Bracket" named ambiguity case** (CONTEXT `<specifics>`; UI-SPEC line 241)
- [ ] `tests/Feature/Drawings/DeviceStencilCurationFlowTest.php` — DRAW-50/51/52/53, criterion 3
- [ ] `tests/Feature/Drawings/DeviceStencilPromotionTest.php` — DRAW-53, D-04 hard-block vs soft-warn assertions, criterion 4 propagation
- [ ] `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` — D-08 dry-run/`--commit`; also proves D-11's claim that the 92 existing zero-port stubs need no separate backfill
- [ ] **Synthetic "Light Forms 21CQ30451-01-OPS" fixture builder** — no such PDF exists on disk (grepped, confirmed absent). Follow the established programmatic pattern from `tests/Feature/Rams/DocxBuilderPdfParityTest.php:65-106` (`makeRams()`): build a `ProjectPackage` with `extracted_data['equipment']` containing `FW-85BZ40L`, `BT9910/B`, `PA20` directly. Do **not** attempt to source or fabricate a real PDF.
- [ ] Framework install: **none** — PHPUnit + `RefreshDatabase` already configured project-wide.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|---|---|---|---|
| Top-10 highest-volume part_numbers reach Tier 1 (full port) coverage | Criterion 5 | Engineer labour, not code. Each stencil needs per-device datasheet review; correctness is a human judgement about real hardware, not an assertable invariant. | Run `php artisan stencils:coverage-report`, curate the top 10 via `/admin/device-stencils`, re-run the report and attach before/after output as the audit trail. |
| Provisional vs verified port rails are visually distinguishable | D-05 / UI-SPEC | Visual contract — dashed/muted vs solid/full-opacity. Automatable only as "the XML differs", which does not prove a human can tell them apart. | Open a project drawing containing both a template-stubbed and an engineer-curated device; confirm at a glance which ports are unverified. |
| Live preview matches the finally-rendered drawing | D-02 | The whole point of the server-rendered preview is parity. A test can assert both call the same service; only a human confirms the rendered result actually corresponds. | Edit ports on the edit screen, save, then open the project drawing and compare against what the preview showed. |
| Post-upload migration ordering on live | 21 D-13 | Deployment step, not code. | After upload, run `php artisan migrate` BEFORE opening the new admin screen. |

---

## Validation Sign-Off

- [ ] All tasks have an `<automated>` verify command or a declared Wave 0 dependency
- [ ] Sampling continuity: no 3 consecutive tasks without an automated verify
- [ ] Wave 0 covers all ❌ MISSING references above
- [ ] No watch-mode flags in any command
- [ ] Feedback latency < 20s at task granularity
- [ ] D-10 backfill migration verified PHP-based, not raw SQL JSON (SQLite/MySQL divergence)
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

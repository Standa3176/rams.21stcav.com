---
phase: 23
slug: xten-av-style-renderer
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-13
---

# Phase 23 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

> The planner fills this in based on the test architecture surfaced in 23-RESEARCH.md (PHPUnit feature/unit split, 27 mapped test cases across DRAW-42..49, 4 fixture projects, determinism harness via Carbon::setTestNow, v1.3-surface git-diff gate). This file is a contract; the planner is responsible for completing it before plans pass the Nyquist gate.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5 + Mockery ^1.6 + FakerPHP ^1.23 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Phase23` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~{planner fills} seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Phase23 --stop-on-failure`
- **After every plan wave:** Run `php artisan test` (full suite — confirms no v1.3 regression)
- **Before `/gsd-verify-work`:** Full suite green AND `git diff --stat` on v1.3 surfaces shows no changes
- **Max feedback latency:** {planner fills} seconds

---

## Per-Task Verification Map

> Planner fills this — one row per task. Map each DRAW-42..49 deliverable + every D-01..D-10 decision to at least one test. Existing primitives from Phase 21/22 (DeviceStencilCacheService, CablePortFkResolverService, etc.) already have coverage; Phase 23 adds renderer-level tests.

| Task ID | Plan | Wave | Requirement | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------------|-----------|-------------------|-------------|--------|
| 23-XX-YY | XX | N | DRAW-42 | mxGraph stencil emits port-rail constraints | feature | `php artisan test --filter=DeviceCardStencilTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

> Planner fills the test stubs needed before any Wave 1 task runs. Per RESEARCH.md, ~10 new test files are expected. List them here.

- [ ] `tests/Feature/Drawings/XtenAvRendererDeterminismTest.php` — D-LOCK-5/6 byte-identity contract (with `Carbon::setTestNow()`)
- [ ] `tests/Feature/Drawings/DeviceCardStencilTest.php` — DRAW-42 stencil layout (logo top / name centre / model bottom / port rails)
- [ ] `tests/Feature/Drawings/PortToPortRoutingTest.php` — DRAW-43 named-port edge attachment (`exitPortId`/`entryPortId`)
- [ ] `tests/Feature/Drawings/NullFkFallbackTest.php` — D-07 device-edge fallback + ⚠ glyph
- [ ] `tests/Feature/Drawings/SignalTypeColourTest.php` — DRAW-44 reads `config/cables.php` (single source of truth per D-10)
- [ ] `tests/Feature/Drawings/CableIdLabelTest.php` — DRAW-45 midpoint label from `cable_schedule_items.cable_id`
- [ ] `tests/Feature/Drawings/SubRoomZoneTest.php` — DRAW-46 dashed-group derivation (D-01 config map + D-02 per-device override + D-04 free-text escape hatch)
- [ ] `tests/Feature/Drawings/MultiPagePaginatorTest.php` — DRAW-47 threshold ≥5 cables + ≥3 devices per sub-sheet; system overview always emits
- [ ] `tests/Feature/Drawings/TitleBlockTest.php` — DRAW-48 source-of-truth resolution per D-08 (project/client/sheet#/date/revision/designed-by/drawn-by/checked-by)
- [ ] `tests/Feature/Drawings/SheetBorderTest.php` — DRAW-49 dashed border on every page
- [ ] `tests/Feature/Drawings/V13SurfacesUntouchedTest.php` — Phase 21 D-10 invariant: rendering does NOT touch SchematicGeneratorService / SchematicD2SourceBuilder / DrawingDataResolverService / BoundPdfBuilderService / DrawingExportRendererService

> Fixture projects (4) per RESEARCH.md: small-Teams-Room (single area, ≤5 devices), boardroom (multi-area, ≥20 devices), paging-system (sub-zone heavy, ≥3 zones), legacy-null-fk (NULL source_port_id / dest_port_id rows for D-07 fallback).

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| XTEN-AV reference colour parity (D-10) | DRAW-44 | Visual side-by-side against reference image; no automated image-diff in scope | Open `/admin/drawings/draw-io-spike/{paging-system-fixture}` → screenshot → side-by-side vs XTEN-AV PAGING SYSTEM image (2026-05-09). If mismatch: log separate config-update ticket, do NOT mutate `config/cables.php` in Phase 23 |
| `<mxfile>` multi-page embed UX (DRAW-47) | DRAW-47 | draw.io embed tab rendering is browser UX, not testable in PHPUnit | Open spike URL for paging-system fixture → confirm 3+ sheets show tabs in embed → click each tab → confirm sheet content + title block updates |
| Tier 1 placeholder density (deferred from D-04 carry-forward) | — | Visual acceptability of 20+ auto-generic cards is subjective | Open boardroom fixture → confirm no visual overlap / unreadable text. If unacceptable, raise v2.1 polish ticket |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (~10 new test files per RESEARCH.md)
- [ ] No watch-mode flags
- [ ] Feedback latency < {planner fills} seconds
- [ ] Determinism harness uses `Carbon::setTestNow()` in setUp (RESEARCH.md notes this is mandatory — title block's `now()->format('Y-m-d')` otherwise breaks byte-identity at date boundary)
- [ ] v1.3 git-diff gate: `git diff --stat` on the 5 invariant files shows zero changes after every plan
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

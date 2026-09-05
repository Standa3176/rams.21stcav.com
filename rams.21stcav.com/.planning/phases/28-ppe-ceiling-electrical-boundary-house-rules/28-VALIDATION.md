---
phase: 28
slug: ppe-ceiling-electrical-boundary-house-rules
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-09-05
---

# Phase 28 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Seeded from `28-RESEARCH.md` §"Validation Architecture". The planner fills the
> per-task map; the executor keeps Status current.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit via Laravel 12's `php artisan test` wrapper |
| **Config file** | `phpunit.xml` — `Unit` / `Feature` suites, `snapshot` group excluded by default |
| **Quick run command** | `php artisan test --filter=<TouchedClass>` |
| **Full suite command** | `php artisan test` (excludes `@group snapshot`) |
| **Byte-output suite** | `php artisan test --group=snapshot` — **required whenever DOCX/PDF template output changes** |
| **Estimated runtime** | Quick ~5-15s; full suite unmeasured this session — planner to record on first Wave 0 run |

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=<TouchedClass>`
- **After every plan wave:** `php artisan test` (default suite)
- **Before `/gsd:verify-work`:** Full suite green, **plus** an explicit `--group=snapshot`
  run if any DOCX/PDF template byte output changed
- **Max feedback latency:** target < 30s for the quick command

---

## Per-Task Verification Map

*Planner fills Task ID / Plan / Wave / Threat Ref / Secure Behavior. Requirement rows and
commands below are pre-seeded from research and are the minimum coverage this phase must reach.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | RULE-01 | — | FFP3 + face-fit replaces FFP2 at all 14 live sites; no source file reintroduces it | unit + static scan | `php artisan test --filter=FfpTwoBannedFromSourceTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | RULE-01 | — | A stored `reviewed_data['ppe']` entry of `Dust Mask (FFP2)` renders as FFP3 | feature | `php artisan test --filter=PpeVocabulary` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | RULE-06 | — | Ceiling hazard title identical across fold map, seeder, requirement and roadmap (D-05) | unit | `php artisan test --filter=LegacyHazardNameFoldMap` | ✅ extend | ⬜ pending |
| TBD | TBD | TBD | RULE-09 | — | Electrical scope boundary lands in `exclusions` on both DOCX render paths | feature | `php artisan test --filter=RamsDisplayPatchService` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | RULE-10 | — | Ceiling-load statement lands whenever the `ceiling_works` activity is present, incl. mount-only jobs | feature | `php artisan test --filter=HazardIncludeWhenResolver` | ⚠️ verify | ⬜ pending |
| TBD | TBD | TBD | GATE-06 | — | Throws on any FFP2 surviving to generation; proven by revert-and-restore | feature | `php artisan test --filter=Ffp2ConfinedSpaceGate` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | GATE-07 | — | Throws on affirmative confined-space labelling; **clean on the seeder's own negating line** | unit + feature | `php artisan test --filter=ControlTextRuleViolations` | ✅ extend | ⬜ pending |
| TBD | 28-01 | 1 | GATE-07 | — | Hyphenated `confined-space entry` classifies identically to the spaced form | unit | `php artisan test --filter=ControlTextRuleViolations` | ✅ extend | ⬜ pending |
| TBD | 28-06 | 2 | GATE-07 | — | A hazard **NAME** of `Confined Space` / `Confined Spaces` / `Confined Space Entry` throws, even when its controls are clean | unit + feature | `php artisan test --filter=Ffp2ConfinedSpaceGateTest` | ❌ W0 | ⬜ pending |
| TBD | 28-06 | 2 | GATE-06, GATE-07 | — | Gate throws via `runPipeline()` **and** via `runFromReview()`, not only Save Review | feature | `php artisan test --filter=Ffp2ConfinedSpaceDualPathGateTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` — repo-wide static ban on `FFP2`,
      copying `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php`'s shape verbatim
      (recursive `.php` glob — `.blade.php` files are covered, since `SplFileInfo::getExtension()`
      returns `php` for them — forbidden-substring assertion, self-exclusion for the guard file).
      Exclusion list: the 5 backup-only occurrences under `resources/views.backup-260430/` and the
      two `rams.blade - keep boarder.php` / `rams.blade-keep-borders.php` copies. Zero `FFP2`
      occurrences exist under `tests/` today, so nothing pre-existing conflicts.
- [ ] `tests/Feature/Rams/Ffp2ConfinedSpaceDualPathGateTest.php` — **required, not optional**
      (plan-checker Blocker 2, revision 1). Proves the gate throws via `runPipeline()`
      (`RamsBuilderService.php:941`) and via `runFromReview()` (`:296`), not only via the Save
      Review HTTP route. The reflection unit test is path-agnostic and does not substitute for
      this. Phase 26 was closed prematurely twice on exactly this gap.
- [ ] GATE-06/07 throw-and-surface suite mirroring Phase 27's four-file GATE-09 shape
      (`DisplayLiftGateTest`, `DisplayLiftDualPathTest`, `DisplayLiftSaveReviewGateTest`,
      `DisplayLiftPdfSourceTest` — ~65 tests). **Planner decides whether all four dual-path shapes
      are needed or a subset suffices; say which and why.**
- [ ] A fixture proving the PPE-array closed-vocabulary fix — no existing test touches
      `reviewed_data['ppe']` → rendered-document FFP3 substitution. This surface has **no**
      rule-scanning mechanism today (research Q4).
- [ ] Confirm whether a `HazardIncludeWhenResolverTest`-style test already proves Q1's mechanism
      (`ceiling_works` → `signal:ceiling_void_access`). Not located during research. If absent,
      one is needed to make Q1's finding regression-proof rather than a point-in-time observation.

*Fold-map / drift-guard surfaces need extending only — those tests already exist.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Clean regeneration of 21CQ30960 through the **DOCX** path with no gate trip | ROADMAP criterion 4 | Requires live production data; the local DB has 0 `RamsDocument` rows | On `rams.21stcav.com` **as `stcav`, never root**: pull, migrate, regenerate 21CQ30960 via `BuildRamsDocumentJob`, confirm `status = completed` / `error_message = null`, then scan `generated_data` for `FFP2` and affirmative confined-space strings |
| Gate observed firing on live | ROADMAP criterion 4 / GATE-06 | Requires deliberately entering a defect on a real RAMS | Optional bite test, mirroring Phase 27's: introduce `FFP2` into a scratch RAMS's control text and Save Review; expect the redirect-with-message path, not a 500 |
| Backfill scope measurement | D-08 | No DB reachable from the research session | Run the counting query research supplies against production before deciding whether a migration is needed — **measure, do not assume** |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] Both DOCX render paths exercised, not just the PDF blade
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

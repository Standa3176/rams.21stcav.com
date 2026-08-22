---
phase: 260822-esf
slug: project-deliverables-selection
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-22
---

# Phase 260822-esf — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `260822-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.3+ (**NOT Pest**) |
| **Config file** | `phpunit.xml` (repo root) |
| **Quick run command** | `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=<TestClass>` |
| **Full suite command** | `composer test` (clears config cache, then `php artisan test`) |
| **Estimated runtime** | quick ~5–20s · full ~310s |

**Blade caveat:** `php -l` is NOT sufficient for Blade files. Any touched
`.blade.php` must additionally be verified with
`app('blade.compiler')->compileString(file_get_contents($path))` — a JS comment
referencing a component silently broke compilation of a shared drawer on
2026-08-17 and `php -l` passed it.

---

## Sampling Rate

- **After every task commit:** targeted `--filter=` run against the touched test class(es)
- **After every plan wave:** `composer test` (full suite)
- **Before `/gsd:verify-work`:** full suite green
- **Max feedback latency:** ~20s (targeted) / ~310s (full)

**Known-good baseline:** the full suite currently has **one intentional
failure** — `QueueRecoverCommandTest::unhealthy queue runs restart and drain
plan`, documented in `.planning/quick/20260817-green-the-suite/SUMMARY.md`
Item 5 (`queue:work` inherits the 128MB default; `Worker::memoryExceeded()`
reads whole-process memory, so ~2000 prior tests in one PHPUnit process trip
it). It passes in isolation. Any *other* failure is a genuine regression.
Do not "fix" the suite by absorbing new failures into this exception.

---

## Per-Task Verification Map

| Decision | Behavior | Test Type | Automated Command | File Exists | Status |
|---|---|---|---|---|---|
| D-11 | `quote_imported → engineering` valid **iff** Survey not required | unit | `--filter=ProjectTransitionTest` | ✅ needs rewrite | ⬜ pending |
| D-11 | Auto-advance hooks skip `survey_pending` when not required | feature | `--filter=ProjectAutoAdvanceTest` | ✅ needs new cases | ⬜ pending |
| D-11 | Stepper renders no false "done" tick for a skipped stage | feature | `--filter=ProjectStepperTest` | ❌ W0 | ⬜ pending |
| D-11 | "Next Step" chain does not prompt "Create Site Survey" when not required | feature | `--filter=ProjectNextStepTest` | ❌ W0 | ⬜ pending |
| D-12 | Not-required RAMS/Survey never produce red/amber | unit | `--filter=ProjectHealthServiceTest` | ✅ needs new cases | ⬜ pending |
| D-13 | "Not yet decided" goes amber only after the grace period | unit | `--filter=ProjectHealthServiceTest` | ✅ needs new cases | ⬜ pending |
| D-01/D-02/D-03 | Three-state CRUD, auto-flip on create, audit row per change | unit | `--filter=ProjectDeliverablesServiceTest` | ❌ W0 | ⬜ pending |
| D-03 | Audit captures who + when + **reason** | feature | `--filter=ProjectDeliverableAuditTest` | ❌ W0 | ⬜ pending |
| D-04/D-07 | One canonical list drives tabs, storage types and adapters | unit | `--filter=DeliverableCatalogTest` | ❌ W0 | ⬜ pending |
| D-08 | Not-required tabs render muted, at the end, still present | feature | `--filter=ProjectTabStripTest` | ❌ W0 | ⬜ pending |
| D-09 | A deliverable holding data is never hidden, whatever the flag | feature | `--filter=ProjectTabStripTest` | ❌ W0 | ⬜ pending |
| D-14 | Completion warns, does not block | feature | `--filter=ProjectCompletionTest` | ❌ W0 | ⬜ pending |
| D-15 | Import defaults derived from `services`-category presence | feature | `--filter=DeliverableImportDefaultsTest` | ❌ W0 | ⬜ pending |
| D-16 | Interstitial step renders between review and confirm and persists | feature | `--filter=QuoteImportDeliverablesStepTest` | ❌ W0 | ⬜ pending |
| D-17 | Retrofit infers correct default per existing project shape | unit | `--filter=DeliverableRetrofitTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/ProjectTransitionTest.php:91-97` — **must be rewritten, not extended.**
      `test_cannot_transition_to_completely_skipped_state` currently asserts
      `quote_imported → engineering` is **always** false, which is the exact
      opposite of D-11. Make it conditional and keep a case proving the
      transition stays invalid when Survey **is** required — otherwise the
      rewrite silently legalises the skip for every project.
- [ ] `tests/Feature/ProjectAutoAdvanceTest.php` — new cases covering the
      not-required-Survey path through **all three** live hook call sites
      (`QuoteImportService::confirm()` and two in `SurveyService`).
- [ ] `tests/Unit/ProjectHealthServiceTest.php` — new cases mirroring the
      existing `setRelation()` pattern at `:202-217`.
- [ ] New `tests/Unit/Services/ProjectDeliverablesServiceTest.php` — D-01/02/03 core.
- [ ] New migration/retrofit test for D-17's inference logic against seeded
      `RamsDocument` / `SiteSurvey` combinations.
- [ ] New Blade-render tests for D-08/D-09 tab behaviour.
- [ ] Framework install: **none** — PHPUnit and Mockery already configured.
- [ ] `Project::factory()` **does exist** despite `TESTING.md` claiming otherwise
      (stale doc, flagged by pattern mapping) — use it.

---

## Manual-Only Verifications

| Behavior | Decision | Why Manual | Test Instructions |
|---|---|---|---|
| Muted "Not required" grouping reads correctly and the inline "add anyway" is discoverable | D-08 | Visual/affordance judgement; a render assertion proves presence, not legibility | Open a project with mixed flags; confirm not-required tabs are visually subordinate but findable, and that "add anyway" flips the flag |
| Interstitial step is not skipped past in real use | D-16 | The entire rationale for choosing a step over a fieldset is attention, which no test can measure | Run a real quote import end-to-end; confirm the checklist gets its own moment before confirm |
| Retrofit produced sane flags across the real back-catalogue | D-17 | Local SQLite has 1 project and 0 documents — unrepresentative | After migrating production, spot-check a hardware-only project, a full-scope project, and one mid-flight |

---

## Open Risk — carried from research

**D-17 retrofit counts are unvalidated (LOW confidence).** The local dev
database has exactly one project with no related documents, so the inference
logic cannot be exercised against realistic data before shipping. Real
per-document-type counts must be pulled from production MySQL/MariaDB. Until
then, treat D-17's migration as the highest-uncertainty deliverable in the
phase and make it idempotent and re-runnable.

---

## Validation Sign-Off

- [ ] All tasks have automated verify or a Wave 0 dependency
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Full suite matches baseline (1 known-intentional failure, no others)
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

# Retrospective

Living document — updated after each milestone.

---

## Milestone: v1.0 — RAMS MVP

**Shipped:** 2026-04-12
**Phases:** 7 | **Plans:** 29 | **Tasks:** 42
**Timeline:** 3 days (2026-04-09 → 2026-04-12)
**Commits:** 212

### What Was Built

1. `Project` model as top-level entity with lifecycle state machine (7 states: quote_imported → archived)
2. `ProjectDataService` — 4-tier canonical data merge point powering all downstream generators
3. QuoteWerks SQL import pipeline via read-only MS SQL connection (QuoteWerksRepository pattern)
4. Three document generators: Worksheet DOCX, O&M Manual DOCX, Cable Schedule XLSX — all queue-based
5. AI content pack — single Claude call generates room-by-room scope narratives for RAMS enrichment
6. RAMS document quality improvements — scope conditional, content pack integration, skip-guard
7. Dynamic AI-generated pre-install check questions per survey room on creation, with yes/no/other answer UI and completion gate enforcement

### What Worked

- **Wave-based parallel execution** — phases with independent plans ran simultaneously via worktree isolation, dramatically cutting wall-clock time
- **TDD RED-first discipline** — writing failing tests before production code caught several interface design issues early (Phase 07 contract surface)
- **AIManager container resolution** (`app(AIManager::class)->run()`) — using the IoC container instead of static calls made Mockery test doubles work correctly
- **Silent failure pattern in Jobs** — wrapping AI dispatch in try/catch without rethrowing kept survey creation atomic even when AI was unavailable
- **4-tier merge priority in ProjectDataService** — clear precedence (reviewed > survey > sql > extracted > defaults) eliminated ambiguity across all generators
- **Phase 07 Blade ParseError diagnosis** — systematic approach (php -l on compiled cache, counting if/endif imbalance in compiled PHP) isolated a subtle Blade regex pattern matching bug

### What Was Inefficient

- **REQUIREMENTS.md checkboxes not maintained** — requirements were defined upfront but never marked [x] during execution; archive reflects shipped state only from memory
- **ROADMAP.md left empty** — the planning file was cleared/reset and not rebuilt during execution; required manual reconstruction at milestone completion
- **STATE.md left minimal** — gsd-tools couldn't parse it due to blank state; STATE.md format warnings throughout
- **Worktree cleanup failures on Windows** — `git worktree remove` returned "Invalid argument" errors on Windows for Phases 07-05 and 07-06; non-blocking but left orphaned tracking entries
- **Worktree sync conflicts (Phases 07-03, 07-04)** — agent wrote production files to main working tree instead of worktree during execution; required manual commit-then-merge resolution

### Patterns Established

- `app(AIManager::class)->run()` — always resolve AIManager from container, never static; required for Mockery compatibility in tests
- Blade inline `@if`/`@endif` inside outer `@if` blocks → use PHP ternary `{{ $var ? 'x' : '' }}` instead to avoid Blade pattern-matching miscount
- Survey creation dispatch pattern: `GenerateSurveyQuestionsJob::dispatch($room->id)` wrapped in try/catch — non-blocking, silent failure
- `$room->relationLoaded('questions')` guard before rendering relationship in Blade partials
- Phase plan files should be committed with a `docs(XX)` prefix commit, execution commits with `feat(XX-YY)` prefix

### Key Lessons

- Keep REQUIREMENTS.md checkboxes in sync during execution — mark [x] when a plan's SUMMARY.md confirms delivery, not just at milestone end
- ROADMAP.md and STATE.md need explicit rebuild steps between phases — don't assume tools will maintain them
- On Windows, worktree cleanup should use `git worktree prune` + `rmdir` rather than `git worktree remove`
- Blade template: never nest `@if`/`@endif` inline inside another `@if` block on the same line — Blade's sequential pattern matching will miscount and leave orphaned directives
- The 4-day MVP delivery (3 days active) shows the GSD workflow is effective for structured feature work — keep phase plans tight (≤6 plans per phase)

### Cost Observations

- Model mix: balanced profile (sonnet primary, opus for research/planning)
- Sessions: multiple across 3 days
- Notable: AI content pack (Phase 05) single-call approach avoided per-room AI overhead — significant cost saving vs. room-by-room generation

---

## Cross-Milestone Trends

| Metric | v1.0 |
|--------|------|
| Phases | 7 |
| Plans | 29 |
| Timeline (days) | 3 |
| Commits | 212 |
| Worktree conflicts | 2 |
| Blade bugs found | 1 |

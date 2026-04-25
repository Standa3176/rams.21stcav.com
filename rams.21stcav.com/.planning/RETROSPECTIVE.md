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

## Milestone: v1.1 — Operations Dashboard & Notifications

**Shipped:** 2026-04-25
**Phases:** 2 (08, 09) | **Plans:** 9 | **Originally scoped:** 4 (10/11 deferred)
**Timeline:** 5 days (2026-04-14 research → 2026-04-19 verification)
**Commits in dev range:** 83 (`96722e0..c8576c5`)
**Source LOC delta:** +24,441 / −12,814 across 203 files
**Audit verdict:** `tech_debt` — 34/34 satisfied, 14/14 wiring + 6/6 E2E flows PASS

### What Was Built

1. `DashboardController` + `ProjectHealthService` + `ProjectHealth` DTO with priority-ordered red/amber/green derivation rules; install-programme task-completion widget; Alpine.js client-side filter (no page reload, URL hash)
2. Six queued email mailables (`RamsReadyMail`, `OmManualReadyMail`, `WorksheetReadyMail`, `CableScheduleReadyMail`, `RamsReviewNeededMail`, `DocumentGenerationFailedMail`) — all `implements ShouldQueue`; document attachments via `DocumentArtifactStorage::readPath()`
3. Idempotency-first dispatch — 9 timestamp columns set BEFORE send; locked by 24 notification feature tests + dedicated Idempotency + Bcc regression tests
4. `NotificationRecipientResolver` service eliminating `is_admin` boolean drift (codebase migrated to `User::where('role', 'admin')`)
5. Postmark transport packaged + `RAMS_NOTIFICATION_BCC` global BCC config; production cutover gated by `POSTMARK-OPS-CHECKLIST.md` runbook
6. `SurveyService::submitPublic` refactored to use the resolver — latent `Project::with('user')` and `is_admin` bugs fixed without regression

### What Worked

- **Idempotency-first dispatch design** — setting `*_email_sent_at` BEFORE send (in the same `update()` as the dispatch) made retries provably safe by construction; eliminated an entire class of duplicate-email bugs without needing per-test orchestration
- **`NotificationRecipientResolver` extracted as a service** — single source of truth for owner+admin-fallback resolution; `SurveyService::submitPublic` was refactored to use it for free, fixing a latent `Project::with('user')` bug at the same time
- **Sub-plan numbering (`09-02b`)** — when the 09-02 NotificationRecipientResolver work spawned a missing-factory dependency, splitting it into `09-02b` for the 4 model factories kept the plan boundary clean without renumbering everything downstream
- **Mail::fake() + assertQueued** — confirmed queue dispatch path without needing a real worker; let feature tests assert recipient + attachment + idempotency in one shot
- **ROADMAP overview table updated for shipped milestones** — dashboard-style row format ("v1.1 ✅ Shipped — phases 08-11") gave at-a-glance state vs. v1.0's multi-paragraph milestone block

### What Was Inefficient

- **Scope reduction not recorded mid-execution** — phases 10 (Bitrix24/CRM push) and 11 (multi-channel notifications + quality scoring) were dropped during execution but ROADMAP.md still listed them as v1.1 phases. Discovered only at archive time; required retroactive scope-reduction note in MILESTONES.md
- **Phase directories not archived after v1.1 was marked Shipped** — phases 08/09 sat in `.planning/phases/` next to v1.2 phases for over a week, complicating subsequent `init` queries (e.g., `phase_count: 5` when v1.2 was the active milestone)
- **Shared REQUIREMENTS.md across milestones** — the file holds both v1.1 NOTF-* and v1.2 INST-* requirements; the complete-milestone CLI's auto-archival/deletion logic doesn't handle the multi-milestone case cleanly. Required manual hand-holding of v1.1 archive (don't delete REQUIREMENTS.md; v1.2 still needs it)
- **REQUIREMENTS.md checkboxes lagged execution** — same pattern as v1.0; 21 NOTF-* boxes still `[ ]` at audit time despite VERIFICATION marking every one `pass`. Resolved at archive time with a single `replace_all` edit, but ideally these tick during execution
- **Milestone CLI's accomplishment extraction pulls from current milestone, not requested milestone** — running `milestone complete v1.1` while config says `milestone_version: v1.2` produced bogus accomplishments + wrong stats; required full manual rewrite of MILESTONES.md entry and v1.1-ROADMAP.md archive
- **VALIDATION.md `wave_0_complete: false` for both phases** — Nyquist contracts were authored at plan time but never closed during execution; tracked as post-hoc debt (`/gsd-validate-phase 08 09`)

### Patterns Established

- **Idempotency-timestamp before send** — `$model->update(['*_email_sent_at' => now()])` in the SAME `update()` call as the dispatch site; never split. Job retries cannot double-send by construction
- **`User::where('role', 'admin')` is the canonical admin lookup** — `is_admin` boolean is forbidden in `app/`; codebase has 0 `is_admin` matches as the lock
- **`NotificationRecipientResolver::resolveProjectRecipient(Project)` and `resolveAdminRecipients()`** — single seam for all recipient resolution; no `Project::with('user')` direct calls
- **`config('rams.notifications.bcc')` chain at every dispatch site** — `if ($bcc = trim(config('rams.notifications.bcc'))) $pending->bcc($bcc)` — applied uniformly to all 9 dispatch sites
- **`try { Mail::send } catch (\Throwable) { Log::warning }`** — every send wrapped; mail failure must NEVER roll back the underlying generation job, status transition, or survey submission
- **`POSTMARK-OPS-CHECKLIST.md` runbook for production cutover** — operational gate is in markdown next to the phase, not in code; treat as deferred-with-runbook rather than tech debt

### Key Lessons

- **Multi-milestone REQUIREMENTS.md is fragile** — when one file holds requirements for two milestones, the complete-milestone CLI assumes single ownership and tries to delete it. Future milestones should split REQUIREMENTS.md per milestone before authoring next milestone's reqs
- **Tick REQUIREMENTS.md checkboxes when SUMMARY.md confirms delivery, not at audit** — the audit ends up doing what should be a per-plan hygiene step. Same lesson as v1.0
- **Archive phase directories at milestone shipped, not at the next milestone's audit** — leaving 08/09 next to 12-16 for a week confused tooling and added a manual cleanup step
- **The CLI auto-extractor can't be trusted for non-current-milestone archival** — for v1.1 audit/archive while config points at v1.2, manually rewrite the MILESTONES.md entry and the milestone-archive ROADMAP.md
- **Operational debt deserves its own bucket** — NOTF-05g (Postmark cutover) is genuinely separate from "tech debt"; it's done-with-runbook, not deferred. PROJECT.md should track operational debt distinctly from incomplete code
- **Scope reduction is information** — phases 10/11 being deferred is a v1.1 outcome that needed to be recorded explicitly. Silent scope cuts cost time at archive

### Cost Observations

- Model mix: balanced profile (sonnet primary)
- Sessions: estimated 4-6 across the 5-day window (planning → research → execution → verification → audit/archive)
- Notable: integration-checker agent ran twice (once for v1.1, once for v1.2 in the same session) — no observable cost penalty for back-to-back audits since both phases were already documented

---

## Cross-Milestone Trends

| Metric | v1.0 | v1.1 |
|--------|------|------|
| Phases scoped | 7 | 4 |
| Phases delivered | 7 | 2 (10/11 deferred) |
| Plans | 29 | 9 |
| Timeline (days) | 3 | 5 |
| Commits in dev range | 212 | 83 |
| Source LOC +/- | n/a | +24,441 / −12,814 |
| Worktree conflicts | 2 | 0 |
| REQUIREMENTS.md checkbox lag at audit | yes | yes (21 NOTF-*) |
| Scope cuts mid-execution | 0 | 2 phases (10/11) |
| Operational debt at ship | 1 (DATA-04 partial) | 1 (Postmark cutover) |
| Audit verdict | n/a (no audit step in v1.0) | tech_debt (no blockers) |

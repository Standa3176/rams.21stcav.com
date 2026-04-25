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

## Milestone: v1.2 — Installation Programme & Field Management

**Shipped:** 2026-04-25
**Phases:** 5 (12, 13, 14, 15, 16) | **Plans:** 21
**Timeline:** 10 days (2026-04-13 phase-12 plan → 2026-04-23 phase-16 verification)
**Commits in dev range:** 281 (`c0b37da..a92503c`); 95 `feat(...)` commits
**Source LOC delta:** +45,200 / −13,579 across 348 files
**Audit verdict:** `tech_debt` — 48/48 satisfied, 11/11 wiring + 5/5 E2E flows PASS

### What Was Built

1. **Auto-generated install programmes** — `InstallTaskGeneratorService::generate()` reads canonical `ProjectDataset` from `ProjectDataService::resolve()`, persists per-room × equipment-item `install_tasks`; PM confirm gate before activation; re-generation archives prior programme
2. **Engineer assignment + scheduling UI** — bulk + per-task assignment; planned start/end dates; week-view table; conditional Gantt timeline (frappe-gantt) when programme spans >4 days; engineer-only field-view filter
3. **Mobile field view** (`/projects/{project}/programme`) — sticky bar + clock chip + bottom tab-bar; tap-to-advance status with 400ms ring-green pulse; per-task photo capture with iOS HEIC server-side conversion (`HeicImageConverter::writeAsJpeg()`); per-task notes blur-saved; counters() Alpine helper for room/programme progress
4. **Time tracking** — category-tagged `time_entries` (Installation / Commissioning / Testing / Other); 60s silent heartbeat (Axios interval, exponential retry, Page Visibility pause); `programme:close-stale-sessions` Artisan command (hourly cron, 2-hour safety net); retro-edit with atomic `time_entry_audits` row; owner/admin-only Actual Hours widget on project dashboard (4-row horizontal bar, pure CSS, no chart library)
5. **Commissioning checklist + client sign-off** — `commissioning_items` per equipment × AVIXA category (7 categories); per-item AJAX save (4 distinct PATCH/POST routes, never single-form POST); HEIC photo evidence per item; `creagia/laravel-sign-pad` client signature with `devicePixelRatio` scaling for iOS Retina (human-verified 2026-04-22); snagging PDF via `DocumentArtifactStorage::TYPE_SNAGGING` with embedded signature; state machine advances `Project.status` from INSTALLING → COMMISSIONING via `canTransitionTo()` guard
6. **Worksheet DOCX integration** — Section E (pre-install check answers per room) + dashboard generation trigger button (WORK-05 + WORK-06)

### What Worked

- **`ProjectDataService::resolve()` as the canonical seam** — Phase 12 made install-task generation read from the v1.0 4-tier merge, never `extracted_data` directly. Continued the v1.0 discipline; meant Phase 16 `CommissioningItemGenerator` reused the same source without ambiguity
- **Per-item AJAX over single-form POST** — Phase 16's commissioning UI saves each status / note / photo as a separate request. When a basement-installed plant-room engineer's session drops mid-checklist, the work already saved stays saved. Single-form POST would have been catastrophically fragile here
- **`devicePixelRatio` canvas scaling** — defended against documented signature_pad iOS Retina DPI corruption (GitHub issues #71, #153, #200, #362). Spike in Plan 16-02 explicitly tested it, then locked in Plan 16-05 with `Math.max(window.devicePixelRatio || 1, 1)` + `resize`/`orientationchange` listeners. Human-verified on real iPhone in one cycle
- **Phase 14 → Phase 15 layered design** — Phase 14 shipped INST-04g (one-open-entry clock-in guard) early to fulfil its mobile field view SC; Phase 15 extended `time_entries` with `category` / `notes` / `closure_reason` via additive ALTER migration without breaking Phase 14's contract. Documented in 14-CONTEXT.md "Claude's Discretion"
- **HEIC server-side conversion as a single seam** — `HeicImageConverter::writeAsJpeg()` (introduced in Phase 14, reused in Phase 16's `CommissioningPhotoService`) eliminated a class of silent-failure bugs (HEIC uploads succeed; GD render fails later)
- **`DocumentArtifactStorage::TYPE_SNAGGING`** — Phase 16's new artifact type was intentionally excluded from `LEGACY_ROOTS` (no pre-H-07 history); kept the read/write path clean and locked the convention
- **Fewer worktree conflicts than v1.0** — multi-wave parallel execution produced 0 worktree conflicts (vs. v1.0's 2). Suggests the v1.0 lessons (commit pattern, scope discipline) carried over

### What Was Inefficient

- **Same shared-REQUIREMENTS.md problem as v1.1** — file held both v1.1 NOTF-* and v1.2 INST-* requirements; the complete-milestone CLI's archive/delete logic doesn't handle the multi-milestone case. Required manual hand-holding (don't delete after v1.1 archive; do delete after v1.2 archive)
- **Same milestone-CLI accomplishment-extraction garbage as v1.1** — the CLI pulled SUMMARY.md fragments that weren't one-liners (or were partial markdown) and treated them as accomplishments. MILESTONES.md required full manual rewrite both times
- **CLI overwrote the manually-written v1.2-ROADMAP.md archive** — workaround was to back up to /tmp before running the CLI, then restore. Should be a documented step or the CLI should preserve user-authored archive files
- **Phase 15 PLAN files were never committed during execution** — discovered as untracked at v1.2 archive time. `git add` of the 5 PLAN files was needed before the milestone commit. Likely an executor-agent omission
- **REQUIREMENTS.md checkbox lag again** — same as v1.0 / v1.1; 25 INST-* + WORK-* boxes still `[ ]` at audit time despite VERIFICATION marking every one SATISFIED. Resolved at audit time but ideally these tick during execution
- **Phase 12, 13, 15 missing VALIDATION.md `wave_0_complete: true`** — Nyquist contracts not closed; tracked as post-hoc debt
- **Audit milestone surfaced shared-file taxonomy issue late** — discovered REQUIREMENTS.md was a v1.1+v1.2 combined file at v1.2 audit time, requiring the "do v1.1 first" plan. Earlier detection would have let v1.1 ship cleanly when its phases were marked Shipped in ROADMAP

### Patterns Established

- **`ProjectDataService::resolve()` as the only source for any install-* code path** — never read `extracted_data`, `reviewed_data`, `survey_data` directly. Repeats the v1.0 pattern with a sharper teeth: enforced in Phase 12 verification
- **Per-item AJAX endpoints for any field-page checklist** — `PATCH /commissioning-items/{item}/status`, `PATCH .../notes`, `POST .../photo`, `POST .../fail-with-evidence`. Pattern reusable for any future phase that lets engineers fill out items in spotty signal
- **`HeicImageConverter::writeAsJpeg()` is the single HEIC seam** — every photo upload service (`TaskPhotoService`, `CommissioningPhotoService`) delegates to it; never inline `imagick` calls
- **`DocumentArtifactStorage::TYPE_*` constants** — every new artifact type registers a constant (TYPE_RAMS, TYPE_OM, TYPE_WORKSHEET, TYPE_CABLE, TYPE_SNAGGING); excluded from `LEGACY_ROOTS` if there's no pre-H-07 history
- **Atomic transaction + lockForUpdate + canTransitionTo() guard for state-machine writes** — `CommissioningService::finalise()` pattern: `DB::transaction` → `lockForUpdate` → guard → write → commit. Future state transitions should mirror this
- **`{Phase}-CONTEXT.md` "Claude's Discretion" section** — when a phase ships an early sliver of a future requirement (e.g., Phase 14's INST-04g for Phase 15), record it explicitly in CONTEXT.md so the dependency chain is visible at audit time
- **Wave-0 RED test scaffold with Nyquist VALIDATION map** — Phases 14, 15, 16 each opened with a Plan -01 that wrote 16-22 RED tests + factories + a VALIDATION.md frontmatter. The pattern of "scaffold the assertions before writing production code" worked across all three; missing VALIDATION close-out is the wart

### Key Lessons

- **Split REQUIREMENTS.md per milestone before authoring next milestone's reqs** — combined files break the complete-milestone CLI's assumptions and force manual orchestration. Future projects should keep REQUIREMENTS.md per-milestone (`REQUIREMENTS-v1.3.md`) or add a separator the CLI understands
- **The complete-milestone CLI is unreliable for non-current-milestone archival AND for current-milestone accomplishment extraction** — for any archive cycle, expect to manually rewrite v{X}-ROADMAP.md (back up first if you wrote it before running CLI) and the MILESTONES.md entry
- **Tick REQUIREMENTS.md checkboxes during execution, not at audit** — three milestones, three audits with checkbox lag. Fix at the workflow level: add a hook in execute-phase that ticks the listed REQ-IDs from a plan's frontmatter when the SUMMARY.md is written
- **Untracked PLAN.md files at archive time signal an executor-agent gap** — Phase 15's 5 PLAN files were never committed during execution. Worth investigating whether the executor's commit step is missing PLAN files in some flow
- **Document scope reductions when they happen, not at archive** — v1.1 deferred phases 10/11 silently; v1.2 had no comparable scope cut but the milestone audit was the first moment the v1.1 reduction was recorded
- **Operational debt deserves a dedicated bucket in PROJECT.md** — both v1.1 (Postmark cutover) and v1.2 (deployment-gated UAT) shipped with operational rather than implementation debt. PROJECT.md needs to track these distinctly so they don't get confused with incomplete code
- **Wave-0 + VALIDATION.md is half the value if `wave_0_complete: true` never gets set** — the contract is authored, the tests exist, but the close-out flag never flips. Process gap, not a code gap

### Cost Observations

- Model mix: balanced profile (sonnet primary)
- Sessions: estimated 8-12 across the 10-day window (research → plan → execute × 5 phases → verify → audit/archive)
- Notable: Phase 16's research + UI design + signature spike + 87 green tests + iOS human-verify all completed in a single phase. The wave-based plan structure with explicit dependency declaration ("Wave 1 / Wave 2 / Wave 3") let parallel work happen safely
- Notable: integration-checker agent was efficient at this scale — both v1.1 and v1.2 audits ran the agent once each, no re-runs needed

---

## Cross-Milestone Trends

| Metric | v1.0 | v1.1 | v1.2 |
|--------|------|------|------|
| Phases scoped | 7 | 4 | 5 |
| Phases delivered | 7 | 2 (10/11 deferred) | 5 |
| Plans | 29 | 9 | 21 |
| Timeline (days) | 3 | 5 | 10 |
| Commits in dev range | 212 | 83 | 281 |
| `feat(...)` commits | n/a | n/a | 95 |
| Source LOC +/- | n/a | +24,441 / −12,814 | +45,200 / −13,579 |
| Worktree conflicts | 2 | 0 | 0 |
| REQUIREMENTS.md checkbox lag at audit | yes | yes (21 NOTF-*) | yes (25 INST-*/WORK-*) |
| Scope cuts mid-execution | 0 | 2 phases (10/11) | 0 |
| Operational debt at ship | 1 (DATA-04 partial) | 1 (Postmark cutover) | 2 (Phase 13/14 deployment-gated UAT) |
| VALIDATION.md `wave_0_complete: true` | n/a | 0/2 phases | 2/5 phases (14, 16) |
| Audit verdict | n/a (no audit step in v1.0) | tech_debt (no blockers) | tech_debt (no blockers) |
| CLI manual-fix needed at archive | n/a | yes (full MILESTONES + ROADMAP rewrite) | yes (same; back up ROADMAP first) |

---

## Recurring Themes

These show up in every milestone retrospective so far:

1. **REQUIREMENTS.md checkboxes lag execution** — three for three. Workflow-level fix needed (auto-tick from SUMMARY frontmatter, or per-plan hygiene gate before phase complete)
2. **Tooling assumes single-milestone REQUIREMENTS.md** — combined files (v1.1+v1.2) break complete-milestone CLI; v1.3+ should split before authoring
3. **CLI accomplishment auto-extraction is unreliable** — pull SUMMARY one-liners that aren't proper one-liners; produces garbage every time. Treat MILESTONES.md entry as a manual write step
4. **VALIDATION.md `wave_0_complete: false`** — Nyquist contracts authored at plan time but never closed during execution. Two phases out of seven across v1.1 + v1.2 actually closed it. Process gap
5. **Operational debt vs implementation debt** — both warrant distinct tracking. Currently lumped together as "tech_debt" in audit; PROJECT.md ought to separate them

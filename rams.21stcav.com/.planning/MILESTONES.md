# Milestones

## v1.2 Installation Programme & Field Management (Shipped: 2026-04-25)

**Phases completed:** 5 phases (12, 13, 14, 15, 16), 21 plans
**Timeline:** 2026-04-13 → 2026-04-23 (10 days)
**Total commits in dev range:** 281 (`c0b37da..a92503c`); 95 `feat(...)` commits
**Source LOC delta:** +45,200 / −13,579 across 348 source files
**Audit:** [`milestones/v1.2-MILESTONE-AUDIT.md`](milestones/v1.2-MILESTONE-AUDIT.md) — `tech_debt`, 48/48 satisfied, 11/11 wiring + 5/5 E2E flows PASS

**Key accomplishments:**

1. Auto-generated install programmes from canonical project data — `InstallTaskGeneratorService` reads from `ProjectDataService::resolve()` (never `extracted_data` directly); per room × equipment item; PM confirm gate before activation; re-generation archives prior programme
2. Engineer assignment + scheduling — bulk + per-task assignment; `assigned_user_id` FK; planned start/end dates; week-view calendar; conditional Gantt (frappe-gantt) when programme spans >4 days; engineer-only field-view filter
3. Mobile field view — `/projects/{project}/programme` thumb-friendly route; tap-to-advance status with 400ms ring-green pulse; per-task photo capture with iOS HEIC server-side conversion to JPEG (`HeicImageConverter::writeAsJpeg()`); per-task notes blur-saved; sticky bar + clock chip + bottom tab-bar; online-only (no PWA)
4. Time tracking — category-tagged `time_entries` (Installation / Commissioning / Testing / Other); 60s silent heartbeat; `programme:close-stale-sessions` cron (2-hour safety net); retro-edit with atomic `time_entry_audits` row; owner/admin-only Actual Hours widget on project dashboard (per-category breakdown, no chart library)
5. Commissioning checklist + client sign-off — `commissioning_items` per equipment × AVIXA category (7 categories); per-item AJAX save (no full-form POST — 4 distinct PATCH/POST routes); HEIC photo evidence per item; `creagia/laravel-sign-pad` client signature with `devicePixelRatio` scaling for iOS Retina (human-verified); snagging PDF via DomPDF with embedded signature; state machine advance INSTALLING → COMMISSIONING via guard
6. Worksheet DOCX integration — pre-install check answers per room (Section E); dashboard generation trigger button (WORK-05 + WORK-06)

**Known gaps at ship:**

- VALIDATION.md `wave_0_complete: false` for phases 12, 13, 15 (Nyquist contracts not closed; `/gsd-validate-phase` available post-hoc)
- Phase 13 outstanding human UAT — Gantt rendering + slide-over click + role-based filter (frappe-gantt is bundled but browser-based confirmation pending)
- Phase 14 outstanding human UAT — iOS HEIC end-to-end upload (blocked on deploying app to a web-accessible environment for real-iPhone test)
- Phase 15 REVIEW WR-01 — TOCTOU race in `TimeEntryService::closeStaleSessions` (stale-close safety net still works; tracked for post-phase quick fix)
- Phase 15 REVIEW WR-02 + IN-01..IN-06 — exception-message hygiene + 6 cosmetic items (dead clock.error state, duplicated category list, redundant ternary, etc.)
- Budget vs actual hours comparison deferred (labour source decision required — QuoteWerks labour lines vs manual entry)
- Drag-to-reschedule on Gantt deferred (read-only timeline only in v1.2)

---

## v1.1 Operations Dashboard & Notifications (Shipped: 2026-04-25)

**Phases completed:** 2 phases (08, 09), 9 plans
**Timeline:** 2026-04-14 → 2026-04-19 (5 days)
**Total commits in dev range:** 83 (`96722e0..c8576c5`)
**Source LOC delta:** +24,441 / −12,814 across 203 source files
**Audit:** [`milestones/v1.1-MILESTONE-AUDIT.md`](milestones/v1.1-MILESTONE-AUDIT.md) — `tech_debt`, 34/34 satisfied, 14/14 wiring + 6/6 E2E flows PASS

**Key accomplishments:**

1. Real-time project health command centre — `DashboardController` + `ProjectHealthService` + `ProjectHealth` DTO with priority-ordered red/amber/green derivation rules, status-summary chips, install-programme task-completion widget; Alpine.js client-side filter (no page reload, URL hash for bookmarkability)
2. Six queued email mailables (`RamsReadyMail`, `OmManualReadyMail`, `WorksheetReadyMail`, `CableScheduleReadyMail`, `RamsReviewNeededMail`, `DocumentGenerationFailedMail`) — all `implements ShouldQueue`; document attachments resolved via `DocumentArtifactStorage::readPath()`
3. Idempotency-first dispatch — 9 timestamp columns set BEFORE send to prevent retry-driven double-sends; locked by 24 notification feature tests + dedicated Idempotency + Bcc regression tests
4. `NotificationRecipientResolver` service — single source of truth for owner-with-admin-fallback recipient resolution; eliminated `is_admin` boolean drift across the codebase (migrated to `User::where('role', 'admin')`)
5. Postmark transport packaged + `RAMS_NOTIFICATION_BCC` global BCC config; production cutover gated by `POSTMARK-OPS-CHECKLIST.md` runbook (DNS, sender signature, first-send confirmation)
6. Public survey path refactored to use `NotificationRecipientResolver` without regression; latent `Project::with('user')` and `is_admin` bugs in `SurveyService::submitPublic` removed; locked by extended `PublicSurveyControllerTest`

**Scope reduction:**

- Originally scoped as 4 phases (08–11); only 08 and 09 were executed. Phases 10 (Bitrix24/CRM push) and 11 (multi-channel notifications) were not built and were deferred to a future milestone.

**Known gaps at ship (operational, not implementation):**

- **NOTF-05g** production Postmark cutover deferred — DNS records (SPF/DKIM/DMARC), `POSTMARK_API_KEY` in production `.env`, sender signature verification, first-send confirmation. Runbook archived at `milestones/v1.1-phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md`
- Dev log-mailer end-to-end smoke (manual queue-worker run against `MAIL_MAILER=log`)
- VALIDATION.md `wave_0_complete: false` for both phases (Nyquist contracts not closed; `/gsd-validate-phase` available post-hoc)
- Phase 09 REVIEW WR-01..WR-04 polish items: error-truncation pattern inconsistencies + narrow `failed()` try/catch gap that could drop a failure alert on transient DB error

---

## v1.0 RAMS MVP (Shipped: 2026-04-12)

**Phases completed:** 7 phases, 29 plans, 42 tasks
**Timeline:** 2026-04-09 → 2026-04-12 (3 days)
**Total commits:** 212

**Key accomplishments:**

1. Built `Project` model with full lifecycle state machine (quote_imported → archived) as top-level entity linking all platform records
2. Implemented `ProjectDataService` — canonical 4-tier data merge (reviewed_data > survey_data > quotewerks_sql > extracted_data) powering all document generators
3. QuoteWerks SQL import pipeline via direct MS SQL read-only connection (QuoteWerksRepository + QuoteWerksImportService)
4. Three document generators: Worksheet (DOCX), O&M Manual (DOCX), Cable Schedule (XLSX) — all queue-based, all driven from ProjectDataService
5. AI content pack — single Claude call generates room-by-room scope narratives and method statement improvements for RAMS
6. Dynamic site survey AI room questions — per-room pre-install checks generated by AI based on solution type, with yes/no/other answer UI and completion gate enforcement

**Known gaps at ship:**

- REQUIREMENTS.md checkboxes were not maintained during execution — actual delivery covers all v1 requirements
- DATA-04 confidence scoring (source annotation per field) was designed but partial implementation — soft columns only, no UI

---

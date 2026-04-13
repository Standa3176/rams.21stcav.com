# Roadmap: RAMS Platform — AV Operations System

## Milestones

- ✅ **v1.0 RAMS MVP** — Phases 01–07 (shipped 2026-04-12)
- 📋 **v1.1 Operations Dashboard & Notifications** — Phases 08–11 (planned)
- 📋 **v1.2 Installation Programme & Field Management** — Phases 12–16 (planned)
- 📋 **v1.3 Technical Drawings & Schematics** — Phases 17–20 (planned)
- 📋 **v1.4 Client Portal & Project Visibility** — Phases 21–24 (planned)
- 📋 **v1.5 Financial & Proposal Engine** — Phases 25–28 (planned)
- 📋 **v1.6 Service & Inventory** — Phases 29–32 (planned)

## Phases

<details>
<summary>✅ v1.0 RAMS MVP (Phases 01–07) — SHIPPED 2026-04-12</summary>

- [x] Phase 01: Project Layer & Data Foundation (6/6 plans) — completed 2026-04-10
- [x] Phase 02: QuoteWerks SQL Import (3/3 plans) — completed 2026-04-10
- [x] Phase 03: Survey Data Integration (4/4 plans) — completed 2026-04-11
- [x] Phase 04: Document Generators — Worksheets, O&M, Cable Schedules (4/4 plans) — completed 2026-04-11
- [x] Phase 05: Project Content Pack — Single AI Call Scope Generation (4/4 plans) — completed 2026-04-11
- [x] Phase 06: RAMS Document Quality (2/2 plans) — completed 2026-04-12
- [x] Phase 07: Dynamic Site Survey AI-Generated Room Questions (6/6 plans) — completed 2026-04-12

Full archive: [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md)

</details>

### v1.1 Operations Dashboard & Notifications
*"See everything, miss nothing"*

- [ ] Phase 08: Enterprise Dashboard — Real-time project status, health indicators, overdue/blocked alerts across all active projects
- [ ] Phase 09: Email Notifications — Generation complete, survey submitted, review needed triggers
- [ ] Phase 10: Document Quality Scores — Confidence indicators and data completeness per room/equipment
- [ ] Phase 11: Bitrix24 Integration — OAuth connection, project sync, document links, task updates

### v1.2 Installation Programme & Field Management
*"Quote to field in one platform"*

- [ ] Phase 12: Install Task Generation — Auto-generate task list (room × equipment) from ProjectDataService; install_programmes + install_tasks models; WORK-05/06 worksheet enhancements
- [ ] Phase 13: Task Assignment & Scheduling — Engineer assignment, planned dates, week-view calendar; conditional Gantt (frappe-gantt) only when project duration > 4 days
- [ ] Phase 14: Mobile Field View — Responsive task checklist, status toggle, per-task photo capture (HEIC protection), clock in/out; online-only
- [ ] Phase 15: Time Tracking — Actual labour hours per project/category; heartbeat-guarded sessions; UTC storage; actuals-only (no budget comparison in v1.2)
- [ ] Phase 16: Commissioning Checklist — Per-equipment AVIXA-category sign-off; per-item AJAX saves; client signature (creagia/laravel-sign-pad, DPI-corrected); snagging PDF; programme completion → project state advance

### v1.3 Technical Drawings & Schematics
*"AI-powered visuals from the same dataset"*

- [ ] Phase 17: System Schematics — Auto-generate signal flow diagrams from equipment and cable schedule data
- [ ] Phase 18: Rack Elevations — Generate rack layouts from equipment lists with U-height and ventilation data
- [ ] Phase 19: Floor Plans — Upload building layout, auto-place equipment per room with logical positioning
- [ ] Phase 20: Drawing Export — PDF immediate download, DWG export for CAD tools (AutoCAD/Vectorworks)

### v1.4 Client Portal & Project Visibility
*"Clients see what they need, when they need it"*

- [ ] Phase 21: Client Portal — Branded project status page per client/site with secure access
- [ ] Phase 22: Document Access — Clients download RAMS, O&M, drawings and certificates from portal
- [ ] Phase 23: Survey & Installation Progress — Live completion percentages per room visible to client
- [ ] Phase 24: Notification & Communication — Client receives updates on project milestones and document availability

### v1.5 Financial & Proposal Engine
*"From pricing rules to signed proposal"*

- [ ] Phase 25: Pricing Engine — Multiplier-based config (HW value x multiplier with min/max), admin+sales accessible
- [ ] Phase 26: Proposal Generator — New client + renewal flows, PDF/DOCX branded output
- [ ] Phase 27: Budget Tracking — Project cost monitoring, margin alerts, forecast vs actual
- [ ] Phase 28: Renewal Workflow — Auto-populate from existing contract hardware, year-on-year escalation

### v1.6 Service & Inventory
*"Post-install lifecycle"*

- [ ] Phase 29: Asset Registry — Track installed equipment as live assets with QR codes per item
- [ ] Phase 30: Service Tickets — Contract search, room/asset select, auto-fill site/contact, callback scheduling
- [ ] Phase 31: PMV Checklists — Per-equipment-type maintenance checks with fault diagnosis and sign-off
- [ ] Phase 32: AI Troubleshooting — QR scan triggers AI-guided device-specific troubleshooting workflow

## Progress

| Phase | Milestone | Plans | Status | Completed |
|-------|-----------|-------|--------|-----------|
| 01. Project Layer & Data Foundation | v1.0 | 6/6 | Complete | 2026-04-10 |
| 02. QuoteWerks SQL Import | v1.0 | 3/3 | Complete | 2026-04-10 |
| 03. Survey Data Integration | v1.0 | 4/4 | Complete | 2026-04-11 |
| 04. Document Generators | v1.0 | 4/4 | Complete | 2026-04-11 |
| 05. Project Content Pack | v1.0 | 4/4 | Complete | 2026-04-11 |
| 06. RAMS Document Quality | v1.0 | 2/2 | Complete | 2026-04-12 |
| 07. Dynamic Survey AI Questions | v1.0 | 6/6 | Complete | 2026-04-12 |
| 08. Enterprise Dashboard | v1.1 | — | Planned | — |
| 09. Email Notifications | v1.1 | — | Planned | — |
| 10. Document Quality Scores | v1.1 | — | Planned | — |
| 11. Bitrix24 Integration | v1.1 | — | Planned | — |
| 12. Install Task Generation + Worksheet Enhancements | v1.2 | — | Planned | — |
| 13. Task Assignment & Scheduling | v1.2 | — | Planned | — |
| 14. Mobile Field View & Time Tracking | v1.2 | — | Planned | — |
| 15. Time Tracking | v1.2 | — | Planned | — |
| 16. Commissioning Checklist & Sign-off | v1.2 | — | Planned | — |
| 17. System Schematics | v1.3 | — | Planned | — |
| 18. Rack Elevations | v1.3 | — | Planned | — |
| 19. Floor Plans | v1.3 | — | Planned | — |
| 20. Drawing Export | v1.3 | — | Planned | — |
| 21. Client Portal | v1.4 | — | Planned | — |
| 22. Document Access | v1.4 | — | Planned | — |
| 23. Survey & Installation Progress | v1.4 | — | Planned | — |
| 24. Notification & Communication | v1.4 | — | Planned | — |
| 25. Pricing Engine | v1.5 | — | Planned | — |
| 26. Proposal Generator | v1.5 | — | Planned | — |
| 27. Budget Tracking | v1.5 | — | Planned | — |
| 28. Renewal Workflow | v1.5 | — | Planned | — |
| 29. Asset Registry | v1.6 | — | Planned | — |
| 30. Service Tickets | v1.6 | — | Planned | — |
| 31. PMV Checklists | v1.6 | — | Planned | — |
| 32. AI Troubleshooting | v1.6 | — | Planned | — |

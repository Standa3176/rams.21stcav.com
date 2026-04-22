---
milestone: v1.2
milestone_name: Installation Programme & Field Management
last_updated: "2026-04-22"
---

# Roadmap

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-13)

**Current milestone:** v1.2 — Installation Programme & Field Management

## Roadmap Overview

| Milestone | Theme | Phases | Status |
|-----------|-------|--------|--------|
| v1.0 | RAMS MVP | 01–07 | ✅ Shipped |
| v1.1 | Operations Dashboard & Notifications | 08–11 | ✅ Shipped |
| v1.2 | Installation Programme & Field Management | 12–16 | 🚧 In progress |
| v1.3 | Technical Drawings & Schematics | 17–20 | 📋 Planned |
| v1.4 | Client Portal & Project Visibility | 21–24 | 📋 Planned |
| v1.5 | Financial & Proposal Engine | 25–28 | 📋 Planned |
| v1.6 | Service & Inventory | 29–32 | 📋 Planned |

---

## v1.2 Installation Programme & Field Management
*"From commissioned install plan to signed-off handover"*

### Phase 12: Install Task Generation
**Goal**: Generate structured install_tasks from reviewed project data grouped by room.
**Status**: ✅ Complete

### Phase 13: Task Assignment & Scheduling
**Goal**: Assign install_tasks to engineers with scheduling, dependency, and capacity visibility.
**Status**: ✅ Complete

### Phase 14: Mobile Field View
**Goal**: Engineer-facing mobile field view with per-task photo evidence, notes, HEIC conversion, and one-open-time-entry guard.
**Status**: ✅ Complete

### Phase 15: Time Tracking
**Goal**: Clock in / clock out time_entries with category, heartbeat, stale-session recovery, retro-edit, and actual-hours widget.
**Status**: ✅ Complete

### Phase 16: Commissioning Checklist & Sign-off
**Goal**: Per-equipment commissioning checklist with AVIXA categories, per-item photo evidence, and client digital signature. Completing the checklist generates a snagging PDF and advances project to Commissioning state.
**Depends on**: Phase 14
**Requirements**: INST-05, INST-05a, INST-05b, INST-05c, INST-05d, INST-05e, INST-05f, INST-05g, INST-05h, INST-05i
**Success Criteria** (what must be TRUE):
  1. `commissioning_items` table has all columns from REQUIREMENTS.md
  2. Each item status update is saved via a separate AJAX request (no full-form POST)
  3. Uploading a HEIC photo for a commissioning item stores it as JPEG
  4. Client signature canvas renders at correct DPI on iOS Retina (devicePixelRatio scaling applied)
  5. "Complete Commissioning" button is disabled until all items are pass/fail/na
  6. Generating the snagging PDF produces a downloadable file embedding the signature image
  7. On programme completion, `Project.status` advances to `STATUS_COMMISSIONING` via state machine
**Plans**: 5 plans
Plans:
- [x] 16-01-PLAN.md — Wave 0 test scaffold (22 tests + 2 factories + VALIDATION map; Nyquist red baseline)
- [ ] 16-02-PLAN.md — Scaffold: composer require creagia/laravel-sign-pad + DPI spike + config/commissioning.php + 2 migrations + 2 models + exception + generator + sync service + InstallTaskObserver + DocumentArtifactStorage::TYPE_SNAGGING (Wave 1)
- [ ] 16-03-PLAN.md — Checklist UI + per-item AJAX: CommissioningController + CommissioningItemController + 3 FormRequests + CommissioningPhotoService + show/item-row/fail-sheet Blade views + 5 routes (Wave 2)
- [ ] 16-04-PLAN.md — Snagging PDF + signoff finalisation: CommissioningPdfService + CommissioningService + CommissioningSignoffController + FinaliseRequest + PDF Blade + 3 routes + D-16 atomic transaction + state-machine guard (Wave 2, parallel to 16-03)
- [ ] 16-05-PLAN.md — Signature canvas + Re-sync UI + checkpoint: signoff-sheet Blade with DPI scaling + resync-diff partial + CommissioningResyncController + iOS Retina human-verify (Wave 3)

---

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

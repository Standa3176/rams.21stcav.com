# Technology Stack — v1.2 Installation Programme & Field Management

**Project:** RAMS Platform (rams.21stcav.com)
**Researched:** 2026-04-13
**Scope:** Additions ONLY for v1.2. Existing stack (Laravel 12, PHP 8.2+, MySQL, Alpine.js, Tailwind,
PHPWord, DomPDF, mPDF, PhpSpreadsheet) is validated and must not be re-researched or changed.

---

## What the Existing Stack Already Covers (Reuse Without Change)

| v1.2 Capability | Existing Mechanism — Reuse As-Is |
|---|---|
| Photo capture from mobile browser | `<input type="file" accept="image/*" capture="environment">` on HTML file input — triggers device camera natively on iOS Safari and Android Chrome. Already proven in site survey photo upload. No new library needed. |
| File storage for commissioning photos | `storage/app/private/` + `Storage::disk('local')->put()` — identical to survey photo pattern already shipped. |
| Auth and engineer identity | Laravel Breeze users table. Engineers are already users. Task assignment = foreign key to `users.id`. No auth changes. |
| Queue-based job dispatch | Database queue driver, `GenerateInstallTasksJob` follows `BuildRamsDocumentJob` pattern exactly. |
| DOCX for programme documents | `phpoffice/phpword ^1.4` — already installed. Any generated install programme report reuses `DocxBuilderService`. |
| PDF for sign-off sheets | `barryvdh/laravel-dompdf ^3.1` — already installed. Embeds base64 PNG signature via `<img src="data:image/png;base64,...">` — DomPDF supports inline data URIs. |
| Project data as task input | `ProjectDataService` 4-tier canonical merge — `InstallTaskGeneratorService` reads rooms × equipment exactly as `WorksheetBuilderService` does. |
| Budget vs actual time | Plain SQL `SUM()` aggregate over a `time_entries` table — no package. |
| Calendar/schedule UI (simple) | Blade + Alpine.js for basic date display. Gantt view requires one new JS library (see below). |

---

## New Additions Required

### JS Dependencies

| Library | Version | Install | Purpose | Why This One |
|---|---|---|---|---|
| `signature_pad` | `^5.1.3` | `npm install signature_pad` | HTML5 canvas signature capture for commissioning sign-off (INST-05) | Zero external dependencies. Works on iOS Safari and Android Chrome touch and stylus input. Outputs base64 PNG or SVG. Actively maintained — v5.1.3 published December 2025. No Composer counterpart needed: store the base64 string in a MySQL TEXT column, embed in DomPDF as a data URI. |
| `frappe-gantt` | `^1.2.2` | `npm install frappe-gantt` | Gantt/calendar timeline for task scheduling (INST-02) | MIT licence (DHTMLX Gantt GPL is incompatible with commercial internal use). Vanilla JS, zero dependencies, ~50 kB minified. Works directly in a Blade partial via an Alpine.js `x-init` wrapper — no React or Vue required. v1.0 released after a year of stabilisation; v1.2.2 is the current stable release (March 2026). Use for read + drag-to-reschedule only. |
| `dexie` | `^4.4.2` | `npm install dexie` | IndexedDB wrapper for offline checklist state (INST-03) | The standard IndexedDB abstraction in 2026 — 100k+ dependent projects on npm, v4.4.2 published April 2026. Provides a clean async/Promise API over raw IndexedDB. Needed so engineers can tick checklist items offline (no mobile data). Dexie persists state to IndexedDB; Alpine.js `$store` reads from it for reactive display. On reconnect, Alpine detects `navigator.onLine` and Axios POSTs the pending queue to a `/field/sync` endpoint. |
| `vite-plugin-pwa` | `^1.1.0` | `npm install -D vite-plugin-pwa` | Service worker + web manifest for offline-capable field view (INST-03) | Zero-config Workbox-backed PWA for Vite. Required for the field view to load at all when an engineer has no mobile data — without a registered service worker, the HTML, CSS, and JS bundle will not be available offline. Plugs into `vite.config.js` alongside the existing `laravel-vite-plugin`. v1.1.0 is the current release (2025). Scope the service worker to `/field/*` routes only — do not make the entire RAMS platform offline-capable. |

**Full install command:**
```bash
npm install signature_pad frappe-gantt dexie
npm install -D vite-plugin-pwa
```

### Composer Dependencies

**No new Composer packages are needed for v1.2.**

| v1.2 Feature | Backend Implementation | Package Needed? |
|---|---|---|
| Task list generation (INST-01) | `InstallTaskGeneratorService` reads `ProjectDataService` output, writes to `install_tasks` table | None — plain Eloquent |
| Engineer assignment (INST-02) | Foreign key `install_tasks.assigned_user_id -> users.id` | None — existing users |
| Clock in/out time tracking (INST-04) | `time_entries` table (`project_id`, `user_id`, `clocked_in_at`, `clocked_out_at`, `category`) with `TimeTrackingService` | None — plain timestamps |
| Budget vs actual (INST-04) | `TimeTrackingService::getBudgetComparison()` — SQL `SUM(TIMESTAMPDIFF(...))` grouped by category | None |
| Commissioning sign-off storage (INST-05) | `commissioning_sign_offs` table with `signature_data TEXT` column | None |
| Sign-off PDF generation (INST-05) | `barryvdh/laravel-dompdf` already installed — embed base64 PNG as data URI | None — already installed |
| Offline sync endpoint (INST-03) | Standard Laravel controller + JSON batch — no real-time infrastructure | None |
| Worksheet pre-install answers (WORK-05) | Extend existing `WorksheetBuilderService` to read `survey_rooms.pre_install_answers` | None |

### Server-Level Requirements

| Requirement | Status | Notes |
|---|---|---|
| HTTPS | Required — not new | Service workers are blocked by browsers on HTTP. The production server must already serve over HTTPS for auth to be secure. No new infrastructure. |
| `storage/app/private/` writable | Already met | Commissioning photos use the same private storage path as survey photos. No new disk config. |
| Queue worker | Already running | Task generation job reuses the existing `database` queue driver and worker process. No new queue infrastructure. |
| No new PHP extensions | Confirmed | No OCR, no binary conversion, no new system packages required for v1.2. |

---

## Architecture Integration Points

### Offline field view — scope boundary (critical)

The offline capability applies to ONE route prefix only: `/field/{project}/*` (the engineer mobile checklist view). It does NOT make the entire RAMS admin platform offline-capable.

`vite.config.js` configuration:

```js
VitePWA({
    strategies: 'generateSW',
    registerType: 'autoUpdate',
    workbox: {
        navigateFallback: '/field/offline',
        navigateFallbackAllowlist: [/^\/field/],  // scope to /field/* only
        runtimeCaching: [
            {
                urlPattern: /^\/field\/.*\/sync/,
                handler: 'NetworkOnly',  // sync endpoint must never be cached
            },
        ],
    },
    manifest: {
        name: '21CAV Field View',
        short_name: 'Field',
        start_url: '/field',
        display: 'standalone',
    },
})
```

Failing to scope the service worker to `/field/*` would cause stale-cache issues for the admin dashboard and document generation pipeline.

### Offline write-back pattern

```
Engineer opens /field/{project}/tasks
  → app shell and task data cached by service worker
  → Engineer ticks items while offline
  → Dexie writes ticked state to IndexedDB (key: task ID)
  → Alpine.js watches navigator.onLine
  → On reconnect: reads pending Dexie queue, POSTs to /field/{project}/sync via Axios
  → Laravel controller processes batch, updates install_task_progress table
  → Dexie clears pending queue
```

This avoids a full sync framework (no RxDB, no Laravel Echo, no Livewire, no broadcast channels — none are warranted at this scale and internal-tooling context).

### Signature capture flow

1. Commissioning Blade page loads, Alpine.js mounts `signature_pad` on a `<canvas>` element via `x-init`.
2. On form submit, Alpine serialises canvas to base64 PNG: `pad.toDataURL('image/png')`.
3. Hidden input carries the base64 string in a standard form POST.
4. Laravel controller validates (non-empty string, starts with `data:image/png;base64,`) and stores in `commissioning_sign_offs.signature_data TEXT`.
5. A queued job generates the sign-off PDF via DomPDF, embedding the data URI: `<img src="{{ $signatureData }}" style="max-width: 300px;">` — DomPDF supports inline data URIs without additional packages.

### Gantt chart integration

Frappe Gantt is a vanilla JS library with no build-time requirements beyond a standard `npm install`. Import as an ES module via Vite. Task data is injected server-side as a JSON Blade variable to avoid a separate API call on page load:

```blade
<div x-data="ganttView()" x-init="init()">
    <div id="gantt-container"></div>
</div>
<script>
    const rawTasks = @json($tasks);  // server-rendered
    function ganttView() {
        return {
            init() {
                const gantt = new Gantt('#gantt-container', rawTasks, {
                    on_date_change: (task, start, end) => {
                        axios.patch(`/install-tasks/${task.id}`, { start, end });
                    },
                    view_mode: 'Week',
                });
            }
        }
    }
</script>
```

Date changes on drag emit an Axios PATCH to the task resource endpoint.

### Task generation (INST-01) — no new package

`InstallTaskGeneratorService::generateForProject(Project $project): void`

1. Calls `ProjectDataService::getCanonicalData($project)` — existing 4-tier merge.
2. Iterates rooms × equipment items.
3. Inserts into `install_tasks` table (project_id, room, equipment_item, category, status, estimated_minutes).
4. Dispatched as `GenerateInstallTasksJob` from the project dashboard controller — same pattern as `BuildRamsDocumentJob`.

---

## What NOT to Add

| Rejected Option | Reason |
|---|---|
| Livewire | Adds a second reactive framework alongside Alpine.js. Unjustified complexity for forms and checklists that Alpine already handles well. |
| Laravel Echo / Pusher / Reverb | Real-time multi-user is explicitly out of scope (PROJECT.md). No websocket infrastructure needed. |
| Full-app PWA (all routes) | Only `/field/*` needs offline capability. Service worker scoped globally would cache stale AI-generated data and admin views unintentionally. |
| NativePHP or Capacitor | Native mobile app is explicitly out of scope (PROJECT.md). Mobile browser is the target. |
| DHTMLX Gantt | GPL licence — incompatible with commercial internal tooling without a paid licence. Frappe Gantt is MIT. |
| React or Vue | No SPA framework warranted. Existing Blade + Alpine.js is sufficient. Adding a second reactive framework fragments the codebase. |
| `creagia/laravel-sign-pad` (Composer) | Wraps `signature_pad` with Blade components and optional OpenSSL PDF certification. The overhead is not justified — the raw `signature_pad` npm package wires cleanly with Alpine.js and DomPDF handles PDF embedding already. |
| `maatwebsite/excel` | Not needed — time tracking aggregates and task exports are simple enough for direct Eloquent queries + PhpSpreadsheet (already installed). |
| Any real-time broadcast / WebSockets | Explicitly out of scope. Clock in/out and task updates are sequential single-user actions — a standard HTTP POST per event is correct. |
| Laravel Sanctum or Passport tokens for mobile | Engineers authenticate via the existing session-based Breeze auth — the mobile field view is a server-rendered Blade page, not a separate mobile API client. No token-based auth needed. |

---

## Confidence Assessment

| Area | Level | Basis |
|---|---|---|
| `signature_pad` | HIGH | npm v5.1.3 confirmed, published December 2025. GitHub active. Mobile touch compatibility documented. Canvas → base64 → DomPDF data URI path is a proven integration pattern. |
| `frappe-gantt` | HIGH | npm v1.2.2, published March 2026. MIT licence confirmed on GitHub. Vanilla JS confirmed — no framework dependency. v1.0 stability milestone reached. |
| `dexie` | HIGH | npm v4.4.2, published April 2026. 100k+ dependent projects. IndexedDB + Alpine.js integration documented with community examples. |
| `vite-plugin-pwa` | HIGH | npm v1.1.0 confirmed. Compatible with `laravel-vite-plugin` — community-confirmed pattern on Laracasts. Workbox integration documented. |
| No new Composer packages | HIGH | All v1.2 backend features map to existing Laravel patterns + already-installed packages. Verified by mapping each feature to existing code. |
| Mobile photo capture without new library | HIGH | HTML `capture="environment"` is a W3C standard. Proven by existing survey photo upload in this codebase. No new code needed. |
| Offline scope boundary configuration | MEDIUM | `vite-plugin-pwa` with `navigateFallbackAllowlist` for route scoping is documented but requires careful configuration. Needs implementation attention — incorrectly scoped service workers cause hard-to-debug caching bugs. |

---

## Sources

- signature_pad npm: https://www.npmjs.com/package/signature_pad
- signature_pad GitHub: https://github.com/szimek/signature_pad
- frappe-gantt npm: https://www.npmjs.com/package/frappe-gantt
- frappe-gantt v1 release: https://frappe.io/blog/product-updates/gantt-v1-is-out
- Best JS Gantt libraries 2026: https://dhtmlx.com/blog/top-8-javascript-gantt-chart-libraries-2026/
- dexie npm: https://www.npmjs.com/package/dexie
- dexie GitHub: https://github.com/dexie/Dexie.js
- IndexedDB with Alpine.js (community example): https://www.raymondcamden.com/2023/11/26/using-indexeddb-with-alpinejs
- Offline-first frontend 2025: https://blog.logrocket.com/offline-first-frontend-apps-2025-indexeddb-sqlite/
- vite-plugin-pwa npm: https://www.npmjs.com/package/vite-plugin-pwa
- vite-plugin-pwa docs: https://vite-pwa-org.netlify.app/
- HTML capture attribute: https://www.amitmerchant.com/capturing-images-and-videos-from-the-camera-of-mobile-devices-using-html/

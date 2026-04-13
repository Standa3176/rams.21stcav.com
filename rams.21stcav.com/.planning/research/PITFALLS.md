# Domain Pitfalls — v1.2 Installation Programme & Field Management

**Project:** RAMS Platform — 21st Century AV
**Researched:** 2026-04-13
**Scope:** Adding task management, mobile field features, and time tracking to existing Laravel 12 / Blade / Alpine.js platform
**Confidence:** HIGH for iOS/timezone/canvas issues (documented library bugs); MEDIUM for task-generation and scheduling patterns (field service community evidence)

---

## Critical Pitfalls

Mistakes that cause rewrites, data corruption, or client-facing failures.

---

### Pitfall C1: Task Generation From Wrong Data Tier

**What goes wrong:** Auto-generated install tasks are derived from `extracted_data` (AI-parsed PDF output) or the raw `equipment_list` column rather than from `reviewed_data` (post-engineering-review). The quote data reflects what was commercially offered — not what will be physically installed. Substitutions, provisional items, and speculative quantities all live in `extracted_data`.

**Why it happens:** `extracted_data` is the most accessible field on `ProjectPackage` and is tempting to use directly. The `ProjectDataService` 4-tier merge exists precisely to handle this, but developers unfamiliar with the system bypass it for simplicity.

**Consequences:** Engineers arrive on site with tasks for items that won't be installed, or missing tasks for site-added items. Commissioning checklists are built against the wrong equipment list. Client sign-off is obtained against incorrect scope. This directly contradicts the platform's core value: "no AI guessing — every output is driven by structured project data."

**Prevention:**
- Generate tasks only via `ProjectDataService` (which enforces `reviewed_data > survey_data > quotewerks_sql > extracted_data` priority) — never directly from raw package fields
- Add a human "confirm task list" gate before tasks become active — analogous to the existing RAMS review gate (`STATUS_AWAITING_REVIEW` pattern on `RamsDocument`)
- Lock task generation to projects in `STATUS_INSTALLING` or later — engineering review must have completed first
- Allow engineers to mark tasks "not applicable" with a required reason, which creates an audit record on the project

**Detection:** Task count diverges from `SiteSurveyRoom` equipment counts. Engineers frequently flag tasks as N/A on first site visit. Commissioning completion rate falls below 80% on first pass.

**Phase risk:** INST-01 (task generation). This is the highest-risk design decision of the entire milestone.

---

### Pitfall C2: iOS HEIC Photo Uploads Stored But Unrenderable

**What goes wrong:** iPhones default to HEIC format. When an engineer uploads a photo from the mobile field view, the file may arrive as `image/heic` or `image/heif`. PHP's GD driver (the default) cannot process HEIC. The upload succeeds — the file is stored — but any subsequent thumbnail generation, display, or PDF embedding silently fails.

**Why it happens:** iOS Safari will respect `accept="image/*"` and still upload HEIC files. The MIME type reported in the Content-Type header is unreliable — iOS sometimes reports `image/jpeg` even when the file is HEIC. Server-side validation that trusts the Content-Type header will pass HEIC files through.

Safari *will* convert to JPEG when `accept="image/jpeg"` is specified explicitly, but only for camera-captured photos — not for files selected from the photo library. This distinction is critical for a field app where engineers may select from gallery.

**Consequences:** Evidence photo exists on disk. The commissioning sign-off page shows a broken image. PDF evidence pack fails to embed the photo. The failure is silent — engineers don't know the photo is unusable until a manager reviews it later.

**Prevention:**
- Set `accept="image/jpeg,image/png,image/webp"` on all file inputs (not `image/*`) — this forces iOS camera captures to convert, and warns users selecting HEIC from gallery
- Add server-side MIME detection using PHP `finfo_file()` (not the Content-Type header) in the upload controller
- Reject HEIC at the controller with a clear error message: "Please upload a JPEG or PNG photo"
- Do not use Intervention/Image v2 for any photo processing in this milestone — it does not support HEIC and will throw on any processing attempt. If image resizing is needed, use the programmatic GD functions directly or upgrade to Intervention/Image v3 with Imagick driver

**Detection:** File extension `.heic` or `.HEIC` in storage. `finfo_file()` returns `image/heic`. GD processing throws `RuntimeException`.

**Phase risk:** INST-03 (mobile field view). Must be addressed in the upload handler before any beta testing on iOS.

---

### Pitfall C3: Unclosed Clock-In Sessions After Browser Closure

**What goes wrong:** Engineer clocks in on mobile. Browser tab is closed, phone screen locks, or network drops. The `time_entries` record has `clocked_in_at` but no `clocked_out_at`. The session is permanently open. The next clock-in creates a duplicate open session. Budget vs actual calculations become wildly inaccurate. Engineers are blocked from clocking in again.

**Why it happens:** `beforeunload` and `pagehide` browser events are unreliable on iOS Safari — they do not fire reliably when a tab is backgrounded or the browser crashes. Relying on client-side signals to close sessions is fundamentally fragile in a mobile field context where signal is intermittent and screen locks are frequent.

**Consequences:** Inflated actual hours. Engineers unable to clock in. Managers see meaningless time data. Budget vs actual comparison produces misleading reports.

**Prevention:**
- Never rely on `beforeunload` for session close
- Implement a `last_heartbeat_at` timestamp column on `time_entries`, updated every 2 minutes via a lightweight Alpine.js `setInterval` AJAX ping
- A scheduled artisan command (via `php artisan schedule:run`) auto-closes sessions where `last_heartbeat_at` is more than 30 minutes stale, flagging them as `auto_closed = true` with `close_reason = 'inactivity'`
- Display auto-closed sessions prominently in the engineer's time log with a "Was this correct?" prompt
- Enforce one open session per engineer per project — on second clock-in: "You have an open session from [time]. Clock out first, or discard it."

**Detection:** Query `WHERE clocked_out_at IS NULL AND clocked_in_at < NOW() - INTERVAL 1 DAY`. Run weekly.

**Phase risk:** INST-04 (time tracking). Must be designed into the schema from day one — retrofitting heartbeat columns is disruptive.

---

### Pitfall C4: Timezone Corruption in Time Entry Records

**What goes wrong:** Engineer clocks in at 08:30 BST (UTC+1). Alpine.js sends a local time string to the server. Laravel stores 08:30 as UTC, which is 09:30 BST actual time. All time entries are off by one hour during British Summer Time. In winter (GMT = UTC) it works correctly and looks fine in development.

**Why it happens:** JavaScript's `new Date()` is timezone-aware but `.toLocaleString()` or `.toISOString().slice(0,19)` truncations lose the offset. If the resulting string is sent without timezone context and stored as a raw string, the database absorbs the local time as if it were UTC. This is a seasonal bug invisible until the clocks change.

A separate Laravel framework issue (GitHub #58478, #33592) means that datetime strings *containing* timezone offset data can also be stored incorrectly depending on `APP_TIMEZONE` configuration.

**Consequences:** All summer time entries are wrong by one hour. Pay calculations, budget vs actual, and shift reports are incorrect for half the year. The bug is seasonal and hard to reproduce in development environments set to UTC.

**Prevention:**
- Always transmit timestamps from the browser as UTC ISO 8601: `new Date().toISOString()` — this is always UTC regardless of local timezone
- Always use `DATETIME` columns (not `TIMESTAMP`) in MySQL for time tracking — MySQL `TIMESTAMP` applies implicit timezone conversion that can interact badly with Laravel's timezone config
- Set `APP_TIMEZONE=UTC` in `.env` explicitly and document that this is intentional
- Never send bare time strings like `08:30:00` from the browser — always UTC ISO
- Display stored UTC times through a single server-side formatter that applies the company's local timezone for output only

**Detection:** Compare stored `clocked_in_at` values against known clock-in times during BST. A systematic 1-hour offset in summer confirms this bug.

**Phase risk:** INST-04 (time tracking). Schema and transmission design must handle this before the first time entry is stored.

---

### Pitfall C5: Signature Canvas DPI / Pixel Ratio Corruption

**What goes wrong:** Engineer captures client signature on a Retina or high-DPI mobile screen (iPhone, modern Android). The canvas is rendered at CSS size (e.g., 400×200px) but the physical pixel buffer is 800×400 (`devicePixelRatio = 2`). The signature looks correct visually but when saved as a PNG data URL, it is half the expected resolution. When embedded in a commissioning PDF, it appears blurry, pixelated, or incorrectly sized.

This is a documented, persistent bug in the `szimek/signature_pad` library (npm package — the standard JS library for this use case): GitHub issues #71, #153, #200, #362. The `fromDataURL()` method behaves incorrectly on devices with `devicePixelRatio !== 1`.

**Why it happens:** The `<canvas>` element's `width` and `height` attributes control the pixel buffer; CSS `width`/`height` control display size. Most implementations set one and not the other. Without explicit DPI scaling, the buffer is under-sampled on retina screens.

**Consequences:** Signatures look correct to the engineer in the browser. When the commissioning PDF is generated (via DomPDF or mPDF), the signature is blurry. Clients may question the validity of the sign-off. A reprint requires the client to be on-site again.

**Prevention:**
- Implement canvas DPI scaling on every signature pad init (and on `resize`/`orientationchange`):
  ```javascript
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  canvas.width = canvas.offsetWidth * ratio;
  canvas.height = canvas.offsetHeight * ratio;
  canvas.getContext('2d').scale(ratio, ratio);
  signaturePad = new SignaturePad(canvas);
  ```
- On `resize` or `orientationchange`: clear the canvas and re-prompt for signature — do not attempt to rescale existing signature data
- Store the raw vector path data from `signaturePad.toData()` alongside the PNG data URL — this allows server-side regeneration at correct resolution if needed
- Test on a physical iPhone and Android device before launch — device emulators do not accurately replicate `devicePixelRatio`

**Detection:** Save a test signature on an iPhone. Download the stored PNG. If PNG width equals CSS canvas width (without DPI multiplication), scaling is missing.

**Phase risk:** INST-05 (commissioning sign-off). Must be implemented correctly from the start — retrofitting after client sign-offs have been collected creates a re-signing requirement.

---

### Pitfall C6: Commissioning Progress Lost on Network Dropout Mid-Completion

**What goes wrong:** Engineer is on site in a basement plant room or server rack room (common AV install environments with poor signal). They complete 8 of 12 checklist items, upload 3 evidence photos, the client has signed. Network drops. They hit "Submit" — POST fails. On page reload, all state is gone because the form lived in browser memory only.

**Why it happens:** A standard Blade form submit sends everything in one POST. If it fails, there is no partial save. Alpine.js component state is lost on page reload. This is a traditional server-rendered form pattern applied to a scenario that requires resilience to connectivity loss.

**Consequences:** Engineer must redo the entire checklist. Client must re-sign. Photos must be re-uploaded. On a multi-hour commissioning session, this is a serious delay and severely damages trust in the tool.

**Prevention:**
- Save each checklist item completion individually via AJAX immediately when ticked: `PATCH /commissioning/{id}/items/{item}` — do not batch
- Photo uploads are standalone AJAX calls — each returns a stored file path with a success confirmation before the next step is enabled
- Signature capture is a standalone AJAX call with its own success confirmation
- Final "Submit" is a gate check only: are all items complete? Is signature present? If yes, flip `status` — no data is transmitted at this point
- Add a connectivity indicator in Alpine.js watching `navigator.onLine` — display a warning when offline: "No network — your progress has been saved"

**Detection:** Test on throttled connection (Chrome DevTools: "Slow 3G"). Kill connection mid-checklist and reload. If any completed items are missing on reload, the per-item save is not working.

**Phase risk:** INST-05 (commissioning). Architecture must use per-item saves from day one — retrofitting requires a full form refactor.

---

## Moderate Pitfalls

---

### Pitfall M1: Equipment-to-Task Mapping Producing Unreadable Task Names

**What goes wrong:** The task generator creates names like "Install QW-ITEM-0042" or "Install Samsung The Frame 55 QN55LS03BAFXZA" (raw QuoteWerks description). Engineers on site don't recognise model codes. Commissioning checklists become reference documents rather than actionable lists.

**Prevention:**
- Route all equipment names through the existing `EquipmentNormalizerService` before building task names — this already normalises QuoteWerks descriptions to human-readable categories
- Task name template: "[Action] [NormalisedName] in [RoomName]" — e.g., "Mount display in Boardroom", "Commission AV matrix in Server Room"
- Expose the generated task list in a pre-site-visit review screen so engineers can edit task names before going on site
- Store both the normalised task name and the original equipment reference (QuoteWerks item code) — the reference is for audit, the name is for the engineer

**Phase risk:** INST-01. A bad naming scheme baked into the data model is hard to rename retroactively without migrating existing records.

---

### Pitfall M2: Migrations Breaking Existing Queries During Deployment

**What goes wrong:** A new column is added to `projects` or `project_packages`. The migration runs in production. A service class (e.g., `ProjectDataService`) that uses `SELECT *` or accesses specific columns returns unexpected results until PHP-FPM is restarted. On a live system with active queue workers, jobs dispatched before the migration reference models that now have a different column set.

**Prevention:**
- All new columns on existing tables must be `nullable()` or have a `default()` — no exceptions
- New feature tables (`install_tasks`, `time_entries`, `commissioning_items`, `commissioning_signatures`) carry zero migration risk to existing tables — prefer new tables over new columns on `projects`
- Never modify the JSON structure of `extracted_data`, `reviewed_data`, or `equipment_list` columns via migration — add a new column if structural change is needed
- Run `php artisan queue:clear` after any schema migration affecting models used by queued jobs (`projects`, `project_packages`, `rams_documents`)
- Follow the two-phase pattern already established in the codebase: add column nullable first, deploy code, then add constraints if needed

**Phase risk:** All INST phases that add columns. Particularly INST-01 if task counts are stored on `projects` rather than in a child table.

---

### Pitfall M3: Project Lifecycle State Machine Polluted With Ad-Hoc Booleans

**What goes wrong:** The `Project` model has a clean linear lifecycle: `quote_imported → survey_pending → engineering → installing → commissioning → handover → completed → archived`. v1.2 features need to express whether tasks have been generated, whether a field session is active, whether commissioning is complete. Without a deliberate decision, developers add boolean columns: `tasks_generated`, `commissioning_started`, `field_active`. The state machine becomes a hybrid of status + booleans with no clear authority.

**Prevention:**
- Audit the existing `LIFECYCLE` and `TRANSITIONS` constants before adding any new Project-level state
- Prefer status fields on child models (`install_tasks.status`, `commissioning_checklists.status`) rather than booleans on `projects`
- The project's `STATUS_INSTALLING` and `STATUS_COMMISSIONING` states are sufficient — sub-state (e.g., "tasks generated") is tracked by the presence of `install_tasks` records, not by a column on `projects`
- If a new top-level status genuinely needs to be added (e.g., a "field_ready" gate), add it to the `LIFECYCLE` and `TRANSITIONS` constants formally — not as a boolean

**Phase risk:** INST-01, INST-04. The temptation to add quick booleans is highest when building the task generation and time tracking features.

---

### Pitfall M4: Over-Engineering Engineer Scheduling Before Validating Adoption

**What goes wrong:** The specification mentions "calendar view and Gantt timeline." A developer implements drag-and-drop Gantt, conflict detection, skill-matching, and availability calendars. Engineers use a WhatsApp group to coordinate. The feature is never adopted. Weeks of development are wasted, and the complex UI becomes maintenance burden.

**Prevention:**
- Phase 1 of INST-02 should deliver exactly: a dropdown on the task record to assign an engineer + a date field. Nothing more.
- A calendar view is a read-only display of assigned tasks grouped by date — not a scheduling engine
- Gantt, route optimisation, and conflict detection are explicitly deferred until field adoption of the simple assignment is validated on at least 2 real projects
- The spec phrase "calendar view" should be interpreted as "a list grouped by date" for v1.2

**Phase risk:** INST-02. This is the most likely feature to be over-engineered in this milestone.

---

### Pitfall M5: Multiple File Inputs Failing on iOS Safari

**What goes wrong:** The mobile field view uses `<input type="file" multiple>` for uploading multiple evidence photos at once. On iOS, multiple file selection from the file system (not photo library) is not supported. Engineers attempt to select multiple files and only one uploads. They assume the rest uploaded and move on.

**Prevention:**
- Use single-file inputs with an "Add another photo" button pattern — each button creates a new `<input type="file">` element
- Or maintain a JavaScript queue with individual file inputs that accumulate before a single upload action
- Never rely on the `multiple` attribute for critical evidence collection on iOS
- Each uploaded photo should show a success confirmation (filename + thumbnail) before the engineer proceeds

**Phase risk:** INST-03, INST-05. Both mobile field view and commissioning evidence capture are affected.

---

### Pitfall M6: Commissioning Sign-Off Has No Per-Item Audit Trail

**What goes wrong:** A commissioning checklist is completed and signed. Three weeks later, a client disputes whether a specific configuration item was verified. The only record is `completed_at` and a PNG of the overall signature. There is no record of who checked each item, what was observed, or when each item was signed off.

**Prevention:**
- Each checklist item completion must record: `completed_by` (user_id FK), `completed_at` (datetime UTC), `observed_value` (nullable text for readings/serial numbers), `notes` (nullable text)
- The client signature record must store: `signed_by_name` (free text — the client's name, not a user account), `signed_at` (datetime UTC), `ip_address`, `device_user_agent`
- These records are immutable once written — no soft delete, no update. If a correction is needed, add a new record with a `supersedes_id` reference.
- This audit trail costs nothing extra at schema design time and is extremely expensive to retrofit after sign-offs have been collected

**Phase risk:** INST-05. Must be designed into the commissioning schema before the first record is written.

---

## Minor Pitfalls

---

### Pitfall Mi1: Upload Size Limits Rejecting Field Photos

**What goes wrong:** Modern iPhone photos are 4–12MB. PHP's `upload_max_filesize` defaults to 2MB. Nginx's `client_max_body_size` defaults to 1MB. Engineers upload site photos and receive a silent failure or generic 413/500 error with no explanation.

**Prevention:**
- Set `upload_max_filesize = 20M` and `post_max_size = 25M` in `php.ini`
- Set `client_max_body_size 25M` in Nginx config
- Add client-side file size check in Alpine.js before the upload begins: warn if file > 15MB, hard reject with user message if > 20MB
- Return a JSON error with a `message` field from the upload controller if the server limit is hit

**Phase risk:** INST-03, INST-05.

---

### Pitfall Mi2: Alpine.js State Lost on Blade Partial Reload

**What goes wrong:** The checklist form uses Alpine.js for interactive state (ticked items, photo previews). Any full or partial page reload (navigation back, browser refresh after disconnect) destroys the Alpine component. Without server-backed state, the engineer's progress appears lost even though items may be individually saved.

**Prevention:**
- Back all checklist state with the per-item AJAX save pattern (Pitfall C6) — server is the source of truth, Alpine.js reflects server state on load
- On component init, fetch current item states from the server and populate Alpine data accordingly
- Do not use `x-data` for anything that needs to survive a page navigation; server state is the only reliable store in a field context

**Phase risk:** INST-03, INST-05.

---

### Pitfall Mi3: Displaying Budget vs Actual Before Sufficient Data Exists

**What goes wrong:** The budget vs actual time comparison widget appears on the project dashboard from day one. Budget hours are 0 (not yet entered). Actual hours are 0. Percentages and progress bars divide by zero or display 0/0%. Engineers ask "is this broken?" Trust in the tool is undermined before any data exists.

**Prevention:**
- The budget vs actual component must only render when: (a) at least one budget figure has been entered AND (b) at least one closed time entry exists for the project
- Default state: a neutral prompt — "Add time budgets to start tracking"
- Never display percentage ratios, progress bars, or efficiency scores with a zero denominator

**Phase risk:** INST-04.

---

### Pitfall Mi4: Task Names Not Updateable After Engineering Sign-Off

**What goes wrong:** Tasks are generated and locked at engineering sign-off. On site, the engineer discovers the scope has changed (a room is split, a display size is upgraded). The task name references the original equipment but the engineer must install different kit. There is no mechanism to amend the task without admin access.

**Prevention:**
- Task names should be editable by engineers up to the point of individual task completion — not locked at generation
- Task completion creates an immutable record; the task itself remains editable while pending
- Site-added tasks (equipment not in the original scope) must be createable by engineers in the field, with a `source = 'field_addition'` flag and a required note

**Phase risk:** INST-01, INST-03.

---

## Phase-Specific Warnings Summary

| Phase | Pitfall | Mitigation |
|---|---|---|
| INST-01 (task generation) | Tasks generated from extracted_data not reviewed_data | Lock to ProjectDataService; require human confirm gate |
| INST-01 (task generation) | Unreadable task names from raw QuoteWerks descriptions | Route through EquipmentNormalizerService; pre-site review screen |
| INST-01 (task generation) | Ad-hoc booleans on projects table | Use child-model status; protect LIFECYCLE constants |
| INST-02 (engineer assignment) | Over-engineered scheduling UI | Dropdown + date only for v1.2; defer Gantt |
| INST-03 (mobile field view) | HEIC uploads stored but unrenderable | accept="image/jpeg,image/png"; finfo server-side validation |
| INST-03 (mobile field view) | Multiple file input fails on iOS | Single-file inputs with "Add another" pattern |
| INST-03 (mobile field view) | 413 errors from large photos | Set PHP + Nginx limits; client-side size validation |
| INST-03 (mobile field view) | Alpine.js state lost on reload | Server-backed state; fetch on component init |
| INST-04 (time tracking) | Unclosed sessions from browser closure | Heartbeat ping + scheduled auto-close job |
| INST-04 (time tracking) | Timezone corruption BST vs UTC | Always transmit UTC ISO from browser; use DATETIME not TIMESTAMP |
| INST-04 (time tracking) | Zero-denominator budget display | Conditional render; require budget + entry before showing widget |
| INST-05 (commissioning) | Lost progress on network dropout | Per-item AJAX save; signature as standalone call; Submit = gate only |
| INST-05 (commissioning) | Signature blurry on Retina screens | Canvas DPI scaling; store vector path data |
| INST-05 (commissioning) | No per-item audit trail | completed_by + completed_at + observed_value per item at schema time |
| INST-05 (commissioning) | Multiple file input fails on iOS | Single-file with confirmation pattern |
| All phases (migrations) | Breaking existing tables and queued jobs | Nullable-only additions to existing tables; new tables for new features |

---

## Sources

- [HEIC handling in Laravel — Mastering Laravel](https://masteringlaravel.io/daily/2023-10-27-how-to-get-rid-of-heic-files-in-your-app)
- [Rendering HEIC on the Web — Upside Lab](https://upsidelab.io/blog/handling-heic-on-the-web)
- [iOS file input accept attribute not working — Apple Developer Forums](https://developer.apple.com/forums/thread/685295)
- [iOS multiple file input limitation — Apple Developer Forums](https://developer.apple.com/forums/thread/129845)
- [Laravel double timezone conversion bug — GitHub #58478](https://github.com/laravel/framework/issues/58478)
- [Datetime timezone storage incorrect — GitHub #33592](https://github.com/laravel/framework/issues/33592)
- [Handle date/time correctly to avoid timezone bugs — DEV Community](https://dev.to/kcsujeet/how-to-handle-date-and-time-correctly-to-avoid-timezone-bugs-4o03)
- [signature_pad DPI issue on high-DPI screens — GitHub #153](https://github.com/szimek/signature_pad/issues/153)
- [signature_pad fromDataURL half-size on Android — GitHub #200](https://github.com/szimek/signature_pad/issues/200)
- [signature_pad resize ratio not kept — GitHub #362](https://github.com/szimek/signature_pad/issues/362)
- [Creating a functioning signature widget — Ekreative](https://ekreative.com/blog/creating-a-functioning-signature-widget-problems-and-solutions/)
- [Laravel migration disasters — Medium](https://medium.com/@prevailexcellent/database-migration-disasters-how-not-to-ruin-your-laravel-app-ac6ff1d8920c)
- [Laravel migrations without downtime — DZone](https://dzone.com/articles/laravel-database-migrations-without-downtime)
- [Field Service Management challenges 2025 — NetSuite](https://www.netsuite.com/portal/resource/articles/erp/field-services-management-challenges.shtml)
- [Field Service Scheduling mistakes — myCloudDash](https://www.myclouddash.com/field-service-scheduling-software-7-mistakes-youre-making/)
- Codebase analysis: `app/Models/Project.php` (LIFECYCLE, TRANSITIONS constants), `app/Services/ProjectDataService` pattern, `EquipmentNormalizerService`

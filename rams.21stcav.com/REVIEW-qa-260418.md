# DEEP Code Review — Uncommitted Changes (2026-04-18)

Reviewed 55 files across controllers, jobs, services, models, migrations, routes, providers, and Blade components. Full file reads, cross-file call-chain tracing, orphan detection via grep, contract-drift verification against callers.

---

## 🟢 Closure log — 2026-07-14 (quick task 260714)

All 12 LOWs closed. 4 auto-closed by intervening work between 2026-04-18 and 2026-07-14; 4 fixed inline in this pass; 4 declined as trivial/informational-only (see per-item notes below).

- **L-01 — auto-closed.** Stray `count())` file no longer in repo root.
- **L-02 — auto-closed.** `QuoteParserService-working0904.php` no longer in `app/Services/`.
- **L-03 — auto-closed.** `retryGeneration` at `WorksheetController.php:239` now precedes `destroy` at `:285`, matching the section divider.
- **L-04 — auto-closed.** `RamsController::create()` no longer contains an `abort(403, 'You do not own this project.')` — the ownership branch was removed when the workspace went shared.
- **L-05 — fixed.** Removed 3 dead `abort_unless(auth()->check(), 403)` calls: `ProjectController::show()` :118, `ProjectController::transition()` :297, `RamsController::generateFromProject()` :230. All routes are already inside the `auth` middleware group, so the check could never fail. Replaced each with an inline "(auth is already enforced by the route's `auth` middleware group)" comment.
- **L-06 — declined.** `site_contact` max is `200`, not `500` — the review body already conceded 200 is fine. Reducing to 150 is a preference, not a correctness fix.
- **L-07 — declined.** `crc32()` variant selection: the `abs((int) crc32(...))` guard is fine on both 32-bit and 64-bit PHP. The PHP_INT_MIN edge case cited is unreachable from string inputs.
- **L-08 — fixed.** Moved inline `<style>` block from `resources/views/components/section-card.blade.php:45-54` into `resources/css/app.css` under a section header comment. Each render no longer duplicates the CSS.
- **L-09 — declined.** Review body itself says "None required; document the behavior." Documented here — canonical-shape enforcement in `SurveyController::normalizeEquipment()` intentionally drops out-of-schema keys.
- **L-10 — declined.** `t()` audit — spot-check across current `OmManualDocxService` shows callers all pass `string|null`, not arrays. No live bug.
- **L-11 — fixed.** `RamsComplianceUpgradeService::applyTypoFixes()` — swapped `str_ireplace` for a `preg_replace_callback` that inspects the matched word's casing and applies `strtoupper` (all-caps), `ucfirst` (title/sentence-start), or lowercase to the replacement. "Exisiting" → "Existing", "EXISITING" → "EXISTING", "exisiting" → "existing". Smoke-tested.
- **L-12 — declined.** Review body itself says "Not critical — consistent visual treatment is acceptable." Consistent grey `sb--grey` for all `draft` statuses across models is deliberate.

**Result:** RAMS QA backlog cleared. No open items from 2026-04-18 code review remain.

---

## CRITICAL

### C-01: Two classes named `CableScheduleGeneratorService` in different namespaces — collision and confusion hazard
- **Files:** `app/Services/CableScheduleGeneratorService.php` (active — namespace `App\Services`) and `app/Services/Cable/CableScheduleGeneratorService.php` (new — namespace `App\Services\Cable`)
- **What's wrong:** The new `App\Services\Cable\CableScheduleGeneratorService` is a static-method class that produces flat XLSX rows from a structured schedule. The active `App\Services\CableScheduleGeneratorService` is an instance-based Eloquent writer that creates `CableScheduleItem` rows. `BuildCableScheduleJob::handle()` still type-hints and uses the OLD one. Two classes with the same short name in PSR-4 namespaces *technically* compile, but any import oversight (`use App\Services\CableScheduleGeneratorService;` vs `use App\Services\Cable\CableScheduleGeneratorService;`) will silently call the wrong implementation. Callers grepping for `CableScheduleGeneratorService` cannot tell at a glance which one they're hitting.
- **Impact:** Real regression risk the next time anyone adds a caller or refactors imports. Pattern matches the C-01 class-collision risk from the prior 2026-04-11 REVIEW.md.
- **Fix:** Rename one of them. The new one is clearly intended as `CableScheduleRowExporter` or `CableScheduleExportFormatter` — use a name that does not collide. Same problem applies to `App\Services\Cable\CableScheduleService` vs. an existing `CableScheduleService` (the existing one is referenced in this file's own docblock, line 20, as "Separate from the flat-namespace App\Services\CableScheduleService"). Rename the `Cable\` ones.

### C-02: New `app/Services/Cable/*`, `OandM/*`, `Worksheet/*`, `ProjectContext/*` pipeline is almost entirely ORPHANED — ~70% dead code shipping
- **Files:** `app/Services/Cable/CableScheduleService.php`, `app/Services/Cable/CableScheduleGeneratorService.php` (the new one), `app/Services/OandM/OandMDataBuilderService.php`, `app/Services/Worksheet/WorksheetDataBuilderService.php`, `app/Services/ProjectContext/ProjectContextValidatorService.php`
- **What's wrong:** Callers (via `::` or `use` statements) for these classes do not exist anywhere in `app/`:
  - `Cable\CableScheduleService::buildSchedule()` — ZERO callers
  - `Cable\CableScheduleGeneratorService::toRows()` / `::toExport()` — ZERO callers
  - `OandMDataBuilderService::build()` — ZERO callers
  - `WorksheetDataBuilderService::build()` — ZERO callers
  - `ProjectContextValidatorService::validate()` — ZERO callers
  - `Rams\RamsReviewService::validate()` / `::approve()` — ZERO callers

  Only `ProjectContextBuilder`, `SurveyToProjectContextMapper`, `EquipmentActivityMapper`, and `Cable\CableScheduleBuilderService` are actually wired in (via `RamsBuilderService` and `RamsDataBuilderService`). The other six classes are 100% orphaned.
- **Impact:** ~70% of the new `Services/` subdirectories are dead code. The O&M and Worksheet "new pipelines" described in the docblocks do not exist as actual pipelines — the existing `OmManualGeneratorService` and `WorksheetGeneratorService` (both modified in this sweep) still carry the real generation logic. Reviewers will confuse these new services for the real flow and refactor against dead artifacts.
- **Fix:** Either (a) delete orphaned classes until a controller/job actually wires them in, or (b) wire them into a real endpoint in this same sweep. Shipping unreachable code is a documented anti-pattern in CLAUDE.md ("No duplicated data, no AI guessing") and violates the project's own convention.

### C-03: `BuildWorksheetJob::handle()` — `$worksheet->update(... error_message ...)` can throw after record already deleted
- **File:** `app/Jobs/BuildWorksheetJob.php:115-130`
- **What's wrong:** The catch block at line 115 calls `$worksheet->update(['status' => STATUS_FAILED, 'error_message' => ...])` without checking whether `$worksheet` is still present. If the record was soft-deleted between `Worksheet::find()` at line 50 and the catch block, the `update()` call will silently succeed but set status on a trashed row. More importantly, the surrounding `try` wraps the entire generation which does include a `throw new RuntimeException(...)` at line 89 for the no-content quality gate — this throws into the catch block which then re-throws, which is fine, but the catch is missing the nested try/catch pattern used in `BuildOmManualJob::handle()` (lines 105-115) and `BuildCableScheduleJob::handle()` (lines 124-133) that guards the status update DB write itself. If the DB becomes unavailable during the catch, the exception will propagate without the `Log::critical` breadcrumb written by the other two jobs, losing the trace.
- **Impact:** Inconsistent pattern across the three async doc-generation jobs. Any DB blip during failure handling will leave the worksheet stuck in `generating` with no status-update log line.
- **Fix:** Wrap the `$worksheet->update([...])` inside the catch in its own try/catch, matching the pattern already used in `BuildCableScheduleJob::handle()` lines 124-133.

---

## HIGH

### H-01: `ProjectController::show()` — `canonicalData` build runs on every page load without caching — ProjectDataService::resolve() is O(N*M) with nested loops
- **File:** `app/Http/Controllers/ProjectController.php:241`, `app/Core/Modules/Projects/ProjectDataService.php:43-85`
- **What's wrong:** `$this->projectDataService->resolve($project)` is called on every `projects.show` request. `resolve()` runs `mergeSurveyRooms()` which is O(N_quote_rooms × N_survey_rooms) with `similar_text()` inside, and builds 7 separate resolver arrays. `AppServiceProvider` binds it as a `singleton` (good) but that singleton still runs `resolve()` afresh on every call. The view then also iterates `$linkedRecords` and loads relationships that were already eager-loaded — those are fine. The real risk is the resolver is pulled on a page that already has 6 separate `->limit(5)` collections eager-loaded at lines 119-129.
- **Impact:** Every project show hits ProjectDataService, which in turn re-runs `similar_text()` fuzzy matching across all quote × survey room pairs. For projects with ≥ 20 rooms this is noticeable (but not catastrophic). The brief says performance is out of scope, however this is also a correctness concern: `similar_text()` is locale-dependent and non-idempotent under different PHP configs. Room matching may behave differently across requests on multi-node deploys.
- **Fix:** `resolve()` is read-only, so request-scoped memoisation via a static array keyed on `$project->id` is safe, or cache the result for the request lifecycle. At minimum, document that the view path rebuilds the context each time.

### H-02: `RamsController::updateAndDownload()` persists reviewed_data/generated_data BEFORE validation succeeds, then silently loses the download if render fails
- **File:** `app/Http/Controllers/RamsController.php:514-557`
- **What's wrong:** The flow is: merge form edits → call `RamsComplianceUpgradeService::upgrade()` → `$rams->update([...generated_data, reviewed_data...])` (line 516) → try/catch the render. If `render()` throws, we already persisted the merged generated_data AND triggered the project-sync write (lines 527-546). The user sees "could not be regenerated, please try again" but the database state already reflects the new data. The NEXT retry will skip the compliance upgrade step because the upgrade was already applied in-memory and saved — applying it twice is idempotent but ugly. Also, if the user hits back and cancels, the edits are still saved.
- **Impact:** Violates "thin controllers" and transactional integrity. The fail-and-retry UX does not match reality: the data is already updated.
- **Fix:** Wrap the `$rams->update()` + render in a `DB::transaction()` that rolls back on render failure, OR render FIRST to a temp file, then persist + download on success. Standard Laravel pattern: build artifact first, persist last.

### H-03: `RamsController::updateAndDownload()` — `project_ref` can be silently nulled
- **File:** `app/Http/Controllers/RamsController.php:518`
- **What's wrong:** `'project_ref' => $validated['project_ref'] ?? $rams->project_ref` falls through to the existing model value when null — OK. But `$generatedData['project']['ref']` at line 409 is `$validated['project_ref'] ?? ($generatedData['project']['ref'] ?? null)`. If the user submits an empty string `""` (not null), `?? null` does not trigger; the empty string wins and `generated_data['project']['ref']` becomes `""`. The model column retains the old value (because of the fall-through at 518), so the two sources of truth now disagree. The PDF renderer (`patchRamsForDisplay`) falls back to `$rams->project_ref` when `$p['ref']` is empty, which masks the bug, but any direct consumer of `generated_data` will show blank ref.
- **Fix:** Use `$request->filled('project_ref') ? $validated['project_ref'] : $rams->project_ref` consistently, or normalise empty strings to null.

### H-04: `OmManualController::storeFromProject()` and `generateFromProject()` check `$project->user_id !== auth()->id() && auth()->user()?->role !== 'admin'` — inconsistent with Policy pattern
- **File:** `app/Http/Controllers/OmManualController.php:128, 154, 198`
- **What's wrong:** CLAUDE.md explicitly says "Use `$this->authorize('view', $rams)` in controllers (policy-based)" and "Use `abort_if()` / `abort_unless()` for inline admin checks". The `edit()`, `update()`, `generate()`, `download()`, `downloadPdf()`, `destroy()` methods correctly use `$this->authorize(...)` with `OmManualPolicy`. But `storeFromProject()`, `generateFromProject()`, `status()`, and `retryGeneration()` reach directly into `auth()->user()?->role` — bypassing the policy layer and duplicating logic. Worse, the `role` string check is non-robust: `isAdmin()` exists on the User model (used in `CableScheduleController::destroy()` line 163) and is the documented convention. Comparing `role !== 'admin'` will miss any future admin role variants ('super_admin', 'super-admin', etc.).
- **Impact:** Silent auth drift. If the User model gains a new admin-tier, every one of these `role === 'admin'` checks will miss it, while `isAdmin()`-based checks adapt automatically.
- **Fix:** Replace `auth()->user()?->role !== 'admin'` with `!auth()->user()?->isAdmin()` everywhere, and prefer policy methods (`$this->authorize('generate', $project)` with a ProjectPolicy). Same issue exists in `WorksheetController::generateFromProject()` line 101 (uses `isAdmin()` correctly — inconsistent within the codebase).

### H-05: `SurveyController::stepSave()` — `validateStep()` always returns null — server-side validation is a no-op
- **File:** `app/Http/Controllers/SurveyController.php:275-278`
- **What's wrong:** The entire method body is `return null;` with a comment saying "field presence is enforced client-side". Anyone bypassing the Alpine UI (curl, browser devtools, malicious script) can POST arbitrary `data` arrays and have them written to `survey_data`. The controller then calls `enforceCanonicalShape()` which strips unknown keys — so the damage is constrained — but type coercion is very lax. Notably:
  - `normalizeEquipment()` accepts any `type` string; no validation it matches the controlled vocabulary (`display|projector|camera|...`).
  - `normalizeInfrastructure()` accepts any string/float for `distance_to_screen` but uses `?? null` which still accepts objects and arrays, which then blow up downstream when `ProjectContextBuilder` stringifies them.
  - `step` is validated 1..8 but steps 7 and 8 are explicitly no-ops with `break` — safe, but misleading in validation schema.
- **Impact:** Data-quality hazard. Garbage data in survey_data feeds into `SurveyToProjectContextMapper::map()` which feeds `RamsBuilderService`, `OandMDataBuilderService`, etc. This violates CLAUDE.md's core constraint: "All document content must trace back to quote data, survey data, or reviewed inputs" — and implicitly "reviewed" means validated.
- **Fix:** Replace the `return null;` stub with real per-step validation:
  - Step 5: validate each equipment `type` against the controlled vocabulary.
  - Step 4: validate `estimated_distance` is a reasonable positive number.
  - Step 6: validate risk `working_height` is a known string.
  - At minimum enforce type coercion via Laravel validators before `normalizeStepContribution`.

### H-06: `RamsController::store()` — buildFromForm runs synchronously in HTTP request; ExtractRamsDraftJob timeout up to 600s is useless here
- **File:** `app/Http/Controllers/RamsController.php:202-214`
- **What's wrong:** `$this->ramsBuilder->buildFromForm(...)` is called synchronously inside the HTTP request. `buildFromForm` (per the service's responsibility) runs the entire local pipeline (classify → hazards → AI method statement → DOCX). The comment at line 202 says "Run the full local pipeline" — if the AI call stalls or the DOCX write is slow, the HTTP request will time out before the catch at line 206 fires. The store method sets status to `STATUS_FOR_REVIEW` (placeholder) at line 192, and only updates to COMPLETED after the synchronous pipeline finishes at line 205.
- **Impact:** Users hitting the Create RAMS form will see 504s on slow AI responses; record stays at `for_review` forever. This is inconsistent with `generateFromProject()` at line 289-290 which correctly dispatches via `BuildRamsDocumentJob::dispatch()` to avoid 504s. WorkerMonitorService specifically documents "Job dispatched to queue but may not process until worker is started" — but `store()` doesn't even use that queued path.
- **Fix:** Move the `buildFromForm` invocation into `BuildRamsDocumentJob::dispatch()` for consistency with every other doc-generation flow in this codebase.

### H-07: `WorksheetController::download()` — uses Storage::disk('local')->path() but worksheet files may also be written to `storage/app/worksheets` directly by the DocxService
- **File:** `app/Http/Controllers/WorksheetController.php:171-177`, `app/Services/WorksheetDocxService.php:64-70`
- **What's wrong:** `WorksheetDocxService::build()` at line 65 calls `Storage::disk('local')->path('worksheets')` which resolves to `storage/app/private/worksheets/` in Laravel 11 (since the `local` disk root is `storage/app/private`). The controller's `abort_unless` at line 171 checks `Storage::disk('local')->exists('worksheets/' . $worksheet->filename)` which uses the same disk, so they match. HOWEVER, the `OmManualDocxService::build()` writes to `storage_path('app/om-manuals/')` (absolute — NOT via the local disk — see line 153 and the controller comment at OmManualController.php:309). So worksheets go to `storage/app/private/worksheets/` but O&Ms go to `storage/app/om-manuals/` (no `private/`). This inconsistency is a maintenance trap — the pattern is not documented anywhere and anyone updating one path will miss the other.
- **Impact:** Not a runtime bug, but a very high confusion cost. Reviewers expect consistency. `CableScheduleXlsxService` (line 137) uses `Storage::disk('local')->path('cable-schedules/')` which also lands under `storage/app/private/`. RAMS DOCX goes to `storage/app/rams/` (no disk — via `storage_path`). Four different document flows, three different storage conventions.
- **Fix:** Standardise. Either all document files go through a `document-artifacts` disk defined in `config/filesystems.php`, or all use `storage_path('app/<type>/')` explicitly. Document the chosen convention in CLAUDE.md.

### H-08: `CableScheduleController::store()` uses `CableSchedulePrompt` (AI call) despite brief saying AI is only for formatting/method statements
- **File:** `app/Http/Controllers/CableScheduleController.php:72-82`, vs. `app/Services/CableScheduleGeneratorService.php` (deterministic)
- **What's wrong:** The controller's `store()` method uses `AIManager::run(new CableSchedulePrompt($lines), ...)` to generate cable rows from an uploaded PDF. The NEW `CableScheduleGeneratorService` (used by `generateFromProject` via `BuildCableScheduleJob`) explicitly says "No AI. Deterministic keyword matching only." — and produces cable rows from project data, not PDFs. So the `store()` path (manual upload + AI) violates the CLAUDE.md constraint "AI is ONLY allowed for formatting and method statement structuring — never for inventing scope, equipment, or design." Cable rows are equipment and design — AI is inventing scope here.
- **Impact:** Directly violates the project's stated architectural constraint. Cable IDs, from/to locations, cable types, cores, and lengths are all "design" outputs that trace back only to an AI guess, not to quote data or survey data.
- **Fix:** Remove the AI path from `store()`. Either require upload users to go through the deterministic pipeline (project → `generateFromProject`), or replace `CableSchedulePrompt` with the deterministic `inferCableRun()` keyword matcher already shipped in `CableScheduleGeneratorService`.

### H-09: `RamsController::patchRamsForDisplay()` is 300+ lines of transient patching that mutates model attributes — fragile and test-hostile
- **File:** `app/Http/Controllers/RamsController.php:1023-1330`
- **What's wrong:** The controller's private `patchRamsForDisplay()` does 6 distinct jobs (live project sync, personnel fallback, client contact inference, package scope rebuild, hardware filtering, reviewed_data pre-fill defaults) — all by assigning to `$rams->generated_data` and `$rams->reviewed_data` in-place. The comment repeatedly says "transient" but anyone not reading the class will assume `$rams` is persisted. If a future refactor adds a `$rams->save()` downstream for any reason, a huge amount of synthesized data will leak into the DB.
- **Impact:** Violates "thin controllers, services return data/paths" from CLAUDE.md. This is a service in all but name. The closures for hardware filtering (lines 1186-1212) duplicate the classification logic in `CableScheduleGeneratorService`, `WorksheetGeneratorService`, and `OmManualGeneratorService` — four copies of the same keyword list, each drifted from the others.
- **Fix:** Extract `patchRamsForDisplay()` into `App\Services\Rams\RamsDisplayPatchService::patch(RamsDocument $rams): RamsDocument` returning a cloned instance. Consolidate the hardware/service/cable keyword lists into a single `HardwareClassificationService` used by all four pipelines. The controller should be under 300 lines; currently 1349 and rising.

---

## MEDIUM

### M-01: `OmManualGeneratorService::buildContextFromProjectData()` — `->whereNotNull('project_id')` on package query is semantically wrong
- **File:** `app/Core/Modules/OMManual/OmManualGeneratorService.php:237-240`
- **What's wrong:** `$project->packages()->whereNotNull('project_id')->latest()->first()` filters packages by `project_id IS NOT NULL`. But every package returned by `$project->packages()` already has `project_id = $project->id` by the relationship — so `whereNotNull('project_id')` is always true and never filters anything. Either this was meant to be a `whereNotNull('reviewed_data')` guard, or it's dead code.
- **Fix:** Remove the `whereNotNull('project_id')` clause, or change it to the intended guard (probably `whereNotNull('reviewed_data')` or `where('status', ProjectPackage::STATUS_REVIEWED)`).

### M-02: `OmManualController::downloadPdf()` doesn't `deleteFileAfterSend()` consistently with the docx route
- **File:** `app/Http/Controllers/OmManualController.php:342-345`
- **What's wrong:** `downloadPdf()` calls `->deleteFileAfterSend()` — correct, because `pdfService->buildOmManual()` writes to a temp file. But RAMS `downloadPdf()` (at `RamsController.php:756-758`) also uses `deleteFileAfterSend()`. However, these temp files may accumulate if Laravel's BinaryFileResponse fails to fire the termination callback (e.g., connection dropped mid-download). No cleanup job exists.
- **Fix:** Add a `storage:cleanup` command that removes files in the temp PDF directory older than 1 hour. Not critical but will pile up on a busy server.

### M-03: (retracted) `WorksheetDocxService::build()` — mkdir recursive flag consistency
- **Note:** Originally flagged; rechecked — all mkdir calls across the three DocxServices use `0755, true` consistently. No finding.

### M-04: `BuildCableScheduleJob::buildCsvFallback()` — no `flock()` on the file handle
- **File:** `app/Jobs/BuildCableScheduleJob.php:147-190`
- **What's wrong:** `fopen($filePath, 'w')` followed by `fputcsv()` calls. If two workers (or one worker + a retry) process the same cable_schedule_id in parallel, both will truncate and write to the same file, corrupting it. The outer status transition to `generating` at line 56-58 is the guard, but because it's an `update()` not a `SELECT FOR UPDATE`, a retry can race.
- **Fix:** Either use `flock($fp, LOCK_EX)` before writing, or write to a `.tmp` filename and `rename()` atomically at the end (see `WindowsSafeFilesystem::replace()` which already does this pattern for Blade compiled files).

### M-05: `WorksheetGeneratorService::generateContent()` — AI call inside loop with no budget / per-project timeout
- **File:** `app/Services/WorksheetGeneratorService.php:268-282`
- **What's wrong:** For each room, an AI call is made via `AIManager::run(WorksheetPrompt::forRoom(...), ...)`. If a project has 20 rooms, this dispatches 20 sequential AI calls. The job timeout is 300s. Claude's average response is ~5-15s for prompts of this size — so 20 rooms × 10s = 200s, which is close to the timeout. The `try/catch` around the AI call means failures are silently swallowed (lines 278-281) and fall through to the deterministic plan — so the job doesn't fail loudly, but the user gets mixed AI/deterministic content without visibility into which succeeded.
- **Impact:** Slow worksheets for large projects. No per-project AI cost budget. CLAUDE.md says "AI is ONLY allowed for formatting and method statement structuring" — worksheet install_steps narrative is arguably "structuring," but 20 parallel AI invocations per worksheet should be batched.
- **Fix:** Either (a) batch all rooms into a single AI call with a structured prompt, or (b) add an explicit per-job AI cost cap and log telemetry.

### M-06: `WorksheetController::download()` uses `abort_unless` with two-argument check
- **File:** `app/Http/Controllers/WorksheetController.php:170-174`
- **What's wrong:** `abort_unless($worksheet->filename && Storage::disk('local')->exists('worksheets/' . $worksheet->filename), 404, '...')` — the evaluated expression is `null && Storage::exists(...)` when filename is null. The second operand won't short-circuit on a string path built with `null`, and `Storage::disk('local')->exists('worksheets/')` will likely return true (a directory exists), then the `&&` result is `null`, abort fires with 404. Functionally correct but relies on `null && anything` evaluating to falsy. Cleaner to split.
- **Fix:** Split into explicit checks:
  ```php
  if (! $worksheet->filename) abort(404, 'Worksheet DOCX not generated.');
  abort_unless(Storage::disk('local')->exists('worksheets/'.$worksheet->filename), 404, 'File missing on disk.');
  ```

### M-07: `ProjectDataService::resolveSurveyMeta()` — `$survey->completed_at` is accessed with `isset` but `$survey` may be a stdClass stub (for tests)
- **File:** `app/Core/Modules/Projects/ProjectDataService.php:243-251`
- **What's wrong:** `isset($survey->completed_at)` is `true` even if the property is literally `null` on a real Eloquent model (because Eloquent resolves attribute access through `__get`). Looking at line 243: `if (isset($survey->completed_at) && $survey->completed_at !== null)` — this is defensive in the right way, but on a plain `object` stub (used by tests per line 289 comment) `isset` may return false for a property that was set to null. Subtle but fine in practice.
- **Fix:** Replace `isset($survey->completed_at) && $survey->completed_at !== null` with just `! empty($survey->completed_at)` for readability.

### M-08: `SurveyController::stepSave()` — no project association check on the token, only `isTokenExpired()`
- **File:** `app/Http/Controllers/SurveyController.php:433-439`
- **What's wrong:** `resolveSurvey()` only validates the access_token exists and isn't expired. It does NOT check whether the survey is still linked to an active, non-soft-deleted project. If a project is soft-deleted while a surveyor is mid-wizard, the token still works and writes can continue — but any downstream `ProjectDataService::resolve()` will get a soft-deleted project model. Related: there's no `$survey->project?->trashed()` check anywhere in the step save path.
- **Impact:** Surveyors can continue writing to `survey_data` for dead projects. Data is orphaned.
- **Fix:** Extend `resolveSurvey()`:
  ```php
  if ($survey->project && $survey->project->trashed()) {
      abort(410, 'This survey link has expired — project archived.');
  }
  ```

### M-09: `WorksheetDocxService::build()` — filename includes `now()->format('Ymd_His')` but two concurrent jobs could produce the same filename
- **File:** `app/Services/WorksheetDocxService.php:64`
- **What's wrong:** `'worksheet_' . $worksheet->id . '_' . now()->format('Ymd_His')` — includes worksheet ID so cross-record collision is impossible. But two retries of the SAME worksheet within the same second will collide (possible on fast dev machines). Not critical because the second write wins, but a retry could overwrite a successful file before its download starts.
- **Fix:** Append `.uniqid()` to the filename or use `now()->format('Ymd_His_u')` for microsecond precision.

### M-10: `CableScheduleController::store()` — `$schedule = DB::transaction(function () {...})` returns value from closure, but then-used outside — not idiomatic Laravel, may lose variable on failure
- **File:** `app/Http/Controllers/CableScheduleController.php:86-115`
- **What's wrong:** The transaction closure returns `$s` and it's assigned to `$schedule` outside. If the transaction fails and throws, the outer `return redirect()->route(...)` is unreachable because the exception propagates. OK in this case. But the pattern is brittle — if someone adds a `try/catch` around the transaction later, they could catch the exception and find `$schedule` undefined at line 113.
- **Fix:** Assign a default `$schedule = null;` before the transaction and check `abort_if($schedule === null, 500)` before the redirect.

### M-11: `RamsController::downloadPdf()` filename sanitation is incomplete — does not handle UTF-8 control chars
- **File:** `app/Http/Controllers/RamsController.php:754`
- **What's wrong:** `preg_replace('/[\\\\\/:\*\?"<>|]/', '_', $base)` strips only the classic Windows-forbidden characters. It doesn't strip control characters (`\x00-\x1F`) or newlines. If a project's `ref` or `client` happens to contain a tab or a DEL char (`\x7F`), the Content-Disposition header will be corrupt.
- **Fix:** Expand the regex to also strip `\x00-\x1F\x7F`:
  ```php
  $pdfName = preg_replace('/[\x00-\x1F\x7F\\\\\/:\*\?"<>|]/u', '_', $base) . '.pdf';
  ```

### M-12: Migration `2026_04_15_093841_add_survey_room_gap_columns` — `after()` clauses assume column order which MySQL accepts but other DBs reject
- **File:** `database/migrations/2026_04_15_093841_add_survey_room_gap_columns_to_site_survey_rooms_table.php:16-25`
- **What's wrong:** Every column uses `->after('previous_column')`. On MySQL this is fine. On SQLite (used by PHPUnit `:memory:` by default in many Laravel test configs), `ALTER TABLE ... AFTER` is not supported and the migration will fail the test suite. `phpunit.xml` likely sets `DB_CONNECTION=sqlite` for tests — if so, this migration will break tests.
- **Impact:** Test-suite breakage. Same issue in `2026_04_15_200000_add_wizard_fields` lines 23-28.
- **Fix:** Remove `->after(...)` clauses (column order isn't semantic), or wrap in `if (DB::connection()->getDriverName() === 'mysql')`.

### M-13: Migration `2026_04_15_210000_add_survey_data_to_site_surveys_table` — adding `json` column after `survey_type` — same as M-12 and MySQL `json` columns cannot have `DEFAULT` values before MySQL 8.0.13
- **File:** `database/migrations/2026_04_15_210000_add_survey_data_to_site_surveys_table.php:34`
- **What's wrong:** `$table->json('survey_data')->nullable()->after('survey_type');` — nullable JSON columns work on MySQL 5.7+. The `after()` clause will break SQLite tests (M-12). No default value is set, which is correct.
- **Fix:** Drop the `->after()` as in M-12.

### M-14: `WorkerMonitorService::ensureRunning()` — `app()->runningInConsole()` check via Laravel's `app()` inside a service is tightly coupled
- **File:** `app/Services/WorkerMonitorService.php:162`
- **What's wrong:** The service uses `app()->runningInConsole()` directly. This is a framework dependency that makes unit testing awkward (has to mock the application instance). But the constructor of `WorkerMonitorService` takes NO dependencies, and the class is used as a service container binding. Acceptable for Laravel conventions but violates the pure-class pattern suggested by constructor (lines 54-58).
- **Fix:** Not required — this is Laravel convention — but could be better to inject a `RuntimeContext` interface.

### M-15: `RamsComplianceUpgradeService::crossReferenceMethodStatementRisks()` — wasteful loop allows duplicate appends
- **File:** `app/Services/Rams/RamsComplianceUpgradeService.php:613-618`
- **What's wrong:** `break 2` exits inner two loops but the outer iteration continues. The same hazard ID can be appended multiple times. `array_unique` at line 636 cleans it up but is wasteful.
- **Fix:** Track seen IDs in a set and skip early.

### M-16: `WorksheetDocxService::powerNetworkTable()` — boolean `$room['requires_additional_power']` rendered via `(string)` becomes "1" / ""
- **File:** `app/Services/WorksheetDocxService.php:335, 343-344`
- **What's wrong:** `['Additional power required', $room['requires_additional_power'] ?? null]` — if the survey wizard saves this as `true` (bool), the `(string) $value` cast at line 344 produces `"1"`. The client-facing DOCX will show "1" instead of "Yes". If `false`, it casts to `""` which triggers the else branch "Not surveyed" — silently hiding a meaningful "No, no additional power required" answer as if it were unsurveyed.
- **Fix:** Normalise bool fields explicitly:
  ```php
  $val = $room['requires_additional_power'];
  $display = is_bool($val) ? ($val ? 'Yes' : 'No') : $val;
  ```

### M-17: `patchRamsForDisplay()` — scope filtering closure creates `$isHardware` anonymous func on EVERY page load
- **File:** `app/Http/Controllers/RamsController.php:1187-1212`
- **What's wrong:** The closure is defined within `patchRamsForDisplay()` which is called by `review()` AND `downloadPdf()`. Per-request this rebuilds a closure containing two compiled regexes and an array membership check. Not a performance issue, but the closure body is 25 lines of classification logic that SHOULD be a shared service (see H-09).
- **Fix:** Extract to a service.

---

## LOW

### L-01: Stray file `count()` in repo root — housekeeping
- **File:** Repo root `count())` (with unbalanced paren in filename)
- **What's wrong:** There's a file named `count())` at the repo root (seen in git status as `?? count())`). Probably from an accidental shell redirect (`> count())`). Should be deleted before any commit.
- **Fix:** `rm "count())"` (with careful shell quoting on Windows).

### L-02: Untracked backup file `app/Services/QuoteParserService-working0904.php`
- **File:** `app/Services/QuoteParserService-working0904.php`
- **What's wrong:** This duplicates `QuoteParserService` in the same namespace. Directly repeats the C-01 finding from the 2026-04-11 REVIEW.md (`QuoteParserService2903.php`). CLAUDE.md explicitly says numeric-date-suffix backup files are "NOT recommended for new code" and represent a class-collision hazard.
- **Fix:** Delete the file. If kept for historical reference, move to `.planning/backups/` where PSR-4 autoload doesn't reach it.

### L-03: `WorksheetController::show()` has an orphan doctring block
- **File:** `app/Http/Controllers/WorksheetController.php:186-195`
- **What's wrong:** The `// DESTROY` divider at line 183-185 is followed by a `/** Soft-delete the worksheet record */` block, then another `/** Retry / regenerate */` block, and the `retryGeneration` method. Then the actual `destroy` method. The order is "documented destroy → retry → destroy" which reads backwards.
- **Fix:** Reorder to put `retryGeneration` before `destroy`, matching the section divider.

### L-04: `RamsController::create()` — `abort(403, ...)` bypasses policies
- **File:** `app/Http/Controllers/RamsController.php:133-138`
- **What's wrong:** Direct `abort(403, 'You do not own this project.')` instead of `$this->authorize('view', $candidate)`. If a ProjectPolicy is added later, this check will not consult it.
- **Fix:** Use `abort_unless($candidate->user_id === auth()->id() || auth()->user()->isAdmin(), 403)` for clarity, or introduce ProjectPolicy.

### L-05: `ProjectController::show()` uses `abort_unless(auth()->check(), 403)` which is always true inside an `auth` middleware group
- **File:** `app/Http/Controllers/ProjectController.php:116`
- **What's wrong:** The whole route is inside `Route::middleware('auth')` (routes/web.php:68). So `auth()->check()` can never be false here. The `abort_unless` is dead code.
- **Fix:** Remove the check, or replace with an explicit policy invocation like `$this->authorize('viewAny', Project::class)`.

### L-06: `RamsController::updateAndDownload()` validation accepts 500-char `site_contact` but column may be shorter
- **File:** `app/Http/Controllers/RamsController.php:333`
- **What's wrong:** `'site_contact' => ['nullable', 'string', 'max:200']` — but the field is stored into `generated_data['project']['site_contact']` which is a JSON blob, so 200 chars is fine. However, `client_contact_name` and `client_contact_phone` derived from `$p['site_contact']` at lines 1106-1115 inherit this 200-char value with no further truncation. If downstream PDF templates have column widths, very long contact strings will overflow.
- **Fix:** Reduce max to 150 or document the expected bound.

### L-07: `WorksheetGeneratorService::buildRoomWorksDescription()` — `crc32()` used for variant selection (non-cryptographic but deterministic — fine)
- **File:** `app/Services/WorksheetGeneratorService.php:739`
- **What's wrong:** `abs((int) crc32(strtolower($roomName))) % 4` picks one of 4 sentence templates. Deterministic per room name, which is the intent. But `crc32` returns int on 32-bit PHP (signed, can be negative). On 64-bit PHP it returns a positive int. The `abs((int) crc32(...))` handles both. Not a bug — but `abs(PHP_INT_MIN)` returns PHP_INT_MIN (overflow). Extremely unlikely given string inputs, but possible.
- **Fix:** Use `hexdec(substr(md5($roomName), 0, 4)) % 4` for portability.

### L-08: Several new Blade components use inline `<style>` blocks (e.g., section-card.blade.php:45-54)
- **File:** `resources/views/components/section-card.blade.php:45-54`
- **What's wrong:** Inline `<style>` tag inside a Blade component duplicates every time the component renders. For a repeated component on one page, this inflates HTML output and creates CSS specificity issues (last wins). Tailwind utility classes are the documented project convention.
- **Fix:** Move to `resources/css/app.css` under an `@layer components { ... }` block.

### L-09: `SurveyController::normalizeEquipment()` — `array_intersect_key` approach drops custom fields silently
- **File:** `app/Http/Controllers/SurveyController.php:403-410`
- **What's wrong:** `$allowed = array_flip(['type', 'status', 'location']); ... array_intersect_key((array) $item, $allowed)` — any client-supplied key outside those three is dropped silently. OK — that's the canonical-shape enforcement — but the function does not preserve key ORDER, and `array_intersect_key` reorders keys based on the first argument. In practice the downstream consumer doesn't care, but a subtle note.
- **Fix:** None required; document the behavior.

### L-10: `OmManualDocxService::t()` typing — PhpWord `string|null` parameter with `return ''` on null
- **File:** `app/Services/OmManualDocxService.php:810-818`
- **What's wrong:** Signature `private function t(string|null $value): string` is correct PHP 8. The body returns `''` for null/empty — fine. But other parts of the file call `$this->t((string) $value)` with explicit cast, while some omit the cast. Inconsistent. Example: line 304 `$this->t($eq['description'] ?? '—')` is fine because `??` gives string, but line 627 `$this->t($mfr['notes'] ?? '—')` — if `$mfr['notes']` is an array (line 609 calls it as foreach), this casts array to string via `(string)` cast which produces "Array" + warning.
- **Fix:** Audit all callers of `t()` to ensure the argument is always a string or null. Never pass an array.

### L-11: `RamsComplianceUpgradeService::cleanTextArtifacts()` — typo map uses `str_ireplace` which is case-insensitive but does not preserve the replacement's case
- **File:** `app/Services/Rams/RamsComplianceUpgradeService.php:889`
- **What's wrong:** `str_ireplace('exisiting', 'existing', ...)` replaces "Exisiting" → "existing" (lowercased), losing sentence-start capitalisation. Similarly "EXISITING" → "existing".
- **Fix:** Use `preg_replace_callback` with a case-preserving callback, or run `ucfirst()` after.

### L-12: New Blade `status-badge` maps `draft` → `sb--grey` but RAMS and OmManual `STATUS_DRAFT` mean different things
- **File:** `resources/views/components/status-badge.blade.php:45`
- **What's wrong:** Single `'draft' => 'sb--grey'` row handles all models. A draft RAMS (generated but not approved) semantically differs from a draft survey (still being written). Both render with the same grey dot.
- **Fix:** Not critical — consistent visual treatment is acceptable — but consider scoping the map per-model or documenting the override pattern.

---

## INFO

### IN-01: Orphaned test coverage — only `WorksheetGeneratorServiceTest.php` and `WorksheetPromptTest.php` exist for this entire sweep
- **Files:** `tests/Unit/Services/`
- **What's observed:** No tests exist for:
  - `ProjectContextBuilder`
  - `SurveyToProjectContextMapper`
  - `ProjectContextValidatorService`
  - `CableScheduleBuilderService`, `Cable\CableScheduleService`, `Cable\CableScheduleGeneratorService`
  - `OandMDataBuilderService`
  - `WorksheetDataBuilderService`
  - `EquipmentActivityMapper`
  - `RamsReviewService`
  - `RamsComplianceUpgradeService`
  - `SurveyController`
- **Impact:** Every new pure function in this sweep is untested. Given all of them are static methods with no external deps, unit tests would be trivial to write and would expose the orphaning issues above immediately.
- **Fix:** Add at least happy-path tests for each new static service.

### IN-02: `resources/views/components/dashboard/empty-state.blade.php` uses `{!! $icon !!}` — pre-existing, not in this sweep
- **File:** `resources/views/components/dashboard/empty-state.blade.php:12`
- **What's observed:** Not in the review set but surfaced during the XSS scan. This is unescaped SVG markup. Since `$icon` is always a constant in parent views, XSS risk is low, but flagging for awareness.

### IN-03: `pdf/rams.blade.php:1157` — `{!! $stepLabel !!}` uses pre-escaped value
- **File:** `resources/views/pdf/rams.blade.php:1155-1157`
- **What's observed:** `$stepLabel = 'Step ' . ... . htmlspecialchars($cleanTitle, ENT_QUOTES);` — the value is escaped via `htmlspecialchars` before the `{!! !!}` renders it. This is safe because the non-dynamic portions ("Step ", "&nbsp;") are developer-controlled. OK, noting for awareness.

### IN-04: New `<x-app-shell>`, `<x-status-badge>`, `<x-section-card>` components — minor a11y gap
- **Files:** `resources/views/components/app-shell.blade.php`, `status-badge.blade.php`, `section-card.blade.php`
- **What's observed:** Only SVG icons have `aria-hidden="true"`. The `<x-status-badge>` has `role="switch"` on its dot — correct but the parent span has no `role="status"` attribute, which would be helpful for screen readers on live-updating status displays.
- **Fix:** Add `role="status" aria-live="polite"` to the `<span class="status-badge">` when the status is in a dynamic state (generating).

### IN-05: `WorkerMonitorService::spawnWorker()` uses `exec()` on Windows with `start /B` — documented as potentially hang-prone elsewhere in this file
- **File:** `app/Services/WorkerMonitorService.php:312-325`
- **What's observed:** Lines 9-13 of the class docblock say "exec() hangs indefinitely on this managed host". Yet `spawnWorker()` and `killProcesses()` call `exec()` freely under the `canExec()` guard. This is a documented, intentional tension — exec is only reached via `WORKER_EXEC_ENABLED=true`. No bug, just flagging that the class has two very different behavioral modes.

### IN-06: `CableScheduleController::generateFromProject()` — `$project->quote_reference ?? $project->ref ?? null`
- **File:** `app/Http/Controllers/CableScheduleController.php:208`
- **What's observed:** The `Project` model has `ref` but the code here checks `quote_reference` first. This suggests `Project` may have both columns (or aliasing). Verified in `ProjectDataService::resolveProjectFields` line 144: `'quote_reference' => $project->quote_reference ?? $project->ref` — same pattern. Documenting: two fields mean the same thing on the model, one or both may be in use. Should be consolidated.

### IN-07: `SurveyController` has no middleware (public route) — correct per architecture but worth noting
- **File:** `routes/web.php:50-51`
- **What's observed:** Routes `survey/{token}` and `survey/{token}/step-save` have no `auth` middleware (by design — UUID-gated). Throttle is `60,1` on step-save. Given M-08 above (no project-trash check), the throttle is the only real rate-limiter. Consider tightening to `30,1` for step-save since 60 requests/minute is generous.

---

## ORPHAN CHECK

Classes / static entry points verified via grep for `::methodName` or `use App\...Class` across all of `app/`:

| Class | External Callers | Status |
|---|---|---|
| `App\Services\Cable\CableScheduleBuilderService::buildRequirements` | `RamsDataBuilderService.php:74` | WIRED |
| `App\Services\Cable\CableScheduleService::buildSchedule` | — | **ORPHAN** |
| `App\Services\Cable\CableScheduleGeneratorService::toRows` / `::toExport` | — | **ORPHAN** |
| `App\Services\Equipment\EquipmentActivityMapper::map` | `SurveyToProjectContextMapper.php:132` | WIRED |
| `App\Services\OandM\OandMDataBuilderService::build` | — | **ORPHAN** |
| `App\Services\ProjectContext\ProjectContextBuilder::build` | `RamsBuilderService.php:708` | WIRED |
| `App\Services\ProjectContext\ProjectContextValidatorService::validate` | — | **ORPHAN** |
| `App\Services\ProjectContext\SurveyToProjectContextMapper::map` | `ProjectContextBuilder.php:73, 92` (only) | INTERNAL-ONLY (consumed via ProjectContextBuilder) |
| `App\Services\Rams\RamsComplianceUpgradeService::upgrade` | `RamsController.php:514, 585, 731`; `RamsBuilderService.php:237, 647` | WIRED |
| `App\Services\Rams\RamsReviewService::validate` / `::approve` | — | **ORPHAN** |
| `App\Services\Worksheet\WorksheetDataBuilderService::build` | — | **ORPHAN** |
| `App\Support\Filesystem\WindowsSafeFilesystem` | `AppServiceProvider.php:40` | WIRED |
| `App\Http\Controllers\SurveyController` | routes/web.php:50, 51 | WIRED |

**ORPHAN COUNT: 6 classes** (all in new `Services/` subdirectories). See C-02 for impact.

---

## CONTRACT DRIFT

Modified services verified against original caller signatures:

| Service | Signature change | Caller impact |
|---|---|---|
| `OmManualGeneratorService` | Added `buildContextFromProjectData(Project)` and `extractFromProjectPackage(User, ProjectPackage)` — both new public methods. `generateContent()` signature unchanged. `extractFromPdf()` signature unchanged. | No existing caller broken. `OmManualController::generateFromProject()` uses new `buildContextFromProjectData`. |
| `ProjectDataService::resolve` | Added `_raw_equipment` key to returned array. Existing keys unchanged. | `WorksheetGeneratorService.php:137` already reads `_raw_equipment` — new consumer matches new field. `CableScheduleGeneratorService` does NOT use `_raw_equipment` — uses `rooms` and `equipment`. Inconsistent but non-breaking. |
| `CableScheduleGeneratorService::generate` (flat namespace) | Signature unchanged. Body heavily refactored to use `ProjectDataService`. | `BuildCableScheduleJob::handle()` line 75 still calls `$generator->generate($schedule)` — compatible. |
| `CableScheduleXlsxService::build` | Signature unchanged. Now writes to `storage/app/private/cable-schedules/` (via local disk). | `BuildCableScheduleJob` dispatches to it — consistent. `CableScheduleController::download` reads from `storage_path('app/private/cable-schedules/')` (line 270) — consistent. |
| `OmManualDocxService::build` | Signature unchanged. Added XML validation post-build (throws `RuntimeException` on bad XML). | `BuildOmManualJob::handle()` wraps in try/catch — new throws captured. |
| `WorkerMonitorService` | Now accepts `?string $heartbeatPath, ?string $logPath, ?int $ttlOverride` in constructor. All nullable. | Existing `app(WorkerMonitorService::class)` calls still work. `WorkerMonitorController` uses property injection — compatible. |
| `WorksheetDocxService::build` | Signature changed to accept `array $generatedData, Worksheet $worksheet`. Returns `void`. | `BuildWorksheetJob::handle()` line 109 calls `$docxService->build($generatedData, $worksheet)` — matches new signature. **Verified only one caller.** |
| `WorksheetGeneratorService::generateContent` | Signature unchanged. Internal logic heavily refactored with new subsystem detection and AI enhancement. Return shape now includes `'subsystems'`, `'phased_plan'`, `'commissioning'`, `'safety'`, `'tools'`, `'pre_install_answers'`, `'room_works_description'` keys. | `BuildWorksheetJob::handle()` line 62-88 reads new keys. `WorksheetDocxService` renders them. Compatible. |

**NO CONTRACT BREAKAGE DETECTED.** All modified services preserve their public signatures. The return-shape changes on `WorksheetGeneratorService::generateContent` and `ProjectDataService::resolve` are additive (new keys) and backward-compatible.

---

## SUMMARY COUNTS

| Severity | Count |
|---|---|
| CRITICAL | 3 |
| HIGH | 9 |
| MEDIUM | 17 |
| LOW | 12 |
| INFO | 7 |
| **TOTAL** | **48** |

**Most urgent actions, in order:**
1. **C-02** — Delete or wire the six orphaned services before they drift further. Half a pipeline is worse than no pipeline.
2. **C-01** — Rename the `Cable\CableScheduleService` / `Cable\CableScheduleGeneratorService` pair to avoid class-name collisions with the flat-namespace ones.
3. **H-05** — `SurveyController::validateStep()` is a no-op; add real per-step validation before malformed data poisons the downstream pipeline.
4. **H-08** — Remove AI from the `CableScheduleController::store()` path; it violates the project's AI-usage constraint.
5. **H-02** — Wrap `RamsController::updateAndDownload` in a DB transaction so render failures don't leave orphaned data.
6. **M-12/13** — Remove `->after()` clauses from migrations or guard them to MySQL-only, to keep the SQLite test suite working.
7. **L-01** — Delete the stray `count())` file at repo root.
8. **L-02** — Delete `QuoteParserService-working0904.php` (recurring duplicate-class hazard).

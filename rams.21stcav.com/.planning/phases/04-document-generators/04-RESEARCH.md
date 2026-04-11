# Phase 4: Document Generators — Research

**Researched:** 2026-04-11
**Domain:** Laravel queue jobs, PHPWord DOCX generation, PhpSpreadsheet XLSX generation, ProjectDataService data contracts
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01** Worksheet is an engineer's job card. Room-by-room: what to install, cable routes, power/network, site constraints.
- **D-02** AI generates install step narrative per room. Reads equipment list + survey room data. AI structures what's in ProjectDataService — never invents scope.
- **D-03** Four sections per room block: (1) equipment list, (2) AI install steps, (3) cable routes, (4) power & network requirements.
- **D-04** Unsurveyed rooms included with "Not surveyed" for null survey fields.
- **D-05** Worksheet output is DOCX. PHPWord. Queue-based `BuildWorksheetJob`.
- **D-06** Worksheet status states: `pending` → `generating` → `draft` → `final` (or `failed`).
- **D-07** O&M: replace Pass 1 (PDF extraction) with ProjectDataService feed. Pass 2 AI retained unchanged.
- **D-08** New Pass 1 replacement reads from `ProjectDataService::resolve($project)`, produces same reviewed data shape as existing Pass 2 expects.
- **D-09** Pass 2 AI scope unchanged: operating procedures, maintenance schedule, fault-finding, asset register.
- **D-10** `OmManualDocxService::build()` and `BuildOmManualJob` not restructured.
- **D-11** Entry point is `OmManualController::generateFromProject()` — already exists, update its implementation.
- **D-12** Cable: auto-generate `CableScheduleItem` records from ProjectDataService equipment + survey `cable_route_desc`.
- **D-13** Item content: from (room + equipment), to (endpoint), cable_type (inferred from category), length/route_notes blank.
- **D-14** New `CableScheduleGeneratorService`. `CableScheduleXlsxService::build()` unchanged.
- **D-15** Engineer edits items in existing CableSchedule show/edit UI before XLSX export.
- **D-16** All three generators triggered from project show page linked records card.
- **D-17** UX pattern: Generate → Generating... (spinner) → Download. Status polling via Alpine.js.
- **D-18** Minimal project show page changes. Existing `$linkedRecords` card already handles all types.

### Claude's Discretion

- Exact cable type inference rules per equipment category
- Whether `BuildWorksheetJob` calls AI per room in parallel or sequentially
- Worksheet model schema (column names, nullable strategy)
- Whether to create a new `OmManualProjectDataService` or modify existing `OmManualGeneratorService`
- PHPWord template vs programmatic build for worksheets (match existing DocxBuilderService pattern)

### Deferred Ideas (OUT OF SCOPE)

None explicitly deferred for this phase.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| WORK-01 | User can generate a worksheet document (DOCX) from project data | New Worksheet model + BuildWorksheetJob + WorksheetDocxService following OmManual pattern |
| WORK-02 | Worksheet contains room-by-room install steps, equipment lists, cable routes, key constraints | PHPWord programmatic DOCX with four-section room blocks; AI generates install steps per room |
| WORK-03 | Worksheet content derived entirely from structured project + survey data (no AI guessing) | ProjectDataService::resolve() feeds WorksheetGeneratorService; AI prompt receives only structured data |
| WORK-04 | Worksheet generation uses queue-based async processing with status tracking | BuildWorksheetJob pattern identical to BuildOmManualJob; status polling via existing Alpine.js pattern |
| OM-01 | User can generate an O&M manual (DOCX) from project data | OmManualController::generateFromProject() already exists; update implementation only |
| OM-02 | O&M contains equipment schedules, system descriptions, maintenance guidance, asset register | Pass 2 AI (OmManualGeneratorService::generateContent) retained unchanged |
| OM-03 | O&M content is equipment-driven — no generic filler, only installed systems included | ProjectDataService equipment list replaces PDF extraction; hardware filter preserved |
| OM-04 | O&M generation uses queue-based async processing with status tracking | BuildOmManualJob already exists; only Pass 1 data source changes |
| CABLE-01 | User can generate a cable schedule (XLSX) from project data | New CableScheduleGeneratorService; trigger from project show page |
| CABLE-02 | Cable schedule contains cable type, from/to, length, route notes | CableScheduleItem fields already match; from/to/cable_type auto-filled, length blank |
| CABLE-03 | Cable data derived from equipment relationships and survey inputs | Category-to-cable-type mapping + survey cable_route_desc enrichment |
| CABLE-04 | Cable schedule generation uses queue-based async processing with status tracking | New BuildCableScheduleJob following BuildOmManualJob pattern |
</phase_requirements>

---

## Summary

Phase 4 builds three document generators from the same ProjectDataService canonical data source. The codebase already has mature patterns for all three: BuildOmManualJob is the definitive job template, OmManualDocxService is the definitive DOCX builder pattern, and CableScheduleXlsxService is the XLSX builder (unchanged). The Worksheet is the only net-new entity (model, migration, job, service, controller, views). The O&M refactor is surgical — only `OmManualController::generateFromProject()` changes internally, replacing `extractFromProjectPackage()` with a direct `ProjectDataService::resolve()` call. The Cable Schedule refactor replaces `CableScheduleService::generateFromQuote()` (AI-from-PDF) with a deterministic `CableScheduleGeneratorService` that maps equipment categories to cable types.

**Primary recommendation:** Build Worksheet first (new, self-contained), then O&M refactor (smallest delta to working system), then Cable Schedule refactor. This ordering de-risks the phase — each generator is independently testable.

**Critical data contract finding:** The existing `OmManualGeneratorService::generateContent()` reads from `$manual->extracted_data` via `buildContentContext()`. The context shape expected by `OmManualPrompt::forContent()` is `{ project_name, project_ref, client_name, site_address, notes, rooms[] }` where each room has `{ name, floor, drawing_ref, equipment[] }`. The new Pass 1 replacement must produce this exact shape from `ProjectDataService::resolve()` output. [VERIFIED: source file read]

---

## Standard Stack

### Core (all already installed)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| phpoffice/phpword | ^1.4 | DOCX generation (Worksheet, O&M) | Already used by DocxBuilderService, OmManualDocxService |
| phpoffice/phpspreadsheet | (via composer.lock) | XLSX generation (Cable Schedule) | Already used by CableScheduleXlsxService |
| Laravel Queue (database driver) | Laravel 12 | Async job processing | Already configured; all existing generators use it |
| Alpine.js | (loaded via app.js) | Frontend polling/spinner | Already used in existing generate flows |

[VERIFIED: composer.json and existing service files read directly]

**Installation:** No new packages required. All dependencies are already installed.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| App\Core\AI\AIManager | internal | AI call orchestration | Worksheet install step generation per room |
| App\Core\Modules\Projects\ProjectDataService | internal | Canonical data source | ALL three generators — only data source allowed |
| App\Services\WorkerMonitorService | internal | Ensure queue worker is running | Before dispatching any job (existing pattern) |

---

## Architecture Patterns

### Recommended Project Structure (new files only)

```
app/
├── Models/
│   └── Worksheet.php                         # NEW — status constants, fillable, relationships
├── Jobs/
│   ├── BuildWorksheetJob.php                 # NEW — mirrors BuildOmManualJob exactly
│   └── BuildCableScheduleJob.php             # NEW — mirrors BuildOmManualJob exactly
├── Services/
│   ├── WorksheetDocxService.php              # NEW — mirrors OmManualDocxService
│   ├── WorksheetGeneratorService.php         # NEW — Pass 1+2: ProjectDataService → DOCX data
│   └── CableScheduleGeneratorService.php     # NEW — ProjectDataService → CableScheduleItem records
├── Core/Modules/
│   └── Worksheet/
│       └── (optional — if AI prompt needed for worksheet steps)
├── Core/AI/Prompts/
│   └── WorksheetPrompt.php                   # NEW — AI install steps per room
├── Http/Controllers/
│   └── WorksheetController.php              # NEW — generate, download, destroy
database/
└── migrations/
    ├── YYYY_MM_DD_create_worksheets_table.php
    └── YYYY_MM_DD_add_project_id_cable_schedules_generate_route.php (if needed)
resources/views/
└── worksheets/
    └── (index, show/download view — minimal)
```

### Pattern 1: Job Pattern (BuildOmManualJob is the definitive template)

**What:** Job receives model ID, runs generation service, sets status, builds file.
**When to use:** All three generators follow this exact pattern.

```php
// Source: app/Jobs/BuildOmManualJob.php — read directly
class BuildWorksheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $worksheetId) {}

    public function handle(
        WorksheetGeneratorService $generator,
        WorksheetDocxService      $docxService,
    ): void {
        $worksheet = Worksheet::find($this->worksheetId);
        if (! $worksheet) { return; } // deleted — discard silently

        try {
            $generatedData = $generator->generateContent($worksheet);

            $worksheet->update([
                'generated_data' => $generatedData,
                'status'         => Worksheet::STATUS_DRAFT,
                'error_message'  => null,
            ]);

            $docxService->build($generatedData, $worksheet);

        } catch (\Throwable $e) {
            $worksheet->update([
                'status'        => Worksheet::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Worksheet::find($this->worksheetId)
            ?->update(['status' => Worksheet::STATUS_FAILED, 'error_message' => $e->getMessage()]);
    }
}
```

[VERIFIED: BuildOmManualJob.php read directly]

### Pattern 2: generateFromProject Controller Method

**What:** Synchronous "Pass 1" populates the model, then dispatches async job.
**When to use:** All three generators triggered from project show page.

```php
// Source: app/Http/Controllers/OmManualController.php — read directly
public function generateFromProject(Project $project): RedirectResponse
{
    abort_if($project->user_id !== auth()->id() && auth()->user()?->role !== 'admin', 403);

    // Sync: create the model record (fast, no AI)
    $worksheet = Worksheet::create([
        'project_id'   => $project->id,
        'user_id'      => auth()->id(),
        'project_name' => $project->name,
        'status'       => Worksheet::STATUS_GENERATING,
    ]);

    app(WorkerMonitorService::class)->ensureRunning();
    BuildWorksheetJob::dispatch($worksheet->id);

    return back()->with('success', 'Worksheet generation queued.');
}
```

[VERIFIED: OmManualController::generateFromProject() read directly]

### Pattern 3: ProjectDataService → O&M Context Shape

**What:** The exact data shape `OmManualPrompt::forContent()` expects, mapped from ProjectDataService output.
**Critical finding:** The existing `buildContentContext()` method in `OmManualGeneratorService` reads `$manual->extracted_data['equipment']` and builds a single `General` room. The new Pass 1 must build a proper rooms array from `ProjectDataService::resolve()['rooms']`.

```php
// New OmManualProjectDataService (or update OmManualGeneratorService)
// Maps ProjectDataService::resolve() output to OmManualPrompt::forContent() context shape
private function buildContextFromProjectData(Project $project): array
{
    $data = $this->projectDataService->resolve($project);

    $rooms = array_map(function (array $room) {
        return [
            'name'        => $room['name'] ?? 'Unknown Room',
            'floor'       => $room['floor'] ?? null,
            'drawing_ref' => $room['drawing_ref'] ?? '',
            'equipment'   => array_map(fn($eq) => [
                'qty'          => $eq['quantity'] ?? $eq['qty'] ?? 1,
                'name'         => $eq['name'] ?? $eq['description'] ?? '',
                'description'  => $eq['description'] ?? $eq['name'] ?? '',
                'model'        => $eq['model'] ?? '',
                'manufacturer' => $eq['manufacturer'] ?? '',
                'part_no'      => $eq['part_no'] ?? '',
                'category'     => $eq['category'] ?? 'Other',
            ], $room['equipment'] ?? []),
        ];
    }, $data['rooms'] ?? []);

    return [
        'project_name' => $data['project']['name'] ?? '',
        'project_ref'  => $data['project']['quote_reference'] ?? '',
        'client_name'  => $data['project']['client_name'] ?? '',
        'site_address' => $data['project']['site_address'] ?? '',
        'notes'        => $data['survey']['h_and_s_notes'] ?? '',
        'rooms'        => $rooms,
    ];
}
```

[VERIFIED: OmManualGeneratorService::buildContentContext() + OmManualPrompt::forContent() read directly]

### Pattern 4: Cable Type Inference Table

**What:** Deterministic category-to-cable-type mapping. No AI involved. [ASSUMED — categories from codebase, cable types from AV industry norms]

| Equipment Category | Primary Cable Type | Cores | Notes |
|-------------------|-------------------|-------|-------|
| Display / Screen | HDMI 2.0 | — | For runs under 10m; HDBaseT for longer |
| Display (long run) | Cat6 (HDBaseT) | — | Via AV over IP / HDBaseT extender |
| Speaker | 2-Core Speaker Cable | 2 | Low-impedance 8Ω |
| DSP / Amplifier | Audio Snake / Multicore | varies | signal routing |
| Camera / VC | Cat6 (USB over IP or direct USB) | — | Or HDMI for local |
| Switch / Networking | Cat6 | — | Always Cat6 data |
| Control / Controller | Cat6 or RS-232 | — | Depends on device |
| Mount / Infrastructure | N/A | — | No cable generated |
| Cables / Consumables | Skip | — | Already a cable, no item generated |
| Services | Skip | — | Labour line, no item generated |

**Planner note:** The exact mapping is Claude's discretion per CONTEXT.md. The table above is a starting hypothesis the implementer refines. The critical rule is: cables, consumables, services, and option items from the equipment list must be excluded from cable item generation (same filter as `filterHardwareItems()` in OmManualGeneratorService).

### Pattern 5: Worksheet Model Schema

**What:** Follows OmManual schema with simplified status states.

```php
// Proposed Worksheet schema — Claude's discretion per CONTEXT.md
Schema::create('worksheets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
    $table->string('project_name', 200);
    $table->string('project_ref', 50)->nullable();
    $table->string('client_name', 150)->nullable();
    $table->string('site_address', 500)->nullable();
    $table->string('status', 30)->default('pending'); // pending|generating|draft|final|failed
    $table->string('error_message', 1000)->nullable();
    $table->json('generated_data')->nullable();       // rooms[] with AI steps
    $table->string('filename', 255)->nullable();      // stored in storage/app/worksheets/
    $table->timestamps();
    $table->softDeletes();
});
```

[ASSUMED — modelled on om_manuals migration, adapted to Worksheet context]

### Pattern 6: CableSchedule Model Gaps

**Finding:** `CableSchedule` model does NOT have `project_id` in its `$fillable`. The `project_id` column was added by migration `2026_03_14_000020_add_project_id_to_module_tables.php` but the model `$fillable` array only has: `user_id`, `project_name`, `project_ref`, `client_name`, `source_filename`, `status`. [VERIFIED: CableSchedule.php read directly]

**Action required:** Add `project_id` to `$fillable` on `CableSchedule` model, and add `project()` BelongsTo relationship. Also add `filename` to `$fillable` — the `CableScheduleXlsxService::build()` calls `$schedule->update(['filename' => $filename])` but `filename` is not in `$fillable`. [VERIFIED: CableScheduleXlsxService.php + CableSchedule.php read directly]

**Also:** `CableSchedule` needs a `generateFromProject` controller action (similar to O&M). Current `CableScheduleController` only has `store()` via PDF upload and no project-linked generate path. Route `cable-schedules.create` passes `project_id` as query param but doesn't auto-generate — Phase 4 adds a POST `cable-schedules/generate-from-project/{project}` route. [VERIFIED: routes/web.php + CableScheduleController.php read directly]

### Anti-Patterns to Avoid

- **Sending the full ProjectDataService array directly to AI:** The context must be shaped to match what each prompt expects. Pass only what the prompt is designed to receive.
- **Modifying OmManualDocxService or CableScheduleXlsxService:** These are locked per D-10 and D-14. The refactor changes the data source, not the rendering layer.
- **Rebuilding the extracted_data review step for O&M:** D-07 removes the review step for project-linked O&Ms. ProjectDataService data is already reviewed — no intermediate edit screen needed.
- **Dispatching two jobs in BuildWorksheetJob (one per room):** Sequential per-room AI calls within a single job is simpler and avoids job fan-out complexity. Per CONTEXT.md this is Claude's discretion — sequential is the safer default.
- **Making cable_type nullable by default:** The cable type must be set at generation time. Use a fallback of `'Unknown'` rather than null so the engineer can see which items need attention.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| DOCX file creation | Custom XML builder | PHPWord (already installed) | Page layout, styles, tables, headers — enormous complexity |
| XLSX file creation | Custom CSV or XML | PhpSpreadsheet (already installed) | Column widths, cell styles, merged cells |
| Queue dispatch | Custom async | Laravel Queue + `ShouldQueue` | Retries, failure hooks, status tracking all built in |
| AI provider selection | Hard-coded provider | `config('ai.default')` + AIManager | Provider-agnostic, swappable |
| Worker lifecycle | Process spawning | `WorkerMonitorService::ensureRunning()` | Already handles the ensure-running pattern |

---

## Common Pitfalls

### Pitfall 1: O&M extracted_data shape mismatch

**What goes wrong:** The new Pass 1 replacement produces a context array that doesn't match what `OmManualGeneratorService::buildContentContext()` reads from `$manual->extracted_data`. `buildContentContext()` reads `$manual->extracted_data['equipment']` (a flat list), not `extracted_data['rooms']`. If the new service stores rooms-keyed data in `extracted_data`, Pass 2 will get empty equipment.

**Why it happens:** `extractFromProjectPackage()` stored `{ project: {}, rooms: [] }` in `extracted_data`. The current `buildContentContext()` reads `extracted_data['equipment']` (the flat list from local extraction). There are two different shapes in play depending on which Pass 1 path was used.

**How to avoid:** The new OmManualProjectDataService (or updated `generateFromProject()` method) should either: (a) build context fresh from ProjectDataService at job dispatch time (not stored in extracted_data), or (b) store the pre-shaped context array that `buildContentContext()` expects. Option (a) is cleaner — store project_id reference, resolve at job time.

**Warning signs:** `OmManual` records with `generated_data` containing empty rooms arrays despite having equipment in the project.

### Pitfall 2: CableSchedule model missing project_id in $fillable

**What goes wrong:** `CableSchedule::create(['project_id' => $project->id, ...])` silently ignores `project_id` because it's not in `$fillable`. The record is created unlinked.

**Why it happens:** The `project_id` column was added by migration but the model `$fillable` was never updated.

**How to avoid:** Add `project_id` and `filename` to `CableSchedule::$fillable` and add the `project()` BelongsTo relationship in the same task as the new generator service.

**Warning signs:** `cable_schedules.project_id` is null for project-linked records.

### Pitfall 3: CableScheduleXlsxService cannot find the download file

**What goes wrong:** `$schedule->update(['filename' => $filename])` in `CableScheduleXlsxService::build()` silently fails because `filename` is not in `$fillable`. The XLSX is written to disk but the model has no filename — download returns 404.

**Why it happens:** Same root cause as pitfall 2. `filename` was not in the original `$fillable`.

**How to avoid:** Fix `$fillable` before building the CableScheduleGeneratorService.

### Pitfall 4: Worksheet AI prompt context too large

**What goes wrong:** A project with 20+ rooms and 5+ equipment items per room produces a very large context. Claude's token limit per request (8192 output tokens for O&M) may be insufficient if the worksheet prompt is equally verbose.

**Why it happens:** Per-room AI generation amplifies context size vs. a single-document call.

**How to avoid:** The `WorksheetPrompt` should request concise install steps (3-5 bullet points per room), not full narrative paragraphs. Cap `maxTokens()` at 4096 for worksheet prompts. If the project has many rooms, consider batching: one AI call per 5 rooms.

**Warning signs:** `AIGenerationException` with "max tokens" in the message.

### Pitfall 5: Project show page Worksheet card showing "Coming in Phase 4"

**What goes wrong:** The project show blade has a hard-coded `@if($entry['type'] === 'Worksheet') Coming in Phase 4.` check that overrides the empty-action button rendering.

**Why it happens:** Phase 1 scaffolded the card with a placeholder. [VERIFIED: projects/show.blade.php read directly, line 293-295]

**How to avoid:** Remove the Phase 4 placeholder string and populate the Worksheet `$linkedRecords` entry with a real generate route and action label.

### Pitfall 6: Cable schedule items generated from cables/consumables/services line items

**What goes wrong:** The equipment list from ProjectDataService includes cables, consumables, and service lines (installation, project management). Generating cable items from these produces nonsense records (a cable with From: "Installation Labour" To: "Rack").

**Why it happens:** Equipment list is not pre-filtered in ProjectDataService — it returns all line items.

**How to avoid:** Apply the same filter as `OmManualGeneratorService::filterHardwareItems()` in `CableScheduleGeneratorService`. Exclude categories: `cables`, `consumables`, `services`, `option`. Apply keyword fallback for uncategorised items (cable, cat5, cat6, hdmi, install, commission, etc.).

---

## Code Examples

### Existing OmManualGeneratorService::buildContentContext() — exact shape consumed by Pass 2

```php
// Source: app/Core/Modules/OMManual/OmManualGeneratorService.php (read directly)
// This is what OmManualPrompt::forContent() receives. New Pass 1 must produce this.
return [
    'project_name' => $manual->project_name ?? 'AV Installation',
    'project_ref'  => $manual->project_ref  ?? '',
    'client_name'  => $manual->client_name  ?? '',
    'site_address' => $manual->site_address ?? '',
    'notes'        => '',
    'rooms'        => [[
        'name'        => 'General',
        'floor'       => null,
        'drawing_ref' => '',
        'equipment'   => [
            ['qty' => 1, 'name' => '...', 'description' => '...', 'model' => '', 'manufacturer' => '', 'part_no' => '', 'category' => 'Other'],
        ],
    ]],
];
// Currently uses a single 'General' room. New implementation should use actual rooms from ProjectDataService.
```

### ProjectDataService::resolve() output shape (equipment and rooms keys)

```php
// Source: app/Core/Modules/Projects/ProjectDataService.php (read directly)
// resolve() returns:
[
    'project'   => ['id', 'name', 'client_name', 'site_address', 'quote_reference', 'status', 'created_at'],
    'equipment' => [ // each item: ['name', 'quantity', 'description', 'model', 'manufacturer', 'part_no', 'category', 'area', 'data_source', 'confidence'] ],
    'rooms'     => [ // each room: ['name', 'floor', 'drawing_ref', 'equipment' => [...], 'data_source', 'confidence']
                     // + survey enrichment: ['ceiling_type', 'ceiling_height_m', 'cable_route_desc', 'has_power', 'power_outlet_count', 'network_port_count', ...] ],
    'survey'    => ['has_survey', 'submitted_at', 'site_risks', 'access_constraints', 'h_and_s_notes', 'general_notes', 'rooms' => [...]],
    'meta'      => ['data_source', 'has_survey', 'survey_complete', 'confidence'],
    // also: activities, risks, programme, cables
]
```

### WorkerMonitorService dispatch pattern

```php
// Source: app/Http/Controllers/OmManualController.php (read directly)
app(WorkerMonitorService::class)->ensureRunning();
BuildOmManualJob::dispatch($manual->id);
```

### CableScheduleXlsxService — what it reads from CableScheduleItem

```php
// Source: app/Services/CableScheduleXlsxService.php (read directly)
// Reads these fields from each CableScheduleItem:
$item->cable_id         // nullable — auto-assigned as CS-001, CS-002 etc. or left null
$item->from_location    // required — room name + equipment name
$item->to_location      // required — rack/endpoint description
$item->cable_type       // required — HDMI 2.0 / Cat6 / 2-Core Speaker Cable etc.
$item->cores            // nullable — "2" for speaker cable, null for HDMI
$item->approx_length_m  // nullable — left blank for engineer
$item->notes            // nullable — from survey cable_route_desc
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| O&M Pass 1: AI reads raw PDF | O&M Pass 1: ProjectDataService::resolve() | Phase 4 | No PDF upload required; equipment data is reviewed |
| Cable Schedule: AI-from-PDF via CableScheduleService | Cable Schedule: deterministic from ProjectDataService | Phase 4 | Reproducible; no AI hallucination risk |
| Worksheet: did not exist | Worksheet: new model + job + DOCX | Phase 4 | New capability |

**Deprecated/outdated after Phase 4:**
- `OmManualService::extractFromQuote()` — old PDF-based Pass 1, no longer called for project-linked O&Ms (standalone PDF upload flow may still use it)
- `CableScheduleService::generateFromQuote()` — replaced by `CableScheduleGeneratorService`
- `OmManualGeneratorService::extractFromProjectPackage()` — replaced by ProjectDataService feed

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Worksheet model schema (column names, nullable strategy) | Architecture Patterns | Migration needs revising; low risk as schema is additive |
| A2 | Cable type inference table (HDMI, Cat6, 2-Core Speaker etc.) | Architecture Patterns | Wrong cable types generated; engineer corrects in edit UI |
| A3 | Sequential (not parallel) AI calls per room in BuildWorksheetJob | Architecture Patterns | No functional impact; parallel would be faster but riskier |
| A4 | `WorksheetPrompt` should request concise steps with 4096 token cap | Common Pitfalls | Token limit hit for large projects; adjust cap in prompt |

---

## Open Questions

1. **Should OmManualGeneratorService be modified or should a new service be created?**
   - What we know: D-07 says replace Pass 1, D-08 says new service or modified existing. Modifying `generateFromProject()` in the controller is the minimal change.
   - What's unclear: Whether the existing `extractFromProjectPackage()` method is used elsewhere (it's called only from `storeFromProject()` and `generateFromProject()` in OmManualController).
   - Recommendation: Create a dedicated private method in OmManualController's `generateFromProject()` that calls ProjectDataService directly and stores the result in `extracted_data`. No new class needed unless complexity grows.

2. **Does `BuildCableScheduleJob` need to be async, or can cable generation be synchronous?**
   - What we know: CABLE-04 requires queue-based async. Cable item creation is fast (no AI call, just DB inserts). But D-17 specifies the spinner UX pattern.
   - What's unclear: Whether a synchronous generate + redirect would be acceptable for cable schedules given the absence of AI calls.
   - Recommendation: Implement as queued job (matches CABLE-04 and consistency with other generators) even if generation is fast. The spinner UX is already the project standard.

3. **Where does the Worksheet download route redirect after generation?**
   - What we know: O&M redirects to `om-manuals.edit` after queuing. Cable redirects to `cable-schedules.edit`.
   - What's unclear: Worksheet has no edit view (no review step). Download is the terminal action.
   - Recommendation: Redirect to `projects.show` with success flash. Add a Download button to the Worksheet entry in `$linkedRecords` when status is `draft` or `final`.

---

## Environment Availability

Step 2.6: SKIPPED — all dependencies (PHPWord, PhpSpreadsheet, Laravel Queue, Alpine.js) are already installed and verified in use by existing services. No external binaries required. No new packages needed.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 |
| Config file | phpunit.xml |
| Quick run command | `php artisan test --filter=Worksheet` |
| Full suite command | `php artisan test` |

Tests use SQLite in-memory (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). `QUEUE_CONNECTION=sync` means jobs run synchronously in tests — no async complexity. AI calls must be mocked via Mockery.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| WORK-01 | POST generate-from-project creates Worksheet record and dispatches job | Feature | `php artisan test --filter=WorksheetGenerateTest` | ❌ Wave 0 |
| WORK-02 | Generated DOCX file exists on disk after job | Feature | `php artisan test --filter=WorksheetDocxTest` | ❌ Wave 0 |
| WORK-03 | WorksheetGeneratorService reads from ProjectDataService, not raw package | Unit | `php artisan test --filter=WorksheetGeneratorServiceTest` | ❌ Wave 0 |
| WORK-04 | BuildWorksheetJob sets status=generating, then draft on success, failed on exception | Feature | `php artisan test --filter=BuildWorksheetJobTest` | ❌ Wave 0 |
| OM-01 | POST generate-from-project creates OmManual record using ProjectDataService | Feature | `php artisan test --filter=OmManualProjectDataTest` | ❌ Wave 0 |
| OM-02 | Pass 2 AI context contains rooms array from project data (not flat equipment) | Unit | `php artisan test --filter=OmManualContextShapeTest` | ❌ Wave 0 |
| OM-03 | Hardware filter excludes cables/consumables/services from O&M content | Unit | `php artisan test --filter=OmManualHardwareFilterTest` | ❌ Wave 0 |
| OM-04 | BuildOmManualJob picks up OmManual with new extracted_data shape | Feature | `php artisan test --filter=BuildOmManualJobRefactorTest` | ❌ Wave 0 |
| CABLE-01 | POST generate-from-project creates CableSchedule + CableScheduleItems | Feature | `php artisan test --filter=CableScheduleGenerateTest` | ❌ Wave 0 |
| CABLE-02 | CableScheduleItems have from_location, to_location, cable_type populated | Unit | `php artisan test --filter=CableScheduleGeneratorServiceTest` | ❌ Wave 0 |
| CABLE-03 | Category-to-cable-type mapping produces correct types | Unit | `php artisan test --filter=CableTypeInferenceTest` | ❌ Wave 0 |
| CABLE-04 | BuildCableScheduleJob sets status=generating, then draft on success | Feature | `php artisan test --filter=BuildCableScheduleJobTest` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=<TestClass>`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

All test files for this phase are new. The following must be created in Wave 0:

- [ ] `tests/Feature/Worksheet/WorksheetGenerateTest.php` — covers WORK-01, WORK-04
- [ ] `tests/Feature/Worksheet/WorksheetDocxTest.php` — covers WORK-02
- [ ] `tests/Unit/Services/WorksheetGeneratorServiceTest.php` — covers WORK-03
- [ ] `tests/Feature/OmManual/OmManualProjectDataRefactorTest.php` — covers OM-01, OM-02, OM-03, OM-04
- [ ] `tests/Feature/CableSchedule/CableScheduleGenerateTest.php` — covers CABLE-01, CABLE-04
- [ ] `tests/Unit/Services/CableScheduleGeneratorServiceTest.php` — covers CABLE-02, CABLE-03

Existing infrastructure: PHPUnit + SQLite in-memory, `RefreshDatabase` trait, Mockery — all ready.

---

## Security Domain

No new authentication surfaces are introduced. All new routes are under `auth` middleware (consistent with existing routes). The `abort_if($project->user_id !== auth()->id() && ...)` pattern from `OmManualController::generateFromProject()` must be replicated in `WorksheetController` and `CableScheduleController::generateFromProject()`.

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V4 Access Control | Yes | `abort_if` owner check + admin bypass (existing pattern) |
| V5 Input Validation | Minimal | No user input beyond project route model binding |
| V2 Authentication | Yes (inherited) | `auth` middleware on all routes |

No new file upload surfaces. DOCX/XLSX files stored in `storage/app/` (not public). Download responses use `Storage::disk('local')->download()` with correct Content-Type headers (existing pattern).

---

## Sources

### Primary (HIGH confidence — read directly from codebase)

- `app/Jobs/BuildOmManualJob.php` — definitive job pattern
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — Pass 1/2 pipeline, buildContentContext() shape
- `app/Services/OmManualService.php` — old Pass 1 being replaced
- `app/Services/OmManualDocxService.php` — DOCX builder pattern
- `app/Http/Controllers/OmManualController.php` — generateFromProject() entry point
- `app/Services/CableScheduleService.php` — old AI-from-PDF service being replaced
- `app/Services/CableScheduleXlsxService.php` — XLSX builder (unchanged), reveals missing $fillable
- `app/Models/CableSchedule.php` — confirms project_id and filename not in $fillable
- `app/Models/CableScheduleItem.php` — item fields
- `app/Models/OmManual.php` — status constants, fillable pattern to follow
- `app/Http/Controllers/CableScheduleController.php` — confirms no project-linked generate route
- `app/Core/Modules/Projects/ProjectDataService.php` — resolve() return shape
- `app/Http/Controllers/ProjectController.php` — $linkedRecords construction, Worksheet placeholder
- `app/Core/AI/Prompts/OmManualPrompt.php` — exact context shape for Pass 2
- `resources/views/projects/show.blade.php` — "Coming in Phase 4" placeholder confirmed
- `routes/web.php` — existing routes, confirms om-manuals.generate-from-project exists
- `database/migrations/2026_03_09_000002_create_cable_schedules_table.php` — cable_schedules schema
- `database/migrations/2026_03_09_000005_create_om_manuals_table.php` — om_manuals schema
- `phpunit.xml` — test configuration, SQLite in-memory, sync queue

### Secondary (MEDIUM confidence)

- CLAUDE.md — project conventions, naming patterns, architecture constraints

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries verified in existing services
- Architecture: HIGH — patterns read directly from BuildOmManualJob, OmManualController, OmManualGeneratorService
- Data contracts: HIGH — OmManualPrompt::forContent() context shape verified from source
- Pitfalls: HIGH — CableSchedule $fillable gap verified from source; others verified from code flow
- Cable type inference: MEDIUM — categories verified, specific type mappings are discretion-level assumptions

**Research date:** 2026-04-11
**Valid until:** 2026-05-11 (stable stack; only internal code changes would affect this)

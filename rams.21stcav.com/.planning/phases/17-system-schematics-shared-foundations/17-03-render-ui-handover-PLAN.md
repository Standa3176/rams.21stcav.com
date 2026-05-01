---
phase: 17-system-schematics-shared-foundations
plan: 03
type: execute
wave: 3
depends_on: ["17-01", "17-02"]
files_modified:
  - app/Services/Drawings/DrawingExportRendererService.php
  - app/Jobs/BuildSchematicJob.php
  - app/Http/Controllers/ProjectDrawingController.php
  - app/Services/OmManualDocxService.php
  - app/Console/Commands/PdfSmokeTestCommand.php
  - resources/views/projects/drawings/index.blade.php
  - resources/views/projects/drawings/show.blade.php
  - resources/views/projects/drawings/_status-pill.blade.php
  - resources/views/projects/drawings/_regenerate-confirm-modal.blade.php
  - resources/views/projects/show.blade.php
  - routes/web.php
autonomous: true
requirements: [DRAW-05, DRAW-06, DRAW-26, DRAW-27]
must_haves:
  truths:
    - "User clicking 'Generate Schematic' on a project sees the new schematic appear in the drawings index with status flipping draft → generating → ready"
    - "User can download an individual schematic as PDF (rendered via PdfRenderService::fromBlade with the Plan 02 Blade view), SVG (writes generated_svg directly), or PNG (rendered via PdfRenderService::fromBladeAsPng — Warning 8: NO Browsershot duplication)"
    - "User clicking 'Regenerate' on a drawing whose canvas_state is non-null sees a confirm modal warning that prior edits will be archived (lock-on-edit prompt UX) — DRAW-05 scaffolding only; functional editor in Phase 19"
    - "User can change a schematic's status via UI between draft/for_review/approved (set_status operation through DrawingEditAdapter; superseded auto-set by regenerate flow only)"
    - "When OmManualDocxService builds an O&M Manual, it appends a 'Drawings' section embedding each ready drawing as PNG via PhpWord addImage() — one drawing per page (DRAW-26). The new section is added by opening a fresh PhpWord section via $phpWord->addSection($this->sectionProps()), NOT by reusing the previous $s variable (Blocker 3)."
    - "php artisan pdf:smoke-test --drawings runs against a fixture project and produces a non-empty PDF, asserting PDF render path is healthy"
    - "Drawings index page links from /projects/{project}/show via a 'Drawings' tab/section so engineers find the page from existing project navigation"
    - "createSchematic controller action calls DrawingService::createForProject() THEN DrawingService::generateInitial() — never createForProject + regenerate (which would archive the just-created row). Warning 9 fix."
  artifacts:
    - path: "app/Services/Drawings/DrawingExportRendererService.php"
      provides: "Single entrypoint for PDF/SVG/PNG export — wraps PdfRenderService::fromBlade (PDF) and PdfRenderService::fromBladeAsPng (PNG); never instantiates Browsershot directly."
    - path: "resources/views/projects/drawings/index.blade.php"
      provides: "Drawings list page with kind-grouped cards, status pill, regenerate/download buttons"
    - path: "resources/views/projects/drawings/show.blade.php"
      provides: "Per-drawing preview page with embedded SVG + status controls + per-format download links"
    - path: "resources/views/projects/drawings/_regenerate-confirm-modal.blade.php"
      provides: "Lock-on-edit confirm modal (DRAW-05 UX scaffolding)"
  key_links:
    - from: "app/Services/Drawings/DrawingExportRendererService.php"
      to: "PdfRenderService::fromBlade('pdf.drawings.schematic', ['drawing' => $drawing])"
      via: "Method renderPdf()"
      pattern: "fromBlade.*pdf\\.drawings\\.schematic"
    - from: "app/Services/Drawings/DrawingExportRendererService.php"
      to: "PdfRenderService::fromBladeAsPng('pdf.drawings.schematic', ['drawing' => $drawing])"
      via: "Method renderPng() — Warning 8: delegates to centralised Browsershot construction in PdfRenderService"
      pattern: "fromBladeAsPng"
    - from: "app/Services/OmManualDocxService.php"
      to: "DrawingExportRendererService::ensurePngForHandover"
      via: "Drawings section in DOCX build (added via $phpWord->addSection — Blocker 3)"
      pattern: "ensurePngForHandover|TYPE_DRAWING"
    - from: "app/Http/Controllers/ProjectDrawingController.php"
      to: "ProjectDrawingPolicy::view + DrawingExportRendererService"
      via: "download(format) action"
      pattern: "DrawingExportRendererService"
    - from: "app/Http/Controllers/ProjectDrawingController.php"
      to: "DrawingService::createForProject + DrawingService::generateInitial (Warning 9)"
      via: "createSchematic action — uses generateInitial (NOT regenerate) for v1 dispatch"
      pattern: "createForProject|generateInitial"
    - from: "resources/views/projects/show.blade.php"
      to: "/projects/{project}/drawings"
      via: "Tab/link added"
      pattern: "projects\\.drawings\\.index"
    - from: "app/Console/Commands/PdfSmokeTestCommand.php"
      to: "DrawingExportRendererService"
      via: "--drawings flag dispatching schematic render against a fixture"
      pattern: "drawings.*flag|--drawings"
---

<objective>
Wire up the schematic Blade view (from Plan 02) into the PDF/SVG/PNG export pipeline; fill the `ProjectDrawingController` index/show/download bodies; wire the O&M Manual handover to embed ready drawings as PNG (DRAW-26); extend `pdf:smoke-test` with a `--drawings` flag; add the regenerate confirm modal (lock-on-edit prompt — DRAW-05 scaffolding); link drawings from the project show page; and provide UI controls for changing status (draft/for_review/approved/superseded — DRAW-25).

Purpose: Deliver DRAW-05 (lock-on-edit prompt UX scaffolding — full editor in Phase 19), DRAW-06 (export schematic as PDF and SVG), DRAW-26 (drawings included in O&M handover via PNG embed), DRAW-27 (download individual drawing as PDF, SVG, or PNG). Closes the Phase 17 user journey: click Generate → schematic appears → preview → download → embed in O&M.

**Wave + ordering note (Warning 6):** This plan modifies `app/Jobs/BuildSchematicJob.php` in a different region from Plan 02. To avoid coordination hazards, `depends_on` is `["17-01", "17-02"]` — this plan runs in **Wave 3** (or as a follower of Plan 02 in Wave 2 — execute-phase will resolve based on `gsd-tools` wave assignment, but in either case Plan 02 commits FIRST). When this plan edits BuildSchematicJob, it sees the post-Plan-02 `handle()` body (with `$generator->generate($drawing)` already in place) and inserts the thumbnail block AFTER that call and BEFORE the completion email send.

**Co-edit hazard (Warning 6):** `app/Jobs/BuildSchematicJob.php` — disjoint regions, sequential plan execution. Plan 02 owns the SVG-writing block; Plan 03 owns the post-success thumbnail block.

Output:
- `DrawingExportRendererService` — single source of truth for PDF/SVG/PNG export. **Delegates Browsershot construction to `PdfRenderService::fromBladeAsPng` (Warning 8). Never instantiates Browsershot directly.**
- Filled `ProjectDrawingController` (index, show, download per-format, status update). **createSchematic uses `DrawingService::generateInitial` after `createForProject` (Warning 9 — option (a)).**
- Drawings list + preview Blade views with status pill, regenerate confirm modal, per-format download links.
- "Drawings" link/section added to `resources/views/projects/show.blade.php`.
- `OmManualDocxService` patched to append a "Drawings" section embedding ready drawings as PNG. **Patch opens a fresh PhpWord section via `$phpWord->addSection($this->sectionProps())` (Blocker 3) — does NOT reuse `$s` from a prior section nor depend on a `$section` variable that doesn't exist in scope.**
- `pdf:smoke-test --drawings` flag rendering a fixture schematic to PDF.
- New routes: per-format download + status update + create-schematic.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/17-system-schematics-shared-foundations/17-CONTEXT.md
@.planning/phases/17-system-schematics-shared-foundations/17-01-foundations-PLAN.md
@.planning/phases/17-system-schematics-shared-foundations/17-02-schematic-generator-PLAN.md
@.planning/research/SUMMARY.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Models/ProjectDrawing.php
@app/Models/OmManual.php
@app/Services/PdfRenderService.php
@app/Services/DocumentArtifactStorage.php
@app/Services/Drawings/DrawingService.php
@app/Services/OmManualDocxService.php
@app/Http/Controllers/OmManualController.php
@app/Console/Commands/PdfSmokeTestCommand.php
@resources/views/pdf/drawings/schematic.blade.php
@resources/views/pdf/drawings/_title-block.blade.php
@routes/web.php

<interfaces>
<!-- Plan 01 + 02 landed — Plan 03 builds against. -->

From app/Services/Drawings/DrawingService.php (Plan 01 — FOUR methods, post-revision):
```php
public function createForProject(Project $project, string $kind, ?int $roomId, int $userId): ProjectDrawing;
public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing;   // Warning 9 — first-version dispatch (no archive-prior)
public function regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing;       // archive-prior + dispatch (revisions only)
public function archivePrior(ProjectDrawing $existing, ProjectDrawing $newRow): void;
```

From app/Models/ProjectDrawing.php (Plan 01):
```php
const STATUS_DRAFT, STATUS_FOR_REVIEW, STATUS_APPROVED, STATUS_SUPERSEDED, STATUS_GENERATING, STATUS_READY, STATUS_FAILED;
public function isReady(): bool;
public function hasUserEdits(): bool;       // canvas_state non-empty
public function statusBadgeClass(): string;
public function statusLabel(): string;
public function kindLabel(): string;
public function revisionLabel(): string;
```

From app/Models/OmManual.php (verified during Plan 03 revision — `project()` relation exists):
```php
public function project(): BelongsTo { return $this->belongsTo(Project::class); }
// Confirmed: $manual->project returns the Project model — Blocker 3 access pattern is safe.
```

From app/Services/OmManualDocxService.php (verified during Plan 03 revision — section-per-block pattern):
```php
// build() opens a NEW section per major block (lines 88, 125, 131, 135, 139, 143, 147, 151).
// Each is a local variable: $s = $phpWord->addSection($this->sectionProps());
// The Drawings section MUST follow the same pattern — open its own section. Do NOT reuse
// $s from the Document Control block (variable name collision, append-after-save risk).
$s = $phpWord->addSection($this->sectionProps());
$this->buildDocumentControlSection($s, $sectionNum, $manual);
// ↓ NEW Drawings section — Blocker 3 fix below ↓
```

From app/Services/PdfRenderService.php (Plan 01 extended):
```php
public function fromBlade(string $view, array $data, ?string $writeToPath = null, array $options = []): string;
// Option: 'waitForJs' (bool, default false) — Phase 17 schematics: false. Phase 19 floor plans: true.

public function fromBladeAsPng(string $view, array $data, ?string $writeToPath = null, array $options = []): string;
// Plan 01 added this method (Warning 8). Options: widthPx (int, default 1920), heightPx (int, default widthPx*0.707), waitForJs (bool, default false).
// Returns absolute path when $writeToPath given, raw PNG bytes otherwise.
// Uses the SAME chrome-path / no-sandbox / chromium-args construction as fromBlade — Phase 20's CRIT-03 hardening lands in one place.
```

From app/Services/DocumentArtifactStorage.php (Plan 01):
```php
public const TYPE_DRAWING = 'drawings';
public function writePath(string $type, string $filename): string;
public function readPath(string $type, string $filename): ?string;
```

From resources/views/pdf/drawings/schematic.blade.php (Plan 02): renders SVG + title block, A4 landscape.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: DrawingExportRendererService + Job wiring + downloads + smoke test</name>
  <read_first>
    - app/Services/PdfRenderService.php (call signature + options — Plan 01 added fromBladeAsPng; Warning 8 says use it instead of constructing Browsershot directly)
    - app/Services/DocumentArtifactStorage.php (writePath/readPath/TYPE_DRAWING)
    - app/Console/Commands/PdfSmokeTestCommand.php (existing smoke-test command — extend with --drawings flag)
    - app/Http/Controllers/OmManualController.php (download method shape — mirror)
    - app/Jobs/BuildSchematicJob.php (post-Plan-02 state — handle() now calls $generator->generate($drawing); this task adds PNG thumbnail generation AFTER that call and BEFORE the completion email)
  </read_first>
  <action>
    Three deliverables: the renderer service, the smoke-test extension, and the controller download routes.

    **`app/Services/Drawings/DrawingExportRendererService.php`:**

    Single entrypoint for "drawing → PDF / SVG / PNG". Constructor injects `PdfRenderService` and `DocumentArtifactStorage`. **Warning 8 — uses `PdfRenderService::fromBladeAsPng()` for the PNG path; does NOT construct Browsershot directly.**

    Public methods:
    ```php
    /**
     * Render the drawing to PDF using the kind-appropriate Blade view.
     * Returns absolute path to the written PDF.
     */
    public function renderPdf(ProjectDrawing $drawing): string;

    /**
     * Write the drawing's SVG (from generated_svg or canvas_state if Phase 19) to disk.
     * For Phase 17 schematics: just dumps generated_svg into a TYPE_DRAWING file.
     * Returns absolute path.
     */
    public function renderSvg(ProjectDrawing $drawing): string;

    /**
     * Capture a PNG screenshot via PdfRenderService::fromBladeAsPng (uses the same Blade view as renderPdf).
     * Returns absolute path. NEVER instantiates Browsershot directly — Phase 20's CRIT-03
     * hardening lives in PdfRenderService and this method picks it up automatically.
     */
    public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string;

    /**
     * For O&M Manual handover (DRAW-26). Idempotent: returns the cached PNG if it
     * exists for this drawing version; otherwise generates one. Filename pattern:
     * drawings/handover-png/drawing-{id}-v{version}.png.
     */
    public function ensurePngForHandover(ProjectDrawing $drawing): ?string;
    ```

    Implementation notes:
    - Blade view selection: `match ($drawing->kind) { KIND_SCHEMATIC => 'pdf.drawings.schematic', KIND_RACK => 'pdf.drawings.rack', KIND_FLOOR_PLAN => 'pdf.drawings.floor-plan' }`. Phase 17 only `pdf.drawings.schematic` exists; for the other two, throw `RuntimeException("Phase 18/19 will provide this Blade view")` matching the build-order doctrine.
    - Filename convention (per ARCHITECTURE.md §6.1): `drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}`. Generate ULID via `Illuminate\Support\Str::ulid()->toBase32()`.
    - PDF: `$path = $this->artifacts->writePath(TYPE_DRAWING, $filename); $this->pdfRenderService->fromBlade($bladeView, ['drawing' => $drawing], $path); return $path;`
    - SVG: write `$drawing->generated_svg` directly to `$this->artifacts->writePath(TYPE_DRAWING, $svgFilename)` via `file_put_contents`.
    - **PNG (Warning 8 — was: inline Browsershot; now: delegate):**
      ```php
      $pngPath = $this->artifacts->writePath(TYPE_DRAWING, $pngFilename);
      $this->pdfRenderService->fromBladeAsPng(
          $bladeView,
          ['drawing' => $drawing],
          $pngPath,
          ['widthPx' => $widthPx, 'heightPx' => intval($widthPx * 0.707)]
      );
      return $pngPath;
      ```
      No `use Spatie\Browsershot\Browsershot;` import needed in this file. Phase 20's CRIT-03 chrome-flag additions go into PdfRenderService and renderPng picks them up automatically.
    - `ensurePngForHandover()`: filename `drawings/handover-png/drawing-{id}-v{version}.png`. Uses `readPath()` first; if file exists, return its path. Otherwise call `$this->pdfRenderService->fromBladeAsPng($bladeView, ['drawing' => $drawing], $absoluteHandoverPath, ['widthPx' => 1280])` (slightly smaller than full to keep DOCX size manageable).

    Add a defensive guard: `renderPdf/renderSvg/renderPng` throw `RuntimeException` when `$drawing->status !== STATUS_READY` (cannot render an in-progress drawing). `ensurePngForHandover` returns `null` for not-ready drawings (handover Word doc gracefully skips them).

    **Update `app/Jobs/BuildSchematicJob.php` — insert thumbnail render block (Warning 6 disjoint region):**

    Plan 02 left the `handle()` body with `$generator->generate($drawing)` followed by the completion email block. INSERT a thumbnail render block AFTER `$generator->generate($drawing)` and BEFORE the `$drawing->refresh()` / completion email guard:

    ```php
    // ── Plan 17-03 thumbnail render (Warning 6 disjoint region) ─────────────
    // After the generator writes generated_svg + sets STATUS_READY, render a
    // PNG thumbnail via the centralised PdfRenderService::fromBladeAsPng path
    // (Warning 8 — no inline Browsershot). Failure is non-fatal — the SVG is
    // the primary artifact; a missing thumbnail just means the index card
    // won't have a preview image until the next regeneration.
    if ($drawing->status === ProjectDrawing::STATUS_READY) {
        try {
            $renderer  = app(\App\Services\Drawings\DrawingExportRendererService::class);
            $thumbPath = $renderer->renderPng($drawing, 400);   // 400px wide thumbnail
            $relative  = 'drawings/' . basename($thumbPath);
            $drawing->update(['thumbnail_png_path' => $relative]);
        } catch (\Throwable $thumbErr) {
            Log::warning('BuildSchematicJob: thumbnail render failed (non-fatal)', [
                'drawing_id' => $drawing->id,
                'error'      => $thumbErr->getMessage(),
            ]);
            // Do NOT mark drawing as failed — the SVG is the primary artifact.
        }
    }
    // ── End Plan 17-03 thumbnail block ──────────────────────────────────────
    ```

    Place this block ABOVE the `$drawing->refresh()` call and ABOVE the `if ($drawing->status === STATUS_READY && $drawing->completion_email_sent_at === null)` guard. The Plan 02 `$generator->generate($drawing)` call has already set status=READY synchronously, so the thumbnail attempt happens BEFORE the email send. Failures stay non-fatal so a Browsershot-flake doesn't block the email.

    **Update `app/Console/Commands/PdfSmokeTestCommand.php` — add `--drawings` flag:**

    Read the existing command. Append `{--drawings : Render a schematic fixture instead of the RAMS smoke baseline}` to `$signature`. In `handle()`, branch on `$this->option('drawings')`:

    ```php
    if ($this->option('drawings')) {
        return $this->renderDrawingSmoke();
    }
    // ... existing RAMS smoke logic
    ```

    `renderDrawingSmoke()` body:
    1. Find any project with at least one ready drawing of kind=schematic, OR fall back to creating an in-memory ProjectDrawing fixture with a hard-coded `generated_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="200"><text x="20" y="100">Smoke test schematic</text></svg>'`.
    2. Resolve `DrawingExportRendererService` from container.
    3. Output path: `$this->option('out') ?? storage_path('app/pdf-smoke-drawing.pdf')`.
    4. Call `$renderer->renderPdf($drawing)` (or for the in-memory case, call `PdfRenderService::fromBlade('pdf.drawings.schematic', ['drawing' => $drawing], $outPath)` directly).
    5. `$this->info('Drawing PDF rendered: ' . $outPath . ' (' . filesize($outPath) . ' bytes)');`.
    6. Return 0 on success.

    Mirror the existing pdf:smoke-test command structure exactly (reading file + reporting bytes — MIN-09 anti-pattern: don't assert HTML internals, just non-zero bytes).

    **Update `app/Http/Controllers/ProjectDrawingController.php` — add `download` action:**

    ```php
    public function download(Project $project, ProjectDrawing $drawing, string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('view', $drawing);
        if ($drawing->project_id !== $project->id) {
            abort(404);
        }
        if (!in_array($format, ['pdf', 'svg', 'png'], true)) {
            abort(404);
        }

        $renderer = app(\App\Services\Drawings\DrawingExportRendererService::class);
        $path = match ($format) {
            'pdf' => $renderer->renderPdf($drawing),
            'svg' => $renderer->renderSvg($drawing),
            'png' => $renderer->renderPng($drawing),
        };

        $filename = sprintf('%s-%s-%s.%s', $drawing->kind, $project->ref ?? $project->id, $drawing->revisionLabel(), $format);
        return response()->download($path, $filename);
    }
    ```

    **Update `routes/web.php`** — add:
    ```php
    Route::get('projects/{project}/drawings/{drawing}/download/{format}',
        [\App\Http\Controllers\ProjectDrawingController::class, 'download'])
        ->name('projects.drawings.download');
    ```
  </action>
  <acceptance_criteria>
    - `app/Services/Drawings/DrawingExportRendererService.php` exists; `grep -n "renderPdf\|renderSvg\|renderPng\|ensurePngForHandover\|TYPE_DRAWING\|PdfRenderService\|fromBladeAsPng" app/Services/Drawings/DrawingExportRendererService.php` shows all seven tokens.
    - **Warning 8 verification — no inline Browsershot in DrawingExportRendererService:** `grep -n "use Spatie\\\\Browsershot\\\\Browsershot\|new Browsershot\|Browsershot::html" app/Services/Drawings/DrawingExportRendererService.php` returns NOTHING. The renderer must delegate to `PdfRenderService::fromBladeAsPng` for PNG output.
    - `grep -n "thumbnail_png_path\|renderPng" app/Jobs/BuildSchematicJob.php` shows thumbnail wiring added.
    - **Warning 6 verification — Plan 02 mail dispatch preserved:** `grep -n "DrawingReadyMail\|completion_email_sent_at\|resolveProjectRecipient" app/Jobs/BuildSchematicJob.php` still returns hits (Plan 03's edit did NOT remove them).
    - `php artisan list 2>&1 | grep "pdf:smoke-test"` shows the command. Running `php artisan pdf:smoke-test --drawings --out=storage/app/test-drawing.pdf` (with the placeholder fallback fixture) reports a positive byte count without throwing.
    - `php artisan route:list --name=drawings.download` shows the route.
    - `app/Http/Controllers/ProjectDrawingController.php::download` is present and authorises via `$this->authorize('view', $drawing)`.
    - `php -l app/Services/Drawings/DrawingExportRendererService.php app/Jobs/BuildSchematicJob.php app/Http/Controllers/ProjectDrawingController.php app/Console/Commands/PdfSmokeTestCommand.php` reports no syntax errors.
  </acceptance_criteria>
  <verify>
    <automated>php artisan route:list --name=drawings 2>&1 | grep -E "drawings.download" | wc -l | grep -q "^[1-9]" && echo PASS || echo FAIL</automated>
  </verify>
  <done>Renderer service wired (PNG via PdfRenderService::fromBladeAsPng — Warning 8); smoke-test extended; per-format download endpoint live and policy-gated; thumbnail block inserted into BuildSchematicJob without disturbing Plan 02's mail dispatch (Warning 6).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Drawings index + show views + status controls + project page link (with createSchematic → generateInitial — Warning 9)</name>
  <read_first>
    - resources/views/projects/show.blade.php (existing tabs/sections — match style)
    - resources/views/document-edit-drawer.blade.php (DocumentEdits chat drawer — for Phase 19 hookup; in Phase 17 just leave a TODO comment so Plan 19 can plug in)
    - app/Models/ProjectDrawing.php (status methods — statusBadgeClass, statusLabel, kindLabel, hasUserEdits)
    - app/Services/Drawings/DrawingService.php (Plan 01 — has FOUR methods: createForProject / generateInitial / regenerate / archivePrior. Use generateInitial after createForProject — Warning 9)
    - .planning/research/PITFALLS.md CRIT-02 (lock-on-edit + UI confirm prompt)
    - .planning/research/FEATURES.md Phase 17 — Auto-generate then editable workflow
    - app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php (Plan 01 — set_status operation)
  </read_first>
  <action>
    Create three Blade views + one partial; add a "Drawings" section to projects/show.blade.php; wire status update endpoint.

    **`resources/views/projects/drawings/_status-pill.blade.php`:**

    Reusable pill that renders `$drawing->statusLabel()` styled by `$drawing->statusBadgeClass()`:
    ```blade
    <span class="px-2 py-0.5 rounded-full text-xs {{ $drawing->statusBadgeClass() }}">{{ $drawing->statusLabel() }}</span>
    ```
    Match Tailwind utility classes used elsewhere (look at site-survey/show.blade.php for badge style precedent).

    **`resources/views/projects/drawings/_regenerate-confirm-modal.blade.php`:**

    Alpine.js modal (matches existing `document-edit-drawer.blade.php` Alpine pattern). Triggered by `x-data="{ open: false }"` and `x-show="open"`. The modal:

    ```blade
    @php($drawing = $drawing ?? null)
    <div x-data="{ open: false, drawingId: null, hasUserEdits: false }"
         @open-regenerate-confirm.window="open = true; drawingId = $event.detail.id; hasUserEdits = $event.detail.hasUserEdits">
        <template x-if="open">
            <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 max-w-md">
                    <h3 class="font-semibold text-lg">Regenerate drawing</h3>
                    <template x-if="hasUserEdits">
                        <p class="mt-2 text-sm text-red-700">
                            This drawing has manual edits saved. Regenerating will archive the current revision and create a new one from canonical project data — your edits will be preserved on the archived revision but not carried forward.
                        </p>
                    </template>
                    <template x-if="!hasUserEdits">
                        <p class="mt-2 text-sm text-gray-700">
                            This will create a new revision from the latest canonical project data. The current revision is archived.
                        </p>
                    </template>
                    <div class="mt-4 flex gap-2 justify-end">
                        <button type="button" @click="open = false" class="px-3 py-1 rounded border">Cancel</button>
                        <form :action="`/projects/{{ $project->id }}/drawings/${drawingId}/regenerate`" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 rounded bg-blue-600 text-white">Regenerate</button>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
    ```

    The trigger fires from the index/show buttons via `$dispatch('open-regenerate-confirm', { id: {{ $drawing->id }}, hasUserEdits: {{ $drawing->hasUserEdits() ? 'true' : 'false' }} })`.

    **`resources/views/projects/drawings/index.blade.php`:**

    ```blade
    @extends('layouts.app')

    @section('content')
    <div class="max-w-7xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-semibold">Drawings — {{ $project->name }}</h1>
                <p class="text-sm text-gray-500">Project ref: {{ $project->ref ?? '—' }}</p>
            </div>
            <form method="POST" action="{{ route('projects.drawings.create-schematic', $project) }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Generate Schematic</button>
            </form>
        </div>

        @if (session('status'))
            <div class="mb-4 bg-green-50 border border-green-200 text-green-800 p-3 rounded">{{ session('status') }}</div>
        @endif

        <h2 class="text-lg font-semibold mt-4 mb-2">Schematics ({{ \App\Models\ProjectDrawing::KIND_SCHEMATIC }})</h2>
        @forelse ($drawings->where('kind', \App\Models\ProjectDrawing::KIND_SCHEMATIC) as $drawing)
            <div class="bg-white border rounded-lg p-4 mb-3 flex items-center justify-between">
                <div>
                    <div class="font-medium">{{ $drawing->kindLabel() }} — {{ $drawing->room?->name ?? 'Whole project' }}</div>
                    <div class="text-xs text-gray-500">Revision {{ $drawing->revisionLabel() }} · Updated {{ $drawing->updated_at?->diffForHumans() }}</div>
                </div>
                <div class="flex items-center gap-3">
                    @include('projects.drawings._status-pill', ['drawing' => $drawing])

                    @if ($drawing->isReady())
                        <a href="{{ route('projects.drawings.download', [$project, $drawing, 'pdf']) }}" class="text-sm underline">PDF</a>
                        <a href="{{ route('projects.drawings.download', [$project, $drawing, 'svg']) }}" class="text-sm underline">SVG</a>
                        <a href="{{ route('projects.drawings.download', [$project, $drawing, 'png']) }}" class="text-sm underline">PNG</a>
                    @endif

                    <a href="{{ route('projects.drawings.show', [$project, $drawing]) }}" class="text-sm underline">Open</a>

                    <button type="button"
                            x-data
                            @click="$dispatch('open-regenerate-confirm', { id: {{ $drawing->id }}, hasUserEdits: {{ $drawing->hasUserEdits() ? 'true' : 'false' }} })"
                            class="text-sm px-2 py-1 border rounded">Regenerate</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No schematics yet — click "Generate Schematic" above.</p>
        @endforelse

        {{-- Phase 18 will list racks here, Phase 19 will list floor plans here. --}}

        @include('projects.drawings._regenerate-confirm-modal', ['project' => $project])
    </div>
    @endsection
    ```

    **`resources/views/projects/drawings/show.blade.php`:**

    Per-drawing preview page. Renders embedded SVG + status controls (status select + per-format downloads + regenerate button).

    ```blade
    @extends('layouts.app')

    @section('content')
    <div class="max-w-7xl mx-auto p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <a href="{{ route('projects.drawings.index', $project) }}" class="text-sm text-blue-600">← All drawings</a>
                <h1 class="text-2xl font-semibold">{{ $drawing->kindLabel() }} — {{ $drawing->room?->name ?? 'Whole project' }}</h1>
                <p class="text-sm text-gray-500">Revision {{ $drawing->revisionLabel() }} · {{ $drawing->kind }}</p>
            </div>
            <div class="flex items-center gap-3">
                @include('projects.drawings._status-pill', ['drawing' => $drawing])
                @if ($drawing->isReady())
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'pdf']) }}" class="px-3 py-1 border rounded text-sm">Download PDF</a>
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'svg']) }}" class="px-3 py-1 border rounded text-sm">Download SVG</a>
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'png']) }}" class="px-3 py-1 border rounded text-sm">Download PNG</a>
                @endif
            </div>
        </div>

        @if ($drawing->isReady() && !empty($drawing->generated_svg))
            <div class="bg-white border rounded-lg p-6 overflow-auto">
                {!! $drawing->generated_svg !!}
            </div>
        @elseif ($drawing->status === \App\Models\ProjectDrawing::STATUS_GENERATING)
            <p class="bg-blue-50 border border-blue-200 p-3 rounded">Generation in progress — refresh in a few seconds.</p>
        @elseif ($drawing->isFailed())
            <p class="bg-red-50 border border-red-200 p-3 rounded text-red-800">Generation failed: {{ $drawing->error_message ?? 'unknown error' }}</p>
        @endif

        {{-- Status update form (DRAW-25 — set_status operation) --}}
        <form method="POST" action="{{ route('projects.drawings.update-status', [$project, $drawing]) }}" class="mt-6 flex items-center gap-2">
            @csrf
            @method('PUT')
            <label for="status" class="text-sm font-medium">Workflow status:</label>
            <select name="status" id="status" class="border rounded px-2 py-1 text-sm">
                <option value="draft"      @selected($drawing->status === 'draft')>Draft</option>
                <option value="for_review" @selected($drawing->status === 'for_review')>For Review</option>
                <option value="approved"   @selected($drawing->status === 'approved')>Approved</option>
            </select>
            <button type="submit" class="px-2 py-1 border rounded text-sm">Update</button>
            <p class="text-xs text-gray-500">"Superseded" is set automatically when this drawing is regenerated.</p>
        </form>

        {{-- DRAW-30 chat scaffolding hook — Phase 19 plugs the editor + chat in. --}}
        {{-- TODO Phase 19: include('document-edit-drawer', ['documentType' => 'drawing', 'documentId' => $drawing->id]) --}}

        @include('projects.drawings._regenerate-confirm-modal', ['project' => $project])
    </div>
    @endsection
    ```

    **`app/Http/Controllers/ProjectDrawingController.php` — fill `index`, `show`, add `createSchematic` and `updateStatus` (Warning 9 fix in createSchematic):**

    - `index` already wired in Plan 01; ensure it passes `$drawings` correctly grouped/sorted.
    - `show` becomes a Blade view render: `return view('projects.drawings.show', ['project' => $project, 'drawing' => $drawing]);` (replacing Plan 01's JSON stub).
    - **`createSchematic(Request, Project $project)` — Warning 9 fix (option (a)):**
      ```php
      // Authorize: any auth'd user can create a drawing for a project they can view.
      // Project-level auth check happens at route binding via existing project policy.
      if (! auth()->check()) abort(403);

      $service = app(\App\Services\Drawings\DrawingService::class);

      // Step 1: create the row (status=DRAFT, no job dispatched).
      $drawing = $service->createForProject(
          $project,
          \App\Models\ProjectDrawing::KIND_SCHEMATIC,
          null,                                  // no specific room — Phase 17 v1 generates per-project schematic
          $request->user()->id,
      );

      // Step 2: dispatch the build job WITHOUT archive-prior semantics
      // (this is the FIRST version — there's nothing to archive).
      // Calling regenerate() here would archive the just-created row and replicate
      // a fresh one — wastes a row, breaks revisioning, and produces a misleading
      // "R1" instead of "R0" for the first version. (Warning 9.)
      $service->generateInitial($drawing, $request->user()->id);

      return redirect()
          ->route('projects.drawings.index', $project)
          ->with('status', 'Schematic generation queued.');
      ```
    - `updateStatus(Request, Project $project, ProjectDrawing $drawing)` — uses the DocumentEditAdapter:
      ```php
      $this->authorize('update', $drawing);
      $registry = app(\App\Services\DocumentEdits\DocumentEditAdapterRegistry::class);
      $adapter = $registry->for('drawing');
      $payload = $adapter->loadPayload($drawing->id);
      $result = $adapter->applyOperation($payload, ['op' => 'set_status', 'value' => $request->input('status')]);
      if ($result['ok'] ?? false) {
          $adapter->commitChanges($drawing->id, $result['payload']);
          return redirect()->route('projects.drawings.show', [$project, $drawing])->with('status', 'Status updated.');
      }
      return back()->withErrors(['status' => $result['error'] ?? 'Status update rejected']);
      ```

    **Update `routes/web.php`** — add:
    ```php
    Route::post('projects/{project}/drawings/create-schematic',
        [\App\Http\Controllers\ProjectDrawingController::class, 'createSchematic'])
        ->name('projects.drawings.create-schematic');
    Route::put('projects/{project}/drawings/{drawing}/status',
        [\App\Http\Controllers\ProjectDrawingController::class, 'updateStatus'])
        ->name('projects.drawings.update-status');
    ```

    **Update `resources/views/projects/show.blade.php`** — add a "Drawings" link/section:

    Find the existing tab/link list (where worksheets, RAMS, O&M Manual etc. are listed). Add a "Drawings" link adjacent to those:
    ```blade
    <a href="{{ route('projects.drawings.index', $project) }}" class="...same classes as existing tabs...">
        Drawings
        @php($drawingCount = $project->drawings()->whereNull('superseded_by_id')->count())
        @if ($drawingCount > 0)
            <span class="ml-1 text-xs bg-gray-200 px-1.5 rounded">{{ $drawingCount }}</span>
        @endif
    </a>
    ```
    Do NOT replace existing tabs — additive only.
  </action>
  <acceptance_criteria>
    - `resources/views/projects/drawings/index.blade.php`, `show.blade.php`, `_status-pill.blade.php`, `_regenerate-confirm-modal.blade.php` all exist.
    - `php artisan route:list --name=drawings` shows ALL of: `drawings.index`, `drawings.show`, `drawings.regenerate`, `drawings.download`, `drawings.create-schematic`, `drawings.update-status` (six routes total).
    - `grep -n "projects.drawings.index\|Drawings" resources/views/projects/show.blade.php` confirms link added (do not remove existing links).
    - `grep -n "set_status\|DocumentEditAdapterRegistry" app/Http/Controllers/ProjectDrawingController.php` shows updateStatus goes through the adapter (DRAW-30 scaffolding pathway).
    - **Warning 9 verification — createSchematic uses generateInitial, not regenerate:** `grep -n "generateInitial\|createForProject" app/Http/Controllers/ProjectDrawingController.php` shows BOTH methods called from createSchematic. `grep -n "regenerate" app/Http/Controllers/ProjectDrawingController.php` shows regenerate ONLY in the regenerate action (NOT in createSchematic).
    - `grep -n "open-regenerate-confirm\|hasUserEdits" resources/views/projects/drawings/_regenerate-confirm-modal.blade.php` shows modal handles edit-warning state (DRAW-05 scaffolding).
    - `php -l resources/views/projects/drawings/index.blade.php resources/views/projects/drawings/show.blade.php resources/views/projects/drawings/_status-pill.blade.php resources/views/projects/drawings/_regenerate-confirm-modal.blade.php 2>&1 | head -10` — Blade files don't have a PHP linter for the templating syntax, but the embedded `@php` blocks must be valid (a manual visual review is sufficient; no syntax errors detected by `php artisan view:cache` is a stronger check).
    - `php artisan view:cache 2>&1 | grep -i error` returns nothing.
    - **End-to-end UX check (Warning 9):** Manual run — click Generate Schematic for a project with no prior drawing → resulting row is `version=1` (R0), `status=GENERATING`, NOT `version=2` (R1). Index page shows ONE row, not two with one superseded.
  </acceptance_criteria>
  <verify>
    <automated>php artisan route:list --name=drawings 2>&1 | grep -cE "drawings\.(index|show|regenerate|download|create-schematic|update-status)" | grep -q "^6$" && echo PASS || echo FAIL</automated>
  </verify>
  <done>Drawings list + preview pages live; lock-on-edit confirm modal wired; status updates flow through DocumentEditAdapter; project show page links to drawings; createSchematic uses generateInitial (Warning 9 — no archive-prior on first version).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: O&M Manual handover wiring (DRAW-26 — Blocker 3 fix)</name>
  <read_first>
    - app/Services/OmManualDocxService.php (lines 80-160 — the section-per-major-block pattern. EVERY major section opens a fresh `$s = $phpWord->addSection($this->sectionProps())`. Lines 88, 125, 131, 135, 139, 143, 147, 151. The Drawings section MUST follow this pattern — Blocker 3.)
    - app/Models/OmManual.php (verified during planning revision: project() relation EXISTS — `$manual->project` is safe. Blocker 3.)
    - .planning/research/ARCHITECTURE.md §6.3 OmManualDocxService Integration
    - .planning/research/PITFALLS.md MOD-10 (versioned filenames, regen_recommended) — phase 20 hardens; Phase 17 does the basic version
    - app/Services/Drawings/DrawingExportRendererService.php (Task 1 — uses ensurePngForHandover)
  </read_first>
  <action>
    Patch `OmManualDocxService` to append a "Drawings" section embedding ready drawings as PNG (DRAW-26). Single file modified — same scope discipline as the Phase 17 build-order doctrine.

    **Blocker 3 fix — section creation pattern:**

    The original Plan 03 pseudocode used `$section->addPageBreak(); $section->addTitle('Drawings', 1)` against an undefined `$section`. The actual codebase pattern in `OmManualDocxService::build()` is one fresh `$s = $phpWord->addSection($this->sectionProps())` PER major block (lines 88, 125, 131, 135, 139, 143, 147, 151). The Drawings section MUST mirror that pattern — open a NEW section via `$phpWord->addSection($this->sectionProps())` and call `addImage` on THAT section's variable.

    **Locate the existing `build()` method in `app/Services/OmManualDocxService.php`.** The LAST major section in the current file is "Document Control" at line ~151 (`$s = $phpWord->addSection($this->sectionProps()); $this->buildDocumentControlSection($s, $sectionNum, $manual);`). The new Drawings section lands AFTER Document Control and BEFORE the `// ── Save ──` block (line ~154).

    **Insert this block AFTER `$this->buildDocumentControlSection(...)` and BEFORE the `$filename = ...` save block:**

    ```php
    // ── DRAW-26 — Phase 17 v1.3: Drawings section (PNG embed for Word compat) ──
    // Uses DrawingExportRendererService::ensurePngForHandover() which is idempotent
    // per-drawing-version. PhpWord's addImage handles raster cleanly; SVG support
    // in PhpWord 1.4+ exists but is inconsistent across Word/Word Online/LibreOffice
    // (per ARCHITECTURE.md §6.3). PNG is the safe path; switch to SVG embed if
    // a future Word release firms up SVG handling.
    //
    // Blocker 3 fix: open a FRESH section (do NOT reuse $s from the Document Control
    // block). Variable name $drawingsSection is intentional — keeps the diff readable.
    // Project access: $manual->project — relation exists on App\Models\OmManual
    // (verified during plan revision, app/Models/OmManual.php line 59).

    $drawings = $manual->project?->drawings()
        ->where('status', \App\Models\ProjectDrawing::STATUS_READY)
        ->whereNull('superseded_by_id')
        ->orderBy('kind')
        ->orderBy('site_survey_room_id')
        ->get() ?? collect();

    if ($drawings->isNotEmpty()) {
        // Fresh PhpWord section — mirrors the pattern at lines 88/125/131/.../151.
        $drawingsSection = $phpWord->addSection($this->sectionProps());

        $sectionNum++;
        $this->addHeading1($drawingsSection, $sectionNum . '.  Drawings', 1);

        $renderer = app(\App\Services\Drawings\DrawingExportRendererService::class);

        foreach ($drawings as $drawing) {
            $pngPath = null;
            try {
                $pngPath = $renderer->ensurePngForHandover($drawing);
            } catch (\Throwable $e) {
                Log::warning(
                    'OmManualDocxService: drawing PNG render failed (skipping)',
                    ['drawing_id' => $drawing->id, 'om_manual_id' => $manual->id, 'error' => $e->getMessage()]
                );
                continue;
            }
            if (!$pngPath || !is_file($pngPath)) {
                continue;
            }

            $drawingsSection->addText(
                $drawing->kindLabel() . ' — ' . ($drawing->room?->name ?? 'Whole project') . ' (' . $drawing->revisionLabel() . ')',
                ['bold' => true, 'size' => 12]
            );
            $drawingsSection->addImage($pngPath, [
                'width'         => 500,                  // points; fits A4 portrait with margins
                'height'        => null,                  // preserve aspect
                'wrappingStyle' => 'square',
                'alignment'     => Jc::CENTER,            // already imported at top of file (line 12)
            ]);
            $drawingsSection->addPageBreak();             // one drawing per page (DRAW-26)
        }
    }
    ```

    Important constraints:
    - **The variable is `$drawingsSection`, NOT `$section`** — `$section` was never in scope in the original draft, which would have raised `Undefined variable $section` at runtime. The `$drawingsSection` name follows the same `$s` / `$cover` naming style used elsewhere in build() (descriptive variable per major section).
    - **`$manual->project`** — confirmed safe: `app/Models/OmManual.php` line 59 defines `public function project(): BelongsTo`.
    - **`Jc` is already imported** at the top of `OmManualDocxService.php` (`use PhpOffice\PhpWord\SimpleType\Jc;` line 12) — use the bare class reference, not the FQN.
    - **`addHeading1` is an existing private helper** on the service — confirm by reading the file (used throughout build() for `1. Introduction`, `2. ...`, etc.). Match the style.
    - **`Log` import**: already at `use Illuminate\Support\Facades\Log;` (line 15) — use bare `Log::warning(...)`.
    - Failures rendering one drawing's PNG MUST NOT fail the whole O&M generation (defensive try/catch + log + continue) — matches the existing CLAUDE.md "fail loud per-step, never silently break the pipeline" pattern.
    - The drawings are scoped to `whereNull('superseded_by_id')` so superseded versions never appear in the handover.
    - The PNG path comes from `DrawingExportRendererService::ensurePngForHandover()` — never construct the path manually. Cached per-version under `drawings/handover-png/drawing-{id}-v{version}.png` (Task 1 implements caching).
    - Order: kind (schematic → rack → floor_plan), then by room — gives a consistent reading order matching AVIXA convention (signal flow before physical layout).

    **Smoke check:** Run an existing O&M generation flow against a project that has zero drawings — the new section MUST be a no-op (no errors, no empty "Drawings" header, no fresh empty section appended).
  </action>
  <acceptance_criteria>
    - `grep -n "Drawings\|ensurePngForHandover\|addImage\|STATUS_READY\|drawingsSection\|addSection" app/Services/OmManualDocxService.php` shows the new section wired (Drawings header, addImage call, ensurePngForHandover invocation, status filter, dedicated section variable, addSection call).
    - **Blocker 3 verification — fresh section opened, no `$section` reference:** `grep -n "\\\$section->" app/Services/OmManualDocxService.php` returns NOTHING (the patch must use `$drawingsSection`, not `$section`).
    - **Blocker 3 verification — `$drawingsSection` is an addSection result:** `grep -n "drawingsSection = .phpWord->addSection" app/Services/OmManualDocxService.php` returns at least one match.
    - **Blocker 3 verification — `$manual->project` access valid:** `grep -n "function project" app/Models/OmManual.php` shows the relation method exists (sanity check; planner already verified).
    - `grep -n "addPageBreak" app/Services/OmManualDocxService.php` shows page-break pattern (one per drawing per DRAW-26).
    - `php -l app/Services/OmManualDocxService.php` reports no syntax errors.
    - For a project with zero drawings: O&M generation completes without errors and produces a DOCX with NO Drawings header (no-op when collection is empty — guarded by `if ($drawings->isNotEmpty())`).
    - For a project with one ready schematic: O&M generation calls `DrawingExportRendererService::ensurePngForHandover()` for that drawing and embeds the PNG via `$drawingsSection->addImage()` — observable by adding a `Log::info` at the call site or by reading `storage/logs/laravel.log` after a manual run.
    - `whereNull('superseded_by_id')` filter excludes archived versions — verified by reading the modified code.
    - The new section is the LAST major section before `$phpWord->save()` (or the existing final write call).
    - Existing O&M generation tests (if any) still pass: `php artisan test --filter=OmManual` (or equivalent feature tests for O&M) stays green.
  </acceptance_criteria>
  <verify>
    <automated>php -l app/Services/OmManualDocxService.php 2>&1 | grep -q "No syntax errors detected" && grep -q "ensurePngForHandover" app/Services/OmManualDocxService.php && grep -q "drawingsSection" app/Services/OmManualDocxService.php && echo PASS || echo FAIL</automated>
  </verify>
  <done>O&M Manual handover embeds ready drawings as one-per-page PNGs via DrawingExportRendererService; failures per-drawing are non-fatal; superseded versions excluded; section pattern follows the codebase convention (fresh `$drawingsSection = $phpWord->addSection(...)`, NOT a reused `$section` variable — Blocker 3).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Authenticated user → ProjectDrawingController download/show/updateStatus | Web-form/AJAX requests must pass policy gates |
| ProjectDrawing.generated_svg → Blade view → Browsershot/PDF | SVG embedded with `{!! ... !!}` — trust source: deterministic D2 output, no user-controlled fields |
| OmManualDocxService → DrawingExportRendererService::ensurePngForHandover | Server-internal call; PNG path resolved via DocumentArtifactStorage |
| pdf:smoke-test --drawings → DrawingExportRendererService | Local CLI; runs as same user as queue worker; no external attack surface |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17.03-01 | E (Elevation of privilege) | ProjectDrawingController::download | mitigate | `$this->authorize('view', $drawing)` + project_id match check (`abort(404)` when drawing's project_id ≠ {project} URL param). Format whitelist `['pdf','svg','png']` enforced (no `php`/`exe` extensions). |
| T-17.03-02 | T (Tampering) | updateStatus → DrawingEditAdapter::set_status | mitigate | DocumentEditAdapter restricts set_status to `[draft, for_review, approved]`. Cannot set superseded/ready/failed/generating from UI. CSRF protected via `@csrf` token on form. Uses adapter validator path → consistent with all other DocumentEdits flows. |
| T-17.03-03 | I (Information disclosure) | Cross-project access via mismatched route IDs | mitigate | Implicit route-model binding + `if ($drawing->project_id !== $project->id) abort(404)` guard in download action. |
| T-17.03-04 | T (Tampering) | DrawingExportRendererService::renderPdf Blade view selection | mitigate | Blade view chosen via `match ($drawing->kind) { ... }` against KIND_* constants. Unknown kinds throw `RuntimeException` (no path traversal via crafted kind value — kind enum is varchar(20) and validated by model). |
| T-17.03-05 | I (Information disclosure) | Browsershot screenshot in renderPng | mitigate | **Warning 8 — DrawingExportRendererService::renderPng delegates to PdfRenderService::fromBladeAsPng. SAME `noSandbox()` + `--disable-dev-shm-usage` config inherits from the central method. Render runs server-side from a Blade-rendered HTML string; no remote URL fetches; no user-supplied URLs. CHROME_PATH from env (no user input). Phase 20's CRIT-03 chrome-flag hardening applies automatically.** |
| T-17.03-06 | D (Denial of service) | renderPng huge SVG | mitigate | PNG capture inherits PdfRenderService's per-process memory pressure. Phase 17 thumbnails are 400px-wide — small. Phase 20 (CRIT-03) lands the dedicated drawings queue + memory probe. Phase 17 acceptable risk: thumbnail render is bounded. |
| T-17.03-07 | T (Tampering) | OmManualDocxService::addImage path | mitigate | PNG path resolved via `ensurePngForHandover` → DocumentArtifactStorage::readPath. No user-controlled filename. is_file() guard before addImage call. |
| T-17.03-08 | I (Information disclosure) | Drawings index counts archived drawings | mitigate | `whereNull('superseded_by_id')` filter applied in both index controller and OmManualDocxService — superseded revisions never surface to UI or O&M handover. |
| T-17.03-09 | T (Tampering) | BuildSchematicJob.php co-edited (Warning 6) | mitigate | `depends_on: ["17-01", "17-02"]` forces Plan 02 to commit BEFORE Plan 03 edits. Disjoint regions: Plan 02 owns the SVG-writing block inside try{}; Plan 03 inserts the thumbnail block AFTER `$generator->generate()` succeeds and BEFORE the completion email. Acceptance criteria explicitly verify Plan 02's mail dispatch is preserved post-Plan-03 edit. |
| T-17.03-10 | T (Tampering) | createSchematic UX bug (Warning 9) | mitigate | createSchematic calls `DrawingService::generateInitial` (NOT `regenerate`) after `createForProject`. Prevents the "create then immediately archive" anti-pattern that would have produced version=2 (R1) for the first row, with a phantom archived version=1 sibling. Acceptance criteria verify version=1 after createSchematic for a project with no prior drawings. |
</threat_model>

<verification>
1. **Routes**: `php artisan route:list --name=drawings` lists all six routes (index, show, regenerate, download, create-schematic, update-status).
2. **Smoke test**: `php artisan pdf:smoke-test --drawings --out=/tmp/sm.pdf` produces a non-empty PDF (filesize > 1024 bytes).
3. **DRAW-06**: Manual UI test — click Generate Schematic → drawing appears → click PDF/SVG/PNG buttons → all three file types download with correct content-type and non-empty bytes.
4. **DRAW-05**: Click Regenerate on a drawing → confirm modal appears; modal text differs depending on `hasUserEdits` value (Phase 17 only — UX scaffolding; functional editor lands in Phase 19).
5. **DRAW-25**: Status select on show page — flipping draft → for_review → approved persists via DocumentEditAdapter; "superseded" is NOT in the select (auto-set by regenerate).
6. **DRAW-26**: Generate an O&M Manual for a project with at least one ready schematic — DOCX contains a "Drawings" section with one PNG-embedded page per drawing. Project with zero drawings: no section appears.
7. **DRAW-27**: All three formats (PDF/SVG/PNG) downloadable per-drawing.
8. **No regression**: Existing PDF generation paths unaffected (`php artisan pdf:smoke-test` without `--drawings` still produces the RAMS smoke baseline).
9. **Project page link**: `/projects/{project}/show` page now displays a Drawings link with the active count.
10. **Blocker 3** — `grep -q drawingsSection app/Services/OmManualDocxService.php && ! grep -q '\$section->' app/Services/OmManualDocxService.php` — passes.
11. **Warning 6** — `app/Jobs/BuildSchematicJob.php` post-Plan-03 still has `DrawingReadyMail` and `completion_email_sent_at` references intact (Plan 03's thumbnail block didn't accidentally remove Plan 02's mail dispatch).
12. **Warning 8** — `! grep -q "use Spatie\\\\Browsershot" app/Services/Drawings/DrawingExportRendererService.php` returns true (no direct Browsershot import — delegation to PdfRenderService::fromBladeAsPng confirmed).
13. **Warning 9** — UX test: createSchematic on a fresh project produces version=1 (R0), not version=2. `php artisan tinker --execute="\$d = App\Models\ProjectDrawing::latest()->first(); echo \$d->version === 1 ? 'PASS' : 'FAIL';"` after a clean createSchematic call.
</verification>

<success_criteria>
- DRAW-05 (lock-on-edit prompt UX scaffolding) — observable: Regenerate button on a drawing with canvas_state non-null surfaces a confirm modal warning that prior edits will be archived. NOTE: full schematic editor functionality is Phase 19; Phase 17 only delivers the UX scaffolding.
- DRAW-06 (export schematic as PDF and SVG) — observable: per-drawing PDF and SVG download endpoints functional.
- DRAW-26 (drawings included in O&M Manual handover via PNG embed) — observable: O&M DOCX for a project with ready drawings contains a Drawings section, one PNG per drawing, one per page, opened via fresh `$drawingsSection = $phpWord->addSection(...)` (Blocker 3).
- DRAW-27 (download individual drawing as PDF, SVG, or PNG) — observable: three download buttons per drawing, all returning correct file types.
- pdf:smoke-test --drawings flag exists and produces a non-empty PDF (sets the stage for Phase 20's CRIT-04 chrome-version-drift extension).
- Phase 19 plug-in points clearly marked: TODO comments in show.blade.php for the document-edit-drawer chat hook; functional schematic editor via Konva loads here when Phase 19 ships.
- All UI flows policy-gated via ProjectDrawingPolicy; CSRF tokens present; no cross-project drawing access possible.
- **Warning 6** — co-edit of BuildSchematicJob.php with Plan 02 sequenced via `depends_on: ["17-01", "17-02"]`; disjoint regions; Plan 02's mail dispatch preserved.
- **Warning 8** — DrawingExportRendererService delegates PNG rendering to PdfRenderService::fromBladeAsPng (no Browsershot duplication; Phase 20's CRIT-03 hardening applies automatically).
- **Warning 9** — createSchematic uses `DrawingService::generateInitial` after `createForProject`; first drawing version is `1` (R0), not `2` with a phantom archived sibling.
</success_criteria>

<output>
After completion, create `.planning/phases/17-system-schematics-shared-foundations/17-03-SUMMARY.md` documenting:
- Routes added (count + names).
- Files created vs modified.
- O&M handover wiring confirmation (one screenshot or sample DOCX byte count from a fixture run; note the `$drawingsSection` variable rename — Blocker 3).
- Phase 19 plug-in points (chat drawer TODO, Konva editor TODO).
- Phase 20 hand-off notes (DXF, ZIP bundle, dedicated queue, MOD-10 versioned filenames hardening — already partly addressed via versioned filename convention here, fully hardened in Phase 20; CRIT-03 chrome-flag hardening will land in PdfRenderService and apply to renderPng automatically — Warning 8 paid off).
- Pointers to Phase 18 (rack elevations as a pure addition; uses TYPE_DRAWING + ProjectDrawing + BuildSchematicJob shape + DrawingReadyMail + DrawingEditAdapter pattern from Phase 17).
</output>
</output>

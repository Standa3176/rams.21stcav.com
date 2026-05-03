---
phase: 20-drawing-export-pipeline-o-m-integration
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - composer.lock
  - database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php
  - app/Services/Drawings/DrawingService.php
  - app/Services/Drawings/SheetNumberAllocator.php
  - app/Services/Drawings/BoundPdfBuilderService.php
  - app/Jobs/BuildBoundPdfJob.php
  - app/Mail/BoundPdfReadyMail.php
  - resources/views/emails/bound-pdf-ready.blade.php
  - resources/views/pdf/drawings/bound-cover.blade.php
  - resources/views/pdf/drawings/_title-block.blade.php
  - resources/views/projects/drawings/index.blade.php
  - app/Http/Controllers/ProjectDrawingController.php
  - routes/web.php
  - tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php
  - tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php
  - tests/Feature/Drawings/BoundPdfDownloadTest.php
  - tests/Feature/Drawings/ZipBundleDownloadTest.php
autonomous: true
requirements: [DRAW-21, DRAW-23, DRAW-28]

must_haves:
  truths:
    - "User can click 'Download Bound PDF' on a project's drawings index and receive a single multi-page PDF (cover sheet + drawing register table + per-drawing pages) (DRAW-21)"
    - "Every drawing's title block renders the auto-derived sheet number (AV-201, AV-202, ... for schematics; AV-301, AV-302, ... for racks) consistently across per-drawing PDFs and the bound PDF (DRAW-23)"
    - "User can click 'Download ZIP' on the drawings index and receive a streamed ZIP containing bound PDF + every per-drawing PDF/SVG/PNG + a drawing register CSV (DRAW-28)"
    - "If any per-drawing PDF render fails during bound-PDF assembly, the bound PDF still completes with a '[render failed]' placeholder row in the register; one drawing failure never aborts the whole bound PDF (MOD-10 hardening)"
    - "If any drawing in the project has updated_at > the bound PDF's generated_at, the index page surfaces an amber 'Regen needed — drawing changed' badge near the bound PDF download button (MOD-10)"
  artifacts:
    - path: "database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php"
      provides: "project_drawings.sheet_number varchar(20) nullable column"
      contains: "sheet_number"
    - path: "app/Services/Drawings/SheetNumberAllocator.php"
      provides: "Pure function — given (project, kind), returns next AV-XXX number"
      exports: ["allocate"]
    - path: "app/Services/Drawings/BoundPdfBuilderService.php"
      provides: "Concatenates cover PDF + per-drawing PDFs into a single bound PDF; isolates per-drawing failures"
      exports: ["build"]
    - path: "app/Jobs/BuildBoundPdfJob.php"
      provides: "Async bound-PDF assembly; tries=2; timeout=300; failed() admin alert; idempotent send-once notification"
      contains: "class BuildBoundPdfJob"
    - path: "app/Mail/BoundPdfReadyMail.php"
      provides: "Bound-PDF completion notification; mirrors DrawingReadyMail structure"
    - path: "resources/views/pdf/drawings/bound-cover.blade.php"
      provides: "Cover sheet + drawing register table Blade view"
    - path: "resources/views/projects/drawings/index.blade.php"
      provides: "Drawings index with bound-PDF + ZIP download buttons + sheet-number column + 'regen needed' badge"
  key_links:
    - from: "ProjectDrawingController::downloadBoundPdf"
      to: "BuildBoundPdfJob (dispatch)"
      via: "queue('drawings') dispatch"
      pattern: "BuildBoundPdfJob::dispatch"
    - from: "BuildBoundPdfJob::handle"
      to: "BoundPdfBuilderService::build"
      via: "service-class injection"
      pattern: "BoundPdfBuilderService"
    - from: "BoundPdfBuilderService::build"
      to: "DrawingExportRendererService::renderPdf"
      via: "per-drawing PDF render loop with try/catch"
      pattern: "renderPdf"
    - from: "ProjectDrawingController::downloadBundle"
      to: "ZipArchive::addFile"
      via: "response()->streamDownload"
      pattern: "ZipArchive"
    - from: "DrawingService::createForProject"
      to: "SheetNumberAllocator::allocate"
      via: "set-once on draft create"
      pattern: "SheetNumberAllocator"
---

<objective>
Ship the user-visible "bound PDF + sheet numbering + ZIP bundle" trio of v1.3's last phase. Every project gets a single downloadable handover PDF that combines a cover sheet, a drawing register, and every drawing in deterministic order. Every drawing gets an auto-derived AVIXA-style sheet number (AV-201..299 for schematics, AV-301..399 for racks) that appears in its title block. Every project gets a one-click ZIP download containing the bound PDF + per-drawing PDF/SVG/PNG + a CSV drawing register.

Per CONTEXT.md locked decisions:
- Render path REUSES Phase 17/18 infrastructure — `PdfRenderService::fromBlade` per-drawing then concatenate via a lightweight PHP PDF merge library (D-A hybrid)
- Sheet number is auto-derived ONCE on draft create; never re-derived; per-drawing manual override deferred to v1.3.x (D-B)
- ZIP built on-demand via PHP's standard `ZipArchive` + `response()->streamDownload`; no staleness risk (D-C)
- Storage uses existing `DocumentArtifactStorage::TYPE_DRAWING` constant — bound PDF and bundles land under `documents/drawings/`
- Bound PDF failure semantics: per-drawing render failures log + skip + show as `[render failed]` in register; whole bound PDF still completes (MOD-10 / per CONTEXT critical_constraints)
- "Regenerate recommended" badge fires when ANY drawing.updated_at > bound_pdf_generated_at (MOD-10)

Purpose: deliver DRAW-21, DRAW-23, DRAW-28 — the three Phase 20 requirement IDs.
Output: bound PDF artifacts under `documents/drawings/bound-{projectId}-v{version}-{ulid}.pdf`; ZIP bundles streamed at request time; sheet numbers persisted in `project_drawings.sheet_number`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/20-drawing-export-pipeline-o-m-integration/20-CONTEXT.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@CLAUDE.md

# Phase 17 + 18 foundations this plan extends:
@app/Services/Drawings/DrawingExportRendererService.php
@app/Services/Drawings/DrawingService.php
@app/Services/PdfRenderService.php
@app/Services/DocumentArtifactStorage.php
@app/Models/ProjectDrawing.php
@app/Jobs/BuildSchematicJob.php
@app/Mail/DrawingReadyMail.php
@app/Http/Controllers/ProjectDrawingController.php
@resources/views/projects/drawings/index.blade.php
@resources/views/pdf/drawings/_title-block.blade.php
@routes/web.php

<interfaces>
<!-- Key contracts the executor needs — extracted from Phase 17 + 18 codebase. Use these directly; no exploration. -->

From app/Models/ProjectDrawing.php:
```php
class ProjectDrawing extends Model {
    public const KIND_SCHEMATIC = 'schematic';
    public const KIND_RACK = 'rack';
    public const KIND_FLOOR_PLAN = 'floor_plan';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FOR_REVIEW = 'for_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id', 'site_survey_room_id', 'kind', 'rack_label',
        'version', 'superseded_by_id',
        'source_data', 'generated_svg', 'canvas_state', 'thumbnail_png_path',
        'status', 'error_message', 'filename',
        'completion_email_sent_at', 'failed_email_sent_at',
        'access_token', 'generated_by',
    ];
    // NOTE: this plan adds 'sheet_number' to $fillable as part of Task 1.

    public function project(): BelongsTo;
    public function isReady(): bool;
    public function isSchematic(): bool;
    public function isRack(): bool;
    public function kindLabel(): string;       // 'System Schematic' / 'Rack Elevation'
    public function revisionLabel(): string;   // 'R0' / 'R1' / ...
}
```

From app/Services/DocumentArtifactStorage.php:
```php
class DocumentArtifactStorage {
    public const TYPE_DRAWING = 'drawings';
    public function writePath(string $type, string $filename): string;  // returns absolute path
    public function readPath(string $type, string $filename): ?string;  // null = not found
    public function delete(string $type, string $filename): void;
}
```

From app/Services/Drawings/DrawingExportRendererService.php:
```php
class DrawingExportRendererService {
    public function renderPdf(ProjectDrawing $drawing): string;     // throws if !isReady
    public function renderSvg(ProjectDrawing $drawing): string;     // throws if !isReady or empty SVG
    public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string;
    public function ensurePngForHandover(ProjectDrawing $drawing): ?string;  // null if !isReady; idempotent cache
}
```

From app/Services/Drawings/DrawingService.php:
```php
class DrawingService {
    public function createForProject(Project $project, string $kind, ?int $roomId, int $userId): ProjectDrawing;
    public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing;
    public function regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing;
}
```

From app/Jobs/BuildSchematicJob.php (template for BuildBoundPdfJob):
```php
class BuildSchematicJob implements ShouldQueue {
    public int $tries = 2;
    public int $timeout = 300;
    public function __construct(public readonly int $drawingId) {}
    public function handle(SchematicGeneratorService $generator): void;  // status flips, idempotent send-once email, NotificationRecipientResolver
    public function failed(\Throwable $e): void;  // status=failed + admin DocumentGenerationFailedMail (idempotent via failed_email_sent_at)
}
```

From app/Mail/DrawingReadyMail.php (template for BoundPdfReadyMail):
```php
class DrawingReadyMail extends Mailable implements ShouldQueue {
    public function __construct(public readonly ProjectDrawing $drawing) {}
    public function envelope(): Envelope;     // builds "[ref] Schematic ready — {projectName}"
    public function content(): Content;       // 'emails.drawing-ready' view
    public function attachments(): array;     // attaches the artifact via DocumentArtifactStorage::TYPE_DRAWING
}
```

From routes/web.php (existing drawing routes — append to this same auth-middleware block):
```
projects.drawings.index    GET   projects/{project}/drawings
projects.drawings.download GET   projects/{project}/drawings/{drawing}/download/{format}
... (10 more drawings routes from Phase 17 + 18)
```

Project model field shape: `$project->ref` (e.g. "21CQ29001-09"), `$project->name`, `$project->id`. `Project::drawings(): HasMany`.

</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Sheet-number column + allocator + auto-set on draft create + composer add PDF merge library</name>
  <files>
    composer.json,
    database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php,
    app/Services/Drawings/SheetNumberAllocator.php,
    app/Services/Drawings/DrawingService.php,
    app/Models/ProjectDrawing.php,
    tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php
  </files>
  <read_first>
    @database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php (column-add migration shape precedent)
    @app/Services/Drawings/DrawingService.php (createForProject body — Task adds the allocator call)
    @app/Models/ProjectDrawing.php ($fillable list — Task adds 'sheet_number')
    @composer.json (current require block — Task adds setasign/fpdi for the PDF merge primitive used by Task 2; install in this Task so Task 2 finds it)
  </read_first>
  <behavior>
    - Migration: `up()` adds nullable varchar(20) `sheet_number` after `version` column on `project_drawings`; `down()` drops it.
    - SheetNumberAllocator::allocate(int $projectId, string $kind): string — given a project + kind, returns the next available AV-XXX in the kind-block (schematic=AV-201..299; rack=AV-301..399).
    - Numbering algorithm: count rows where `project_id = $projectId` AND `kind = $kind` AND `superseded_by_id IS NULL` AND `sheet_number LIKE 'AV-{block-prefix}%'`; next-number = block-base + count + 1. e.g. first schematic = AV-201, second = AV-202.
    - Block bases: KIND_SCHEMATIC → 200 (so first = 201); KIND_RACK → 300 (so first = 301).
    - Idempotent: re-calling on the same project after an archive (superseded_by_id set) skips the archived row, so a regeneration of AV-201 keeps producing AV-201 not AV-202.
    - DrawingService::createForProject: AFTER ProjectDrawing::create(), call $allocator->allocate($project->id, $kind) and persist to drawing->sheet_number IF the kind is SCHEMATIC or RACK (skip floor_plan — v2.0). Set-once: `if ($drawing->sheet_number === null) { ... }`.
    - ProjectDrawing::$fillable adds 'sheet_number'.

    Test 1 (SheetNumberAllocatorTest::test_first_schematic_gets_av_201): seed empty project, allocate(KIND_SCHEMATIC) returns 'AV-201'.
    Test 2 (test_second_schematic_gets_av_202): seed one ready schematic with sheet_number='AV-201'; allocate returns 'AV-202'.
    Test 3 (test_first_rack_gets_av_301): seed one schematic; allocate(KIND_RACK) returns 'AV-301' (rack block independent of schematic block).
    Test 4 (test_superseded_drawings_dont_consume_a_number): seed one superseded schematic with sheet_number='AV-201' AND one current schematic with 'AV-201'; allocate returns 'AV-202' (counts only non-superseded).
    Test 5 (test_floor_plan_kind_not_supported): allocate(KIND_FLOOR_PLAN) throws InvalidArgumentException with message mentioning "v2.0".
  </behavior>
  <action>
    1. Migration `2026_05_03_000001_add_sheet_number_to_project_drawings_table.php`:
       ```php
       Schema::table('project_drawings', function (Blueprint $table) {
           $table->string('sheet_number', 20)->nullable()->after('version');
       });
       ```
       down() drops 'sheet_number'.

    2. New `app/Services/Drawings/SheetNumberAllocator.php`:
       ```php
       namespace App\Services\Drawings;
       use App\Models\ProjectDrawing;
       use InvalidArgumentException;

       class SheetNumberAllocator {
           private const BLOCK_BASES = [
               ProjectDrawing::KIND_SCHEMATIC => 200,
               ProjectDrawing::KIND_RACK      => 300,
           ];

           public function allocate(int $projectId, string $kind): string {
               $base = self::BLOCK_BASES[$kind] ?? throw new InvalidArgumentException(
                   "SheetNumberAllocator: kind '{$kind}' not supported in v1.3 (floor plans land in v2.0)"
               );
               // Block-base + (occupied + 1). Schematics live in 200s, racks in 300s;
               // count non-superseded drawings of this kind in this project that already
               // have a sheet_number, then assign base + count + 1.
               $existing = ProjectDrawing::query()
                   ->where('project_id', $projectId)
                   ->where('kind', $kind)
                   ->whereNull('superseded_by_id')
                   ->whereNotNull('sheet_number')
                   ->count();
               return sprintf('AV-%d', $base + $existing + 1);
           }
       }
       ```

    3. Modify `DrawingService::createForProject` in `app/Services/Drawings/DrawingService.php`: inject `private readonly SheetNumberAllocator $sheetAllocator` into the constructor; AFTER the existing `ProjectDrawing::create([...])` call, BEFORE the Log::info, add:
       ```php
       if (in_array($kind, [ProjectDrawing::KIND_SCHEMATIC, ProjectDrawing::KIND_RACK], true)
           && $drawing->sheet_number === null) {
           $drawing->update(['sheet_number' => $this->sheetAllocator->allocate($project->id, $kind)]);
       }
       ```

    4. Add `'sheet_number'` to `ProjectDrawing::$fillable` array (after `'version'` to match migration column order).

    5. composer require: `setasign/fpdi:^2.6` AND `setasign/fpdf:^1.8` (FPDI core is MIT — verified at https://github.com/Setasign/FPDI; FPDF underlying is permissive/public-domain-style — keeps us OUT of TCPDF's LGPL trap which would force MOD-01 license-audit hits). FPDI 2.x uses FPDF as its rendering backend; both packages must be installed for `new setasign\Fpdi\Fpdi()` to instantiate. Run `composer require setasign/fpdi:^2.6 setasign/fpdf:^1.8 --no-scripts` to refresh composer.lock. Document the licence choice in the migration's PHPDoc comment. (NOTE: there is no `setasign/fpdi-fpdf` package on Packagist — do not require that name.)

    6. Create the test file with the 5 tests described in <behavior>. Use `RefreshDatabase` trait. Seed via `Project::factory()->create()` and direct `ProjectDrawing::create([...])` (bypass the allocator for setup so we test it in isolation).
  </action>
  <verify>
    <automated>php artisan migrate --pretend && php artisan test --filter=SheetNumberAllocatorTest && composer licenses 2>&1 | grep -i "setasign/fpdi" | grep -iv "GPL\|AGPL"</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan migrate:status` shows the new migration as PENDING (then RAN after migrate)
    - `grep -n "sheet_number" database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php` returns the column declaration line
    - `grep -n "sheet_number" app/Models/ProjectDrawing.php` returns the $fillable insertion
    - `grep -n "SheetNumberAllocator" app/Services/Drawings/DrawingService.php` returns at least one match (constructor inject + allocate call)
    - `php artisan test --filter=SheetNumberAllocatorTest` reports 5 tests / all green
    - `composer licenses 2>&1 | grep "setasign/fpdi"` returns a line containing "MIT" (NOT GPL or AGPL)
    - composer.lock updated (modified time newer than composer.json after the require step)
  </acceptance_criteria>
  <done>Sheet number column persisted on draft create; allocator unit-tested; FPDI installed under MIT; composer.lock committed; ProjectDrawing::$fillable extended; legacy schematic + rack creation flows automatically pick up sheet numbers via the existing createForProject path with zero call-site changes.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: BoundPdfBuilderService + cover Blade view + title-block sheet-number consumption + BuildBoundPdfJob + BoundPdfReadyMail</name>
  <files>
    app/Services/Drawings/BoundPdfBuilderService.php,
    app/Jobs/BuildBoundPdfJob.php,
    app/Mail/BoundPdfReadyMail.php,
    resources/views/emails/bound-pdf-ready.blade.php,
    resources/views/pdf/drawings/bound-cover.blade.php,
    resources/views/pdf/drawings/_title-block.blade.php,
    tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php
  </files>
  <read_first>
    @app/Services/Drawings/DrawingExportRendererService.php (renderPdf — bound builder loops over this)
    @app/Services/PdfRenderService.php (fromBlade — used to render the cover sheet)
    @app/Services/DocumentArtifactStorage.php (writePath — bound PDF lands at TYPE_DRAWING/bound-{projectId}-v{ulid}.pdf)
    @app/Jobs/BuildSchematicJob.php (handle/failed body shape — mirror exactly for BuildBoundPdfJob)
    @app/Mail/DrawingReadyMail.php (envelope/content/attachments shape — mirror for BoundPdfReadyMail)
    @resources/views/pdf/drawings/_title-block.blade.php (current title block — Task adds sheet_number row)
    @resources/views/pdf/drawings/schematic.blade.php (how _title-block is included — to confirm $drawing variable shape)
    @resources/views/pdf/drawings/rack.blade.php (same — to ensure title-block changes work for both kinds)
  </read_first>
  <behavior>
    - BoundPdfBuilderService::build(Project $project): array — returns ['path' => string, 'register' => array, 'failed_drawings' => array, 'generated_at' => Carbon, 'version' => int]
      where register = [['sheet_number' => 'AV-201', 'title' => '...', 'kind' => 'schematic', 'revision' => 'R0', 'status' => 'ready', 'date' => '2026-05-03'], ...]
      and failed_drawings = [['drawing_id' => int, 'error' => string], ...]
    - Order: schematics first (by created_at ASC), then racks (by created_at ASC). Filter to non-superseded, kind != floor_plan.
    - For EACH drawing, attempt PdfRenderService::fromBlade-via-DrawingExportRendererService::renderPdf inside try/catch. On exception: log warning, append to failed_drawings, register row gets title prefix "[render failed] " — drawing IS added to register, just NOT to the PDF concat.
    - Cover PDF rendered via PdfRenderService::fromBlade('pdf.drawings.bound-cover', ['project' => ..., 'register' => $register, 'failed_drawings' => $failed_drawings, 'generated_at' => $now]).
    - PDF concatenation: use FPDI to import each PDF page-by-page into a fresh FPDF instance; output to TYPE_DRAWING/bound-{projectId}-v{nextVersion}-{ulid}.pdf via DocumentArtifactStorage::writePath. Version = max(existing bound PDFs for this project) + 1; first time = 1.
    - Bound PDF path stored on Project via a new `latest_bound_pdf_path` JSON column? NO — instead, derive on-demand from filesystem listing (drawings/bound-{projectId}-*.pdf) sorted by mtime. Keeps the migration footprint zero. Alternative considered + rejected: the project's bound PDFs are ALWAYS regeneratable from data, so on-disk listing is the source of truth.
    - BuildBoundPdfJob: $tries=2, $timeout=300, $queue='drawings' (Plan 20-02 sets up the queue config; this Task targets the queue name now so dispatch works once 20-02 lands). Apply WithoutOverlapping middleware keyed by project_id. handle() calls BoundPdfBuilderService::build, then dispatches BoundPdfReadyMail to the project recipient (via existing NotificationRecipientResolver). Idempotent send-once: write `meta_completed_at` column? — too much migration noise. Instead: skip the email if the bound PDF was generated within last 60 seconds AND a previous send happened (track via a ProjectMeta row? — no, simpler: the email send is best-effort, log warnings on failure; if a duplicate fires, the recipient gets two emails — acceptable for a download trigger). Decision: NO idempotency table; rely on user-initiated dispatch + 60-second WithoutOverlapping window to dedupe.
    - failed() hook: log + admin DocumentGenerationFailedMail (mirror BuildSchematicJob::failed exactly), but with `documentType: 'Bound project drawings PDF'`.
    - BoundPdfReadyMail: subject `"[ref] Project drawings ready — {projectName}"`. Content view: `emails.bound-pdf-ready`. Attachment: the bound PDF via DocumentArtifactStorage::readPath.
    - Title block partial: add a row that displays `{{ $drawing->sheet_number ?? '—' }}` near the existing revision label. Apply to BOTH schematic + rack via the shared partial (single change covers both).

    Test 1 (BoundPdfBuilderServiceTest::test_two_schematic_project_produces_bound_pdf_with_three_pages): seed project + 2 ready schematics + mock DrawingExportRendererService to return 1-page PDFs; call build(); assert resulting PDF has 3 pages (1 cover + 2 drawings) by reading file with FPDI's setSourceFile()+setSourceFilePageCount() helper OR by counting "/Page" occurrences via raw byte read.
    Test 2 (test_failed_drawing_is_skipped_but_register_still_lists_it): mock renderer to throw on drawing #2; assert build() returns failed_drawings with one entry, register has 2 entries, output PDF has 2 pages (cover + 1 successful drawing).
    Test 3 (test_floor_plan_drawings_excluded): seed a floor_plan drawing AND a schematic; assert register has 1 entry (schematic only), no FloorPlanRendererException raised.
    Test 4 (test_register_orders_schematics_before_racks): seed 1 rack created Jan-01 + 1 schematic created Jan-02; assert register[0]['kind']=schematic, register[1]['kind']=rack (NOT chronological — kind-grouped).
    Test 5 (test_bound_pdf_filename_matches_pattern): assert returned path matches `~drawings/bound-\d+-v\d+-[0-9a-z]{26}\.pdf$~`.
  </behavior>
  <action>
    1. New `app/Services/Drawings/BoundPdfBuilderService.php`:
       ```php
       namespace App\Services\Drawings;
       use App\Models\Project;
       use App\Models\ProjectDrawing;
       use App\Services\DocumentArtifactStorage;
       use App\Services\PdfRenderService;
       use Carbon\Carbon;
       use Illuminate\Support\Facades\Log;
       use Illuminate\Support\Facades\Storage;
       use Illuminate\Support\Str;
       use setasign\Fpdi\Fpdi;

       class BoundPdfBuilderService {
           public function __construct(
               private readonly DrawingExportRendererService $renderer,
               private readonly PdfRenderService $pdf,
               private readonly DocumentArtifactStorage $artifacts,
           ) {}

           public function build(Project $project): array {
               $now = Carbon::now();
               $drawings = $project->drawings()
                   ->whereNull('superseded_by_id')
                   ->whereIn('kind', [ProjectDrawing::KIND_SCHEMATIC, ProjectDrawing::KIND_RACK])
                   ->orderByRaw("FIELD(kind, '".ProjectDrawing::KIND_SCHEMATIC."', '".ProjectDrawing::KIND_RACK."')")
                   ->orderBy('created_at')
                   ->get();

               // Per-drawing PDF render with isolation.
               $perDrawingPdfs = [];   // drawing_id => absolute path
               $failedDrawings = [];   // [['drawing_id' => int, 'error' => string], ...]
               $register = [];

               foreach ($drawings as $drawing) {
                   $titlePrefix = '';
                   try {
                       if (! $drawing->isReady()) {
                           throw new \RuntimeException("drawing not ready (status={$drawing->status})");
                       }
                       $perDrawingPdfs[$drawing->id] = $this->renderer->renderPdf($drawing);
                   } catch (\Throwable $e) {
                       Log::warning('BoundPdfBuilderService: per-drawing render failed (skipping in concat, marking in register)', [
                           'project_id' => $project->id,
                           'drawing_id' => $drawing->id,
                           'kind' => $drawing->kind,
                           'error' => $e->getMessage(),
                       ]);
                       $failedDrawings[] = ['drawing_id' => $drawing->id, 'error' => $e->getMessage()];
                       $titlePrefix = '[render failed] ';
                   }
                   $register[] = [
                       'sheet_number' => $drawing->sheet_number ?? '—',
                       'title'        => $titlePrefix.$drawing->kindLabel().' — '.($drawing->room?->name ?? $drawing->rack_label ?? 'Whole project'),
                       'kind'         => $drawing->kind,
                       'revision'     => $drawing->revisionLabel(),
                       'status'       => $drawing->status,
                       'date'         => optional($drawing->updated_at)->toDateString() ?? '—',
                   ];
               }

               // Render cover sheet to a temp PDF.
               $coverTmp = tempnam(sys_get_temp_dir(), 'cover-').'.pdf';
               $this->pdf->fromBlade(
                   'pdf.drawings.bound-cover',
                   ['project' => $project, 'register' => $register, 'failed_drawings' => $failedDrawings, 'generated_at' => $now],
                   $coverTmp,
               );

               // Concat with FPDI: cover + each per-drawing PDF.
               $version = $this->nextVersion($project->id);
               $filename = sprintf('bound-%d-v%d-%s.pdf', $project->id, $version, strtolower((string) Str::ulid()));
               $outPath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

               $this->concat($outPath, array_merge([$coverTmp], array_values($perDrawingPdfs)));

               @unlink($coverTmp);

               return [
                   'path'             => $outPath,
                   'register'         => $register,
                   'failed_drawings'  => $failedDrawings,
                   'generated_at'     => $now,
                   'version'          => $version,
               ];
           }

           private function concat(string $outPath, array $sourcePdfPaths): void {
               $pdf = new Fpdi();
               foreach ($sourcePdfPaths as $src) {
                   if (! is_file($src)) continue;
                   $pageCount = $pdf->setSourceFile($src);
                   for ($p = 1; $p <= $pageCount; $p++) {
                       $tplId = $pdf->importPage($p);
                       $size  = $pdf->getTemplateSize($tplId);
                       $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
                       $pdf->AddPage($orient, [$size['width'], $size['height']]);
                       $pdf->useTemplate($tplId);
                   }
               }
               $pdf->Output($outPath, 'F');
           }

           private function nextVersion(int $projectId): int {
               // Scan disk for existing bound-{projectId}-v*-*.pdf and pick max+1.
               $dir = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, '');
               $glob = glob(rtrim($dir, '/').'/bound-'.$projectId.'-v*-*.pdf') ?: [];
               $maxV = 0;
               foreach ($glob as $f) {
                   if (preg_match('/-v(\d+)-/', basename($f), $m)) {
                       $maxV = max($maxV, (int) $m[1]);
                   }
               }
               return $maxV + 1;
           }
       }
       ```

    2. New `app/Jobs/BuildBoundPdfJob.php`: mirror the BuildSchematicJob structure (ShouldQueue, $tries=2, $timeout=300, handle/failed shape) BUT diverge on the constructor: bound PDFs are project-level, not drawing-level. **Constructor signature is `public function __construct(public readonly int $projectId) {}`** — NOT `$drawingId` like BuildSchematicJob. Every reference inside the job (handle body, failed body, middleware key, log context) uses `$this->projectId`. Resolve the Project inside `handle()` via `Project::findOrFail($this->projectId)` and pass to BoundPdfBuilderService::build(). Replace generator call with BoundPdfBuilderService::build(); replace DrawingReadyMail with BoundPdfReadyMail (passing `$project` + `$boundPdfPath`); set `public string $queue = 'drawings';` (Plan 20-02 wires the queue worker; using the queue name now is forward-compatible — Laravel falls back to 'default' when the queue isn't configured separately, which is exactly the current state); apply `Illuminate\Queue\Middleware\WithoutOverlapping` middleware via `public function middleware(): array { return [(new WithoutOverlapping('bound-pdf-'.$this->projectId))->releaseAfter(60)]; }`. failed() body mirrors BuildSchematicJob::failed but uses 'Bound project drawings PDF' as the documentType.

    3. New `app/Mail/BoundPdfReadyMail.php`: mirror DrawingReadyMail; constructor takes `(public readonly Project $project, public readonly string $boundPdfPath)`; subject `"[{$project->ref}] Project drawings ready — {$project->name}"` (with bracket fallback to '' if ref empty); content view `emails.bound-pdf-ready`; attachment via `Attachment::fromPath($this->boundPdfPath)->as(basename($this->boundPdfPath))->withMime('application/pdf')`.

    4. New `resources/views/emails/bound-pdf-ready.blade.php`: minimal HTML — greeting, "Project {{ $project->ref ?? $project->name }} drawings handover PDF is ready (attached)", sign-off "21st Century AV Ltd". 30-50 lines.

    5. New `resources/views/pdf/drawings/bound-cover.blade.php`: A4 portrait cover page. Top: project ref + name + client name (large font). Middle: drawing register table — columns: Sheet | Title | Kind | Revision | Status | Date. Bottom: generation date + drawing count + revision summary. Use Tailwind-like inline CSS (NOT @vite — this Blade renders via Browsershot from raw HTML). Include `<style>` block with print-friendly @page, font-family Arial+Liberation Sans (CRIT-04 mitigation; @font-face declaration lands in 20-02). Render @foreach over $register with @foreach($register as $row) ... @endforeach. Highlight `[render failed]` rows in red. If failed_drawings non-empty, surface a banner row "{{ count($failed_drawings) }} drawing(s) failed to render — see register".

    6. Modify `resources/views/pdf/drawings/_title-block.blade.php`: add a sheet-number cell/row near the existing revision label. Wrap in `@if(! empty($drawing->sheet_number))` so the change is no-op for any drawing missing a number (defensive — shouldn't happen post-Task-1 but keeps backward compat for any pre-Task-1 rows).

    7. Test file `tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php`: 5 tests as described in <behavior>. Mock DrawingExportRendererService via Mockery to return paths to fixture 1-page PDFs (commit a single fixture PDF to `tests/fixtures/single-page.pdf` — generate via `Fpdi` in a setUp helper if needed). Use Storage::fake('documents') to avoid disk side-effects.
  </action>
  <verify>
    <automated>php artisan test --filter=BoundPdfBuilderServiceTest && php -r "require 'vendor/autoload.php'; \$pdf = new setasign\Fpdi\Fpdi(); echo 'FPDI loaded OK';"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -n "class BoundPdfBuilderService" app/Services/Drawings/BoundPdfBuilderService.php` returns a match
    - `grep -n "class BuildBoundPdfJob" app/Jobs/BuildBoundPdfJob.php` returns a match
    - `grep -n "WithoutOverlapping" app/Jobs/BuildBoundPdfJob.php` returns a match (the middleware() method)
    - `grep -n "queue = 'drawings'" app/Jobs/BuildBoundPdfJob.php` OR equivalent property assignment returns a match
    - `grep -n "class BoundPdfReadyMail" app/Mail/BoundPdfReadyMail.php` returns a match
    - `php artisan view:cache` succeeds with the new bound-cover.blade.php (no Blade compile error)
    - `grep -n "sheet_number" resources/views/pdf/drawings/_title-block.blade.php` returns a match
    - `php artisan test --filter=BoundPdfBuilderServiceTest` reports 5 tests / all green
    - Manual cli check: `php artisan tinker --execute="echo class_exists('\\setasign\\Fpdi\\Fpdi') ? 'OK' : 'MISSING';"` prints OK
  </acceptance_criteria>
  <done>BoundPdfBuilderService produces a deterministic-ordered bound PDF with isolated per-drawing failures; BuildBoundPdfJob mirrors the BuildSchematicJob shape with WithoutOverlapping; BoundPdfReadyMail + email view ship; cover Blade view renders the drawing register with failed-row highlighting; title-block partial consumes sheet_number; 5 unit tests cover ordering, failure isolation, kind filtering, filename pattern.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Routes + ProjectDrawingController download actions + drawings index UI (bound-PDF button + ZIP button + sheet column + regen-needed badge) + integration tests</name>
  <files>
    routes/web.php,
    app/Http/Controllers/ProjectDrawingController.php,
    resources/views/projects/drawings/index.blade.php,
    tests/Feature/Drawings/BoundPdfDownloadTest.php,
    tests/Feature/Drawings/ZipBundleDownloadTest.php
  </files>
  <read_first>
    @routes/web.php (existing drawings route block lines 313-351 — append new routes inside the same auth middleware group)
    @app/Http/Controllers/ProjectDrawingController.php (existing download() action shape — mirror auth + abort_if patterns)
    @app/Policies/ProjectDrawingPolicy.php (the view ability — bound-PDF download routes through the same policy)
    @resources/views/projects/drawings/index.blade.php (current index — Task adds bound-PDF button block + ZIP button + sheet column + badge)
    @app/Services/DocumentArtifactStorage.php (writePath/readPath/exists for the bound-PDF lookup + ZIP composition)
  </read_first>
  <behavior>
    - New route GET `projects/{project}/drawings/bound-pdf` → `ProjectDrawingController::downloadBoundPdf` → name `projects.drawings.bound-pdf`. If a current bound PDF exists on disk AND every drawing.updated_at <= bound-pdf-mtime → stream it as BinaryFileResponse. Otherwise dispatch BuildBoundPdfJob synchronously OR async based on a `?sync=1` query flag (default async — returns redirect with flash "Bound PDF queued — you'll receive an email when it's ready").
    - New route GET `projects/{project}/drawings/bound-pdf/build` → `ProjectDrawingController::regenerateBoundPdf` → name `projects.drawings.bound-pdf.build`. POST not GET? Use POST (mutates state via job dispatch). Dispatches BuildBoundPdfJob::dispatch($project->id) and redirects with flash.
    - New route GET `projects/{project}/drawings/bundle.zip` → `ProjectDrawingController::downloadBundle` → name `projects.drawings.bundle`. Builds ZIP on demand and streams.
    - downloadBoundPdf: $this->authorize('view', $project) — uses project policy not drawing policy because this is a project-level artifact. Locate latest bound PDF via the same nextVersion-1 logic as Task 2 BoundPdfBuilderService::nextVersion (extract a `latestBoundPdfPath(int $projectId): ?string` static helper there OR inline in the controller). If null OR stale → either redirect to bound-pdf.build OR build inline. Decision: build inline via a synchronous BoundPdfBuilderService::build() call IF the project has 0 drawings or 1-2 drawings (fast); otherwise dispatch the job. Set the threshold at 3 drawings — under that, sync; over, async-with-flash.
    - downloadBundle: stream ZIP via response()->streamDownload(callback: function() use ($project) { ... }, name: $filename, headers: ['Content-Type' => 'application/zip']). ZIP contents (sanitised filenames via basename to prevent ZIP path traversal):
      - bound PDF (if exists; otherwise call BoundPdfBuilderService::build inline first)
      - per ready drawing: PDF + SVG + PNG via DrawingExportRendererService::renderPdf/renderSvg/renderPng
      - drawing-register.csv built in-memory: columns Sheet,Title,Kind,Revision,Status,Date,Filename
    - ZIP filename: `{project->ref or projectId}-drawings-{Y-m-d}.zip` with non-alphanumerics replaced by `_`.
    - Index page changes:
      a) Add a header-row block ABOVE the schematics section: "Project Documents" with two buttons — "Download Bound PDF" (POST projects.drawings.bound-pdf.build OR GET projects.drawings.bound-pdf depending on whether the file exists) and "Download ZIP".
      b) If the latest bound PDF mtime < max(drawings.updated_at) AND the bound PDF exists, render an amber pill `<span class="badge bg-amber-100 text-amber-800 px-2 py-1 text-xs rounded">Regen needed — drawing changed</span>` next to the bound-PDF button.
      c) Add a "Sheet" column to BOTH the schematics list AND the racks list (display $drawing->sheet_number ?? '—').
    - Threat model for routes: ProjectPolicy gate (owner OR admin); ZIP entry names sanitized via basename(); no untrusted input flows into Chrome args; bound PDF filename includes ULID so old caches can't collide.

    Test 1 (BoundPdfDownloadTest::test_owner_can_download_bound_pdf_when_ready_drawings_exist): seed project + 2 ready schematics with sheet numbers; hit GET projects.drawings.bound-pdf; assert 200 response with Content-Type application/pdf AND response body starts with `%PDF`.
    Test 2 (test_non_owner_gets_403): different user; same route; assert 403.
    Test 3 (test_regen_needed_badge_renders_when_drawing_updated_after_bound_pdf): seed project, build bound PDF (so mtime stamped), update one drawing; hit GET projects.drawings.index; assert response sees text "Regen needed".
    Test 4 (ZipBundleDownloadTest::test_owner_can_download_zip_with_per_drawing_artifacts): seed project + 1 ready schematic + 1 ready rack; GET projects.drawings.bundle; assert 200 + Content-Disposition includes `.zip`; open ZIP from streamed bytes (collect from response()->getContent()) and assert it contains entries matching `~bound-.+\.pdf$~`, `~schematic-.+\.pdf$~`, `~schematic-.+\.svg$~`, `~schematic-.+\.png$~`, `~drawing-register\.csv$~`.
    Test 5 (test_zip_entry_names_have_no_path_traversal): assert no entry name contains `..` or starts with `/`.
  </behavior>
  <action>
    1. Append to `routes/web.php` inside the existing `auth` middleware group, immediately AFTER the existing `projects.drawings.regenerate` line:
       ```php
       Route::get('projects/{project}/drawings/bound-pdf',
           [ProjectDrawingController::class, 'downloadBoundPdf'])
           ->name('projects.drawings.bound-pdf');
       Route::post('projects/{project}/drawings/bound-pdf/build',
           [ProjectDrawingController::class, 'regenerateBoundPdf'])
           ->name('projects.drawings.bound-pdf.build');
       Route::get('projects/{project}/drawings/bundle.zip',
           [ProjectDrawingController::class, 'downloadBundle'])
           ->name('projects.drawings.bundle');
       ```

    2. Add three new methods to `app/Http/Controllers/ProjectDrawingController.php`:
       - `downloadBoundPdf(Project $project): BinaryFileResponse|RedirectResponse` — locate latest bound PDF on disk via glob; if exists AND fresh (mtime >= max drawings.updated_at) → return BinaryFileResponse; if exists AND stale → redirect to ::regenerateBoundPdf; if missing → call BoundPdfBuilderService::build inline (when drawings.count <= 3) OR dispatch BuildBoundPdfJob and redirect with flash.
       - `regenerateBoundPdf(Project $project): RedirectResponse` — authorize('view', $project), BuildBoundPdfJob::dispatch($project->id), redirect back with flash "Bound PDF queued — you'll receive an email when it's ready".
       - `downloadBundle(Project $project)` — authorize('view', $project), build ZIP via streamDownload + ZipArchive (open, addFile per artifact + drawing register CSV via addFromString, close, then re-open + stream + close + delete tmp). All entry names = basename($realPath) — explicit basename() call to prevent path traversal (CRIT — security_threat_model item).
       Inject BoundPdfBuilderService and DrawingExportRendererService into the constructor (extend existing constructor signature).

    3. Modify `resources/views/projects/drawings/index.blade.php`:
       - Add a "Project Documents" block ABOVE the System Schematics h2:
         ```blade
         @if($drawings->whereIn('kind', [\App\Models\ProjectDrawing::KIND_SCHEMATIC, \App\Models\ProjectDrawing::KIND_RACK])->isNotEmpty())
           <div class="bg-white border rounded-lg p-4 mb-6 flex items-center justify-between">
             <div>
               <div class="font-semibold text-gray-900">Project Documents</div>
               <div class="text-xs text-gray-500">Bound multi-page PDF + ZIP bundle</div>
               @if($boundPdfStaleBadge ?? false)
                 <span class="inline-block mt-2 px-2 py-1 text-xs rounded bg-amber-100 text-amber-800">Regen needed — drawing changed</span>
               @endif
             </div>
             <div class="flex gap-2">
               <a href="{{ route('projects.drawings.bound-pdf', $project) }}" class="bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium px-3 py-2 rounded-lg">Download Bound PDF</a>
               <a href="{{ route('projects.drawings.bundle', $project) }}" class="border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-3 py-2 rounded-lg">Download ZIP</a>
             </div>
           </div>
         @endif
         ```
       - In each drawing row's left-hand block, add a span showing `Sheet {{ $drawing->sheet_number ?? '—' }}` next to the existing "Revision Rx · Updated ..." line.
       - Modify ProjectDrawingController::index to compute `$boundPdfStaleBadge`: bool — true when latest bound PDF exists AND its mtime < max drawings.updated_at; pass to view alongside $drawings.

    4. Test file `tests/Feature/Drawings/BoundPdfDownloadTest.php`: 3 tests as described. Use RefreshDatabase + Storage::fake('documents'). Set $drawing->status = STATUS_READY directly + populate generated_svg with a stub SVG so DrawingExportRendererService::renderPdf doesn't throw assertReady. Bypass actual Browsershot by binding a partial mock of PdfRenderService that writes a stub PDF byte string to the requested path. (NOTE: this test exercises the whole controller→builder→merger path with Browsershot mocked — pure integration test of routing + auth + freshness logic, not Browsershot end-to-end which is covered by 20-02's smoke test.)

    5. Test file `tests/Feature/Drawings/ZipBundleDownloadTest.php`: 2 tests as described. Same Storage::fake + PdfRenderService mock pattern.
  </action>
  <verify>
    <automated>php artisan route:list --name=drawings.bound-pdf 2>&1 | grep -q bound-pdf && php artisan route:list --name=drawings.bundle 2>&1 | grep -q bundle && php artisan test --filter="BoundPdfDownloadTest|ZipBundleDownloadTest"</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan route:list | grep "drawings.bound-pdf"` returns at least 2 lines (GET download + POST build)
    - `php artisan route:list | grep "drawings.bundle"` returns at least 1 line
    - `grep -n "downloadBoundPdf\|downloadBundle\|regenerateBoundPdf" app/Http/Controllers/ProjectDrawingController.php` returns 3+ matches
    - `grep -n "basename(" app/Http/Controllers/ProjectDrawingController.php` returns at least 1 match in the downloadBundle method (ZIP path traversal mitigation)
    - `grep -n "Regen needed" resources/views/projects/drawings/index.blade.php` returns a match
    - `grep -n "sheet_number" resources/views/projects/drawings/index.blade.php` returns at least 2 matches (one per kind block)
    - `grep -n "Download Bound PDF\|Download ZIP" resources/views/projects/drawings/index.blade.php` returns 2 matches
    - `php artisan test --filter="BoundPdfDownloadTest|ZipBundleDownloadTest"` reports 5 tests / all green
    - `php artisan view:cache` succeeds (no Blade syntax errors in the modified index.blade.php)
  </acceptance_criteria>
  <done>Three new routes wired and policy-gated; controller download actions ship with explicit basename() ZIP-entry sanitisation; drawings index renders the Project Documents block + sheet-number column + regen-needed badge; 5 feature tests green covering happy path + 403 + staleness + ZIP contents + path-traversal mitigation; user can now click two buttons on the drawings page to receive every Phase 20 user-facing artifact.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → controller download routes | authenticated user requests a project-level artifact; project ownership must be enforced |
| controller → ZipArchive::addFile | filenames flow into ZIP entry names; path traversal possible if names not sanitized |
| controller → BoundPdfBuilderService → PdfRenderService → Chromium | rendered Blade content includes project + drawing data; Chromium flag injection NOT possible (flags are static) |
| email send → recipient | bound PDF attachment streams via DocumentArtifactStorage::readPath; mime hardcoded to application/pdf |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-20-01 | Information disclosure | downloadBoundPdf, downloadBundle, regenerateBoundPdf routes | mitigate | `$this->authorize('view', $project)` on every action; reuses existing ProjectPolicy (owner OR admin) registered in AppServiceProvider; verified by Test 2 (non-owner gets 403) |
| T-20-02 | Tampering | ZIP entry names | mitigate | Every entry name passed through `basename($realPath)` before `ZipArchive::addFile($realPath, basename($realPath))`; Test 5 asserts no entry contains `..` or absolute paths |
| T-20-03 | Tampering | bound PDF filename collision | mitigate | Filename pattern `bound-{projectId}-v{version}-{ulid}.pdf` includes a 26-char ULID — collision probability statistically zero; old bound PDFs eligible for cleanup but cannot poison new requests |
| T-20-04 | Denial of service | bound PDF generation on huge projects | mitigate (partial — full mitigation in 20-02) | WithoutOverlapping middleware on BuildBoundPdfJob keyed by project_id with releaseAfter(60s); per-drawing failures isolated so one bad drawing can't OOM the whole bound PDF build; full queue isolation lands in 20-02 |
| T-20-05 | Information disclosure | bound PDF cache poisoning across projects | mitigate | Filename includes `{projectId}` — a request for project A bound PDF can never resolve to project B's bound PDF; glob() pattern is project-scoped |
| T-20-06 | Repudiation | who triggered the bound PDF build | accept | Standard Laravel auth + Log::info captures user ID at dispatch time (BuildBoundPdfJob logs $userId); audit trail sufficient for an internal-only platform |
| T-20-07 | Spoofing | route auth | mitigate | All three new routes inside `auth` middleware group (existing pattern from Phase 17 + 18 routes 313-351) |
| T-20-08 | Elevation of privilege | composer add of setasign/fpdi | mitigate | Library is MIT licensed (FPDI core) + permissive (FPDF base); does NOT introduce TCPDF (LGPL trap); license verified via `composer licenses` in Task 1 acceptance criteria |
</threat_model>

<verification>
After all 3 tasks land:

1. **Migration applied**: `php artisan migrate:status | grep sheet_number` shows new migration as Ran
2. **Sheet numbering working end-to-end**:
   - Create a fresh project via UI flow, add 2 schematics + 2 racks
   - Inspect DB: `select id, kind, sheet_number from project_drawings where project_id = X` should show AV-201, AV-202, AV-301, AV-302
   - View any drawing's PDF (download via existing route): the title block shows the sheet number
3. **Bound PDF downloadable**: from the project drawings index page, click "Download Bound PDF". Receive a multi-page PDF with cover sheet + register table + every drawing's page.
4. **ZIP downloadable**: from the same page, click "Download ZIP". Receive a ZIP containing bound-*.pdf + per-drawing PDF/SVG/PNG + drawing-register.csv.
5. **Regen-needed badge**: edit a rack canvas (Phase 18 editor) AFTER downloading a bound PDF. Refresh the drawings index; amber "Regen needed" badge appears.
6. **Failure isolation**: temporarily corrupt one drawing's `generated_svg` to empty string; trigger bound-PDF build. Bound PDF still completes; register row for that drawing prefixed `[render failed]`; failed_drawings array logged in BuildBoundPdfJob log.
7. **Test suite**: `php artisan test --filter="SheetNumberAllocator|BoundPdfBuilder|BoundPdfDownload|ZipBundleDownload"` reports all 12 tests green.
8. **License audit**: `composer licenses 2>&1 | grep -iE "GPL|AGPL"` returns nothing for the new fpdi dependencies.
9. **Routes registered**: `php artisan route:list --name=drawings` lists 14 drawing routes (11 from Phase 17 + 18 plus the 3 new ones from this plan).
</verification>

<success_criteria>
DRAW-21, DRAW-23, DRAW-28 implemented. User can download a bound multi-page project PDF, sees auto-derived AV-201..AV-3xx sheet numbers on every drawing, and can download a ZIP bundle containing every drawing artifact. Per-drawing render failures don't abort the whole bound PDF. Regen-needed badge surfaces when drawings change after a bound PDF is built. ZIP entries cannot path-traverse. PDF merge library is MIT-licensed. Twelve unit + feature tests pass. Drawings index UI presents the new flows alongside the existing per-drawing download buttons. No Phase 17 or Phase 18 functionality regressed (existing routes + downloads + edit flows untouched).
</success_criteria>

<output>
After completion, create `.planning/phases/20-drawing-export-pipeline-o-m-integration/20-01-SUMMARY.md` covering: which decisions D-A through D-D from CONTEXT.md were honored exactly; what the bound PDF assembly path looks like end-to-end; how sheet numbers are allocated (algorithm + edge cases); ZIP composition + entry-name sanitization approach; failure semantics for bound PDF; tests added (count + scope); license audit result for setasign/fpdi; any deviations + rationale; commit shas.
</output>

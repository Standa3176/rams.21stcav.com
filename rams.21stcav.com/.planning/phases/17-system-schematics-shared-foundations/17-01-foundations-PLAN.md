---
phase: 17-system-schematics-shared-foundations
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_01_000001_create_project_drawings_table.php
  - database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php
  - app/Models/ProjectDrawing.php
  - app/Models/Device.php
  - app/Models/Project.php
  - app/Policies/ProjectDrawingPolicy.php
  - app/Services/DocumentArtifactStorage.php
  - app/Services/PdfRenderService.php
  - app/Services/Drawings/DrawingService.php
  - app/Services/Drawings/DrawingDataResolverService.php
  - app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php
  - app/Services/DocumentEdits/DocumentEditAdapterRegistry.php
  - app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php
  - app/Jobs/BuildSchematicJob.php
  - app/Mail/DrawingReadyMail.php
  - resources/views/emails/drawing-ready.blade.php
  - app/Http/Controllers/ProjectDrawingController.php
  - app/Providers/AppServiceProvider.php
  - routes/web.php
autonomous: true
requirements: [DRAW-24, DRAW-25, "DRAW-30 (scaffolding only — functional schematic chat lands in Phase 19)"]
must_haves:
  truths:
    - "project_drawings table exists with kind discriminator and superseded_by_id versioning column"
    - "ProjectDrawing model has status state machine constants (draft/for_review/approved/superseded/generating/ready/failed)"
    - "DocumentArtifactStorage::TYPE_DRAWING is a valid type constant accepted by writePath/readPath"
    - "PdfRenderService::fromBlade accepts a waitForJs option that defaults to false"
    - "PdfRenderService::fromBladeAsPng method exists and produces a PNG via the same Browsershot construction the PDF path uses"
    - "DrawingEditAdapter is registered in DocumentEditAdapterRegistry under type 'drawing' with operationSchemas() defining a fixed allow-list of layout-only operations"
    - "BuildSchematicJob class exists with $tries=2, $timeout=300, idempotent completion_email_sent_at and failed_email_sent_at, and failed() admin alert"
    - "DrawingReadyMail single mailable branches on $drawing->kind for subject/body"
    - "Routes /projects/{project}/drawings (index) and /projects/{project}/drawings/{drawing}/regenerate (POST) are wired to ProjectDrawingController"
    - "ProjectDrawingPolicy enforces owner-or-admin on view/update/delete (mirrors RamsDocumentPolicy)"
    - "Device model exposes isSource(), isDestination(), isProcessor() classification helpers driven by a signal_role column"
    - "DrawingService exposes createForProject(), generateInitial(), regenerate(), archivePrior() — generateInitial is the first-version dispatch path that does NOT archive prior (since none exists), regenerate archives the prior + dispatches the job"
  artifacts:
    - path: "database/migrations/2026_05_01_000001_create_project_drawings_table.php"
      provides: "project_drawings table schema"
      contains: "Schema::create('project_drawings'"
    - path: "database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php"
      provides: "Device.signal_role column for source/destination/processor classification"
      contains: "signal_role"
    - path: "app/Models/ProjectDrawing.php"
      provides: "Eloquent model with status + kind constants, relations, helpers"
      exports: ["STATUS_DRAFT", "STATUS_FOR_REVIEW", "STATUS_APPROVED", "STATUS_SUPERSEDED", "STATUS_GENERATING", "STATUS_READY", "STATUS_FAILED", "KIND_SCHEMATIC", "KIND_RACK", "KIND_FLOOR_PLAN"]
    - path: "app/Policies/ProjectDrawingPolicy.php"
      provides: "Owner-or-admin authorisation policy"
    - path: "app/Services/Drawings/DrawingService.php"
      provides: "Orchestration entry point — createForProject(), generateInitial(), regenerate(), archivePrior()"
    - path: "app/Services/Drawings/DrawingDataResolverService.php"
      provides: "Wraps ProjectDataService::resolve() and reshapes for drawing generators"
    - path: "app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php"
      provides: "DocumentEdits adapter scaffolding (returns documentType='drawing', allowedOperations() limited to layout ops)"
    - path: "app/Jobs/BuildSchematicJob.php"
      provides: "Queue job skeleton mirroring BuildOmManualJob"
    - path: "app/Mail/DrawingReadyMail.php"
      provides: "Single completion mailable, branches by kind"
  key_links:
    - from: "app/Services/DocumentArtifactStorage.php"
      to: "TYPE_DRAWING constant + types() array + assertType()"
      via: "Adding constant to types() return"
      pattern: "TYPE_DRAWING\\s*=\\s*'drawings'"
    - from: "app/Services/DocumentEdits/DocumentEditAdapterRegistry.php"
      to: "DrawingEditAdapter::class"
      via: "DEFAULT_MAP entry 'drawing' => DrawingEditAdapter::class"
      pattern: "'drawing'\\s*=>\\s*DrawingEditAdapter::class"
    - from: "app/Providers/AppServiceProvider.php"
      to: "Gate::policy(ProjectDrawing::class, ProjectDrawingPolicy::class)"
      via: "boot() registration"
      pattern: "Gate::policy\\(ProjectDrawing"
    - from: "routes/web.php"
      to: "ProjectDrawingController"
      via: "Authenticated route group"
      pattern: "projects/\\{project\\}/drawings"
---

<objective>
Land all shared drawings infrastructure that Phases 18 (rack), 19 (floor plan), and 20 (export) build on as pure additions: the `project_drawings` table, `ProjectDrawing` model + policy, `DocumentArtifactStorage::TYPE_DRAWING`, `PdfRenderService::waitForJs` extension + new `fromBladeAsPng()` method (so Plan 03 doesn't duplicate Browsershot construction — see Warning 8 below), `BuildSchematicJob` skeleton, `DrawingReadyMail`, `DrawingEditAdapter` scaffolding, `Device` signal-classification helpers, the orchestration `DrawingService` (with createForProject/generateInitial/regenerate/archivePrior — see Warning 9 below) + `DrawingDataResolverService` (read-only canonical reshape), and the routes/policy/registration wiring.

Purpose: Phase 17 owns the shared foundations. Bake everything every later drawing phase needs into this single migration + service set so Phases 18/19/20 are pure additions, never re-architects. Auto-generation logic for schematics (D2 + symbol pack + signal flow) lives in Plan 02; PDF/SVG/PNG export, list UI, and O&M wiring live in Plan 03. This plan is foundation only — no schematic Blade view, no D2 invocation, no symbol pack.

Output:
- One migration creating `project_drawings` (all columns Phases 18/19 will use, even where stubbed).
- One migration adding `signal_role` (and helpers) to `devices` for CRIT-05 prevention.
- `ProjectDrawing` Eloquent model with full status + kind state machine and `superseded_by_id` versioning.
- `ProjectDrawingPolicy` mirroring `RamsDocumentPolicy`.
- Extended `DocumentArtifactStorage` with `TYPE_DRAWING`.
- Extended `PdfRenderService::fromBlade()` with optional `waitForJs` flag (default false) AND a new `fromBladeAsPng()` method (so Plan 03's PNG renderer reuses the central Browsershot construction — chrome flags, sandbox config, etc. — instead of duplicating it).
- `DrawingService` (createForProject + generateInitial + regenerate + archivePrior) + `DrawingDataResolverService` (read-only).
- `DrawingEditAdapter` registered in `DocumentEditAdapterRegistry` with a fixed layout-only operation enum (DRAW-30 scaffolding only — no functional schematic chat in Phase 17).
- `BuildSchematicJob` job skeleton mirroring `BuildOmManualJob` exactly (full handle() + failed() bodies — no prose, no TODO).
- `DrawingReadyMail` single mailable branching on `$drawing->kind`.
- Email Blade view `emails/drawing-ready.blade.php`.
- `ProjectDrawingController` shell with `index`, `regenerate`, `show` (returning JSON status only — Plan 03 fills view + downloads).
- Routes wired in authenticated group.
- `Project::drawings()` HasMany relation.
- `AppServiceProvider::boot()` policy registration.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/17-system-schematics-shared-foundations/17-CONTEXT.md
@.planning/research/SUMMARY.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Services/DocumentArtifactStorage.php
@app/Services/PdfRenderService.php
@app/Models/RamsDocument.php
@app/Models/InstallProgramme.php
@app/Models/Device.php
@app/Policies/RamsDocumentPolicy.php
@app/Jobs/BuildOmManualJob.php
@app/Mail/RamsReadyMail.php
@app/Services/DocumentEdits/Adapters/RamsEditAdapter.php
@app/Services/DocumentEdits/Adapters/OmEditAdapter.php
@app/Services/DocumentEdits/DocumentEditAdapterRegistry.php
@app/Services/DocumentEdits/DocumentEditAdapterInterface.php
@app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php
@app/Providers/AppServiceProvider.php
@app/Core/Modules/Projects/ProjectDataService.php
@app/Services/InstallTaskGeneratorService.php
@app/Services/InstallProgrammeService.php
@database/migrations/2026_04_22_000001_create_commissioning_items_table.php
@database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php
@database/migrations/2026_04_28_151100_create_devices_table.php
@routes/web.php

<interfaces>
<!-- Key types/contracts the executor needs. Extracted from codebase. -->
<!-- Use these directly — no codebase exploration needed. -->

From app/Services/DocumentArtifactStorage.php:
```php
public const DISK = 'documents';
public const TYPE_RAMS      = 'rams';
public const TYPE_OM        = 'om-manuals';
public const TYPE_WORKSHEET = 'worksheets';
public const TYPE_CABLE     = 'cable-schedules';
public const TYPE_SNAGGING  = 'snagging';

public function writePath(string $type, string $filename): string;   // creates subdir on demand
public function readPath(string $type, string $filename): ?string;   // null when not found
public function exists(string $type, string $filename): bool;
public function delete(string $type, string $filename): void;        // idempotent
public function types(): array;                                       // returns array of TYPE_* values
private function assertType(string $type): void;                      // throws InvalidArgumentException

private const LEGACY_ROOTS = [
    self::TYPE_RAMS      => 'app/rams',
    self::TYPE_OM        => 'app/om-manuals',
    self::TYPE_WORKSHEET => 'app/private/worksheets',
    self::TYPE_CABLE     => 'app/private/cable-schedules',
];
// TYPE_SNAGGING is intentionally NOT in LEGACY_ROOTS (no pre-H-07 history); same applies to TYPE_DRAWING.
```

From app/Services/PdfRenderService.php:
```php
public function fromBlade(
    string $view,
    array $data,
    ?string $writeToPath = null,
    array $options = [],
): string;
// Existing options: 'headerHtml', 'footerHtml', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft'.
// Return: absolute path when $writeToPath supplied; otherwise raw PDF bytes.
// Browsershot built with: noSandbox(), addChromiumArguments(['disable-dev-shm-usage' => null, 'disable-setuid-sandbox' => null]),
// format('A4'), showBackground(), emulateMedia('print'), margins(...), and headerHtml/footerHtml when supplied.
```

From app/Services/DocumentEdits/DocumentEditAdapterInterface.php:
```php
interface DocumentEditAdapterInterface {
    public function documentType(): string;                                          // 'drawing' for new adapter
    public function loadPayload(int $documentId): ?array;
    public function allowedOperations(): array;
    public function applyOperation(array $payload, array $op): array;                // returns ['ok' => true, 'payload' => ...] or ['ok' => false, 'code' => ..., 'error' => ...]
    public function summariseDiff(array $before, array $after): array;
    public function commitChanges(int $documentId, array $payload): ?string;
}

// Optional method (surfaced via method_exists in DocumentEditParsingPromptFactory):
// public function operationSchemas(): array;   // maps op name => ['args' => [...], 'notes' => '...']
```

From app/Services/DocumentEdits/DocumentEditAdapterRegistry.php:
```php
private const DEFAULT_MAP = [
    'rams'      => RamsEditAdapter::class,
    'survey'    => SurveyEditAdapter::class,
    'worksheet' => WorksheetEditAdapter::class,
    'om'        => OmEditAdapter::class,
    'cable'     => CableEditAdapter::class,
];
// Add 'drawing' => DrawingEditAdapter::class.
public function for(string $type): DocumentEditAdapterInterface;
public function supportedTypes(): array;
```

From app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php:
```php
private function safeSnapshot(string $documentType, ?array $payload): array {
    return match ($documentType) {
        'worksheet' => $this->worksheetSnapshot($payload),
        'rams'      => $this->ramsSnapshot($payload),
        'survey'    => $this->surveySnapshot($payload),
        'om'        => $this->omSnapshot($payload),
        'cable'     => $this->cableSnapshot($payload),
        default     => [],     // <-- 'drawing' falls through here unless we add a case
    };
}
// Add a 'drawing' case + private drawingSnapshot() method that returns minimal safe fields:
// project_ref, kind, status, equipment_count (if available).
```

From app/Models/Project.php (existing — add drawings() relation):
```php
// Add: public function drawings(): HasMany { return $this->hasMany(ProjectDrawing::class); }
```

From app/Jobs/BuildOmManualJob.php (reference shape — mirror exactly for BuildSchematicJob):
```php
class BuildOmManualJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries   = 2;
    public int $timeout = 300;
    public function __construct(public readonly int $omManualId) {}
    public function handle(...);   // status flip + work + idempotent completion email + failed status on throw
    public function failed(\Throwable $e): void;   // idempotent failed_email_sent_at + DocumentGenerationFailedMail to admins
}
```

From app/Mail/RamsReadyMail.php (reference shape — mirror for DrawingReadyMail with kind branching):
```php
class RamsReadyMail extends Mailable implements ShouldQueue {
    use Queueable, SerializesModels;
    public function __construct(public readonly RamsDocument $rams) {}
    public function envelope(): Envelope;          // subject "[ref] RAMS ready — projectName"
    public function content(): Content;            // returns view: 'emails.rams-ready'
    public function attachments(): array;          // uses DocumentArtifactStorage::readPath(TYPE_RAMS, basename($filename))
}
```

From app/Models/RamsDocument.php (reference for status state machine):
```php
const STATUS_UPLOADED = 'uploaded';
const STATUS_AWAITING_REVIEW = 'awaiting_review';
const STATUS_APPROVED = 'approved';
const STATUS_GENERATING = 'generating';
const STATUS_COMPLETED = 'completed';
const STATUS_FAILED = 'failed';
const STATUS_SUPERSEDED = 'superseded';
// public function isSuperseded(): bool { return ! is_null($this->superseded_by_id); }
// statusLabel(): string;
// statusBadgeClass(): string;
```

From app/Services/InstallProgrammeService.php (the actual archive-prior pattern in this codebase — mirror exactly):
```php
// archive-prior implemented via STATUS change, NOT a self-FK. InstallProgramme has no superseded_by_id —
// it tracks "current vs historical" via status='archived'. ProjectDrawing layers the self-FK ON TOP of
// the same lifecycle: status flips to STATUS_SUPERSEDED + superseded_by_id is set so the index page
// can `whereNull('superseded_by_id')` (Plan 03 Task 2 list filter).
public function createForProject(Project $project, User $user): InstallProgramme {
    $this->archiveExisting($project);   // ← archives EVERY existing draft/active row first
    return InstallProgramme::create([...]);
}
public function archiveExisting(Project $project): void {   // ← simple status flip, no transaction
    foreach (InstallProgramme::where('project_id', $project->id)->whereIn('status', [DRAFT, ACTIVE])->get() as $programme) {
        $programme->status = STATUS_ARCHIVED;
        $programme->save();
    }
}
```

From app/Models/InstallProgramme.php (reference for archive-prior-version pattern):
```php
public const STATUS_DRAFT    = 'draft';
public const STATUS_ACTIVE   = 'active';
public const STATUS_COMPLETE = 'complete';
public const STATUS_ARCHIVED = 'archived';
// Project::activeInstallProgramme() enforces single-active via query scope.
```

From app/Policies/RamsDocumentPolicy.php (mirror exactly for ProjectDrawingPolicy):
```php
public function view(User $user, RamsDocument $rams): bool   { return $user->id === $rams->user_id || $user->isAdmin(); }
public function update(User $user, RamsDocument $rams): bool { return $user->id === $rams->user_id || $user->isAdmin(); }
public function delete(User $user, RamsDocument $rams): bool { return $user->id === $rams->user_id || $user->isAdmin(); }
```

From app/Core/Modules/Projects/ProjectDataService.php:
```php
public function resolve(Project $project): array;
// Returns canonical dataset with keys: project, equipment, rooms, activities, risks, survey, programme, cables, meta.
// READ-ONLY. Bound singleton. DrawingDataResolverService MUST consume only resolve() — never extracted_data/reviewed_data directly.
```

From database/migrations/2026_04_28_151100_create_devices_table.php (verified during planning — line 32):
```php
$table->string('part_no')->nullable();
```
The `part_no` column DOES physically exist on the `devices` table, so `->after('part_no')` is safe in
the new signal_role migration. The acceptance criteria below verify this with `Schema::hasColumn('devices', 'part_no')`.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Migrations + Device classification</name>
  <read_first>
    - database/migrations/2026_04_22_000001_create_commissioning_items_table.php (migration shape, comment style, foreignId/cascadeOnDelete pattern)
    - database/migrations/2026_04_26_000001_add_access_token_to_worksheets_table.php (additive nullable column pattern)
    - database/migrations/2026_04_28_151100_create_devices_table.php (verifies part_no column physically exists — required by `->after('part_no')` in the new signal_role migration; line 32 has `$table->string('part_no')->nullable();`)
    - app/Models/Device.php (current schema — add isSource/isDestination/isProcessor + relations)
    - .planning/research/ARCHITECTURE.md §2 Data Model (canonical column list — do not deviate)
    - .planning/research/PITFALLS.md CRIT-05 (signal-flow direction must come from classification, never row order)
    - .planning/research/PITFALLS.md MOD-05 (canvas state size — use MEDIUMTEXT)
  </read_first>
  <action>
    Create TWO migrations and extend the Device model.

    **Migration 1 — `database/migrations/2026_05_01_000001_create_project_drawings_table.php`:**

    ```php
    Schema::create('project_drawings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

        // Per-room drawings (Phase 17 schematics, Phase 19 floor plans). Nullable for project-wide master schematic (Claude's Discretion: NULL means whole-project; not used in Phase 17 v1).
        $table->foreignId('site_survey_room_id')->nullable()->constrained('site_survey_rooms')->nullOnDelete();

        // Kind discriminator. Stored as varchar(20), application-validated against constants on the model (matches install_tasks.status pattern).
        $table->string('kind', 20);   // 'schematic' | 'rack' | 'floor_plan'

        // Phase 18 — set when kind=rack. Nullable for non-rack rows.
        $table->string('rack_label', 100)->nullable();

        // Versioning — DRAW-24 (R0/R1/R2…). version is 1-indexed; R0 == version 1.
        $table->unsignedInteger('version')->default(1);
        $table->foreignId('superseded_by_id')->nullable()->constrained('project_drawings')->nullOnDelete();

        // Generation source — JSON snapshot from ProjectDataService at generation time.
        $table->json('source_data')->nullable();

        // Auto-generated SVG (D2 output for schematics, custom builder for racks). MEDIUMTEXT is overkill for SVG but matches storage cap for canvas_state. Keep both as longText for simplicity (Laravel maps 'longText' to MEDIUMTEXT-class on most drivers — but use mediumText explicitly for canvas_state per MOD-05).
        $table->longText('generated_svg')->nullable();

        // Konva scene graph for user edits (Phase 19). MEDIUMTEXT (16 MB) per MOD-05.
        $table->mediumText('canvas_state')->nullable();

        // Thumbnail PNG path (relative on documents disk — e.g. drawings/thumbnails/schematic-42.png).
        $table->string('thumbnail_png_path', 500)->nullable();

        // Pipeline status — model defines constants (STATUS_DRAFT/STATUS_FOR_REVIEW/STATUS_APPROVED/STATUS_SUPERSEDED/STATUS_GENERATING/STATUS_READY/STATUS_FAILED).
        $table->string('status', 20)->default('draft');
        $table->text('error_message')->nullable();

        // Stored export filename — current PDF filename for download. SVG/PNG paths derive from this convention (drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}).
        $table->string('filename', 500)->nullable();

        // Idempotent notification timestamps (NOTF-01 / NOTF-04).
        $table->timestamp('completion_email_sent_at')->nullable();
        $table->timestamp('failed_email_sent_at')->nullable();

        // Forward-compat for v1.4 client portal (per ARCHITECTURE.md §6.4). Nullable; not exposed in v1.3 routes.
        $table->string('access_token', 64)->nullable()->unique();

        $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->softDeletes();

        $table->index(['project_id', 'kind']);
        $table->index(['project_id', 'site_survey_room_id', 'kind']);
        $table->index(['project_id', 'status']);
    });
    ```

    Header docblock follows the commissioning_items migration style (§ what + § why + § references DRAW-24/DRAW-25 in REQUIREMENTS.md).

    **Migration 2 — `database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php`:**

    Adds three nullable columns to `devices`:
    ```php
    Schema::table('devices', function (Blueprint $table) {
        // CRIT-05 — drives signal-flow arrow direction in Phase 17 schematics.
        // Values: 'source' | 'destination' | 'processor' | null (unknown — schematic renders cable as undirected with warning).
        // ->after('part_no') is safe: part_no column was created by the
        // 2026_04_28_151100_create_devices_table migration (line 32). Verified
        // during planning. The acceptance criteria below also assert
        // Schema::hasColumn('devices','part_no') === true at runtime.
        $table->string('signal_role', 16)->nullable()->after('part_no');
    });
    ```

    Down migration drops the column.

    **Extend `app/Models/Device.php`:**

    Add to `$fillable`: `'signal_role'`.
    Add three classifier helpers BEFORE the closing `}`:
    ```php
    public const ROLE_SOURCE      = 'source';
    public const ROLE_DESTINATION = 'destination';
    public const ROLE_PROCESSOR   = 'processor';

    public function isSource(): bool      { return $this->signal_role === self::ROLE_SOURCE; }
    public function isDestination(): bool { return $this->signal_role === self::ROLE_DESTINATION; }
    public function isProcessor(): bool   { return $this->signal_role === self::ROLE_PROCESSOR; }

    /**
     * Returns true when signal_role is not classified — schematic generator must
     * render cables touching this device as undirected lines and surface a warning.
     * Phase 17 CRIT-05 protection: never infer direction from cable-row order.
     */
    public function hasUnknownSignalRole(): bool { return $this->signal_role === null; }
    ```

    Run `php artisan migrate` locally to confirm both migrations apply cleanly. Do NOT roll back; downstream tasks need the schema present.
  </action>
  <acceptance_criteria>
    - `database/migrations/2026_05_01_000001_create_project_drawings_table.php` exists.
    - `database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php` exists.
    - `php artisan migrate --pretend` (or actual migrate) reports both migrations without error.
    - **Blocker 4 verification — `part_no` column physically present BEFORE the new migration runs:** `php artisan tinker --execute="echo Schema::hasColumn('devices', 'part_no') ? 'PASS' : 'FAIL';"` returns `PASS`. (If FAIL: the `->after('part_no')` clause must be removed because the column doesn't exist yet — but planner verified it DOES exist via grep on `database/migrations/2026_04_28_151100_create_devices_table.php` line 32, so this should always pass.)
    - `grep -n "TYPE_SNAGGING\|TYPE_RAMS\|TYPE_OM\|TYPE_WORKSHEET\|TYPE_CABLE\|TYPE_DRAWING" app/Services/DocumentArtifactStorage.php` does NOT yet show TYPE_DRAWING (Task 2 adds it).
    - `grep -n "signal_role\|isSource\|isDestination\|isProcessor\|hasUnknownSignalRole\|ROLE_SOURCE\|ROLE_DESTINATION\|ROLE_PROCESSOR" app/Models/Device.php` shows all eight tokens.
    - `Schema::hasTable('project_drawings')` returns true after migrate (verify via `php artisan tinker --execute="echo Schema::hasTable('project_drawings') ? 'yes' : 'no';"`).
    - `Schema::hasColumns('project_drawings', ['project_id','site_survey_room_id','kind','rack_label','version','superseded_by_id','source_data','generated_svg','canvas_state','thumbnail_png_path','status','error_message','filename','completion_email_sent_at','failed_email_sent_at','access_token','generated_by'])` returns true.
    - `Schema::hasColumn('devices', 'signal_role')` returns true.
    - The new project_drawings table has the three indexes listed.
  </acceptance_criteria>
  <verify>
    <automated>php artisan migrate --pretend 2>&1 | grep -E "create_project_drawings_table|add_signal_classification_to_devices_table"</automated>
  </verify>
  <done>Both migrations present and runnable; Device has signal_role column and isSource/isDestination/isProcessor helpers.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Model + Policy + Storage + PdfRenderService extension (waitForJs + fromBladeAsPng)</name>
  <read_first>
    - app/Models/RamsDocument.php (status state machine + superseded_by_id precedent — mirror constants pattern)
    - app/Models/InstallProgramme.php (archive-prior-version status flow — mirror)
    - app/Policies/RamsDocumentPolicy.php (mirror exactly for ProjectDrawingPolicy)
    - app/Services/DocumentArtifactStorage.php (TYPE_* registry — extend with TYPE_DRAWING)
    - app/Services/PdfRenderService.php (Browsershot wrapper — extend fromBlade with optional waitForJs flag AND add fromBladeAsPng method)
    - app/Models/Project.php (add HasMany drawings() relation)
    - app/Providers/AppServiceProvider.php (register Gate::policy)
    - .planning/research/ARCHITECTURE.md §4.3 PdfRenderService waitForJs extension
    - .planning/research/PITFALLS.md MIN-03 (avoid foreignObject in SVG passing through Browsershot)
  </read_first>
  <action>
    Create the model, policy, storage extension, and PdfRenderService extension. Five files touched.

    **`app/Models/ProjectDrawing.php`:**

    ```php
    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\SoftDeletes;

    class ProjectDrawing extends Model
    {
        use HasFactory, SoftDeletes;

        // ── Kind discriminator (DRAW-25) ──
        public const KIND_SCHEMATIC  = 'schematic';
        public const KIND_RACK       = 'rack';
        public const KIND_FLOOR_PLAN = 'floor_plan';

        // ── Status state machine (DRAW-25) — workflow + pipeline both live here ──
        public const STATUS_DRAFT      = 'draft';
        public const STATUS_FOR_REVIEW = 'for_review';
        public const STATUS_APPROVED   = 'approved';
        public const STATUS_SUPERSEDED = 'superseded';
        public const STATUS_GENERATING = 'generating';
        public const STATUS_READY      = 'ready';
        public const STATUS_FAILED     = 'failed';

        protected $fillable = [
            'project_id', 'site_survey_room_id', 'kind', 'rack_label',
            'version', 'superseded_by_id',
            'source_data', 'generated_svg', 'canvas_state', 'thumbnail_png_path',
            'status', 'error_message', 'filename',
            'completion_email_sent_at', 'failed_email_sent_at',
            'access_token', 'generated_by',
        ];

        protected $casts = [
            'source_data'              => 'array',
            'completion_email_sent_at' => 'datetime',
            'failed_email_sent_at'     => 'datetime',
            'version'                  => 'integer',
            'deleted_at'               => 'datetime',
        ];

        // canvas_state stays as raw text — Phase 19 will gzcompress it (MOD-05); no auto-cast.

        public function project(): BelongsTo       { return $this->belongsTo(Project::class); }
        public function room(): BelongsTo          { return $this->belongsTo(SiteSurveyRoom::class, 'site_survey_room_id'); }
        public function generatedBy(): BelongsTo   { return $this->belongsTo(User::class, 'generated_by'); }
        public function supersededBy(): BelongsTo  { return $this->belongsTo(self::class, 'superseded_by_id'); }
        public function predecessors(): HasMany    { return $this->hasMany(self::class, 'superseded_by_id'); }

        public function isSchematic(): bool        { return $this->kind === self::KIND_SCHEMATIC; }
        public function isRack(): bool             { return $this->kind === self::KIND_RACK; }
        public function isFloorPlan(): bool        { return $this->kind === self::KIND_FLOOR_PLAN; }

        public function isSuperseded(): bool       { return ! is_null($this->superseded_by_id); }
        public function hasUserEdits(): bool       { return ! empty($this->canvas_state); }
        public function isReady(): bool            { return $this->status === self::STATUS_READY; }
        public function isFailed(): bool           { return $this->status === self::STATUS_FAILED; }

        public function statusLabel(): string {
            return match ($this->status) {
                self::STATUS_DRAFT      => 'Draft',
                self::STATUS_FOR_REVIEW => 'For Review',
                self::STATUS_APPROVED   => 'Approved',
                self::STATUS_SUPERSEDED => 'Superseded',
                self::STATUS_GENERATING => 'Generating',
                self::STATUS_READY      => 'Ready',
                self::STATUS_FAILED     => 'Failed',
                default                 => ucfirst(str_replace('_', ' ', $this->status)),
            };
        }

        public function statusBadgeClass(): string {
            return match ($this->status) {
                self::STATUS_DRAFT      => 'badge-grey',
                self::STATUS_FOR_REVIEW => 'badge-yellow',
                self::STATUS_APPROVED   => 'badge-green',
                self::STATUS_SUPERSEDED => 'badge-grey',
                self::STATUS_GENERATING => 'badge-teal',
                self::STATUS_READY      => 'badge-blue',
                self::STATUS_FAILED     => 'badge-red',
                default                 => 'badge-grey',
            };
        }

        public function kindLabel(): string {
            return match ($this->kind) {
                self::KIND_SCHEMATIC  => 'System Schematic',
                self::KIND_RACK       => 'Rack Elevation',
                self::KIND_FLOOR_PLAN => 'Floor Plan',
                default               => ucfirst(str_replace('_', ' ', $this->kind)),
            };
        }

        public function revisionLabel(): string {
            return 'R' . max(0, ((int) $this->version) - 1);
        }
    }
    ```

    **`app/Models/Project.php` — add `drawings()` HasMany relation** (find existing `hasMany` block alongside other relations like `installProgrammes()`, `worksheets()`, etc., and add):
    ```php
    public function drawings(): \Illuminate\Database\Eloquent\Relations\HasMany {
        return $this->hasMany(\App\Models\ProjectDrawing::class);
    }
    ```

    **`app/Policies/ProjectDrawingPolicy.php`:**

    Mirror `RamsDocumentPolicy` exactly with three methods (view/update/delete). Owner determined by `$drawing->generated_by`. Admin override via `$user->isAdmin()`. PHPDoc references DRAW-25 and the registration line.

    ```php
    namespace App\Policies;

    use App\Models\ProjectDrawing;
    use App\Models\User;

    class ProjectDrawingPolicy
    {
        public function view(User $user, ProjectDrawing $drawing): bool   { return $user->id === $drawing->generated_by || $user->isAdmin(); }
        public function update(User $user, ProjectDrawing $drawing): bool { return $user->id === $drawing->generated_by || $user->isAdmin(); }
        public function delete(User $user, ProjectDrawing $drawing): bool { return $user->id === $drawing->generated_by || $user->isAdmin(); }
    }
    ```

    **`app/Services/DocumentArtifactStorage.php` — extend:**

    Add `public const TYPE_DRAWING = 'drawings';` immediately after `TYPE_SNAGGING`. The constant docblock matches TYPE_SNAGGING (no LEGACY_ROOTS entry — drawings are post-H-07 only). Update `types()` array to include `self::TYPE_DRAWING`. Do NOT add to LEGACY_ROOTS.

    **`app/Services/PdfRenderService.php` — extend `fromBlade()` AND add `fromBladeAsPng()` (Warning 8 — eliminate Browsershot duplication in Plan 03):**

    1. **Existing `fromBlade()` — add `waitForJs` option:** if `$options['waitForJs'] === true`, append `->waitUntilNetworkIdle()->waitForFunction('window.__drawingReady === true')` BEFORE the `savePdf` / `pdf()` call. Update the PHPDoc to document the new option:
       ```
       *                                 - 'waitForJs' (bool, default false) — when true,
       *                                   Browsershot waits for window.__drawingReady === true.
       *                                   Used by Phase 17 schematic edit-override and Phase 19
       *                                   Konva renders. The Blade view is responsible for setting
       *                                   window.__drawingReady = true after client-side rendering.
       ```
       The default `false` keeps every existing call site (RAMS / O&M / Site Survey PDFs) byte-for-byte identical.

    2. **NEW method `fromBladeAsPng()` — central Browsershot construction for PNG output (Warning 8):**

       Plan 03 Task 1 originally inlined `Browsershot::html(...)->setChromePath(env(...))->noSandbox()->...->save($png)` directly inside `DrawingExportRendererService::renderPng`. That duplicates the chrome path / sandbox / chromium-args construction that already lives here in PdfRenderService. Phase 20 will likely add chrome flags for CRIT-03 mitigation; if the PNG path uses its own Browsershot construction those flags won't be picked up. Land the method here so Plan 03's PNG renderer just delegates.

       Add the method (signature mirrors `fromBlade` so callers don't need to learn a new shape):
       ```php
       /**
        * Render a Blade view to PNG via Browsershot screenshot — uses the SAME
        * Browsershot construction (chrome path, no-sandbox, --disable-dev-shm-usage,
        * --disable-setuid-sandbox) as fromBlade(). Phase 17 schematics, Phase 19
        * floor plans, and Phase 20 thumbnails ALL go through this method so Phase 20's
        * CRIT-03 hardening (chrome flags, dedicated queue) lands in one place.
        *
        * @param string      $view        Blade view name.
        * @param array       $data        View data.
        * @param string|null $writeToPath Absolute output path; if null, raw PNG bytes returned.
        * @param array       $options     Optional:
        *                                 - 'waitForJs' (bool, default false) — same semantics as fromBlade.
        *                                 - 'widthPx'   (int, default 1920)  — Browsershot windowSize width.
        *                                 - 'heightPx'  (int, default null)  — when null, computed as widthPx * 0.707
        *                                                                       (A4 portrait aspect; pass explicit value
        *                                                                       for thumbnails, e.g. widthPx=400).
        *
        * @return string Absolute file path (when $writeToPath provided) or raw PNG bytes.
        */
       public function fromBladeAsPng(
           string $view,
           array $data,
           ?string $writeToPath = null,
           array $options = [],
       ): string {
           if (! View::exists($view)) {
               throw new \RuntimeException(
                   'PNG template missing: resources/views/' . str_replace('.', '/', $view) . '.blade.php'
               );
           }

           $html     = view($view, $data)->render();
           $widthPx  = (int) ($options['widthPx']  ?? 1920);
           $heightPx = (int) ($options['heightPx'] ?? intval($widthPx * 0.707));

           $shot = Browsershot::html($html)
               ->setChromePath(env('CHROME_PATH', '/home/stcav/chrome'))
               ->noSandbox()
               ->addChromiumArguments([
                   'disable-dev-shm-usage'  => null,
                   'disable-setuid-sandbox' => null,
               ])
               ->showBackground()
               ->emulateMedia('print')
               ->windowSize($widthPx, $heightPx);

           if (! empty($options['waitForJs'])) {
               $shot->waitUntilNetworkIdle()->waitForFunction('window.__drawingReady === true');
           }

           if ($writeToPath !== null) {
               $dir = dirname($writeToPath);
               if (! is_dir($dir)) {
                   mkdir($dir, 0755, true);
               }
               $shot->save($writeToPath);   // Browsershot infers PNG format from .png extension.
               return $writeToPath;
           }

           // Raw PNG bytes — Browsershot's screenshot() returns binary PNG.
           return $shot->screenshot();
       }
       ```

    **`app/Providers/AppServiceProvider.php` — register policy:**

    In the `boot()` method, add (next to existing `Gate::policy(...)` lines):
    ```php
    Gate::policy(\App\Models\ProjectDrawing::class, \App\Policies\ProjectDrawingPolicy::class);
    ```

    Add `use App\Models\ProjectDrawing;` and `use App\Policies\ProjectDrawingPolicy;` imports if you prefer (otherwise keep fully qualified). Match the existing style.
  </action>
  <acceptance_criteria>
    - `app/Models/ProjectDrawing.php` exists; `grep -n "STATUS_DRAFT\|STATUS_FOR_REVIEW\|STATUS_APPROVED\|STATUS_SUPERSEDED\|STATUS_GENERATING\|STATUS_READY\|STATUS_FAILED\|KIND_SCHEMATIC\|KIND_RACK\|KIND_FLOOR_PLAN" app/Models/ProjectDrawing.php` shows all 10 constants.
    - `grep -n "use SoftDeletes\|isSchematic\|isRack\|isFloorPlan\|isSuperseded\|hasUserEdits\|statusLabel\|statusBadgeClass\|kindLabel\|revisionLabel\|supersededBy\|predecessors\|generatedBy\|drawings.*HasMany" app/Models/ProjectDrawing.php app/Models/Project.php` shows the relation methods and helpers wired.
    - `grep -n "TYPE_DRAWING" app/Services/DocumentArtifactStorage.php` shows the constant defined AND included in `types()` return.
    - `php artisan tinker --execute="echo app(App\Services\DocumentArtifactStorage::class)->writePath('drawings', 'smoke.txt');"` returns a path containing `documents/drawings/smoke.txt` (the call must not throw `InvalidArgumentException`).
    - `grep -n "waitForJs\|fromBladeAsPng" app/Services/PdfRenderService.php` shows BOTH new pieces — the option AND the method — present.
    - `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\PdfRenderService::class, 'fromBladeAsPng') ? 'PASS' : 'FAIL';"` returns `PASS`.
    - `grep -n "ProjectDrawingPolicy\|Gate::policy.*ProjectDrawing" app/Providers/AppServiceProvider.php` shows policy registered in boot().
    - `app/Policies/ProjectDrawingPolicy.php` defines view, update, delete methods that each return `$user->id === $drawing->generated_by || $user->isAdmin()`.
    - Existing RAMS/O&M/Survey PDF generation tests (or a quick `php artisan pdf:smoke-test`) still pass — no regression from PdfRenderService extension.
  </acceptance_criteria>
  <verify>
    <automated>php artisan tinker --execute="\$s = app(App\Services\DocumentArtifactStorage::class); echo in_array(App\Services\DocumentArtifactStorage::TYPE_DRAWING, \$s->types(), true) && method_exists(App\Services\PdfRenderService::class, 'fromBladeAsPng') ? 'PASS' : 'FAIL';"</automated>
  </verify>
  <done>ProjectDrawing model + policy + Project::drawings() relation present; TYPE_DRAWING valid in DocumentArtifactStorage; PdfRenderService::fromBlade accepts waitForJs option AND PdfRenderService::fromBladeAsPng exists (no Browsershot duplication in Plan 03 — Warning 8).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Drawing services + Job + Mail + EditAdapter + Routes + Controller shell</name>
  <read_first>
    - app/Jobs/BuildOmManualJob.php (mirror exactly: $tries/$timeout/handle/failed shape — full handle + failed bodies are written below in this task, NOT prose)
    - app/Mail/RamsReadyMail.php (mirror for DrawingReadyMail with kind branching)
    - app/Services/DocumentEdits/Adapters/RamsEditAdapter.php (operationSchemas() pattern)
    - app/Services/DocumentEdits/Adapters/OmEditAdapter.php (commit + log pattern)
    - app/Services/DocumentEdits/DocumentEditAdapterRegistry.php (DEFAULT_MAP add)
    - app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php (safeSnapshot match — add 'drawing' case)
    - app/Core/Modules/Projects/ProjectDataService.php (resolve() contract — DrawingDataResolverService MUST consume only this)
    - app/Services/InstallTaskGeneratorService.php (ProjectDataService consumer precedent)
    - app/Services/InstallProgrammeService.php (regenerate-archives-prior pattern reference — DrawingService below mirrors archiveExisting → create → return shape, layered with the self-FK that ProjectDrawing carries)
    - app/Services/NotificationRecipientResolver.php (resolveProjectRecipient + resolveAdminRecipients — mail dispatch needs both)
    - app/Mail/DocumentGenerationFailedMail.php (admin failure mailable — failed() hook reuses)
    - resources/views/emails/rams-ready.blade.php (mirror template)
    - routes/web.php (om-manuals route block — pattern for projects/{project}/drawings)
  </read_first>
  <action>
    Create six service/job/mail/adapter files plus the controller shell, update three registrations, and wire routes.

    **`app/Services/Drawings/DrawingDataResolverService.php`:**

    Tight read-only wrapper around `ProjectDataService::resolve()`. Phase 17 only implements `adjacencyForProject()` (the schematic-shaped reshape consumed by Plan 02). Stub `rackStackForProject()` and `floorPlanGlyphsForRoom()` to throw `RuntimeException("Implemented in Phase 18")` / `("Implemented in Phase 19")` — matches the build-order doctrine in ARCHITECTURE.md §8.

    ```php
    namespace App\Services\Drawings;

    use App\Core\Modules\Projects\ProjectDataService;
    use App\Models\Project;

    /**
     * READ-ONLY reshape of ProjectDataService::resolve() into drawing-shaped views.
     * Generators MUST NOT touch extracted_data / reviewed_data / survey_data directly.
     * Phase 17 implements adjacencyForProject() only; rack + floor plan reshape
     * land in Phases 18 + 19 respectively. (DATA-03 contract — locked.)
     */
    class DrawingDataResolverService
    {
        public function __construct(private readonly ProjectDataService $projectDataService) {}

        /**
         * Per-room signal-flow adjacency.
         *
         * @return array<int, array{
         *   room_id: int|null,
         *   room_name: string,
         *   devices: array<int, array{equipment_id: int|string, name: string, manufacturer: string|null, model: string|null, signal_role: string|null}>,
         *   cables: array<int, array{cable_id: string, source_equipment_id: int|string|null, source_port: string|null, dest_equipment_id: int|string|null, dest_port: string|null, signal_type: string|null}>,
         * }>
         */
        public function adjacencyForProject(Project $project): array
        {
            $data = $this->projectDataService->resolve($project);
            // Phase 17 Plan 02 fills the body — this stub returns the shape so the
            // generator can be wired against it now.
            // The shape MUST be derived from $data['rooms'], $data['equipment'], $data['cables'].
            // Plan 01 leaves the body as a TODO stub; Plan 02's task 1 implements it.
            return [];
        }

        public function rackStackForProject(Project $project): array
        {
            throw new \RuntimeException('rackStackForProject implemented in Phase 18');
        }

        public function floorPlanGlyphsForRoom(Project $project, int $roomId): array
        {
            throw new \RuntimeException('floorPlanGlyphsForRoom implemented in Phase 19');
        }
    }
    ```

    **`app/Services/Drawings/DrawingService.php`** — full code-level skeleton (Blocker 1):

    Orchestration entry point. Mirrors `InstallProgrammeService` pattern (regenerate-archives-prior). Phase 17 implements FOUR methods (the original three PLUS `generateInitial` per Warning 9):

    - `createForProject(Project $project, string $kind, ?int $roomId, int $userId): ProjectDrawing` — creates a new draft row, version 1, status STATUS_DRAFT. Does NOT dispatch the job (separation of concerns — Plan 03 controller calls `generateInitial` next to dispatch).
    - **`generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing`** (Warning 9 fix — option (a)) — dispatches BuildSchematicJob WITHOUT archive-prior semantics. This is the path used right after `createForProject` for the very first version of a drawing. Status flip: STATUS_DRAFT → STATUS_GENERATING; dispatches `BuildSchematicJob::dispatch($drawing->id)` for kind=schematic. (For kind=rack/floor_plan throw RuntimeException with phase pointer per build-order doctrine.)
    - `regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing` — replicates row, bumps version, sets `superseded_by_id` on the old row, dispatches `BuildSchematicJob`. ONLY use when a prior row already exists. Status transition on the NEW row: STATUS_DRAFT → STATUS_GENERATING.
    - `archivePrior(ProjectDrawing $existing, ProjectDrawing $newRow): void` — sets old row's status to STATUS_SUPERSEDED and `superseded_by_id` to the new row's id (transactional).

    Full skeleton (executor implements verbatim — fidelity matches DrawingDataResolverService above):

    ```php
    <?php

    namespace App\Services\Drawings;

    use App\Core\Modules\Projects\ProjectDataService;
    use App\Jobs\BuildSchematicJob;
    use App\Models\Project;
    use App\Models\ProjectDrawing;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;

    /**
     * DrawingService — orchestration entry point for the v1.3 drawings module.
     *
     * Mirrors the InstallProgrammeService precedent (Phase 12) for "regenerate
     * archives prior". Layered with the project_drawings.superseded_by_id self-FK
     * so Plan 03's index page can `whereNull('superseded_by_id')` to show only the
     * current revision per kind+room.
     *
     * Method matrix:
     *   createForProject()  — create row #1 (status=draft). Does NOT dispatch the job.
     *   generateInitial()   — flip status=generating + dispatch job (no archive-prior;
     *                         used for the very first version after createForProject).
     *   regenerate()        — replicate + bump version + archive prior + dispatch job
     *                         (used for every revision AFTER the first).
     *   archivePrior()      — internal helper; transactional supersede flip.
     *
     * CRIT-02 (lock-on-edit + archive-prior) + DRAW-24 (revision tracking) + DRAW-25
     * (status state machine).
     *
     * @see InstallProgrammeService for the regenerate-archives-prior precedent.
     * @see app/Jobs/BuildSchematicJob.php — dispatched async work.
     */
    class DrawingService
    {
        public function __construct(
            private readonly ProjectDataService $projectDataService,
            private readonly DrawingDataResolverService $resolver,
        ) {}

        /**
         * Create a new draft row for a project. Does NOT dispatch the build job —
         * call generateInitial() next.
         */
        public function createForProject(
            Project $project,
            string $kind,
            ?int $roomId,
            int $userId,
        ): ProjectDrawing {
            $drawing = ProjectDrawing::create([
                'project_id'          => $project->id,
                'site_survey_room_id' => $roomId,
                'kind'                => $kind,
                'version'             => 1,
                'status'              => ProjectDrawing::STATUS_DRAFT,
                'generated_by'        => $userId,
                'source_data'         => $this->projectDataService->resolve($project),
            ]);

            Log::info('DrawingService: drawing created', [
                'drawing_id' => $drawing->id,
                'project_id' => $project->id,
                'kind'       => $kind,
                'room_id'    => $roomId,
                'user_id'    => $userId,
            ]);

            return $drawing;
        }

        /**
         * Dispatch the build job for the very first version (no archive-prior).
         * Use this immediately after createForProject() in the controller create-flow.
         *
         * Warning 9 fix: avoids the "create then immediately archive" UX bug.
         */
        public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing
        {
            if ($drawing->kind !== ProjectDrawing::KIND_SCHEMATIC) {
                throw new \RuntimeException(
                    "DrawingService::generateInitial: kind '{$drawing->kind}' lands in Phase 18/19"
                );
            }

            $drawing->update([
                'status'       => ProjectDrawing::STATUS_GENERATING,
                'generated_by' => $userId,
            ]);

            BuildSchematicJob::dispatch($drawing->id);

            Log::info('DrawingService: generateInitial dispatched', [
                'drawing_id' => $drawing->id,
                'kind'       => $drawing->kind,
            ]);

            return $drawing;
        }

        /**
         * Regenerate an existing drawing — replicate row, bump version, archive
         * prior, dispatch BuildSchematicJob.
         *
         * Wrapped in DB::transaction so a failure rolls back BOTH the new row AND
         * the supersede flip. Job dispatch happens AFTER commit (via afterCommit())
         * so a queue worker never sees a phantom row.
         */
        public function regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing
        {
            if ($existing->kind !== ProjectDrawing::KIND_SCHEMATIC) {
                throw new \RuntimeException(
                    "DrawingService::regenerate: kind '{$existing->kind}' lands in Phase 18/19"
                );
            }

            $newRow = DB::transaction(function () use ($existing, $userId): ProjectDrawing {
                // Replicate but DROP per-version artifacts so the new row starts clean.
                $newRow = $existing->replicate([
                    'canvas_state',
                    'generated_svg',
                    'thumbnail_png_path',
                    'filename',
                    'completion_email_sent_at',
                    'failed_email_sent_at',
                    'superseded_by_id',
                    'access_token',
                ]);

                $newRow->version       = ((int) $existing->version) + 1;
                $newRow->status        = ProjectDrawing::STATUS_GENERATING;
                $newRow->generated_by  = $userId;
                $newRow->source_data   = $this->projectDataService->resolve($existing->project);
                $newRow->error_message = null;
                $newRow->save();

                $this->archivePrior($existing, $newRow);

                return $newRow;
            });

            BuildSchematicJob::dispatch($newRow->id);

            Log::info('DrawingService: regenerate dispatched', [
                'old_drawing_id' => $existing->id,
                'new_drawing_id' => $newRow->id,
                'kind'           => $newRow->kind,
                'version'        => $newRow->version,
            ]);

            return $newRow;
        }

        /**
         * Internal: flip the prior row to STATUS_SUPERSEDED and link it to the new
         * row via superseded_by_id. Called only from inside regenerate()'s
         * DB::transaction block.
         */
        public function archivePrior(ProjectDrawing $existing, ProjectDrawing $newRow): void
        {
            $existing->status           = ProjectDrawing::STATUS_SUPERSEDED;
            $existing->superseded_by_id = $newRow->id;
            $existing->save();

            Log::info('DrawingService: prior drawing superseded', [
                'archived_drawing_id' => $existing->id,
                'superseded_by_id'    => $newRow->id,
            ]);
        }
    }
    ```

    **`app/Jobs/BuildSchematicJob.php`** — full handle() + failed() (Blocker 2):

    Mirror `BuildOmManualJob` exactly. Constructor takes `int $drawingId`. `$tries=2`, `$timeout=300`. The Phase 17 Plan 01 `handle()` body is a SKELETON that writes a placeholder SVG and dispatches the completion email — Plan 02 replaces the SVG-writing section with the real D2 invocation (the surrounding mail dispatch + idempotency stays identical). Full code-level body (executor implements verbatim):

    ```php
    <?php

    namespace App\Jobs;

    use App\Mail\DocumentGenerationFailedMail;
    use App\Mail\DrawingReadyMail;
    use App\Models\ProjectDrawing;
    use App\Services\NotificationRecipientResolver;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Str;

    /**
     * Async schematic generation job.
     *
     * Plan 17-01 placeholder: writes a stub SVG so Plans 02/03 can be planned and
     * tested end-to-end. Plan 17-02 Task 2 REPLACES the placeholder body with the
     * real D2-driven SchematicGeneratorService call. The mail dispatch and
     * failed() hook below stay UNCHANGED across that handover.
     *
     * Status transitions:
     *   draft|generating → ready    (success)
     *   draft|generating → failed   (any exception, timeout, or retry exhaustion)
     *
     * Idempotency:
     *   completion_email_sent_at — set BEFORE send (NOTF-01 / D-14 pattern from BuildOmManualJob)
     *   failed_email_sent_at     — set BEFORE send (NOTF-04)
     */
    class BuildSchematicJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public int $tries   = 2;
        public int $timeout = 300;

        public function __construct(public readonly int $drawingId) {}

        public function handle(): void
        {
            $drawing = ProjectDrawing::find($this->drawingId);

            if (! $drawing) {
                Log::warning('BuildSchematicJob: record not found, discarding', [
                    'drawing_id' => $this->drawingId,
                ]);
                return;
            }

            if ($drawing->status !== ProjectDrawing::STATUS_GENERATING) {
                $drawing->update(['status' => ProjectDrawing::STATUS_GENERATING]);
            }

            Log::info('BuildSchematicJob: starting', [
                'drawing_id' => $this->drawingId,
                'attempt'    => $this->attempts(),
                'kind'       => $drawing->kind,
            ]);

            try {
                // ── Plan 17-01 placeholder body — Plan 17-02 REPLACES this block ──
                $placeholderSvg = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 200">'
                    . '<text x="20" y="100" font-family="sans-serif" font-size="14">'
                    . 'Phase 17 Plan 02 will implement schematic generation'
                    . '</text></svg>';

                $filename = sprintf(
                    'schematic-%d-v%d-%s.svg',
                    $drawing->id,
                    $drawing->version,
                    strtolower((string) Str::ulid()),
                );

                $drawing->update([
                    'generated_svg' => $placeholderSvg,
                    'filename'      => $filename,
                    'status'        => ProjectDrawing::STATUS_READY,
                    'error_message' => null,
                ]);
                // ── End Plan 17-01 placeholder body ──────────────────────────────

                Log::info('BuildSchematicJob: completed successfully', [
                    'drawing_id' => $this->drawingId,
                    'attempt'    => $this->attempts(),
                    'filename'   => $drawing->filename,
                    'status'     => 'ready',
                ]);

                // ── Idempotent completion email (NOTF-01) — copies BuildOmManualJob verbatim ──
                $drawing->refresh();
                if ($drawing->status === ProjectDrawing::STATUS_READY
                    && $drawing->completion_email_sent_at === null) {

                    // Set timestamp FIRST (D-14 idempotency) so a retry sees it set.
                    $drawing->update(['completion_email_sent_at' => now()]);

                    try {
                        $resolver  = app(NotificationRecipientResolver::class);
                        $recipient = $resolver->resolveProjectRecipient($drawing->project);
                        if ($recipient?->email) {
                            $pending = Mail::to($recipient->email);
                            $bcc = config('rams.notifications.bcc');
                            if (is_string($bcc) && trim($bcc) !== '') {
                                $pending->bcc(trim($bcc));
                            }
                            $pending->send(new DrawingReadyMail($drawing));
                            Log::info(
                                'BuildSchematicJob: completion email dispatched',
                                ['drawing_id' => $drawing->id, 'recipient' => $recipient->email]
                            );
                        }
                    } catch (\Throwable $mailErr) {
                        Log::warning(
                            'BuildSchematicJob: completion email send failed',
                            ['drawing_id' => $drawing->id, 'error' => $mailErr->getMessage()]
                        );
                        // Do NOT clear completion_email_sent_at — D-14.
                    }
                }
            } catch (\Throwable $e) {
                Log::error('BuildSchematicJob: failed', [
                    'drawing_id'      => $this->drawingId,
                    'attempt'         => $this->attempts(),
                    'exception_class' => get_class($e),
                    'error'           => $e->getMessage(),
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                ]);

                try {
                    $drawing->update([
                        'status'        => ProjectDrawing::STATUS_FAILED,
                        'error_message' => substr($e->getMessage(), 0, 500),
                    ]);
                } catch (\Throwable $dbErr) {
                    Log::critical('BuildSchematicJob: could not set failed status', [
                        'drawing_id' => $this->drawingId,
                        'db_error'   => $dbErr->getMessage(),
                    ]);
                }

                throw $e;
            }
        }

        public function failed(\Throwable $e): void
        {
            Log::error('BuildSchematicJob: all retries exhausted', [
                'drawing_id'      => $this->drawingId,
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
            ]);

            try {
                ProjectDrawing::find($this->drawingId)
                    ?->update([
                        'status'        => ProjectDrawing::STATUS_FAILED,
                        'error_message' => 'All retries exhausted: ' . substr($e->getMessage(), 0, 400),
                    ]);
            } catch (\Throwable $dbErr) {
                Log::critical('BuildSchematicJob::failed: could not set failed status', [
                    'drawing_id' => $this->drawingId,
                    'db_error'   => $dbErr->getMessage(),
                ]);
            }

            // NOTF-04 — admin failure alert (idempotent via failed_email_sent_at).
            $record = ProjectDrawing::find($this->drawingId);
            if ($record && $record->failed_email_sent_at === null) {
                $record->update(['failed_email_sent_at' => now()]);

                try {
                    $resolver = app(NotificationRecipientResolver::class);
                    $admins   = $resolver->resolveAdminRecipients();
                    $rawError     = (string) ($record->error_message ?? $e->getMessage() ?? '');
                    $errorMessage = $rawError !== '' ? substr($rawError, 0, 500) : null;
                    $bcc          = config('rams.notifications.bcc');

                    foreach ($admins as $admin) {
                        if (! $admin->email) {
                            continue;
                        }
                        $pending = Mail::to($admin->email);
                        if (is_string($bcc) && trim($bcc) !== '') {
                            $pending->bcc(trim($bcc));
                        }
                        $pending->send(new DocumentGenerationFailedMail(
                            documentType: ucfirst((string) $record->kind) . ' drawing',
                            projectRef:   (string) ($record->project->ref ?? ''),
                            projectName:  (string) ($record->project->name ?? ''),
                            errorMessage: $errorMessage,
                            detailUrl:    route('projects.drawings.show', [
                                'project' => $record->project_id,
                                'drawing' => $record->id,
                            ]),
                        ));
                    }
                } catch (\Throwable $mailErr) {
                    Log::warning(
                        'BuildSchematicJob: failure-alert email send failed',
                        ['drawing_id' => $this->drawingId, 'error' => $mailErr->getMessage()]
                    );
                }
            }
        }
    }
    ```

    Plan 17-02 Task 2 will: (a) inject `SchematicGeneratorService` into `handle()`, (b) replace the "Plan 17-01 placeholder body" section with `$generator->generate($drawing);`, and (c) leave the surrounding mail dispatch + failed() hook UNTOUCHED. The full code is here so Plan 02 doesn't have to re-derive it.

    **`app/Mail/DrawingReadyMail.php`:**

    Mirror `RamsReadyMail` shape. Constructor takes `public readonly ProjectDrawing $drawing`. `envelope()` subject branches on kind:
    - schematic → `"[{ref}] Schematic ready — {projectName}"`
    - rack → `"[{ref}] Rack elevation ready — {projectName}"`
    - floor_plan → `"[{ref}] Floor plan ready — {projectName}"`

    `content()` returns `view('emails.drawing-ready')`. `attachments()` reads via `DocumentArtifactStorage::readPath(TYPE_DRAWING, basename($drawing->filename))` — when present attaches with mime `application/pdf` or `image/svg+xml` based on extension (use `pathinfo($filename, PATHINFO_EXTENSION)`); when filename is empty or path null, return `[]`.

    **`resources/views/emails/drawing-ready.blade.php`:**

    Mirror `emails/rams-ready.blade.php` structure (read it first). Body says e.g. "The {{ $drawing->kindLabel() }} for {{ $drawing->project->name }} (revision {{ $drawing->revisionLabel() }}) is ready. The file is attached." Use `{{ $drawing->project->ref }}` in the heading. No new email infrastructure.

    **`app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php`:**

    Phase 17 SCAFFOLDING ONLY (DRAW-30 — functional schematic chat lands in Phase 19 alongside the editor). Implements `DocumentEditAdapterInterface` with:
    - `documentType()` returns `'drawing'`.
    - `loadPayload(int $documentId)` finds `ProjectDrawing::find($documentId)`; returns `null` if not found; otherwise `['kind' => $d->kind, 'status' => $d->status, 'version' => $d->version, 'has_canvas_state' => !empty($d->canvas_state), 'project_ref' => (string)($d->project->ref ?? '')]`.
    - `allowedOperations()` returns a fixed enum of layout-only operations: `['set_status', 'set_revision_note', 'add_layout_hint']`. (NO operations that mutate equipment, cables, or invent design.)
    - `operationSchemas()` returns:
      ```php
      [
          'set_status' => [
              'args' => ['value' => 'One of: draft | for_review | approved'],
              'notes' => 'Cannot set superseded — only the regenerate flow does that. Cannot set generating/ready/failed — those are job-controlled.',
          ],
          'set_revision_note' => [
              'args' => ['text' => 'string — short revision note (≤200 chars)'],
              'notes' => 'Stored on the drawing for audit (DRAW-24). Does not modify the SVG or canvas_state.',
          ],
          'add_layout_hint' => [
              'args' => ['hint' => 'string — short layout suggestion (e.g. "group audio chain top, video bottom")'],
              'notes' => 'Phase 17 SCAFFOLDING ONLY — actual schematic editor lands in Phase 19. The hint is recorded but does not yet alter generation. AI may NEVER add equipment or cables; layout-only.',
          ],
      ]
      ```
    - `applyOperation()` validates op against allowedOperations; for `set_status` enforces the allow-list `[STATUS_DRAFT, STATUS_FOR_REVIEW, STATUS_APPROVED]`; rejects with `ok=false, code='invalid_op'` if outside.
    - `summariseDiff()` returns `['status_changed' => $before['status'] !== $after['status']]`.
    - `commitChanges(int $id, array $payload): ?string` — finds drawing, applies status update if present, persists. Returns null (artifact regen deferred — Phase 19 wires functional regen).

    Header docblock states: "Phase 17 scaffolding — full schematic chat ships in Phase 19 alongside the Konva editor (per CONTEXT.md GAP-4 deferral). Layout-only operations; AI MAY NOT invent equipment/cables/rooms (REQUIREMENTS.md DRAW-30 + AI usage constraint)."

    **Update `app/Services/DocumentEdits/DocumentEditAdapterRegistry.php`:**

    Import `App\Services\DocumentEdits\Adapters\DrawingEditAdapter` and add `'drawing' => DrawingEditAdapter::class,` to `DEFAULT_MAP` after `'cable' => CableEditAdapter::class,`.

    **Update `app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php`:**

    Add a new arm to the `match` in `safeSnapshot()`: `'drawing' => $this->drawingSnapshot($payload),`. Add a new private method:
    ```php
    private function drawingSnapshot(array $p): array {
        return [
            'project_ref'      => (string) ($p['project_ref'] ?? ''),
            'kind'             => (string) ($p['kind'] ?? ''),
            'status'           => (string) ($p['status'] ?? ''),
            'version'          => (int) ($p['version'] ?? 0),
            'has_canvas_state' => (bool) ($p['has_canvas_state'] ?? false),
        ];
    }
    ```

    **`app/Http/Controllers/ProjectDrawingController.php` — shell only:**

    Thin controller with three methods (Plan 03 fills `show`/`download` bodies):
    - `index(Project $project)` — returns `view('projects.drawings.index', ['project' => $project, 'drawings' => $project->drawings()->whereNull('superseded_by_id')->orderBy('kind')->orderBy('version', 'desc')->get()])`. (Plan 03 creates the Blade view; Plan 01 leaves the route wired but the view file may not exist yet — note in PHPDoc.)
    - `regenerate(Request $request, Project $project, ProjectDrawing $drawing)` — `$this->authorize('update', $drawing); app(\App\Services\Drawings\DrawingService::class)->regenerate($drawing, $request->user()->id); return redirect()->route('projects.drawings.index', $project)->with('status', 'Schematic regeneration queued.');`
    - `show(Project $project, ProjectDrawing $drawing)` — `$this->authorize('view', $drawing); return response()->json(['id' => $drawing->id, 'kind' => $drawing->kind, 'status' => $drawing->status, 'version' => $drawing->version, 'filename' => $drawing->filename]);` (Plan 03 replaces with full preview view.)

    Use `AuthorizesRequests` trait. Constructor injects `DrawingService`.

    **Update `routes/web.php`:**

    Inside the existing `Route::middleware('auth')->group(function () { ... })` block (next to existing `om-manuals` routes), add:
    ```php
    // ── v1.3 Phase 17 — Drawings (foundations) ────────────────────────────────
    Route::get('projects/{project}/drawings', [\App\Http\Controllers\ProjectDrawingController::class, 'index'])
        ->name('projects.drawings.index');
    Route::get('projects/{project}/drawings/{drawing}', [\App\Http\Controllers\ProjectDrawingController::class, 'show'])
        ->name('projects.drawings.show');
    Route::post('projects/{project}/drawings/{drawing}/regenerate', [\App\Http\Controllers\ProjectDrawingController::class, 'regenerate'])
        ->name('projects.drawings.regenerate');
    ```

    Plan 02 adds the schematic generation trigger; Plan 03 adds list/preview view + per-format download routes.
  </action>
  <acceptance_criteria>
    - `app/Services/Drawings/DrawingDataResolverService.php` exists with `adjacencyForProject()`, `rackStackForProject()`, `floorPlanGlyphsForRoom()` methods. `grep -n "throw new \\\\RuntimeException.*Phase 18\|Phase 19" app/Services/Drawings/DrawingDataResolverService.php` shows both stub throws.
    - **Warning 5 verification — adjacencyForProject method exists at Plan 01 boundary:** `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\Drawings\DrawingDataResolverService::class, 'adjacencyForProject') ? 'PASS' : 'FAIL';"` returns `PASS`. (Catches typos in the stub method name before Plan 02 tries to fill the body.)
    - `app/Services/Drawings/DrawingService.php` exists; `grep -n "createForProject\|generateInitial\|regenerate\|archivePrior\|DB::transaction\|superseded_by_id\|BuildSchematicJob::dispatch\|STATUS_GENERATING\|STATUS_SUPERSEDED" app/Services/Drawings/DrawingService.php` shows all nine tokens.
    - **Warning 9 verification — generateInitial exists:** `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\Drawings\DrawingService::class, 'generateInitial') ? 'PASS' : 'FAIL';"` returns `PASS`.
    - `app/Jobs/BuildSchematicJob.php` exists; `grep -n "tries.*2\|timeout.*300\|completion_email_sent_at\|failed_email_sent_at\|DrawingReadyMail\|DocumentGenerationFailedMail\|NotificationRecipientResolver\|rams.notifications.bcc\|resolveProjectRecipient\|resolveAdminRecipients" app/Jobs/BuildSchematicJob.php` shows all ten tokens (full mail dispatch, not prose).
    - `app/Mail/DrawingReadyMail.php` exists; `grep -n "Schematic ready\|Rack elevation ready\|Floor plan ready" app/Mail/DrawingReadyMail.php` shows all three subject branches.
    - `resources/views/emails/drawing-ready.blade.php` exists.
    - `app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php` exists; `grep -n "documentType\|drawing\|set_status\|set_revision_note\|add_layout_hint\|operationSchemas" app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php` shows all six tokens.
    - `grep -n "DrawingEditAdapter\|'drawing'" app/Services/DocumentEdits/DocumentEditAdapterRegistry.php` shows the mapping added.
    - `grep -n "drawingSnapshot\|'drawing' =>" app/Services/DocumentEdits/Prompts/DocumentEditParsingPromptFactory.php` shows safeSnapshot arm + private method added.
    - `app/Http/Controllers/ProjectDrawingController.php` exists with `index`, `show`, `regenerate` methods.
    - `php artisan route:list --name=drawings` lists three routes (`projects.drawings.index`, `projects.drawings.show`, `projects.drawings.regenerate`).
    - `php artisan tinker --execute="echo app(App\Services\DocumentEdits\DocumentEditAdapterRegistry::class)->for('drawing')->documentType();"` returns `drawing` (no exception).
    - No PHP syntax errors: `php -l app/Models/ProjectDrawing.php app/Policies/ProjectDrawingPolicy.php app/Services/Drawings/DrawingService.php app/Services/Drawings/DrawingDataResolverService.php app/Jobs/BuildSchematicJob.php app/Mail/DrawingReadyMail.php app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php app/Http/Controllers/ProjectDrawingController.php` reports `No syntax errors detected` for every file.
  </acceptance_criteria>
  <verify>
    <automated>php artisan route:list --name=drawings 2>&1 | grep -E "drawings.index|drawings.show|drawings.regenerate" | wc -l | grep -q "^3$" && echo PASS || echo FAIL</automated>
  </verify>
  <done>All foundation services, job, mail, edit-adapter, controller shell, and routes wired and registered. DrawingService has the four methods (createForProject/generateInitial/regenerate/archivePrior); BuildSchematicJob has the full handle() + failed() bodies (no prose, no TODO); Plans 02 and 03 can plug into stable interfaces.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Browser → ProjectDrawingController | Authenticated form/AJAX requests crossing into `index/show/regenerate` |
| Adapter → DrawingService::regenerate | DocumentEdits chat input fans into the regenerate flow only via explicit op (Phase 19); Phase 17 has no direct path |
| File system → DocumentArtifactStorage | All drawing artifact writes/reads must go through writePath/readPath (no direct path construction) |
| Schedule → BuildSchematicJob (queue) | Job receives drawingId integer; loads its own model; no cross-tenant data |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17.01-01 | T (Tampering) | ProjectDrawingController::regenerate | mitigate | `$this->authorize('update', $drawing)` via ProjectDrawingPolicy enforces owner-or-admin (mirrors RamsDocumentPolicy). CSRF middleware applies (POST under web group). |
| T-17.01-02 | I (Information disclosure) | ProjectDrawingController::show | mitigate | `$this->authorize('view', $drawing)` enforced; route-model binding uses implicit `{drawing}` so cross-project access is impossible without policy pass. |
| T-17.01-03 | E (Elevation of privilege) | DrawingEditAdapter::applyOperation | mitigate | allowedOperations() is a fixed enum (`set_status`, `set_revision_note`, `add_layout_hint`); set_status further restricts to `[draft, for_review, approved]` — cannot leap to superseded/ready/failed. operationSchemas() expose argument shapes so the AI cannot inject unknown fields. |
| T-17.01-04 | I (Information disclosure) | DocumentEditParsingPromptFactory::drawingSnapshot | mitigate | Snapshot exposes only project_ref, kind, status, version, has_canvas_state — no equipment lists, no PII, no cross-project ids. Mirrors the conservative approach already taken for RAMS/O&M snapshots. |
| T-17.01-05 | T (Tampering) | DocumentArtifactStorage filename handling | mitigate | DocumentArtifactStorage::writePath asserts the type constant via `assertType()`; filenames composed by the service follow the convention `drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}` — no user-controlled filename component, eliminating path traversal. ULID is cryptographically generated. |
| T-17.01-06 | D (Denial of service) | BuildSchematicJob | mitigate | `$tries=2` + `$timeout=300` cap blast radius; CRIT-03 mitigations (`--disable-dev-shm-usage`, dedicated queue) land in Phase 20 — Phase 17 placeholder body is non-Browsershot so DoS surface is negligible at this stage. |
| T-17.01-07 | I (Information disclosure) | access_token column | accept | Column added but NOT exposed via routes in v1.3. v1.4 client portal will add token-gated routes; until then access_token is read-only forward-compat. |
| T-17.01-08 | T (Tampering) | DrawingService::regenerate | mitigate | Wrapped in `DB::transaction()`; new row created BEFORE old row's superseded_by_id is set → rollback on failure leaves old row intact. Mirrors InstallProgrammeService archive-prior pattern. Job dispatched only AFTER commit so the queue worker never sees a phantom row. |
| T-17.01-09 | T (Tampering) | BuildSchematicJob.php co-edited by Plan 02 + Plan 03 (Warning 6) | mitigate | Plan 17-03 frontmatter `depends_on: ["17-01", "17-02"]` forces sequential execution; Plan 02 replaces the placeholder SVG-writing block in `handle()`, Plan 03 inserts the thumbnail render in the success branch BEFORE the completion email. Disjoint regions but sequential to avoid merge conflicts. |
</threat_model>

<verification>
1. `php artisan migrate --pretend` shows both migrations cleanly.
2. After actual migrate: `php artisan tinker` confirms `Schema::hasTable('project_drawings')` and `Schema::hasColumn('devices', 'signal_role')` AND `Schema::hasColumn('devices', 'part_no')` (Blocker 4) all return true.
3. `php artisan tinker --execute="\$s = app(App\Services\DocumentArtifactStorage::class); echo in_array('drawings', \$s->types(), true) ? 'PASS' : 'FAIL';"` returns `PASS`.
4. `php artisan route:list --name=drawings` returns the three routes (`index`, `show`, `regenerate`).
5. `php artisan tinker --execute="echo app(App\Services\DocumentEdits\DocumentEditAdapterRegistry::class)->for('drawing')->documentType();"` returns `drawing`.
6. **Warning 5** — `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\Drawings\DrawingDataResolverService::class, 'adjacencyForProject') ? 'PASS' : 'FAIL';"` returns `PASS`.
7. **Warning 9** — `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\Drawings\DrawingService::class, 'generateInitial') ? 'PASS' : 'FAIL';"` returns `PASS`.
8. **Warning 8** — `php -r "require 'vendor/autoload.php'; echo method_exists(\App\Services\PdfRenderService::class, 'fromBladeAsPng') ? 'PASS' : 'FAIL';"` returns `PASS`.
9. `php -l` on every new/modified PHP file reports `No syntax errors detected`.
10. Existing RAMS / O&M / Site Survey PDF generation runs without regression — `php artisan pdf:smoke-test` (RAMS smoke baseline from 260427-qvr) still produces a valid PDF.
11. `grep -rn "TYPE_DRAWING" app/` shows TYPE_DRAWING used only in DocumentArtifactStorage (constant declaration) and any test fixtures — no other unexpected callers in Phase 17 (Plans 02/03 will add more).
</verification>

<success_criteria>
- All 13 must_haves above are observable.
- Plan 02 (Schematic Generator) can be planned/executed against the interfaces created here without needing to revisit Plan 01.
- Plan 03 (PDF render + UI + status state machine) can be planned/executed against the interfaces created here.
- Phases 18 (Rack), 19 (Floor Plans), 20 (Export + O&M) are now pure additions — table, model, policy, storage type, job pattern, mailable, edit-adapter, waitForJs flag, and `fromBladeAsPng()` are all present.
- DRAW-24 (revision tracking via version + superseded_by_id), DRAW-25 (status enum draft/for_review/approved/superseded — model constants exist), and DRAW-30 scaffolding only (DrawingEditAdapter registered, layout-only operation enum) are foundationally landed (functional UI for DRAW-25 lives in Plan 03; functional schematic chat for DRAW-30 lives in Phase 19).
</success_criteria>

<output>
After completion, create `.planning/phases/17-system-schematics-shared-foundations/17-01-SUMMARY.md` documenting:
- Schema columns landed (project_drawings + devices.signal_role).
- New constants (TYPE_DRAWING, all STATUS_* / KIND_*).
- Files created/modified count.
- DrawingService method matrix (createForProject / generateInitial / regenerate / archivePrior — and which controller call sites in Plan 03 use which).
- New PdfRenderService::fromBladeAsPng API + which downstream renderers consume it.
- Any deviations from plan with rationale.
- Pointers to Plan 02 (D2 + symbol pack + signal flow) and Plan 03 (PDF render + UI + O&M wiring).
</output>
</output>

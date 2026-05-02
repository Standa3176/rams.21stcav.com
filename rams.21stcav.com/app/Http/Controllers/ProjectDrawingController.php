<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\DocumentEdits\DocumentEditAdapterRegistry;
use App\Services\Drawings\DrawingDataResolverService;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\Drawings\DrawingService;
use App\Services\Drawings\RackElevationRenderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v1.3 / Phase 17 — drawings list / regenerate / show / download / create /
 * update-status.
 *
 * Plan 17-01 landed the routes + regenerate flow.
 * Plan 17-03 fills index/show Blade views, adds createSchematic +
 * updateStatus + per-format download endpoints. createSchematic uses
 * DrawingService::generateInitial (NOT regenerate) so the first version is
 * R0 and not R1-with-phantom-archived-sibling (Warning 9 fix). updateStatus
 * routes through the DrawingEditAdapter so the same set_status allow-list
 * applies to chat-driven and UI-driven edits.
 *
 * Authorization: ProjectDrawingPolicy (owner-or-admin) is enforced on
 * regenerate(), show(), updateStatus(), download(). The index page is
 * project-scoped (a future-Phase-21 client portal policy will live there).
 */
class ProjectDrawingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DrawingService $drawingService,
    ) {}

    /**
     * List drawings for a project — current revisions only (filtered by
     * superseded_by_id IS NULL). Plan 03 creates the Blade view; until then
     * the view file may not exist and the route will 500 on render — that's
     * expected at Plan 01 boundary.
     */
    public function index(Project $project): View
    {
        $drawings = $project->drawings()
            ->whereNull('superseded_by_id')
            ->orderBy('kind')
            ->orderBy('version', 'desc')
            ->get();

        return view('projects.drawings.index', [
            'project' => $project,
            'drawings' => $drawings,
        ]);
    }

    /**
     * Per-drawing preview page — embedded SVG + status controls + per-format
     * download links + regenerate button. Plan 03 replaces Plan 01's JSON
     * stub now that the Blade view exists.
     */
    public function show(Project $project, ProjectDrawing $drawing): View
    {
        $this->authorize('view', $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404);
        }

        return view('projects.drawings.show', [
            'project' => $project,
            'drawing' => $drawing,
        ]);
    }

    /**
     * Create + dispatch a v1 schematic (DRAW-06). Warning 9 fix: calls
     * createForProject() THEN generateInitial() (NOT regenerate) — regenerate
     * archives the just-created row and replicates a fresh one, which would
     * waste a row, break revisioning, and produce a misleading "R1" instead
     * of "R0" for the first version.
     */
    public function createSchematic(Request $request, Project $project): RedirectResponse
    {
        if (! $request->user()) {
            abort(403);
        }

        $userId = (int) $request->user()->id;

        // Step 1: create the row (status=DRAFT, no job dispatched).
        $drawing = $this->drawingService->createForProject(
            $project,
            ProjectDrawing::KIND_SCHEMATIC,
            null, // no specific room — Phase 17 v1 generates per-project schematic
            $userId,
        );

        // Step 2: dispatch the build job WITHOUT archive-prior semantics
        // (this is the FIRST version — there's nothing to archive). Calling
        // regenerate() here would archive the just-created row and replicate
        // a fresh one — Warning 9.
        $this->drawingService->generateInitial($drawing, $userId);

        return redirect()
            ->route('projects.drawings.index', $project)
            ->with('status', 'Schematic generation queued.');
    }

    /**
     * Phase 18 — create an empty rack drawing. SYNCHRONOUS flow: no job
     * dispatched. Engineer always builds the rack manually in Plan 18-03's
     * editor (CONTEXT.md "NO auto-place flow"). Redirects to the rack show
     * page for immediate building.
     *
     * Rack label auto-increments per project (Rack 1, Rack 2, ...). Counts
     * only non-superseded rack drawings so re-using a number after archive
     * isn't possible.
     */
    public function createRack(Request $request, Project $project): RedirectResponse
    {
        if (! $request->user()) {
            abort(403);
        }

        $userId = (int) $request->user()->id;

        $existingRackCount = $project->drawings()
            ->where('kind', ProjectDrawing::KIND_RACK)
            ->whereNull('superseded_by_id')
            ->count();

        $defaultLabel = 'Rack '.($existingRackCount + 1);
        $rackLabel = trim((string) $request->input('rack_label', '')) ?: $defaultLabel;

        // Step 1: create the row (status=DRAFT, no job dispatched).
        $drawing = $this->drawingService->createForProject(
            $project,
            ProjectDrawing::KIND_RACK,
            null, // no specific room — rack is project-level
            $userId,
        );

        // Step 2: stamp the rack_label BEFORE generateInitial so
        // generateInitialRack() can read it into source_data.rack_meta.
        $drawing->update(['rack_label' => $rackLabel]);

        // Step 3: seed the rack scaffold synchronously. NO job dispatched —
        // CONTEXT.md "NO BuildRackElevationJob — rack rendering is synchronous
        // in Plan 18-03's editor."
        $this->drawingService->generateInitial($drawing, $userId);

        return redirect()
            ->route('projects.drawings.show', [$project, $drawing])
            ->with('status', "Rack '{$rackLabel}' created — drag equipment from the palette to build it.");
    }

    /**
     * Phase 18 — unified "+ Create Drawing" picker. Single endpoint that
     * dispatches to the kind-specific create action. Floor plans land in
     * v2.0 (Phase 19 deferred from v1.3 scope 2026-05-02) — POSTing
     * kind=floor_plan returns back() with a session 'kind' validation error
     * so the picker modal surfaces it gracefully.
     */
    public function picker(Request $request, Project $project): RedirectResponse
    {
        $kind = (string) $request->input('kind', '');

        return match ($kind) {
            ProjectDrawing::KIND_SCHEMATIC => $this->createSchematic($request, $project),
            ProjectDrawing::KIND_RACK => $this->createRack($request, $project),
            ProjectDrawing::KIND_FLOOR_PLAN => back()->withErrors([
                'kind' => 'Floor plans land in v2.0 — coming soon.',
            ]),
            default => back()->withErrors([
                'kind' => 'Unknown drawing kind.',
            ]),
        };
    }

    /**
     * DRAW-25 — workflow status update. Routed through the DrawingEditAdapter's
     * `set_status` operation so the same allow-list (draft/for_review/approved)
     * applied to chat-driven edits applies here too. Generating/ready/failed
     * are job-controlled; superseded is regenerate-controlled — neither
     * surfaces in the UI dropdown.
     */
    public function updateStatus(
        Request $request,
        Project $project,
        ProjectDrawing $drawing,
    ): RedirectResponse {
        $this->authorize('update', $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404);
        }

        $registry = app(DocumentEditAdapterRegistry::class);
        $adapter = $registry->for('drawing');

        $payload = $adapter->loadPayload($drawing->id);
        if ($payload === null) {
            abort(404);
        }

        $result = $adapter->applyOperation($payload, [
            'op' => 'set_status',
            'value' => (string) $request->input('status', ''),
        ]);

        if (($result['ok'] ?? false) === true) {
            $adapter->commitChanges($drawing->id, $result['payload'] ?? []);

            return redirect()
                ->route('projects.drawings.show', [$project, $drawing])
                ->with('status', 'Status updated.');
        }

        return back()->withErrors([
            'status' => $result['error'] ?? 'Status update rejected',
        ]);
    }

    /**
     * Regenerate an existing drawing. Delegates to DrawingService::regenerate
     * which runs inside DB::transaction (replicates row, bumps version,
     * archives prior, dispatches BuildSchematicJob).
     */
    public function regenerate(
        Request $request,
        Project $project,
        ProjectDrawing $drawing,
    ): RedirectResponse {
        $this->authorize('update', $drawing);

        $this->drawingService->regenerate($drawing, (int) $request->user()->id);

        return redirect()
            ->route('projects.drawings.index', $project)
            ->with('status', 'Drawing regeneration queued.');
    }

    /**
     * Per-format download endpoint (DRAW-27). Format whitelist enforced —
     * cannot smuggle 'php' / 'exe' or other suffixes. Cross-project access
     * blocked by project_id match check (T-17.03-03).
     */
    public function download(
        Project $project,
        ProjectDrawing $drawing,
        string $format,
    ): BinaryFileResponse {
        $this->authorize('view', $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404);
        }
        if (! in_array($format, ['pdf', 'svg', 'png'], true)) {
            abort(404);
        }

        $renderer = app(DrawingExportRendererService::class);
        $path = match ($format) {
            'pdf' => $renderer->renderPdf($drawing),
            'svg' => $renderer->renderSvg($drawing),
            'png' => $renderer->renderPng($drawing),
        };

        $filename = sprintf(
            '%s-%s-%s.%s',
            $drawing->kind,
            $project->ref ?? (string) $project->id,
            $drawing->revisionLabel(),
            $format,
        );

        return response()->download($path, $filename);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Phase 18 Plan 03 — rack editor + AJAX save + flip-rack-mounted endpoint
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Phase 18 — rack editor page. Engineer drags equipment from a palette
     * into U-slots; saves via AJAX. Synchronous render runs server-side on
     * each save (no BuildRackElevationJob — CONTEXT.md "rack rendering is
     * synchronous in Plan 18-03's editor").
     *
     * Route binds {drawing} via Eloquent. Policy gate enforces owner OR admin.
     * Cross-project URL tampering blocked by project_id match check.
     */
    public function editRack(
        Project $project,
        ProjectDrawing $drawing,
        DrawingDataResolverService $resolver,
    ): View {
        $this->authorize('update', $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404);
        }
        if (! $drawing->isRack()) {
            abort(404, 'editRack only handles kind=rack drawings.');
        }

        $rackStack = $resolver->rackStackForProject($project);

        // Group palette: is_rack_mounted=true first, then null/false (greyed
        // but draggable). Mirrors the existing rackStackForProject ordering
        // — kept explicit here so the palette template can iterate two arrays.
        $rackMounted = [];
        $other = [];
        foreach ($rackStack['palette'] as $row) {
            if (($row['is_rack_mounted'] ?? null) === true) {
                $rackMounted[] = $row;
            } else {
                $other[] = $row;
            }
        }

        return view('projects.drawings.rack-edit', [
            'project' => $project,
            'drawing' => $drawing,
            'palette_rack_mounted' => $rackMounted,
            'palette_other' => $other,
        ]);
    }

    /**
     * Phase 18 — AJAX save endpoint. Validates rack_items JSON shape, persists
     * to source_data, runs RackElevationRenderService synchronously, flips
     * status to READY. Failures persist status=failed + error_message and
     * return 500 with the error string.
     *
     * Threat model T-18.03-01: validate rack_items as a typed list — no
     * arbitrary keys, integer u_position, decimal u_height, string equipment
     * id/name/part_no. Reject anything else BEFORE writing.
     */
    public function saveRackCanvas(
        Request $request,
        Project $project,
        ProjectDrawing $drawing,
        RackElevationRenderService $renderer,
    ): JsonResponse {
        $this->authorize('update', $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404);
        }
        if (! $drawing->isRack()) {
            abort(422, 'saveRackCanvas only handles kind=rack drawings.');
        }

        $validated = $request->validate([
            'rack_meta' => ['required', 'array'],
            'rack_meta.rack_label' => ['required', 'string', 'max:120'],
            'rack_meta.rack_height_u' => ['required', 'integer', 'min:1', 'max:99'],
            'rack_meta.nominal_voltage_v' => ['nullable', 'integer', 'min:100', 'max:480'],
            'rack_meta.floor' => ['nullable', 'string', 'max:60'],
            'rack_items' => ['array'],
            'rack_items.*.equipment_id' => ['required', 'string', 'max:120'],
            'rack_items.*.name' => ['required', 'string', 'max:200'],
            'rack_items.*.part_no' => ['nullable', 'string', 'max:120'],
            'rack_items.*.u_position' => ['required', 'integer', 'min:1', 'max:99'],
            'rack_items.*.u_height' => ['nullable', 'numeric', 'min:0.5', 'max:42'],
            'rack_items.*.locked' => ['boolean'],
            'rack_items.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'rack_items.*.current_draw_a' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rack_items.*.btu_per_hour' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ]);

        $drawing->source_data = array_merge(
            (array) ($drawing->source_data ?? []),
            [
                'rack_meta' => $validated['rack_meta'],
                'rack_items' => $validated['rack_items'] ?? [],
            ],
        );
        $drawing->rack_label = $validated['rack_meta']['rack_label'];

        try {
            $svg = $renderer->render($drawing);
            $drawing->generated_svg = $svg;
            $drawing->status = ProjectDrawing::STATUS_READY;
            $drawing->error_message = null;
        } catch (\Throwable $e) {
            $drawing->status = ProjectDrawing::STATUS_FAILED;
            $drawing->error_message = $e->getMessage();
            Log::error('ProjectDrawingController: rack render failed', [
                'drawing_id' => $drawing->id,
                'error' => $e->getMessage(),
            ]);
            $drawing->save();

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        $drawing->save();

        return response()->json([
            'ok' => true,
            'drawing_id' => $drawing->id,
            'status' => $drawing->status,
            'updated_at' => $drawing->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Phase 18 — engineer marks (or unmarks) an equipment item as rack-mounted
     * from the palette OR from the project-package review page. CONTEXT.md:
     * "engineer-set via a checkbox in the palette OR the project-package review
     * page — NO automatic classification."
     *
     * Authorisation: owner OR admin on the PROJECT (not on any specific
     * drawing). The Device-row mutation is conceptually project-owned. This
     * endpoint must remain reachable BEFORE the engineer creates their first
     * rack drawing — otherwise the palette-on-empty-rack flow would 404 and
     * the project-package-review checkbox would have nowhere to POST.
     *
     * Bound parameter on the SQL — `whereRaw('LOWER(TRIM(part_no)) = ?',
     * [$normalised])` (T-18.03-07).
     */
    public function flipRackMountedFlag(
        Request $request,
        Project $project,
    ): JsonResponse {
        $this->authorize('update', $project);

        $partNo = (string) $request->input('part_no', '');
        $isRackMounted = (bool) $request->input('is_rack_mounted', false);

        if (trim($partNo) === '') {
            return response()->json(['ok' => false, 'error' => 'part_no required'], 422);
        }

        $updated = \App\Models\Device::query()
            ->where('project_id', $project->id)
            ->whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])
            ->update(['is_rack_mounted' => $isRackMounted]);

        Log::info('ProjectDrawingController: flipped is_rack_mounted', [
            'project_id' => $project->id,
            'part_no' => $partNo,
            'is_rack_mounted' => $isRackMounted,
            'rows_updated' => $updated,
        ]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }
}

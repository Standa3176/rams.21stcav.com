<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\DocumentEdits\DocumentEditAdapterRegistry;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\Drawings\DrawingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}

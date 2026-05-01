<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\Drawings\DrawingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v1.3 / Phase 17 — drawings list / regenerate / show shell.
 *
 * Plan 17-01 lands the routes + the regenerate flow so Plan 02 can dispatch
 * BuildSchematicJob via this controller. Plan 03 fills the index Blade view
 * + per-format download endpoints; until then index() returns the view name
 * the frontend will eventually point at, but the resources/views/projects/
 * drawings/index.blade.php file is created by Plan 03.
 *
 * Authorization: ProjectDrawingPolicy (owner-or-admin) is enforced on
 * regenerate() and show(). The index page is project-scoped, so a
 * future-Phase-21 (client portal) policy will live there.
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
     * JSON status endpoint — used by Plan 03's polling UI to update the
     * status badge after regenerate. Plan 03 may replace this with a full
     * preview view; for Plan 01 / 02 boundaries we return JSON-only so the
     * route is testable end-to-end without a view file.
     */
    public function show(Project $project, ProjectDrawing $drawing): JsonResponse
    {
        $this->authorize('view', $drawing);

        return response()->json([
            'id' => $drawing->id,
            'kind' => $drawing->kind,
            'status' => $drawing->status,
            'version' => $drawing->version,
            'filename' => $drawing->filename,
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

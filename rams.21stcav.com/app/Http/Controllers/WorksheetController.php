<?php

namespace App\Http\Controllers;

use App\Jobs\BuildWorksheetJob;
use App\Models\Project;
use App\Models\Worksheet;
use App\Services\WorkerMonitorService;
use App\Services\WorksheetDocxService;
use App\Services\WorksheetGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * WorksheetController — manages the Worksheet document pipeline.
 *
 * Generation flow:
 *   POST generateFromProject → creates Worksheet record (status=generating) →
 *   dispatches BuildWorksheetJob → returns redirect back with success flash.
 *
 * Status polling:
 *   GET status → JSON { status, download_url } — used by Alpine.js poller on
 *   the project show page.
 *
 * Auth pattern:
 *   abort_if ownership check on every action — same guard as OmManualController.
 *
 * @see BuildWorksheetJob          — async pipeline handler
 * @see WorksheetGeneratorService  — per-room content generator
 * @see WorksheetDocxService       — DOCX builder
 */
class WorksheetController extends Controller
{
    public function __construct(
        private readonly WorksheetGeneratorService $generator,
        private readonly WorksheetDocxService      $docxService,
        private readonly WorkerMonitorService      $workerMonitor,
    ) {}

    // =========================================================================
    // INDEX
    // =========================================================================

    /**
     * List all worksheets for the authenticated user (admins see all).
     *
     * @param  Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $isAdmin = auth()->user()->isAdmin();

        $worksheets = $isAdmin
            ? Worksheet::with('project')->latest()->paginate(15)
            : auth()->user()->worksheets()->with('project')->latest()->paginate(15);

        return view('worksheets.index', compact('worksheets', 'isAdmin'));
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    /**
     * Display worksheet details with room accordion.
     *
     * @param  Worksheet $worksheet
     * @return View
     */
    public function show(Worksheet $worksheet): View
    {
        abort_if(
            $worksheet->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $worksheet->load('project');

        return view('worksheets.show', compact('worksheet'));
    }

    // =========================================================================
    // GENERATE FROM PROJECT
    // =========================================================================

    /**
     * Create a new Worksheet record and dispatch the async generation job.
     *
     * @param  Project $project
     * @return RedirectResponse
     */
    public function generateFromProject(Project $project): RedirectResponse
    {
        abort_if(
            $project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $worksheet = Worksheet::create([
            'user_id'      => auth()->id(),
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'project_ref'  => $project->ref ?? $project->quote_reference ?? null,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
            'status'       => Worksheet::STATUS_GENERATING,
        ]);

        $this->workerMonitor->ensureRunning();

        BuildWorksheetJob::dispatch($worksheet->id);

        return back()->with('success', 'Worksheet generation queued.');
    }

    // =========================================================================
    // STATUS (JSON — Alpine.js polling endpoint)
    // =========================================================================

    /**
     * Return worksheet generation status as JSON for polling.
     *
     * Response shape:
     *   { "status": "generating", "download_url": null }
     *   { "status": "draft",      "download_url": "/worksheets/42/download" }
     *
     * @param  Worksheet $worksheet
     * @return JsonResponse
     */
    public function status(Worksheet $worksheet): JsonResponse
    {
        abort_if(
            $worksheet->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $downloadUrl = in_array($worksheet->status, [Worksheet::STATUS_DRAFT, Worksheet::STATUS_FINAL])
            ? route('worksheets.download', $worksheet)
            : null;

        return response()->json([
            'status'       => $worksheet->status,
            'download_url' => $downloadUrl,
        ]);
    }

    // =========================================================================
    // DOWNLOAD
    // =========================================================================

    /**
     * Stream the generated DOCX file to the browser.
     *
     * @param  Worksheet $worksheet
     * @return BinaryFileResponse
     */
    public function download(Worksheet $worksheet): BinaryFileResponse
    {
        abort_if(
            $worksheet->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        if (! $worksheet->filename) {
            abort(404, 'Worksheet DOCX not found. Try regenerating.');
        }

        // Post-H-07: new worksheets land in storage/app/documents/worksheets/.
        // readPath() falls back to legacy storage/app/private/worksheets/ so
        // already-generated files remain downloadable.
        $filePath = app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_WORKSHEET, basename($worksheet->filename));

        abort_if($filePath === null, 404, 'Worksheet DOCX not found. Try regenerating.');

        return response()->download($filePath, $worksheet->filename);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    /**
     * Soft-delete the worksheet record.
     *
     * @param  Worksheet $worksheet
     * @return RedirectResponse
     */
    /**
     * Retry / regenerate an existing worksheet.
     * Re-dispatches BuildWorksheetJob using the same project link.
     */
    public function retryGeneration(Worksheet $worksheet): RedirectResponse
    {
        abort_if(
            $worksheet->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        if ($worksheet->status === Worksheet::STATUS_GENERATING) {
            return back()->with('error', 'This worksheet is already being generated. Please wait.');
        }

        $worksheet->update([
            'status' => Worksheet::STATUS_GENERATING,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildWorksheetJob::dispatch($worksheet->id);

        Log::info('WorksheetController: regeneration queued', [
            'worksheet_id' => $worksheet->id,
            'user_id'      => auth()->id(),
        ]);

        return back()->with('success', 'Worksheet regeneration queued. The document will be ready to download shortly.');
    }

    public function destroy(Worksheet $worksheet): RedirectResponse
    {
        abort_if(
            $worksheet->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $worksheet->delete();

        Log::info('WorksheetController: worksheet soft-deleted', [
            'worksheet_id' => $worksheet->id,
            'user_id'      => auth()->id(),
        ]);

        return redirect()->route('worksheets.index')
            ->with('success', 'Worksheet deleted.');
    }
}

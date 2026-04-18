<?php

namespace App\Http\Controllers;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Exceptions\AIGenerationException;
use App\Jobs\BuildOmManualJob;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Services\OmManualDocxService;
use App\Services\PdfService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OmManualController extends Controller
{
    public function __construct(
        private readonly OmManualGeneratorService $generator,
        private readonly OmManualDocxService      $docxService,
        private readonly PdfService               $pdfService,
    ) {}

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');

        if ($showDeleted) {
            $manuals = OmManual::onlyTrashed()->with(['user', 'project'])->latest('deleted_at')->paginate(15);
            return view('om-manual.index', compact('manuals', 'isAdmin', 'showDeleted'));
        }

        $manuals = $isAdmin
            ? OmManual::with(['user', 'project'])->latest()->paginate(15)
            : auth()->user()->omManuals()->with('project')->latest()->paginate(15);

        return view('om-manual.index', compact('manuals', 'isAdmin', 'showDeleted'));
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function create(Request $request): View
    {
        $defaultProvider = config('ai.default');

        $projects = Project::forUser(auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'ref', 'client_name']);

        $selectedProjectId = $request->query('project_id');
        $selectedProject   = null;
        $latestPackage     = null;

        if ($selectedProjectId) {
            $selectedProject = Project::where('id', $selectedProjectId)
                ->where('user_id', auth()->id())
                ->with('latestPackage')
                ->first();
            $latestPackage = $selectedProject?->latestPackage;
        }

        return view('om-manual.create', compact('defaultProvider', 'projects', 'selectedProjectId', 'selectedProject', 'latestPackage'));
    }

    // ── store (Pass 1) ────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'quote_pdf'   => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'project_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'ai_provider' => ['nullable', 'string', 'in:claude,openai'],
        ]);

        $provider  = $request->input('ai_provider', config('ai.default', 'claude'));
        $projectId = $request->input('project_id');

        $project = $projectId
            ? Project::where('id', $projectId)->where('user_id', auth()->id())->firstOrFail()
            : null;

        // Move to a temp directory for extraction
        $tmpDir  = storage_path('app/tmp/om-uploads/' . uniqid('om_', true));
        $pdfPath = $tmpDir . '/quote.pdf';

        if (! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return back()->withInput()->with('error', 'Could not create temporary directory.');
        }

        $request->file('quote_pdf')->move($tmpDir, 'quote.pdf');

        try {
            $manual = $this->generator->extractFromPdf(
                user:         auth()->user(),
                pdfPath:      $pdfPath,
                originalName: $request->file('quote_pdf')->getClientOriginalName(),
                project:      $project,
                provider:     $provider,
            );
        } catch (AIGenerationException $e) {
            $this->cleanupTmp($tmpDir);
            return back()->withInput()->with('error', 'Extraction failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupTmp($tmpDir);
            return back()->withInput()->with('error', 'An unexpected error occurred. Please try again.');
        }

        $this->cleanupTmp($tmpDir);

        return redirect()
            ->route('om-manuals.edit', $manual)
            ->with('success', 'Equipment extracted successfully. Please review the list below before generating the manual.');
    }

    // ── storeFromProject (Pass 1, project package) ───────────────────────────

    public function storeFromProject(Request $request, Project $project): RedirectResponse
    {
        abort_if($project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        $package = $project->latestPackage;

        if (! $package || $package->status !== ProjectPackage::STATUS_REVIEWED) {
            return back()->with('error', 'No reviewed quote data found for this project. Please review the quote import first.');
        }

        try {
            $manual = $this->generator->extractFromProjectPackage(
                user:    auth()->user(),
                package: $package,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not create O&M manual from project data: ' . $e->getMessage());
        }

        return redirect()
            ->route('om-manuals.edit', $manual)
            ->with('success', 'Project quote data loaded. Please review the equipment list before generating the manual.');
    }

    // ── generateFromProject (create + generate immediately) ─────────────────

    public function generateFromProject(Project $project): RedirectResponse
    {
        abort_if($project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        // Pass 1 replacement (D-07, D-08): Build extracted_data from ProjectDataService.
        // ProjectDataService::resolve() returns reviewed, merged canonical data.
        // No PDF upload, no AI extraction, no user review step required.
        try {
            $context = $this->generator->buildContextFromProjectData($project);
        } catch (\Throwable $e) {
            Log::error('OmManualController: buildContextFromProjectData failed', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not read project data: ' . $e->getMessage());
        }

        // Create the OmManual record with pre-built extracted_data.
        // Status starts as 'generating' — BuildOmManualJob will advance to 'draft' on success.
        $manual = OmManual::create([
            'user_id'        => auth()->id(),
            'project_id'     => $project->id,
            'project_name'   => $context['project_name'],
            'project_ref'    => $context['project_ref'],
            'client_name'    => $context['client_name'],
            'site_address'   => $context['site_address'],
            'extracted_data' => $context,
            'status'         => OmManual::STATUS_GENERATING,
            'error_message'  => null,
        ]);

        Log::info('OmManualController: generateFromProject queued', [
            'project_id'   => $project->id,
            'om_manual_id' => $manual->id,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($manual->id);

        return back()->with('success', 'O&M generation queued — the document will be ready to download shortly.');
    }

    // ── status (JSON polling endpoint for Alpine.js — D-17) ──────────────────

    public function status(OmManual $omManual): JsonResponse
    {
        abort_if($omManual->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        $downloadUrl = in_array($omManual->status, [OmManual::STATUS_DRAFT, OmManual::STATUS_FINAL])
            ? route('om-manuals.download', $omManual)
            : null;

        return response()->json([
            'status'       => $omManual->status,
            'label'        => $omManual->statusLabel(),
            'download_url' => $downloadUrl,
            'error'        => $omManual->error_message,
        ]);
    }

    /**
     * Retry a failed O&M generation job.
     * Re-dispatches BuildOmManualJob using the existing extracted_data.
     */
    public function retryGeneration(OmManual $omManual): RedirectResponse
    {
        abort_if($omManual->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        if (empty($omManual->extracted_data)) {
            return back()->with('error', 'Cannot retry — extracted data is missing. Please create a new O&M manual.');
        }

        $omManual->update([
            'status'        => OmManual::STATUS_GENERATING,
            'error_message' => null,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($omManual->id);

        return back()->with('success', 'O&M generation re-queued.');
    }

    // ── edit (review Pass 1) ──────────────────────────────────────────────────

    public function edit(OmManual $omManual): View
    {
        $this->authorize('update', $omManual);

        return view('om-manual.edit', ['manual' => $omManual]);
    }

    // ── update (save edited extracted_data) ───────────────────────────────────

    public function update(Request $request, OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        $request->validate([
            'extracted_json' => ['required', 'string'],
        ]);

        $decoded = json_decode($request->input('extracted_json'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return back()->with('error', 'Invalid JSON — please check the equipment list and try again.');
        }

        // Sanitise rooms via the generator service
        if (isset($decoded['rooms']) && is_array($decoded['rooms'])) {
            $decoded['rooms'] = $this->generator->sanitiseRooms($decoded['rooms']);
        }

        $omManual->update([
            'project_name'   => $decoded['project']['name']   ?? $decoded['project_name'] ?? $omManual->project_name,
            'project_ref'    => $decoded['project']['ref']    ?? $decoded['project_ref']  ?? $omManual->project_ref,
            'client_name'    => $decoded['project']['client'] ?? $decoded['client_name']  ?? $omManual->client_name,
            'site_address'   => $decoded['project']['site']   ?? $decoded['site_address'] ?? $omManual->site_address,
            'extracted_data' => $decoded,
        ]);

        return back()->with('success', 'Equipment list saved.');
    }

    // ── generate (Pass 2) ─────────────────────────────────────────────────────

    public function generate(OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        if (empty($omManual->extracted_data)) {
            return back()->with('error', 'No equipment data found. Please re-upload the quote PDF.');
        }

        // Dispatch as background job to avoid 504 timeout — same pattern as
        // generateFromProject() and retryGeneration().
        $omManual->update([
            'status'        => OmManual::STATUS_GENERATING,
            'error_message' => null,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($omManual->id);

        return back()->with('success', 'O&M generation queued — the document will be ready to download shortly.');
    }

    // ── download (.docx) ──────────────────────────────────────────────────────

    public function download(OmManual $omManual): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $omManual);

        if (! $omManual->filename) {
            return back()->with('error', 'No document available yet. Please generate the manual first.');
        }

        // Post-H-07: the artifact store resolves the new `documents` disk first
        // and falls back to legacy storage/app/om-manuals/ for older files.
        $filePath = app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_OM, basename($omManual->filename));

        if ($filePath === null) {
            return back()->with('error', 'Document file not found on disk.');
        }

        return response()->download(
            $filePath,
            $omManual->filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    // ── downloadPdf ───────────────────────────────────────────────────────────

    public function downloadPdf(OmManual $omManual): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $omManual);

        if (empty($omManual->generated_data)) {
            return back()->with('error', 'No generated content yet. Please run Generate first.');
        }

        try {
            $pdfPath = $this->pdfService->buildOmManual($omManual);
        } catch (\Throwable $e) {
            return back()->with('error', 'PDF generation failed: ' . $e->getMessage());
        }

        $filename = pathinfo($omManual->filename ?? 'om-manual', PATHINFO_FILENAME) . '.pdf';

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function destroy($omManual): RedirectResponse
    {
        $record = OmManual::findOrFail($omManual);
        $this->authorize('delete', $record);

        // Soft-delete only — keep file on disk so it can be restored
        $record->delete();

        return redirect()->route('om-manuals.index')->with('success', 'O&M Manual deleted.');
    }

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = OmManual::withTrashed()->findOrFail($id);
        $record->restore();

        return back()->with('success', 'O&M Manual restored.');
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = OmManual::onlyTrashed()->findOrFail($id);

        if ($record->filename) {
            // Previously this targeted Storage::disk('local') which resolves
            // to storage/app/private/om-manuals/ — the wrong disk — so the
            // delete was a silent no-op. The artifact store removes copies
            // from both the new `documents` disk and the legacy path.
            app(\App\Services\DocumentArtifactStorage::class)
                ->delete(\App\Services\DocumentArtifactStorage::TYPE_OM, basename((string) $record->filename));
        }

        $record->forceDelete();

        return back()->with('success', 'O&M Manual permanently deleted.');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function cleanupTmp(string $dir): void
    {
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
            }
            rmdir($dir);
        }
    }
}

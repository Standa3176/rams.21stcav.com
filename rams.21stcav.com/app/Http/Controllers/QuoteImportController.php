<?php

namespace App\Http\Controllers;

use App\Core\AI\AIManager;
use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Exceptions\AIGenerationException;
use App\Http\Requests\QuoteImportRequest;
use App\Jobs\ExtractQuoteJob;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Services\WorkerMonitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuoteImportController extends Controller
{
    public function __construct(
        private readonly QuoteImportService    $service,
        private readonly WorkerMonitorService  $workerMonitor,
    ) {}

    // ── Step 1: Upload form ───────────────────────────────────────────────────

    public function create(Request $request): View
    {
        $projects        = Project::forUser(auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'ref', 'client_name', 'status']);
        $defaultProvider = AIManager::defaultProvider();

        // Pre-select a project if passed via query string
        $selectedProjectId = $request->query('project_id');

        return view('quote-import.create', compact('projects', 'defaultProvider', 'selectedProjectId'));
    }

    // ── Step 2: Process upload + dispatch async extraction ───────────────────

    public function store(QuoteImportRequest $request): RedirectResponse
    {
        $file          = $request->file('quote_pdf');
        $createProject = (bool) $request->input('create_project', true);
        $projectId     = $request->integer('project_id') ?: null;

        try {
            $package = $this->service->importPending(auth()->user(), $file, $projectId);
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'Failed to store quote PDF: ' . $e->getMessage());
        }

        $this->workerMonitor->ensureRunning();
        ExtractQuoteJob::dispatch($package, auth()->user(), $createProject);

        return redirect()->route('quote-import.extracting', $package)
            ->with('info', 'Quote upload received — extracting data in the background.');
    }

    // ── Extraction progress page ──────────────────────────────────────────────

    public function extracting(ProjectPackage $package): View|RedirectResponse
    {
        $this->authorizePackage($package);

        if ($package->status === ProjectPackage::STATUS_EXTRACTED) {
            return redirect()->route('project-packages.review.show', $package)
                ->with('success', 'Quote extracted successfully. Please review and confirm the data below.');
        }

        if ($package->status === ProjectPackage::STATUS_FAILED) {
            return redirect()->route('quote-import.create')
                ->with('error', 'Quote extraction failed. Please try again or contact support.');
        }

        return view('quote-import.extracting', compact('package'));
    }

    public function extractStatus(ProjectPackage $package): \Illuminate\Http\JsonResponse
    {
        $this->authorizePackage($package);

        return response()->json([
            'status'   => $package->status,
            'terminal' => in_array($package->status, [
                ProjectPackage::STATUS_EXTRACTED,
                ProjectPackage::STATUS_FAILED,
            ]),
            'redirect' => $package->status === ProjectPackage::STATUS_EXTRACTED
                ? route('project-packages.review.show', $package)
                : null,
        ]);
    }

    // ── Step 3: Review extracted data ─────────────────────────────────────────

    public function review(ProjectPackage $package): View
    {
        $this->authorizePackage($package);

        $package->load('project');

        $projects = Project::forUser(auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'ref', 'client_name']);

        return view('quote-import.review', compact('package', 'projects'));
    }

    // ── Step 4: Confirm + link to project ────────────────────────────────────

    public function confirm(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:200'],
            'ref'               => ['nullable', 'string', 'max:50'],
            'client_name'       => ['required', 'string', 'max:150'],
            'site_address'      => ['required', 'string', 'max:500'],
            'works_description' => ['nullable', 'string'],
            // Optionally reassign / create project
            'project_id'        => ['nullable', 'integer', 'exists:projects,id'],
            'new_project'       => ['nullable', 'boolean'],
        ]);

        // Determine project assignment
        if (! empty($validated['project_id'])) {
            $project = Project::where('id', $validated['project_id'])
                ->where('user_id', auth()->id())
                ->firstOrFail();

            // Update package's project_id if it changed
            if ($package->project_id !== $project->id) {
                $package->update(['project_id' => $project->id]);
                $package->refresh();
            }
        } elseif ($package->project_id === null) {
            // No project yet — force-create one
            $validated['new_project'] = true;
        }

        $overrides = array_filter([
            'name'              => $validated['name']              ?? null,
            'ref'               => $validated['ref']               ?? null,
            'client_name'       => $validated['client_name']       ?? null,
            'site_address'      => $validated['site_address']      ?? null,
            'works_description' => $validated['works_description'] ?? null,
        ], fn($v) => $v !== null);

        try {
            // If still no project, create one now
            if ($package->project_id === null) {
                $service = app(\App\Core\Modules\Projects\ProjectService::class);
                $project = $service->create(auth()->user(), [
                    'name'              => $validated['name'],
                    'ref'               => $validated['ref'] ?? null,
                    'client_name'       => $validated['client_name'],
                    'site_address'      => $validated['site_address'],
                    'works_description' => $validated['works_description'] ?? null,
                ]);
                $package->update(['project_id' => $project->id]);
                $package->refresh();
                // Overrides already applied via create — no need to call confirm's update
                $overrides = [];
            }

            $this->service->confirm(
                user:      auth()->user(),
                package:   $package,
                overrides: $overrides,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('QuoteImportController: confirm failed', [
                'package_id' => $package->id,
                'error'      => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to confirm import: ' . $e->getMessage());
        }

        $project = $package->fresh()->project;

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Quote data confirmed. Project is ready.');
    }

    // ── Re-extract ────────────────────────────────────────────────────────────

    public function reextract(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

        $provider = $request->input('ai_provider');

        try {
            $newPackage = $this->service->reimport(
                user:     auth()->user(),
                existing: $package,
                provider: $provider,
            );
        } catch (AIGenerationException $e) {
            return back()->with('error', 'Re-extraction failed: ' . $e->getMessage());
        }

        return redirect()
            ->route('project-packages.review.show', $newPackage)
            ->with('success', 'Quote re-extracted (revision ' . $newPackage->revision . '). Please review the updated data.');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function authorizePackage(ProjectPackage $package): void
    {
        abort_unless(
            $package->user_id === auth()->id() || auth()->user()?->role === 'admin',
            403
        );
    }
}

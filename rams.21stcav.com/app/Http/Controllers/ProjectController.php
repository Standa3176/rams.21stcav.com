<?php

namespace App\Http\Controllers;

use App\Core\Modules\Projects\ProjectDataService;
use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService     $service,
        private readonly ProjectDataService $projectDataService,
    ) {}

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');
        $status      = $request->query('status');
        $search      = $request->query('search');
        $client      = $request->input('client');

        if ($showDeleted) {
            $projects     = Project::onlyTrashed()->with(['user'])->latest('deleted_at')->paginate(20)->withQueryString();
            $statusCounts = collect();
            $clients      = collect();
            return view('projects.index', compact('projects', 'statusCounts', 'status', 'search', 'isAdmin', 'showDeleted', 'clients', 'client'));
        }

        $query = Project::with('latestPackage')
            ->orderByDesc('updated_at');

        if ($status) {
            $query->byStatus($status);
        }

        $query->when($client, fn ($q) => $q->where('client_name', $client));

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('client_name', 'like', "%{$search}%")
                  ->orWhere('site_address', 'like', "%{$search}%")
                  ->orWhere('ref', 'like', "%{$search}%");
            });
        }

        $projects = $query->paginate(20)->withQueryString();

        $statusCounts = Project::query()
            ->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $clients = Project::query()
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('client_name')
            ->pluck('client_name')
            ->filter();

        return view('projects.index', compact('projects', 'statusCounts', 'status', 'search', 'isAdmin', 'showDeleted', 'clients', 'client'));
    }

    // ── create / store ────────────────────────────────────────────────────────

    public function create(): RedirectResponse
    {
        return redirect()->route('quote-import.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:200'],
            'quote_reference'   => ['required', 'string', 'max:50'],
            'client_name'       => ['required', 'string', 'max:150'],
            'site_address'      => ['required', 'string', 'max:500'],
            'works_description' => ['nullable', 'string'],
            'notes'             => ['nullable', 'string'],
        ]);

        // D-14: Warn (but allow) when a project with matching client+site already exists.
        $similarExists = Project::whereRaw('LOWER(client_name) = ?', [strtolower($validated['client_name'])])
            ->whereRaw('LOWER(site_address) = ?', [strtolower($validated['site_address'])])
            ->whereNull('deleted_at')
            ->exists();

        $project = $this->service->create(auth()->user(), $validated);

        $redirect = redirect()->route('projects.show', $project);

        if ($similarExists) {
            $redirect = $redirect->with('warning', 'A project with the same client and site address already exists. Please confirm this is a separate project.');
        } else {
            $redirect = $redirect->with('success', "Project \"{$project->name}\" created.");
        }

        return $redirect;
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function show(Project $project): View
    {
        // D-15: Projects are shared across all authenticated users.
        abort_unless(auth()->check(), 403);

        // Eager-load all related data to prevent N+1 queries on the show page.
        $project->load([
            'projectQuotes.uploadedBy',                          // quote history panel
            'ramsDocuments'  => fn ($q) => $q->latest()->limit(5),
            'latestPackage',
            'activityLog.user',
            'omManuals'      => fn ($q) => $q->latest()->limit(5),
            'siteSurveys'    => fn ($q) => $q->latest()->limit(5),
            'cableSchedules' => fn ($q) => $q->latest()->limit(5),
            'worksheets'     => fn ($q) => $q->latest()->limit(5),
        ]);

        $nextStatus = $project->nextStatus();

        // Build linked records summary for the Linked Records card.
        $linkedRecords = [
            [
                'type'               => 'RAMS',
                'badge_class'        => 'badge-teal',
                'records'            => $project->ramsDocuments,
                'route_name'         => 'rams.review',
                'empty_action_label' => 'Upload Quote for RAMS',
                'empty_action_route' => route('rams.upload.create', ['project_id' => $project->id]),
            ],
            [
                'type'               => 'Survey',
                'badge_class'        => 'badge-grey',
                'records'            => $project->siteSurveys,
                'route_name'         => 'site-surveys.show',
                'empty_action_label' => 'Start Survey',
                'empty_action_route' => route('site-surveys.from-project', $project),
            ],
            [
                'type'                => 'Worksheet',
                'badge_class'         => 'badge-teal',
                'records'             => $project->worksheets,
                'route_name'          => 'worksheets.show',
                'generate_route'      => route('worksheets.generate-from-project', $project),
                'status_route_name'   => 'worksheets.status',
                'download_route_name' => 'worksheets.download',
                'generate_label'      => 'Generate Worksheet',
                'empty_action_label'  => null,
                'empty_action_route'  => null,
            ],
            [
                'type'                => 'O&M',
                'badge_class'         => 'badge-green',
                'records'             => $project->omManuals,
                'route_name'          => 'om-manuals.edit',
                'generate_route'      => route('om-manuals.generate-from-project', $project),
                'status_route_name'   => 'om-manuals.status',
                'download_route_name' => 'om-manuals.download',
                'generate_label'      => 'Generate O&M Manual',
                'empty_action_label'  => null,
                'empty_action_route'  => null,
            ],
            [
                'type'                => 'Cable Schedule',
                'badge_class'         => 'badge-grey',
                'records'             => $project->cableSchedules,
                'route_name'          => 'cable-schedules.edit',
                'generate_route'      => route('cable-schedules.generate-from-project', $project),
                'status_route_name'   => 'cable-schedules.status',
                'download_route_name' => 'cable-schedules.download',
                'generate_label'      => 'Generate Cable Schedule',
                'empty_action_label'  => null,
                'empty_action_route'  => null,
            ],
        ];

        $canonicalData = $this->projectDataService->resolve($project);

        return view('projects.show', compact('project', 'nextStatus', 'linkedRecords', 'canonicalData'));
    }

    // ── edit / update ─────────────────────────────────────────────────────────

    public function edit(Project $project): View
    {
        $this->authorizeProject($project);

        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:200'],
            'ref'               => ['nullable', 'string', 'max:50'],
            'client_name'       => ['required', 'string', 'max:150'],
            'site_address'      => ['required', 'string', 'max:500'],
            'works_description' => ['nullable', 'string'],
            'notes'             => ['nullable', 'string'],
        ]);

        $this->service->update($project, auth()->user(), $validated);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project details updated.');
    }

    // ── transition ────────────────────────────────────────────────────────────

    public function transition(Request $request, Project $project): RedirectResponse
    {
        // D-19: Any authenticated user can trigger lifecycle transitions.
        abort_unless(auth()->check(), 403);

        $validated = $request->validate([
            'to_status' => ['required', 'string'],
            'note'      => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->transition(
                $project,
                $validated['to_status'],
                auth()->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = Project::STATUS_LABELS[$validated['to_status']] ?? $validated['to_status'];

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "Project advanced to: {$label}.");
    }

    // ── archive ───────────────────────────────────────────────────────────────

    public function archive(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->archive($project, auth()->user(), $validated['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('projects.index')
            ->with('success', "Project \"{$project->name}\" archived.");
    }

    // ── reopen ────────────────────────────────────────────────────────────────

    public function reopen(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $validated = $request->validate([
            'reopen_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->service->reopen($project, auth()->user(), $validated['reopen_reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "Project reopened and restored to previous status.");
    }

    // ── destroy (soft delete) ─────────────────────────────────────────────────

    public function destroy($project): RedirectResponse
    {
        $record = Project::findOrFail($project);
        $this->authorizeProject($record);

        $name = $record->name;
        $record->delete();

        Log::info('ProjectController: project soft-deleted', ['id' => $record->id, 'user_id' => auth()->id()]);

        return redirect()->route('projects.index')->with('success', "Project \"{$name}\" deleted.");
    }

    // ── restore (admin only) ──────────────────────────────────────────────────

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = Project::withTrashed()->findOrFail($id);
        $record->restore();

        Log::info('ProjectController: project restored', ['id' => $id, 'admin_id' => auth()->id()]);

        return back()->with('success', "Project \"{$record->name}\" restored.");
    }

    // ── forceDestroy (admin only) ─────────────────────────────────────────────

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = Project::onlyTrashed()->findOrFail($id);
        $name   = $record->name;
        $record->forceDelete();

        Log::info('ProjectController: project permanently deleted', ['id' => $id, 'admin_id' => auth()->id()]);

        return back()->with('success', "Project \"{$name}\" permanently deleted.");
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function authorizeProject(Project $project): void
    {
        abort_unless(
            $project->user_id === auth()->id() || auth()->user()?->role === 'admin',
            403
        );
    }
}

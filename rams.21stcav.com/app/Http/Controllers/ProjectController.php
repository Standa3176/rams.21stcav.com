<?php

namespace App\Http\Controllers;

use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');
        $status      = $request->query('status');
        $search      = $request->query('search');

        if ($showDeleted) {
            $projects     = Project::onlyTrashed()->with(['user'])->latest('deleted_at')->paginate(20)->withQueryString();
            $statusCounts = collect();
            return view('projects.index', compact('projects', 'statusCounts', 'status', 'search', 'isAdmin', 'showDeleted'));
        }

        $query = Project::with('latestPackage')
            ->forUser(auth()->id())
            ->orderByDesc('updated_at');

        if ($status) {
            $query->byStatus($status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('client_name', 'like', "%{$search}%")
                  ->orWhere('ref', 'like', "%{$search}%");
            });
        }

        $projects = $query->paginate(20)->withQueryString();

        $statusCounts = Project::forUser(auth()->id())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('projects.index', compact('projects', 'statusCounts', 'status', 'search', 'isAdmin', 'showDeleted'));
    }

    // ── create / store ────────────────────────────────────────────────────────

    public function create(): View
    {
        return view('projects.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:200'],
            'ref'               => ['nullable', 'string', 'max:50'],
            'client_name'       => ['required', 'string', 'max:150'],
            'site_address'      => ['required', 'string', 'max:500'],
            'works_description' => ['nullable', 'string'],
            'notes'             => ['nullable', 'string'],
        ]);

        $project = $this->service->create(auth()->user(), $validated);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "Project \"{$project->name}\" created.");
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function show(Project $project): View
    {
        // Security: only the project owner (or an admin) may view the project.
        abort_if(
            $project->user_id !== auth()->id() && auth()->user()?->role !== 'admin',
            403
        );

        // Eager-load all related data to prevent N+1 queries on the show page.
        $project->load([
            'projectQuotes.uploadedBy',   // quote history panel
            'ramsDocuments',              // RAMS documents panel
            'latestPackage',
            'activityLog.user',
            'omManuals',
            'siteSurveys',
            'cableSchedules',
        ]);

        $nextStatus = $project->nextStatus();

        return view('projects.show', compact('project', 'nextStatus'));
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
        $this->authorizeProject($project);

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

<?php

namespace App\Http\Controllers;

use App\Core\Modules\Projects\ProjectDataService;
use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Services\ProjectDeliverablesService;
use App\Services\TimeEntryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService              $service,
        private readonly ProjectDataService           $projectDataService,
        private readonly TimeEntryService             $timeEntryService,
        private readonly ProjectDeliverablesService   $deliverablesService,
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
            $projects     = Project::onlyTrashed()->with(['owner'])->latest('deleted_at')->paginate(20)->withQueryString();
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
        // (auth is already enforced by the route's `auth` middleware group.)

        // Eager-load all related data to prevent N+1 queries on the show page.
        $project->load([
            'projectQuotes.uploadedBy',                              // quote history panel
            'ramsDocuments'      => fn ($q) => $q->latest()->limit(3),
            'latestPackage',
            'activityLog.user',
            'omManuals'          => fn ($q) => $q->latest()->limit(3),
            'siteSurveys'        => fn ($q) => $q->latest()->limit(3),
            'cableSchedules'     => fn ($q) => $q->latest()->limit(3),
            'worksheets'         => fn ($q) => $q->latest()->limit(3),
            'installProgrammes'  => fn ($q) => $q->latest()->limit(3),
            // 260822-04 (D-07/D-08/D-09): required so Project::deliverableState()
            // can answer without issuing its own query — see the relationLoaded()
            // guard in Project::deliverableState().
            'deliverables',
        ]);

        $nextStatus = $project->nextStatus();

        // Build linked records summary for the Linked Records card.

        // Determine RAMS action based on package state (latestPackage eager-loaded above).
        $latestPackage = $project->latestPackage;
        $ramsDownloadKeys = [
            'download_route_name'     => 'rams.download',
            'download_pdf_route_name' => 'rams.download-pdf',
            'regenerate_route_name'   => 'rams.retry-generation',
            'delete_route_name'       => 'rams.destroy',
        ];

        if ($latestPackage && $latestPackage->status === ProjectPackage::STATUS_REVIEWED) {
            $ramsEntry = array_merge([
                'type'           => 'RAMS',
                'badge_class'    => 'badge-teal',
                'records'        => $project->ramsDocuments,
                'route_name'     => 'rams.review',
                'generate_route' => route('rams.from-project', $project),
                'generate_label' => 'Create RAMS',
            ], $ramsDownloadKeys);
        } elseif ($latestPackage) {
            $ramsEntry = array_merge([
                'type'               => 'RAMS',
                'badge_class'        => 'badge-teal',
                'records'            => $project->ramsDocuments,
                'route_name'         => 'rams.review',
                'empty_action_label' => 'Review Data First',
                'empty_action_route' => route('project-packages.review.show', $latestPackage),
            ], $ramsDownloadKeys);
        } else {
            $ramsEntry = array_merge([
                'type'               => 'RAMS',
                'badge_class'        => 'badge-teal',
                'records'            => $project->ramsDocuments,
                'route_name'         => 'rams.review',
                'empty_action_label' => 'Create RAMS',
                'empty_action_route' => route('rams.create', ['project_id' => $project->id]),
            ], $ramsDownloadKeys);
        }

        $linkedRecords = [
            $ramsEntry,
            [
                'type'               => 'Survey',
                'badge_class'        => 'badge-grey',
                'records'            => $project->siteSurveys,
                'route_name'         => 'site-surveys.show',
                'delete_route_name'       => 'site-surveys.destroy',
                'regenerate_route_name'   => 'site-surveys.supersede-from-project', // POST — auto-archives existing
                'regenerate_route_param'  => $project, // This route expects {project}, not {record}
                'copy_link'               => true,  // renders copy-link button per record
                'empty_action_label'      => 'Start Survey',
                'empty_action_route'      => route('site-surveys.from-project', $project),
            ],
            [
                'type'                    => 'Worksheet',
                'badge_class'             => 'badge-teal',
                'records'                 => $project->worksheets,
                'route_name'              => 'worksheets.show',
                'delete_route_name'       => 'worksheets.destroy',
                'regenerate_route_name'   => 'worksheets.retry-generation',
                'generate_route'          => route('worksheets.generate-from-project', $project),
                'status_route_name'       => 'worksheets.status',
                'download_route_name'     => 'worksheets.download',
                'generate_label'          => 'Generate Worksheet',
                'empty_action_label'      => null,
                'empty_action_route'      => null,
            ],
            [
                'type'                    => 'O&M',
                'badge_class'             => 'badge-green',
                'records'                 => $project->omManuals,
                'route_name'              => 'om-manuals.edit',
                'delete_route_name'       => 'om-manuals.destroy',
                'regenerate_route_name'   => 'om-manuals.retry-generation',
                'generate_route'          => route('om-manuals.generate-from-project', $project),
                'status_route_name'       => 'om-manuals.status',
                'download_route_name'     => 'om-manuals.download',
                'generate_label'          => 'Generate O&M Manual',
                'empty_action_label'      => null,
                'empty_action_route'      => null,
            ],
            [
                'type'                    => 'Cable Schedule',
                'badge_class'             => 'badge-grey',
                'records'                 => $project->cableSchedules,
                'route_name'              => 'cable-schedules.edit',
                'delete_route_name'       => 'cable-schedules.destroy',
                'regenerate_route_name'   => 'cable-schedules.retry-generation',
                'generate_route'          => route('cable-schedules.generate-from-project', $project),
                'status_route_name'       => 'cable-schedules.status',
                'download_route_name'     => 'cable-schedules.download',
                'generate_label'          => 'Generate Cable Schedule',
                'empty_action_label'      => null,
                'empty_action_route'      => null,
            ],
            [
                'type'               => 'Install Programme',
                'badge_class'        => 'badge-blue',
                'records'            => $project->installProgrammes,
                'route_name'         => 'install-programmes.review',
                'generate_route'     => route('install-programmes.generate', $project),
                'generate_label'     => 'Generate Install Programme',
                'empty_action_label' => null,
                'empty_action_route' => null,
            ],
            // 260822-04 (D-04/D-07): reconciliation additions — Drawings and
            // Snagging had no Linked Records entry before this phase. Neither
            // has a download/status route yet (D-07 Deferred Ideas), so this
            // entry only offers the index/view route.
            [
                'type'               => 'Drawings',
                'badge_class'        => 'badge-blue',
                'records'            => $project->drawings()->whereNull('superseded_by_id')->get(),
                'route_name'         => 'projects.drawings.index',
                'generate_route'     => route('projects.drawings.index', $project),
                'generate_label'     => 'View Drawings',
                'empty_action_label' => null,
                'empty_action_route' => null,
            ],
            [
                'type'               => 'Snagging',
                'badge_class'        => 'badge-grey',
                'records'            => $project->snaggingSignoffs,
                'route_name'         => null,
                'empty_action_label' => null,
                'empty_action_route' => null,
            ],
        ];

        $canonicalData = $this->projectDataService->resolve($project);

        // ── Actual Hours widget visibility ────────────────────────────────────
        // Shared workspace: all authenticated users see actual hours.
        $canSeeActualHours = auth()->check();

        $actualHours = $canSeeActualHours
            ? $this->timeEntryService->summaryForProject($project)
            : null;

        return view('projects.show', compact(
            'project',
            'nextStatus',
            'linkedRecords',
            'canonicalData',
            'canSeeActualHours',
            'actualHours',
        ));
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
        // (auth is already enforced by the route's `auth` middleware group.)

        $validated = $request->validate([
            'to_status' => ['required', 'string'],
            'note'      => ['nullable', 'string', 'max:500'],
        ]);

        // D-14: completing a project with required-but-missing deliverables
        // warns and asks for confirmation — it never silently blocks, and it
        // never silently proceeds. First submit (no confirm_incomplete) with
        // outstanding required deliverables redirects back with a warning,
        // WITHOUT performing the transition. Re-submitting with
        // confirm_incomplete=1 proceeds exactly as this method did before
        // this branch existed.
        if ($validated['to_status'] === Project::STATUS_COMPLETED && ! $request->boolean('confirm_incomplete')) {
            $outstanding = $this->outstandingRequiredDeliverables($project);
            if ($outstanding !== []) {
                return back()->with(
                    'warning',
                    'This project has required deliverables with no documents yet: '
                        .implode(', ', $outstanding)
                        .'. Re-submit to complete anyway.',
                );
            }
        }

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

    // ── deliverables ─────────────────────────────────────────────────────────

    /**
     * D-10: edit a project's deliverable states from the Project Data tab.
     *
     * Accepts the multi-row edit form's `deliverables[key]=state` associative
     * array shape. Also accepts Plan 04's already-shipped muted-tab "Add
     * anyway" form, which posts a flat `deliverable_key` + `state` pair to
     * this exact route (registered above) — normalized into the same
     * `deliverables` array shape below so both callers share one validation
     * and write path (Rule 1 fix: the flat shape would otherwise 422 against
     * the `array:` rule and silently break Plan 04's shipped recovery
     * action).
     *
     * Every changed key is written through ProjectDeliverablesService::
     * setState() — the sole audited (D-03) write path. Never writes directly
     * to project_deliverables.
     */
    public function updateDeliverables(Request $request, Project $project): RedirectResponse
    {
        // D-19: Any authenticated user can edit deliverables (auth already
        // enforced by the route's `auth` middleware group).

        if ($request->has('deliverable_key') && ! $request->has('deliverables')) {
            $request->merge([
                'deliverables' => [$request->input('deliverable_key') => $request->input('state')],
            ]);
        }

        $validated = $request->validate([
            'deliverables' => ['required', 'array:'.implode(',', ProjectDeliverable::ALL_KEYS)],
            'deliverables.*' => ['required', 'string', Rule::in([
                ProjectDeliverable::STATE_REQUIRED,
                ProjectDeliverable::STATE_NOT_REQUIRED,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
            ])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($validated['deliverables'] as $key => $newState) {
            $this->deliverablesService->setState(
                $project,
                $key,
                $newState,
                auth()->user(),
                $validated['reason'] ?? null,
            );
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Deliverables updated.');
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
        abort_unless(auth()->check(), 403); // Shared workspace: any authenticated user has full access.
    }

    /**
     * D-14: human-readable labels for every ALL_KEYS entry that is currently
     * Required AND has zero backing documents. `programming` is deliberately
     * EXCLUDED — it has no document type/generator (D-05), so there is
     * nothing to be "missing" and nothing to warn about.
     *
     * This is a separate, simpler question than ProjectHealthService's D-12
     * health-scoring rules — do not import ProjectHealthService here.
     *
     * @return array<int, string>
     */
    private function outstandingRequiredDeliverables(Project $project): array
    {
        // Project::deliverableState() only answers once `deliverables` is
        // eager-loaded (relationLoaded() guard) — the route-model-bound
        // $project passed into transition() has NOT been through show()'s
        // eager-load list, so without this it would return null for every
        // key and this whole check would silently never fire. Rule 1 fix.
        $project->loadMissing('deliverables');

        $counts = [
            ProjectDeliverable::KEY_SITE_SURVEY       => $project->siteSurveys()->count(),
            ProjectDeliverable::KEY_RAMS              => $project->ramsDocuments()->count(),
            ProjectDeliverable::KEY_WORKSHEET         => $project->worksheets()->count(),
            ProjectDeliverable::KEY_OM                => $project->omManuals()->count(),
            ProjectDeliverable::KEY_CABLE_SCHEDULE    => $project->cableSchedules()->count(),
            ProjectDeliverable::KEY_INSTALL_PROGRAMME => $project->installProgrammes()->count(),
            ProjectDeliverable::KEY_DRAWINGS          => $project->drawings()->count(),
            ProjectDeliverable::KEY_SNAGGING          => $project->snaggingSignoffs()->count(),
        ];

        $labels = [
            ProjectDeliverable::KEY_SITE_SURVEY       => 'Surveys',
            ProjectDeliverable::KEY_RAMS              => 'RAMS',
            ProjectDeliverable::KEY_WORKSHEET         => 'Worksheets',
            ProjectDeliverable::KEY_OM                => 'O&M',
            ProjectDeliverable::KEY_CABLE_SCHEDULE    => 'Cable Schedule',
            ProjectDeliverable::KEY_INSTALL_PROGRAMME => 'Install Programme',
            ProjectDeliverable::KEY_DRAWINGS          => 'Drawings',
            ProjectDeliverable::KEY_SNAGGING          => 'Snagging',
        ];

        $outstanding = [];
        foreach ($counts as $key => $count) {
            if ($project->deliverableState($key) === ProjectDeliverable::STATE_REQUIRED && $count === 0) {
                $outstanding[] = $labels[$key];
            }
        }

        return $outstanding;
    }
}

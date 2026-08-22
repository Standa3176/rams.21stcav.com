<?php

namespace App\Http\Controllers;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Services\InstallProgrammeService;
use App\Services\ProjectDeliverablesService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * InstallProgrammeController — HTTP layer for the install programme lifecycle.
 *
 * Generation flow:
 *   POST generate → InstallProgrammeService::createForProject() (synchronous, no queue) →
 *   redirect to review page with draft programme.
 *
 * Review flow:
 *   GET review → display tasks grouped by room — PM can delete individual tasks before activating.
 *
 * Activation flow:
 *   POST activate → InstallProgrammeService::activate() → redirect to project show page.
 *
 * Auth pattern:
 *   abort_if ownership check on every action, matching WorksheetController pattern.
 *   destroyTask checks ownership transitively: task → programme → project.
 *
 * @see InstallProgrammeService       — orchestration layer
 * @see InstallTaskGeneratorService   — populates tasks on generation
 */
class InstallProgrammeController extends Controller
{
    public function __construct(
        private readonly InstallProgrammeService $service,
        private readonly ProjectDeliverablesService $deliverablesService,
    ) {}

    // =========================================================================
    // GENERATE
    // =========================================================================

    /**
     * Create a new draft InstallProgramme for the given project.
     *
     * Archives any existing draft/active programmes before creating the new one.
     * Synchronous — no queue dispatched.
     *
     * @param  Project $project
     * @return RedirectResponse
     */
    public function generate(Project $project): RedirectResponse
    {
        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        $programme = $this->service->createForProject($project, auth()->user());

        $this->deliverablesService->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_INSTALL_PROGRAMME, auth()->user());

        Log::info('InstallProgrammeController: programme generated', [
            'programme_id' => $programme->id,
            'project_id'   => $project->id,
            'user_id'      => auth()->id(),
        ]);

        return redirect()
            ->route('install-programmes.review', $programme)
            ->with('success', 'Install programme draft created — review tasks before activating.');
    }

    // =========================================================================
    // REVIEW
    // =========================================================================

    /**
     * Display the draft programme review page with tasks grouped by room.
     *
     * @param  InstallProgramme $programme
     * @return View
     */
    public function review(InstallProgramme $programme): View
    {
        $programme->load(['tasks', 'project']);

        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        return view('install-programmes.review', compact('programme'));
    }

    // =========================================================================
    // ACTIVATE
    // =========================================================================

    /**
     * Activate the draft programme, making it the live delivery programme.
     *
     * @param  InstallProgramme $programme
     * @return RedirectResponse
     */
    public function activate(InstallProgramme $programme): RedirectResponse
    {
        $programme->load('project');

        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        $this->service->activate($programme);

        Log::info('InstallProgrammeController: programme activated', [
            'programme_id' => $programme->id,
            'project_id'   => $programme->project_id,
            'user_id'      => auth()->id(),
            'activated_at' => $programme->activated_at?->toISOString(),
        ]);

        return redirect()
            ->route('projects.show', $programme->project_id)
            ->with('success', 'Install programme activated.');
    }

    // =========================================================================
    // SCHEDULE (Week-view + conditional Gantt)
    // =========================================================================

    /**
     * Display the programme schedule — week-view calendar with optional Gantt.
     *
     * INST-02g: Field engineers (non-owner, non-admin) see only their assigned tasks.
     * Project owners and admins see all tasks.
     *
     * @param  InstallProgramme $programme
     * @return View
     */
    public function schedule(InstallProgramme $programme): View
    {
        $programme->load(['project', 'tasks.assignedUser']);

        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        // In a shared workspace everyone sees ALL tasks (the ternary below yields
        // the full task collection when this is true).
        $isOwnerOrAdmin = true;

        // INST-02g: filter tasks for field engineers
        $tasks = $isOwnerOrAdmin
            ? $programme->tasks
            : $programme->tasks->where('assigned_to', auth()->id())->values();

        // INST-02e: show Gantt when programme-level planned_end_date - planned_start_date > 4 days
        $showGantt = false;
        if ($programme->planned_start_date && $programme->planned_end_date) {
            $diff = Carbon::parse($programme->planned_end_date)
                ->diffInDays(Carbon::parse($programme->planned_start_date));
            $showGantt = $diff > 4;
        }

        // Week-view grouping: tasks with planned_start_date grouped by ISO year-week
        // Tasks without planned_start_date go into the 'unscheduled' bucket
        $unscheduled = $tasks->filter(fn ($t) => is_null($t->planned_start_date))->values();
        $scheduled   = $tasks->filter(fn ($t) => ! is_null($t->planned_start_date));

        // Group by ISO year + week number for stable ordering
        $byWeek = $scheduled->groupBy(function ($task) {
            return Carbon::parse($task->planned_start_date)->format('o-W');
            // 'o' = ISO year, 'W' = ISO week number — produces '2026-17' etc.
        })->sortKeys();

        // Gantt data: map tasks to frappe-gantt format
        $ganttTasks = $tasks
            ->filter(fn ($t) => $t->planned_start_date && $t->planned_end_date)
            ->map(fn ($t) => [
                'id'       => 'task-' . $t->id,
                'name'     => $t->title,
                'start'    => Carbon::parse($t->planned_start_date)->format('Y-m-d'),
                'end'      => Carbon::parse($t->planned_end_date)->format('Y-m-d'),
                'progress' => match ($t->status) {
                    InstallTask::STATUS_COMPLETE    => 100,
                    InstallTask::STATUS_IN_PROGRESS => 50,
                    default                         => 0,
                },
                // INST-02f: extra fields for click-to-detail slide-over panel
                'room'     => $t->room_name,
                'engineer' => $t->assignedUser?->name,
            ])
            ->values();

        // Colour map for engineer badge colouring — deterministic by user ID modulo 8
        $engineerColours = [
            0 => 'bg-blue-100 text-blue-800',
            1 => 'bg-green-100 text-green-800',
            2 => 'bg-amber-100 text-amber-800',
            3 => 'bg-purple-100 text-purple-800',
            4 => 'bg-pink-100 text-pink-800',
            5 => 'bg-teal-100 text-teal-800',
            6 => 'bg-orange-100 text-orange-800',
            7 => 'bg-red-100 text-red-800',
        ];

        Log::info('InstallProgrammeController: schedule viewed', [
            'programme_id'    => $programme->id,
            'user_id'         => auth()->id(),
            'is_owner_admin'  => $isOwnerOrAdmin,
            'task_count'      => $tasks->count(),
            'show_gantt'      => $showGantt,
        ]);

        return view('install-programmes.schedule', compact(
            'programme',
            'unscheduled',
            'byWeek',
            'showGantt',
            'ganttTasks',
            'engineerColours',
            'isOwnerOrAdmin',
        ));
    }

    // =========================================================================
    // FIELD VIEW (Mobile — engineers execute tasks from phone)
    // =========================================================================

    /**
     * Mobile-first field page for a project's active install programme (INST-03a).
     *
     * Scope rules (D-02 / INST-03b):
     *   - Owner/admin: sees all tasks by default, with `scope=mine` query param to filter
     *   - Engineer (assigned-only): sees their own tasks, with `scope=all` to broaden
     *   - Unrelated user: 403
     *
     * Shape returned to the Blade view:
     *   - $programme           InstallProgramme|null (null if project has no active programme)
     *   - $project             Project (for sticky-bar chrome)
     *   - $rooms               Collection grouped by room_name
     *   - $counters            ['programme' => ['complete' => int, 'total' => int], 'room' => [...]]
     *   - $openEntry           TimeEntry|null — current user's open time entry on this project
     *   - $isOwnerOrAdmin      bool
     *   - $scope               'mine' | 'all'
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\Project      $project
     * @return View
     */
    public function field(\Illuminate\Http\Request $request, Project $project): View
    {
        $user = auth()->user();

        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        // In a shared workspace everyone defaults to the 'all' scope (sees ALL
        // tasks); the ?scope=mine toggle still works for anyone who wants it.
        $isOwnerOrAdmin = true;

        // Resolve active programme (null = none yet; view shows an empty state)
        $programme = $project->activeInstallProgramme()
            ->with(['tasks.assignedUser', 'tasks.photos'])
            ->first();

        // Scope toggle: D-02. Default for engineer = 'mine'. Default for owner/admin = 'all'.
        $scope = $request->query('scope', $isOwnerOrAdmin ? 'all' : 'mine');
        if (! in_array($scope, ['mine', 'all'], true)) {
            $scope = $isOwnerOrAdmin ? 'all' : 'mine';
        }

        $allTasks = $programme ? $programme->tasks : collect();
        $tasks = ($scope === 'mine')
            ? $allTasks->where('assigned_to', $user->id)->values()
            : $allTasks->values();

        // Group by denormalised room_name string (Phase 12 convention)
        $rooms = $tasks->groupBy('room_name');

        // Counters (scoped to the current view — engineers see their own counts)
        $counters = [
            'programme' => [
                'complete' => $tasks->where('status', InstallTask::STATUS_COMPLETE)->count(),
                'total'    => $tasks->count(),
            ],
            'room' => $rooms->map(fn ($rt) => [
                'complete' => $rt->where('status', InstallTask::STATUS_COMPLETE)->count(),
                'total'    => $rt->count(),
            ])->toArray(),
        ];

        // Open time entry for this (project, user) pair (null if not clocked in)
        $openEntry = \App\Models\TimeEntry::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->first();

        Log::info('InstallProgrammeController: field view loaded', [
            'project_id'     => $project->id,
            'programme_id'   => $programme?->id,
            'user_id'        => $user->id,
            'is_owner_admin' => $isOwnerOrAdmin,
            'scope'          => $scope,
            'task_count'     => $tasks->count(),
        ]);

        return view('install-programmes.field', compact(
            'project', 'programme', 'rooms', 'counters', 'openEntry',
            'isOwnerOrAdmin', 'scope',
        ));
    }

    // =========================================================================
    // DESTROY TASK
    // =========================================================================

    /**
     * Soft-delete a single task from the programme.
     *
     * Ownership is checked transitively: task → programme → project.
     *
     * @param  InstallTask $task
     * @return RedirectResponse
     */
    public function destroyTask(InstallTask $task): RedirectResponse
    {
        $task->load('programme.project');

        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        $programmeId = $task->install_programme_id;

        $task->delete();

        Log::info('InstallProgrammeController: task removed', [
            'task_id'      => $task->id,
            'programme_id' => $programmeId,
            'user_id'      => auth()->id(),
        ]);

        return back()->with('success', 'Task removed.');
    }
}

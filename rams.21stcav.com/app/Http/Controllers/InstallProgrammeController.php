<?php

namespace App\Http\Controllers;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Services\InstallProgrammeService;
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
        abort_if(
            $project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $programme = $this->service->createForProject($project, auth()->user());

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

        abort_if(
            $programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

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

        abort_if(
            $programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

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

        $isOwnerOrAdmin = $programme->project->user_id === auth()->id()
            || auth()->user()->isAdmin();

        // Field engineers must be assigned to at least one task to access the schedule.
        // If user has no assigned tasks and is not owner/admin, deny access.
        $hasTasks = $programme->tasks->where('assigned_to', auth()->id())->isNotEmpty();

        abort_if(! $isOwnerOrAdmin && ! $hasTasks, 403);

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

        abort_if(
            $task->programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

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

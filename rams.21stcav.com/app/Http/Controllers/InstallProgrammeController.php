<?php

namespace App\Http\Controllers;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Services\InstallProgrammeService;
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

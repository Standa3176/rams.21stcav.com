<?php

namespace App\Http\Controllers;

use App\DTO\ProjectHealth;
use App\Models\Project;
use App\Services\ProjectHealthService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * ProjectCockpitController — the read-only project cockpit (Phase 45).
 *
 * READ-ONLY BY CONSTRUCTION. ROADMAP criteria 3 and 5 say the cockpit renders
 * what the app already holds and adds no new capture and no new writes from
 * any user-facing surface. This controller therefore exposes exactly one
 * public action, `show()`. There is deliberately no store(), update(),
 * destroy(), create() or edit(), and no POST/PATCH/DELETE route points here.
 * Do not add one: the next phase that needs a write owns its own controller.
 *
 * Feature-flagged via COCKPIT_ENABLED (config/cockpit.php, default false), so
 * the route 404s until the visits backfill has run and someone flips the flag
 * deliberately. The route itself is registered UNCONDITIONALLY — wrapping
 * Route::get() in `if (config(...))` would make route('projects.cockpit')
 * throw a RouteNotFoundException whenever the flag is off, which is worse
 * than a 404. This mirrors SpikeSchematicController::show():19-25.
 *
 * Auth follows the shared-workspace convention (CommissioningController:50-51):
 * any authenticated user has full access. No role gate beyond that.
 */
class ProjectCockpitController extends Controller
{
    public function __construct(private ProjectHealthService $health)
    {
    }

    public function show(Project $project): View
    {
        abort_unless(config('cockpit.enabled'), 404);
        abort_unless(auth()->check(), 403);

        // ProjectHealthService MUST NOT query (its docblock at :13-15 is a hard
        // contract) — the caller eager-loads. These are the three relations it
        // names. Load them BEFORE assess() or the D-13 deliverables rule
        // silently degrades to a no-op and the dashboard and the cockpit would
        // disagree about the same project.
        $project->loadMissing(['ramsDocuments', 'siteSurveys', 'deliverables']);

        $health = $this->assessQuietly($project);

        return view('projects.cockpit', compact('project', 'health'));
    }

    /**
     * Health is one line of context, not the page. If it cannot be derived the
     * spine below it is still accurate, so the attention line degrades to its
     * "could not be read" copy and everything else renders. Never blank the
     * page over a summary.
     */
    private function assessQuietly(Project $project): ?ProjectHealth
    {
        try {
            return $this->health->assess($project);
        } catch (Throwable $e) {
            Log::warning('ProjectCockpitController: health assessment failed', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }
}

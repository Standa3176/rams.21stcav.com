<?php

namespace App\Http\Controllers;

use App\DTO\ProjectHealth;
use App\Models\Project;
use App\Services\ProjectHealthService;
use App\Support\Cockpit\CockpitHeaderPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitSectionPresenter;
use Illuminate\Http\Request;
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
 *
 * THE SIDE PANEL'S STATE IS URL STATE (Plan 45-11). `?module=` and `?tab=`
 * select which module's panel is open and which of its three tabs is showing.
 * They are the only user-supplied input this page accepts, and they are
 * resolved by MEMBERSHIP against CockpitModulePresenter's own module keys and
 * the TABS constant below — never by validate(), whose redirect-with-error-bag
 * is a write-shaped behaviour on a read-only page. An unrecognised value is a
 * stale bookmark, not an error worth showing a PM: it falls back silently to
 * the closed state and the page still renders 200. `show()` remains the only
 * action, and a `?module=` request is a GET that writes nothing.
 */
class ProjectCockpitController extends Controller
{
    /**
     * The three tabs of the side panel (D-09). Anything else falls back to
     * the first entry.
     *
     * @var array<int, string>
     */
    public const TABS = ['overview', 'files', 'notes'];

    public function __construct(
        private ProjectHealthService $health,
        private CockpitSectionPresenter $sections,
        private CockpitModulePresenter $modulePresenter,
        private CockpitHeaderPresenter $headerPresenter,
    ) {
    }

    public function show(Request $request, Project $project): View
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

        // The spine's own relations. Eager-loaded AFTER assess() so the probe
        // above still proves the health contract, and loaded here rather than
        // derived here — the controller wires, the presenter derives.
        $project->loadMissing([
            'visits',
            'worksheets',
            'installProgrammes',
            'drawings',
            'omManuals',
            'cableSchedules',
            'activityLog',
        ]);

        $sections = $this->sections->sections($project);
        $isEmpty  = $this->sections->isEmpty($project);

        $modules = $this->modulePresenter->modules($project);

        $moduleKey  = $this->resolveModuleKey($request);
        $tab        = $this->resolveTab($request);
        $openModule = $moduleKey === null ? null : $modules->firstWhere('key', $moduleKey);
        $progress   = $moduleKey === null ? null : $this->modulePresenter->progress($project, $moduleKey);

        $masthead   = $this->headerPresenter->masthead($project);
        $kpis       = $this->headerPresenter->kpis($project, $health);
        $stageChips = $this->headerPresenter->stageChips($project);

        return view('projects.cockpit', compact(
            'project',
            'health',
            'sections',
            'isEmpty',
            'modules',
            'masthead',
            'kpis',
            'stageChips',
            'openModule',
            'tab',
            'progress',
        ));
    }

    /**
     * The open module, or null.
     *
     * Whitelisted against CockpitModulePresenter::moduleMap() — the presenter's
     * OWN keys, never a hand-maintained duplicate list, which would drift from
     * the rows actually rendered the first time a module is added. Matching is
     * exact and case-sensitive: `WORKSHEET` is not a module key, so it opens
     * nothing rather than being helpfully corrected.
     */
    private function resolveModuleKey(Request $request): ?string
    {
        $submitted = $request->query('module');

        if (! is_string($submitted)) {
            return null;
        }

        return array_key_exists($submitted, CockpitModulePresenter::moduleMap()) ? $submitted : null;
    }

    /**
     * The active tab. Resolved independently of the module: a `?tab=` with no
     * `?module=` opens nothing, because the panel itself is only rendered when
     * a module resolved.
     */
    private function resolveTab(Request $request): string
    {
        $submitted = $request->query('tab');

        if (! is_string($submitted)) {
            return self::TABS[0];
        }

        return in_array($submitted, self::TABS, true) ? $submitted : self::TABS[0];
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

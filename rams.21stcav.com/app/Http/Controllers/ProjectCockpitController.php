<?php

namespace App\Http\Controllers;

use App\DTO\ProjectHealth;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Services\ProjectHealthService;
use App\Support\Cockpit\CockpitHeaderPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitPanelPresenter;
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

    /**
     * The panel's THIRD piece of URL state (Phase 46, Plan 46-04).
     *
     * `?action=create-visit` discloses the Quick actions form. It is resolved
     * by MEMBERSHIP against this list, exactly as `?module=` and `?tab=` are,
     * so an unrecognised value discloses nothing and is never echoed. This is
     * the only edit Phase 46 makes to this controller, and it ADDS NO WRITE:
     * the cockpit's writes live in ProjectCockpitActionController.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['create-visit'];

    public function __construct(
        private ProjectHealthService $health,
        private CockpitSectionPresenter $sections,
        private CockpitModulePresenter $modulePresenter,
        private CockpitHeaderPresenter $headerPresenter,
        private CockpitPanelPresenter $panelPresenter,
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

        // The panel's three bodies. Derived HERE rather than in Blade, on the
        // same rule as everything else on this page: the controller wires, the
        // presenter derives, the view draws. The feed is project-wide and
        // takes no module — see CockpitPanelPresenter's docblock.
        $panelFiles = $moduleKey === null ? collect() : $this->panelPresenter->files($project, $moduleKey);
        $panelNotes = $moduleKey === null ? collect() : $this->panelPresenter->notes($project, $moduleKey);
        $activity   = $this->panelPresenter->activity($project);

        // Quick actions (Plan 46-04). The form's two option lists are read
        // HERE rather than in Blade, on the same rule as everything else on
        // this page. Both are READS: deriving them writes nothing, which
        // CockpitReadOnlyFenceTest's GET row-count tests still prove.
        $action = $this->resolveAction($request);

        $quickActionRooms  = $action === null ? [] : $this->roomNames($project);
        $quickActionPeople = $action === null ? [] : $this->activePeople();

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
            'panelFiles',
            'panelNotes',
            'activity',
            'action',
            'quickActionRooms',
            'quickActionPeople',
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
     * The disclosed Quick action, or null.
     *
     * Membership again, never validate(): a stale `?action=` bookmark is not an
     * error worth showing a PM, and a redirect-with-error-bag is a write-shaped
     * behaviour that belongs on the POST, not here.
     */
    private function resolveAction(Request $request): ?string
    {
        $submitted = $request->query('action');

        if (! is_string($submitted)) {
            return null;
        }

        return in_array($submitted, self::ACTIONS, true) ? $submitted : null;
    }

    /**
     * The project's survey room names, for the "Rooms in scope" checkboxes
     * (D-05). Read from the live survey the engineer link is built on, so the
     * rooms a PM ticks are rooms that exist.
     *
     * @return array<int, string>
     */
    private function roomNames(Project $project): array
    {
        $survey = SiteSurvey::where('project_id', $project->id)
            ->whereNull('superseded_at')
            ->whereIn('status', ['draft', 'completed'])
            ->with('rooms')
            ->first();

        if ($survey === null) {
            return [];
        }

        return $survey->rooms
            ->pluck('room_name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
    }

    /**
     * ACTIVE labour resources, NAME ONLY (LR-04).
     *
     * The cockpit is staff-auth, so contact details would be sanctioned here —
     * but this control needs only names, so only names are read. Inactive
     * people are not offered: assigning one would be assigning somebody who
     * has left.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function activePeople(): array
    {
        return LabourResource::active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LabourResource $r) => ['id' => $r->id, 'name' => (string) $r->name])
            ->all();
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

<?php

namespace App\Http\Controllers;

use App\DTO\ProjectHealth;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Services\ProjectHealthService;
use App\Support\Cockpit\CockpitEvidencePresenter;
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
     * The tabs of the side panel (D-09). Anything else falls back to the
     * first entry.
     *
     * THE ORDER OF THIS ARRAY IS THE TAB ORDER, and `TABS[0]` is still the
     * fallback — which is why Phase 46.1 inserted `returned` at index 1 (D-01
     * puts it between Overview and Files) without `resolveTab()` changing at
     * all. Everything that ITERATES this constant rather than sampling it —
     * `CockpitReadOnlyFenceTest::everyRegion()`, its two GET row-count tests,
     * `CockpitPageTest::test_every_module_renders_on_every_tab()` — picked the
     * new tab up on the day it was added. That is the point of iterating the
     * constant, and the reason a hand-maintained second list is never written.
     *
     * A tab being IN this list does not mean every module offers it: the
     * Returned tab renders only where the open module's drawer holds a visit
     * with a source (see `panel.blade.php`). Membership here is about what the
     * URL may legally say, not about what a given drawer draws.
     *
     * @var array<int, string>
     */
    public const TABS = ['overview', 'returned', 'files', 'notes'];

    /**
     * The panel's THIRD piece of URL state (Phase 46, Plan 46-04).
     *
     * `?action=create-visit` discloses the Quick actions form. It is resolved
     * by MEMBERSHIP against this list, exactly as `?module=` and `?tab=` are,
     * so an unrecognised value discloses nothing and is never echoed. This is
     * the only edit Phase 46 makes to this controller, and it ADDS NO WRITE:
     * the cockpit's writes live in ProjectCockpitActionController.
     *
     * Plan 46-06 adds `send-back`, which discloses ONE visit row's reason
     * field. That row is named by a fourth piece of URL state, `?visit={id}`,
     * resolved as an INT and only ever COMPARED against the ids already being
     * rendered — it addresses no record and is never echoed, so a hostile
     * value discloses nothing.
     *
     * Plan 46-07 adds `note` and `snag`, which disclose ONE visit row's note
     * field or snag fields. ONLY ONE FITS IN THE URL AT A TIME, and that is a
     * feature: the panel cannot become a wall of open forms, which is the
     * failure the user named when they rejected an earlier design as busy.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['create-visit', 'send-back', 'note', 'snag'];

    public function __construct(
        private ProjectHealthService $health,
        private CockpitSectionPresenter $sections,
        private CockpitModulePresenter $modulePresenter,
        private CockpitHeaderPresenter $headerPresenter,
        private CockpitPanelPresenter $panelPresenter,
        private CockpitEvidencePresenter $evidencePresenter,
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
            // `visits.snags` (Plan 46-07): the visit row renders a plain COUNT
            // of the snags raised against it, so the count is eager-loaded
            // here rather than queried per row inside Blade. It is a READ —
            // the fence's seven-table GET row-count tests still prove it.
            'visits.snags',
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

        // The Returned tab's payload (Phase 46.1, Plan 46.1-03). Keyed by
        // visit id, derived HERE and never in Blade, on the same rule as the
        // three above: the controller wires, the presenter derives, the view
        // draws. Null when no module is open, because the panel that would
        // read it is not rendered.
        $panelEvidence = $openModule === null ? null : $this->evidenceFor($project, $openModule);

        // Quick actions (Plan 46-04). The form's two option lists are read
        // HERE rather than in Blade, on the same rule as everything else on
        // this page. Both are READS: deriving them writes nothing, which
        // CockpitReadOnlyFenceTest's GET row-count tests still prove.
        $action = $this->resolveAction($request);

        // Only the create form needs these two lists, so `?action=send-back`
        // must not pay for a query it never reads.
        $quickActionRooms  = $action === 'create-visit' ? $this->roomNames($project) : [];
        $quickActionPeople = $action === 'create-visit' ? $this->activePeople() : [];

        // The visit row the `send-back` disclosure names. An int or null, and
        // nothing looks it up: the row component COMPARES it against the
        // visits it is already rendering (Plan 46-06).
        $actionVisitId = $this->resolveActionVisitId($request);

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
            'panelEvidence',
            'activity',
            'action',
            'actionVisitId',
            'quickActionRooms',
            'quickActionPeople',
        ));
    }

    /**
     * The evidence behind every SOURCED visit in the open module's drawer,
     * keyed by visit id (Phase 46.1, Plan 46.1-03).
     *
     * A visit with no `source_type` is skipped rather than given an empty
     * entry: nothing was ever issued, so nothing could have come back, and the
     * Returned tab's own presence rule is derived from the same fact.
     *
     * TWO RESHAPES HAPPEN HERE, both deliberate:
     *
     *  1. every photo gains a `url` — the project-scoped GET registered by
     *     Plan 46.1-02. The route is built in PHP, once, rather than in Blade
     *     inside a loop.
     *  2. every photo LOSES its `path`. The view has no use for a storage
     *     path and a page that never receives one cannot print one; the ZIP
     *     builder and the photo route remain the only two places in this phase
     *     where a stored path meets the filesystem (T-46.1-05).
     *
     * `captured_by`, `ip_address` and `user_agent` are absent because
     * `VisitEvidence` never returns them (RV-03). Nothing here reaches past
     * the presenter to a model to get them back, and nothing ever should.
     *
     * @param  array<string, mixed>  $module
     * @return array<int, array<string, mixed>>
     */
    private function evidenceFor(Project $project, array $module): array
    {
        /** @var \Illuminate\Support\Collection<int, \App\Models\Visit> $visits */
        $visits = $module['section']['visits'] ?? collect();

        $evidence = [];

        foreach ($visits as $visit) {
            if ($visit->source_type === null) {
                continue;
            }

            $payload = $this->evidencePresenter->evidence($project, $visit);

            if ($payload === null) {
                continue;
            }

            foreach ($payload['photos_by_bucket'] as $bucket => $photos) {
                $payload['photos_by_bucket'][$bucket] = array_map(
                    function (array $photo) use ($project, $visit): array {
                        $photo['url'] = route('projects.cockpit.visits.photo', [
                            'project' => $project,
                            'visit'   => $visit,
                            'kind'    => $photo['kind'],
                            'photo'   => $photo['id'],
                        ]);

                        unset($photo['path']);

                        return $photo;
                    },
                    $photos
                );
            }

            $payload['serials'] = array_map(
                function (array $serial) use ($project, $visit): array {
                    $serial['url'] = route('projects.cockpit.visits.photo', [
                        'project' => $project,
                        'visit'   => $visit,
                        'kind'    => \App\Support\Cockpit\VisitEvidence::KIND_LABEL,
                        'photo'   => $serial['id'],
                    ]);

                    return $serial;
                },
                $payload['serials']
            );

            $evidence[(int) $visit->id] = $payload;
        }

        return $evidence;
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
     * The visit id the `send-back` disclosure names, or null.
     *
     * CAST, NEVER LOOKED UP. A visit id in a query string must not address a
     * record on a read page: this value is compared against the visits the
     * panel is already rendering, so a foreign or hostile id simply opens
     * nothing. The POST it discloses does its own project-scoped ownership
     * check (T-46-06-01).
     */
    private function resolveActionVisitId(Request $request): ?int
    {
        $submitted = $request->query('visit');

        if (! is_string($submitted) && ! is_int($submitted)) {
            return null;
        }

        return ctype_digit((string) $submitted) ? (int) $submitted : null;
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

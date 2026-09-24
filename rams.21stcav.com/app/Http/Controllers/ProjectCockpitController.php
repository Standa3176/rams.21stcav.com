<?php

namespace App\Http\Controllers;

use App\DTO\ProjectHealth;
use App\Models\LabourResource;
use App\Models\Project;
use App\Services\ProjectHealthService;
use App\Services\RamsReviewDataService;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitHeaderPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitPanelPresenter;
use App\Support\Cockpit\CockpitSectionPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
     * fallback. Everything that ITERATES this constant rather than sampling it
     * — `CockpitReadOnlyFenceTest::everyRegion()`, its two GET row-count tests,
     * `CockpitPageTest::test_every_module_renders_on_every_tab()` — picks a tab
     * change up on the day it lands. That is the point of iterating the
     * constant, and the reason a hand-maintained second list is never written.
     *
     * `returned` WAS AT INDEX 1 AND IS GONE (Phase 46.2, Plan 46.2-03, D-02).
     * The Returned tab is no longer surfaced on the cockpit, so `returned` is
     * no longer a legal thing for the URL to say, and a stale `?tab=returned`
     * bookmark falls back to `overview` HERE — one fallback, in
     * `resolveTab()`. Phase 46.1's second fallback inside `panel.blade.php`
     * (the `$offersReturned` coercion) went with it, so the strip and the body
     * can no longer disagree about which tab is open. The page is still 200 and
     * the submitted string is still never echoed.
     *
     * UNSURFACED, NOT DELETED: the returned-evidence review still exists in
     * full — `App\Support\Cockpit\CockpitEvidencePresenter`,
     * `App\Support\Cockpit\VisitEvidence`, `ProjectCockpitEvidenceController`
     * and the five `ProjectCockpitActionController` POSTs are all untouched and
     * still green at their own routes.
     *
     * @var array<int, string>
     */
    public const TABS = ['overview', 'files', 'notes'];

    /**
     * The panel's THIRD piece of URL state (Phase 46, Plan 46-04) — LIVE AGAIN,
     * with exactly ONE entry (Phase 46.2, Plan 46.2-05).
     *
     * This list held `create-visit`, `send-back`, `note` and `snag`: the four
     * visit disclosures. 46.2 D-02 took all four off the page and Plan 46.2-03
     * emptied the list, KEEPING it dormant on the same reasoning that kept
     * `CockpitModulePresenter::COUNT_NONE` dormant in 46.2-01 — that the
     * document form would need exactly this mechanism one plan later.
     *
     * IT DOES, AND THIS IS IT. `generate` discloses the DOCUMENT form, resolved
     * by MEMBERSHIP against this list and never by validate(), so an
     * unrecognised value discloses nothing, is never echoed, and the page is
     * still 200 — the identical treatment `?module=` and `?tab=` have had since
     * 45-11. `?action=generate` is a GET that writes nothing; the WRITE is
     * `projects.cockpit.documents.store` on its own controller.
     *
     * The four removed strings are NOT deferred capabilities and they do NOT
     * come back here. They exist and they work, at
     * `projects.cockpit.visits.store` / `.send-back` / `.notes` / `.snags`. Only
     * the URL state that DISCLOSED THEIR FORMS is gone, and it stays gone.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['generate'];

    public function __construct(
        private ProjectHealthService $health,
        private CockpitSectionPresenter $sections,
        private CockpitModulePresenter $modulePresenter,
        private CockpitHeaderPresenter $headerPresenter,
        private CockpitPanelPresenter $panelPresenter,
        // Plan 46.2-05. Read-only: `documentFieldMap()` and `fieldsFor()` touch
        // no model at all, and `readiness()` DELEGATES to
        // `OmManualValidationService` rather than forming a second opinion about
        // what is missing, so the panel and the generator cannot disagree.
        private CockpitDocumentFormPresenter $docFormPresenter,
        private RamsReviewDataService $reviewData,
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

        // ── THE DOCUMENT FORM'S FOUR WIRINGS (Phase 46.2, Plan 46.2-05) ────
        //
        // All four are READS and MOVE NO ROW IN ANY TABLE — which is why
        // `CockpitReadOnlyFenceTest::WRITE_SURFACE_TABLES` grows 11 -> 13 in the
        // same commit: `$docValues` reads `project_packages` (the RAMS reviewed
        // payload) and `site_surveys` (the survey's own columns), and
        // `$docResources` reads `labour_resources`. The whole point of that
        // fence is that disclosing this form still writes nothing.
        //
        // Derived HERE and never in Blade, on this page's standing rule: the
        // controller wires, the presenter derives, the view draws. The FIELDS
        // come from `CockpitDocumentFormPresenter` and from nowhere else, so the
        // view decides no field of its own.
        $action       = $this->resolveAction($request);
        $docFields    = $moduleKey === null ? [] : $this->docFormPresenter->fieldsFor($moduleKey);
        $docReadiness = $moduleKey === null ? [] : $this->docFormPresenter->readiness($project, $moduleKey);
        $docFormats   = $moduleKey === null ? [] : $this->documentFormats($moduleKey);
        $docIntro     = $moduleKey === null ? null : $this->documentIntro($moduleKey);
        $docValues    = $moduleKey === null ? [] : $this->documentValues($project, $moduleKey);
        $docResources = $moduleKey === null ? [] : $this->resourceNames();

        // FOUR WIRINGS REMOVED BY 46.2 D-02 (Plan 46.2-03), unsurfaced not
        // deleted — and with them four private helpers:
        //
        //   $panelEvidence     ← evidenceFor()          the Returned tab payload
        //   $action            ← resolveAction()        the four visit disclosures
        //   $quickActionRooms  ← roomNames()            Create visit's room list
        //   $quickActionPeople ← activePeople()         Create visit's people list
        //   $actionVisitId     ← resolveActionVisitId() the send-back row id
        //
        // Nothing behind them was touched. `CockpitEvidencePresenter` and
        // `VisitEvidence` still exist, still have their own green unit tests,
        // and are now ZERO-CALLER SERVICES from the cockpit's side. That is
        // deliberate and it matches this repo's own precedent — SiteSurveyDocxService
        // sat written and tested with no caller until Plan 46.2-02 wired it up.
        // DO NOT delete them to tidy the dependency graph. The five POST routes
        // and the two evidence GETs remain registered and green.
        //
        // The `CockpitEvidencePresenter` constructor injection went with
        // evidenceFor(), its only caller. Re-inject it in the commit that ships
        // a surface that reads it.

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
            'docFields',
            'docReadiness',
            'docFormats',
            'docIntro',
            'docValues',
            'docResources',
        ));
    }

    /*
     * FIVE PRIVATE HELPERS WERE HERE, AND ARE RETIRED BY NAME (46.2 D-02,
     * Plan 46.2-03). Each fed a control the cockpit no longer surfaces. None
     * of the CAPABILITY they fed was deleted:
     *
     *   evidenceFor()           — reshaped CockpitEvidencePresenter's payload
     *                             for the Returned tab. The presenter,
     *                             VisitEvidence, VisitPhotoZipBuilder and
     *                             ProjectCockpitEvidenceController are all
     *                             untouched and still green; the photo and ZIP
     *                             GETs are still registered.
     *   resolveAction()         — resolved `?action=` against ACTIONS, which is
     *                             now empty. Plan 46.2-05 brings this back for
     *                             the document form's `?action=generate`.
     *   resolveActionVisitId()  — cast `?visit=` for the send-back disclosure.
     *                             The POST's own project-scoped ownership check
     *                             (T-46-06-01) was always the real guard and is
     *                             unchanged.
     *   roomNames()             — Create visit's "Rooms in scope" option list.
     *   activePeople()          — Create visit's engineer option list.
     *
     * The acts themselves live at projects.cockpit.visits.store / .accept /
     * .send-back / .notes / .snags and .photos-zip / .photo. If you need one of
     * these helpers back, the surface is what you are adding — write it in the
     * commit that ships the control, do not resurrect the wiring first.
     */

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
     * The panel's disclosed action, or null (Plan 46-04, RESTORED BY 46.2-05).
     *
     * Resolved by MEMBERSHIP against `ACTIONS`, never by validate(): an
     * unrecognised value discloses nothing, is never echoed, and the page is
     * still 200. Matching is exact and case-sensitive — `GENERATE` discloses
     * nothing rather than being helpfully corrected.
     *
     * This is the resolver 46.2-03 retired with the visit disclosures and kept
     * named in its place-holder comment. It is back for ONE action, `generate`,
     * and the four visit strings do not come with it.
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
     * The document's Word and PDF route names, straight off the map — which
     * mirrors `46.2-FORMAT-INVENTORY.md` exactly.
     *
     * A null cell is KEPT AS NULL and travels to the view, because the view's job
     * is to SAY the format is unavailable (46.2 D-05, DC-07). Dropping the key
     * here would turn a stated gap into a silent absence. `Route::has()` guards
     * the survivors on the same terms 45-12's document links use, so a renamed
     * route degrades to one fewer offered format rather than a 500 on a PM's
     * screen.
     *
     * @return array<string, string|null>
     */
    private function documentFormats(string $moduleKey): array
    {
        $formats = CockpitDocumentFormPresenter::documentFieldMap()[$moduleKey]['formats'] ?? [];

        return array_map(
            static fn (?string $routeName): ?string => $routeName !== null && Route::has($routeName) ? $routeName : null,
            $formats,
        );
    }

    private function documentIntro(string $moduleKey): ?string
    {
        return CockpitDocumentFormPresenter::documentFieldMap()[$moduleKey]['intro'] ?? null;
    }

    /**
     * The value each field should show: WHAT IS ALREADY STORED, or failing that
     * what the PROJECT already answers.
     *
     * This is the executable half of "the form never asks a question the project
     * already answers" (DC-11). Resolution order per field:
     *
     *   1. the map's own `target`, read back out of wherever the generator reads
     *      it — so a field the PM filled in last month comes back filled in;
     *   2. the map's `prefill`, a named path on the project;
     *   3. ''.
     *
     * A `project.` target is READ from the `Project` model when that attribute
     * exists and from the reviewed payload's `project` section otherwise. The
     * prefix is overloaded in the map for a measured reason: the O&M's
     * `project.handover_date` IS a Project column, while the RAMS'
     * `project.project_name` is a key of `normaliseProject()`. Resolving by
     * attribute existence keeps that a data question rather than a per-document
     * branch. Only `handover_date` is WRITABLE among them — every other
     * `project.` field carries `prohibited`.
     *
     * READ-ONLY, ALL OF IT. This method is the reason `WRITE_SURFACE_TABLES`
     * grows to name `project_packages` and `site_surveys`.
     *
     * @return array<string, mixed>
     */
    private function documentValues(Project $project, string $moduleKey): array
    {
        $survey   = $project->siteSurveys
            ->whereNull('superseded_at')
            ->whereIn('status', ['draft', 'completed'])
            ->sortByDesc('id')
            ->first();
        $reviewed = $this->reviewData->normalise($project->latestPackage?->extracted_data ?? []);

        $fromProject = [
            'project.name'          => (string) ($project->name ?? ''),
            'project.ref'           => (string) ($project->ref ?? ''),
            'project.client_name'   => (string) ($project->client_name ?? ''),
            'project.site_address'  => (string) ($project->site_address ?? ''),
            'project.handover_date' => $project->handover_date?->format('Y-m-d') ?? '',
            'project.owner_name'    => (string) ($project->owner?->name ?? ''),
        ];

        $values = [];

        foreach ($this->docFormPresenter->fieldsFor($moduleKey) as $group) {
            foreach ($group['fields'] as $field) {
                [$prefix, $leaf] = explode('.', $field['target'], 2);

                $stored = match ($prefix) {
                    'survey'                     => $survey?->{$leaf},
                    'programme', 'site_logistics' => $reviewed[$prefix][$leaf] ?? null,
                    'project'                    => $project->getAttribute($leaf) ?? ($reviewed['project'][$leaf] ?? null),
                    // `query`, `form_data`, `worksheet` and `om_context` have no
                    // readable home before the document exists. Named rather
                    // than defaulted, so a new prefix is a loud failure.
                    'query', 'form_data', 'worksheet', 'om_context' => null,
                };

                if ($stored instanceof \DateTimeInterface) {
                    $stored = $stored->format('Y-m-d');
                }

                $blank = $stored === null || $stored === '' || $stored === [];

                $values[$field['key']] = $blank
                    ? ($fromProject[$field['prefill'] ?? ''] ?? '')
                    : $stored;
            }
        }

        return $values;
    }

    /**
     * The `LabourResource` NAMES a `resource-list` field may offer, keyed by the
     * map's own prefill constant.
     *
     * LR-04, STRUCTURALLY: this method selects `name` and nothing else, so an
     * engineer's email or phone cannot reach the page even by a later author
     * forgetting to strip it. There is nothing to strip. The list is a
     * convenience source and not a foreign key — the selected NAME is what the
     * RAMS stores, because `lead_engineer_name` is a string column and
     * `additional_engineers` a string array (T-46.2-11, accepted).
     *
     * @return array<string, array<int, string>>
     */
    private function resourceNames(): array
    {
        $names = [];

        foreach (CockpitDocumentFormPresenter::resourceListRoles() as $prefill => $role) {
            $names[$prefill] = LabourResource::query()
                ->active()
                ->whereJsonContains('roles', $role)
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        return $names;
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

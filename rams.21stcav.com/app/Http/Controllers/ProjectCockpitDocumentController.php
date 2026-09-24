<?php

namespace App\Http\Controllers;

use App\Http\Requests\CockpitDocumentRequest;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Services\RamsReviewDataService;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use Illuminate\Http\RedirectResponse;

/**
 * ProjectCockpitDocumentController — the cockpit's SIXTH write, and the only
 * one that produces a document (Phase 46.2, Plan 46.2-05; DC-05/DC-06/DC-11).
 *
 * ── IT WRITES NO GENERATOR AND RENDERS NO DOCUMENT ────────────────────────
 *
 * 46.2 D-04 forbids a second renderer. This class does exactly three things:
 *
 *   1. VALIDATES, from `DOCUMENT_FIELD_MAP` via `CockpitDocumentRequest`.
 *   2. PERSISTS the entered values WHERE THE GENERATOR ALREADY READS THEM.
 *   3. DELEGATES to the generate entry point that already exists, and hands
 *      back that controller's own response.
 *
 * It dispatches no job, opens no PhpWord, touches no `filename` and touches no
 * `status`. If a document ever appears to need a new render path, that is DC-07's
 * situation and the answer is to REPORT it, as the worksheet PDF gap is reported
 * on the panel — never to build one here.
 *
 * ── A FOURTH CONTROLLER, ON PURPOSE ───────────────────────────────────────
 *
 * `ProjectCockpitController` is the READ surface and its docblock forbids a POST
 * reaching it. `ProjectCockpitActionController` owns the five visit acts, which
 * 46.2 D-02 unsurfaced and this plan must leave byte-identical.
 * `ProjectCockpitEvidenceController` serves the two evidence GETs. So the
 * document write lands here, and `CockpitPageTest`'s route loop names this class
 * explicitly rather than being relaxed to "any controller".
 *
 * ── WHERE EACH DOCUMENT'S VALUES GO (the map's `target` prefix decides) ────
 *
 *   survey.*         → columns on the project's ACTIVE `SiteSurvey` row
 *   project.*        → a column on the `Project` (the O&M's handover date)
 *   programme.*      ┐ merged into `ProjectPackage::extracted_data` and passed
 *   site_logistics.* ┘ back through `RamsReviewDataService::normalise()`, so the
 *                      saved shape IS the shape `RamsController::generateFromProject`
 *                      re-reads. Never a key `normalise()` does not emit.
 *   form_data.*      → the ONE documented exception (the map's `working_hours`).
 *                      Its home is a column on the `RamsDocument`, which does not
 *                      exist until the generator creates it, so it is patched on
 *                      immediately AFTER the delegation — see `patchFormData()`.
 *   query.*          → travels in the request to the existing controller, which
 *                      persists it itself (the O&M's `draft` flag).
 *   worksheet.* / om_context.* → display-only in the map (`prohibited`), so
 *                      nothing is ever submitted for them and nothing is written.
 *
 * ── THE MERGE IS NON-DESTRUCTIVE, AND THAT IS NOT A DETAIL ────────────────
 *
 * `RamsReviewDataService::normalise()` returns a FIXED thirteen-key array. Saving
 * its output straight over `extracted_data` would DELETE every sibling key the
 * QuoteWerks import and the review form put there — `overview`, `equipment_list`
 * and the rest. So the two sections this form owns are normalised and merged back
 * into the existing payload; everything else is left exactly as it was.
 * `CockpitDocumentFormTest` asserts the surviving sibling by name.
 *
 * ── EVERY LOOKUP IS SCOPED TO THE ROUTE-BOUND `{project}` (T-46.2-13) ─────
 *
 * The survey, the package and the document are all reached THROUGH `$project`.
 * No id from the request ever selects a row, and there is no id field in the
 * form to send one — asserted with a second project's survey id in the payload.
 *
 * ── THE PREREQUISITE PATHS ARE THE EXISTING CONTROLLERS' OWN ──────────────
 *
 * There is no second copy of "is the package reviewed" or "which O&M fields are
 * missing" here. `RamsController::generateFromProject` already redirects to the
 * review page and `OmManualController::generateFromProject` already returns
 * `withErrors(..., 'om_generate')`; both responses are handed back unchanged
 * except for their redirect TARGET, which is re-pointed at the panel the PM was
 * looking at (see `retarget()`). Two copies of a rule are two rules.
 */
final class ProjectCockpitDocumentController extends Controller
{
    public function __construct(
        private RamsReviewDataService $reviewData,
    ) {
    }

    public function store(CockpitDocumentRequest $request, Project $project): RedirectResponse
    {
        // Flag-gated INSIDE the controller, exactly as the cockpit GET and the
        // five visit POSTs are, so `route()` keeps resolving with the flag off.
        abort_unless(config('cockpit.enabled'), 404);
        abort_unless(auth()->check(), 403);

        $validated = $request->validated();
        $module    = (string) $validated['module'];
        $format    = (string) $validated['format'];

        // THE SITE SURVEY IS THE ONE DOCUMENT WHOSE ROW MUST EXIST BEFORE ITS
        // COLUMNS CAN BE WRITTEN, because its "generate" IS the row's creation
        // (`site-surveys.from-project` -> `SurveyService::createFromProject`).
        // Every other document is a header row plus a queued build, so their
        // values live somewhere that already exists. Ordering is therefore
        // per-document and is stated here rather than hidden in a helper.
        if ($module === ProjectDeliverable::KEY_SITE_SURVEY) {
            $created = $this->ensureSurvey($project);

            $this->persist($project, $module, $validated);

            return $this->retarget($request, $created, $project, $module, $format);
        }

        $this->persist($project, $module, $validated);

        $before   = $this->latestRamsId($project);
        $response = $this->delegate($request, $project, $module);

        $this->patchFormData($project, $module, $validated, $before);

        return $this->retarget($request, $response, $project, $module, $format);
    }

    // ── Persistence ─────────────────────────────────────────────────────────

    /**
     * Group the submitted values by the map's target prefix, then write each
     * bucket once.
     *
     * A field absent from the payload is absent from the bucket, so an untouched
     * field is never overwritten with ''. A display-only field can never be here
     * at all: `prohibited` rejected it before this method ran.
     *
     * @param  array<string, mixed>  $validated
     */
    private function persist(Project $project, string $module, array $validated): void
    {
        $buckets = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (! array_key_exists($field['key'], $validated)) {
                    continue;
                }

                [$prefix, $leaf] = explode('.', $field['target'], 2);

                $buckets[$prefix][$leaf] = $validated[$field['key']];
            }
        }

        foreach ($buckets as $prefix => $pairs) {
            match ($prefix) {
                'survey'         => $this->persistSurvey($project, $pairs),
                'project'        => $this->persistProject($project, $pairs),
                'programme',
                'site_logistics' => $this->persistReviewed($project, $prefix, $pairs),
                // `form_data` is patched after the delegation (its row does not
                // exist yet) and `query` travels in the request. Named rather
                // than swept into a default arm, so a NEW prefix with no home is
                // a loud failure instead of a value that vanishes.
                'form_data'      => null,
                'query'          => null,
            };
        }
    }

    /** @param  array<string, mixed>  $pairs */
    private function persistSurvey(Project $project, array $pairs): void
    {
        $survey = $this->activeSurvey($project);

        // No active survey means the delegation did not create one (the existing
        // controller's own guard fired). Nothing to write, and nothing invented.
        $survey?->update($pairs);
    }

    /** @param  array<string, mixed>  $pairs */
    private function persistProject(Project $project, array $pairs): void
    {
        $project->update($pairs);
    }

    /**
     * The RAMS round trip: merge, normalise, save — never save-then-hope.
     *
     * @param  array<string, mixed>  $pairs
     */
    private function persistReviewed(Project $project, string $section, array $pairs): void
    {
        $package = $project->latestPackage;

        if ($package === null) {
            // `RamsController::generateFromProject` reports this itself, in its
            // own words ("No quote data found for this project"). There is no
            // second copy of the message here.
            return;
        }

        $data = $package->extracted_data ?? [];

        $data[$section] = array_merge(
            is_array($data[$section] ?? null) ? $data[$section] : [],
            $pairs,
        );

        // Pass the WHOLE payload through the normaliser and take back only the
        // section this write owns, so the saved shape is the shape the generator
        // re-reads and every sibling key survives.
        $normalised = $this->reviewData->normalise($data);

        $data[$section] = $normalised[$section];

        $package->update(['extracted_data' => $data]);
    }

    /**
     * `form_data.working_hours` — the map's single documented exception to the
     * normalised-key rule (46.2-04, D-46.2-04-03).
     *
     * `normaliseProject()` is exactly eleven keys and `working_hours` is not one,
     * so there is no `reviewed_data` home for it that survives `normalise()`.
     * What the RAMS cover and Section 4 actually render is
     * `form_data['working_hours']` on the `RamsDocument` — a row the generator
     * creates, so this is the one value that can only be written AFTER the
     * delegation. Scoped to the document this request created (`id > $before`),
     * so a re-submission never edits an earlier RAMS.
     *
     * @param  array<string, mixed>  $validated
     */
    private function patchFormData(Project $project, string $module, array $validated, ?int $before): void
    {
        $pairs = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (! str_starts_with($field['target'], 'form_data.')) {
                    continue;
                }

                if (! array_key_exists($field['key'], $validated)) {
                    continue;
                }

                $pairs[substr($field['target'], strlen('form_data.'))] = $validated[$field['key']];
            }
        }

        if ($pairs === []) {
            return;
        }

        $document = $project->ramsDocuments()
            ->when($before !== null, fn ($query) => $query->where('id', '>', $before))
            ->orderByDesc('id')
            ->first();

        if ($document === null) {
            return;
        }

        // `form_data` ONLY. `filename` and `status` belong to the generator and
        // are never touched from this page.
        $document->update(['form_data' => array_merge($document->form_data ?? [], $pairs)]);
    }

    // ── Delegation ──────────────────────────────────────────────────────────

    /**
     * The four EXISTING generate entry points, resolved out of the container and
     * called directly so each one's own flash message survives.
     *
     * No default arm: `module` was validated against the map's keys, and a fifth
     * document added to the map without an entry here must fail loudly rather
     * than silently generate nothing.
     */
    private function delegate(CockpitDocumentRequest $request, Project $project, string $module): RedirectResponse
    {
        return match ($module) {
            ProjectDeliverable::KEY_RAMS      => app(RamsController::class)->generateFromProject($project),
            ProjectDeliverable::KEY_WORKSHEET => app(WorksheetController::class)->generateFromProject($project),
            // The only entry point that takes the request: it reads
            // `boolean('draft')`, which is the map's `query.draft` field.
            ProjectDeliverable::KEY_OM        => app(OmManualController::class)->generateFromProject($request, $project),
        };
    }

    /**
     * The survey's "generate" is its creation, and it is IDEMPOTENT here.
     *
     * `SurveyService::createFromProject()` THROWS when an active survey already
     * exists ("Use supersede flag to replace it"), and superseding is a different
     * act with its own route — one this panel does not offer. So: create only
     * when there is none, and otherwise treat the existing row as the thing the
     * PM is filling in. Either way the columns are written next.
     */
    private function ensureSurvey(Project $project): ?RedirectResponse
    {
        if ($this->activeSurvey($project) !== null) {
            return null;
        }

        $response = app(SiteSurveyController::class)->createFromProject($project);

        // `createFromProject` renders the supersede form when an active survey
        // exists — unreachable here, because the guard above already returned.
        // Guarded rather than assumed: a non-redirect is handed back as "no
        // redirect of our own", never cast.
        return $response instanceof RedirectResponse ? $response : null;
    }

    // ── Response ────────────────────────────────────────────────────────────

    /**
     * Hand back the delegated controller's response, re-pointed at the panel.
     *
     * THE ERROR PATHS ARE LEFT ALONE. A delegate that redirected somewhere
     * specific — `RamsController` sending the PM to the package review page — is
     * returned untouched, because that page IS the fix for what went wrong. Only
     * a `back()` is re-pointed, and it is re-pointed to:
     *
     *   the FORM, when the session carries an error, so the message lands beside
     *   the fields that produced it; or
     *   the FILES tab, when it did not, because that is where the document the
     *   PM just asked for will appear.
     *
     * The chosen `format` is named in a flash of our own. It cannot be handed
     * back as bytes: all four generators QUEUE their build (`BuildRamsDocumentJob`,
     * `BuildWorksheetJob`, `BuildOmManualJob`) or hand off to a confirm step, so
     * there is nothing to stream at the moment of the POST. Saying which format
     * was asked for, and validating it against the inventory, is what this page
     * can honestly do — and it is what keeps `worksheet.pdf` refused in two
     * places instead of one.
     */
    private function retarget(
        CockpitDocumentRequest $request,
        ?RedirectResponse $response,
        Project $project,
        string $module,
        string $format,
    ): RedirectResponse {
        $failed = $this->sessionReportsFailure($request);

        if ($response !== null) {
            $previous = url()->previous();

            if ($response->getTargetUrl() !== $previous) {
                return $response;
            }
        }

        if (! $failed) {
            $request->session()->flash(
                'cockpit_document_format',
                $format === 'pdf' ? 'PDF' : 'Word',
            );
        }

        return redirect()->to($this->panelUrl($project, $module, $failed, $request->input('tab')));
    }

    private function sessionReportsFailure(CockpitDocumentRequest $request): bool
    {
        $session = $request->session();

        if ($session->get('error') !== null) {
            return true;
        }

        $bag = $session->get('errors');

        return $bag !== null && method_exists($bag, 'any') && $bag->any();
    }

    /**
     * WHERE THE PM LANDS, AND WHY THE TWO OUTCOMES DIFFER.
     *
     * FAILURE goes back to the TAB THE FORM WAS OPENED FROM, with
     * `?action=generate` so the form is re-disclosed and the message lands beside
     * the fields that produced it. That tab arrives in the hidden `tab` field —
     * the same `x-cockpit.tab-field` component and the same `TABS` membership
     * resolution the four visit acts use, so the two mechanisms cannot drift. An
     * unreal value was already dropped by the request, so `TABS[0]` is the
     * fallback and nothing submitted is ever reflected.
     *
     * SUCCESS goes to the FILES TAB, deliberately NOT to the tab the PM came
     * from: that is where the document they just asked for appears, and returning
     * them to an unchanged Overview would be a redirect that shows nothing
     * happened.
     */
    private function panelUrl(Project $project, string $module, bool $failed, mixed $submittedTab): string
    {
        $tab = is_string($submittedTab) && in_array($submittedTab, ProjectCockpitController::TABS, true)
            ? $submittedTab
            : ProjectCockpitController::TABS[0];

        return route('projects.cockpit', array_filter([
            'project' => $project->getKey(),
            'module'  => $module,
            'tab'     => $failed ? $tab : 'files',
            'action'  => $failed ? 'generate' : null,
        ], static fn ($value): bool => $value !== null));
    }

    // ── Project-scoped reads ────────────────────────────────────────────────

    /**
     * The project's active survey — the same predicate
     * `SiteSurveyController::createFromProject` and `SurveyService` use, so the
     * panel and the creator can never disagree about whether one exists.
     */
    private function activeSurvey(Project $project): ?SiteSurvey
    {
        return $project->siteSurveys()
            ->whereNull('superseded_at')
            ->whereIn('status', ['draft', 'completed'])
            ->orderByDesc('id')
            ->first();
    }

    private function latestRamsId(Project $project): ?int
    {
        $id = $project->ramsDocuments()->max('id');

        return $id === null ? null : (int) $id;
    }
}

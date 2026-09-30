<?php

namespace App\Http\Controllers;

use App\Http\Requests\CockpitDocumentRequest;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Services\RamsReviewDataService;
use App\Support\Cockpit\CockpitCombinedCreator;
use App\Support\Cockpit\CockpitCreationOutcome;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Http\RedirectResponse;
use Throwable;

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
        private CockpitWizardPresenter $wizard,
        private CockpitCombinedCreator $creator,
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

        // ── THE BRANCH THAT MUST COME FIRST (Plan 46.5-04, GCW-03) ──────────
        //
        // BEFORE `ensureSurvey()`, BEFORE `persist()` and BEFORE `delegate()`.
        // A step advance is not a creation with fewer fields; it is a different
        // act that touches no model at all, and putting the branch anywhere
        // below this line would mean a half-finished wizard had already written
        // something by the time it was recognised.
        // ── QUICK TASK 260930-qcy, TASK 3: THE DOCUMENT-ONLY REGENERATE ─────
        //
        // Checked BEFORE the advance/create branch below, and site-survey-only
        // (enforced inside `regenerateDocumentOnly()`): the worksheet already
        // has its own no-visit regenerate at `worksheets.retry-generation`, and
        // RAMS/the O&M are already document-only via `delegate()` below.
        if ($request->intent() === CockpitDocumentRequest::INTENT_REGENERATE) {
            return $this->regenerateDocumentOnly($request, $project, $module, $validated);
        }

        if ($request->intent() !== CockpitDocumentRequest::INTENT_CREATE) {
            return $this->advance($request, $project, $module);
        }

        $format = (string) $validated['format'];

        // THE SITE SURVEY IS THE ONE DOCUMENT WHOSE ROW MUST EXIST BEFORE ITS
        // COLUMNS CAN BE WRITTEN, because its "generate" IS the row's creation
        // (`site-surveys.from-project` -> `SurveyService::createFromProject`).
        // Every other document is a header row plus a queued build, so their
        // values live somewhere that already exists. Ordering is therefore
        // per-document and is stated here rather than hidden in a helper.
        // ── D-07: ONE CREATION, ONE OUTCOME (Plan 46.5-06) ─────────────────
        //
        // The two modules that HAVE an engineer link go through
        // `CockpitCombinedCreator`, which owns the per-module ordering and runs
        // the document, the fields, the visit and the link in ONE transaction.
        // RAMS and the O&M keep the path below EXACTLY as it was: they issue no
        // link of their own (D-04), and an install's link is the install's.
        //
        // THE PERSISTENCE STAYS HERE. The creator receives it as a closure, so
        // there is still one place that knows where a value goes and one place
        // that knows when it goes there.
        if (CockpitCombinedCreator::handles($module)) {
            return $this->createCombined($request, $project, $module, $validated, $format);
        }

        $this->persist($project, $module, $validated);

        $before   = $this->latestRamsId($project);
        $response = $this->delegate($request, $project, $module);

        $this->patchFormData($project, $module, $validated, $before);

        return $this->retarget($request, $response, $project, $module, $format);
    }

    // ── The combined creation (Plan 46.5-06, D-07) ──────────────────────────

    /**
     * ONE POST -> the document, the visit and the engineer link, or NOTHING.
     *
     * THE FAILURE SHAPE IS `storeVisit()`'S OWN, deliberately: catch
     * `Throwable`, `report($e)` so the detail reaches the log, and hand the PM
     * back their input with a message that names what happened. No exception
     * text and no submitted value reaches the screen (T-46.5-06-07).
     *
     * ⚠ THE MESSAGE NAMES WHICH HALF HAPPENED, and it is not one generic
     * sentence. It is built from `CockpitCreationOutcome`, which knows whether
     * the survey the PM can still see is one this request ADOPTED — and
     * therefore left exactly as it was — or one that never existed at all. A
     * rollback undoes what it wrote and nothing else; saying "nothing happened"
     * about a survey that is still on the project would be false.
     *
     * ⚠ SUCCESS IS NEVER FLASHED FOR A HALF-RUN. The only way to obtain a
     * success sentence is `successMessage()`, which returns NULL whenever
     * `isComplete()` is false — so a caller that forgets the check gets nothing
     * to flash rather than a lie.
     *
     * @param  array<string, mixed>  $validated
     */
    private function createCombined(
        CockpitDocumentRequest $request,
        Project $project,
        string $module,
        array $validated,
        string $format,
    ): RedirectResponse {
        // ASKED BEFORE THE TRANSACTION OPENS, because afterwards a rollback has
        // erased the difference between "adopted" and "never existed".
        $preExisting = $this->creator->documentPreExists($project, $module);

        // ── QUICK TASK 260930-qcy: THE PERMANENT COLLISION, REFUSED BEFORE IT
        //    CAN REACH THE DATABASE ────────────────────────────────────────
        //
        // A naive retry on a project with a live survey ADOPTS the survey
        // (safe) and then tries to create a SECOND visit against the same
        // `(source_type, source_id)` an EARLIER visit already claims — the
        // exact shape `visits_source_unique` refuses with `SQLSTATE[23000]`
        // on live. That is not a transient failure the PM can retry away: the
        // survey and its visit are BOTH still there, so the message states
        // the true, permanent reason and hands back the existing engineer
        // link rather than saying "try again".
        if ($module === ProjectDeliverable::KEY_SITE_SURVEY) {
            $claimingVisit = $this->creator->visitAlreadyClaimsSurvey($project);

            if ($claimingVisit !== null) {
                $request->session()->flash(
                    'cockpit_existing_link',
                    $this->creator->liveSurvey($project)?->publicUrl(),
                );

                return back()->withInput()->withErrors(['module' =>
                    'This project already has a survey visit and an engineer link. '
                    .'You can regenerate the document without a new visit, or start a genuinely fresh survey.'
                ]);
            }
        }

        try {
            $outcome = $this->creator->create(
                $project,
                $module,
                $validated,
                $request->user(),
                // THE DOCUMENT. `ensureSurvey()` is IDEMPOTENT and unchanged —
                // it creates only when there is none, so the issuer's
                // `surveyFor()` then ADOPTS the very row created here and there
                // is never a second survey. Returns TRUE when this request
                // created it.
                function () use ($project): bool {
                    if ($this->activeSurvey($project) !== null) {
                        return false;
                    }

                    $this->ensureSurvey($project);

                    return true;
                },
                fn () => $this->persist($project, $module, $validated),
            );
        } catch (Throwable $e) {
            report($e);

            $failed = CockpitCreationOutcome::rolledBack($preExisting);

            return back()->withInput()->withErrors(['module' => $failed->sentence()]);
        }

        $success = $outcome->successMessage();

        if ($success === null) {
            // UNREACHABLE TODAY — `create()` either completes or throws — and
            // guarded anyway, because "unreachable" is what every half-run
            // looked like before it happened.
            return back()->withInput()->withErrors(['module' => $outcome->sentence()]);
        }

        $request->session()->flash('cockpit_document_format', $format === 'pdf' ? 'PDF' : 'Word');
        $request->session()->flash('success', $success);

        return redirect()->to($this->panelUrl($project, $module, false, $request->input('tab')));
    }

    // ── The document-only regenerate (quick task 260930-qcy) ────────────────

    /**
     * UPDATE THE SURVEY'S ANSWERED FIELDS AND REBUILD ITS DOCUMENT. NO VISIT,
     * NO LINK, EVER.
     *
     * This is the action a PM was actually reaching for when a pre-existing
     * survey visit made a plain retry collide (Task 1's refusal): they wanted
     * to update what they had already answered and get a fresh Word/PDF, not
     * a second visit. `CockpitCombinedCreator` — and therefore
     * `VisitLinkIssuer::issue()` — is never called from this path.
     *
     * SITE-SURVEY-ONLY. The worksheet already has its own document-only,
     * no-new-visit regenerate at `worksheets.retry-generation`
     * (`WorksheetController::retryGeneration()`, re-dispatches
     * `BuildWorksheetJob` against the EXISTING row), and RAMS/the O&M are
     * already document-only via the untouched `delegate()` path (D-04). A
     * hand-crafted `module=worksheet&intent=regenerate-document` gets a 404,
     * never a silent no-op that could be mistaken for success.
     *
     * @param  array<string, mixed>  $validated
     */
    private function regenerateDocumentOnly(
        CockpitDocumentRequest $request,
        Project $project,
        string $module,
        array $validated,
    ): RedirectResponse {
        abort_unless($module === ProjectDeliverable::KEY_SITE_SURVEY, 404);

        if ($this->activeSurvey($project) === null) {
            return back()->withInput()->withErrors([
                'module' => 'There is no survey yet for this project — generate one first.',
            ]);
        }

        $this->persist($project, $module, $validated);

        $request->session()->flash('success', 'The document was updated.');

        return redirect()->to($this->panelUrl($project, $module, false, $request->input('tab')));
    }

    // ── The step advance: A WRITE ROUTE THAT WRITES NOTHING ─────────────────

    /**
     * MOVE THE WIZARD ON ONE STEP, AND PERSIST NOTHING WHATSOEVER.
     *
     * This method writes no row, creates no model, updates no column, dispatches
     * no job, queues no build, touches no file and logs no activity. It reads
     * the document's step list, adds or subtracts one, and returns a redirect
     * carrying the submitted values in the session flash. That is the whole of
     * it, and the emptiness is the POINT rather than an omission.
     *
     * ── WHY (46.5 D-02, requirement GCW-03; DECIDED, NOT LEFT TO CHANCE) ────
     *
     * A HALF-FINISHED WIZARD MUST NOT CREATE A HALF-FINISHED RECORD. NOTHING IS
     * PERSISTED UNTIL THE FINAL STEP. THERE IS NO DRAFT, and no resumable state
     * beyond the session flash.
     *
     * AN ENGINEER LINK IS THEREFORE NEVER ISSUED AGAINST A SURVEY WHOSE SPACES
     * WERE NOT CONFIRMED, BECAUSE UNTIL THE FINAL SUBMIT THERE IS NO SURVEY.
     * That is the named worst case in D-02 and this is the design that makes it
     * unreachable rather than merely unlikely.
     *
     * A resumable draft was the alternative and was rejected: a draft IS a
     * half-finished record — the exact thing D-02 forbids — and it would need a
     * table, a cleanup job and a rule about when a draft goes stale. The session
     * flash needs none of the three and forgets an abandoned wizard by itself.
     *
     * THE COST, STATED: close the tab on step 2 and the answers are gone. That
     * was accepted at plan time. Losing two short steps of typing is a smaller
     * harm than an engineer arriving on site against a survey nobody finished.
     *
     * `CockpitWizardTest::test_a_step_advance_writes_no_row_in_any_of_the_six_tables()`
     * holds this to row counts across `site_surveys`, `visits`, `worksheets`,
     * `rams_documents`, `project_activity_logs` and `project_packages`, so a
     * later change that gives this method a model call goes RED rather than
     * quietly leaving an orphan behind every abandoned wizard.
     *
     * NO ROUTE OF ITS OWN. This is the same POST the creation uses, told apart
     * by `intent`. A second route would move `CockpitPageTest`'s exact 6/3
     * counts, and that red is correct behaviour rather than a number to update.
     */
    private function advance(CockpitDocumentRequest $request, Project $project, string $module): RedirectResponse
    {
        $steps = $this->wizard->stepsFor($module);
        $step  = $request->step();

        // CLAMPED TO THE DOCUMENT'S OWN LIST, so `next` on the last step and
        // `back` on the first stay where they are rather than resolving to a
        // step the document does not have. The list is the map's, never a range
        // assumed here.
        $index  = array_search($step, $steps, true);
        $index  = $index === false ? 0 : $index;

        // ── THE SPACE INTENTS STAY ON THIS STEP (quick task 260930-sv2) ─────
        //
        // `spaces-all` and `spaces-none` are not navigation. They re-render the
        // step the PM is ALREADY on with the tick state rewritten, which is why
        // they resolve to `$index` rather than to `$index ± 1`.
        //
        // THE REWRITE IS DONE BY WHAT IS FLASHED, NOT BY A STORED VALUE. The
        // blade reads `old('visit_rooms')`: absent means "not answered yet, so
        // default all" (D-02, unchanged), and an EMPTY ARRAY means "answered,
        // and the answer is none". So `spaces-all` DROPS the key to fall back
        // onto the existing default, and `spaces-none` flashes `[]`. No new
        // branch was needed in the view and D-02's default is still a render
        // decision rather than a persisted one.
        //
        // EVERY OTHER ANSWER SURVIVES because the rest of the payload is
        // flashed untouched — the hidden inputs that carry earlier steps
        // forward included. That is the whole reason this is a POST intent and
        // not the `?spaces=none` LINK it looks like it could be: a GET would
        // arrive with no payload at all and silently wipe steps 1 and 2.
        if (in_array($request->intent(), CockpitDocumentRequest::SPACE_INTENTS, true)) {
            $input = $request->except(['_token', 'intent']);

            if ($request->intent() === 'spaces-none') {
                $input['visit_rooms'] = [];
            } else {
                unset($input['visit_rooms']);
            }

            return redirect()
                ->to($this->wizardUrl($project, $module, $step, $request->input('tab')))
                ->withInput($input);
        }

        $target = $request->intent() === 'next' ? $index + 1 : $index - 1;
        $target = max(0, min($target, max(0, count($steps) - 1)));

        return redirect()
            ->to($this->wizardUrl($project, $module, $steps[$target] ?? $step, $request->input('tab')))
            ->withInput();
    }

    /**
     * The URL of one step of the open wizard.
     *
     * It sits beside `panelUrl()` and shares its tab resolution
     * (`resolveTab()`), so the two cannot drift about which tab is legal or
     * what an unreal one falls back to. `action=generate` is always present:
     * an advance lands the PM back INSIDE the form they are filling in, never
     * on the closed control.
     */
    private function wizardUrl(Project $project, string $module, int $step, mixed $submittedTab): string
    {
        return route('projects.cockpit', [
            'project' => $project->getKey(),
            'module'  => $module,
            'tab'     => $this->resolveTab($submittedTab),
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    /**
     * ONE definition of "which tab is legal", used by both URL builders.
     *
     * An unreal value was already dropped by the request, so this is the second
     * of two membership checks and `TABS[0]` is the fallback. Nothing submitted
     * is ever reflected.
     */
    private function resolveTab(mixed $submittedTab): string
    {
        return is_string($submittedTab) && in_array($submittedTab, ProjectCockpitController::TABS, true)
            ? $submittedTab
            : ProjectCockpitController::TABS[0];
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

                // ONE ANSWER MAY HAVE TWO HOMES. `also_target` exists for
                // exactly one row today — the site survey's `Visit date`, which
                // writes BOTH `visit.scheduled_date` and `survey.survey_date`
                // because the two were the same day and asking twice confused
                // the PM. It is read here, through the SAME bucketing every
                // other target uses, so a second target cannot reach a
                // different persister than a first one would.
                foreach ([$field['target'], $field['also_target'] ?? null] as $target) {
                    if ($target === null) {
                        continue;
                    }

                    [$prefix, $leaf] = explode('.', $target, 2);

                    $buckets[$prefix][$leaf] = $validated[$field['key']];
                }
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
                // `visit.*` is NOT a document value. It belongs to the Visit
                // `CockpitCombinedCreator` creates alongside the document
                // (Plan 46.5-06), which reads it off the same map by TARGET.
                // Named rather than swept into a default arm, so a new prefix
                // with no home is still a loud failure.
                'visit'          => null,
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
        $tab = $this->resolveTab($submittedTab);

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

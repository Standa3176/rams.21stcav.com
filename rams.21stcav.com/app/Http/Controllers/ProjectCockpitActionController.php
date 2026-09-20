<?php

namespace App\Http\Controllers;

use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\Visit;
use App\Support\Visits\VisitLinkIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * ProjectCockpitActionController — the cockpit's ONE write controller
 * (Phase 46, Plan 46-04).
 *
 * WHY A SECOND CONTROLLER. `ProjectCockpitController`'s docblock says it
 * exposes exactly one action and that "the next phase that needs a write owns
 * its own controller". This is that controller. No POST points at the read
 * one, and the forbidding is the point: a reader can still tell, from the
 * class alone, that rendering the cockpit writes nothing.
 *
 * EVERY ACTION IS A PLAIN FORM POST. There is no JSON endpoint here and no
 * JavaScript anywhere in the cockpit region, because
 * CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES still bans all nine
 * handler attributes — Phase 46 considered retiring that ban and declined.
 * What the query-string pattern buys is bookmarkable panel state, a working
 * back button, and a page that still works with JavaScript off, on a phone, in
 * a plant room. So the disclosure of this form is `&action=create-visit` on the
 * module's own URL and the submit is a real POST through the `web` middleware
 * group, which is what applies session CSRF (T-46-04-01).
 *
 * WHO MAY CREATE: ANY AUTHENTICATED STAFF USER. This is the app's documented
 * shared-workspace convention — every controller in this codebase carries
 * `abort_unless(auth()->check(), 403)` and there is no role model beyond
 * `EnsureUserIsAdmin`. 46-CONTEXT.md leaves the choice to discretion, and
 * inventing a PM role here would be a role model nothing else in the app has.
 *
 * INPUT IS VALIDATED, NOT WHITELISTED BY MEMBERSHIP. The read page resolves
 * `?module=` by membership precisely because its redirect-with-error-bag is a
 * write-shaped behaviour on a read-only page. This IS a write, so the redirect
 * is correct and the messages render above the re-disclosed form.
 *
 * NO ID IN THE PAYLOAD ADDRESSES A RECORD (T-46-04-02). `{project}` is
 * route-model-bound and every lookup is scoped to it; `module` and
 * `visit_type` are `Rule::in` the issuer's own const map. The one id a PM can
 * submit, `labour_resource_ids`, must `exist` and gates nothing.
 *
 * @see \App\Support\Visits\VisitLinkIssuer  — the link, from the existing generator
 * @see \App\Http\Controllers\ProjectCockpitController — the read side, untouched by writes
 */
class ProjectCockpitActionController extends Controller
{
    public function __construct(
        private VisitLinkIssuer $issuer,
        private ProjectService $projects,
    ) {
    }

    /**
     * POST /projects/{project}/cockpit/visits
     *
     * Creates a visit, issues exactly one engineer link through the generator
     * that already exists, logs one activity row, and redirects back to the
     * module the PM had open.
     */
    public function storeVisit(Request $request, Project $project): RedirectResponse
    {
        // The same two gates the read controller applies, in the same order.
        abort_unless(config('cockpit.enabled'), 404);
        abort_unless(auth()->check(), 403);

        $moduleKey = $request->input('module');

        $data = $request->validate([
            'module'                => ['required', 'string', Rule::in(VisitLinkIssuer::moduleKeys())],
            'visit_type'            => [
                'required',
                'string',
                // Scoped to the SUBMITTED module, so an install type on the
                // survey module is a validation failure rather than a visit
                // pointing at the wrong paperwork.
                Rule::in(VisitLinkIssuer::typesFor(is_string($moduleKey) ? $moduleKey : '')),
            ],
            'scheduled_date'        => ['nullable', 'date'],
            'rooms'                 => ['nullable', 'array'],
            'rooms.*'               => ['string', 'max:200'],
            'labour_resource_ids'   => ['nullable', 'array'],
            'labour_resource_ids.*' => ['integer', 'exists:labour_resources,id'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        try {
            // ONE TRANSACTION, so a generator that throws leaves no visit whose
            // link does not exist. A PM must never see a half-created visit.
            DB::transaction(function () use ($data, $project, $user): void {
                $visit = $this->visitToWrite($project, $data['visit_type']);

                $visit->fill([
                    'project_id'          => $project->id,
                    'type'                => $data['visit_type'],
                    'status'              => Visit::STATUS_PLANNED,
                    'scheduled_date'      => $data['scheduled_date'] ?? null,
                    'rooms_in_scope'      => $data['rooms'] ?? null,
                    'labour_resource_ids' => array_map('intval', $data['labour_resource_ids'] ?? []),
                ]);

                // A visit a PM created is NOT an inference — the Phase 45
                // backfill is the only thing that may set this flag.
                $visit->is_backfilled    = false;
                $visit->created_by_user_id ??= $user->id;

                $visit->save();

                // Issuing the link sets `sent_at` AND NOTHING ELSE. `sent` is a
                // DERIVED state (Visit::state(), Plan 46-01), never a stored
                // status: growing the stored vocabulary would mean rewriting
                // the 24 reconstructed rows on live.
                $this->issuer->issue($visit, $user);
                $visit->sent_at = now();
                $visit->save();

                $this->projects->log(
                    project:     $project,
                    user:        $user,
                    action:      ProjectActivityLog::ACTION_VISIT_CREATED,
                    description: "{$user->name} created a ".$this->typeLabel($data['visit_type']).' visit.',
                    metadata:    ['visit_id' => $visit->id, 'visit_type' => $visit->type],
                );
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors([
                'module' => 'The visit could not be created because its engineer link could not be produced. Nothing was saved.',
            ]);
        }

        return redirect()
            ->route('projects.cockpit', ['project' => $project, 'module' => $data['module']])
            ->with('success', 'Visit created and the engineer link is ready.');
    }

    /**
     * The visit row this create should write to.
     *
     * USUALLY A NEW ONE. But a survey visit ADOPTS the project's live survey,
     * and that survey may already be wrapped by a Phase 45 backfilled visit —
     * in which case the `(source_type, source_id)` UNIQUE index means creating
     * a second row would throw. The reconstructed row is REUSED and updated
     * instead, which is also the truer record: there is one survey, so there is
     * one survey visit.
     */
    private function visitToWrite(Project $project, string $type): Visit
    {
        if ($type !== Visit::TYPE_SITE_SURVEY) {
            return new Visit();
        }

        $survey = $this->issuer->liveSurveyFor($project);

        if ($survey === null) {
            return new Visit();
        }

        return Visit::where('project_id', $project->id)
            ->where('source_type', Visit::SOURCE_SITE_SURVEY)
            ->where('source_id', $survey->id)
            ->first() ?? new Visit();
    }

    /** Human words for the activity feed. Never the raw enum value. */
    private function typeLabel(string $type): string
    {
        return match ($type) {
            Visit::TYPE_SITE_SURVEY => 'site survey',
            Visit::TYPE_FIRST_FIX   => 'first fix',
            Visit::TYPE_INSTALL     => 'install',
            default                 => 'site',
        };
    }
}

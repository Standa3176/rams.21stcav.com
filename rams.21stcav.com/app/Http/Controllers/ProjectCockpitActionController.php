<?php

namespace App\Http\Controllers;

use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\Snag;
use App\Models\Visit;
use App\Models\VisitNote;
use App\Support\Cockpit\CockpitModulePresenter;
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
    /**
     * The states in which a visit may be ANNOTATED (Plan 46-07, D-02).
     *
     * ACCEPTED is included on purpose: a note after acceptance is exactly the
     * annotation D-02 describes and it changes nothing about the acceptance.
     * PLANNED and SENT are not — there is nothing back from site to annotate.
     * A reconstructed visit never reaches here because the row offers no
     * control at all (46-06's backfill trap), and CLOSED is excluded for the
     * same reason: a visit finished years ago is not asking for a reading.
     *
     * @var array<int, string>
     */
    private const NOTEABLE_STATES = [
        Visit::STATE_RETURNED,
        Visit::STATE_SENT_BACK,
        Visit::STATE_ACCEPTED,
    ];

    /**
     * The states in which a snag may be RAISED (Plan 46-07, D-03).
     *
     * NOT accepted. A snag found after acceptance is Phase 47's register, not
     * a retroactive edit to a closed visit — which is why this list is one
     * entry shorter than NOTEABLE_STATES rather than the same list reused.
     *
     * @var array<int, string>
     */
    private const SNAGGABLE_STATES = [
        Visit::STATE_RETURNED,
        Visit::STATE_SENT_BACK,
    ];

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
     * POST /projects/{project}/cockpit/visits/{visit}/accept
     *
     * D-02's first PM act: the office says "yes, that's done". It records WHO
     * and WHEN and NOTHING ELSE.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO: it does not rewrite `Visit::status`.
     * The stored vocabulary is `planned` / `completed` and 24 reconstructed
     * rows on live depend on it not growing — `isClosed()` already reads an
     * acceptance, so the progress ring counts this visit without a single
     * stored value changing. Nor does it touch the wrapped survey or
     * worksheet: an office action never changes what the engineer said
     * (T-46-06-02).
     *
     * ACCEPTANCE IS FINAL IN THIS PHASE. There is no un-accept: nothing in
     * D-02 grants one, and an un-accept that cleared `accepted_by_user_id`
     * would erase the record of who said yes (T-46-06-03). If it is ever
     * needed it is a new decision, not an obvious extension of this method.
     */
    public function acceptVisit(Request $request, Project $project, Visit $visit): RedirectResponse
    {
        $this->guard($project, $visit);

        // A PM who double-clicks must be told which of the two clicks counted,
        // so a second accept is an ERROR, never a silent no-op that re-stamps
        // the actor. Only a visit that has actually come back can be accepted.
        if (! in_array($visit->state(), [Visit::STATE_RETURNED, Visit::STATE_SENT_BACK], true)) {
            return $this->refuse(
                $visit->state() === Visit::STATE_ACCEPTED
                    ? 'This visit was already accepted, so nothing was recorded a second time.'
                    : 'This visit has not come back from the engineer yet, so there is nothing to accept.',
            );
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();

        DB::transaction(function () use ($project, $visit, $user): void {
            $visit->forceFill([
                'accepted_at'         => now(),
                'accepted_by_user_id' => $user->id,
            ])->save();

            $this->projects->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_VISIT_ACCEPTED,
                description: "{$user->name} accepted a ".$this->typeLabel($visit->type).' visit.',
                metadata:    ['visit_id' => $visit->id, 'visit_type' => $visit->type],
            );
        });

        return redirect()
            ->route('projects.cockpit', ['project' => $project, 'module' => $this->moduleKeyFor($visit)])
            ->with('success', 'Visit accepted. Its scope is now locked.');
    }

    /**
     * POST /projects/{project}/cockpit/visits/{visit}/send-back
     *
     * D-02's second PM act: the office says "no, go back", with a reason the
     * engineer reads on their own link.
     *
     * IT WRITES `sent_back_at` AND `send_back_reason` AND NOTHING ELSE. In
     * particular IT NEVER CLEARS `submitted_at`. Plan 46-05 derives the
     * reopening as `sent_back_at > the last submission`, so a resubmission
     * relocks the link with no flag to clear — and clearing the engineer's own
     * submission marker to make a form editable again is the exact D-02
     * violation ("an office action never changes what the engineer said") the
     * whole design exists to avoid.
     *
     * ONE REASON, NOT A HISTORY. Send back, engineer resubmits, send back
     * again: both asks are legitimate and the LATEST is what shows. A reason
     * history would be a second place to read the current ask from, which is
     * how an engineer ends up answering last month's question.
     *
     * NOBODY IS NOTIFIED. There is a mail path in this app and a notification
     * recipient resolver, and using either here would be inventing scope:
     * 46-CONTEXT.md's in-scope list says "reopens the engineer link", not
     * "emails the engineer". The omission is a decision, recorded here so it
     * does not read as an oversight.
     */
    public function sendBackVisit(Request $request, Project $project, Visit $visit): RedirectResponse
    {
        $this->guard($project, $visit);

        // Only from RETURNED. A visit that has not come back has nothing to
        // reject, and one already sent back has no second send-back to give —
        // the reopening ends when the engineer resubmits, not when the office
        // clicks again.
        if ($visit->state() !== Visit::STATE_RETURNED) {
            return $this->refuse(match ($visit->state()) {
                Visit::STATE_ACCEPTED  => 'This visit was accepted, so it cannot be sent back. Raise a new visit instead.',
                Visit::STATE_SENT_BACK => 'This visit is already back with the engineer, so nothing was sent a second time.',
                default                => 'This visit has not come back from the engineer yet, so there is nothing to send back.',
            });
        }

        $data = $request->validate([
            // REQUIRED. Sending work back without saying why is how a second
            // incomplete return happens, and the engineer reads this text.
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        DB::transaction(function () use ($project, $visit, $user, $data): void {
            $visit->forceFill([
                'sent_back_at'     => now(),
                'send_back_reason' => $data['reason'],
            ])->save();

            $this->projects->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_VISIT_SENT_BACK,
                // The PM's free text is NOT copied into the feed. It is the
                // engineer's current ask and belongs in exactly one place.
                description: "{$user->name} sent a ".$this->typeLabel($visit->type).' visit back to the engineer.',
                metadata:    ['visit_id' => $visit->id, 'visit_type' => $visit->type],
            );
        });

        return redirect()
            ->route('projects.cockpit', ['project' => $project, 'module' => $this->moduleKeyFor($visit)])
            ->with('success', 'Visit sent back. The engineer link is open again and carries your reason.');
    }

    /**
     * POST /projects/{project}/cockpit/visits/{visit}/notes
     *
     * D-02's third PM act: the office annotates the return.
     *
     * IT WRITES TO `visit_notes` AND NEVER TO `site_surveys.office_review_notes`.
     * That column exists and reusing it would have been one line — it is
     * single-valued and overwritable (the second note destroys the first), it
     * carries no author and no timestamp, and it lives ON THE ENGINEER'S
     * RECORD, which is the shape D-02 forbids in its own words: "the engineer's
     * record stays intact; the office view sits alongside it". A worksheet has
     * no equivalent column at all, so reusing the survey's would leave first
     * fix and install unannotatable. See VisitNote's docblock before
     * "consolidating" the two.
     *
     * NOTHING ENGINEER-CAPTURED MOVES. No touch on the visit, no write to the
     * survey or worksheet — asserted byte-for-byte through `getRawOriginal()`,
     * `updated_at` included (T-46-07-02).
     *
     * AN ACCEPTED VISIT MAY STILL BE ANNOTATED. A note after acceptance is
     * exactly the annotation D-02 describes and changes nothing; only a visit
     * that has never come back has nothing to annotate.
     *
     * THE NOTE IS NOT COPIED INTO THE FEED. The activity row records that a
     * note was added and by whom; the words live in exactly one place — the
     * same rule the send-back reason follows.
     */
    public function storeNote(Request $request, Project $project, Visit $visit): RedirectResponse
    {
        $this->guard($project, $visit);

        if (! in_array($visit->state(), self::NOTEABLE_STATES, true)) {
            return $this->refuse('This visit has not come back from the engineer yet, so there is nothing to annotate.');
        }

        $data = $request->validate([
            // PM free text. Stored raw and escaped at render — `{{ }}` only,
            // never `{!! !!}` (T-46-07-05).
            'body' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        DB::transaction(function () use ($project, $visit, $user, $data): void {
            $note = VisitNote::create([
                'project_id' => $project->id,
                'visit_id'   => $visit->id,
                'user_id'    => $user->id,
                'body'       => $data['body'],
            ]);

            $this->projects->log(
                project:     $project,
                user:        $user,
                // REUSING the existing constant deliberately: a note is a
                // note, and a second one would split the feed's history.
                action:      ProjectActivityLog::ACTION_NOTE_ADDED,
                description: "{$user->name} added an office note to a ".$this->typeLabel($visit->type).' visit.',
                // `visit_note_id` is what lets the Notes tab list the note
                // itself ONCE rather than alongside this row's description.
                metadata:    ['visit_id' => $visit->id, 'visit_note_id' => $note->id],
            );
        });

        return redirect()
            ->route('projects.cockpit', [
                'project' => $project,
                'module'  => $this->moduleKeyFor($visit),
                // The PM lands on what they just wrote.
                'tab'     => 'notes',
            ])
            ->with('success', 'Office note added.');
    }

    /**
     * POST /projects/{project}/cockpit/visits/{visit}/snags
     *
     * D-02's fourth PM act: raise a snag from the visit it came from.
     *
     * RAISING A SNAG IS NOT MANAGING ONE (D-03). This creates ONE `open` row
     * with three fields and stops. There is no outcome, no parts, no follow-up
     * chain, no assignee and no cost — each is owned by a named Phase 47
     * criterion and each is absent from the schema (46-02's scope fence).
     *
     * THE FENCE IS ENFORCED AT THIS BOUNDARY TOO. Exactly three fields are
     * validated and exactly three are passed to `create()`, so a POST carrying
     * `outcome`, `parts`, `parent_snag_id`, `assigned_to`, `cost` or
     * `resolved_at` has them IGNORED (T-46-07-03). `status` is set here, never
     * taken from the request: a raised snag is open.
     *
     * NOT AFTER ACCEPTANCE. A snag found after a visit was accepted belongs in
     * Phase 47's register, not in a retroactive edit to a closed visit.
     */
    public function storeSnag(Request $request, Project $project, Visit $visit): RedirectResponse
    {
        $this->guard($project, $visit);

        if (! in_array($visit->state(), self::SNAGGABLE_STATES, true)) {
            return $this->refuse(match ($visit->state()) {
                Visit::STATE_ACCEPTED => 'This visit was accepted, so a snag against it belongs on the project rather than on the visit.',
                default               => 'This visit has not come back from the engineer yet, so there is nothing to snag.',
            });
        }

        $data = $request->validate([
            'title'     => ['required', 'string', 'min:3', 'max:200'],
            'detail'    => ['nullable', 'string', 'max:4000'],
            'room_name' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        DB::transaction(function () use ($project, $visit, $user, $data): void {
            $snag = Snag::create([
                'project_id'        => $project->id,
                'visit_id'          => $visit->id,
                'title'             => $data['title'],
                'detail'            => $data['detail'] ?? null,
                'room_name'         => $data['room_name'] ?? null,
                'raised_by_user_id' => $user->id,
                // Set here, never read from the request. A raised snag is open.
                'status'            => Snag::STATUS_OPEN,
            ]);

            $this->projects->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_SNAG_RAISED,
                description: "{$user->name} raised a snag on a ".$this->typeLabel($visit->type).' visit.',
                metadata:    ['visit_id' => $visit->id, 'snag_id' => $snag->id],
            );
        });

        return redirect()
            ->route('projects.cockpit', ['project' => $project, 'module' => $this->moduleKeyFor($visit)])
            ->with('success', 'Snag raised against this visit.');
    }

    /**
     * The two gates every cockpit write carries, plus the ownership check.
     *
     * ROUTE-MODEL BINDING DOES NOT CHECK THE RELATIONSHIP. Without the third
     * line, a visit id belonging to another project would be accepted by
     * anyone who guessed it (T-46-06-01), so the check is explicit and the
     * failure is a 404 — a 403 would confirm the id exists.
     */
    private function guard(Project $project, Visit $visit): void
    {
        abort_unless(config('cockpit.enabled'), 404);
        abort_unless(auth()->check(), 403);
        abort_unless($visit->project_id === $project->id, 404);
    }

    /**
     * A refused act: back to where the PM was, with the reason visible, at
     * 422. Not a redirect-with-success and not an exception page — the PM must
     * be able to read what did not happen and carry on.
     */
    private function refuse(string $message): RedirectResponse
    {
        return back()->withInput()->withErrors(['visit' => $message])->setStatusCode(422);
    }

    /**
     * The module drawer this visit belongs in, read from the presenter's OWN
     * map rather than a second copy of it here — a seventh visit type added
     * later must not silently redirect to nothing.
     */
    private function moduleKeyFor(Visit $visit): ?string
    {
        foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
            if (in_array($visit->type, $definition['visit_types'] ?? [], true)) {
                return $key;
            }
        }

        return null;
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

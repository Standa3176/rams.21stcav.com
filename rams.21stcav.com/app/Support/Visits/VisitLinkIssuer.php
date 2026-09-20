<?php

namespace App\Support\Visits;

use App\Core\Modules\Survey\SurveyService;
use App\Jobs\BuildWorksheetJob;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Services\ProjectDeliverablesService;
use App\Services\WorkerMonitorService;
use RuntimeException;

/**
 * VisitLinkIssuer — a visit's engineer link, produced by the generator that
 * ALREADY EXISTS (Phase 46, Plan 46-04; 46-CONTEXT.md D-04).
 *
 * TWO MODULES, AND THE RULE IS NOT ARBITRARY. `VISIT_MODULES` maps exactly
 * `site_survey` and `worksheet`, because THEY ARE THE ONLY TWO MODULES WITH AN
 * ENGINEER LINK. A visit on a module with no link would be a record with
 * nothing behind it, and "the PM sends it to whoever is attending" would be a
 * lie. Programming, commissioning and snag visits are out of this phase's link
 * scope; snagging's own visit is Phase 47's `Book a visit`, which stays a
 * banned affordance in CockpitReadOnlyFenceTest.
 *
 * THIS CLASS MIRRORS TWO LIVE CONTROLLER METHODS AND DELIBERATELY DOES NOT
 * REFACTOR THEM:
 *
 *   • App\Http\Controllers\SiteSurveyController::createFromProject()
 *   • App\Http\Controllers\WorksheetController::generateFromProject()
 *
 * The risk posture for this milestone is a minimal diff against live delivery
 * paths, and both of those are live. So the same collaborators are called in
 * the same order, and the two method names are written here so a future change
 * to either is findable by grep.
 *
 * TOKENS ARE NEVER MINTED HERE (T-46-04-04). `SiteSurvey::boot()` and
 * `Worksheet::boot()` assign `access_token` by DIRECT PROPERTY ASSIGNMENT,
 * bypassing `$fillable` — a deliberate omission from the S-02/S-03 security
 * re-audit. This class creates the record and then READS `publicUrl()`. It
 * never writes, rotates, mass-assigns or renders a token.
 *
 * ADOPTION, NOT A SECOND SURVEY. If the project already has a live
 * (non-superseded, draft-or-completed) survey, a survey visit ADOPTS it: same
 * survey, same token, no second link. Superseding is a deliberate act that
 * already exists at `site-surveys.supersede-from-project` and stays on the
 * project page.
 */
final class VisitLinkIssuer
{
    /**
     * Module key => the visit types that module may create.
     *
     * Enumerated as DATA so the controller's `Rule::in` reads from here rather
     * than from a second hand-maintained list that would drift.
     *
     * @var array<string, array<int, string>>
     */
    public const VISIT_MODULES = [
        ProjectDeliverable::KEY_SITE_SURVEY => [Visit::TYPE_SITE_SURVEY],
        ProjectDeliverable::KEY_WORKSHEET   => [Visit::TYPE_FIRST_FIX, Visit::TYPE_INSTALL],
    ];

    public function __construct(
        private readonly SurveyService $surveys,
        private readonly ProjectDeliverablesService $deliverables,
        private readonly WorkerMonitorService $workerMonitor,
    ) {
    }

    /** @return array<int, string> */
    public static function moduleKeys(): array
    {
        return array_keys(self::VISIT_MODULES);
    }

    /**
     * The visit types a module may create, or [] for a module with no link.
     *
     * @return array<int, string>
     */
    public static function typesFor(string $moduleKey): array
    {
        return self::VISIT_MODULES[$moduleKey] ?? [];
    }

    /**
     * The project's live survey, or null.
     *
     * The same four clauses `SiteSurveyController::createFromProject()` uses,
     * so "live" means the same thing on the cockpit as on the project page.
     */
    public function liveSurveyFor(Project $project): ?SiteSurvey
    {
        return SiteSurvey::where('project_id', $project->id)
            ->whereNull('superseded_at')
            ->whereIn('status', ['draft', 'completed'])
            ->first();
    }

    /**
     * Issue the visit's engineer link, setting `source_type` / `source_id` to
     * the record it issued. Returns the public token URL.
     *
     * Throws when the visit's type reaches no module — never guesses, because
     * a guess here would produce a visit pointing at the wrong paperwork.
     */
    public function issue(Visit $visit, User $user): string
    {
        $project = $visit->project;

        $source = match ($visit->type) {
            Visit::TYPE_SITE_SURVEY                    => $this->surveyFor($project, $user),
            Visit::TYPE_FIRST_FIX, Visit::TYPE_INSTALL => $this->worksheetFor($project, $user),
            default                                    => throw new RuntimeException(
                "Visit type [{$visit->type}] has no engineer link in Phase 46."
            ),
        };

        $visit->source_type = $source instanceof SiteSurvey
            ? Visit::SOURCE_SITE_SURVEY
            : Visit::SOURCE_WORKSHEET;
        $visit->source_id = $source->id;

        return $source->publicUrl();
    }

    /**
     * Mirrors SiteSurveyController::createFromProject() — minus its supersede
     * confirmation screen, which is a page, not a service call. Where that
     * controller renders a form, this ADOPTS.
     */
    private function surveyFor(Project $project, User $user): SiteSurvey
    {
        $existing = $this->liveSurveyFor($project);

        if ($existing !== null) {
            return $existing;
        }

        $survey = $this->surveys->createFromProject($project, $user);

        $this->deliverables->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_SITE_SURVEY, $user);

        return $survey;
    }

    /**
     * Mirrors WorksheetController::generateFromProject() — the same four
     * collaborators, in the same order: create, auto-flip, ensure the worker,
     * dispatch the build.
     *
     * There is NO adoption here and that is deliberate: a first-fix visit and
     * an install visit are different trips to site with different sign-offs,
     * so each gets its own worksheet exactly as the project page produces one
     * per click today.
     */
    private function worksheetFor(Project $project, User $user): Worksheet
    {
        $worksheet = Worksheet::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'project_ref'  => $project->ref ?? $project->quote_reference ?? null,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
            'status'       => Worksheet::STATUS_GENERATING,
        ]);

        $this->deliverables->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_WORKSHEET, $user);

        $this->workerMonitor->ensureRunning();

        BuildWorksheetJob::dispatch($worksheet->id);

        return $worksheet;
    }
}

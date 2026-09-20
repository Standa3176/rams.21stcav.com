<?php

namespace App\Support\Cockpit;

use App\DTO\ProjectHealth;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/**
 * CockpitHeaderPresenter — the delivery cockpit's masthead, its three KPI cards
 * (D-12) and its stage chip (Phase 45, Plan 45-10; sketch 004).
 *
 * PURE READING. No write, no touch(), no cache warm. It receives the
 * `?ProjectHealth` the controller already derived and MUST NOT call
 * `ProjectHealthService` itself — that service's docblock forbids it from
 * querying, and the controller owns the eager-load ordering that keeps the
 * D-13 deliverables rule from silently degrading to a no-op.
 *
 * ── THREE VALUES THE DESIGN ASKS FOR THAT HAVE NO SOURCE ────────────────────
 *
 * Each is handled by OMISSION, and each omission is pinned by a named test in
 * CockpitHeaderPresenterTest so that a later agent has to delete a reasoned
 * test before it can wire a plausible-looking substitute.
 *
 * 1. SITE CONTACT EMAIL — there is no such column. `SiteSurvey` carries
 *    `site_contact_name` and `site_contact_phone` only. `SiteSurvey::pm_email`
 *    is the PROJECT MANAGER's address; putting it in a slot labelled "Site
 *    contact" would tell a PM something false about who they are emailing.
 *    There is therefore NO `contact_email` key in the masthead, in any state.
 *
 * 2. "PROPOSED INSTALL DATE" — no such field. The nearest value is
 *    `InstallProgramme::planned_start_date`, which is the PROGRAMME's planned
 *    start: a different fact, set by a different act. It is surfaced as
 *    `planned_start` and may only ever be labelled "Planned start". The design
 *    phrase itself appears nowhere in this class's output.
 *
 * 3. THE SECOND STAGE CHIP — the design shows two chips side by side ("Survey
 *    Pending" and "Installation phase"). A project has exactly ONE
 *    `Project::status` and no second source exists, so `stageChips()` returns
 *    exactly one chip. The absent second chip is a recorded decision, not an
 *    oversight.
 *
 * ── THE DENOMINATOR ─────────────────────────────────────────────────────────
 *
 * "Documents n of N complete" takes N from `CockpitModulePresenter::modules()`
 * — the rows actually rendered — NEVER from a literal. A hardcoded 9 above a
 * list that a later phase lengthens or shortens would misreport delivery
 * progress to a PM (threat T-45-10-01). "Complete" means the module's chip is
 * `on-file`, which is `pip === 'done'` from the existing section presenter:
 * `ProjectDeliverable` has no complete state, only required / not_required /
 * not_yet_decided, so that is the only defensible reading in this codebase.
 */
final class CockpitHeaderPresenter
{
    /**
     * Stage wording for the chip, written out rather than derived with
     * `Str::headline()` so every string is reviewable in one place.
     *
     * Deliberately NOT `Project::STATUS_LABELS`: that map is Title Case for the
     * dashboard's status badges ("Survey Pending", "Installing"), and reusing
     * it here would couple the cockpit's copy to the dashboard's — a change to
     * either would silently move the other, which is the ROADMAP criterion 4
     * failure mode this phase is guarding against.
     *
     * An unmapped status yields NO chip rather than a raw enum on screen.
     *
     * @var array<string, string>
     */
    private const STAGE_LABELS = [
        Project::STATUS_QUOTE_IMPORTED => 'Quote imported',
        Project::STATUS_SURVEY_PENDING => 'Survey pending',
        Project::STATUS_ENGINEERING    => 'Engineering',
        Project::STATUS_INSTALLING     => 'Installation',
        Project::STATUS_COMMISSIONING  => 'Commissioning',
        Project::STATUS_HANDOVER       => 'Handover',
        Project::STATUS_COMPLETED      => 'Completed',
        Project::STATUS_ARCHIVED       => 'Archived',
    ];

    /**
     * Visit type wording for the "Next visit" card. Same reasoning as
     * STAGE_LABELS — reviewable copy, and an unknown type degrades to the
     * generic word rather than printing a raw enum.
     *
     * @var array<string, string>
     */
    private const VISIT_TYPE_LABELS = [
        Visit::TYPE_SITE_SURVEY   => 'Site survey',
        Visit::TYPE_FIRST_FIX     => 'First fix',
        Visit::TYPE_INSTALL       => 'Install',
        Visit::TYPE_PROGRAMMING   => 'Programming',
        Visit::TYPE_SNAG          => 'Snagging',
        Visit::TYPE_COMMISSIONING => 'Commissioning',
    ];

    public function __construct(
        private CockpitModulePresenter $modules,
    ) {
    }

    /**
     * The masthead facts. A key is ABSENT — not null, not an empty string —
     * when its source is absent, so the Blade layer renders only the lines it
     * has, and a project with no survey shows no contact line rather than an
     * empty one.
     *
     * @return array<string, mixed>
     */
    public function masthead(Project $project): array
    {
        $masthead = [];

        $this->put($masthead, 'ref', $project->ref);
        $this->put($masthead, 'site_address', $project->site_address);

        $survey = $project->latestSurvey;

        if ($survey instanceof SiteSurvey) {
            $this->put($masthead, 'contact_name', $survey->site_contact_name);
            $this->put($masthead, 'contact_phone', $survey->site_contact_phone);
            // NO contact_email. See the class docblock, point 1.
        }

        $plannedStart = $this->plannedStart($project);

        if ($plannedStart instanceof Carbon) {
            // Rendered under "Planned start" only. See the class docblock, point 2.
            $masthead['planned_start'] = $plannedStart;
        }

        return $masthead;
    }

    /**
     * The three KPI cards of D-12.
     *
     * @return array<string, array<string, mixed>>
     */
    public function kpis(Project $project, ?ProjectHealth $health): array
    {
        return [
            'overall'    => $this->overallCard($project, $health),
            'next_visit' => $this->nextVisitCard($project),
            'documents'  => $this->documentsCard($project),
        ];
    }

    /**
     * EXACTLY ONE chip — the humanised `Project::status`. See the class
     * docblock, point 3.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function stageChips(Project $project): array
    {
        $status = (string) $project->status;
        $label  = self::STAGE_LABELS[$status] ?? null;

        if ($label === null) {
            return [];
        }

        return [['key' => $status, 'label' => $label]];
    }

    // -- Cards ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function overallCard(Project $project, ?ProjectHealth $health): array
    {
        $stage = self::STAGE_LABELS[(string) $project->status] ?? null;

        if ($health === null) {
            // Never "On track". A summary that could not be derived is not the
            // same fact as a project that is fine, and conflating them would
            // hide exactly the projects a PM most needs to look at.
            return [
                'status'  => null,
                'reason'  => 'The status summary could not be read.',
                'overdue' => false,
                'stage'   => $stage,
            ];
        }

        return [
            'status'  => $health->status,
            'reason'  => $health->reason,
            'overdue' => $health->overdue,
            'stage'   => $stage,
        ];
    }

    /**
     * The earliest PLANNED visit that has a date. A visit with no
     * `scheduled_date` is not a next visit — it is an unscheduled one — and a
     * completed visit is never shown here, however recent.
     *
     * @return array<string, mixed>
     */
    private function nextVisitCard(Project $project): array
    {
        $next = $project->visits
            ->filter(fn (Visit $visit): bool => $visit->status === Visit::STATUS_PLANNED
                && $visit->scheduled_date !== null)
            ->sortBy(fn (Visit $visit) => $visit->scheduled_date->timestamp)
            ->first();

        if (! $next instanceof Visit) {
            return [
                'planned' => false,
                'label'   => 'None planned',
            ];
        }

        return [
            'planned'    => true,
            'date'       => $next->scheduled_date,
            'type_label' => self::VISIT_TYPE_LABELS[$next->type] ?? 'Visit',
            'label'      => $next->scheduled_date->format('j M Y'),
        ];
    }

    /**
     * @return array{complete: int, total: int, percent: int}
     */
    private function documentsCard(Project $project): array
    {
        $modules = $this->modules->modules($project);

        $total    = $modules->count();
        $complete = $modules
            ->filter(fn (array $row): bool => $row['chip'] === CockpitModulePresenter::CHIP_ON_FILE)
            ->count();

        return [
            'complete' => $complete,
            'total'    => $total,
            'percent'  => $total === 0 ? 0 : (int) floor(($complete / $total) * 100),
        ];
    }

    // -- Sources -------------------------------------------------------------

    private function plannedStart(Project $project): ?Carbon
    {
        $programme = $project->installProgrammes
            ->first(fn (InstallProgramme $p): bool => $p->planned_start_date !== null);

        return $programme?->planned_start_date;
    }

    /**
     * Adds a key only when its source actually holds something.
     *
     * @param  array<string, mixed>  $target
     */
    private function put(array &$target, string $key, ?string $value): void
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return;
        }

        $target[$key] = $value;
    }
}

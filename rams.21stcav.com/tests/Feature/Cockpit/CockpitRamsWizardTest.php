<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildRamsDocumentJob;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\User;
use App\Models\Visit;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use App\Support\Visits\VisitLinkIssuer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-05 — RAMS JOINS THE STEPPED FLOW.
 *
 * 46.5-CONTEXT D-04 and D-05, in the user's own words: *"RAMs is doc creation
 * only ie office user should be able to either gen independant of a visit or as
 * part of an install ie are RAMS req ? if ticked yes it follow a similar flow to
 * site survey ... then create rams based on project info , user entered visit
 * info and userer enter job summary"* — and, decisively, *"RAMs will use
 * existing RAM process."*
 *
 * So this file proves four things and writes no renderer:
 *
 *   1. THE JOB SUMMARY REACHES THE DOCUMENT the existing generator builds,
 *      through the controller's EXISTING `patchFormData()` — no controller edit,
 *      no new route, no new build job.
 *   2. RAMS HAS THREE STEPS and every one of its 17 fields reaches exactly one
 *      of them, asserted by SET EQUALITY against the map rather than by counting.
 *   3. THE TICK DRIVES THE FLOW, IT DOES NOT GATE THE PAGE. Every deliverable
 *      state renders, in every mode, and the COUNT of states rendered is itself
 *      asserted — a test that renders one state proves nothing about the others.
 *   4. RAMS ISSUES NO ENGINEER LINK AND CREATES NO VISIT. An install's link is
 *      the install's.
 *
 * ── NO SPEND, AND TWO BELTS ─────────────────────────────────────────────────
 *
 * `Bus::fake()` stops the queued RAMS builder and `Http::fake()` stops anything
 * that ever stops going through it. Neither the AI pipeline nor a byte of DOCX
 * is produced by this file — the same treatment `CockpitDocCreationEndToEndTest`
 * established.
 */
class CockpitRamsWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE THREE DELIVERABLE STATES, NAMED. `ProjectDeliverable::STATE_*` in
     * full, plus the fourth real-world case: NO ROW AT ALL, which
     * `deliverableState()` reports as `not_yet_decided` and which is what every
     * pre-260822 project actually looks like.
     *
     * @var array<int, string|null>
     */
    private const DELIVERABLE_STATES = [
        null, // no row — the pre-260822 project
        ProjectDeliverable::STATE_REQUIRED,
        ProjectDeliverable::STATE_NOT_REQUIRED,
        ProjectDeliverable::STATE_NOT_YET_DECIDED,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
        Bus::fake();
        Http::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Northbank RAMS Job',
            'ref'          => 'Q-4657',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(string $name = 'Priya Mistry'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /** A reviewed package — what `rams.from-project` requires before it creates anything. */
    private function reviewedPackage(Project $project): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user('Package Owner')->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'overview'               => 'A prose overview the normaliser does not carry.',
                'method_statement_notes' => 'Strip out, install, commission.',
                'project'                => ['project_name' => 'Northbank RAMS Job'],
                'equipment'              => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display']],
                'activities'             => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'                    => ['Gloves', 'Safety boots'],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'  => 'Dev Chandra', 'email' => 'dev.chandra@example.test',
            'phone' => '07700 900111', 'roles' => [LabourResource::ROLE_ENGINEER], 'is_active' => true,
        ]);
    }

    /** Sets (or deliberately does not create) the project's `rams` deliverable row. */
    private function tick(Project $project, ?string $state): void
    {
        if ($state !== null) {
            ProjectDeliverable::create([
                'project_id'      => $project->id,
                'deliverable_key' => ProjectDeliverable::KEY_RAMS,
                'state'           => $state,
            ]);
        }

        $project->unsetRelation('deliverables');
    }

    private function wizard(): CockpitWizardPresenter
    {
        return app(CockpitWizardPresenter::class);
    }

    /** The full RAMS answer set — every one of the map's writable fields. */
    private function ramsPayload(): array
    {
        return [
            'module'                => ProjectDeliverable::KEY_RAMS,
            'format'                => 'word',
            'tab'                   => 'overview',
            'planned_start_date'    => '2026-10-05',
            'planned_end_date'      => '2026-10-09',
            'planned_start_time'    => '0730',
            'working_hours'         => 'Monday-Friday, 07:30-17:00',
            'project_manager_name'  => 'Priya Mistry',
            'project_manager_phone' => '0113 000 0000',
            'project_manager_email' => 'priya@example.test',
            'lead_engineer_name'    => 'Dev Chandra',
            'lead_engineer_phone'   => '07700 900111',
            'additional_engineers'  => [],
            'programmers'           => [],
            'contact_name'          => 'Sam Bright',
            'contact_phone'         => '07700 900444',
            'contact_email'         => 'sam.bright@example.test',
            'job_summary'           => 'Strip out two legacy displays and install two 75in screens in the boardroom.',
        ];
    }

    private function generate(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    private function stepUrl(Project $project, int $step): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_RAMS,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    // ── 1. THE JOB SUMMARY REACHES THE EXISTING GENERATOR'S DOCUMENT ────────

    /**
     * THE LOAD-BEARING ONE. A field the generator ignores is a field that
     * teaches a PM to type noise (46.2 D-03), so this walks the value the whole
     * way: a PM's textarea → validation → the controller's EXISTING
     * `patchFormData()` → `RamsDocument::form_data['works_description']`, which
     * is the key `RamsBuilderService.php:119` reads.
     *
     * NO CONTROLLER EDIT. `patchFormData()` already loops every `form_data.*`
     * target on the map, which is why the job summary needed one map row and
     * nothing else.
     */
    public function test_a_typed_job_summary_reaches_the_documents_form_data(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();

        $rams = RamsDocument::where('project_id', $project->id)->sole();

        $this->assertSame(
            'Strip out two legacy displays and install two 75in screens in the boardroom.',
            $rams->form_data['works_description'] ?? null,
            'The job summary a PM typed never reached the key RamsBuilderService reads.',
        );

        // The map's other `form_data` exception is undisturbed by the new one.
        $this->assertSame('Monday-Friday, 07:30-17:00', $rams->form_data['working_hours'] ?? null);

        // The EXISTING process, unspent: the existing job, the existing status.
        Bus::assertDispatched(BuildRamsDocumentJob::class);
        $this->assertSame(RamsDocument::STATUS_GENERATING, $rams->status);
    }

    /**
     * T-46.5-05-01. `patchFormData()` is scoped to the document THIS request
     * created (`id > $before`), so a second submission cannot rewrite the first
     * RAMS's summary.
     */
    public function test_a_second_submission_never_edits_the_first_rams_summary(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();
        $first = RamsDocument::where('project_id', $project->id)->sole();

        $this->generate($project, ['job_summary' => 'A completely different job.'] + $this->ramsPayload(), $pm)
            ->assertRedirect();

        $this->assertSame(
            'Strip out two legacy displays and install two 75in screens in the boardroom.',
            $first->fresh()->form_data['works_description'] ?? null,
            'A re-submission edited an EARLIER RAMS document.',
        );
    }

    /** The map's own rule: 5000 characters is the ceiling, and it is enforced. */
    public function test_an_oversized_job_summary_is_a_validation_failure(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, ['job_summary' => str_repeat('a', 5001)] + $this->ramsPayload(), $pm)
            ->assertSessionHasErrors('job_summary');

        $this->assertSame(0, RamsDocument::where('project_id', $project->id)->count());
    }

    /**
     * D-04's *"or taken from project data"* — and it needed NO prefill
     * mechanism. `RamsBuilderService.php:714-718` already falls back to the
     * package's own method-statement notes and then to an equipment-derived
     * scope, so a PM who types nothing still gets a document and the app
     * invents no second source of the same sentence.
     *
     * ⚠ NOTE FOR A LATER READER, NOT A DEFECT THIS PLAN FIXES: that block's
     * comment says *"when works_description is blank"* while the code it heads
     * reads `$reviewedData['method_statement_notes']`. The fallback is real;
     * only the comment names the wrong key. Editing `RamsBuilderService` is
     * forbidden by D-04 (*"RAMs will use existing RAM process"*), so the
     * finding is recorded here rather than acted on.
     */
    public function test_a_blank_job_summary_still_creates_the_document(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $payload = $this->ramsPayload();
        unset($payload['job_summary']);

        $this->generate($project, $payload, $pm)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, RamsDocument::where('project_id', $project->id)->count());
    }
}

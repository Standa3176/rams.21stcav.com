<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitSectionPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46 Plan 01 — the visit lifecycle, as executable fact.
 *
 * Task 1 covers the schema: seven columns, and the two that are ABSENT on
 * purpose. Task 2 is the state machine — stored acts, derived truth.
 * Task 3 is the anti-rot pair that stops either half being quietly undone.
 */
class VisitLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** The seven columns, exactly — one per act a human performed. */
    private const LIFECYCLE_COLUMNS = [
        'sent_at',
        'accepted_at',
        'sent_back_at',
        'send_back_reason',
        'accepted_by_user_id',
        'created_by_user_id',
        'rooms_in_scope',
    ];

    // -- Task 1: the schema --------------------------------------------------

    public function test_the_seven_lifecycle_columns_exist(): void
    {
        foreach (self::LIFECYCLE_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('visits', $column),
                "visits.{$column} is required by the Phase 46 lifecycle."
            );
        }
    }

    public function test_there_is_no_returned_at_and_no_scope_locked_at_column(): void
    {
        // DERIVED OVER STORED. A return is recorded by the engineer on their
        // own record (SiteSurvey.submitted_at / the latest WorksheetSignoff);
        // a second copy here would go stale on a re-signoff and then
        // contradict its own source. The lock is a CONSEQUENCE of a return,
        // not an independent fact. See the migration docblock before adding
        // either of these.
        $this->assertFalse(
            Schema::hasColumn('visits', 'returned_at'),
            'A stored returned_at would be a second source of truth for "it came back".'
        );

        $this->assertFalse(
            Schema::hasColumn('visits', 'scope_locked_at'),
            'The lock is derived from the return — storing it lets it contradict its own cause.'
        );
    }

    public function test_every_new_column_is_nullable_so_a_backfilled_visit_asserts_nothing(): void
    {
        $visit = Visit::factory()->backfilledFromWorksheet()->create();

        $visit->refresh();

        foreach (self::LIFECYCLE_COLUMNS as $column) {
            $this->assertNull(
                $visit->getAttribute($column),
                "visits.{$column} must default to NULL — the 24 backfilled visits on live ".
                'performed none of these acts and the schema must not claim otherwise.'
            );
        }

        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertTrue($visit->isBackfilled());
    }

    public function test_the_existing_source_unique_index_still_guards_the_backfill(): void
    {
        $first = Visit::factory()->create([
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => 4242,
        ]);

        $this->assertNotNull($first->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Visit::factory()->create([
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => 4242,
        ]);
    }

    // -- Task 2: the state machine -------------------------------------------

    public function test_a_fresh_planned_visit_is_planned_and_nothing_else(): void
    {
        $visit = Visit::factory()->planned()->create();

        $this->assertSame(Visit::STATE_PLANNED, $visit->state());
        $this->assertNull($visit->returnedAt());
        $this->assertFalse($visit->isClosed());
        $this->assertFalse($visit->isAwaitingReturn());
        $this->assertFalse($visit->isLocked());
        $this->assertFalse($visit->wasSentBack());
    }

    public function test_a_sent_visit_is_sent_and_awaiting_return_but_not_locked(): void
    {
        $visit = Visit::factory()->sent()->create();

        // The STORED status is still `planned` — `sent` is derived from
        // `sent_at`, so the stored vocabulary never had to grow.
        $this->assertSame(Visit::STATUS_PLANNED, $visit->status);
        $this->assertSame(Visit::STATE_SENT, $visit->state());
        $this->assertTrue($visit->isAwaitingReturn());
        $this->assertFalse($visit->isLocked(), 'ROADMAP criterion 3: scope stays editable after sending.');
        $this->assertFalse($visit->isClosed());
    }

    public function test_a_returned_survey_visit_derives_its_return_from_the_survey(): void
    {
        $project = Project::factory()->create();
        $survey  = $this->survey($project);

        $visit = Visit::factory()->backfilledFromSurvey($survey)->sent()->create([
            'project_id' => $project->id,
            'status'     => Visit::STATUS_PLANNED,
        ]);

        $this->assertNull($visit->returnedAt(), 'Nothing has come back until the survey is submitted.');

        $survey->forceFill(['submitted_at' => '2026-09-10 08:30:00'])->save();

        $this->assertSame(
            '2026-09-10 08:30:00',
            $visit->returnedAt()?->format('Y-m-d H:i:s'),
            'returnedAt() reads SiteSurvey.submitted_at, never a column on visits.'
        );
        $this->assertSame(Visit::STATE_RETURNED, $visit->state());
        $this->assertFalse($visit->isAwaitingReturn());
    }

    public function test_a_returned_worksheet_visit_derives_its_return_from_the_latest_signoff(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->create([
            'project_id'  => $project->id,
            'status'      => Visit::STATUS_PLANNED,
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $worksheet->id,
        ]);

        $this->assertNull($visit->returnedAt());

        $this->signoff($worksheet, '2026-09-01 10:00:00');

        $this->assertSame('2026-09-01 10:00:00', $visit->returnedAt()?->format('Y-m-d H:i:s'));

        // THE REASON THERE IS NO `returned_at` COLUMN: sign-off is append-only,
        // so a re-signoff after remedials produces a NEWER row. A stored copy
        // would still be pointing at 1 September and would contradict the
        // worksheet it wraps.
        $this->signoff($worksheet, '2026-09-15 16:45:00');

        $this->assertSame(
            '2026-09-15 16:45:00',
            $visit->returnedAt()?->format('Y-m-d H:i:s'),
            'A re-signoff must move the derived return; a stored copy could not.'
        );
    }

    public function test_a_return_is_null_when_the_source_was_force_deleted(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $gone      = $worksheet->id;

        $visit = Visit::factory()->create([
            'project_id'  => $project->id,
            'status'      => Visit::STATUS_PLANNED,
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $gone,
        ]);

        $worksheet->forceDelete();

        $this->assertNull($visit->returnedAt());
        $this->assertFalse($visit->isLocked());
        $this->assertSame(Visit::STATE_PLANNED, $visit->state());
    }

    public function test_scope_locks_on_return_not_on_acceptance(): void
    {
        $sent = Visit::factory()->sent()->create();
        $this->assertFalse($sent->isLocked());

        $returned = Visit::factory()->returned()->create();
        $this->assertTrue(
            $returned->fresh()->isLocked(),
            'ROADMAP criterion 3: scope locks once a return arrives — before any PM acts.'
        );
    }

    public function test_an_accepted_visit_is_accepted_closed_and_locked(): void
    {
        $user = User::factory()->create();

        $visit = Visit::factory()->accepted($user)->create()->fresh();

        $this->assertSame(Visit::STATE_ACCEPTED, $visit->state());
        $this->assertTrue($visit->isClosed());
        $this->assertTrue($visit->isLocked());
        $this->assertFalse($visit->isAwaitingReturn());
        $this->assertSame($user->id, $visit->acceptedBy?->id);
    }

    public function test_accepting_never_rewrites_the_stored_status(): void
    {
        $visit = Visit::factory()->accepted()->create()->fresh();

        $this->assertSame(
            Visit::STATUS_PLANNED,
            $visit->status,
            'Acceptance is recorded in accepted_at. Rewriting status would rewrite history.'
        );
        $this->assertTrue($visit->isClosed());
    }

    public function test_deleting_the_accepting_user_nulls_the_actor_and_keeps_the_visit(): void
    {
        $user  = User::factory()->create();
        $visit = Visit::factory()->accepted($user)->create();

        $user->delete();

        $visit = $visit->fresh();

        $this->assertNotNull($visit, 'Deleting a staff login must never delete delivery history.');
        $this->assertNull($visit->accepted_by_user_id);
        $this->assertNull($visit->acceptedBy);
        $this->assertTrue($visit->isClosed());
    }

    public function test_a_sent_back_visit_reads_sent_back(): void
    {
        $visit = Visit::factory()->sentBack()->create()->fresh();

        $this->assertTrue($visit->wasSentBack());
        $this->assertSame(Visit::STATE_SENT_BACK, $visit->state());
        $this->assertTrue($visit->isLocked());
        $this->assertNotNull($visit->send_back_reason);
    }

    public function test_a_send_back_answered_by_a_later_return_is_no_longer_outstanding(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->create([
            'project_id'   => $project->id,
            'status'       => Visit::STATUS_PLANNED,
            'sent_at'      => '2026-09-01 08:00:00',
            'sent_back_at' => '2026-09-05 09:00:00',
            'source_type'  => Visit::SOURCE_WORKSHEET,
            'source_id'    => $worksheet->id,
        ]);

        $this->signoff($worksheet, '2026-09-02 10:00:00');
        $this->assertTrue($visit->wasSentBack(), 'The send-back is newer than the return it rejected.');

        // The engineer answers it: a NEW signoff, after the send-back.
        $this->signoff($worksheet, '2026-09-08 11:00:00');

        $this->assertFalse(
            $visit->wasSentBack(),
            'Comparative, not a flag: a visit returned again is no longer awaiting rework.'
        );
        $this->assertSame(Visit::STATE_RETURNED, $visit->state());
    }

    public function test_acceptance_outranks_a_send_back(): void
    {
        $visit = Visit::factory()->sentBack()->create();
        $visit->forceFill(['accepted_at' => now()->addDay()])->save();

        $this->assertSame(Visit::STATE_ACCEPTED, $visit->fresh()->state());
    }

    public function test_rooms_in_scope_casts_to_an_array(): void
    {
        $visit = Visit::factory()->create([
            'rooms_in_scope' => ['Boardroom', 'Training Room 2'],
        ])->fresh();

        $this->assertSame(['Boardroom', 'Training Room 2'], $visit->rooms_in_scope);
    }

    // -- The Phase 45 regression: nothing reconstructed changes ---------------

    public function test_a_backfilled_worksheet_visit_still_reads_closed_and_reconstructed(): void
    {
        $project = Project::factory()->create(['status' => Project::STATUS_INSTALLING]);
        $signed  = Worksheet::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->backfilledFromWorksheet($signed)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $this->assertTrue($visit->isBackfilled());
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertTrue($visit->isClosed());
        $this->assertSame(Visit::STATE_CLOSED, $visit->state());
        $this->assertNull($visit->sent_at);
        $this->assertNull($visit->accepted_at);

        // And the at-rest disclosure phrase Plan 45-13 restored is unchanged.
        $this->assertSame(
            'reconstructed',
            CockpitSectionPresenter::visitQualifiers(new EloquentCollection([$visit]))
        );
    }

    public function test_a_backfilled_worksheet_visit_with_a_signoff_is_locked(): void
    {
        $project = Project::factory()->create();
        $signed  = Worksheet::factory()->create(['project_id' => $project->id]);
        $this->signoff($signed, '2024-06-03 15:00:00');

        $visit = Visit::factory()->backfilledFromWorksheet($signed)->create([
            'project_id' => $project->id,
        ]);

        // Correct, and worth stating: the work came back — years ago.
        $this->assertTrue($visit->isLocked());

        // It is also still CLOSED for every purpose that counts completion —
        // the progress ring asks isClosed(), which reads the stored status.
        $this->assertTrue($visit->isClosed());
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);

        // But state() reads RETURNED, not CLOSED, because the documented
        // resolution ORDER puts a derived return above a stored `completed`.
        // Pinned deliberately: a later plan that builds a "returned, awaiting
        // review" queue MUST filter it by `! isClosed()`, or the 24
        // reconstructed visits on live would appear as phantom review items
        // for work finished years ago.
        $this->assertSame(Visit::STATE_RETURNED, $visit->state());
    }

    public function test_the_progress_ring_counts_an_accepted_visit_as_done(): void
    {
        $project = Project::factory()->create();

        Visit::factory()->backfilledFromWorksheet()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        $planned = Visit::factory()->planned()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        $presenter = app(CockpitModulePresenter::class);

        $before = $presenter->progress($project->fresh(), 'worksheet');
        $this->assertSame(1, $before['completed']);
        $this->assertSame(2, $before['total']);

        $planned->forceFill([
            'accepted_at'         => now(),
            'accepted_by_user_id' => User::factory()->create()->id,
        ])->save();

        $after = $presenter->progress($project->fresh(), 'worksheet');

        $this->assertSame(2, $after['completed'], 'An accepted visit is done; the ring must not go backwards.');
        $this->assertSame(100, $after['percent']);
    }

    // -- Task 3: anti-rot ----------------------------------------------------

    public function test_the_stored_status_vocabulary_did_not_grow(): void
    {
        // The day somebody adds a stored `returned`, this fails — and they
        // have to argue with the migration docblock rather than silently
        // create the second source of truth it forbids, and rewrite the 24
        // reconstructed rows on live to match.
        $this->assertSame(
            ['planned', 'completed'],
            Visit::STATUSES,
            'Phase 46 layers DERIVED states on top of the stored vocabulary; it never grows it.'
        );
    }

    public function test_every_derived_state_is_reachable_from_a_factory_state(): void
    {
        $producers = [
            Visit::STATE_PLANNED   => fn (): Visit => Visit::factory()->planned()->create(),
            Visit::STATE_SENT      => fn (): Visit => Visit::factory()->sent()->create(),
            Visit::STATE_RETURNED  => fn (): Visit => Visit::factory()->returned()->create(),
            Visit::STATE_SENT_BACK => fn (): Visit => Visit::factory()->sentBack()->create(),
            Visit::STATE_ACCEPTED  => fn (): Visit => Visit::factory()->accepted()->create(),
            Visit::STATE_CLOSED    => fn (): Visit => Visit::factory()->backfilledFromWorksheet()->create(),
        ];

        foreach (Visit::STATES as $state) {
            $this->assertArrayHasKey(
                $state,
                $producers,
                "No factory state produces {$state} — a state that renders nowhere is a state nobody can test."
            );

            $this->assertSame($state, ($producers[$state])()->fresh()->state());
        }

        $this->assertCount(
            count(Visit::STATES),
            $producers,
            'This map and Visit::STATES move together, on purpose.'
        );
    }

    // -- Helpers -------------------------------------------------------------

    private function survey(Project $project): SiteSurvey
    {
        return SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);
    }

    private function signoff(Worksheet $worksheet, string $signedAt): WorksheetSignoff
    {
        return WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => $signedAt,
        ]);
    }
}

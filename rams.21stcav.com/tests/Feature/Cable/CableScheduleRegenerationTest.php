<?php

namespace Tests\Feature\Cable;

use App\Jobs\BuildCableScheduleJob;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Quick task 260712-ip3 — Regenerate button on the cable schedule edit
 * page. REUSES the existing `cable-schedules.retry-generation` route +
 * `CableScheduleController::retryGeneration()` action; this suite just
 * pins that the button is visible when the policy allows, disabled when
 * generation is already in flight, hidden when the policy denies, and
 * that submitting it dispatches the build job and clears prior items.
 */
class CableScheduleRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private CableSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create(['user_id' => $this->user->id]);

        $this->schedule = CableSchedule::create([
            'user_id'      => $this->user->id,
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name ?? 'Test project',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);
    }

    public function test_regenerate_button_visible_on_edit_page_for_authorised_user(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('cable-schedules.edit', $this->schedule))
            ->assertOk();

        $response->assertSee('↻ Regenerate', false);
        $response->assertSee(route('cable-schedules.retry-generation', $this->schedule), false);
    }

    public function test_regenerate_button_disabled_when_status_generating(): void
    {
        $this->schedule->update(['status' => CableSchedule::STATUS_GENERATING]);

        $html = $this->actingAs($this->user)
            ->get(route('cable-schedules.edit', $this->schedule))
            ->assertOk()
            ->getContent();

        // The button IS still rendered (so the user can see the state),
        // but MUST carry both the disabled attribute AND the
        // pointer-events:none inline style so mistaken clicks are inert.
        $this->assertStringContainsString('↻ Regenerate', $html);
        $this->assertMatchesRegularExpression(
            '/↻ Regenerate.*disabled/s',
            $html,
            'When status is generating the Regenerate button must carry the disabled attribute.'
        );
        $this->assertStringContainsString('pointer-events:none', $html);
    }

    public function test_regenerate_button_hidden_when_user_lacks_update_permission(): void
    {
        // The CableSchedulePolicy currently returns true for every
        // authenticated user (shared workspace); force the gate to deny
        // for this test so the Blade `can('update', $schedule)` check
        // hides the button. This proves the template respects the
        // policy rather than the policy's current permissive state.
        Gate::before(fn () => false);

        $html = $this->actingAs($this->user)
            ->get(route('cable-schedules.edit', $this->schedule))
            ->assertOk()
            ->getContent();

        // Assert the absence of the CONTROL, not its label. The visible
        // caption "↻ Regenerate" also appears in document-edit-drawer's
        // JavaScript copy ("…use the \"↻ Regenerate\" button…"), which
        // renders regardless of the policy — so a label check passes or
        // fails for reasons that have nothing to do with authorization,
        // and would silently stop testing anything if the caption changed.
        // The retry-generation form action is the thing the @if at
        // cable-schedule/edit.blade.php:25 actually gates.
        $this->assertStringNotContainsString(
            route('cable-schedules.retry-generation', $this->schedule),
            $html,
            'The retry-generation form must not be rendered when the update policy denies.'
        );

        // And the submit control itself must be gone with it — pinned via
        // the button's data-confirm hook rather than its caption.
        $this->assertStringNotContainsString(
            'data-confirm="Regenerate this cable schedule?',
            $html,
            'The Regenerate submit button must be hidden when the update policy denies.'
        );
    }

    public function test_regenerate_button_submit_dispatches_job(): void
    {
        Bus::fake();

        // Seed a prior item so we can prove the regenerate flow clears it.
        CableScheduleItem::create([
            'cable_schedule_id' => $this->schedule->id,
            'cable_id'          => 'CAB-STALE',
            'from_location'     => 'stale source',
            'to_location'       => 'stale destination',
            'cable_type'        => 'Cat6',
            'sort_order'        => 0,
        ]);
        $this->assertSame(1, $this->schedule->fresh()->items()->count());

        $this->actingAs($this->user)
            ->post(route('cable-schedules.retry-generation', $this->schedule))
            ->assertStatus(302);

        $fresh = $this->schedule->fresh();
        $this->assertSame(CableSchedule::STATUS_GENERATING, $fresh->status,
            'Regenerate must flip the schedule status to generating.');
        $this->assertSame(0, $fresh->items()->count(),
            'Regenerate must clear existing cable_schedule_items.');

        Bus::assertDispatched(BuildCableScheduleJob::class,
            fn (BuildCableScheduleJob $job) => true);
    }
}

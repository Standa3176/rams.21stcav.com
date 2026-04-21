<?php

namespace Tests\Feature\TimeEntries;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /time-entries/{entry}/heartbeat feature coverage — Plan 15-02 (INST-04d).
 *
 * Ownership is strict: only the entry's user may heartbeat (T-15-02-01).
 * Rate-limited to 10/min via the `throttle:10,1` middleware on the route.
 */
class TimeEntryHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_returns_204_for_owner(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => TimeEntry::CATEGORY_INSTALLATION,
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->postJson("/time-entries/{$entry->id}/heartbeat");

        $response->assertNoContent();

        $fresh = $entry->fresh();
        $this->assertLessThanOrEqual(
            3,
            (int) $fresh->last_heartbeat_at->diffInSeconds(now()),
        );
    }

    public function test_heartbeat_returns_403_for_non_owner(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $project  = Project::factory()->create(['user_id' => $owner->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $owner->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($stranger)->postJson("/time-entries/{$entry->id}/heartbeat");

        $response->assertForbidden();
    }

    public function test_heartbeat_returns_422_for_closed_entry(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->closed()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        $response = $this->actingAs($user)->postJson("/time-entries/{$entry->id}/heartbeat");

        $response->assertStatus(422);
    }

    /**
     * @group rate-limit
     */
    public function test_heartbeat_route_rate_limited(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $entry = TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'category'   => TimeEntry::CATEGORY_INSTALLATION,
        ]);

        // Fire 11 heartbeats — the 11th must be throttled (429)
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->actingAs($user)->postJson("/time-entries/{$entry->id}/heartbeat");
        }

        $this->assertSame(429, $last->status());
    }
}

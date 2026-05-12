<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 Plan 02 Task 1 — T-22-A4 cross-project FK injection guard.
 *
 * HIGH-severity threat: an engineer working on Project A submits an
 * `items[N][source_device_id]` pointing at a Device that belongs to
 * Project B. Eloquent's `exists:devices,id` rule is NECESSARY (the device
 * row exists) but NOT SUFFICIENT (it would happily save the cross-project
 * FK). The controller MUST walk every submitted device_id and reject with
 * 422 on any project_id mismatch.
 *
 * Additional coverage:
 *   - JSON path (putJson) returns 422 with assertJsonValidationErrors
 *   - Non-existent device_id is rejected (standard exists rule)
 *   - T-22-A1 (mass assignment of user_id) silently dropped by $fillable
 *
 * Critical assertion: a pre-seeded item exists BEFORE the malicious submit.
 * After the validation failure, that pre-seeded item is unchanged — proving
 * the guard fires BEFORE `items()->delete()` (no destructive write on
 * failed validation).
 *
 * @see app/Http/Controllers/CableScheduleController.php@update
 * @see .planning/phases/22-cable-schedule-with-port-level-fks/22-RESEARCH.md §"Security Domain"
 */
class CableScheduleCrossProjectFkInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_project_source_device_returns_422_t22_a4(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $projectA->id,
            'project_name' => 'A',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        // Device belongs to Project B — cross-project injection attempt.
        $bDevice = Device::create([
            'project_id'   => $projectB->id,
            'description'  => 'Crestron HD-MD-400 HDMI Multiformat Receiver',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'qty'          => 1,
        ]);

        // Pre-seed an existing item — the guard MUST fire BEFORE any items()->delete().
        // If the guard fires after the delete, this row would be wiped even though
        // the request failed validation. The pre-seeded row is the canary.
        $schedule->items()->create([
            'sort_order'    => 0,
            'from_location' => 'Original-From',
            'to_location'   => 'Original-To',
            'cable_type'    => 'HDMI',
        ]);

        $response = $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location'    => 'Bar',
                        'to_location'      => 'Display',
                        'source_device_id' => $bDevice->id,
                    ],
                ],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['items.0.source_device_id']);

        // Guard rejected; existing item still present (proves no destructive write
        // happened — items()->delete() was NOT reached before validation failed).
        $fresh = $schedule->fresh();
        $this->assertSame(1, $fresh->items()->count());
        $this->assertSame('Original-From', $fresh->items->first()->from_location);
    }

    public function test_cross_project_dest_device_returns_422_t22_a4(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $projectA->id,
            'project_name' => 'A',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $bDevice = Device::create([
            'project_id'   => $projectB->id,
            'description'  => 'Samsung QM65 65" 4K Display',
            'manufacturer' => 'Samsung',
            'model'        => 'QM65',
            'qty'          => 1,
        ]);

        $response = $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location'  => 'Bar',
                        'to_location'    => 'Display',
                        'dest_device_id' => $bDevice->id,
                    ],
                ],
            ]);

        $response->assertStatus(302);
        // Phase 22 WR-02: per-side error keying — when only dest_device_id is the
        // offender, the validation error must be attached to that field (not the
        // unrelated source_device_id input). Locks the corrected behaviour.
        $response->assertSessionHasErrors(['items.0.dest_device_id']);
        $errorBag = session('errors');
        $this->assertNotNull($errorBag);
        $this->assertFalse(
            $errorBag->has('items.0.source_device_id'),
            'source_device_id should NOT carry an error when only dest_device_id is cross-project.'
        );
    }

    public function test_cross_project_source_device_returns_422_for_json_request(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $projectA->id,
            'project_name' => 'A',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $bDevice = Device::create([
            'project_id'   => $projectB->id,
            'description'  => 'Crestron HD-MD-400',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'qty'          => 1,
        ]);

        $response = $this->actingAs($user)
            ->putJson(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location'    => 'Bar',
                        'to_location'      => 'Display',
                        'source_device_id' => $bDevice->id,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.source_device_id']);
    }

    public function test_nonexistent_device_id_returns_422(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'A',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location'    => 'Bar',
                        'to_location'      => 'Display',
                        'source_device_id' => 999999,
                    ],
                ],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['items.0.source_device_id']);
    }

    public function test_mass_assignment_of_user_id_is_dropped_t22_a1(): void
    {
        // T-22-A1 — even when an attacker injects extra keys like user_id,
        // the $fillable whitelist on CableScheduleItem silently drops them.
        // The schedule's owner is never mutated.
        $user = User::factory()->create();
        $attacker = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'A',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location' => 'Bar',
                        'to_location'   => 'Display',
                        // Mass-assignment attack — these aren't in $fillable
                        // and must be silently dropped.
                        'user_id'       => $attacker->id,
                        'admin_only'    => 1,
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $persistedItem = $schedule->fresh()->items->first();
        $this->assertNotNull($persistedItem);
        // schedule owner unchanged
        $this->assertSame($user->id, $schedule->fresh()->user_id);
    }
}

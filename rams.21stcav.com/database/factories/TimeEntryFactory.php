<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 *
 * NOTE: References App\Models\TimeEntry which Plan 14-02 will create.
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'project_id'        => Project::factory(),
            'user_id'           => User::factory(),
            'clocked_in_at'     => now(),
            'clocked_out_at'    => null,
            'last_heartbeat_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'clocked_in_at'  => now()->subHours(2),
            'clocked_out_at' => now(),
        ]);
    }
}

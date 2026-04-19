<?php

namespace Database\Factories;

use App\Models\CableSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CableSchedule>
 */
class CableScheduleFactory extends Factory
{
    protected $model = CableSchedule::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'project_id'      => Project::factory(),
            'project_ref'     => fake()->bothify('##C?#####'),
            'project_name'    => fake()->company() . ' AV Refresh',
            'client_name'     => fake()->company(),
            'source_filename' => 'cable-schedule-' . fake()->uuid() . '.xlsx',
            'status'          => CableSchedule::STATUS_GENERATING,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worksheet>
 */
class WorksheetFactory extends Factory
{
    protected $model = Worksheet::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'project_id'   => Project::factory(),
            'project_ref'  => fake()->bothify('##C?#####'),
            'project_name' => fake()->company() . ' AV Refresh',
            'client_name'  => fake()->company(),
            'site_address' => fake()->address(),
            'filename'     => 'worksheet-' . fake()->uuid() . '.docx',
            'status'       => Worksheet::STATUS_GENERATING,
        ];
    }
}

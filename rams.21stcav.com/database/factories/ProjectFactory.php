<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'name'            => fake()->company() . ' AV Installation',
            'client_name'     => fake()->company(),
            'site_address'    => fake()->address(),
            'quote_reference' => 'Q-' . fake()->numerify('######'),
            'status'          => Project::STATUS_QUOTE_IMPORTED,
        ];
    }
}

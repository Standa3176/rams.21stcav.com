<?php

namespace Database\Factories;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OmManual>
 */
class OmManualFactory extends Factory
{
    protected $model = OmManual::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'project_id'   => Project::factory(),
            'project_ref'  => fake()->bothify('##C?#####'),
            'project_name' => fake()->company() . ' AV Refresh',
            'client_name'  => fake()->company(),
            'site_address' => fake()->address(),
            'filename'     => 'om-manual-' . fake()->uuid() . '.docx',
            'status'       => OmManual::STATUS_GENERATING,
        ];
    }
}

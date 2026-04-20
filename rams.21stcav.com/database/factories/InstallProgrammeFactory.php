<?php

namespace Database\Factories;

use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallProgramme>
 */
class InstallProgrammeFactory extends Factory
{
    protected $model = InstallProgramme::class;

    public function definition(): array
    {
        return [
            'project_id'         => Project::factory(),
            'generated_by'       => User::factory(),
            'status'             => InstallProgramme::STATUS_ACTIVE,
            'generated_at'       => now(),
            'activated_at'       => now(),
            'planned_start_date' => now()->toDateString(),
            'planned_end_date'   => now()->addDays(3)->toDateString(),
            'notes'              => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status'       => InstallProgramme::STATUS_DRAFT,
            'activated_at' => null,
        ]);
    }
}

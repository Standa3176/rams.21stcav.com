<?php

namespace Database\Factories;

use App\Models\LabourResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabourResource>
 *
 * Phase 44 Plan 01 Task 2. Default: an active engineer with no email/phone
 * and no linked user — mirrors the common case of an engineer who is not
 * yet given an account (see LabourResource docblock, D-01/D-02).
 */
class LabourResourceFactory extends Factory
{
    protected $model = LabourResource::class;

    public function definition(): array
    {
        return [
            'name'      => $this->faker->name(),
            'email'     => null,
            'phone'     => null,
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'user_id'   => null,
            'is_active' => true,
        ];
    }
}

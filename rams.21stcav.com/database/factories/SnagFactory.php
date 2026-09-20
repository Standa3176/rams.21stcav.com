<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Snag;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Snag>
 *
 * Phase 46 Plan 02 Task 2. Default: an OPEN snag on a fresh project and visit,
 * with a title and no detail — the minimal honest row a PM raise produces
 * (46-CONTEXT.md D-03). Nothing here says what happens next, because nothing
 * in Phase 46 does.
 *
 * `raised_by_user_id` is null by default rather than minting a user: the
 * column nulls when an account goes, so null is a state every consumer must
 * already handle.
 */
class SnagFactory extends Factory
{
    protected $model = Snag::class;

    public function definition(): array
    {
        return [
            'project_id'        => Project::factory(),
            'visit_id'          => Visit::factory(),
            'title'             => 'Trunking not made good in the comms room',
            'detail'            => null,
            'room_name'         => null,
            'raised_by_user_id' => null,
            'status'            => Snag::STATUS_OPEN,
        ];
    }
}

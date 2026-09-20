<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Visit;
use App\Models\VisitNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitNote>
 *
 * Phase 46 Plan 07 Task 1. Default: one office note on a fresh project and
 * visit, with no author — `user_id` nulls when an account goes, so null is a
 * state every consumer must already handle (the same posture SnagFactory
 * takes with `raised_by_user_id`).
 */
class VisitNoteFactory extends Factory
{
    protected $model = VisitNote::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'visit_id'   => Visit::factory(),
            'user_id'    => null,
            'body'       => 'Cable route photo is missing for the second floor.',
        ];
    }
}

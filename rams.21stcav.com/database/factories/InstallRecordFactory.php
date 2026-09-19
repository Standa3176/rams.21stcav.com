<?php

namespace Database\Factories;

use App\Models\InstallRecord;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallRecord>
 *
 * Phase 45 Plan 04 Task 1. The record carries no content of its own — it is
 * durable parentage and nothing else — so the default is simply a fresh
 * project's record. Note the unique index on `project_id`: two records made
 * for the SAME project will (correctly) throw.
 */
class InstallRecordFactory extends Factory
{
    protected $model = InstallRecord::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
        ];
    }
}

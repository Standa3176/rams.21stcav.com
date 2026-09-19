<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 *
 * Phase 45 Plan 02 Task 2. Default: a completed `install` visit on a fresh
 * project, NOT backfilled, no assigned labour and no wrapped source — the
 * minimal valid row. The `backfilledFrom*` states are the shapes the 45-05
 * backfill command produces, so its own tests can lean on them.
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'project_id'          => Project::factory(),
            'install_record_id'   => null,
            'type'                => Visit::TYPE_INSTALL,
            'status'              => Visit::STATUS_COMPLETED,
            'scheduled_date'      => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'labour_resource_ids' => [],
            'source_type'         => null,
            'source_id'           => null,
            'is_backfilled'       => false,
            'title'               => fake()->company() . ' install visit',
            'summary'             => null,
        ];
    }

    /**
     * A visit reconstructed from a SiteSurvey (D-01/D-03 — surveys type as
     * `site_survey`).
     */
    public function backfilledFromSurvey(?SiteSurvey $survey = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type'          => Visit::TYPE_SITE_SURVEY,
            'is_backfilled' => true,
            'source_type'   => Visit::SOURCE_SITE_SURVEY,
            'source_id'     => $survey?->id,
        ]);
    }

    /**
     * A visit reconstructed from a signed Worksheet. Typed `install` because a
     * signed worksheet proves attendance but not what was done — the
     * `is_backfilled` marker says the type is an inference (D-02).
     */
    public function backfilledFromWorksheet(?Worksheet $worksheet = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type'          => Visit::TYPE_INSTALL,
            'is_backfilled' => true,
            'source_type'   => Visit::SOURCE_WORKSHEET,
            'source_id'     => $worksheet?->id,
        ]);
    }
}

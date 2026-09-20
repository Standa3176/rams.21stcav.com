<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 *
 * Phase 45 Plan 02 Task 2. Default: a completed `install` visit on a fresh
 * project, NOT backfilled, no assigned labour and no wrapped source — the
 * minimal valid row. The `backfilledFrom*` states are the shapes the 45-05
 * backfill command produces, so its own tests can lean on them.
 *
 * Phase 46 adds the lifecycle states. The one that matters is `returned()`:
 * it puts the return on the SOURCE record (a submitted survey / a signed
 * worksheet), never on the visit, because there is no `returned_at` column to
 * put it in and inventing one in a factory would model a shape the schema
 * deliberately does not have.
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

    // -- Phase 46 lifecycle states -------------------------------------------

    /**
     * Planned but not yet sent. The stored status is `planned`; every
     * lifecycle column stays NULL.
     */
    public function planned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Visit::STATUS_PLANNED,
        ]);
    }

    /**
     * Out with the engineer: the link has been issued.
     *
     * Note the stored status stays `planned` -- `sent` is DERIVED from
     * `sent_at` and is deliberately NOT a stored status value.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'  => Visit::STATUS_PLANNED,
            'sent_at' => now()->subDays(3),
        ]);
    }

    /**
     * The engineer's work has come back.
     *
     * THE RETURN IS WRITTEN ON THE SOURCE, not on the visit. When the visit
     * has no source yet, one is created so the return has somewhere true to
     * live.
     */
    public function returned(): static
    {
        return $this->sent()->afterCreating(function (Visit $visit): void {
            $source = $visit->source();

            if ($source === null) {
                $worksheet = Worksheet::factory()->create([
                    'project_id' => $visit->project_id,
                ]);

                $visit->forceFill([
                    'source_type' => Visit::SOURCE_WORKSHEET,
                    'source_id'   => $worksheet->id,
                ])->save();

                $source = $worksheet;
            }

            if ($source instanceof SiteSurvey) {
                $source->forceFill(['submitted_at' => now()->subDays(2)])->save();

                return;
            }

            WorksheetSignoff::create([
                'worksheet_id'         => $source->id,
                'client_name'          => 'A Client',
                'signature_png_base64' => 'iVBORw0KGgo=',
                'signed_with_comments' => false,
                'signed_at'            => now()->subDays(2),
            ]);
        });
    }

    /**
     * Returned, then rejected by the PM for more information. `sent_back_at`
     * is AFTER the return, which is what `wasSentBack()` compares.
     */
    public function sentBack(): static
    {
        return $this->returned()->state(fn (array $attributes): array => [
            'sent_back_at'     => now()->subDay(),
            'send_back_reason' => 'Photos of the comms room are missing.',
        ]);
    }

    /**
     * Returned and accepted by a PM. Records who, because an accept with no
     * actor is repudiable (T-46-01-02).
     */
    public function accepted(?User $user = null): static
    {
        return $this->returned()->state(fn (array $attributes): array => [
            'accepted_at'         => now(),
            'accepted_by_user_id' => $user?->id ?? User::factory(),
        ]);
    }
}

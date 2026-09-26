<?php

namespace Database\Factories;

use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorksheetAdditionalKit>
 *
 * Phase 46.4 Plan 01 Task 2. Default: an OPEN row with NO engineer — the
 * D-02 fallback shape, which is the one most likely to be got wrong
 * downstream, so it is the one that costs nothing to hit by accident.
 *
 * ⚠️ The `marked()`, `amended()` and `reconciled()` states use forceFill,
 * because every column they touch is deliberately OFF `$fillable`. A state
 * that reached those columns through `state()` would be quietly proving the
 * opposite of what the model guarantees.
 */
class WorksheetAdditionalKitFactory extends Factory
{
    protected $model = WorksheetAdditionalKit::class;

    public function definition(): array
    {
        return [
            'worksheet_id'       => Worksheet::factory(),
            'room_name'          => fake()->randomElement(['Boardroom', 'Reception', 'Training Room']),
            'labour_resource_id' => null,
            'qty'                => fake()->numberBetween(1, 20),
            // No unit — D-10. The quantity and the description carry it all.
            'part_description'   => fake()->randomElement([
                'Cat6A U/FTP patch lead, 2m, grey',
                'Trunking, 50x50 white',
                'Backbox, 35mm',
                'HDMI 2.1 lead, 3m',
            ]),
            'sort_order'         => 0,
        ];
    }

    /** D-08 — marked for deletion, with the reason the office reads. */
    public function marked(string $reason = 'Ordered twice — only one fitted.'): static
    {
        return $this->afterCreating(function (WorksheetAdditionalKit $row) use ($reason): void {
            $row->forceFill([
                'marked_for_deletion_at' => now(),
                'deletion_reason'        => $reason,
                'marked_by_actor'        => 'ip:203.0.113.7|actor:' . substr(hash('sha256', 'factory'), 0, 16),
            ])->save();
        });
    }

    /** D-08 — one amendment on the trail. */
    public function amended(): static
    {
        return $this->afterCreating(function (WorksheetAdditionalKit $row): void {
            $row->forceFill([
                'amendments' => [[
                    'at'      => now()->toIso8601String(),
                    'actor'   => 'ip:203.0.113.7|actor:' . substr(hash('sha256', 'factory'), 0, 16),
                    'changes' => ['qty' => ['from' => $row->qty, 'to' => $row->qty + 1]],
                ]],
                'amended_at' => now(),
            ])->save();
        });
    }

    /** The office has dealt with it — D-09's other half of "not yours any more". */
    public function reconciled(): static
    {
        return $this->afterCreating(function (WorksheetAdditionalKit $row): void {
            $row->forceFill(['reconciled_at' => now()])->save();
        });
    }
}

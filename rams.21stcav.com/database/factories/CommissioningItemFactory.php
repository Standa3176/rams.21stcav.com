<?php

namespace Database\Factories;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissioningItem>
 *
 * NOTE: References App\Models\CommissioningItem which Plan 16-02 will create.
 * Factory file exists now so Wave 0 tests can statically reference it; tests
 * that instantiate CommissioningItem::factory() will fail red until the model
 * + migration ship in Plan 16-02 (intended Wave 0 scaffolding state).
 */
class CommissioningItemFactory extends Factory
{
    protected $model = CommissioningItem::class;

    public function definition(): array
    {
        // Equipment → category pairings that config/commissioning.php's
        // keyword_map actually matches for each equipment. Callers that pass
        // `equipment_name` but omit `category` still get a category consistent
        // with their chosen equipment thanks to the `configure()` hook below.
        // CommissioningSyncServiceTest's restore path depends on this: the
        // sync service only restores items whose (install_task_id, category)
        // appears in the expected diff, and the expected diff is driven by
        // keyword_map hits on equipment_name.
        $pair = $this->faker->randomElement([
            ['equipment_name' => 'LG 75 Display',   'category' => 'display'],
            ['equipment_name' => 'Poly Studio X70', 'category' => 'vtc'],
            ['equipment_name' => 'Crestron CP3',    'category' => 'control'],
        ]);

        return [
            'install_programme_id' => InstallProgramme::factory(),
            'install_task_id'      => null,
            'equipment_name'       => $pair['equipment_name'],
            'room_name'            => $this->faker->randomElement(['Boardroom', 'Huddle Room 1', 'Reception']),
            'category'             => $pair['category'],
            'status'               => 'pending',
            'evidence_photo_path'  => null,
            'notes'                => null,
            'signed_off_by'        => null,
            'signed_off_at'        => null,
        ];
    }

    /**
     * When a test overrides `equipment_name` without an explicit `category`,
     * the factory's default pairing breaks — e.g. `equipment_name => $task->
     * equipment_name` (LG 75 Display) combined with a random "vtc" category
     * would fail the restore path in CommissioningSyncServiceTest because
     * (task, vtc) isn't in the LG 75 keyword_map expected set.
     *
     * This hook re-pairs category to a keyword_map-compatible value whenever
     * the category in the state is inconsistent with the (possibly overridden)
     * equipment_name. Tests that explicitly set BOTH fields are left alone.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CommissioningItem $item): void {
            // ── 1. Re-pair category to equipment_name when needed ────────────
            //
            // When a test overrides `equipment_name` without an explicit
            // `category`, the factory's default pairing breaks — e.g.
            // `equipment_name => $task->equipment_name` (LG 75 Display)
            // combined with a random "vtc" category would fail
            // CommissioningSyncServiceTest's restore path because
            // (task, vtc) isn't in the LG 75 keyword_map expected set.
            $keywordMap = config('commissioning.keyword_map', []);
            $nameLower  = mb_strtolower((string) $item->equipment_name);

            $matchesExisting = false;
            foreach (($keywordMap[$item->category] ?? []) as $kw) {
                if ($kw !== '' && str_contains($nameLower, mb_strtolower($kw))) {
                    $matchesExisting = true;
                    break;
                }
            }
            if (! $matchesExisting) {
                // Pick the first category whose keyword list hits equipment_name.
                foreach ($keywordMap as $category => $keywords) {
                    foreach ($keywords as $kw) {
                        if ($kw !== '' && str_contains($nameLower, mb_strtolower($kw))) {
                            $item->category = $category;
                            break 2;
                        }
                    }
                }
                // No keyword match → leave category as-is. Real usage never
                // hits this because the generator only creates (task, category)
                // pairs that ARE in the keyword_map.
            }
        })->afterCreating(function (CommissioningItem $item): void {
            // ── 2. Backfill install_task_id from programme tasks when null ───
            //
            // The generator ALWAYS writes a real FK, so in production
            // `install_task_id` is never null. Tests that omit it end up with
            // orphan items that the sync service can't match against any
            // (task_id, category) key in the expected index — which then
            // soft-deletes them on re-sync. ResyncDiffTest's "adds items for
            // new tasks" asserts `removed === 0`, so we link orphan factory
            // items to a matching task on the same programme at create time.
            if ($item->install_task_id !== null) {
                return;
            }

            $task = InstallTask::query()
                ->where('install_programme_id', $item->install_programme_id)
                ->where('equipment_name', $item->equipment_name)
                ->whereNotIn('id', function ($query) use ($item) {
                    // Avoid double-linking — one task, one item per category.
                    $query->select('install_task_id')
                        ->from('commissioning_items')
                        ->where('install_programme_id', $item->install_programme_id)
                        ->where('category', $item->category)
                        ->whereNotNull('install_task_id');
                })
                ->first();

            if ($task !== null) {
                $item->forceFill(['install_task_id' => $task->id])->save();
            }
        });
    }

    public function passed(): static
    {
        return $this->state(fn () => [
            'status'        => 'pass',
            'signed_off_by' => 'Test Engineer',
            'signed_off_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status'              => 'fail',
            'notes'               => 'Fail reason',
            'evidence_photo_path' => 'commissioning-evidence/test.jpg',
            'signed_off_by'       => 'Test Engineer',
            'signed_off_at'       => now(),
        ]);
    }
}

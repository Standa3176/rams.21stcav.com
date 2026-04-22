<?php

namespace Database\Factories;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
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
        return [
            'install_programme_id' => InstallProgramme::factory(),
            'install_task_id'      => null,
            'equipment_name'       => $this->faker->randomElement(['LG 75 Display', 'Poly Studio X70', 'Crestron CP3']),
            'room_name'            => $this->faker->randomElement(['Boardroom', 'Huddle Room 1', 'Reception']),
            'category'             => $this->faker->randomElement(['power', 'display', 'audio', 'vtc', 'control', 'network']),
            'status'               => 'pending',
            'evidence_photo_path'  => null,
            'notes'                => null,
            'signed_off_by'        => null,
            'signed_off_at'        => null,
        ];
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

<?php

namespace Database\Factories;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallTask>
 */
class InstallTaskFactory extends Factory
{
    protected $model = InstallTask::class;

    public function definition(): array
    {
        $equipment = $this->faker->randomElement([
            'Samsung QM75B display', 'Logitech Tap', 'Shure MXA920',
            'Crestron DM NVX', 'Extron DTP2', 'QSC Q-SYS Core',
        ]);
        $room = $this->faker->randomElement([
            'Boardroom A', 'Huddle Room 1', 'Training Room', 'Client Suite',
        ]);

        return [
            'install_programme_id' => InstallProgramme::factory(),
            'room_name'            => $room,
            'room_ref'             => null,
            'equipment_name'       => $equipment,
            'quantity'             => 1,
            'equipment_category'   => 'hardware',
            'task_type'            => InstallTask::TYPE_INSTALL,
            'title'                => "Install {$equipment}",
            'description'          => null,
            'status'               => InstallTask::STATUS_PENDING,
            'blocked_reason'       => null,
            'sort_order'           => 0,
            'notes'                => null,
            'assigned_to'          => null,
            'assigned_at'          => null,
            'started_at'           => null,
            'completed_at'         => null,
            'sign_off_required'    => true,
            'planned_start_date'   => null,
            'planned_end_date'     => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status'     => InstallTask::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'status'       => InstallTask::STATUS_COMPLETE,
            'started_at'   => now()->subHour(),
            'completed_at' => now(),
        ]);
    }
}

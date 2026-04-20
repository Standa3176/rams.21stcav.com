<?php

namespace Database\Factories;

use App\Models\InstallTask;
use App\Models\InstallTaskPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstallTaskPhoto>
 *
 * NOTE: References App\Models\InstallTaskPhoto which Plan 14-02 will create.
 * Factory file exists now so Wave 0 tests can statically reference it; tests
 * that instantiate InstallTaskPhoto::factory() will fail red until the model
 * + migration ship in Plan 14-02 (intended Wave 0 scaffolding state).
 */
class InstallTaskPhotoFactory extends Factory
{
    protected $model = InstallTaskPhoto::class;

    public function definition(): array
    {
        return [
            'install_task_id' => InstallTask::factory(),
            'filename'        => 'task-photos/test/' . Str::uuid() . '.jpg',
            'original_name'   => 'photo.jpg',
            'mime_type'       => 'image/jpeg',
            'caption'         => null,
            'sort_order'      => 0,
        ];
    }
}

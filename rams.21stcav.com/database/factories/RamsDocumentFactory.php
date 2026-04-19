<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RamsDocument>
 */
class RamsDocumentFactory extends Factory
{
    protected $model = RamsDocument::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'project_id'   => Project::factory(),
            'project_ref'  => fake()->bothify('##C?#####'),
            'project_name' => fake()->company() . ' AV Refresh',
            'client_name'  => fake()->company(),
            'site_address' => fake()->address(),
            // ai_provider / ai_model / form_data are NOT NULL on rams_documents — must be seeded.
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet-4-6',
            'form_data'    => [],
            'filename'     => 'rams-' . fake()->uuid() . '.docx',
            'status'       => RamsDocument::STATUS_AWAITING_REVIEW,
        ];
    }
}

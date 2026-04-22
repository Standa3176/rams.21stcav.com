<?php

namespace Database\Factories;

use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissioningSignoff>
 *
 * NOTE: References App\Models\CommissioningSignoff which Plan 16-02 will create.
 * Factory file exists now so Wave 0 tests can statically reference it; tests
 * that instantiate CommissioningSignoff::factory() will fail red until the
 * model + migration ship in Plan 16-02 (intended Wave 0 scaffolding state).
 */
class CommissioningSignoffFactory extends Factory
{
    protected $model = CommissioningSignoff::class;

    public function definition(): array
    {
        return [
            'install_programme_id'   => InstallProgramme::factory(),
            'client_name'            => $this->faker->name(),
            'client_role'            => 'IT Manager',
            'client_company'         => $this->faker->company(),
            'signature_png_base64'   => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=',
            'certification_text'     => 'Test certification text',
            'snagging_pdf_path'      => 'snagging_test.pdf',
            'signed_at'              => now(),
            'signed_off_engineer_id' => User::factory(),
        ];
    }
}

<?php

namespace Tests\Feature\Commissioning;

use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * INST-05f — the signoff bottom-sheet Blade must render the Retina DPI-scaling
 * snippet, the three client inputs (name/role/company), and the certification
 * text from config.
 *
 * Red until Plan 05 ships _commissioning-signoff-sheet.blade.php.
 */
class SignoffSheetViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_signoff_sheet_view_contains_dpr_scaling_snippet(): void
    {
        [$programme] = $this->scaffoldProgramme();

        $rendered = View::make('commissioning._commissioning-signoff-sheet', [
            'programme' => $programme,
        ])->render();

        $this->assertStringContainsString('devicePixelRatio', $rendered);
        $this->assertStringContainsString('resize', $rendered);
        $this->assertStringContainsString('orientationchange', $rendered);
    }

    public function test_signoff_sheet_view_exposes_client_name_role_company_inputs(): void
    {
        [$programme] = $this->scaffoldProgramme();

        $rendered = View::make('commissioning._commissioning-signoff-sheet', [
            'programme' => $programme,
        ])->render();

        $this->assertStringContainsString('name="client_name"', $rendered);
        $this->assertStringContainsString('name="client_role"', $rendered);
        $this->assertStringContainsString('name="client_company"', $rendered);
    }

    public function test_signoff_sheet_view_shows_certification_text(): void
    {
        config()->set('commissioning.certification_text', 'TEST-CERT-TOKEN-' . uniqid());
        [$programme] = $this->scaffoldProgramme();

        $rendered = View::make('commissioning._commissioning-signoff-sheet', [
            'programme' => $programme,
        ])->render();

        // The rendered HTML should either inline the text OR reference it via
        // a JS data attribute the factory reads — we check either path.
        $this->assertTrue(
            str_contains($rendered, config('commissioning.certification_text'))
                || str_contains($rendered, 'certificationText'),
            'Signoff sheet must surface config(commissioning.certification_text) to the client.',
        );
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgramme(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        return [$programme];
    }
}

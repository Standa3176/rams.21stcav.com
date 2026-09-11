<?php

namespace Tests\Unit\Support\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Support\Rams\SectionComposers\EmergencyComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29 Plan 02 (RULE-08, D-05) — proves EmergencyComposer wires
 * SiteEmergencyResolver::resolve() into EmergencySectionDto's
 * nearestHospitalVerified/nearestHospitalResolvedText fields, so the
 * unified-composer render path (config('rams.unified_composer') — currently
 * false in production, per 29-MEASUREMENT.md) carries the same resolved
 * branch value the legacy blade sites will read once fixed in Plan 29-04.
 *
 * @see app/Support/Rams/SectionComposers/EmergencyComposer.php
 * @see app/Services/Rams/SiteEmergencyResolver.php
 */
class EmergencyComposerSiteEmergencyResolvedTest extends TestCase
{
    use RefreshDatabase;

    private const HOLD_POINT = 'Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)';

    private function makeRams(array $reviewedData): RamsDocument
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        return RamsDocument::create([
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'project_ref' => $project->ref ?? 'TEST-001',
            'project_name' => 'Test Project',
            'client_name' => 'Test Client',
            'site_address' => 'Test Address',
            'ai_provider' => 'claude',
            'ai_model' => 'claude-sonnet-4-6',
            'filename' => 'rams-test.docx',
            'status' => RamsDocument::STATUS_COMPLETED,
            'form_data' => [],
            'reviewed_data' => $reviewedData,
            'generated_data' => [],
        ]);
    }

    public function test_empty_site_emergency_composes_hold_point_branch(): void
    {
        $rams = $this->makeRams([
            'site_emergency' => [],
        ]);

        $dto = (new EmergencyComposer())->compose($rams);

        $this->assertFalse($dto->nearestHospitalVerified);
        $this->assertSame(self::HOLD_POINT, $dto->nearestHospitalResolvedText);
    }

    public function test_verified_site_emergency_composes_verified_branch(): void
    {
        $rams = $this->makeRams([
            'site_emergency' => [
                'nearest_hospital' => 'St Mary\'s Hospital',
                'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
            ],
        ]);

        $dto = (new EmergencyComposer())->compose($rams);

        $this->assertTrue($dto->nearestHospitalVerified);
        $this->assertStringContainsString('St Mary\'s Hospital', $dto->nearestHospitalResolvedText);
        $this->assertStringContainsString('123 Example Road, Testtown, TE5 7ST', $dto->nearestHospitalResolvedText);
    }
}

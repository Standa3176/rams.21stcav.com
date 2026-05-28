<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SurveyService::resolveProjectScopeForSurvey().
 *
 * Bug C (quick task 260528-h8e — 21CQ30485-03-OPS): SurveyService used to
 * read $project->works_description and $package->works_description for the
 * new survey's general_notes. Phase 22.1 D-LOCK (D-01 / D-02) intentionally
 * stopped populating both columns — the canonical scope source post-22.1
 * lives in $package->extracted_data['scope_of_works_bullets'] (computed at
 * approve-time) → 'overview' → 'room_overviews' concatenation. Legacy
 * works_description fields are preserved as last-resort fallbacks for older
 * projects (per planner constraint).
 *
 * Priority ladder:
 *   1. $package->extracted_data['scope_of_works_bullets']  (post-22.1 canonical)
 *   2. $package->extracted_data['overview']                (QuoteWerks verbatim)
 *   3. $package->extracted_data['room_overviews'][*]['overview']
 *   4. $project->works_description                         (legacy)
 *   5. $package->works_description                         (legacy)
 *   → null if all empty.
 *
 * Result is capped at 3000 chars (mb_substr) to match the survey edit
 * form's maxlength constraint.
 *
 * @see SurveyService::resolveProjectScopeForSurvey
 */
class SurveyServiceScopeSourceTest extends TestCase
{
    use RefreshDatabase;

    private SurveyService $service;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SurveyService::class);
        $this->user    = User::factory()->create();
        $this->project = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Test Project',
            'ref'          => 'TEST-H8E-001',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street, London, EC1A 1BB',
            'status'       => 'quote_imported',
        ]);
    }

    private function makePackage(array $extractedData, ?string $worksDescription = null): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id'        => $this->project->id,
            'user_id'           => $this->user->id,
            'quote_path'        => 'test/quote.pdf',
            'extracted_data'    => $extractedData,
            'works_description' => $worksDescription,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    // =========================================================================
    // PRIORITY LADDER
    // =========================================================================

    public function test_prefers_reviewed_scope_of_works_bullets_when_present(): void
    {
        $package = $this->makePackage([
            'scope_of_works_bullets' => [
                'Install 75" display.',
                'Run Cat5e patch leads.',
            ],
            'overview' => 'Should be ignored because bullets present.',
        ]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertIsString($result);
        $this->assertStringContainsString('Install 75" display.', $result);
        $this->assertStringContainsString('Run Cat5e patch leads.', $result);
        $this->assertStringNotContainsString('Should be ignored', $result);
    }

    public function test_falls_back_to_extracted_overview_when_bullets_missing(): void
    {
        $overview = 'Supply and install AV in the larger meeting room.';
        $package = $this->makePackage([
            'overview' => $overview,
        ]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertSame($overview, $result);
    }

    public function test_falls_back_to_concatenated_room_overviews_when_overview_missing(): void
    {
        $package = $this->makePackage([
            'room_overviews' => [
                ['room' => 'Larger Mtg Room', 'overview' => 'Sharp 75" with TCC2 ceiling mic.'],
                ['room' => 'Smaller',         'overview' => 'Sharp 55".'],
            ],
        ]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertIsString($result);
        $this->assertStringContainsString('Sharp 75" with TCC2 ceiling mic.', $result);
        $this->assertStringContainsString('Sharp 55".', $result);
        $this->assertStringContainsString('Larger Mtg Room', $result);
        $this->assertStringContainsString('Smaller', $result);
    }

    public function test_falls_back_to_legacy_works_description_when_modern_sources_empty(): void
    {
        $this->project->works_description = 'Legacy scope text.';
        $this->project->save();

        $package = $this->makePackage([]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertSame('Legacy scope text.', $result);
    }

    public function test_returns_null_when_no_source_has_content(): void
    {
        $this->project->works_description = null;
        $this->project->save();

        $package = $this->makePackage([]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertNull($result);
    }

    // =========================================================================
    // CAP (3000-char survey edit-form maxlength)
    // =========================================================================

    public function test_caps_result_at_3000_chars(): void
    {
        // 4000-char overview, no bullets / room_overviews.
        $bigOverview = str_repeat('A', 4000);
        $package = $this->makePackage([
            'overview' => $bigOverview,
        ]);

        $result = $this->service->resolveProjectScopeForSurvey($this->project, $package);

        $this->assertIsString($result);
        $this->assertLessThanOrEqual(3000, mb_strlen($result));
    }
}

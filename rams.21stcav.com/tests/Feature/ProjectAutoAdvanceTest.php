<?php

namespace Tests\Feature;

use App\Core\Modules\Projects\ProjectService;
use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ProjectAutoAdvanceTest — feature tests for auto-create on import (D-02)
 * and auto-advance lifecycle hooks (D-18, Hook 1 and Hook 2).
 *
 * Tests the wiring between:
 *   - QuoteImportService::import()   → ProjectService::create()        (auto-create)
 *   - QuoteImportService::confirm()  → ProjectService::transition()    (Hook 1)
 *   - SurveyService::complete()      → ProjectService::transition()    (Hook 2)
 *
 * @see D-02, D-18, Plan 01-04
 */
class ProjectAutoAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ─── Test 1: auto-create project on import when none exists ──────────────

    /**
     * @test
     */
    public function test_import_auto_creates_project_when_none_exists(): void
    {
        // Ensure no project exists for this client+site combination
        $this->assertDatabaseMissing('projects', [
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
        ]);

        $service = app(QuoteImportService::class);
        $package = $service->importFromData($this->user, [
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
            'ref'          => 'ABC123',
        ]);

        // A Project record must have been auto-created
        $this->assertDatabaseHas('projects', [
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
        ]);

        // The returned package must be linked to the new project
        $this->assertNotNull($package->project_id);

        $project = Project::find($package->project_id);
        $this->assertNotNull($project);
        $this->assertEquals('Acme Ltd', $project->client_name);
        $this->assertEquals('123 Main St', $project->site_address);
    }

    // ─── Test 2: no duplicate project created when one exists ────────────────

    /**
     * @test
     */
    public function test_import_does_not_create_project_when_one_exists(): void
    {
        // Pre-create a project for this client+site
        $existing = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Existing Project',
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $countBefore = Project::count();

        $service = app(QuoteImportService::class);
        $package = $service->importFromData($this->user, [
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
            'ref'          => 'ABC123',
        ]);

        // Project count must NOT have increased
        $this->assertEquals($countBefore, Project::count());

        // The package must be linked to the EXISTING project
        $this->assertEquals($existing->id, $package->project_id);
    }

    // ─── Test 3: confirm advances quote_imported → survey_pending ────────────

    /**
     * @test
     */
    public function test_quote_confirm_advances_project_to_survey_pending(): void
    {
        $project = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Test Project',
            'client_name'  => 'Client A',
            'site_address' => '1 Test Street',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user->id,
            'quote_filename' => 'test.pdf',
            'quote_path'     => 'quote-imports/test.pdf',
            'extracted_data' => [],
            'equipment_list' => [],
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        $service = app(QuoteImportService::class);
        $service->confirm($this->user, $package);

        // Project status must have advanced to survey_pending
        $this->assertEquals(Project::STATUS_SURVEY_PENDING, $project->fresh()->status);

        // ActivityLog must record the status change
        $this->assertDatabaseHas('project_activity_logs', [
            'project_id' => $project->id,
            'action'     => ProjectActivityLog::ACTION_STATUS_CHANGED,
            'to_status'  => Project::STATUS_SURVEY_PENDING,
        ]);
    }

    // ─── Test 4: confirm does NOT advance if project is not in quote_imported ─

    /**
     * @test
     */
    public function test_quote_confirm_does_not_advance_when_project_not_in_quote_imported(): void
    {
        $project = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Advanced Project',
            'client_name'  => 'Client B',
            'site_address' => '2 Test Road',
            'status'       => Project::STATUS_ENGINEERING,
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user->id,
            'quote_filename' => 'test2.pdf',
            'quote_path'     => 'quote-imports/test2.pdf',
            'extracted_data' => [],
            'equipment_list' => [],
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        $service = app(QuoteImportService::class);
        $service->confirm($this->user, $package);

        // Project status must remain as engineering — guard must have skipped
        $this->assertEquals(Project::STATUS_ENGINEERING, $project->fresh()->status);
    }

    // ─── Test 5: survey submission advances survey_pending → engineering ──────

    /**
     * @test
     */
    public function test_survey_submission_advances_project_to_engineering(): void
    {
        $project = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Survey Project',
            'client_name'  => 'Client C',
            'site_address' => '3 Survey Lane',
            'status'       => Project::STATUS_SURVEY_PENDING,
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $this->user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
        ]);

        $service = app(SurveyService::class);
        $service->complete($survey, $this->user);

        // Project status must have advanced to engineering
        $this->assertEquals(Project::STATUS_ENGINEERING, $project->fresh()->status);
    }

    // ─── Test 6: auto-advance failure does not block primary action ───────────

    /**
     * @test
     */
    public function test_auto_advance_failure_does_not_block_primary_action(): void
    {
        $project = Project::create([
            'user_id'      => $this->user->id,
            'name'         => 'Fault Test Project',
            'client_name'  => 'Client D',
            'site_address' => '4 Error Street',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user->id,
            'quote_filename' => 'test3.pdf',
            'quote_path'     => 'quote-imports/test3.pdf',
            'extracted_data' => [],
            'equipment_list' => [],
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        // Mock ProjectService to throw InvalidArgumentException on transition()
        $mockProjectService = Mockery::mock(ProjectService::class)->makePartial();
        $mockProjectService->shouldReceive('transition')
            ->once()
            ->andThrow(new \InvalidArgumentException('Forced transition failure'));

        // Allow log() and update() to pass through on the mock
        $mockProjectService->shouldReceive('log')->andReturn(new ProjectActivityLog());
        $mockProjectService->shouldReceive('update')->andReturnUsing(fn($p) => $p);

        $this->app->instance(ProjectService::class, $mockProjectService);

        $service = app(QuoteImportService::class);

        // The confirm() call must NOT throw — primary action must succeed
        $result = $service->confirm($this->user, $package);

        $this->assertNotNull($result);
        $this->assertEquals(ProjectPackage::STATUS_REVIEWED, $result->status);
    }
}

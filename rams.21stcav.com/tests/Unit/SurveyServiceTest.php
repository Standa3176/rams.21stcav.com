<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SurveyService — one-survey-per-project enforcement.
 *
 * Covers the two enforcement paths in create() and the supersede mechanism:
 *   1. Creating without the supersede flag when an active survey exists throws RuntimeException.
 *   2. Creating with supersede=true archives the existing survey (superseded_at is set).
 *
 * Uses RefreshDatabase with SQLite in-memory so real Eloquent queries can be
 * executed against the full schema. The SurveyService constructor dependencies
 * (ProjectService, ProjectContextResolver) are resolved via the service container.
 *
 * @see SURV-05, D-07
 */
class SurveyServiceTest extends TestCase
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
            'ref'          => 'TEST-001',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street, London, EC1A 1BB',
            'status'       => 'quote_imported',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. No supersede flag throws when active survey exists
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When an active (non-superseded) survey already exists for a project,
     * calling create() without the supersede flag must throw a RuntimeException.
     *
     * This is the safety-net guard — the controller surfaces the confirmation UI
     * before reaching this point, but the service enforces it unconditionally.
     */
    public function test_create_without_supersede_flag_throws_when_active_survey_exists(): void
    {
        // Arrange: create an existing active survey for the project
        SiteSurvey::create([
            'user_id'      => $this->user->id,
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name,
            'status'       => 'draft',
            'access_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already has an active survey/i');

        // Act: attempt to create a second survey WITHOUT the supersede flag
        $this->service->create($this->user, [
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name,
            'survey_type'  => 'general',
            // 'supersede' key intentionally absent
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. supersede=true sets superseded_at on prior survey
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When create() is called with supersede=true and an active survey exists,
     * the existing survey must have its superseded_at column set to a non-null
     * timestamp, and a new survey must be created successfully.
     *
     * Uses RefreshDatabase because the supersede mechanism executes a real
     * Eloquent UPDATE within the DB::transaction — only real DB writes confirm
     * the atomic behaviour described in D-07.
     */
    public function test_create_with_supersede_flag_sets_superseded_at_on_prior_survey(): void
    {
        // Arrange: create an existing active survey for the project
        $existing = SiteSurvey::create([
            'user_id'      => $this->user->id,
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name,
            'status'       => 'draft',
            'access_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        // Act: create a new survey WITH supersede=true
        $newSurvey = $this->service->create($this->user, [
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name,
            'survey_type'  => 'general',
            'supersede'    => true,
        ]);

        // Assert: existing survey now has superseded_at set
        $existing->refresh();
        $this->assertNotNull($existing->superseded_at, 'Existing survey superseded_at should be set after supersede');

        // Assert: new survey was created successfully
        $this->assertInstanceOf(SiteSurvey::class, $newSurvey);
        $this->assertEquals($this->project->id, $newSurvey->project_id);
        $this->assertNull($newSurvey->superseded_at, 'New survey should not be superseded');
    }
}

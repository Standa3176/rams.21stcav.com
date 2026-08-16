<?php

namespace Tests\Feature\Jobs;

use App\Core\AI\AIManager;
use App\Core\Modules\Survey\SurveyService;
use App\Jobs\GenerateSurveyQuestionsJob;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Contract tests for GenerateSurveyQuestionsJob.
 *
 * These tests are RED in Wave 0 — production classes do not yet exist.
 * Tests will error (class not found) until Plans 02 and 03 create the
 * model, migration, and job.
 *
 * Contract:
 *   - Job is dispatched per room with solution_type_id when createFromProject is called
 *   - Job is NOT dispatched for rooms without solution_type_id
 *   - job handle() creates SiteSurveyRoomQuestion records when AI returns valid response
 *   - job handle() creates zero records and does NOT throw when AI fails
 */
class GenerateSurveyQuestionsJobTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createProject(array $roomOverviews = []): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Test Project',
            'ref'          => 'TEST-001',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street, London',
            'status'       => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'project_name'   => $project->name,
            'project_ref'    => $project->ref,
            'client_name'    => $project->client_name,
            'site_address'   => $project->site_address,
            'status'         => 'approved',
            'extracted_data' => [
                'room_overviews' => $roomOverviews,
                'equipment'      => [],
            ],
            'reviewed_data' => [
                'room_overviews' => $roomOverviews,
            ],
        ]);

        return $project;
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    // ─── Test 1: job dispatched per room with solution_type_id ────────────────

    /**
     * When createFromProject creates rooms with solution_type_id,
     * GenerateSurveyQuestionsJob must be dispatched for each such room.
     */
    public function test_job_is_dispatched_for_rooms_with_solution_type_id(): void
    {
        Queue::fake();

        $solutionType = \App\Models\SolutionType::create([
            'name'             => 'Conference Room',
            'slug'             => 'conference-room',
            'survey_checklist' => "- Check ceiling height\n- Check network points",
        ]);

        $project = $this->createProject([
            ['room' => 'Board Room', 'overview' => 'AV install', 'solution_type_id' => $solutionType->id],
        ]);

        $user = $this->makeUser();

        /** @var SurveyService $service */
        $service = $this->app->make(SurveyService::class);
        $service->createFromProject($project, $user);

        Queue::assertPushed(GenerateSurveyQuestionsJob::class);
    }

    // ─── Test 2: job NOT dispatched for rooms without solution_type_id ────────

    /**
     * Rooms without a solution_type_id must NOT trigger a job dispatch.
     */
    public function test_job_is_not_dispatched_for_rooms_without_solution_type_id(): void
    {
        Queue::fake();

        $project = $this->createProject([
            ['room' => 'Server Room', 'overview' => 'Infrastructure only', 'solution_type_id' => null],
        ]);

        $user = $this->makeUser();

        /** @var SurveyService $service */
        $service = $this->app->make(SurveyService::class);
        $service->createFromProject($project, $user);

        Queue::assertNotPushed(GenerateSurveyQuestionsJob::class);
    }

    // ─── Test 3: handle() creates SiteSurveyRoomQuestion records ─────────────

    /**
     * When AI returns a valid response with a 'questions' array,
     * handle() must create SiteSurveyRoomQuestion records for each question.
     */
    public function test_job_handle_creates_questions_when_ai_returns_valid_response(): void
    {
        // Mock AIManager to return a valid questions array.
        $this->app->bind(AIManager::class, function () {
            $mock = \Mockery::mock(AIManager::class);
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['questions' => ['Q1: Is the ceiling accessible?', 'Q2: Is there existing cabling?']]);
            return $mock;
        });

        // Create the supporting records without Queue::fake() so the job runs sync.
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Test Project',
            'ref'          => 'TEST-001',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street',
            'status'       => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'project_name'   => $project->name,
            'project_ref'    => $project->ref,
            'client_name'    => $project->client_name,
            'site_address'   => $project->site_address,
            'status'         => 'approved',
            'extracted_data' => [
                'room_overviews' => [],
                'equipment'      => [],
                'works_overview' => 'Supply and install AV.',
            ],
            'reviewed_data' => [],
        ]);

        // Quick task 260816-t5c: `access_token` is guarded on SiteSurvey
        // (Re-audit S-03) — boot()'s creating hook auto-generates a UUID
        // regardless of anything passed here, so the mass-assign attempt was
        // a silent no-op. This test never reads access_token back, so the
        // key is simply dropped rather than force-filled.
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
        ]);

        $room = $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        // Dispatch synchronously (no Queue::fake()).
        GenerateSurveyQuestionsJob::dispatchSync($room->id);

        $this->assertEquals(
            2,
            SiteSurveyRoomQuestion::where('site_survey_room_id', $room->id)->count()
        );
    }

    // ─── Test 4: handle() is silent when AI fails ─────────────────────────────

    /**
     * When AI throws a RuntimeException, no exception must propagate
     * from the job and zero questions must be created.
     */
    public function test_job_handle_is_silent_when_ai_fails(): void
    {
        // Mock AIManager to throw.
        $this->app->bind(AIManager::class, function () {
            $mock = \Mockery::mock(AIManager::class);
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new \RuntimeException('AI provider unavailable'));
            return $mock;
        });

        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Test Project',
            'ref'          => 'TEST-002',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street',
            'status'       => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'project_name'   => $project->name,
            'project_ref'    => $project->ref,
            'client_name'    => $project->client_name,
            'site_address'   => $project->site_address,
            'status'         => 'approved',
            'extracted_data' => ['room_overviews' => [], 'equipment' => []],
            'reviewed_data'  => [],
        ]);

        // Quick task 260816-t5c: same guarded-field note as above — key
        // dropped, boot() auto-generates the token, test never reads it.
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
        ]);

        $room = $survey->rooms()->create([
            'room_name'  => 'Training Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        // Must not throw — failure is silent.
        try {
            GenerateSurveyQuestionsJob::dispatchSync($room->id);
            $threw = false;
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'GenerateSurveyQuestionsJob must not propagate exceptions');
        $this->assertEquals(
            0,
            SiteSurveyRoomQuestion::where('site_survey_room_id', $room->id)->count()
        );
    }
}

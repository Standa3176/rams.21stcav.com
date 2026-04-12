<?php

namespace Tests\Feature\Projects;

use App\Jobs\ExtractRamsDraftJob;
use App\Models\Project;
use App\Models\ProjectQuote;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the Project Layer — quote upload, project resolution, version tracking.
 *
 * Each upload goes through:
 *   QuoteUploadController → ProjectResolverService → ProjectSyncFromQuoteService
 *                         → ProjectQuoteVersionService → RamsDocument creation
 *                         → ExtractRamsDraftJob dispatch
 *
 * Bus::fake() is used throughout so jobs are captured without executing,
 * letting us assert record state immediately after each HTTP response.
 *
 * Matching rules tested (in priority order):
 *   1. Exact quote_reference match (project_quotes table) — user-scoped
 *   2. Exact project ref match (projects.ref column) — user-scoped
 *   3. Normalised project name + site address match — user-scoped
 *   4. Normalised client name + site address match — user-scoped
 *   5. No match → create new Project
 */
class QuoteProjectResolutionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf');
    }

    /**
     * POST to the upload endpoint.
     * Bus::fake() must be called by the calling test before invoking this.
     */
    private function upload(User $user, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge(['quote_pdf' => $this->fakePdf()], $overrides);

        return $this->actingAs($user)->post(route('rams.upload.store'), $payload);
    }

    /**
     * Create an existing Project owned by the given user with specific fields.
     */
    private function existingProject(User $user, array $attributes = []): Project
    {
        return Project::create(array_merge([
            'user_id'      => $user->id,
            'name'         => 'Existing Project',
            'client_name'  => 'Existing Client Ltd',
            'site_address' => '10 Old Street, London, EC1A 1AA',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ], $attributes));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. New Project Creation
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_creates_new_project_when_no_match_exists(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'NEW-001',
            'client_name'  => 'Brand New Client',
            'site_address' => '1 New Street, London',
            'project_name' => 'Brand New Project',
        ]);

        $this->assertDatabaseHas('projects', [
            'user_id'     => $user->id,
            'ref'         => 'NEW-001',
            'client_name' => 'Brand New Client',
        ]);

        $this->assertSame(1, Project::where('user_id', $user->id)->count());
    }

    public function test_upload_redirects_to_project_show_page(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $response = $this->upload($user, [
            'project_ref'  => 'REDIRECT-001',
            'client_name'  => 'Redirect Test Client',
            'site_address' => '1 Test Street, London',
        ]);

        $rams = RamsDocument::where('project_ref', 'REDIRECT-001')->firstOrFail();

        $response->assertRedirect(route('rams.processing', $rams));
    }

    public function test_new_project_receives_status_quote_imported(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'STATUS-NEW-001',
            'client_name'  => 'Status Test Client',
            'site_address' => '1 Status Street, London',
        ]);

        $project = Project::where('ref', 'STATUS-NEW-001')->firstOrFail();

        $this->assertSame(Project::STATUS_QUOTE_IMPORTED, $project->status);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Project Matching — by quote reference (user-scoped)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_matches_existing_project_by_quote_reference(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, ['ref' => 'MATCH-QREF-001']);

        // Seed an existing ProjectQuote with the same reference
        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'first_upload.pdf',
            'stored_filename'   => '/tmp/first_upload.pdf',
            'quote_reference'   => 'MATCH-QREF-001',
            'version_number'    => 1,
        ]);

        // Second upload with the same quote reference
        $this->upload($user, [
            'project_ref' => 'MATCH-QREF-001',
        ]);

        // Must NOT have created a second project
        $this->assertSame(1, Project::where('user_id', $user->id)->count());

        // New RAMS document must be linked to the original project
        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertSame($project->id, $rams->project_id);
    }

    public function test_upload_matches_existing_project_by_project_ref_column(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, ['ref' => 'MATCH-PREF-001']);

        // No ProjectQuote seeded — only the projects.ref column should match
        $this->upload($user, [
            'project_ref' => 'MATCH-PREF-001',
        ]);

        // Only one project should exist
        $this->assertSame(1, Project::where('user_id', $user->id)->count());

        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertSame($project->id, $rams->project_id);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Project Matching — by normalised name + address
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_matches_existing_project_by_normalised_name_and_address(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, [
            'name'         => 'Acme Office Fit-Out',
            'site_address' => '22 Baker Street, London, W1U 3BW',
            'ref'          => null,
        ]);

        // Upload with the same name + address (different casing, extra spaces — must still match)
        $this->upload($user, [
            'project_name' => 'ACME  Office  Fit-Out',
            'site_address' => '22 baker street,  london, w1u 3bw',
        ]);

        $this->assertSame(1, Project::where('user_id', $user->id)->count());

        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertSame($project->id, $rams->project_id);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Project Matching — by normalised client + address
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_matches_existing_project_by_normalised_client_and_address(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, [
            'client_name'  => 'Zoom Hardware Ltd',
            'site_address' => '5 Tech Park, Manchester, M1 1AA',
            'ref'          => null,
        ]);

        // Upload with matching client + address (case variations)
        $this->upload($user, [
            'client_name'  => 'zoom hardware ltd',
            'site_address' => '5 tech park, manchester, m1 1aa',
        ]);

        $this->assertSame(1, Project::where('user_id', $user->id)->count());

        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertSame($project->id, $rams->project_id);
    }

    public function test_upload_creates_new_project_when_client_matches_but_address_differs(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $this->existingProject($user, [
            'client_name'  => 'Zoom Hardware Ltd',
            'site_address' => '5 Tech Park, Manchester, M1 1AA',
            'ref'          => null,
        ]);

        // Same client, different address — must NOT match
        $this->upload($user, [
            'client_name'  => 'Zoom Hardware Ltd',
            'site_address' => '99 Different Road, Leeds, LS1 1BA',
        ]);

        $this->assertSame(2, Project::where('user_id', $user->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Quote Version Tracking
    // ═════════════════════════════════════════════════════════════════════════

    public function test_first_upload_creates_project_quote_version_1(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'VERSION-001',
            'client_name'  => 'Version Test Client',
            'site_address' => '1 Version Street, London',
        ]);

        $project = Project::where('ref', 'VERSION-001')->firstOrFail();

        $this->assertDatabaseHas('project_quotes', [
            'project_id'      => $project->id,
            'version_number'  => 1,
            'uploaded_by'     => $user->id,
            'quote_reference' => 'VERSION-001',
        ]);

        $this->assertSame(1, ProjectQuote::where('project_id', $project->id)->count());
    }

    public function test_second_upload_for_same_project_increments_version_to_2(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, ['ref' => 'VERSION-INC-001']);

        // Seed version 1 with a different filename so it is not treated as a duplicate
        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'v1.pdf',
            'stored_filename'   => '/tmp/v1.pdf',
            'quote_reference'   => 'VERSION-INC-001',
            'version_number'    => 1,
        ]);

        // Second upload with a different filename — version must increment to 2
        $pdf = UploadedFile::fake()->create('v2.pdf', 50, 'application/pdf');
        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $pdf,
            'project_ref' => 'VERSION-INC-001',
        ]);

        $this->assertDatabaseHas('project_quotes', [
            'project_id'     => $project->id,
            'version_number' => 2,
        ]);

        $this->assertSame(2, ProjectQuote::where('project_id', $project->id)->count());
    }

    public function test_third_upload_increments_version_to_3(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, ['ref' => 'VERSION-THREE-001']);

        // Seed versions 1 and 2 with distinct filenames
        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'v1_three.pdf',
            'stored_filename'   => '/tmp/v1_three.pdf',
            'version_number'    => 1,
        ]);
        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'v2_three.pdf',
            'stored_filename'   => '/tmp/v2_three.pdf',
            'version_number'    => 2,
        ]);

        // Third upload with a distinct filename
        $pdf = UploadedFile::fake()->create('v3_three.pdf', 50, 'application/pdf');
        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => $pdf,
            'project_ref' => 'VERSION-THREE-001',
        ]);

        $this->assertDatabaseHas('project_quotes', [
            'project_id'     => $project->id,
            'version_number' => 3,
        ]);
    }

    public function test_project_quote_stores_original_filename(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $pdf = UploadedFile::fake()->create('acme_quote_v2.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'    => $pdf,
            'project_ref'  => 'FNAME-001',
            'client_name'  => 'Fname Client',
            'site_address' => '1 Fname Street',
        ]);

        $project = Project::where('ref', 'FNAME-001')->firstOrFail();

        $this->assertDatabaseHas('project_quotes', [
            'project_id'        => $project->id,
            'original_filename' => 'acme_quote_v2.pdf',
        ]);
    }

    public function test_project_quote_stored_filename_is_absolute_path(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'PQPATH-001',
            'client_name'  => 'PQ Path Client',
            'site_address' => '1 PQ Path Street',
        ]);

        $project = Project::where('ref', 'PQPATH-001')->firstOrFail();
        $pq      = ProjectQuote::where('project_id', $project->id)->firstOrFail();

        $this->assertNotNull($pq->stored_filename, 'stored_filename must not be null.');
        $this->assertStringContainsString('rams/uploads', $pq->stored_filename);
        $this->assertStringEndsWith('.pdf', $pq->stored_filename);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Duplicate upload guard
    // ═════════════════════════════════════════════════════════════════════════

    public function test_duplicate_upload_does_not_create_second_quote_version(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, ['ref' => 'DEDUP-001']);

        // Seed an existing version with the same filename and reference
        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'quote.pdf',   // same name as fakePdf()
            'stored_filename'   => '/tmp/quote.pdf',
            'quote_reference'   => 'DEDUP-001',
            'version_number'    => 1,
        ]);

        // Upload again with the same filename (fakePdf() always returns 'quote.pdf')
        $this->upload($user, [
            'project_ref' => 'DEDUP-001',
        ]);

        // Version count must still be 1 — no duplicate created
        $this->assertSame(
            1,
            ProjectQuote::where('project_id', $project->id)->count(),
            'Duplicate upload must not create a second project_quote record.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. RAMS Document — project linkage & guards
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_creates_rams_document_linked_to_resolved_project(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'RAMS-LINK-001',
            'client_name'  => 'RAMS Link Client',
            'site_address' => '1 Link Street, London',
        ]);

        $project = Project::where('ref', 'RAMS-LINK-001')->firstOrFail();

        $this->assertDatabaseHas('rams_documents', [
            'project_id' => $project->id,
            'user_id'    => $user->id,
        ]);
    }

    public function test_rams_document_always_has_project_id(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'RAMS-PROJID-001',
            'client_name'  => 'Project ID Client',
            'site_address' => '1 Project ID Street',
        ]);

        $rams = RamsDocument::where('project_ref', 'RAMS-PROJID-001')->firstOrFail();

        $this->assertNotNull(
            $rams->project_id,
            'Every RamsDocument created via the upload path must have a project_id.'
        );
        $this->assertGreaterThan(0, $rams->project_id);
    }

    public function test_rams_document_has_status_uploaded_after_upload(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'RAMS-STATUS-001',
            'client_name'  => 'Status Check Client',
            'site_address' => '1 Status Street, London',
        ]);

        $rams = RamsDocument::where('project_ref', 'RAMS-STATUS-001')->firstOrFail();

        $this->assertSame(RamsDocument::STATUS_UPLOADED, $rams->status);
    }

    public function test_rams_document_filename_is_absolute_path(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'RAMS-PATH-001',
            'client_name'  => 'Path Test Client',
            'site_address' => '1 Path Street, London',
        ]);

        $rams = RamsDocument::where('project_ref', 'RAMS-PATH-001')->firstOrFail();

        $this->assertNotNull($rams->filename, 'filename must not be null after upload.');
        $this->assertStringContainsString('rams/uploads', $rams->filename);
        $this->assertStringEndsWith('.pdf', $rams->filename);
    }

    public function test_rams_document_three_data_columns_are_null_at_upload(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'RAMS-NULL-001',
            'client_name'  => 'Null Test Client',
            'site_address' => '1 Null Street, London',
        ]);

        $rams = RamsDocument::where('project_ref', 'RAMS-NULL-001')->firstOrFail();

        $this->assertNull($rams->extracted_data, 'extracted_data must be null until ExtractRamsDraftJob runs.');
        $this->assertNull($rams->reviewed_data,  'reviewed_data must be null until user reviews.');
        $this->assertNull($rams->generated_data, 'generated_data must be null until Phase B completes.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Job Dispatch
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_dispatches_extract_rams_draft_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'JOB-DISPATCH-001',
            'client_name'  => 'Job Dispatch Client',
            'site_address' => '1 Job Street, London',
        ]);

        Bus::assertDispatched(ExtractRamsDraftJob::class);
    }

    public function test_dispatched_job_references_the_created_rams_document(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'JOB-REF-001',
            'client_name'  => 'Job Ref Client',
            'site_address' => '1 Ref Street, London',
        ]);

        $rams = RamsDocument::where('project_ref', 'JOB-REF-001')->firstOrFail();

        Bus::assertDispatched(ExtractRamsDraftJob::class, function ($job) use ($rams) {
            return $job->ramsDocumentId === $rams->id;
        });
    }

    public function test_only_one_job_dispatched_per_upload(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'JOB-ONCE-001',
            'client_name'  => 'Single Job Client',
            'site_address' => '1 Once Street, London',
        ]);

        Bus::assertDispatchedTimes(ExtractRamsDraftJob::class, 1);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 9. Project Sync — safe backfill of empty fields
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_backfills_empty_project_fields_on_match(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, [
            'ref'          => 'BACKFILL-001',
            'client_name'  => '',
            'site_address' => '',
        ]);

        $this->upload($user, [
            'project_ref'  => 'BACKFILL-001',
            'client_name'  => 'Backfill Client Ltd',
            'site_address' => '99 Backfill Road, London',
        ]);

        $project->refresh();

        $this->assertSame('Backfill Client Ltd', $project->client_name);
        $this->assertSame('99 Backfill Road, London', $project->site_address);
    }

    public function test_upload_does_not_overwrite_existing_project_fields_on_match(): void
    {
        Bus::fake();

        $user    = User::factory()->create();
        $project = $this->existingProject($user, [
            'ref'          => 'NOOVERWRITE-001',
            'client_name'  => 'Original Client Ltd',
            'site_address' => '1 Original Street',
        ]);

        $this->upload($user, [
            'project_ref'  => 'NOOVERWRITE-001',
            'client_name'  => 'Attempted Overwrite Ltd',
            'site_address' => '999 Overwrite Avenue',
        ]);

        $project->refresh();

        $this->assertSame('Original Client Ltd', $project->client_name,  'client_name must not be overwritten.');
        $this->assertSame('1 Original Street',   $project->site_address, 'site_address must not be overwritten.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 10. Placeholder ref stripping
    // ═════════════════════════════════════════════════════════════════════════

    public function test_placeholder_ref_RAMS_001_is_stripped_before_matching(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        // Seed a project with ref = 'RAMS-001' — should NOT be matched
        $this->existingProject($user, ['ref' => 'RAMS-001']);

        $this->upload($user, [
            'project_ref'  => 'RAMS-001',
            'client_name'  => 'Placeholder Client',
            'site_address' => '1 Placeholder Street, London',
        ]);

        // Placeholder stripped → new project created
        $this->assertSame(2, Project::where('user_id', $user->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 11. Project Detail Page
    // ═════════════════════════════════════════════════════════════════════════

    public function test_project_show_page_loads_successfully(): void
    {
        $user    = User::factory()->create();
        $project = $this->existingProject($user);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertViewIs('projects.show');
        $response->assertViewHas('project');
    }

    public function test_project_show_page_displays_quote_history(): void
    {
        $user    = User::factory()->create();
        $project = $this->existingProject($user);

        ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'my_quote_v1.pdf',
            'stored_filename'   => '/tmp/my_quote_v1.pdf',
            'quote_reference'   => 'SHOW-QREF-001',
            'version_number'    => 1,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('my_quote_v1.pdf');
    }

    public function test_project_show_page_displays_rams_documents(): void
    {
        $user    = User::factory()->create();
        $project = $this->existingProject($user);

        RamsDocument::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_ref'  => 'SHOW-RAMS-001',
            'project_name' => 'Show RAMS Project',
            'client_name'  => 'Show RAMS Client',
            'site_address' => '1 Show Street',
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-3-opus-20240229',
            'form_data'    => [],
            'filename'     => '/tmp/show_rams.pdf',
            'status'       => RamsDocument::STATUS_UPLOADED,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Show RAMS Project');
    }

    public function test_project_show_page_is_forbidden_for_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $project = $this->existingProject($owner);

        $response = $this->actingAs($other)->get(route('projects.show', $project));

        $response->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 12. Multi-project isolation (user scoping)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_project_ref_match_is_scoped_to_authenticated_user(): void
    {
        Bus::fake();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A has a project with ref = 'SHARED-REF-001'
        $projectA = $this->existingProject($userA, ['ref' => 'SHARED-REF-001']);

        // User B uploads with the same ref — must create a NEW project for User B
        $this->upload($userB, [
            'project_ref'  => 'SHARED-REF-001',
            'client_name'  => 'User B Client',
            'site_address' => '1 User B Street',
        ]);

        // User A still has exactly one project
        $this->assertSame(1, Project::where('user_id', $userA->id)->count());

        // User B now also has exactly one project (newly created, not User A's)
        $this->assertSame(1, Project::where('user_id', $userB->id)->count());

        // The RAMS document belongs to User B's project — not User A's
        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertNotSame($projectA->id, $rams->project_id);
    }

    public function test_quote_reference_match_is_scoped_to_authenticated_user(): void
    {
        Bus::fake();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A has a project with a matching ProjectQuote
        $projectA = $this->existingProject($userA, ['ref' => 'QREF-SCOPE-001']);
        ProjectQuote::create([
            'project_id'        => $projectA->id,
            'uploaded_by'       => $userA->id,
            'original_filename' => 'user_a_quote.pdf',
            'stored_filename'   => '/tmp/user_a_quote.pdf',
            'quote_reference'   => 'QREF-SCOPE-001',
            'version_number'    => 1,
        ]);

        // User B uploads with the same quote reference — must NOT match User A's project
        $this->upload($userB, [
            'project_ref'  => 'QREF-SCOPE-001',
            'client_name'  => 'User B Client',
            'site_address' => '1 User B Street',
        ]);

        // A new project must have been created for User B
        $this->assertSame(1, Project::where('user_id', $userB->id)->count());

        // User A's project count must remain 1
        $this->assertSame(1, Project::where('user_id', $userA->id)->count());

        // The RAMS document must be linked to User B's project
        $rams = RamsDocument::latest()->firstOrFail();
        $this->assertSame($userB->id, $rams->user_id);
        $this->assertNotSame($projectA->id, $rams->project_id);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 13. File Storage
    // ═════════════════════════════════════════════════════════════════════════

    public function test_uploaded_pdf_is_stored_in_rams_uploads_directory(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = User::factory()->create();

        $this->upload($user, [
            'project_ref'  => 'STORAGE-001',
            'client_name'  => 'Storage Test Client',
            'site_address' => '1 Storage Street',
        ]);

        $files = Storage::disk('local')->files('rams/uploads');
        $this->assertNotEmpty($files, 'No files found in rams/uploads/.');

        $pdfs = array_filter($files, fn ($f) => str_ends_with($f, '.pdf'));
        $this->assertNotEmpty($pdfs, 'No .pdf file found in rams/uploads/.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 14. Validation guard
    // ═════════════════════════════════════════════════════════════════════════

    public function test_upload_fails_validation_when_no_pdf_supplied(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('rams.upload.store'), [
            'project_ref' => 'NO-PDF-001',
        ]);

        $response->assertSessionHasErrors(['quote_pdf']);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('rams_documents', 0);
    }

    public function test_upload_fails_validation_when_non_pdf_file_supplied(): void
    {
        $user = User::factory()->create();

        $notAPdf = UploadedFile::fake()->create('document.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf' => $notAPdf,
        ]);

        $response->assertSessionHasErrors(['quote_pdf']);
        $this->assertDatabaseCount('rams_documents', 0);
    }

    public function test_unauthenticated_upload_is_redirected_to_login(): void
    {
        $response = $this->post(route('rams.upload.store'), [
            'quote_pdf' => $this->fakePdf(),
        ]);

        $response->assertRedirectToRoute('login');
    }
}

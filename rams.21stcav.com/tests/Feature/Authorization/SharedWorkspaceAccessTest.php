<?php

namespace Tests\Feature\Authorization;

use App\Models\CableSchedule;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Quick task 260525-pyu — shared-workspace authorization regression suite.
 *
 * This is the intended model for the 3-person company: ANY authenticated
 * user (role=user, non-owner) has full access to every Projects function
 * and document, while genuinely administrative endpoints stay admin-only and
 * guests are still bounced to the login page.
 *
 * The headline case (#1) is the exact production bug: non-admin staff
 * (zack, alison — role=user) received HTTP 403 when downloading a RAMS
 * PDF/DOCX owned by user 1 (sonny). After the relaxation it must be 200.
 *
 * H-07: the downloadable artifact is placed via DocumentArtifactStorage with
 * Storage::fake('documents'); paths are never hand-built.
 */
class SharedWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Owner of every document under test = "user 1" (sonny in production). */
    private User $owner;

    /** Non-admin, non-owner actor = "zack / alison" in production. */
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentArtifactStorage::DISK);

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->staff = User::factory()->create(['role' => 'user']);
    }

    /**
     * Write a real, valid .docx artifact for the given type/filename via the
     * H-07 storage seam so download endpoints stream it directly instead of
     * attempting a (heavyweight) rebuild.
     */
    private function placeDocx(string $type, string $filename): void
    {
        $path = app(DocumentArtifactStorage::class)->writePath($type, $filename);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Shared workspace fixture document.');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
    }

    private function makeProject(?User $user = null): Project
    {
        return Project::factory()->create([
            'user_id'      => ($user ?? $this->owner)->id,
            'name'         => 'Shared Workspace Project',
            'ref'          => 'SHARED-REF-001',
            'client_name'  => 'Acme AV Ltd',
            'site_address' => '1 Shared Street, London',
        ]);
    }

    // ── 1. THE PRODUCTION BUG: non-admin non-owner downloads RAMS PDF + DOCX ──

    public function test_non_admin_non_owner_can_download_rams_pdf(): void
    {
        $project = $this->makeProject();
        $rams = RamsDocument::factory()->create([
            'user_id'        => $this->owner->id,
            'project_id'     => $project->id,
            'filename'       => 'rams-shared.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => ['project' => ['name' => 'Shared Workspace Project']],
        ]);
        $this->placeDocx(DocumentArtifactStorage::TYPE_RAMS, 'rams-shared.docx');

        // DOCX download — the exact path zack/alison hit in production.
        $docx = $this->actingAs($this->staff)->get(route('rams.download', $rams));
        $docx->assertOk();
        $this->assertNotSame(403, $docx->getStatusCode(), 'Non-owner must NOT receive 403 on RAMS DOCX download.');

        // PDF download — must also clear the authorization gate (no 403).
        // We assert it is not forbidden rather than 200, because PDF rendering
        // depends on a headless-browser binary that may be absent in CI (which
        // surfaces as a redirect-back-with-error, not a 403).
        $pdf = $this->actingAs($this->staff)->get(route('rams.download-pdf', $rams));
        $this->assertNotSame(403, $pdf->getStatusCode(), 'Non-owner must NOT receive 403 on RAMS PDF download.');
    }

    // ── 2. non-admin non-owner can view + delete a RAMS ──────────────────────

    public function test_non_admin_non_owner_can_view_and_delete_rams(): void
    {
        $project = $this->makeProject();
        $rams = RamsDocument::factory()->create([
            'user_id'        => $this->owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => ['project' => ['name' => 'Shared Workspace Project']],
        ]);

        // View the review page.
        $this->actingAs($this->staff)
            ->get(route('rams.review', $rams))
            ->assertOk();

        // Delete it — must not 403.
        $delete = $this->actingAs($this->staff)->delete(route('rams.destroy', $rams->id));
        $this->assertNotSame(403, $delete->getStatusCode(), 'Non-owner must NOT receive 403 on RAMS delete.');
        $this->assertSoftDeleted('rams_documents', ['id' => $rams->id]);
    }

    // ── 3. non-admin non-owner can reach O&M / Worksheet / Cable / Survey ────

    public function test_non_admin_non_owner_can_access_om_worksheet_cable_survey(): void
    {
        $project = $this->makeProject();

        $om = OmManual::factory()->create([
            'user_id'    => $this->owner->id,
            'project_id' => $project->id,
            'status'     => 'final',
            'filename'   => 'om-shared.docx',
        ]);
        $this->placeDocx(DocumentArtifactStorage::TYPE_OM, 'om-shared.docx');

        $worksheet = Worksheet::factory()->create([
            'user_id'    => $this->owner->id,
            'project_id' => $project->id,
        ]);

        $cable = CableSchedule::factory()->create([
            'user_id'    => $this->owner->id,
            'project_id' => $project->id,
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $this->owner->id,
            'project_id'   => $project->id,
            'project_name' => 'Shared Workspace Project',
            'client_name'  => 'Acme AV Ltd',
            'site_address' => '1 Shared Street, London',
            'status'       => 'draft',
        ]);

        $this->actingAs($this->staff);

        // O&M edit page + download — neither may 403.
        $this->get(route('om-manuals.edit', $om))->assertOk();
        $this->assertNotSame(403, $this->get(route('om-manuals.download', $om))->getStatusCode());

        // Worksheet show.
        $this->get(route('worksheets.show', $worksheet))->assertOk();

        // Cable schedule edit page — must not 403.
        $this->assertNotSame(403, $this->get(route('cable-schedules.edit', $cable))->getStatusCode());

        // Site survey show.
        $this->get(route('site-surveys.show', $survey))->assertOk();
    }

    // ── 4. listings show ALL records to any authenticated user ───────────────

    public function test_listings_show_all_records_to_any_authenticated_user(): void
    {
        $project = $this->makeProject();
        RamsDocument::factory()->create([
            'user_id'      => $this->owner->id,
            'project_id'   => $project->id,
            'project_name' => 'Owners Exclusive RAMS',
            'status'       => RamsDocument::STATUS_COMPLETED,
        ]);

        // Staff (role=user, non-owner) sees the owner's RAMS in the index.
        $this->actingAs($this->staff)
            ->get(route('rams.index'))
            ->assertOk()
            ->assertSee('Owners Exclusive RAMS');
    }

    // ── 5. admin-only endpoints still 403 for non-admin ──────────────────────

    public function test_admin_only_endpoints_still_forbid_non_admin(): void
    {
        $project = $this->makeProject();
        $project->delete(); // soft-delete so restore/forceDestroy target a real row

        $this->actingAs($this->staff);

        $this->post(route('projects.restore', $project->id))->assertForbidden();
        $this->delete(route('projects.force-destroy', $project->id))->assertForbidden();
    }

    // ── 6. guest is bounced to login (auth middleware intact) ────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('rams.index'))->assertRedirect(route('login'));
    }
}

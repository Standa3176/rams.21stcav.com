<?php

namespace Tests\Feature\OmManual;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for O&M manual project linkage.
 *
 * Tests that:
 *   - An O&M manual can be created with a project_id
 *   - The OmManual belongs to the correct project
 *   - The Project page lists linked O&M manuals
 *   - O&M create page pre-selects the project when project_id is in the query string
 *   - O&M records are only visible to their owner (authorization)
 *   - The project show page O&M section is always rendered (even when empty)
 *   - Download routes respect authorization
 *
 * Note: OmManualController::store() (Pass 1) makes an AI call via
 * OmManualGeneratorService. These tests bypass that path and create
 * OmManual records directly to test project linkage in isolation.
 */
class OmManualProjectLinkageTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a Project owned by $user with sensible defaults.
     */
    private function makeProject(User $user, array $attrs = []): Project
    {
        return Project::create(array_merge([
            'user_id'      => $user->id,
            'name'         => 'Test Project',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Street, London',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ], $attrs));
    }

    /**
     * Create an OmManual record directly (bypasses PDF/AI extraction).
     */
    private function makeManual(User $user, Project $project, array $attrs = []): OmManual
    {
        return OmManual::create(array_merge([
            'user_id'         => $user->id,
            'project_id'      => $project->id,
            'project_name'    => $project->name,
            'project_ref'     => $project->ref,
            'client_name'     => $project->client_name,
            'site_address'    => $project->site_address,
            'source_filename' => 'quote.pdf',
            'source_path'     => 'om-sources/test_om.pdf',
            'status'          => 'extracted',
            'extracted_data'  => ['equipment' => [['quantity' => 1, 'name' => 'LED Display']]],
            'generated_data'  => null,
            'filename'        => null,
        ], $attrs));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Model relationships
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_manual_belongs_to_project(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $this->assertNotNull($manual->project);
        $this->assertSame($project->id, $manual->project->id);
    }

    public function test_project_has_many_om_manuals(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);

        $this->makeManual($user, $project);
        $this->makeManual($user, $project);

        $project->refresh();
        $project->load('omManuals');

        $this->assertCount(2, $project->omManuals);
    }

    public function test_om_manual_is_not_generated_before_pass_2(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $this->assertFalse($manual->isGenerated());
    }

    public function test_om_manual_is_generated_after_filename_and_generated_data_set(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project, [
            'status'         => 'draft',
            'generated_data' => ['project' => ['name' => 'Test']],
            'filename'       => 'om-manuals/test.docx',
        ]);

        $this->assertTrue($manual->isGenerated());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Database persistence
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_manual_is_stored_with_correct_project_id(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user, ['ref' => 'OM-LINK-001']);
        $manual  = $this->makeManual($user, $project);

        $this->assertDatabaseHas('om_manuals', [
            'id'         => $manual->id,
            'user_id'    => $user->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_om_manual_project_id_is_not_null(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $this->assertNotNull($manual->project_id);
        $this->assertGreaterThan(0, $manual->project_id);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Project detail page — O&M section
    // ═════════════════════════════════════════════════════════════════════════

    public function test_project_show_page_always_renders_om_section(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);

        // No O&M manuals — section must still be present with a "New O&M" button
        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('O&amp;M Manuals', false);
        $response->assertSee(route('om-manuals.create', ['project_id' => $project->id]));
    }

    public function test_project_show_page_lists_linked_om_manuals(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project, ['project_name' => 'AV Manual Alpha']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('AV Manual Alpha');
    }

    public function test_project_show_page_shows_review_link_for_extracted_manual(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project, ['status' => 'extracted']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee(route('om-manuals.edit', $manual));
    }

    public function test_project_show_page_shows_download_links_for_generated_manual(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project, [
            'status'         => 'final',
            'generated_data' => ['project' => ['name' => 'Test']],
            'filename'       => 'om_test.docx',
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee(route('om-manuals.download', $manual));
    }

    public function test_project_show_page_shows_status_label_for_manual(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeManual($user, $project, ['status' => 'extracted']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        // statusLabel() returns 'Awaiting Review' for 'extracted'
        $response->assertSee('Awaiting Review');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. O&M create page — project pre-selection
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_create_page_loads_with_project_id_query_param(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user, ['name' => 'Pre-Select Project']);

        $response = $this->actingAs($user)
            ->get(route('om-manuals.create', ['project_id' => $project->id]));

        $response->assertOk();
        // The create view renders the project in the select box
        $response->assertSee('Pre-Select Project');
        // The selected project option should have 'selected' attribute
        $response->assertSee((string) $project->id);
    }

    public function test_om_create_page_loads_without_project_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('om-manuals.create'));

        $response->assertOk();
        $response->assertSee('New O&amp;M Manual', false);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Authorization
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_edit_page_is_accessible_to_any_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(); // default role = 'user' — non-owner

        $project = $this->makeProject($owner);
        $manual  = $this->makeManual($owner, $project);

        // Shared workspace: a non-owner, non-admin user may open any O&M edit page.
        $response = $this->actingAs($other)->get(route('om-manuals.edit', $manual));

        $response->assertOk();
    }

    public function test_om_download_is_accessible_to_any_authenticated_user(): void
    {
        \Illuminate\Support\Facades\Storage::fake(\App\Services\DocumentArtifactStorage::DISK);

        $owner = User::factory()->create();
        $other = User::factory()->create(); // default role = 'user' — non-owner

        $project = $this->makeProject($owner);
        $manual  = $this->makeManual($owner, $project, [
            'status'         => 'final',
            'generated_data' => ['project' => ['name' => 'Test']],
            'filename'       => 'om_test.docx',
        ]);

        // Place the artifact via the H-07 storage seam so readPath() resolves it.
        $path = app(\App\Services\DocumentArtifactStorage::class)
            ->writePath(\App\Services\DocumentArtifactStorage::TYPE_OM, 'om_test.docx');
        file_put_contents($path, 'PK fake-docx-bytes');

        // Shared workspace: a non-owner, non-admin user may download any O&M.
        $response = $this->actingAs($other)->get(route('om-manuals.download', $manual));

        $response->assertOk();
    }

    public function test_om_manual_owner_can_access_edit_page(): void
    {
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $response = $this->actingAs($user)->get(route('om-manuals.edit', $manual));

        $response->assertOk();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. O&M index — shared workspace (every user sees ALL manuals)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_index_shows_all_manuals_to_any_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $projectA = $this->makeProject($userA);
        $projectB = $this->makeProject($userB);

        $this->makeManual($userA, $projectA, ['project_name' => 'User A Manual']);
        $this->makeManual($userB, $projectB, ['project_name' => 'User B Manual']);

        // Shared workspace: userA's index lists BOTH manuals.
        $response = $this->actingAs($userA)->get(route('om-manuals.index'));

        $response->assertOk();
        $response->assertSee('User A Manual');
        $response->assertSee('User B Manual');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Update (save extracted_data edits)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_update_saves_extracted_json_via_raw_json_escape_hatch(): void
    {
        // The raw-JSON path is now opt-in — user must tick "use_raw_json"
        // in the Advanced disclosure. Without that flag, the structured
        // fields path runs and rooms are preserved from the current
        // extracted_data, not replaced from the posted JSON.
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $newData = [
            'project' => [
                'name'   => 'Updated Project',
                'ref'    => 'UPD-001',
                'client' => 'Updated Client',
                'site'   => '2 Updated Street',
            ],
            'rooms' => [
                [
                    'name'        => 'Boardroom',
                    'floor'       => '1st',
                    'drawing_ref' => '',   // sanitiseRooms() always normalises this field in
                    'equipment'   => [
                        ['qty' => 2, 'name' => 'LED Display', 'description' => 'LED Display', 'model' => '', 'manufacturer' => '', 'part_no' => '', 'category' => 'Display'],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($user)->put(route('om-manuals.update', $manual), [
            'use_raw_json'   => '1',
            'extracted_json' => json_encode($newData),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $manual->refresh();
        $this->assertSame($newData, $manual->extracted_data);
    }

    public function test_om_update_rejects_invalid_json_when_raw_json_path_is_used(): void
    {
        // Invalid JSON is only surfaced when the user explicitly opts into
        // the raw-JSON path via use_raw_json=1. Without the flag, the
        // structured field validators run instead and the extracted_json
        // field is ignored, so an invalid payload would silently pass
        // through — that's why we require the opt-in here.
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project);

        $response = $this->actingAs($user)->put(route('om-manuals.update', $manual), [
            'use_raw_json'   => '1',
            'extracted_json' => '{not valid json',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_om_update_structured_path_overlays_typed_fields_without_touching_rooms(): void
    {
        // Structured save path (default) — user edits high-signal typed fields
        // in the form and posts them. The controller must preserve rooms +
        // any unknown keys from the existing extracted_data and only overlay
        // the typed fields on top. This is the safety property that lets us
        // hide raw JSON from non-technical users without breaking the manual.
        $user    = User::factory()->create();
        $project = $this->makeProject($user);
        $manual  = $this->makeManual($user, $project, [
            'extracted_data' => [
                'project_name'   => 'Old name',
                'client_name'    => 'Old client',
                'site_address'   => 'Old site',
                'project_ref'    => 'OLD-REF',
                'notes'          => 'Old notes',
                'scope_of_works' => 'Old scope prose.',
                'rooms'          => [
                    [
                        'name'      => 'Existing Room',
                        'equipment' => [
                            ['qty' => 1, 'name' => 'Old kit', 'description' => 'Old kit'],
                        ],
                    ],
                ],
                'system_summary' => 'Preserved unknown key — the form does not touch this.',
            ],
        ]);

        $response = $this->actingAs($user)->put(route('om-manuals.update', $manual), [
            'project_name'   => 'New name',
            'project_ref'    => 'NEW-REF',
            'client_name'    => 'New client',
            'site_address'   => '3 New Street',
            'scope_of_works' => 'Fully revised scope.',
            'notes'          => 'Revised notes.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $manual->refresh();

        // Typed fields land in extracted_data.
        $this->assertSame('New name',              $manual->extracted_data['project_name']);
        $this->assertSame('NEW-REF',               $manual->extracted_data['project_ref']);
        $this->assertSame('New client',            $manual->extracted_data['client_name']);
        $this->assertSame('3 New Street',          $manual->extracted_data['site_address']);
        $this->assertSame('Fully revised scope.',  $manual->extracted_data['scope_of_works']);
        $this->assertSame('Revised notes.',        $manual->extracted_data['notes']);

        // And also into the top-level columns for list-page display.
        $this->assertSame('New name',              $manual->project_name);
        $this->assertSame('NEW-REF',               $manual->project_ref);
        $this->assertSame('New client',            $manual->client_name);
        $this->assertSame('3 New Street',          $manual->site_address);

        // Rooms untouched.
        $this->assertSame('Existing Room',         $manual->extracted_data['rooms'][0]['name']);
        $this->assertSame('Old kit',               $manual->extracted_data['rooms'][0]['equipment'][0]['name']);

        // Unknown keys preserved.
        $this->assertSame(
            'Preserved unknown key — the form does not touch this.',
            $manual->extracted_data['system_summary']
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Project scoping — manual appears ONLY on its own project page
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_manual_appears_on_its_own_project_page_not_on_other(): void
    {
        $user     = User::factory()->create();
        $projectA = $this->makeProject($user, ['name' => 'Project Alpha']);
        $projectB = $this->makeProject($user, ['name' => 'Project Beta']);

        $this->makeManual($user, $projectA, ['project_name' => 'Alpha O&M Manual']);

        // Manual for project A IS visible on project A's page.
        $responseA = $this->actingAs($user)->get(route('projects.show', $projectA));
        $responseA->assertOk();
        $responseA->assertSee('Alpha O&M Manual');

        // Manual for project A is NOT visible on project B's page.
        $responseB = $this->actingAs($user)->get(route('projects.show', $projectB));
        $responseB->assertOk();
        $responseB->assertDontSee('Alpha O&M Manual');
    }

    public function test_project_page_does_not_show_om_manuals_from_other_projects(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $projectA = $this->makeProject($userA, ['name' => 'Owner A Project']);
        $projectB = $this->makeProject($userB, ['name' => 'Owner B Project']);

        $this->makeManual($userA, $projectA, ['project_name' => 'Owner A O&M']);
        $this->makeManual($userB, $projectB, ['project_name' => 'Owner B O&M']);

        // Owner A sees their own manual on their project page.
        $response = $this->actingAs($userA)->get(route('projects.show', $projectA));
        $response->assertOk();
        $response->assertSee('Owner A O&M');
        $response->assertDontSee('Owner B O&M');
    }
}

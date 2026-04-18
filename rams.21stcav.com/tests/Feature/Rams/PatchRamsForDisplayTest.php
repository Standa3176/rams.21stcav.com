<?php

namespace Tests\Feature\Rams;

use App\Http\Controllers\RamsController;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression snapshot tests for RamsController::patchRamsForDisplay().
 *
 * Written BEFORE the H-09 extraction as a behavioural lock: the method mutates
 * $rams->generated_data + $rams->reviewed_data in six distinct ways, and it's
 * called from both review() and downloadPdf(). Any drift during extraction
 * would break both paths silently. Each test here pins one invariant; they
 * should all continue to pass after extraction to RamsDisplayPatchService.
 *
 * Private method → invoked via reflection. Fixtures use Model::make() /
 * Model::setRelation() so we don't need to run the full RAMS pipeline.
 */
class PatchRamsForDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function invokePatch(RamsDocument $rams): void
    {
        $controller = app(RamsController::class);
        $method     = new ReflectionMethod($controller, 'patchRamsForDisplay');
        $method->setAccessible(true);
        $method->invoke($controller, $rams);
    }

    private function baseProject(User $owner, array $attrs = []): Project
    {
        return Project::factory()->for($owner, 'owner')->create(array_merge([
            'name'         => 'Live Project Name',
            'client_name'  => 'Live Client Ltd',
            'site_address' => '1 Live Street, London',
            'ref'          => 'LIVE-REF-01',
        ], $attrs));
    }

    public function test_live_project_values_overwrite_stale_generated_data(): void
    {
        $owner = User::factory()->create(['name' => 'Alice PM']);
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'stale-col',
            'client_name'    => 'stale-col',
            'site_address'   => 'stale-col',
            'project_ref'    => 'stale-col',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => [
                'name'         => 'stale-in-json',
                'client'       => 'stale-in-json',
                'site_address' => 'stale-in-json',
                'ref'          => 'stale-in-json',
            ]],
            'reviewed_data' => [],
            'form_data'     => [],
        ]);

        $this->invokePatch($rams);

        $p = $rams->generated_data['project'];
        $this->assertSame('Live Project Name', $p['name']);
        $this->assertSame('Live Client Ltd',   $p['client']);
        $this->assertSame('1 Live Street, London', $p['site_address']);
        $this->assertSame('LIVE-REF-01',       $p['ref']);
    }

    public function test_personnel_fallback_uses_programme_then_owner_name(): void
    {
        $owner   = User::factory()->create(['name' => 'Owen Owner']);
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => []],
            'reviewed_data'  => ['programme' => [
                'project_manager_name' => 'Priya Review',
                'lead_engineer_name'   => 'Lee Engineer',
                'additional_engineers' => ['Ed One', 'Ed Two'],
                'programmer_name'      => 'Paul Programmer',
                'project_manager_phone' => '01234',
            ]],
            'form_data'      => [],
        ]);

        $this->invokePatch($rams);

        $p = $rams->generated_data['project'];
        $this->assertSame('Priya Review',  $p['project_manager']);
        $this->assertSame('Lee Engineer',  $p['lead_engineer']);
        $this->assertSame('Ed One, Ed Two', $p['additional_engineers']);
        $this->assertSame('Paul Programmer', $p['programmer']);
        $this->assertSame('01234',           $p['project_manager_phone']);
        // doc_author becomes the resolved PM
        $this->assertSame('Priya Review',    $p['doc_author']);
    }

    public function test_project_manager_never_resolves_to_an_email_in_form_data(): void
    {
        // form_data['project_manager'] is allowed as last resort ONLY when it
        // isn't an email address — that rule prevents client email addresses
        // from leaking into the PM field.
        $owner = User::factory()->create(['name' => 'Owen Owner']);
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => []],
            'reviewed_data'  => [],
            'form_data'      => ['project_manager' => 'client@acme.com'],
        ]);

        $this->invokePatch($rams);

        // Owner name should have won since form_data value was an email.
        $this->assertSame('Owen Owner', $rams->generated_data['project']['project_manager']);
        $this->assertNotSame('client@acme.com', $rams->generated_data['project']['project_manager']);
    }

    public function test_site_contact_becomes_client_contact_name_when_empty(): void
    {
        $owner = User::factory()->create();
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => ['site_contact' => 'Sally Site']],
            'reviewed_data'  => [],
            'form_data'      => [],
        ]);

        $this->invokePatch($rams);

        $this->assertSame('Sally Site', $rams->generated_data['project']['client_contact_name']);
    }

    public function test_package_scope_filter_strips_cables_services_and_warranties(): void
    {
        $owner = User::factory()->create();
        $project = $this->baseProject($owner);

        $package = ProjectPackage::create([
            'user_id'     => $owner->id,
            'project_id'  => $project->id,
            'quote_path'  => 'fake.pdf',
            'status'      => ProjectPackage::STATUS_REVIEWED,
            'extracted_data' => [
                'equipment' => [
                    // Genuine hardware — should be kept
                    ['description' => 'Samsung QM75B 75" display', 'qty' => 2, 'location' => 'Boardroom'],
                    ['description' => 'Logitech Rally Bar',        'qty' => 1, 'location' => 'Boardroom'],
                    // Non-hardware — should be filtered
                    ['description' => '3 Year Extended Warranty',  'qty' => 1, 'location' => ''],
                    ['description' => 'Site Survey',               'qty' => 1, 'location' => ''],
                    ['description' => 'Cat6 Patch Cable 3m',       'qty' => 10, 'location' => ''],
                    ['description' => 'Configuration Service',     'qty' => 1, 'location' => ''],
                    ['description' => 'Delivery & Carriage',       'qty' => 1, 'location' => ''],
                ],
            ],
        ]);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => []],
            'reviewed_data'  => [],
            'form_data'      => [],
        ]);

        $this->invokePatch($rams);

        $newInstall = $rams->generated_data['scope_items']['new_install'] ?? [];
        $names = array_map(fn ($r) => $r['item_name'], $newInstall);

        // Hardware kept
        $this->assertContains('Samsung QM75B 75" display', $names);
        $this->assertContains('Logitech Rally Bar',        $names);
        // Non-hardware stripped
        $this->assertNotContains('3 Year Extended Warranty',  $names);
        $this->assertNotContains('Site Survey',               $names);
        $this->assertNotContains('Cat6 Patch Cable 3m',       $names);
        $this->assertNotContains('Configuration Service',     $names);
        $this->assertNotContains('Delivery & Carriage',       $names);
    }

    public function test_reviewed_data_defaults_populate_when_not_already_set(): void
    {
        $owner = User::factory()->create();
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => [], 'quote' => ['line_items' => [
                ['description' => 'Display 75"', 'room' => 'Boardroom'],
            ]]],
            'reviewed_data'  => [],
            'form_data'      => [],
        ]);

        $this->invokePatch($rams);

        $rd = $rams->reviewed_data;

        // scope_traceability is seeded from quote line_items
        $this->assertIsArray($rd['scope_traceability']);
        $this->assertCount(1, $rd['scope_traceability']);
        $this->assertSame('Display 75"', $rd['scope_traceability'][0]['quote_item']);

        // exclusions seeded with the canonical 5-item list
        $this->assertIsArray($rd['exclusions']);
        $this->assertCount(5, $rd['exclusions']);
        $this->assertContains('No structural works', $rd['exclusions']);

        // Other review sub-keys initialised to empty arrays
        $this->assertSame([], $rd['client_responsibilities_expanded']);
        $this->assertSame([], $rd['decommissioning']);
        $this->assertSame([], $rd['commissioning_criteria']);
    }

    public function test_existing_reviewed_data_is_preserved(): void
    {
        // Regression: default-seeding must NOT overwrite user-saved values.
        $owner = User::factory()->create();
        $project = $this->baseProject($owner);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_name'   => 'x', 'client_name' => 'x', 'site_address' => 'x', 'project_ref' => 'x',
            'ai_provider'    => 'claude', 'ai_model' => 'claude-sonnet',
            'filename'       => 'x.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'generated_data' => ['project' => []],
            'reviewed_data'  => [
                'exclusions'                        => ['Custom exclusion'],
                'client_responsibilities_expanded'  => [['item' => 'Custom responsibility']],
                'decommissioning'                   => [['item' => 'Custom decommission']],
                'commissioning_criteria'            => [['system' => 'Custom system']],
            ],
            'form_data'      => [],
        ]);

        $this->invokePatch($rams);

        $rd = $rams->reviewed_data;
        $this->assertSame(['Custom exclusion'], $rd['exclusions']);
        $this->assertSame([['item' => 'Custom responsibility']], $rd['client_responsibilities_expanded']);
        $this->assertSame([['item' => 'Custom decommission']],   $rd['decommissioning']);
        $this->assertSame([['system' => 'Custom system']],       $rd['commissioning_criteria']);
    }
}

<?php

namespace Tests\Feature\MiniOm;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\MiniOmBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Mini O&M cover shows a "Lead Engineer" line. Historically this
 * pulled `$project->owner->name` — for personal-account seeds that
 * value can be a login handle like "sonny" and reads as unprofessional
 * next to the client-facing RAMS cover which uses a properly-formatted
 * name (Sonny Tanda) sourced from the Programme block.
 *
 * The fix in MiniOmBuilderService::build() prefers
 * `latestPackage.extracted_data.programme.lead_engineer_name` when set,
 * falling back to owner name only when unset. Same lookup chain the
 * RAMS builder uses.
 */
class MiniOmLeadEngineerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_engineer_falls_back_to_project_owner_when_no_programme_data(): void
    {
        // Bare project — no package, no programme block. Fallback path.
        $user = User::factory()->create(['name' => 'sonny']);
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Bare project',
            'client_name'  => 'Client Ltd',
            'site_address' => '1 Site St',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $ctx = app(MiniOmBuilderService::class)->build($project->fresh());

        $this->assertSame('sonny', $ctx['project']['lead_engineer']);
    }

    public function test_lead_engineer_prefers_programme_lead_engineer_name_when_present(): void
    {
        // With a package that carries a Programme block naming the lead
        // engineer, the properly-typed name wins over the raw owner handle.
        $user    = User::factory()->create(['name' => 'sonny']);
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Project with programme',
            'client_name'  => 'Client Ltd',
            'site_address' => '1 Site St',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'source'         => 'quote',
            'status'         => 'reviewed',
            'extracted_data' => [
                'programme' => [
                    'lead_engineer_name' => 'Richard Martin',
                ],
            ],
        ]);

        $ctx = app(MiniOmBuilderService::class)->build($project->fresh());

        $this->assertSame('Richard Martin', $ctx['project']['lead_engineer']);
    }

    public function test_lead_engineer_falls_back_when_programme_field_is_empty_string(): void
    {
        // An empty-string programme field (user cleared the input) must fall
        // back to owner — otherwise we'd render a blank "Lead Engineer:" row
        // when the owner name would have been the safer default.
        $user    = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Project',
            'client_name'  => 'Client Ltd',
            'site_address' => '1 Site St',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'source'         => 'quote',
            'status'         => 'reviewed',
            'extracted_data' => [
                'programme' => ['lead_engineer_name' => '   '],
            ],
        ]);

        $ctx = app(MiniOmBuilderService::class)->build($project->fresh());

        $this->assertSame('Sonny Tanda', $ctx['project']['lead_engineer']);
    }
}

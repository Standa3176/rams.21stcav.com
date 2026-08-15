<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectPackages;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Services\MiniOmBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Quick task 260815-sup — proves the exclusion boundary for the new
 * `hardware_supply_only` category holds. A package with one `hardware` line
 * and one `hardware_supply_only` line must:
 *
 *   - INCLUDE both in O&M output (OmManualGeneratorService legacy-shape
 *     filter, MiniOmBuilderService quoted-assets + also-installed lists)
 *   - EXCLUDE the supply-only line from Project::hardwarePartNumbers()
 *     (RAMS), Project::devicesWithStencils() (drawings), the
 *     `stencils:coverage-report` command, and the site survey kit-by-area
 *     list — all four already use an exact `=== 'hardware'` match, so the
 *     new value is excluded by construction. This class exists to prove
 *     that construction actually holds, not to re-derive it.
 *
 * Class name deliberately contains "ProjectPackageReview" so it is swept up
 * by the phase verification filter
 * (`--filter="...|ProjectPackageReview|OmManual|MiniOm"`).
 *
 * @see .planning/quick/20260815-supply-only-category/PLAN.md
 */
class ProjectPackageReviewSupplyOnlyCategoryTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** One hardware line + one hardware_supply_only line, same room. */
    private function mixedEquipment(): array
    {
        return [
            [
                'quantity'    => 1,
                'part_number' => 'HW-INSTALLED-1',
                'name'        => 'Installed 75" Display',
                'area'        => 'Boardroom',
                'category'    => 'hardware',
            ],
            [
                'quantity'    => 1,
                'part_number' => 'SUP-ONLY-1',
                'name'        => 'Client-owned PTZ camera',
                'area'        => 'Boardroom',
                'category'    => 'hardware_supply_only',
            ],
        ];
    }

    private function makeProjectWithEquipment(array $equipment): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Supply-Only Category Test',
            'ref'          => 'SUP-'.fake()->numerify('###'),
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Supply Street, London',
            'status'       => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test-quote.pdf',
            'quote_path'        => 'quotes/test-quote.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Test works',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return $project->fresh();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Inclusion — O&M manual (the "important" half of the exclusion proof)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_om_manual_legacy_shape_filter_includes_both_hardware_and_supply_only(): void
    {
        $user = User::factory()->create();

        // No project link — exercises OmManualGeneratorService's legacy flat
        // extracted_data['equipment'] shape (PDF-uploaded O&Ms), which is
        // where the Task 2 predicate at ~line 845 lives.
        $manual = OmManual::create([
            'user_id'         => $user->id,
            'project_id'      => null,
            'project_name'    => 'Supply-Only O&M Test',
            'project_ref'     => 'SUP-OM-001',
            'client_name'     => 'Test Client Ltd',
            'site_address'    => '1 Supply Street, London',
            'source_filename' => 'quote.pdf',
            'source_path'     => 'om-sources/test_om.pdf',
            'status'          => 'extracted',
            'extracted_data'  => ['equipment' => $this->mixedEquipment()],
            'generated_data'  => null,
            'filename'        => null,
        ]);

        $service = app(OmManualGeneratorService::class);
        $ref     = new ReflectionClass(OmManualGeneratorService::class);
        $method  = $ref->getMethod('buildContentContext');
        $method->setAccessible(true);

        $context = $method->invoke($service, $manual);

        $this->assertArrayHasKey('rooms', $context);
        $names = collect($context['rooms'])
            ->flatMap(fn (array $room) => collect($room['equipment'] ?? [])->pluck('name'));

        $this->assertTrue(
            $names->contains(fn (string $n) => str_contains($n, 'Installed 75" Display')),
            'O&M output must include the hardware line.',
        );
        $this->assertTrue(
            $names->contains(fn (string $n) => str_contains($n, 'Client-owned PTZ camera')),
            'O&M output must include the hardware_supply_only line — client owns the kit, it belongs in handover documentation.',
        );
    }

    public function test_mini_om_quoted_assets_for_room_includes_both_hardware_and_supply_only(): void
    {
        $project = $this->makeProjectWithEquipment($this->mixedEquipment());
        $package = $project->latestPackage;

        $service = app(MiniOmBuilderService::class);
        $ref     = new ReflectionClass(MiniOmBuilderService::class);
        $method  = $ref->getMethod('quotedAssetsForRoom');
        $method->setAccessible(true);

        $rows = $method->invoke($service, $package, 'Boardroom');

        $partNumbers = collect($rows)->pluck('part_number')->all();
        $this->assertContains('HW-INSTALLED-1', $partNumbers);
        $this->assertContains('SUP-ONLY-1', $partNumbers,
            'Mini O&M must list client-owned supply-only kit alongside installed hardware.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Exclusion — everything that must NOT see the supply-only line
    // ═════════════════════════════════════════════════════════════════════════

    public function test_hardware_part_numbers_excludes_supply_only_line(): void
    {
        $project = $this->makeProjectWithEquipment($this->mixedEquipment());

        $parts = $project->hardwarePartNumbers();

        $this->assertSame(['hw-installed-1'], $parts,
            'Project::hardwarePartNumbers() (feeds RAMS) must exclude hardware_supply_only.');
    }

    public function test_devices_with_stencils_excludes_supply_only_line(): void
    {
        $project = $this->makeProjectWithEquipment($this->mixedEquipment());

        $rows = $project->devicesWithStencils();

        $this->assertCount(1, $rows,
            'Project::devicesWithStencils() (feeds drawings) must exclude hardware_supply_only.');
        $this->assertSame('HW-INSTALLED-1', $rows[0]['part_number']);
    }

    public function test_stencils_coverage_report_excludes_supply_only_part_number(): void
    {
        $this->makeProjectWithEquipment($this->mixedEquipment());

        Artisan::call('stencils:coverage-report');
        $output = Artisan::output();

        $this->assertStringContainsString(strtolower('HW-INSTALLED-1'), $output);
        $this->assertStringNotContainsString(strtolower('SUP-ONLY-1'), $output,
            'stencils:coverage-report must not count the supply-only line.');
    }

    public function test_site_survey_kit_by_area_excludes_supply_only_line(): void
    {
        $project = $this->makeProjectWithEquipment($this->mixedEquipment());
        $user    = User::findOrFail($project->user_id);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'client_name'  => $project->client_name,
            'status'       => 'draft',
        ]);
        $survey->rooms()->create([
            'room_name'  => 'Boardroom',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('site-surveys.edit', $survey));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('HW-INSTALLED-1', $html,
            'Site survey kit list must include the installed hardware line.');
        $this->assertStringNotContainsString('SUP-ONLY-1', $html,
            'Site survey kit list must exclude the supply-only line — it is not being installed.');
    }
}

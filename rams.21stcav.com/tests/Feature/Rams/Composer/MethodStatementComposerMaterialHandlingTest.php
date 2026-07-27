<?php

namespace Tests\Feature\Rams\Composer;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Support\Rams\SectionComposers\MethodStatementComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for phase 260726-rf3 Plan 05a — MethodStatementComposer
 * must accept BOTH shapes of reviewed_data.material_handling:
 *
 *   LEGACY   → array<int, string>          (bullet list)
 *   PROD     → {large_items:[{item,weight_kg,handling_method}], handling_notes:string}
 *
 * Prior to Plan 05a the composer treated `material_handling` as a string list
 * only, so casting each `large_items` map through `(string)` threw
 * "Array to string conversion" under PHP 8.4 for every prod record.
 */
class MethodStatementComposerMaterialHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(array $reviewedData): RamsDocument
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        return RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => $project->ref ?? 'TEST-001',
            'project_name'   => 'Test Project',
            'client_name'    => 'Test Client',
            'site_address'   => 'Test Address',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-test.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'form_data'      => [],
            'reviewed_data'  => $reviewedData,
            'generated_data' => [],
        ]);
    }

    public function test_legacy_string_list_shape_populates_material_handling_only(): void
    {
        $rams = $this->makeRams([
            'material_handling' => [
                'Two-person lift for any item over 20 kg',
                'Screen trolley from vehicle to install location',
            ],
        ]);

        $dto = app(MethodStatementComposer::class)->compose($rams);

        $this->assertSame(
            [
                'Two-person lift for any item over 20 kg',
                'Screen trolley from vehicle to install location',
            ],
            $dto->materialHandling,
        );
        $this->assertSame([], $dto->materialHandlingItems,
            'Legacy bullet-list shape must NOT populate materialHandlingItems.');
    }

    public function test_prod_object_shape_populates_items_and_notes_without_warnings(): void
    {
        $rams = $this->makeRams([
            'material_handling' => [
                'large_items' => [
                    ['item' => 'Samsung QM86R display', 'weight_kg' => 55,  'handling_method' => '2-person team lift'],
                    ['item' => 'Populated AV rack',     'weight_kg' => 120, 'handling_method' => 'Mechanical trolley + 2-person team'],
                ],
                'handling_notes' => "Correct lifting technique briefed at toolbox talk.\nUse trolley for all items > 30 kg.",
            ],
        ]);

        // If the pre-Plan-05a bug is still present this call throws
        // "Array to string conversion" under PHP 8.4 error-mode.
        $dto = app(MethodStatementComposer::class)->compose($rams);

        // Structured items surfaced.
        $this->assertCount(2, $dto->materialHandlingItems);
        $this->assertSame('Samsung QM86R display',    $dto->materialHandlingItems[0]['item']);
        $this->assertSame(55.0,                       $dto->materialHandlingItems[0]['weight_kg']);
        $this->assertSame('2-person team lift',       $dto->materialHandlingItems[0]['handling_method']);
        $this->assertSame(120.0,                      $dto->materialHandlingItems[1]['weight_kg']);

        // handling_notes surfaced as bullets in the legacy string-list slot.
        $this->assertSame(
            [
                'Correct lifting technique briefed at toolbox talk.',
                'Use trolley for all items > 30 kg.',
            ],
            $dto->materialHandling,
        );
    }

    public function test_object_shape_with_only_notes_leaves_items_empty(): void
    {
        $rams = $this->makeRams([
            'material_handling' => [
                'handling_notes' => 'Manual lifts only; no mechanical aid required.',
            ],
        ]);

        $dto = app(MethodStatementComposer::class)->compose($rams);

        $this->assertSame([], $dto->materialHandlingItems);
        $this->assertSame(
            ['Manual lifts only; no mechanical aid required.'],
            $dto->materialHandling,
        );
    }

    public function test_empty_material_handling_produces_empty_dto_fields(): void
    {
        $rams = $this->makeRams(['material_handling' => []]);

        $dto = app(MethodStatementComposer::class)->compose($rams);

        $this->assertSame([], $dto->materialHandling);
        $this->assertSame([], $dto->materialHandlingItems);
    }
}

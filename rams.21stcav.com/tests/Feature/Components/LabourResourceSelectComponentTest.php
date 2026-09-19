<?php

namespace Tests\Feature\Components;

use App\Models\LabourResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Phase 44 Plan 03 Task 2 — proves the `<x-labour-resource-select>` component
 * enforces 44-CONTEXT.md D-02/D-03/D-04/D-05 structurally, not by convention.
 *
 * "Client should only be given engineer name never phone or email — this is
 * for PM only." — the user, verbatim, 2026-09-19 (44-CONTEXT.md D-04/D-05).
 *
 * @see app/View/Components/LabourResourceSelect.php
 * @see resources/views/components/labour-resource-select.blade.php
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-02, D-03, D-04, D-05)
 */
class LabourResourceSelectComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_active_resources_and_never_an_inactive_one(): void
    {
        $activeOne = LabourResource::factory()->create(['name' => 'Marcus Okafor']);
        $activeTwo = LabourResource::factory()->create(['name' => 'Priya Shah']);
        $inactive = LabourResource::factory()->create(['name' => 'Departed Dave', 'is_active' => false]);

        $html = Blade::render('<x-labour-resource-select />');

        $this->assertStringContainsString($activeOne->name, $html);
        $this->assertStringContainsString($activeTwo->name, $html);
        $this->assertStringNotContainsString($inactive->name, $html);
    }

    public function test_it_never_renders_email_or_phone_even_when_populated(): void
    {
        $withContact = LabourResource::factory()->create([
            'name'  => 'Robin Contactable',
            'email' => 'robin.contactable@example.test',
            'phone' => '07700900123',
        ]);

        $html = Blade::render('<x-labour-resource-select />');

        $this->assertStringContainsString($withContact->name, $html);
        $this->assertStringNotContainsString($withContact->email, $html);
        $this->assertStringNotContainsString($withContact->phone, $html);
    }

    public function test_selected_prop_marks_only_that_option_as_selected(): void
    {
        $activeOne = LabourResource::factory()->create(['name' => 'Marcus Okafor']);
        LabourResource::factory()->create(['name' => 'Priya Shah']);

        $html = Blade::render(
            '<x-labour-resource-select :selected="$ids" />',
            ['ids' => [$activeOne->id]]
        );

        preg_match_all('/<option[^>]*value="(\d+)"[^>]*>/', $html, $matches, PREG_SET_ORDER);

        $selectedIds = [];
        foreach ($matches as $match) {
            if (str_contains($match[0], 'selected')) {
                $selectedIds[] = (int) $match[1];
            }
        }

        $this->assertSame([$activeOne->id], $selectedIds);
    }

    public function test_it_renders_a_multi_select_with_array_style_name(): void
    {
        LabourResource::factory()->create();

        $defaultHtml = Blade::render('<x-labour-resource-select />');
        $this->assertStringContainsString('multiple', $defaultHtml);
        $this->assertStringContainsString('name="resource_ids[]"', $defaultHtml);

        $customHtml = Blade::render('<x-labour-resource-select name="visit_resource_ids" />');
        $this->assertStringContainsString('name="visit_resource_ids[]"', $customHtml);
    }

    public function test_options_are_ordered_by_name_ascending(): void
    {
        LabourResource::factory()->create(['name' => 'Zoe Last']);
        LabourResource::factory()->create(['name' => 'Amy First']);

        $html = Blade::render('<x-labour-resource-select />');

        $this->assertTrue(
            strpos($html, 'Amy First') < strpos($html, 'Zoe Last'),
            'Expected options to be ordered by name ascending.'
        );
    }
}

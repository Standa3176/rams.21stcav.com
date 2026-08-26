<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27 Plan 07, Task 2 — GATE-09 coverage-gap closure for the live PDF
 * render path.
 *
 * `resources/views/pdf/rams.blade.php` read `$rams->reviewed_data['material_handling']`
 * directly, bypassing `RamsComplianceUpgradeService::upgrade()` (and therefore
 * `enforceDisplayLiftGate()`) entirely by design. This plan re-points the
 * template at `$rams->generated_data['material_handling']` — the gated
 * output — falling back to `reviewed_data` ONLY when the gated key is
 * absent, so RAMS documents generated before this phase (which have no
 * `generated_data['material_handling']` key at all) keep rendering their
 * §6.7 table.
 *
 * @see resources/views/pdf/rams.blade.php
 * @see App\Services\DocxBuilderService::buildMaterialHandling()
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-07-PLAN.md
 */
class DisplayLiftPdfSourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>|null  $generatedMh  material_handling sub-array to
     *   place under generated_data, or null to omit the key entirely (historical shape).
     * @param  array<string, mixed>|null  $reviewedMh  material_handling sub-array to
     *   place under reviewed_data, or null to omit the key entirely.
     */
    private function ramsStub(?array $generatedMh, ?array $reviewedMh): object
    {
        $stub                 = new \stdClass();
        $stub->project_name   = 'Test Project';
        $stub->project_ref    = 'TEST-001';
        $stub->form_data      = [];
        $stub->client_name    = 'Test Client';
        $stub->site_address   = 'Test Site Address';
        $stub->created_at     = Carbon::create(2026, 8, 26);
        $stub->generated_data = $generatedMh === null ? [] : ['material_handling' => $generatedMh];
        $stub->reviewed_data  = $reviewedMh === null ? [] : ['material_handling' => $reviewedMh];

        return $stub;
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'scope_of_works'  => 'Test scope',
            'project'         => [
                'name'         => 'Test Project',
                'ref'          => 'TEST-001',
                'client'       => 'Test Client',
                'site_address' => 'Test Site Address',
            ],
            'hazards'          => [],
            'ppe'              => [],
            'persons_at_risk'  => [],
            'team'             => [],
            'method_statement' => ['phases' => []],
            'quote'            => [],
            'site_logistics'   => [],
        ], $overrides);
    }

    private function renderWith(?array $generatedMh, ?array $reviewedMh): string
    {
        return view('pdf.rams', [
            'data' => $this->baseData(),
            'rams' => $this->ramsStub($generatedMh, $reviewedMh),
        ])->render();
    }

    // ── Precedence: generated_data wins when both are present and differ ───

    public function test_pdf_renders_generated_data_rows_not_reviewed_data_rows_when_both_present(): void
    {
        $generatedMh = [
            'has_large_items' => true,
            'large_items'     => [
                ['item' => 'GATED Samsung 98" display', 'weight_kg' => '55', 'handling_method' => 'Team lift — minimum 3 persons'],
            ],
            'handling_notes' => 'Gated notes',
        ];
        $reviewedMh = [
            'has_large_items' => true,
            'large_items'     => [
                ['item' => 'UNGATED Samsung 98" display', 'weight_kg' => '55', 'handling_method' => 'Team lift — minimum 4 persons'],
            ],
            'handling_notes' => 'Ungated notes',
        ];

        $html = $this->renderWith($generatedMh, $reviewedMh);

        $this->assertStringContainsString('GATED Samsung 98&quot; display', $html);
        $this->assertStringNotContainsString('UNGATED Samsung 98', $html);
    }

    // ── Historical-document fallback: no generated_data key at all ─────────

    public function test_pdf_falls_back_to_reviewed_data_when_generated_data_key_absent(): void
    {
        $reviewedMh = [
            'has_large_items' => true,
            'large_items'     => [
                ['item' => 'Historical Samsung 98" display', 'weight_kg' => '55', 'handling_method' => 'Team lift — minimum 3 persons'],
            ],
            'handling_notes' => 'Historical notes',
        ];

        // No 'material_handling' key in generated_data at all — the
        // pre-Plan-27-07 shape.
        $html = $this->renderWith(null, $reviewedMh);

        $this->assertStringContainsString('Historical Samsung 98&quot; display', $html);
    }

    // ── Neither key present renders without error ───────────────────────────

    public function test_pdf_renders_without_error_when_neither_key_present(): void
    {
        $html = $this->renderWith(null, null);

        $this->assertIsString($html);
        $this->assertStringContainsString('Material Handling', $html);
    }
}

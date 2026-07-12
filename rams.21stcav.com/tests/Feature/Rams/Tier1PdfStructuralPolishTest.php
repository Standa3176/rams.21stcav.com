<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 5 feature tests — PDF frontmatter + structural
 * polish. One test per structural addition:
 *
 *   1. Table of Contents page rendered between cover and Section 1.
 *   2. Standards & Guidance Applicable to This Works table rendered in
 *      Section 3.
 *   3. PPE colour convention paragraph rendered inside 6.3 PPE Matrix
 *      block (gated by $data['ppe_matrix'] non-empty).
 *   4. 6.11 Coordination with Other Trades section rendered.
 *   5. 6.12 IT / Network Integration Safety section rendered.
 *
 * Existing preInstallNum / methodNum / mhNum / permitNum / fixingsNum /
 * qaNum dynamic-numbering pattern preserved verbatim — 6.11 and 6.12 are
 * always-render additions, no downstream renumbering.
 */
class Tier1PdfStructuralPolishTest extends TestCase
{
    private function ramsStub(): object
    {
        $stub                = new \stdClass();
        $stub->project_name  = 'Test Project';
        $stub->project_ref   = 'TEST-001';
        $stub->form_data     = [];
        $stub->client_name   = 'Test Client';
        $stub->site_address  = 'Test Site Address';
        $stub->created_at    = Carbon::create(2026, 7, 12);
        $stub->reviewed_data = [];

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

    private function renderWith(array $data): string
    {
        return view('pdf.rams', [
            'data' => $data,
            'rams' => $this->ramsStub(),
        ])->render();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. TOC page renders between cover and Section 1
    // ══════════════════════════════════════════════════════════════════════════

    public function test_toc_page_renders_between_cover_and_section_1(): void
    {
        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('Table of Contents', $html);
        // Sample of the 10 TOC rows expected.
        $this->assertStringContainsString('1. Document Control', $html);
        $this->assertStringContainsString('5. Risk Assessment', $html);
        $this->assertStringContainsString('6. Method Statement', $html);
        $this->assertStringContainsString('7. Emergency Procedures', $html);
        $this->assertStringContainsString('8. Document Sign-Off', $html);
        $this->assertStringContainsString('COSHH Assessment', $html);
        $this->assertStringContainsString('Appendix A', $html);

        // Position check: TOC must appear before Section 1 heading in the
        // HTML source.
        $tocPos    = strpos($html, 'Table of Contents');
        $sec1Pos   = strpos($html, '1. &nbsp;Document Control');
        $this->assertNotFalse($tocPos);
        $this->assertNotFalse($sec1Pos);
        $this->assertLessThan(
            $sec1Pos,
            $tocPos,
            'TOC must render before Section 1 in the HTML source.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Standards & Guidance table rendered in Section 3
    // ══════════════════════════════════════════════════════════════════════════

    public function test_standards_applicable_table_renders_in_section_3(): void
    {
        config(['rams_tier1.enabled' => true]);

        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('Standards &amp; Guidance Applicable to This Works', $html);
        // At least 6 of the 8+ standards refs must be present.
        $this->assertStringContainsString('BS 7671', $html);
        $this->assertStringContainsString('BS 6701', $html);
        $this->assertStringContainsString('CDM 2015', $html);
        $this->assertStringContainsString('HSG 47', $html);
        $this->assertStringContainsString('HSG 273', $html);
        $this->assertStringContainsString('AVIXA F502.01', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. PPE colour convention paragraph inside 6.3
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ppe_colour_code_paragraph_renders_inside_6_3(): void
    {
        // The 6.3 PPE Matrix block gates on ppe_matrix non-empty — populate
        // a minimal row so the paragraph renders.
        $data = $this->baseData([
            'ppe_matrix' => [
                ['task' => 'Test task', 'ppe' => ['Safety Boots']],
            ],
        ]);

        $html = $this->renderWith($data);

        $this->assertStringContainsString('Hi-vis colour convention on this site', $html);
        $this->assertStringContainsString('orange', $html);
        $this->assertStringContainsString('EN ISO 20471', $html);

        // Position check: PPE paragraph must render between the 6.3 heading
        // and the PPE table.
        $headingPos = strpos($html, '6.3 Personal Protective Equipment');
        $paraPos    = strpos($html, 'Hi-vis colour convention on this site');
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($paraPos);
        $this->assertGreaterThan($headingPos, $paraPos);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Section 6.11 Coordination with Other Trades renders
    // ══════════════════════════════════════════════════════════════════════════

    public function test_section_6_11_coordination_with_other_trades_renders(): void
    {
        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('6.11 Coordination with Other Trades', $html);
        $this->assertStringContainsString('ceiling grid installer', $html);
        $this->assertStringContainsString('Interface disputes are escalated', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. Section 6.12 IT / Network Integration Safety renders
    // ══════════════════════════════════════════════════════════════════════════

    public function test_section_6_12_it_network_integration_safety_renders(): void
    {
        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('6.12 IT / Network Integration Safety', $html);
        $this->assertStringContainsString('Crestron, Q-SYS, Extron', $html);
        $this->assertStringContainsString('Power-cycle and network-fail recovery', $html);
    }
}

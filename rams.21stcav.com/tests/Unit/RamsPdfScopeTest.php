<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Unit tests for RAMS PDF scope section conditional rendering (D-01).
 *
 * Tests that rams.blade.php displays $data['scope_of_works'] when populated
 * and shows a visible red italic notice when it is absent — with no silent
 * fallback to boilerplate text.
 *
 * The view requires a $rams object with model-like properties. We use a plain
 * stdClass stub with the minimum properties the view accesses to avoid DB
 * dependencies.
 */
class RamsPdfScopeTest extends TestCase
{
    /**
     * Build a minimal $rams stub object for the Blade view.
     * The view accesses: ->project_name, ->project_ref, ->form_data,
     * ->client_name, ->site_address, ->created_at (Carbon).
     */
    private function ramsStub(): object
    {
        $stub                = new \stdClass();
        $stub->project_name  = 'Test Project';
        $stub->project_ref   = 'TEST-001';
        $stub->form_data     = [];
        $stub->client_name   = 'Test Client';
        $stub->site_address  = 'Test Site Address';
        $stub->created_at    = Carbon::create(2026, 4, 1);

        return $stub;
    }

    /**
     * Render the RAMS PDF Blade view with the given scope_of_works value.
     * $data mirrors what RamsBuilderService passes to the view.
     */
    private function renderScope(string $scopeOfWorks): string
    {
        $data = [
            'scope_of_works'  => $scopeOfWorks,
            'project'         => [
                'name'              => 'Test Project',
                'ref'               => 'TEST-001',
                'client'            => 'Test Client',
                'site_address'      => 'Test Site Address',
                'works_description' => 'should not appear',
            ],
            'hazards'          => [],
            'ppe'              => [],
            'persons_at_risk'  => [],
            'team'             => [],
            'method_statement' => ['phases' => []],
            'quote'            => [],
            'site_logistics'   => [],
        ];

        return view('pdf.rams', [
            'data' => $data,
            'rams' => $this->ramsStub(),
        ])->render();
    }

    /**
     * When scope_of_works is non-empty, the scope text is displayed and no
     * fallback or notice appears.
     */
    public function test_scope_of_works_renders_when_populated(): void
    {
        $html = $this->renderScope('Install Neat Bar Pro below display in the Board Room.');

        $this->assertStringContainsString(
            'Install Neat Bar Pro below display in the Board Room.',
            $html,
            'Scope text should appear when scope_of_works is populated.'
        );
        $this->assertStringNotContainsString(
            'Scope of works not generated',
            $html,
            'Notice should not appear when scope_of_works is populated.'
        );
        $this->assertStringNotContainsString(
            'AV installation works as per quotation',
            $html,
            'Old boilerplate fallback should not appear.'
        );
    }

    /**
     * When scope_of_works is empty, the red italic notice is displayed.
     */
    public function test_notice_renders_when_scope_empty(): void
    {
        $html = $this->renderScope('');

        $this->assertStringContainsString(
            'Scope of works not generated',
            $html,
            'Notice should appear when scope_of_works is empty.'
        );
        $this->assertStringContainsString(
            'color:#CC0000',
            $html,
            'Notice must use inline red color (dompdf-safe) when scope_of_works is empty.'
        );
        $this->assertStringNotContainsString(
            'AV installation works as per quotation',
            $html,
            'Old boilerplate fallback must not appear when scope_of_works is empty.'
        );
        $this->assertStringNotContainsString(
            'should not appear',
            $html,
            'works_description fallback values must not appear.'
        );
    }

    /**
     * The working hours note-text paragraph below the scope block is
     * unaffected in both populated and empty paths.
     */
    public function test_working_hours_note_unaffected_when_scope_populated(): void
    {
        $html = $this->renderScope('Install Neat Bar Pro below display in the Board Room.');

        $this->assertStringContainsString(
            'Monday',
            $html,
            'Working hours note must appear in both populated and empty scope paths.'
        );
    }

    /**
     * The working hours note-text paragraph is also present when scope is empty.
     */
    public function test_working_hours_note_unaffected_when_scope_empty(): void
    {
        $html = $this->renderScope('');

        $this->assertStringContainsString(
            'Monday',
            $html,
            'Working hours note must appear in the empty scope path.'
        );
    }

    /**
     * The inline color must be exactly #CC0000 (case-sensitive per CSS).
     */
    public function test_notice_uses_red_inline_style(): void
    {
        $html = $this->renderScope('');

        $this->assertStringContainsString(
            'color:#CC0000',
            $html,
            'Notice span must use color:#CC0000 inline style for dompdf compatibility.'
        );
        $this->assertStringContainsString(
            'font-style:italic',
            $html,
            'Notice span must use font-style:italic inline style.'
        );
    }
}

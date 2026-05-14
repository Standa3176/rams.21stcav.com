<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\Drawings\TitleBlockRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 23 Plan 04 — DRAW-48 TitleBlockRenderer (D-08 sources).
 *
 * 8-field title block per sheet:
 *   project · client · designed-by · drawn-by · checked-by · sheet · date · revision
 *
 * Source-of-truth resolution per D-08:
 *   project     → Project.name
 *   client      → Project.client_name
 *   sheet       → $sheet['sheet_number'] (from SheetPaginator)
 *   date        → now()->format('Y-m-d') (Carbon::setTestNow honoured)
 *   revision    → ProjectDrawing.version (else 'R0')
 *   designed-by → Auth::user()->name (else '—')
 *   drawn-by    → same as designed-by
 *   checked-by  → Project.metadata['drawing_checked_by'] (else '—')
 *
 * T-23-04-A1 — every interpolated user string is xml()-escaped before
 * becoming an mxCell value attribute. XSS tests cover project name,
 * client name, designed-by (Auth user name), and checked-by.
 *
 * @see app/Services/Drawings/TitleBlockRenderer.php
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-08
 */
class TitleBlockRendererTest extends TestCase
{
    use RefreshDatabase;

    private TitleBlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        // Carbon harness — Plan 01 Task 3 set this date for determinism across Phase 23.
        Carbon::setTestNow('2026-05-14 12:00:00');
        $this->renderer = app(TitleBlockRenderer::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sheet(string $number = 'AV-201'): array
    {
        return [
            'key'           => 'system_overview',
            'sheet_number'  => $number,
            'title'         => 'System Overview',
            'signal_filter' => null,
        ];
    }

    public function test_title_block_emits_eight_fields(): void
    {
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);

        $this->assertCount(8, $cells);
        $allTitleBlock = collect($cells)->every(fn ($c) => $c['kind'] === 'title-block-field');
        $this->assertTrue($allTitleBlock);
    }

    public function test_title_block_field_sources(): void
    {
        $project = Project::factory()->create([
            'name'        => 'Acme Boardroom Refurb',
            'client_name' => 'Acme Ltd',
        ]);

        $cells = $this->renderer->render($this->sheet('AV-201'), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Project: Acme Boardroom Refurb', $values);
        $this->assertContains('Client: Acme Ltd', $values);
        $this->assertContains('Sheet: AV-201', $values);
        $this->assertContains('Date: 2026-05-14', $values);
    }

    public function test_checked_by_fallback_to_dash(): void
    {
        $project = Project::factory()->create(['metadata' => null]);
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Checked by: —', $values);
    }

    public function test_checked_by_reads_metadata(): void
    {
        $project = Project::factory()->create([
            'metadata' => ['drawing_checked_by' => 'Bob Reviewer'],
        ]);
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Checked by: Bob Reviewer', $values);
    }

    public function test_designed_by_falls_back_to_dash_when_no_user(): void
    {
        // No actingAs() — Auth::user() returns null.
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Designed by: —', $values);
        $this->assertContains('Drawn by: —', $values);
    }

    public function test_designed_by_reads_auth_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Alice Engineer']);
        $this->actingAs($user);

        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Designed by: Alice Engineer', $values);
        $this->assertContains('Drawn by: Alice Engineer', $values);
    }

    public function test_revision_falls_back_to_r0_when_no_drawing(): void
    {
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project, null);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Rev: R0', $values);
    }

    public function test_revision_reads_drawing_version(): void
    {
        $project = Project::factory()->create();
        $drawing = ProjectDrawing::create([
            'project_id'   => $project->id,
            'kind'         => ProjectDrawing::KIND_SCHEMATIC,
            'version'      => 3,
            'sheet_number' => 'AV-201',
            'status'       => ProjectDrawing::STATUS_DRAFT,
            'filename'     => 'fixture.svg',
        ]);

        $cells = $this->renderer->render($this->sheet(), $project, $drawing);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Rev: 3', $values);
    }

    public function test_xss_escaped_in_project_name(): void
    {
        $project = Project::factory()->create(['name' => '<script>alert(1)</script>']);
        $cells = $this->renderer->render($this->sheet(), $project);

        $projectField = collect($cells)->firstWhere(fn ($c) => str_starts_with($c['value'], 'Project:'));
        $this->assertStringNotContainsString('<script>', $projectField['value']);
        $this->assertStringContainsString('&lt;script&gt;', $projectField['value']);
    }

    public function test_xss_escaped_in_client_name(): void
    {
        $project = Project::factory()->create([
            'client_name' => '<img src=x onerror=alert(1)>',
        ]);
        $cells = $this->renderer->render($this->sheet(), $project);

        $clientField = collect($cells)->firstWhere(fn ($c) => str_starts_with($c['value'], 'Client:'));
        $this->assertStringNotContainsString('<img', $clientField['value']);
        $this->assertStringContainsString('&lt;img', $clientField['value']);
    }

    public function test_xss_escaped_in_checked_by_metadata(): void
    {
        $project = Project::factory()->create([
            'metadata' => ['drawing_checked_by' => '<svg onload=alert(1)>'],
        ]);
        $cells = $this->renderer->render($this->sheet(), $project);

        $checkedField = collect($cells)->firstWhere(fn ($c) => str_starts_with($c['value'], 'Checked by:'));
        $this->assertStringNotContainsString('<svg', $checkedField['value']);
        $this->assertStringContainsString('&lt;svg', $checkedField['value']);
    }

    public function test_xss_escaped_in_designed_by_user_name(): void
    {
        $user = User::factory()->create(['name' => '<script>steal()</script>']);
        $this->actingAs($user);

        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);

        $designedField = collect($cells)->firstWhere(fn ($c) => str_starts_with($c['value'], 'Designed by:'));
        $this->assertStringNotContainsString('<script>', $designedField['value']);
        $this->assertStringContainsString('&lt;script&gt;', $designedField['value']);
    }

    public function test_title_block_y_from_config(): void
    {
        config()->set('drawings.page_dimensions.title_block_y', 940);
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);

        foreach ($cells as $c) {
            $this->assertSame(940, $c['y']);
        }
    }
}

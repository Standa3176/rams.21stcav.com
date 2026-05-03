<?php

namespace Tests\Unit\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\Drawings\SheetNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 20 Plan 01 — locks the AVIXA-style sheet-number allocator (DRAW-23).
 *
 * Allocator algorithm:
 *   - Block-base + (count of non-superseded drawings of this kind that already
 *     have a sheet_number) + 1.
 *   - Schematics live in 200s, racks in 300s.
 *   - Floor plans throw — Phase 19 deferred to v2.0.
 *
 * Tests run direct ProjectDrawing::create() to bypass DrawingService and test
 * the allocator in isolation. The DrawingService↔allocator wiring is exercised
 * by Task 2's BoundPdfBuilderServiceTest indirectly + the manual end-to-end
 * smoke in <verification>.
 */
class SheetNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id' => $user->id,
            'name' => 'Sheet Allocator Test',
            'ref' => 'SHEET-'.fake()->numerify('###'),
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Sheet Street, London',
            'status' => 'quote_imported',
        ]);
    }

    private function makeDrawing(Project $project, string $kind, ?string $sheet, bool $superseded = false): ProjectDrawing
    {
        $row = ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => $kind,
            'version' => 1,
            'sheet_number' => $sheet,
            'status' => $superseded
                ? ProjectDrawing::STATUS_SUPERSEDED
                : ProjectDrawing::STATUS_READY,
            'generated_by' => $project->user_id,
            'source_data' => [],
        ]);

        if ($superseded) {
            // Self-link superseded_by_id = own id (sentinel — the allocator only
            // cares that the column is NOT NULL, not what value it holds).
            $row->superseded_by_id = $row->id;
            $row->save();
        }

        return $row;
    }

    public function test_first_schematic_gets_av_201(): void
    {
        $project = $this->makeProject();

        $next = app(SheetNumberAllocator::class)
            ->allocate((int) $project->id, ProjectDrawing::KIND_SCHEMATIC);

        $this->assertSame('AV-201', $next);
    }

    public function test_second_schematic_gets_av_202(): void
    {
        $project = $this->makeProject();
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');

        $next = app(SheetNumberAllocator::class)
            ->allocate((int) $project->id, ProjectDrawing::KIND_SCHEMATIC);

        $this->assertSame('AV-202', $next);
    }

    public function test_first_rack_gets_av_301(): void
    {
        $project = $this->makeProject();
        // A schematic in the same project does NOT consume a rack number —
        // blocks are independent.
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');

        $next = app(SheetNumberAllocator::class)
            ->allocate((int) $project->id, ProjectDrawing::KIND_RACK);

        $this->assertSame('AV-301', $next);
    }

    public function test_superseded_drawings_dont_consume_a_number(): void
    {
        $project = $this->makeProject();

        // The original AV-201 was archived (status=superseded, superseded_by_id set).
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201', superseded: true);

        // The regenerated current AV-201 (a successor that kept the same number).
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201', superseded: false);

        // The next allocate() must skip the archived row — only the current
        // AV-201 counts → next = AV-202 (NOT AV-203).
        $next = app(SheetNumberAllocator::class)
            ->allocate((int) $project->id, ProjectDrawing::KIND_SCHEMATIC);

        $this->assertSame('AV-202', $next);
    }

    public function test_floor_plan_kind_not_supported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/v2\.0/i');

        app(SheetNumberAllocator::class)->allocate(1, ProjectDrawing::KIND_FLOOR_PLAN);
    }
}

<?php

namespace App\Services\Drawings;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Project;

/**
 * READ-ONLY reshape of ProjectDataService::resolve() into drawing-shaped views.
 *
 * Generators MUST NOT touch extracted_data / reviewed_data / survey_data
 * directly — Phase 17's contract is "drawings only ever consume the canonical
 * dataset", and this service is the single seam that enforces it.
 *
 * Phase 17 Plan 01 establishes the SHAPE of adjacencyForProject() so Plan 02
 * (SchematicGeneratorService) can be wired against it; Plan 02 fills the body.
 * rackStackForProject() and floorPlanGlyphsForRoom() are stubbed for build-order
 * doctrine (per ARCHITECTURE.md §8) — Phase 18 / 19 implement them later.
 *
 * @see DATA-03 — locked.
 * @see app/Core/Modules/Projects/ProjectDataService.php
 */
class DrawingDataResolverService
{
    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    /**
     * Per-room signal-flow adjacency. The shape returned here is consumed by
     * Plan 02's SchematicGeneratorService — fill the body in Plan 02 Task 1
     * by walking $data['rooms'], $data['equipment'], and $data['cables'].
     *
     * @return array<int, array{
     *   room_id: int|null,
     *   room_name: string,
     *   devices: array<int, array{equipment_id: int|string, name: string, manufacturer: string|null, model: string|null, signal_role: string|null}>,
     *   cables: array<int, array{cable_id: string, source_equipment_id: int|string|null, source_port: string|null, dest_equipment_id: int|string|null, dest_port: string|null, signal_type: string|null}>,
     * }>
     */
    public function adjacencyForProject(Project $project): array
    {
        // Plan 02 Task 1 fills the body using the canonical dataset:
        //   $data = $this->projectDataService->resolve($project);
        // For Plan 01 we keep the read so a future regression of the
        // ProjectDataService::resolve() contract surfaces here, not in
        // Plan 02's first run.
        $this->projectDataService->resolve($project);

        return [];
    }

    /**
     * Stubbed for Phase 18. Implemented in Phase 18 Plan 01 (Rack Elevations).
     */
    public function rackStackForProject(Project $project): array
    {
        throw new \RuntimeException('rackStackForProject implemented in Phase 18');
    }

    /**
     * Stubbed for Phase 19. Implemented in Phase 19 Plan 01 (Floor Plans).
     */
    public function floorPlanGlyphsForRoom(Project $project, int $roomId): array
    {
        throw new \RuntimeException('floorPlanGlyphsForRoom implemented in Phase 19');
    }
}

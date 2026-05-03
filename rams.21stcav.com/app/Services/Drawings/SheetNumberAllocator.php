<?php

namespace App\Services\Drawings;

use App\Models\ProjectDrawing;
use InvalidArgumentException;

/**
 * Phase 20 Plan 01 — allocates the next AVIXA-style sheet number for a
 * drawing within a project (DRAW-23).
 *
 * Numbering blocks (per Uniform Drawing System Module 1 — Sheet Identification
 * convention):
 *   - schematics → AV-201..AV-299 (block base 200)
 *   - racks      → AV-301..AV-399 (block base 300)
 *   - floor plans (KIND_FLOOR_PLAN) → throws — lands in v2.0 (Phase 19 deferred)
 *
 * Algorithm (set-once, never re-derived):
 *   next-number = block-base + (count of non-superseded drawings of this kind
 *                                in this project that already have a sheet_number)
 *               + 1
 *
 * Idempotent against archived rows: a regenerated drawing keeps its existing
 * sheet_number (the allocator is only consulted on draft create, and the
 * superseded predecessor still has a number — the count stays correct).
 *
 * @see app/Services/Drawings/DrawingService.php::createForProject — call site
 * @see database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php
 */
class SheetNumberAllocator
{
    /**
     * Block-base by drawing kind. Schematics in the 200s, racks in the 300s,
     * matching the AV industry convention referenced in CONTEXT.md and the
     * Uniform Drawing System Module 1.
     *
     * Floor plans (KIND_FLOOR_PLAN) intentionally absent — Phase 19 deferred
     * to v2.0 (2026-05-02 scope reduction).
     */
    private const BLOCK_BASES = [
        ProjectDrawing::KIND_SCHEMATIC => 200,
        ProjectDrawing::KIND_RACK      => 300,
    ];

    /**
     * Allocate the next AV-XXX number for the given (project, kind) pair.
     *
     * @param  int  $projectId  Project::id
     * @param  string  $kind  ProjectDrawing::KIND_SCHEMATIC | KIND_RACK
     * @return string  e.g. "AV-201"
     *
     * @throws InvalidArgumentException When $kind is not supported in v1.3.
     */
    public function allocate(int $projectId, string $kind): string
    {
        $base = self::BLOCK_BASES[$kind] ?? throw new InvalidArgumentException(
            "SheetNumberAllocator: kind '{$kind}' not supported in v1.3 (floor plans land in v2.0)"
        );

        // Block-base + (occupied + 1). Schematics live in 200s, racks in 300s;
        // count non-superseded drawings of this kind in this project that
        // already have a sheet_number, then assign base + count + 1.
        $existing = ProjectDrawing::query()
            ->where('project_id', $projectId)
            ->where('kind', $kind)
            ->whereNull('superseded_by_id')
            ->whereNotNull('sheet_number')
            ->count();

        return sprintf('AV-%d', $base + $existing + 1);
    }
}

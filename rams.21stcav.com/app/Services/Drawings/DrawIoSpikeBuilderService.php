<?php

namespace App\Services\Drawings;

use App\Models\Project;

/**
 * Backwards-compat shim for the original spike-260509-ibx builder.
 *
 * Plan 21-03 generalised the draw.io builder into `DrawIoBuilderService`
 * (DB-backed reads from `device_stencils` instead of the hand-coded
 * `21cav-mtr-spike.json` pack). This class is preserved as a thin
 * delegating shim per CONTEXT.md D-08 so any external reference (test,
 * controller, code path) that still type-hints `DrawIoSpikeBuilderService`
 * keeps working without modification.
 *
 * The historical pack at
 * `resources/data/draw-io-stencils/21cav-mtr-spike.json` is kept in
 * the repository as a reference fixture but is NOT consumed at runtime
 * after Plan 21-03 — `DrawIoBuilderService` reads from the
 * `device_stencils` table populated by Plan 21-02's seeder.
 *
 * @deprecated Use {@see DrawIoBuilderService} directly. This shim exists
 *             only for backwards compatibility during the v2.0 phase
 *             rollout and will be removed once all call-sites are migrated.
 * @see app/Services/Drawings/DrawIoBuilderService.php
 * @see resources/data/draw-io-stencils/21cav-mtr-spike.json — historical reference
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-08)
 */
class DrawIoSpikeBuilderService
{
    public function __construct(private readonly DrawIoBuilderService $builder) {}

    public function build(Project $project): string
    {
        return $this->builder->build($project);
    }
}

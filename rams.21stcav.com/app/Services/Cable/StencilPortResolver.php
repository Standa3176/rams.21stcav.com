<?php

namespace App\Services\Cable;

use App\Models\Device;
use App\Models\DeviceStencil;
use Illuminate\Support\Collection;

/**
 * T2-A — Single source of truth for the bulk stencil-by-part_number lookup
 * used across the backfill command, the port-picker controller, and the
 * cable generator.
 *
 * Devices carry no native belongsTo(DeviceStencil) relation because stencils
 * are cached cross-project on NORMALISED part_number, not a foreign key. Every
 * caller that needs `$device->stencil->ports` therefore has to normalise +
 * whereIn + setRelation. Before T2-A the exact same 4-line block lived in
 * three places (BackfillCablePortFksCommand::loadProjectDevicesWithStencils,
 * CableScheduleController::edit, and inside CableScheduleGeneratorService's
 * device path) — this resolver collapses them to one call site.
 *
 * Behaviour:
 *   - ONE whereIn query per call regardless of device count (batched).
 *   - Every device gets setRelation('stencil', DeviceStencil|null) applied so
 *     downstream accessors are deterministic and never fall back to a lazy-
 *     load attempt on the missing native relation.
 *   - Devices with null/empty part_no explicitly get setRelation('stencil', null).
 *
 * @see app/Console/Commands/BackfillCablePortFksCommand.php
 * @see app/Http/Controllers/CableScheduleController.php
 * @see app/Services/CableScheduleGeneratorService.php
 */
class StencilPortResolver
{
    /**
     * Attach the matching DeviceStencil (with ports) to every Device in the
     * collection via setRelation. Returns the same collection instance.
     *
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, Device>
     */
    public function attachToDevices(Collection $devices): Collection
    {
        if ($devices->isEmpty()) {
            return $devices;
        }

        $partNumbers = $devices
            ->pluck('part_no')
            ->filter()
            ->map(fn ($pn) => DeviceStencil::normalisePartNumber((string) $pn))
            ->unique()
            ->values()
            ->all();

        $stencilsByPartNumber = $partNumbers
            ? DeviceStencil::query()
                ->whereIn('part_number', $partNumbers)
                ->with(['ports' => fn ($q) => $q->orderBy('side')->orderBy('sort_order')])
                ->get()
                ->keyBy('part_number')
            : collect();

        return $devices->each(function (Device $d) use ($stencilsByPartNumber) {
            $key = DeviceStencil::normalisePartNumber((string) ($d->part_no ?? ''));
            // Always set the relation (even to null) so downstream accessor
            // checks are deterministic and never trigger a lazy-load attempt
            // on the missing native stencil() relation.
            $d->setRelation('stencil', $stencilsByPartNumber->get($key));
        });
    }
}

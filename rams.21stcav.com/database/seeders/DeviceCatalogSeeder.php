<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Services\Drawings\DeviceCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Phase 18 Plan 01 — upserts rack metadata from the hand-curated manufacturer
 * JSON pack onto existing Device rows.
 *
 * Idempotent: re-running rewrites the same values (u_height /
 * is_rack_mounted / current_draw_a / weight_kg / btu_per_hour). Devices NOT
 * in the pack are NEVER touched — their u_height stays NULL, surfaced as
 * "U-height unknown" in Plan 18-03's rack renderer (CRIT-06: never silent
 * 1U guess).
 *
 * Match strategy: case-insensitive trimmed part_no (mirrors
 * DrawingDataResolverService::loadSignalRolesForProject normalisation). Uses
 * raw whereRaw('LOWER(TRIM(part_no)) = ?', [...]) with a bound parameter so
 * there is no SQL injection vector — the part_no values come from the
 * in-repo JSON pack (not user input).
 *
 * @see resources/data/device-port-catalog.json
 * @see app/Services/Drawings/DeviceCatalogService.php
 */
class DeviceCatalogSeeder extends Seeder
{
    public function __construct(private readonly DeviceCatalogService $catalog)
    {
    }

    public function run(): void
    {
        $rowsUpdated = 0;
        $partsApplied = 0;

        foreach ($this->catalog->all() as $partNoLower => $row) {
            $touched = Device::query()
                ->whereRaw('LOWER(TRIM(part_no)) = ?', [$partNoLower])
                ->update([
                    'u_height' => $row['u_height'] ?? null,
                    'is_rack_mounted' => $row['is_rack_mounted'] ?? null,
                    'requires_ventilation_gap_above' => $row['requires_ventilation_gap_above'] ?? null,
                    'requires_ventilation_gap_below' => $row['requires_ventilation_gap_below'] ?? null,
                ]);

            if ($touched > 0) {
                $rowsUpdated += $touched;
                $partsApplied++;
            }
        }

        Log::info('DeviceCatalogSeeder: applied catalog', [
            'rows_updated' => $rowsUpdated,
            'parts_applied' => $partsApplied,
            'pack_size' => count($this->catalog->all()),
        ]);
    }
}

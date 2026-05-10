<?php

namespace Database\Seeders;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Services\Drawings\DeviceStencilSeedReader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 21 Plan 02 Task 2 Step D — upserts the curated seed pack into the
 * device_stencils + device_ports tables (per CONTEXT.md D-05).
 *
 * Source of truth: `resources/data/device-stencils-seed/*.json` — 5 per-file
 * spike manifests + 53-entry _v1.3-promoted.json + ~39-entry _top-50-gap.json.
 * Read via DeviceStencilSeedReader (auto-detects per-file vs bulk shape +
 * flat-maps bulk into a single uniform list).
 *
 * Idempotency contract (mirrors DeviceCatalogSeeder):
 *   - part_number lookup uses DeviceStencil::normalisePartNumber() (lowercase
 *     trim) so "NEAT-BAR-PRO" / "neat-bar-pro" / " Neat-Bar-Pro " all hit
 *     the same row.
 *   - updateOrCreate matched on the normalised part_number — repeated runs
 *     rewrite the same payload without inserting duplicates (verified by
 *     DeviceStencilSeederTest::test_second_run_produces_zero_new_rows).
 *   - For each stencil: ports()->delete() + bulk-insert from manifest. The
 *     manifest is the single source of truth — manual port edits are wiped
 *     on reseed (intentional; engineers should edit the JSON manifest, not
 *     the DB row directly). Verified by
 *     DeviceStencilSeederTest::test_manual_port_edits_are_overwritten_by_reseed.
 *   - Wrapped in DB::transaction so partial failures roll back cleanly
 *     (T-21.02-03 mitigation).
 *
 * Source enum: every seeded stencil = engineer-curated regardless of
 * underlying provenance (spike / v1.3 / gap-fill). The manifest IS the
 * engineering authority per D-05; the seeder honours its declared source.
 *
 * Conflict resolution (per Plan 21-02 Task 2 action note D):
 *   - On part_number collision between manifests, per-file > _top-50-gap >
 *     _v1.3-promoted. The seeder applies the LAST seen entry per
 *     part_number (last-write-wins); reader sorting + upsert order means
 *     bulk manifests load BEFORE per-file (because filenames starting with
 *     '_' sort before alphanumerics in glob). Per-file always wins on the
 *     final write.
 *
 * @see app/Services/Drawings/DeviceStencilSeedReader.php
 * @see app/Models/DeviceStencil.php
 * @see app/Models/DevicePort.php
 * @see database/seeders/DeviceCatalogSeeder.php — sibling idempotency pattern
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-05)
 */
class DeviceStencilSeeder extends Seeder
{
    public function __construct(private readonly DeviceStencilSeedReader $reader) {}

    public function run(): void
    {
        $manifests = $this->reader->all();

        // Deduplicate: per-file manifests (no '_' prefix) win over bulk.
        // The reader already orders bulk before per-file (glob sort), so
        // last-write-wins on the indexed array gives us the right semantics.
        $byPartNumber = [];
        foreach ($manifests as $manifest) {
            $key = DeviceStencil::normalisePartNumber((string) $manifest['part_number']);
            $byPartNumber[$key] = $manifest;
        }

        $stencilsUpserted = 0;
        $portsUpserted = 0;

        DB::transaction(function () use ($byPartNumber, &$stencilsUpserted, &$portsUpserted) {
            foreach ($byPartNumber as $normalised => $manifest) {
                $stencil = DeviceStencil::updateOrCreate(
                    ['part_number' => $normalised],
                    [
                        'manufacturer'   => $manifest['manufacturer'] ?? null,
                        'model'          => $manifest['model'] ?? null,
                        'display_name'   => $manifest['display_name'] ?? null,
                        'mxgraph_xml'    => $manifest['mxgraph_xml'],
                        'logo_svg'       => $manifest['logo_svg'] ?? null,
                        'default_width'  => (int) ($manifest['default_width'] ?? 220),
                        'default_height' => (int) ($manifest['default_height'] ?? 140),
                        'source'         => $manifest['source'] ?? DeviceStencil::SOURCE_ENGINEER_CURATED,
                        'metadata'       => $manifest['metadata'] ?? null,
                    ]
                );

                $stencilsUpserted++;

                // Manifest is source of truth: wipe + re-insert ports each run.
                // The compound unique index on (device_stencil_id, port_id)
                // makes the delete+insert pattern safe (no orphan ghosts).
                DevicePort::where('device_stencil_id', $stencil->id)->delete();

                foreach (($manifest['ports'] ?? []) as $portData) {
                    DevicePort::create([
                        'device_stencil_id' => $stencil->id,
                        'label'             => (string) ($portData['label'] ?? ''),
                        'side'              => (string) ($portData['side'] ?? DevicePort::SIDE_LEFT),
                        'connector_type'    => (string) ($portData['connector_type'] ?? ''),
                        'signal_type'       => (string) ($portData['signal_type'] ?? ''),
                        'direction'         => (string) ($portData['direction'] ?? DevicePort::DIRECTION_IN),
                        'sort_order'        => (int) ($portData['sort_order'] ?? 0),
                        'port_id'           => (string) ($portData['port_id'] ?? ''),
                        'y_pct'             => isset($portData['y_pct']) ? (float) $portData['y_pct'] : null,
                        'x_pct'             => isset($portData['x_pct']) ? (float) $portData['x_pct'] : null,
                    ]);
                    $portsUpserted++;
                }
            }
        });

        Log::info('DeviceStencilSeeder: applied seed pack', [
            'rows_upserted'  => $stencilsUpserted,
            'ports_upserted' => $portsUpserted,
            'manifests_read' => count($manifests),
            'unique_part_numbers' => count($byPartNumber),
        ]);
    }
}

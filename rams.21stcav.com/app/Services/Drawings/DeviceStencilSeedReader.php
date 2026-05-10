<?php

namespace App\Services\Drawings;

use RuntimeException;

/**
 * Phase 21 Plan 02 — read-only loader for the engineer-curated stencil seed
 * pack at `resources/data/device-stencils-seed/`. Walks every `*.json` file
 * (skipping the `_INDEX.md` directory marker), validates each manifest's
 * shape, and returns a flat list ready for `DeviceStencilSeeder` to upsert
 * into the `device_stencils` + `device_ports` tables.
 *
 * Supports two manifest shapes (per CONTEXT.md D-05 + Task 1 directory layout):
 *
 *   1. **Per-stencil manifest** — top-level `part_number` key. Each file is
 *      one stencil. Used for the 5 spike-promoted MTR stencils so each lives
 *      in its own git-trackable file.
 *
 *   2. **Bulk manifest** — top-level `stencils: [...]` key, no top-level
 *      `part_number`. Each file holds many stencils. Used for
 *      `_v1.3-promoted.json` (53 entries) and `_top-50-gap.json` (gap-fill).
 *
 * The reader auto-detects shape by inspecting the decoded payload's keys and
 * flat-maps the inner list when bulk shape is detected. Callers see a single
 * uniform list of per-stencil arrays.
 *
 * Memoised per-instance so repeated calls in the same request don't re-walk
 * the directory (mirrors `DeviceCatalogService::all()` memoisation).
 *
 * Validation contract (`validate()`):
 *   - Throws `RuntimeException` with the file path + missing field name on
 *     schema violation
 *   - Required fields: part_number (non-empty string), slug (string),
 *     manufacturer (string), model (string), mxgraph_xml (non-empty string
 *     containing `<shape`), source (one of auto-generated/engineer-curated/
 *     ai-extracted), ports (array — may be empty)
 *   - Each port requires: port_id, label, side (left/right/top/bottom),
 *     connector_type, signal_type, direction (in/out/io)
 *
 * @see resources/data/device-stencils-seed/_INDEX.md
 * @see database/seeders/DeviceStencilSeeder.php
 * @see app/Services/Drawings/DeviceCatalogService.php — sibling read-only pattern
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-05)
 */
class DeviceStencilSeedReader
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cache = null;

    private const VALID_SOURCES = [
        'auto-generated',
        'engineer-curated',
        'ai-extracted',
    ];

    private const VALID_PORT_SIDES = ['left', 'right', 'top', 'bottom'];

    private const VALID_PORT_DIRECTIONS = ['in', 'out', 'io'];

    /**
     * Walk the seed-pack directory + return every stencil manifest as a flat
     * list. Per-file manifests pass through 1:1; bulk manifests are
     * flat-mapped via their `stencils` key.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when any manifest fails schema validation OR
     *                          its file shape is unrecognised
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach ($this->files() as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException("DeviceStencilSeedReader: cannot read {$path}");
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: invalid JSON in {$path} (json_decode error: ".json_last_error_msg().')'
                );
            }

            // Bulk manifest shape: {stencils: [...]} with no top-level part_number.
            if (isset($decoded['stencils']) && ! isset($decoded['part_number'])) {
                if (! is_array($decoded['stencils'])) {
                    throw new RuntimeException(
                        "DeviceStencilSeedReader: bulk manifest at {$path} has non-array 'stencils' key"
                    );
                }

                foreach ($decoded['stencils'] as $i => $stencil) {
                    if (! is_array($stencil)) {
                        throw new RuntimeException(
                            "DeviceStencilSeedReader: stencils[{$i}] in bulk manifest {$path} is not an object"
                        );
                    }
                    $this->validate($stencil, "{$path} [stencils[{$i}]]");
                    $out[] = $stencil;
                }

                continue;
            }

            // Per-stencil manifest shape: top-level part_number key.
            if (isset($decoded['part_number'])) {
                $this->validate($decoded, $path);
                $out[] = $decoded;

                continue;
            }

            throw new RuntimeException(
                "DeviceStencilSeedReader: unknown manifest shape at {$path} — expected top-level 'part_number' (per-stencil) OR 'stencils' (bulk)"
            );
        }

        $this->cache = $out;

        return $out;
    }

    /**
     * Validate a single stencil manifest entry. Throws RuntimeException with
     * the originating file path embedded in the message so engineers can find
     * the broken file from the test failure log.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest, string $path): void
    {
        foreach (['part_number', 'slug', 'manufacturer', 'model', 'mxgraph_xml', 'source', 'ports'] as $required) {
            if (! array_key_exists($required, $manifest)) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: manifest at {$path} is missing required field '{$required}'"
                );
            }
        }

        if (! is_string($manifest['part_number']) || trim($manifest['part_number']) === '') {
            throw new RuntimeException(
                "DeviceStencilSeedReader: manifest at {$path} has empty/non-string 'part_number'"
            );
        }

        foreach (['slug', 'manufacturer', 'model'] as $stringField) {
            if (! is_string($manifest[$stringField])) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: manifest at {$path} has non-string '{$stringField}'"
                );
            }
        }

        if (! is_string($manifest['mxgraph_xml']) || trim($manifest['mxgraph_xml']) === '') {
            throw new RuntimeException(
                "DeviceStencilSeedReader: manifest at {$path} has empty 'mxgraph_xml'"
            );
        }

        if (! str_contains($manifest['mxgraph_xml'], '<shape')) {
            throw new RuntimeException(
                "DeviceStencilSeedReader: manifest at {$path} 'mxgraph_xml' does not contain a <shape> element"
            );
        }

        if (! in_array($manifest['source'], self::VALID_SOURCES, true)) {
            throw new RuntimeException(
                "DeviceStencilSeedReader: manifest at {$path} has invalid 'source' '{$manifest['source']}' (must be one of: ".implode(', ', self::VALID_SOURCES).')'
            );
        }

        if (! is_array($manifest['ports'])) {
            throw new RuntimeException(
                "DeviceStencilSeedReader: manifest at {$path} has non-array 'ports'"
            );
        }

        foreach ($manifest['ports'] as $i => $port) {
            if (! is_array($port)) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: manifest at {$path} ports[{$i}] is not an object"
                );
            }

            foreach (['port_id', 'label', 'side', 'connector_type', 'signal_type', 'direction'] as $required) {
                if (! array_key_exists($required, $port)) {
                    throw new RuntimeException(
                        "DeviceStencilSeedReader: manifest at {$path} ports[{$i}] is missing required field '{$required}'"
                    );
                }
            }

            if (! in_array($port['side'], self::VALID_PORT_SIDES, true)) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: manifest at {$path} ports[{$i}] has invalid 'side' '{$port['side']}' (must be one of: ".implode(', ', self::VALID_PORT_SIDES).')'
                );
            }

            if (! in_array($port['direction'], self::VALID_PORT_DIRECTIONS, true)) {
                throw new RuntimeException(
                    "DeviceStencilSeedReader: manifest at {$path} ports[{$i}] has invalid 'direction' '{$port['direction']}' (must be one of: ".implode(', ', self::VALID_PORT_DIRECTIONS).')'
                );
            }
        }
    }

    /**
     * List all `*.json` files under the seed-pack directory in stable
     * (alphabetical) order. `_INDEX.md` and any other non-JSON sidecars are
     * naturally skipped by the glob extension filter.
     *
     * @return array<int, string>
     */
    private function files(): array
    {
        $dir = resource_path('data/device-stencils-seed');
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json');
        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }
}

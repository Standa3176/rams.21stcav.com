<?php

/**
 * Phase 21 Plan 02 Task 2 Step A — one-time promotion script.
 *
 * Reads resources/data/device-port-catalog.json (53 entries) + emits
 * resources/data/device-stencils-seed/_v1.3-promoted.json with the bulk
 * manifest shape DeviceStencilSeedReader expects.
 *
 * Run: php scripts/promote-v13-catalog.php
 *
 * The output file IS committed to repo. This script is the audit trail for
 * how the file was generated; re-running produces the same bytes (modulo
 * the `generated` date field).
 *
 * After this script runs, the script itself can be retained in the repo as
 * documentation of the promotion process — it's not invoked by any runtime
 * code path.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$catalogPath = __DIR__.'/../resources/data/device-port-catalog.json';
$catalog = json_decode(file_get_contents($catalogPath), true);

if (! is_array($catalog)) {
    fwrite(STDERR, "Cannot read or parse {$catalogPath}\n");
    exit(1);
}

/** @var App\Services\Drawings\AutoGenericStencilGenerator $generator */
$generator = app(App\Services\Drawings\AutoGenericStencilGenerator::class);

$stencils = [];
foreach ($catalog as $entry) {
    $partNumber = $entry['part_no'] ?? null;
    if (! is_string($partNumber) || trim($partNumber) === '') {
        continue;
    }

    $manufacturer = (string) ($entry['manufacturer'] ?? 'Unknown');
    $model = (string) ($entry['model'] ?? $partNumber);
    $displayName = trim($manufacturer.' '.$model);

    // Slug derivation: lowercase, replace spaces / slashes / quotes with -,
    // collapse repeated hyphens. Keeps part_number as the canonical
    // identifier; slug is filename-safe.
    $slug = strtolower(str_replace([' ', '/', '\\', '"', "'"], '-', $partNumber));
    $slug = (string) preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    $payload = $generator->build([
        'manufacturer' => $manufacturer,
        'model'        => $model,
        'name'         => $displayName,
        'part_number'  => $partNumber,
    ]);

    $stencils[] = [
        'part_number'    => $partNumber,
        'slug'           => $slug,
        'manufacturer'   => $manufacturer,
        'model'          => $model,
        'display_name'   => $displayName,
        'default_width'  => $payload['default_width'],
        'default_height' => $payload['default_height'],
        'mxgraph_xml'    => $payload['mxgraph_xml'],
        'source'         => 'engineer-curated',
        'metadata'       => [
            'provenance'                     => 'v1.3-catalog-promoted',
            'needs_phase_24_curation'        => true,
            'u_height'                       => $entry['u_height'] ?? null,
            'is_rack_mounted'                => $entry['is_rack_mounted'] ?? false,
            'requires_ventilation_gap_above' => $entry['requires_ventilation_gap_above'] ?? null,
            'current_draw_a'                 => $entry['current_draw_a'] ?? null,
            'weight_kg'                      => $entry['weight_kg'] ?? null,
            'btu_per_hour'                   => $entry['btu_per_hour'] ?? null,
        ],
        'ports'          => [],
    ];
}

$bulk = [
    'version'    => '1.0',
    'generated'  => date('Y-m-d'),
    'provenance' => sprintf(
        'Generated from resources/data/device-port-catalog.json (%d entries) — promoted as Tier 1.5 stencils per D-05 step 2. Each entry uses AutoGenericStencilGenerator body shell with manufacturer/model/part_number filled from the v1.3 JSON. metadata.needs_phase_24_curation=true so Phase 24 curation UI can filter. Rack metadata (u_height etc) kept in metadata for cross-reference; Phase 18 rack render still reads from device-port-catalog.json directly per D-10.',
        count($stencils)
    ),
    'stencils'   => $stencils,
];

$outPath = __DIR__.'/../resources/data/device-stencils-seed/_v1.3-promoted.json';
file_put_contents(
    $outPath,
    json_encode($bulk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo 'Wrote '.count($stencils)." stencils to {$outPath}".PHP_EOL;

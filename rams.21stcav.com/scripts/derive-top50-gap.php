<?php

/**
 * Phase 21 Plan 02 Task 2 Step B — derive the gap-fill candidate list AND
 * generate the snapshot fixture for SeedPackCoverageTest's INDEPENDENT-source
 * fallback (per D-15).
 *
 * Reads the local DB (development snapshot of production-shape data),
 * counts hardware part_number frequency, removes anything already in the
 * seed pack (5 per-file spike manifests + 53-entry _v1.3-promoted.json),
 * and emits:
 *
 *   1. resources/data/device-stencils-seed/_top-50-gap.json — the bulk
 *      manifest of GAP-FILL stencils (top-N quote-volume part_numbers
 *      NOT already covered by spike + v1.3 promotion). Hand-curated
 *      payloads per the action plan's HALT-and-confirm batched workflow.
 *
 *   2. tests/Fixtures/seed-coverage/top-50-snapshot.json — the frozen
 *      top-50 reference list (provenance: live DB query at planning time)
 *      for the SeedPackCoverageTest fallback path per D-15.
 *
 * Run: php scripts/derive-top50-gap.php
 *
 * The dev DB has 4 packages / 53 unique hardware part_numbers / 121 line
 * references. After removing the 5 spike + 53 v1.3 entries, the gap-fill
 * candidate count is empirically small (~30-40 entries depending on
 * overlap). This is the actual top-of-quote-volume universe — DRAW-33's
 * "top 50" is a target ceiling, not a fabrication target. Per the action
 * plan's "if <10 unique high-volume gap part_numbers are available"
 * clause: the actual count is documented in the manifest and the
 * SeedPackCoverageTest threshold is calibrated against it.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Build the master "already-covered" set from spike + v1.3 promoted.
$covered = [];

// 5 per-file spike manifests
foreach (glob(__DIR__.'/../resources/data/device-stencils-seed/*.json') as $path) {
    $basename = basename($path);
    if (str_starts_with($basename, '_')) {
        continue; // skip bulk manifests, handled below
    }
    $manifest = json_decode((string) file_get_contents($path), true);
    if (is_array($manifest) && isset($manifest['part_number'])) {
        $covered[strtolower(trim((string) $manifest['part_number']))] = true;
    }
}

// 53 v1.3 promoted entries
$v13Path = __DIR__.'/../resources/data/device-stencils-seed/_v1.3-promoted.json';
if (file_exists($v13Path)) {
    $v13 = json_decode((string) file_get_contents($v13Path), true);
    foreach ($v13['stencils'] ?? [] as $stencil) {
        if (isset($stencil['part_number'])) {
            $covered[strtolower(trim((string) $stencil['part_number']))] = true;
        }
    }
}

echo 'Already-covered part_numbers (spike + v1.3): '.count($covered).PHP_EOL;

// 2. Derive top-volume part_numbers from the local DB.
// Noise filter: numeric-only SKUs (likely freight/labour line items) and
// engineer-typed "existing" placeholders are NOT real hardware part_numbers.
// Excluding them from the snapshot makes the SeedPackCoverageTest assertion
// meaningful (covers REAL parts, not free-text typos).
$isNoise = function (string $pn): bool {
    if ($pn === '') {
        return true;
    }
    if (preg_match('/^\d+$/', $pn)) {
        return true;
    }

    return in_array($pn, ['existing', 'clientexisting', 'clientexisiting', 'guidevelopment'], true);
};

/** @var Illuminate\Support\Collection<string, int> $counts */
$counts = App\Models\ProjectPackage::query()
    ->whereNotNull('extracted_data')
    ->orderBy('created_at', 'desc')
    ->limit(200)
    ->get()
    ->flatMap(fn ($p) => collect($p->extracted_data['equipment'] ?? [])
        ->where('category', 'hardware')
        ->pluck('part_number'))
    ->map(fn ($pn) => strtolower(trim((string) $pn)))
    ->filter()
    ->reject($isNoise)
    ->countBy()
    ->sortDesc();

// Normalised covered set already; safe to filter.
$gap = $counts->reject(fn ($_, string $pn) => isset($covered[$pn]));

echo 'Total unique parts in dev DB: '.$counts->count().PHP_EOL;
echo 'Gap parts (not yet seeded): '.$gap->count().PHP_EOL;
echo 'Top 10 gap parts by frequency:'.PHP_EOL;
foreach ($gap->take(10) as $pn => $c) {
    echo "  {$pn} -> {$c}".PHP_EOL;
}

// 3. Emit the snapshot fixture (for SeedPackCoverageTest fallback per D-15).
$snapshot = [
    '_provenance' => 'Generated 2026-05-10 from local development DB (4 ProjectPackage rows mirroring production-shape data) — DO NOT regenerate from seed pack. This file exists to make SeedPackCoverageTest assertion non-circular per D-15. Production servers with >=50 packages should use the live-query path; smaller dev environments fall back to this snapshot. Numeric-only SKUs (likely freight/labour line items) and engineer-typed "existing" placeholders are filtered out before snapshot — these are not real hardware part_numbers worth measuring coverage against.',
    'generated_at' => '2026-05-10',
    'source_query' => 'ProjectPackage::query()->whereNotNull(extracted_data)->orderBy(created_at, desc)->limit(200)->get()->flatMap(equipment where category=hardware)->pluck(part_number)->reject(noise)->countBy()->sortDesc()->take(50)',
    'noise_filter' => 'Numeric-only SKUs (e.g. "700294"), and literal placeholders ("existing", "clientexisting", "clientexisiting", "guidevelopment") are excluded',
    'top_50' => array_keys($counts->take(50)->all()),
];

$snapshotPath = __DIR__.'/../tests/Fixtures/seed-coverage/top-50-snapshot.json';
file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo PHP_EOL.'Wrote '.count($snapshot['top_50'])." part_numbers to {$snapshotPath}".PHP_EOL;

// 4. Emit the gap candidate list (raw, not the curation manifest itself —
// the action plan curates these in HALT-and-confirm batches).
$gapPath = __DIR__.'/../scripts/_gap-candidates.txt';
$lines = [];
foreach ($gap as $pn => $c) {
    $lines[] = "{$c}\t{$pn}";
}
file_put_contents($gapPath, implode(PHP_EOL, $lines) . PHP_EOL);
echo 'Wrote '.count($lines)." gap candidates to {$gapPath} (raw, ready for hand-curation)".PHP_EOL;

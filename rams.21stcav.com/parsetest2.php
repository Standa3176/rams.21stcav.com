<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== parsetest2.php starting ===\n";

$appRoot = dirname(__FILE__);
// If this file is in /tmp, override to the real app root.
if (str_starts_with($appRoot, '/tmp')) {
    $appRoot = '/home/stcav/rams.21stcav.com';
}

echo "App root: {$appRoot}\n";

require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RamsDocument;
use Illuminate\Support\Facades\Storage;

echo "Laravel bootstrapped OK\n\n";

// ── Find the most recent uploaded RAMS document ──────────────────────────────
$rams = RamsDocument::whereNotNull('filename')->latest()->first();

if (! $rams) {
    echo "ERROR: No RamsDocument with a filename found.\n";
    exit(1);
}

echo "RamsDocument ID: {$rams->id}  Status: {$rams->status}\n";
echo "File: {$rams->filename}\n\n";

$absolutePath = Storage::disk('local')->path($rams->filename);
echo "Absolute path: {$absolutePath}\n";

if (! file_exists($absolutePath)) {
    echo "ERROR: File not found at: {$absolutePath}\n";
    exit(1);
}

echo "File exists OK\n\n";

// ── Extract text ─────────────────────────────────────────────────────────────
$extractor = app(App\Services\QuoteTextExtractorService::class);
$rawText   = $extractor->extract($absolutePath);

echo "Raw text length: " . strlen($rawText) . " chars\n\n";

// ── 1. PREPAREDBYSTART raw content ───────────────────────────────────────────
echo "=== PREPAREDBYSTART raw content ===\n";
if (preg_match('/PREPAREDBYSTART\s*(.*?)\s*PREPAREDBYEND/s', $rawText, $m)) {
    $lines = preg_split('/\r?\n/', $m[1]);
    foreach (array_slice($lines, 0, 25) as $i => $line) {
        $line = trim($line);
        echo "  line[{$i}]: " . ($line === '' ? '(empty)' : $line) . "\n";
    }
} else {
    echo "  (PREPAREDBYSTART tag not found)\n";
}

echo "\n";

// ── 2. PARTSTART raw values ───────────────────────────────────────────────────
echo "=== PARTSTART values (first 15 items) ===\n";
preg_match_all(
    '/PARTSTART\s*(.*?)\s*PARTEND\s*PARTDESCSTART\s*(.*?)\s*PARTDESCEND\s*QTYSTART\s*([\d.]+)\s*QTYEND/s',
    $rawText,
    $tuples,
    PREG_OFFSET_CAPTURE
);

$count = min(15, count($tuples[0]));
for ($i = 0; $i < $count; $i++) {
    $rawPart = trim($tuples[1][$i][0]);
    $rawDesc = trim(preg_replace('/\s+/', ' ', $tuples[2][$i][0]));
    $rawDesc = mb_substr($rawDesc, 0, 70);
    $qty     = $tuples[3][$i][0];

    // Line immediately before PARTSTART
    $offset    = $tuples[0][$i][1];
    $before    = substr($rawText, 0, $offset);
    $bLines    = preg_split('/\r?\n/', $before);
    $preceding = '';
    for ($j = count($bLines) - 1; $j >= 0; $j--) {
        $bl = trim($bLines[$j]);
        if ($bl !== '') { $preceding = $bl; break; }
    }

    echo "  [{$i}] qty={$qty}\n";
    echo "       PARTSTART raw : " . ($rawPart === '' ? '(EMPTY)' : $rawPart) . "\n";
    echo "       Preceding line: " . ($preceding === '' ? '(EMPTY)' : $preceding) . "\n";
    echo "       Desc snippet  : {$rawDesc}\n\n";
}

echo "Total tuples: " . count($tuples[0]) . "\n";
echo "=== done ===\n";

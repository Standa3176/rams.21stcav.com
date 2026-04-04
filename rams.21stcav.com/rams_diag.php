<?php
// RAMS diagnostic script — upload to public/ then visit URL
// Token required: ?t=diag21cav2026
if (!isset($_GET['t']) || $_GET['t'] !== 'diag21cav2026') {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);

echo "=== RAMS DIAGNOSTIC (" . date('Y-m-d H:i:s') . ") ===\n\n";

// 1. Check DocxBuilderService.php
$dbs = $root . '/app/Services/DocxBuilderService.php';
echo "--- DocxBuilderService.php ---\n";
if (!file_exists($dbs)) {
    echo "NOT FOUND: $dbs\n";
} else {
    $src = file_get_contents($dbs);
    echo "File size: " . strlen($src) . " bytes\n";
    echo "vMerge present: " . (strpos($src, "vMerge'") !== false ? "YES (bug still present!)" : "NO (good)") . "\n";
    echo "Single header row fix: " . (strpos($src, 'Single header row') !== false ? "YES (fix applied)" : "NO (fix not applied)") . "\n";
    echo "persons_at_risk key: " . (strpos($src, "persons_at_risk") !== false ? "YES (good)" : "NO") . "\n";
    echo "consequences key: " . (strpos($src, "'consequences'") !== false ? "YES (bug - old key still there)" : "NO (good)") . "\n";
    // Check if backup exists
    $backups = glob($dbs . '.bak.*');
    if ($backups) {
        echo "Backups found: " . implode(', ', array_map('basename', $backups)) . "\n";
    } else {
        echo "No backups (deploy script has not run yet)\n";
    }
}

// 2. Check storage/app/rams directory
echo "\n--- storage/app/rams/ ---\n";
$ramsDir = $root . '/storage/app/rams';
if (!is_dir($ramsDir)) {
    echo "Directory does NOT exist: $ramsDir\n";
} else {
    $files = glob($ramsDir . '/*.docx');
    echo "Directory exists. DOCX files found: " . count($files) . "\n";
    foreach ($files as $f) {
        echo "  " . basename($f) . " (" . filesize($f) . " bytes, modified " . date('Y-m-d H:i:s', filemtime($f)) . ")\n";
    }
    if (empty($files)) {
        echo "  (none — no DOCX has been generated yet, or generation failed before save)\n";
    }
}

// 3. Check DB for recent rams_documents
echo "\n--- rams_documents (last 10 records) ---\n";
try {
    // Load .env
    $envFile = $root . '/.env';
    $env = [];
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false && $line[0] !== '#') {
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }

    $host   = $env['DB_HOST']     ?? '127.0.0.1';
    $port   = $env['DB_PORT']     ?? '3306';
    $dbname = $env['DB_DATABASE'] ?? '';
    $user   = $env['DB_USERNAME'] ?? '';
    $pass   = $env['DB_PASSWORD'] ?? '';

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query(
        "SELECT id, status, filename, error_message, created_at, updated_at
         FROM rams_documents
         ORDER BY id DESC
         LIMIT 10"
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "(no records found)\n";
    }
    foreach ($rows as $row) {
        echo sprintf(
            "ID:%d  status:%-15s  filename:%-40s  error:%s\n  created:%s  updated:%s\n",
            $row['id'],
            $row['status'] ?? 'NULL',
            $row['filename'] ?? 'NULL',
            $row['error_message'] ? substr($row['error_message'], 0, 80) : 'none',
            $row['created_at'],
            $row['updated_at']
        );
    }

    // Also check if the file on disk matches DB filename for the most recent completed record
    echo "\n--- File-on-disk check for last completed record ---\n";
    $stmt2 = $pdo->query(
        "SELECT id, filename FROM rams_documents WHERE status = 'completed' ORDER BY id DESC LIMIT 1"
    );
    $completed = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($completed) {
        $fn = $completed['filename'];
        $path1 = $ramsDir . '/' . $fn;
        $path2 = $root . '/storage/app/' . $fn;
        echo "Record ID: {$completed['id']}, filename: $fn\n";
        echo "  Path with rams/ prefix:    " . (file_exists($path1) ? "EXISTS" : "NOT FOUND") . "  ($path1)\n";
        echo "  Path without rams/ prefix: " . (file_exists($path2) ? "EXISTS" : "NOT FOUND") . "  ($path2)\n";
    } else {
        echo "(no completed records)\n";
    }

} catch (\Throwable $e) {
    echo "DB error: " . $e->getMessage() . "\n";
}

// 4. Check queue worker
echo "\n--- Queue / worker ---\n";
$workerLog = $root . '/storage/logs/worker.log';
if (file_exists($workerLog)) {
    $lines = array_slice(file($workerLog), -20);
    echo "Last 20 lines of worker.log:\n";
    echo implode('', $lines);
} else {
    echo "worker.log not found at $workerLog\n";
    // Check laravel.log
    $laravelLog = $root . '/storage/logs/laravel.log';
    if (file_exists($laravelLog)) {
        $lines = array_slice(file($laravelLog), -40);
        echo "Last 40 lines of laravel.log:\n";
        echo implode('', $lines);
    } else {
        echo "laravel.log not found either.\n";
    }
}

echo "\n=== END DIAGNOSTIC ===\n";

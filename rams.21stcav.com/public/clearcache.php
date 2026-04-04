<?php
if (($_GET['t'] ?? '') !== 'chk21cav') { http_response_code(403); exit('Forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$_ENV['APP_DEBUG'] = 'false';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Artisan;
foreach (['view:clear','config:clear','cache:clear','route:clear'] as $cmd) {
    Artisan::call($cmd);
    echo "{$cmd}: " . (trim(Artisan::output()) ?: 'OK') . "\n";
}
echo "\n[Done]\n";
@unlink(__FILE__);

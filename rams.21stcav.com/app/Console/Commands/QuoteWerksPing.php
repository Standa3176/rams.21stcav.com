<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * QuoteWerksPing — verifies MS SQL Server connectivity for QuoteWerks import.
 *
 * Usage: php artisan quotewerks:ping
 *
 * Exit codes:
 *   0 = connection successful
 *   1 = connection failed (driver missing, auth error, host unreachable, timeout)
 *
 * This command MUST be run on the production server before any SQL import code is
 * deployed. If the output says "could not find driver", follow the ODBC 17 install
 * instructions in .env.example — this is a missing PHP extension, not a config error.
 */
class QuoteWerksPing extends Command
{
    protected $signature   = 'quotewerks:ping';
    protected $description = 'Verify connectivity to the QuoteWerks SQL Server database';

    public function handle(): int
    {
        $this->info('Checking QuoteWerks SQL Server connection...');

        // ── Check PHP extension first ──
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('FAILED: pdo_sqlsrv extension is not loaded.');
            $this->line('  Run: php -m | grep sqlsrv');
            $this->line('  Install: follow instructions in .env.example under QW_DB_* section.');
            return self::FAILURE;
        }

        $this->line('  pdo_sqlsrv extension: OK');

        // ── Attempt connection ──
        try {
            $pdo     = DB::connection('quotewerks')->getPdo();
            $version = $pdo->query('SELECT @@VERSION')->fetchColumn();
            $this->info('QuoteWerks connection OK.');
            $this->line('  SQL Server version: ' . trim(explode("\n", $version)[0]));
            return self::SUCCESS;
        } catch (PDOException $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->line('');
            $this->line('Common causes:');
            $this->line('  - VPN not connected (host unreachable)');
            $this->line('  - Wrong QW_DB_HOST or QW_DB_PORT in .env');
            $this->line('  - Wrong QW_DB_USERNAME / QW_DB_PASSWORD');
            $this->line('  - TLS: try QW_DB_TRUST_CERT=true and QW_DB_ENCRYPT=yes');
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('FAILED (unexpected): ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

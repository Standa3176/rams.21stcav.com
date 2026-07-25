<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * QuoteWerksPing — verifies ODBC connectivity to the QuoteWerks SQL Server.
 *
 * Usage: php artisan quotewerks:ping
 *
 * Exit codes:
 *   0 = connection successful
 *   1 = connection failed (extension missing, DSN misconfigured, unreachable)
 *
 * Post-deploy smoke test — run this on the VPS after every deploy that
 * touches config/database.php or the QUOTEWERKS_ODBC_* env vars. If output
 * says "could not find driver", the pdo_odbc extension isn't loaded in the
 * web PHP — check `php -m | grep odbc`. The DSN itself lives at /etc/odbc.ini
 * on the VPS; the app never edits it.
 */
class QuoteWerksPing extends Command
{
    protected $signature   = 'quotewerks:ping';
    protected $description = 'Verify connectivity to the QuoteWerks SQL Server database (ODBC)';

    public function handle(): int
    {
        $this->info('Checking QuoteWerks ODBC connection...');

        // ── Check PHP extension first ──
        if (! extension_loaded('pdo_odbc')) {
            $this->error('FAILED: pdo_odbc extension is not loaded.');
            $this->line('  Run: php -m | grep odbc');
            $this->line('  On the VPS this is bundled with the base PHP install — reinstall the php-odbc');
            $this->line('  package (or php-pdo-odbc, depending on distro) if it went missing.');
            return self::FAILURE;
        }

        $this->line('  pdo_odbc extension: OK');

        // ── Attempt connection ──
        try {
            $pdo    = DB::connection('quotewerks')->getPdo();
            $docNo  = $pdo->query('SELECT TOP 1 DocNo FROM DocumentHeaders')->fetchColumn();
            $this->info('QuoteWerks connection OK.');
            $this->line('  Sample DocNo: ' . ($docNo !== false ? (string) $docNo : '(empty table)'));
            return self::SUCCESS;
        } catch (PDOException $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->line('');
            $this->line('Common causes:');
            $this->line('  - WireGuard tunnel to office SQL Server is down');
            $this->line('  - /etc/odbc.ini DSN [QUOTEWERKS_PROD] missing or malformed');
            $this->line('  - QUOTEWERKS_ODBC_DSN / USER / PASS in .env misconfigured');
            $this->line('  - CSF TCP_OUT does not allow 1433');
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('FAILED (unexpected): ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

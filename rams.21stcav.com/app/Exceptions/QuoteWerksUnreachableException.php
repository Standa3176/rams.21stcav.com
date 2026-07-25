<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when QuoteWerks direct import cannot reach the SQL Server (WireGuard
 * tunnel down, ODBC DSN misconfigured, credentials wrong, etc). Deliberately
 * extends RuntimeException — NOT a Laravel DB exception — so it carries no
 * SQL state metadata. Caller (QuoteWerksImportController) converts to a
 * user-safe flash message.
 *
 * Ported from service.21stcav.com (260723-qw1).
 */
class QuoteWerksUnreachableException extends RuntimeException
{
}

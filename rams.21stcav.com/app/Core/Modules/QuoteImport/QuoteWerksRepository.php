<?php

namespace App\Core\Modules\QuoteImport;

use Illuminate\Support\Facades\DB;

// Note: DB facade is retained for DB::connection() — DB::raw() has been removed from
// all query methods so that unit-test mocks do not need to stub raw().

/**
 * QuoteWerksRepository — read-only SQL Server queries against the QuoteWerks database.
 *
 * This is the ONLY class that knows the QuoteWerks connection name and column names.
 * All identifiers are bracket-quoted because QuoteWerks uses SQL reserved words
 * (Date, Name, Type, Group) as column names.
 *
 * Charset conversion: QuoteWerks may store strings in Windows-1252. The str() method
 * transparently converts to UTF-8 when needed.
 *
 * Column names are [ASSUMED] from standard QuoteWerks API conventions.
 * Run `php artisan quotewerks:schema` to verify against the live database.
 */
class QuoteWerksRepository
{
    public function __construct(
        private readonly string $connection = 'quotewerks',
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // Public query methods
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Find a quote header by its reference number (DocNo).
     *
     * @return array|null Mapped header array with internal key names, or null if not found.
     */
    public function findByReference(string $reference): ?array
    {
        $row = DB::connection($this->connection)
            ->table('DocumentHeaders')
            ->select([
                '[DocNo]', '[SoldToCompanyName]', '[SoldToAddress1]', '[SoldToAddress2]',
                '[SoldToCity]', '[SoldToState]', '[SoldToPostalCode]', '[DocDate]',
                '[Subject]', '[TotalSalePrice]',
            ])
            ->where('[DocNo]', $reference)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->mapHeader((array) $row);
    }

    /**
     * Get all line items for a given document number.
     *
     * @return array[] Array of mapped item arrays.
     */
    public function getItemsByDocNo(string $docNo): array
    {
        $rows = DB::connection($this->connection)
            ->table('DocumentItems')
            ->select([
                '[DocNo]', '[ItemType]', '[ManufacturerPartNumber]', '[Description]',
                '[Quantity]', '[UnitPrice]', '[TotalPrice]', '[GroupName]',
            ])
            ->where('[DocNo]', $docNo)
            ->get()
            ->toArray();

        return array_map(fn ($row) => $this->mapItem((array) $row), $rows);
    }

    /**
     * Search quote headers by client name with optional date filter.
     *
     * @return array[] Array of mapped header arrays (max 20 results).
     */
    public function searchByClient(string $clientName, ?string $dateFrom = null): array
    {
        $query = DB::connection($this->connection)
            ->table('DocumentHeaders')
            ->select([
                '[DocNo]', '[SoldToCompanyName]', '[SoldToAddress1]', '[SoldToCity]',
                '[DocDate]', '[Subject]', '[TotalSalePrice]',
            ])
            ->where('[SoldToCompanyName]', 'LIKE', "%{$clientName}%");

        if ($dateFrom !== null) {
            $query->where('[DocDate]', '>=', $dateFrom);
        }

        $rows = $query
            ->orderByDesc('[DocDate]')
            ->limit(20)
            ->get()
            ->toArray();

        return array_map(fn ($row) => $this->mapHeader((array) $row), $rows);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Mapping helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Map a QuoteWerks header row to internal key names.
     */
    private function mapHeader(array $row): array
    {
        return [
            'doc_no'       => $this->str($row['DocNo'] ?? ''),
            'client_name'  => $this->str($row['SoldToCompanyName'] ?? ''),
            'site_address' => trim(implode(', ', array_filter([
                $this->str($row['SoldToAddress1'] ?? $row['ShipToAddress1'] ?? ''),
                $this->str($row['SoldToAddress2'] ?? $row['ShipToAddress2'] ?? ''),
                $this->str($row['SoldToCity'] ?? $row['ShipToCity'] ?? ''),
                $this->str($row['SoldToState'] ?? $row['ShipToState'] ?? ''),
                $this->str($row['SoldToPostalCode'] ?? $row['ShipToPostalCode'] ?? ''),
            ]))),
            'doc_date'     => $row['DocDate'] ?? null,
            'subject'      => $this->str($row['Subject'] ?? ''),
            'total_price'  => (float) ($row['TotalSalePrice'] ?? 0),
            'sales_person' => $this->str($row['SalesPerson'] ?? ''),
            'notes'        => $this->str($row['Notes'] ?? ''),
        ];
    }

    /**
     * Map a QuoteWerks item row to internal key names.
     */
    private function mapItem(array $row): array
    {
        return [
            'doc_no'      => $this->str($row['DocNo'] ?? ''),
            'item_type'   => $this->str($row['ItemType'] ?? ''),
            'part_number' => $this->str($row['ManufacturerPartNumber'] ?? ''),
            'description' => $this->str($row['Description'] ?? ''),
            'quantity'    => (int) ($row['Quantity'] ?? 0),
            'unit_price'  => (float) ($row['UnitPrice'] ?? 0),
            'total_price' => (float) ($row['TotalPrice'] ?? 0),
            'group_name'  => $this->str($row['GroupName'] ?? ''),
            'sort_order'  => (int) ($row['SortOrder'] ?? 0),
        ];
    }

    /**
     * Convert a string from Windows-1252 to UTF-8 if needed, then trim.
     */
    public function str(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return trim($value);
        }

        return trim(mb_convert_encoding($value, 'UTF-8', 'Windows-1252'));
    }
}

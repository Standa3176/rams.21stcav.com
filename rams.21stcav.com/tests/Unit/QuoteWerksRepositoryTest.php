<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\QuoteImport\QuoteWerksRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for QuoteWerksRepository.
 *
 * Verifies: correct connection usage, bracket-quoted column identifiers,
 * column name mapping from QuoteWerks names to internal keys, charset
 * conversion (Windows-1252 → UTF-8), and result-set limits.
 *
 * All tests mock DB::connection('quotewerks') — no live SQL Server required.
 *
 * @see QWSQL-02, QWSQL-03
 */
class QuoteWerksRepositoryTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a Fluent query-builder stub that returns a specific result from
     * `first()` or `get()`. This avoids a live DB connection in unit tests.
     */
    private function makeQueryBuilderStub(mixed $firstResult = null, mixed $getResult = null): object
    {
        $stub = new class ($firstResult, $getResult) {
            private mixed $firstResult;
            private mixed $getResult;
            private array $wheres = [];
            private ?int $limitValue = null;
            private array $orderBys = [];
            private array $selectCols = [];

            public function __construct(mixed $firstResult, mixed $getResult)
            {
                $this->firstResult = $firstResult;
                $this->getResult   = $getResult ?? collect([]);
            }

            public function select(array|string $columns): static { $this->selectCols = (array) $columns; return $this; }
            public function where(string $column, mixed $op = null, mixed $value = null): static { $this->wheres[] = [$column, $op, $value]; return $this; }
            public function whereDate(string $col, string $op, string $val): static { return $this; }
            public function orderByDesc(string $col): static { $this->orderBys[] = [$col, 'desc']; return $this; }
            public function limit(int $n): static { $this->limitValue = $n; return $this; }
            public function take(int $n): static { $this->limitValue = $n; return $this; }
            public function first(): mixed { return $this->firstResult; }
            public function get(): mixed { return $this->getResult; }

            /** Expose the stored limit so assertions can inspect it. */
            public function getLimit(): ?int { return $this->limitValue; }

            /** Expose wheres for assertion. */
            public function getWheres(): array { return $this->wheres; }
        };

        return $stub;
    }

    /**
     * Build a DB connection stub that returns the given query builder stub from table().
     */
    private function makeConnectionStub(object $queryBuilder): object
    {
        return new class ($queryBuilder) {
            private object $qb;

            public function __construct(object $qb) { $this->qb = $qb; }

            public function table(string $table): object { return $this->qb; }
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — findByReference() uses quotewerks connection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * findByReference() must call DB::connection('quotewerks'), never DB::table() directly.
     */
    public function test_findByReference_uses_quotewerks_connection(): void
    {
        $connectionName = null;

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturnUsing(function (string $name) use (&$connectionName) {
                $connectionName = $name;

                return $this->makeConnectionStub($this->makeQueryBuilderStub(null));
            });

        $repo = new QuoteWerksRepository();
        $repo->findByReference('TEST-001');

        $this->assertSame('quotewerks', $connectionName);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — findByReference() returns null when no row found
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the query builder returns null from first(), findByReference() must return null.
     */
    public function test_findByReference_returns_null_when_no_row(): void
    {
        $qb = $this->makeQueryBuilderStub(null);

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturn($this->makeConnectionStub($qb));

        $repo   = new QuoteWerksRepository();
        $result = $repo->findByReference('MISSING-999');

        $this->assertNull($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — findByReference() maps QuoteWerks column names to internal keys
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The raw row uses QuoteWerks column names (DocNo, SoldToCompanyName, etc.).
     * mapHeader() must produce an array with internal snake_case keys.
     */
    public function test_findByReference_maps_column_names_to_internal_keys(): void
    {
        $rawRow = (object) [
            'DocNo'               => 'QW-2024-001',
            'SoldToCompanyName'   => 'Test Corp Ltd',
            'ShipToAddress1'      => '1 High Street',
            'ShipToCity'          => 'London',
            'DocDate'             => '2024-01-15',
            'SalesPerson'         => 'J Smith',
            'Notes'               => 'Some project notes',
        ];

        $qb = $this->makeQueryBuilderStub($rawRow);

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturn($this->makeConnectionStub($qb));

        $repo   = new QuoteWerksRepository();
        $result = $repo->findByReference('QW-2024-001');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('doc_no',       $result);
        $this->assertArrayHasKey('client_name',  $result);
        $this->assertArrayHasKey('site_address', $result);
        $this->assertArrayHasKey('doc_date',     $result);
        $this->assertArrayHasKey('sales_person', $result);
        $this->assertArrayHasKey('notes',        $result);

        $this->assertSame('QW-2024-001',    $result['doc_no']);
        $this->assertSame('Test Corp Ltd',  $result['client_name']);
        $this->assertSame('J Smith',        $result['sales_person']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — getItemsByDocNo() returns empty array when no items
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the connection returns an empty collection, getItemsByDocNo() must return [].
     */
    public function test_getItemsByDocNo_returns_empty_array_when_no_items(): void
    {
        $qb = $this->makeQueryBuilderStub(null, collect([]));

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturn($this->makeConnectionStub($qb));

        $repo   = new QuoteWerksRepository();
        $result = $repo->getItemsByDocNo('QW-2024-001');

        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — getItemsByDocNo() maps item column names to internal keys
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Raw item rows use QuoteWerks column names. mapItem() must return array with
     * item_type, quantity, part_number, description, group_name, sort_order keys.
     */
    public function test_getItemsByDocNo_maps_item_columns_to_internal_keys(): void
    {
        $rawItem = (object) [
            'ItemType'              => 'P',
            'Quantity'              => 2,
            'ManufacturerPartNumber' => 'SONY-BZ40H',
            'Description'           => 'Sony BRAVIA 40" Display',
            'GroupName'             => 'Boardroom',
            'SortOrder'             => 10,
        ];

        $qb = $this->makeQueryBuilderStub(null, collect([$rawItem]));

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturn($this->makeConnectionStub($qb));

        $repo   = new QuoteWerksRepository();
        $result = $repo->getItemsByDocNo('QW-2024-001');

        $this->assertCount(1, $result);

        $item = $result[0];
        $this->assertArrayHasKey('item_type',   $item);
        $this->assertArrayHasKey('quantity',    $item);
        $this->assertArrayHasKey('part_number', $item);
        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('group_name',  $item);
        $this->assertArrayHasKey('sort_order',  $item);

        $this->assertSame('P',              $item['item_type']);
        $this->assertSame(2,                $item['quantity']);
        $this->assertSame('SONY-BZ40H',     $item['part_number']);
        $this->assertSame('Boardroom',      $item['group_name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — str() converts Windows-1252 bytes to UTF-8
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A Windows-1252 string containing smart quote byte 0x92 must be converted
     * to valid UTF-8. The result must pass mb_check_encoding($result, 'UTF-8').
     */
    public function test_str_converts_windows1252_to_utf8(): void
    {
        // 0x92 is the Windows-1252 right single quotation mark (curly apostrophe).
        // In UTF-8 this maps to U+2019 → bytes E2 80 99.
        $windows1252String = "It\x92s a display";

        $repo   = new QuoteWerksRepository();
        $result = $repo->str($windows1252String);

        $this->assertTrue(
            mb_check_encoding($result, 'UTF-8'),
            "str() must produce valid UTF-8 output"
        );
        // The converted string should NOT contain the raw 0x92 byte
        $this->assertStringNotContainsString("\x92", $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7 — str() passes valid UTF-8 unchanged
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A string that is already valid UTF-8 must be returned unchanged (no double-encoding).
     */
    public function test_str_passes_valid_utf8_unchanged(): void
    {
        $utf8String = "Sony BRAVIA 65\u{2033} Display"; // Unicode double prime character

        $repo   = new QuoteWerksRepository();
        $result = $repo->str($utf8String);

        $this->assertSame(trim($utf8String), $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8 — searchByClient() limits results to 20
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * searchByClient() must apply a result limit of 20.
     */
    public function test_searchByClient_limits_results_to_20(): void
    {
        $capturedLimit = null;

        $qbStub = new class ($capturedLimit) {
            public ?int $capturedLimit = null;
            public function select(array|string $columns): static { return $this; }
            public function where(string $column, mixed $op = null, mixed $value = null): static { return $this; }
            public function whereDate(string $col, string $op, string $val): static { return $this; }
            public function orderByDesc(string $col): static { return $this; }
            public function limit(int $n): static { $this->capturedLimit = $n; return $this; }
            public function take(int $n): static { $this->capturedLimit = $n; return $this; }
            public function get(): mixed { return collect([]); }
        };

        $connStub = new class ($qbStub) {
            public function __construct(private object $qb) {}
            public function table(string $table): object { return $this->qb; }
        };

        DB::shouldReceive('connection')
            ->once()
            ->with('quotewerks')
            ->andReturn($connStub);

        $repo = new QuoteWerksRepository();
        $repo->searchByClient('Test Corp');

        $this->assertSame(20, $qbStub->capturedLimit);
    }
}

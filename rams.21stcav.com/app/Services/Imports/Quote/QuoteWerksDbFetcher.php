<?php

declare(strict_types=1);

namespace App\Services\Imports\Quote;

use App\Exceptions\QuoteWerksUnreachableException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * QuoteWerks ODBC data-access tier (260723-qw1).
 *
 * Ported verbatim from service.21stcav.com's Phase 55 Wave 1 (Plan 55-02)
 * fetcher — only the namespace differs. Issues two parameterised SELECT
 * statements against the 'quotewerks' ODBC connection block and maps the
 * resulting rows into a parser-shape array compatible with the RAMS-side
 * QuoteWerksImportService::buildExtractedData mapper.
 *
 * Schema correctness contracts pinned in source (verified against live QW):
 *   - 'DocumentHeaders' is the correct table name (SPEC drift claimed 'Documents').
 *   - 'Superceeded = 0' uses the QuoteWerks-native misspelling (sic).
 *   - 'DocType = QUOTE' is intentionally NOT filtered here — the controller
 *     needs to see the raw DocType so it can return an error for orders/invoices.
 *     The fetcher hands back whatever DocType the row carries.
 *   - 'LineType IN (1, 32, 256)' filters DocumentItems to products +
 *     top-level section headers + subsection headers only — everything else
 *     (subtotals, comments, discounts, charges) filtered at the SQL layer.
 *   - Header SELECT uses an OR-chain on '(DocNo = ? OR RevisionMasterDocNo = ?)'
 *     so a pasted DocNo can land on either the master or its live revision;
 *     'Superceeded = 0' guarantees the LIVE revision wins.
 *   - Line-item columns use corrected names per Wave 0 live-DB validation:
 *     QtyBase (not Quantity), ManufacturerPartNumber (fully spelled, NOT
 *     ManufacturerPartNo), CustomText01/02 (not Custom01/02),
 *     ShipToPostalCode (not ShipToZip).
 *   - Notes column maps to equipment[*]['description'] preferentially, with
 *     the long-form Description column as fallback (this install's swap;
 *     some operators leave Notes blank and put product text in Description).
 *   - All SQL parameters use '?' placeholders bound via Laravel's facade —
 *     $docNo is NEVER string-interpolated into the SQL.
 *
 * On any PDOException the exception is re-thrown as QuoteWerksUnreachableException
 * with a sanitized constant message and the original PDOException chained as
 * $previous. The controller catches this and renders a user-safe flash message.
 *
 * @see config/database.php 'quotewerks' connection block (Task 1)
 * @see app/Exceptions/QuoteWerksUnreachableException.php (Task 1)
 * @see app/Core/Modules/QuoteImport/QuoteWerksImportService.php downstream
 *      consumer of mapToParsedShape output.
 */
class QuoteWerksDbFetcher
{
    /**
     * Fetch a QuoteWerks document (header + items) by DocNo.
     *
     * Returns a 2-key array — pipe the output through {@see mapToParsedShape()}
     * to get the flat parser-shape array RAMS's importer consumes:
     *   - 'header' (?array): the matched DocumentHeaders row (associative) or null
     *   - 'items'  (array<int,array>): DocumentItems rows filtered to
     *     LineType IN (1, 32, 256), or empty array when header is null.
     *
     * @return array{header: ?array, items: array<int, array<string, mixed>>}
     * @throws QuoteWerksUnreachableException When the underlying PDOException
     *         fires at connect time (DSN missing/unreachable, MSSQL stopped,
     *         network partition, auth failure). Original PDOException is
     *         chained as the previous exception for log-level traceability.
     */
    public function fetch(string $docNo): array
    {
        try {
            $header = $this->fetchHeader($docNo);

            // No header → no items round-trip needed.
            if ($header === null) {
                return ['header' => null, 'items' => []];
            }

            // DocumentItems is keyed by DocID (the header's internal primary key),
            // NOT DocNo. Cast to int defensively in case the driver returns a
            // numeric-string scalar from the SELECT.
            $items = $this->fetchItems((int) ($header['ID'] ?? 0));

            return ['header' => $header, 'items' => $items];
        } catch (PDOException $e) {
            throw new QuoteWerksUnreachableException(
                'Cannot reach QuoteWerks right now.',
                0,
                $e
            );
        }
    }

    /**
     * Map raw fetcher output to a flat parser-shape array.
     *
     * Pure transformation — no I/O, safe to call without a configured ODBC
     * connection. Output keys mirror the SCC-side QuoteWerksPdfParser tag-based
     * parse path (client / site / site_name / ref / prepared_by / scope_narrative /
     * contact_name / contact_phone / contact_email / equipment[] / rooms[]).
     *
     * Each equipment row carries 'description' (from DocumentItems.Notes —
     * Description as fallback), 'part_number' (from ManufacturerPartNumber),
     * 'area' (from CustomText01, overridden by section-header when present),
     * 'location' (from CustomText02), 'qty' (int, defaults to 1 when QtyBase
     * is null/zero), 'unit_price' (float|null), 'manufacturer' (string|null).
     *
     * @param array<string, mixed> $header Matched DocumentHeaders row.
     * @param array<int, array<string, mixed>> $items DocumentItems rows
     *        filtered to LineType IN (1, 32, 256).
     * @return array<string, mixed>
     */
    public function mapToParsedShape(array $header, array $items): array
    {
        $client = trim((string) ($header['SoldToCompany'] ?? ''));

        // Comma-joined site address string. Downstream RAMS mapper stores
        // this in extracted_data.site_address and Project.site_address.
        $siteParts = array_filter([
            (string) ($header['ShipToAddress1'] ?? ''),
            (string) ($header['ShipToAddress2'] ?? ''),
            (string) ($header['ShipToCity'] ?? ''),
            (string) ($header['ShipToPostalCode'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '');
        $site = trim(implode(', ', array_map('trim', $siteParts)));

        // Wave 0 verified: ShipToCompany is the site-name source (confirmed
        // across 3 rows on live QW — Row 2 diverged from SoldToCompany,
        // proving ShipToCompany is the site, not the client).
        $siteName = trim((string) ($header['ShipToCompany'] ?? ''));

        // Wave 0 verified: PreparedBy is a real nvarchar column on
        // DocumentHeaders. Earlier research assumed a SalesRep fallback
        // was needed; that turned out to be unnecessary.
        $preparedBy = trim((string) ($header['PreparedBy'] ?? ''));

        // Scope-of-work narrative pulled from the header's CustomMemo01 column.
        // Real live example (21CQ29750) carries 4126 chars starting with
        // "System Connectivity & Functional Overview". Empty for quotes
        // where the operator hasn't set it (majority of QuoteWerks quotes).
        $scopeNarrative = trim((string) ($header['CustomMemo01'] ?? ''));

        // Introduction / closing notes are quote-wide flavour text. Verified
        // 2026-07-25 against 21CQ29531-05-OPS: IntroductionNotes = "21st
        // Century AV are pleased to provide…", ClosingNotes = "Please contact
        // me if I can be of further assistance." Downstream RAMS mapper
        // surfaces on extracted_data (nullable) for later template use.
        $introNotes   = trim((string) ($header['IntroductionNotes'] ?? ''));
        $closingNotes = trim((string) ($header['ClosingNotes'] ?? ''));

        // Walk items in fetch order (already ORDER BY ID) and thread a
        // "current section" through the loop. LineType=32 (top-level section
        // header) and LineType=256 (subsection header) with a non-empty
        // Description both update the current section. LineType=1 (product)
        // rows inherit that section as their `area` field. Section headers
        // themselves DO NOT surface as equipment rows.
        //
        // Descriptions carry lots of leading whitespace ("            Board
        // Room") because QuoteWerks operators indent them visually — trim
        // aggressively before storing.
        $equipment = [];
        $roomNames = [];
        // Per-room narrative from LineType 32/256 rows' CustomMemo01 column
        // (verified 2026-07-25 against 21CQ29531-05-OPS — Oregano/Cinnamon/
        // Saffron each carry the narrative paragraph on their section header
        // row). Keyed by room name (raw section-header text) — downstream
        // mapper zips this into extracted_data.room_overviews[*].overview.
        $roomDescriptions = [];
        $currentRoom = null;
        foreach ($items as $item) {
            // Default missing LineType to 1 (product) — real fetcher SQL
            // always returns LineType; only unit-test fixtures may omit it.
            $lineType = (int) ($item['LineType'] ?? 1);
            $descriptionRaw = trim((string) preg_replace('/\s+/', ' ', (string) ($item['Description'] ?? '')));

            if ($lineType === 32 || $lineType === 256) {
                if ($descriptionRaw !== '') {
                    $currentRoom = $descriptionRaw;
                    if (! in_array($currentRoom, $roomNames, true)) {
                        $roomNames[] = $currentRoom;
                    }
                    // Capture room narrative iff CustomMemo01 is populated.
                    // Whitespace-collapsed (Oregano's memo contains soft
                    // wrapping). Last-write-wins on duplicate section
                    // headers (not observed in practice).
                    $roomMemo = trim((string) preg_replace('/\s+/', ' ', (string) ($item['CustomMemo01'] ?? '')));
                    if ($roomMemo !== '') {
                        $roomDescriptions[$currentRoom] = $roomMemo;
                    }
                }
                continue;
            }

            if ($lineType === 1) {
                $equipmentRow = $this->mapEquipmentRow($item);
                if ($currentRoom !== null) {
                    // Section header wins over CustomText01 (which mapEquipmentRow
                    // wrote to 'area'). Downstream consumers read 'area' as the
                    // room name — overriding here is what actually surfaces the
                    // QuoteWerks operator-set room grouping on the review UI.
                    $equipmentRow['area'] = $currentRoom;
                }
                $equipment[] = $equipmentRow;
            }
        }

        // Plain string array. Downstream RAMS mapper stores as extracted_data.rooms
        // for the review UI to render room chips.
        $rooms = array_values($roomNames);

        return [
            'client'            => $client !== '' ? $client : null,
            'site'              => $site !== '' ? $site : null,
            'site_name'         => $siteName !== '' ? $siteName : null,
            'ref'               => (string) ($header['DocNo'] ?? ''),
            'prepared_by'       => $preparedBy !== '' ? $preparedBy : null,
            // Null when empty so downstream mapper can distinguish "no scope
            // text set" from "empty string set explicitly".
            'scope_narrative'   => $scopeNarrative !== '' ? $scopeNarrative : null,
            'intro_notes'       => $introNotes   !== '' ? $introNotes   : null,
            'closing_notes'     => $closingNotes !== '' ? $closingNotes : null,
            // QuoteWerks DocumentHeaders does NOT carry structured site contact
            // fields (would require a second SELECT against a Contacts table —
            // deferred out-of-scope). Empty for MVP; downstream handles null
            // contact tuples cleanly.
            'contact_name'      => null,
            'contact_phone'     => null,
            'contact_email'     => null,
            'equipment'         => $equipment,
            // Rooms[] carries operator-set section headings (LineType 32 + 256
            // Descriptions). Empty when the quote has no section headers.
            'rooms'             => $rooms,
            // Room name → narrative paragraph (from section-header row's
            // CustomMemo01). Sparse — only rooms whose header row has a
            // populated memo. Downstream mapper zips into room_overviews[].
            'room_descriptions' => $roomDescriptions,
        ];
    }

    /**
     * Search DocumentHeaders by SoldToCompany, returning the 20 most recent
     * live-revision matches for the client-name search UI.
     *
     * Uses the exact same 'Superceeded = 0' guard as fetchHeader() so search
     * results only show the current revision of each quote — never a superseded
     * ghost. Ordered by DocDate DESC so most recent quotes appear first.
     *
     * @return array<int, array<string, mixed>> Normalized UTF-8 rows.
     * @throws QuoteWerksUnreachableException When the ODBC round-trip fails.
     */
    public function searchByClient(string $clientName, ?string $dateFrom = null): array
    {
        try {
            $sql = 'SELECT TOP 20 ID, DocNo, DocDate, SoldToCompany, ShipToCompany, '
                . 'GrandTotal, PreparedBy, CustomMemo01 '
                . 'FROM DocumentHeaders '
                . 'WHERE SoldToCompany LIKE ? AND Superceeded = 0';
            $bindings = ['%' . $clientName . '%'];

            if ($dateFrom !== null && $dateFrom !== '') {
                $sql .= ' AND DocDate >= ?';
                $bindings[] = $dateFrom;
            }

            $sql .= ' ORDER BY DocDate DESC';

            $rows = DB::connection('quotewerks')->select($sql, $bindings);

            return array_map(
                fn ($row): array => $this->normalizeUtf8Row((array) $row),
                $rows
            );
        } catch (PDOException $e) {
            throw new QuoteWerksUnreachableException(
                'Cannot reach QuoteWerks right now.',
                0,
                $e
            );
        }
    }

    /**
     * Fetch a single DocumentHeaders row, preferring the live (non-superseded)
     * revision via Superceeded = 0 + OR-chain on RevisionMasterDocNo.
     *
     * IMPORTANT: this SELECT deliberately does NOT include a DocType = 'QUOTE'
     * filter — the controller needs to see the actual DocType so it can bounce
     * orders/invoices. The fetcher hands back whatever DocType the row carries;
     * the controller decides the error response.
     *
     * Column names verified against live QW (2026-07-01):
     *   - ShipToPostalCode (not ShipToZip)
     *   - RevisionMasterDocNo (nvarchar — confirmed with value "21CQ14383"
     *     that starts with letters)
     *   - PreparedBy (real nvarchar column — no SalesRep fallback needed)
     *   - CustomMemo01 (scope-of-work narrative surface)
     *
     * @return ?array<string, mixed> Header row as associative array, or null
     *         when no live revision matches the DocNo.
     */
    private function fetchHeader(string $docNo): ?array
    {
        $sql = 'SELECT TOP 1 ID, DocNo, DocType, DocDate, RevisionMasterDocNo, Superceeded, '
            . 'SoldToCompany, ShipToCompany, ShipToContact, ShipToAddress1, ShipToAddress2, '
            . 'ShipToCity, ShipToPostalCode, Subtotal, GrandTotal, PreparedBy, CustomMemo01, '
            . 'IntroductionNotes, ClosingNotes '
            . 'FROM DocumentHeaders '
            . 'WHERE (DocNo = ? OR RevisionMasterDocNo = ?) AND Superceeded = 0 '
            . 'ORDER BY DocDate DESC';

        $row = DB::connection('quotewerks')->selectOne($sql, [$docNo, $docNo]);

        if ($row === null) {
            return null;
        }

        // Laravel's selectOne returns a stdClass — cast to associative array
        // so callers can use array-key access throughout. Normalize all
        // string values to UTF-8 (see normalizeUtf8Row).
        return $this->normalizeUtf8Row((array) $row);
    }

    /**
     * Fetch DocumentItems rows for a given DocID, INCLUDING section-header
     * rows so the mapper can group products under their operator-set headings.
     *
     * QuoteWerks LineType bitmask (confirmed against live QW 2026-07-01):
     *   - 1   = ProductService (real line item)
     *   - 2   = Comment
     *   - 4   = ?
     *   - 8   = Total
     *   - 16  = SubTotal (running subtotal — visual only, skipped)
     *   - 32  = Section Header (top-level — Description carries the heading text)
     *   - 64  = Discount
     *   - 128 = Charge
     *   - 256 = Subsection Header (nested — Description carries the heading text)
     *
     * Only LineType=1 (products) and LineType IN (32, 256) (headings) are
     * material to the parser-shape payload. Everything else is filtered at
     * SQL so the mapper only walks the meaningful rows. Filtering at SQL
     * saves memory + roundtrip on quotes with 1000+ rows.
     *
     * Column names verified against live QW (2026-07-01):
     *   - QtyBase (not Quantity)
     *   - ManufacturerPartNumber (Wave 0 confirmed real name — earlier
     *     research assumed ManufacturerPartNo which does NOT exist)
     *   - CustomText01 / CustomText02 (not Custom01 / Custom02)
     *
     * Wave 0 verified: neither LineNumber nor LineSequence exists on
     * DocumentItems. The table is a heap with a nonclustered PK on ID.
     * ORDER BY ID gives stable insertion-order sort — the operator-entered
     * row sequence — which is critical for the header-→-product grouping walk.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchItems(int $docID): array
    {
        $sql = 'SELECT ID, LineType, QtyBase, ManufacturerPartNumber, Notes, Description, '
            . 'CustomText01, CustomText02, CustomMemo01, UnitPrice, Manufacturer '
            . 'FROM DocumentItems '
            . 'WHERE DocID = ? AND LineType IN (1, 32, 256) '
            . 'ORDER BY ID';

        $rows = DB::connection('quotewerks')->select($sql, [$docID]);

        // Cast each stdClass row to associative array for downstream
        // mapToParsedShape consumption + normalize all string values to
        // UTF-8 (see normalizeUtf8Row).
        return array_map(
            fn ($row): array => $this->normalizeUtf8Row((array) $row),
            $rows
        );
    }

    /**
     * Normalize every string value in an associative row to valid UTF-8.
     *
     * QuoteWerks stores text in SQL Server nvarchar columns which are UTF-16
     * on the server, but Windows-native drivers and PDO_ODBC on Windows
     * sometimes return the bytes in the client OEM code page (usually
     * Windows-1252 on UK/US systems). Characters like ® ™ curly-quotes,
     * en/em dashes, or accented letters land as raw bytes that json_encode
     * rejects with "Malformed UTF-8 characters, possibly incorrectly encoded".
     *
     * Live example: 21CQ29531-05-OPS carries "Crestron AirMedia® Series 3 Kit"
     * and "Crestron 4-Series™ Control System" — both fail the JSON round-trip
     * before this normalization.
     *
     * Strategy: mb_check_encoding first (no-op fast path when the driver
     * already returned clean UTF-8); mb_convert_encoding from Windows-1252
     * when it didn't (turns the raw bytes into their UTF-8 equivalents).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeUtf8Row(array $row): array
    {
        foreach ($row as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            $row[$key] = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        return $row;
    }

    /**
     * Transform a single DocumentItems row into the parser-shape equipment row.
     *
     * Cardinal contracts pinned here:
     *   - 'description' is sourced from Notes preferentially; long-form
     *     Description is used as fallback when Notes is empty (some operators
     *     put product text in Description instead of Notes).
     *   - 'area' from CustomText01, 'location' from CustomText02.
     *   - 'qty' defaults to 1 when QtyBase is null OR zero (defensive default).
     *   - 'manufacturer' is null when empty/missing (only-when-blank rule
     *     downstream means operator-provided value wins over AI fill).
     *
     * @param array<string, mixed> $item DocumentItems row.
     * @return array<string, mixed>
     */
    private function mapEquipmentRow(array $item): array
    {
        // Notes can carry newlines, tabs, multi-space runs (operator-pasted
        // from Word, etc.). Collapse to single spaces so downstream rendering
        // doesn't surface raw whitespace artefacts.
        //
        // Some operators leave Notes blank and put the product text in the
        // Description column instead. Prefer Notes when populated, fall back
        // to Description when Notes is empty. Both trimmed + whitespace-
        // collapsed identically.
        $notesRaw = trim((string) preg_replace('/\s+/', ' ', (string) ($item['Notes'] ?? '')));
        if ($notesRaw !== '') {
            $description = $notesRaw;
        } else {
            $description = trim((string) preg_replace('/\s+/', ' ', (string) ($item['Description'] ?? '')));
        }

        $partNumber = trim((string) ($item['ManufacturerPartNumber'] ?? ''));
        $area = trim((string) ($item['CustomText01'] ?? ''));
        $location = trim((string) ($item['CustomText02'] ?? ''));

        $qtyRaw = $item['QtyBase'] ?? null;
        $qty = max(1, (int) $qtyRaw);

        $unitPriceRaw = $item['UnitPrice'] ?? null;
        $unitPrice = is_numeric($unitPriceRaw) ? (float) $unitPriceRaw : null;

        $manufacturerRaw = $item['Manufacturer'] ?? null;
        $manufacturer = is_string($manufacturerRaw) && trim($manufacturerRaw) !== ''
            ? trim($manufacturerRaw)
            : null;

        return [
            'description'  => $description,
            'part_number'  => $partNumber,
            'area'         => $area,
            'location'     => $location,
            'qty'          => $qty,
            'unit_price'   => $unitPrice,
            'manufacturer' => $manufacturer,
        ];
    }
}

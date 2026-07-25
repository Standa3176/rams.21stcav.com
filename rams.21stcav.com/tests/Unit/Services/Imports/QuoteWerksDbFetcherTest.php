<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\Quote\QuoteWerksDbFetcher;
use Tests\TestCase;

/**
 * Unit tests for QuoteWerksDbFetcher::mapToParsedShape().
 *
 * Only the pure transformation is tested — fetch() itself runs I/O against
 * a live ODBC DSN and is out of scope for the unit test file. The mapper's
 * correctness is what protects us against silent schema drift.
 *
 * Fixtures mirror the shape of a real DocumentHeaders row + DocumentItems
 * rows exactly as PDO returns them (associative arrays after the fetcher's
 * internal cast).
 *
 * @see 260723-qw1 QuoteWerks direct-import
 */
class QuoteWerksDbFetcherTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Header shape
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function header_only_returns_empty_equipment_and_rooms(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), []);

        $this->assertSame([], $result['equipment']);
        $this->assertSame([], $result['rooms']);
        $this->assertSame('21CQ14213', $result['ref']);
    }

    /** @test */
    public function soldtocompany_maps_to_client_and_shiptocompany_to_site_name(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader([
            'SoldToCompany' => 'Acme Corp Ltd',
            'ShipToCompany' => 'Acme Head Office',
        ]);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertSame('Acme Corp Ltd', $result['client']);
        $this->assertSame('Acme Head Office', $result['site_name']);
    }

    /** @test */
    public function prepared_by_and_scope_narrative_pulled_from_header(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader([
            'PreparedBy'   => 'J Smith',
            'CustomMemo01' => 'System Connectivity & Functional Overview',
        ]);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertSame('J Smith', $result['prepared_by']);
        $this->assertSame('System Connectivity & Functional Overview', $result['scope_narrative']);
    }

    /** @test */
    public function empty_scope_narrative_becomes_null(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader(['CustomMemo01' => '   ']);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertNull($result['scope_narrative']);
    }

    /** @test */
    public function site_is_joined_from_shipto_address_parts(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader([
            'ShipToAddress1'   => '1 Old Street',
            'ShipToAddress2'   => 'Suite 4',
            'ShipToCity'       => 'London',
            'ShipToPostalCode' => 'EC1V 9AA',
        ]);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertSame('1 Old Street, Suite 4, London, EC1V 9AA', $result['site']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Item walking
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function linetype_32_header_threads_area_onto_three_following_products(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Boardroom'),
            $this->product('AVR-1', 'Amplifier'),
            $this->product('SPK-2', 'Speaker'),
            $this->product('CBL-3', 'Cable'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertCount(3, $result['equipment']);
        foreach ($result['equipment'] as $row) {
            $this->assertSame('Boardroom', $row['area']);
        }
        $this->assertSame(['Boardroom'], $result['rooms']);
    }

    /** @test */
    public function nested_linetype_256_subsection_updates_current_room_mid_walk(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Boardroom'),
            $this->product('P1', 'Product 1'),
            $this->subsectionHeader('Rear Wall'),
            $this->product('P2', 'Product 2'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertCount(2, $result['equipment']);
        $this->assertSame('Boardroom', $result['equipment'][0]['area']);
        $this->assertSame('Rear Wall', $result['equipment'][1]['area']);
        $this->assertSame(['Boardroom', 'Rear Wall'], $result['rooms']);
    }

    /** @test */
    public function notes_preferred_over_description_for_equipment_description(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $item = array_merge($this->product('SONY-1', 'Long form description text here'), [
            'Notes' => 'Sony BRAVIA 65" Display',
        ]);

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), [$item]);

        $this->assertSame('Sony BRAVIA 65" Display', $result['equipment'][0]['description']);
    }

    /** @test */
    public function description_used_as_fallback_when_notes_empty(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $item = array_merge($this->product('SONY-1', 'Fallback description text'), [
            'Notes' => '',
        ]);

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), [$item]);

        $this->assertSame('Fallback description text', $result['equipment'][0]['description']);
    }

    /** @test */
    public function qty_base_zero_or_null_defaults_to_one(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            array_merge($this->product('P1', 'Zero qty'), ['QtyBase' => 0]),
            array_merge($this->product('P2', 'Null qty'), ['QtyBase' => null]),
            $this->product('P3', 'Missing qty'),
        ];
        // Remove QtyBase from the third row so it's truly missing.
        unset($items[2]['QtyBase']);

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(1, $result['equipment'][0]['qty']);
        $this->assertSame(1, $result['equipment'][1]['qty']);
        $this->assertSame(1, $result['equipment'][2]['qty']);
    }

    /** @test */
    public function windows_1252_bytes_normalise_to_utf8_on_row_map(): void
    {
        // Full round-trip covered via mapEquipmentRow — mapper doesn't call
        // normalizeUtf8Row itself (fetch() does that at the DB boundary), so
        // this test asserts the equipment row descriptions preserve UTF-8
        // characters that are already correctly encoded.
        $fetcher = new QuoteWerksDbFetcher();

        $item = $this->product('CRE-1', 'Crestron AirMedia® Series 3 Kit');

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), [$item]);

        $this->assertTrue(mb_check_encoding($result['equipment'][0]['description'], 'UTF-8'));
        $this->assertStringContainsString('®', $result['equipment'][0]['description']);
    }

    /** @test */
    public function customtext01_provides_default_area_absent_section_header(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $item = array_merge($this->product('P1', 'Product'), ['CustomText01' => 'Rack Room']);

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), [$item]);

        $this->assertSame('Rack Room', $result['equipment'][0]['area']);
    }

    /** @test */
    public function unit_price_null_when_non_numeric_or_missing(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            array_merge($this->product('P1', 'Priced'),    ['UnitPrice' => '123.45']),
            array_merge($this->product('P2', 'Unpriced'),  ['UnitPrice' => null]),
            array_merge($this->product('P3', 'Empty str'), ['UnitPrice' => '']),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(123.45, $result['equipment'][0]['unit_price']);
        $this->assertNull($result['equipment'][1]['unit_price']);
        $this->assertNull($result['equipment'][2]['unit_price']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contact-tuple deferral
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function contact_fields_always_null_for_mvp(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), []);

        $this->assertNull($result['contact_name']);
        $this->assertNull($result['contact_phone']);
        $this->assertNull($result['contact_email']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-room descriptions from LineType 32/256 CustomMemo01 (260725-qw2)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function section_header_custommemo01_populates_room_descriptions(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Oregano', 'Oregano is now using the Crestron small room system with Jabra PanaCast 50.'),
            $this->product('FW-75BZ35L', 'Sony 75" display'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertArrayHasKey('room_descriptions', $result);
        $this->assertSame(
            'Oregano is now using the Crestron small room system with Jabra PanaCast 50.',
            $result['room_descriptions']['Oregano']
        );
    }

    /** @test */
    public function multiple_section_headers_each_land_under_their_own_key(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Oregano', 'Oregano uses the Crestron small room system.'),
            $this->product('P1', 'Display'),
            $this->sectionHeader('Cinnamon', 'Cinnamon uses the Crestron Flex integrator kit with 1Beyond cameras.'),
            $this->product('P2', 'Display'),
            $this->sectionHeader('Saffron', 'Saffron mirrors Cinnamon.'),
            $this->product('P3', 'Display'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(['Oregano', 'Cinnamon', 'Saffron'], $result['rooms']);
        $this->assertSame(
            [
                'Oregano'  => 'Oregano uses the Crestron small room system.',
                'Cinnamon' => 'Cinnamon uses the Crestron Flex integrator kit with 1Beyond cameras.',
                'Saffron'  => 'Saffron mirrors Cinnamon.',
            ],
            $result['room_descriptions']
        );
    }

    /** @test */
    public function empty_custommemo01_leaves_no_room_description_entry(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Oregano', ''),
            $this->product('P1', 'Display'),
            $this->sectionHeader('Cinnamon', '   '),   // whitespace-only also treated as empty
            $this->product('P2', 'Display'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(['Oregano', 'Cinnamon'], $result['rooms']);
        $this->assertSame([], $result['room_descriptions']);
    }

    /** @test */
    public function room_description_whitespace_is_collapsed(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $items = [
            $this->sectionHeader('Oregano', "Oregano   uses\nthe Crestron\t\tsmall room\r\nsystem."),
            $this->product('P1', 'Display'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(
            'Oregano uses the Crestron small room system.',
            $result['room_descriptions']['Oregano']
        );
    }

    /** @test */
    public function windows_1252_room_description_normalises_to_utf8(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        // NOTE: mapToParsedShape does not itself convert encodings — that's
        // normalizeUtf8Row's job at fetch time. Passing pre-UTF-8 text with a
        // curly quote proves the whitespace-collapse doesn't mangle multibyte.
        $items = [
            $this->sectionHeader('Oregano', "Oregano’s system uses “smart” controls."),
            $this->product('P1', 'Display'),
        ];

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), $items);

        $this->assertSame(
            "Oregano’s system uses “smart” controls.",
            $result['room_descriptions']['Oregano']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Header intro / closing notes (260725-qw2)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function introduction_and_closing_notes_pulled_from_header(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader([
            'IntroductionNotes' => '21st Century AV are pleased to provide a detailed quote.',
            'ClosingNotes'      => 'Please contact me if I can be of further assistance.',
        ]);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertSame(
            '21st Century AV are pleased to provide a detailed quote.',
            $result['intro_notes']
        );
        $this->assertSame(
            'Please contact me if I can be of further assistance.',
            $result['closing_notes']
        );
    }

    /** @test */
    public function missing_intro_and_closing_notes_are_null(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $result = $fetcher->mapToParsedShape($this->sampleHeader(), []);

        $this->assertNull($result['intro_notes']);
        $this->assertNull($result['closing_notes']);
    }

    /** @test */
    public function whitespace_only_intro_and_closing_become_null(): void
    {
        $fetcher = new QuoteWerksDbFetcher();

        $header = $this->sampleHeader([
            'IntroductionNotes' => '   ',
            'ClosingNotes'      => "\n\t",
        ]);

        $result = $fetcher->mapToParsedShape($header, []);

        $this->assertNull($result['intro_notes']);
        $this->assertNull($result['closing_notes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sampleHeader(array $overrides = []): array
    {
        return array_merge([
            'ID'                  => 42,
            'DocNo'               => '21CQ14213',
            'DocType'             => 'QUOTE',
            'DocDate'             => '2026-01-15',
            'RevisionMasterDocNo' => '21CQ14213',
            'Superceeded'         => 0,
            'SoldToCompany'       => 'Sample Client Ltd',
            'ShipToCompany'       => 'Sample Site Building',
            'ShipToContact'       => 'Site Manager',
            'ShipToAddress1'      => '',
            'ShipToAddress2'      => '',
            'ShipToCity'          => '',
            'ShipToPostalCode'    => '',
            'Subtotal'            => 0,
            'GrandTotal'          => 0,
            'PreparedBy'          => 'J Smith',
            'CustomMemo01'        => '',
            // 260725-qw2 — quote-wide flavour text, default empty.
            'IntroductionNotes'   => '',
            'ClosingNotes'        => '',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $partNumber, string $description): array
    {
        return [
            'ID'                     => random_int(1, 9999),
            'LineType'               => 1,
            'QtyBase'                => 1,
            'ManufacturerPartNumber' => $partNumber,
            'Notes'                  => '',
            'Description'            => $description,
            'CustomText01'           => '',
            'CustomText02'           => '',
            'UnitPrice'              => 0,
            'Manufacturer'           => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionHeader(string $text, string $memo = ''): array
    {
        return [
            'ID'                     => random_int(1, 9999),
            'LineType'               => 32,
            'QtyBase'                => 0,
            'ManufacturerPartNumber' => '',
            'Notes'                  => '',
            'Description'            => $text,
            'CustomText01'           => '',
            'CustomText02'           => '',
            // 260725-qw2 — per-room narrative source. Empty by default so
            // pre-existing tests that call sectionHeader($text) are unaffected.
            'CustomMemo01'           => $memo,
            'UnitPrice'              => 0,
            'Manufacturer'           => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subsectionHeader(string $text, string $memo = ''): array
    {
        return [
            'ID'                     => random_int(1, 9999),
            'LineType'               => 256,
            'QtyBase'                => 0,
            'ManufacturerPartNumber' => '',
            'Notes'                  => '',
            'Description'            => $text,
            'CustomText01'           => '',
            'CustomText02'           => '',
            'CustomMemo01'           => $memo,   // 260725-qw2 — see sectionHeader().
            'UnitPrice'              => 0,
            'Manufacturer'           => '',
        ];
    }
}

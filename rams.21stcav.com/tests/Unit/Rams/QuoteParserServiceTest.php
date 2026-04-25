<?php

namespace Tests\Unit\Rams;

use App\Services\QuoteParserService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuoteParserService.
 *
 * No Laravel bootstrapping required — the parser is pure PHP with no
 * dependencies on the container, database, or HTTP.
 */
class QuoteParserServiceTest extends TestCase
{
    private QuoteParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new QuoteParserService();
    }

    // =========================================================================
    // OUTPUT SHAPE
    // =========================================================================

    public function test_parse_returns_all_six_required_keys(): void
    {
        $result = $this->parser->parse('');

        $this->assertArrayHasKey('client',    $result);
        $this->assertArrayHasKey('site',      $result);
        $this->assertArrayHasKey('ref',       $result);
        $this->assertArrayHasKey('equipment', $result);
        $this->assertArrayHasKey('tasks',     $result);
        $this->assertArrayHasKey('rooms',     $result);
    }

    public function test_parse_returns_strings_for_scalar_fields_and_arrays_for_list_fields(): void
    {
        $result = $this->parser->parse('');

        $this->assertIsString($result['client']);
        $this->assertIsString($result['site']);
        $this->assertIsString($result['ref']);
        $this->assertIsArray($result['equipment']);
        $this->assertIsArray($result['tasks']);
        $this->assertIsArray($result['rooms']);
    }

    // =========================================================================
    // CLIENT EXTRACTION — EXISTING LABELLED PATTERNS
    // =========================================================================

    public function test_extracts_client_from_labelled_pattern(): void
    {
        $text = "Quote Date: 01/01/2024\nClient: Acme Ltd\nSite: Some Road";

        $result = $this->parser->parse($text);

        $this->assertSame('Acme Ltd', $result['client']);
    }

    public function test_extracts_client_from_company_suffix_in_header(): void
    {
        // Company name without a label — detected via Ltd / Limited suffix
        $text = implode("\n", [
            '21st Century AV Limited',
            'Quote No: Q12345',
            'Date: 01/01/2024',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['client']);
        $this->assertStringContainsStringIgnoringCase('21st Century AV Limited', $result['client']);
    }

    public function test_extracts_client_using_sold_to_label(): void
    {
        $text = "Sold To: Bright Horizons School\nRef: Q99999";

        $result = $this->parser->parse($text);

        $this->assertSame('Bright Horizons School', $result['client']);
    }

    public function test_client_returns_empty_string_when_not_detected(): void
    {
        $text = "Random line one\nAnother line two\nYet another three";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    // =========================================================================
    // CLIENT EXTRACTION — CONTACT NAME FALLBACK
    // =========================================================================

    public function test_falls_back_to_contact_name_when_client_missing(): void
    {
        // No "Client:", "Sold To:", or company suffix — should use "Contact:"
        $text = "Quote No: Q12345\nContact: Sarah Johnson\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('Sarah Johnson', $result['client']);
    }

    public function test_falls_back_to_for_the_attention_of_pattern(): void
    {
        $text = "Quote No: Q12345\nFor the attention of Robert Blackwell\nInstall display";

        $result = $this->parser->parse($text);

        $this->assertSame('Robert Blackwell', $result['client']);
    }

    public function test_contact_name_all_caps_is_rejected(): void
    {
        // ALL CAPS strings are headings/labels, not personal names
        $text = "Contact: JOHN SMITH\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_contact_name_containing_noise_word_invoice_is_rejected(): void
    {
        // "Invoice" is a noise word — extractContactName must reject it.
        // "Invoice Total" has no company suffix (no ltd/limited/etc.),
        // so the company-suffix heuristic also does not fire.
        $text = "Contact: Invoice Total\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_contact_name_containing_noise_word_vat_is_rejected(): void
    {
        $text = "Contact: VAT Number\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_contact_name_single_word_is_rejected(): void
    {
        // A single word is not a plausible personal name (need 2–4 words)
        $text = "Contact: Smithson\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    // =========================================================================
    // CLIENT EXTRACTION — EMAIL DOMAIN FALLBACK
    // =========================================================================

    public function test_falls_back_to_email_domain_when_no_client_or_contact(): void
    {
        $text = "Quote No: Q12345\njohn.smith@acmecorp.co.uk\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertNotSame('', $result['client']);
        $this->assertStringContainsStringIgnoringCase('acmecorp', $result['client']);
    }

    public function test_short_domain_company_name_is_uppercased(): void
    {
        // Domain "bbc.co.uk" → company "bbc" (≤5 chars) → "BBC"
        $text = "Quote No: Q12345\nnews@bbc.co.uk\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('BBC', $result['client']);
    }

    public function test_does_not_use_gmail_domain(): void
    {
        $text = "Quote No: Q12345\njohn@gmail.com\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_does_not_use_yahoo_domain(): void
    {
        $text = "Quote No: Q12345\njohn@yahoo.co.uk\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_does_not_use_outlook_domain(): void
    {
        $text = "Quote No: Q12345\njohn@outlook.com\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_does_not_use_hotmail_domain(): void
    {
        $text = "Quote No: Q12345\njohn@hotmail.com\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['client']);
    }

    public function test_email_subdomain_prefix_is_skipped(): void
    {
        // "mail.btgroup.com" → skip "mail" (subdomain prefix) → company = "btgroup"
        $text = "Quote No: Q12345\norders@mail.btgroup.com\nInstall display in office";

        $result = $this->parser->parse($text);

        $this->assertStringContainsStringIgnoringCase('btgroup', $result['client']);
    }

    // =========================================================================
    // SITE EXTRACTION — EXISTING LABELLED PATTERNS
    // =========================================================================

    public function test_extracts_site_from_ship_to_label(): void
    {
        $text = "Ship To: Manchester Business Park, Manchester, M1 1AA";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringContainsString('Manchester', $result['site']);
    }

    public function test_extracts_site_from_delivery_address_label(): void
    {
        $text = "Delivery Address: 45 High Street, London";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringContainsString('High Street', $result['site']);
    }

    public function test_extracts_site_from_uk_postcode_block(): void
    {
        $text = implode("\n", [
            'Client: Some Corp Ltd',
            'Academy Road',
            'Birmingham',
            'B15 2TT',
            'Quote No: Q11111',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringContainsString('Birmingham', $result['site']);
    }

    public function test_site_returns_empty_string_when_not_detected(): void
    {
        $text = "Random line one\nAnother line two\nYet another three";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['site']);
    }

    // =========================================================================
    // SITE EXTRACTION — UK ADDRESS BLOCK FALLBACK
    // =========================================================================

    public function test_extracts_uk_address_block_when_no_labelled_site(): void
    {
        $text = implode("\n", [
            'Quote No: Q12345',
            '10 Technology Park',
            'Coventry',
            'CV1 2WT',
            'Install display screens',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringContainsString('Coventry', $result['site']);
    }

    public function test_address_block_strips_tel_lines(): void
    {
        $text = implode("\n", [
            '55 Park Lane',
            'Manchester',
            'Tel: 0161 123 4567',
            'M1 3AB',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringNotContainsStringIgnoringCase('Tel', $result['site']);
        $this->assertStringContainsString('Manchester', $result['site']);
    }

    public function test_address_block_strips_email_lines(): void
    {
        $text = implode("\n", [
            '12 Business Road',
            'Bristol',
            'Email: info@company.co.uk',
            'BS1 4QR',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['site']);
        $this->assertStringNotContainsStringIgnoringCase('Email', $result['site']);
        $this->assertStringContainsString('Bristol', $result['site']);
    }

    public function test_address_block_minimum_length_enforced(): void
    {
        // A bare postcode with nothing around it must not be returned
        $text = "AB1 2CD";

        $result = $this->parser->parse($text);

        $this->assertSame('', $result['site']);
    }

    public function test_address_block_requires_alphabetic_content(): void
    {
        // A block that only contains numbers and a postcode is not a valid address
        $text = "12345\n67890\nAB1 2CD";

        $result = $this->parser->parse($text);

        // toLines() strips pure-numeric lines; the remaining block "AB1 2CD"
        // alone is < 10 chars and must be rejected.
        $this->assertSame('', $result['site']);
    }

    // =========================================================================
    // REF EXTRACTION
    // =========================================================================

    public function test_extracts_ref_in_21c_format(): void
    {
        $text = "Project Ref: 21CQ28863\nSome other line";

        $result = $this->parser->parse($text);

        $this->assertSame('21CQ28863', $result['ref']);
    }

    public function test_extracts_bare_21cq_ref_without_label(): void
    {
        // All 21st Century AV quote numbers begin "21CQ"; a bare reference
        // anywhere in the text must be detected without a label prefix.
        $text = "Some header\n21CQ28863\nAnother line";

        $result = $this->parser->parse($text);

        $this->assertSame('21CQ28863', $result['ref']);
    }

    public function test_generic_q_number_without_label_is_not_extracted(): void
    {
        // A bare "Q12345" no longer matches — only labelled refs or 21CQ format
        // are recognised. Without a label or 21CQ prefix, RAMS-001 is returned.
        $text = "Some header\nQ12345\nAnother line";

        $result = $this->parser->parse($text);

        $this->assertSame('RAMS-001', $result['ref']);
    }

    public function test_extracts_ref_from_quote_no_label(): void
    {
        $text = "Quote No: ABC-456\nDate: 01/01/2024";

        $result = $this->parser->parse($text);

        $this->assertSame('ABC-456', $result['ref']);
    }

    public function test_extracts_ref_from_order_no_label(): void
    {
        $text = "Order No: PO-9876\nDate: 01/01/2024";

        $result = $this->parser->parse($text);

        $this->assertSame('PO-9876', $result['ref']);
    }

    public function test_ref_returns_rams_001_when_no_ref_found(): void
    {
        $text = "Client: Some Corp\nSite: Some Place\nNo reference number here";

        $result = $this->parser->parse($text);

        $this->assertSame('RAMS-001', $result['ref']);
    }

    // =========================================================================
    // EQUIPMENT EXTRACTION
    // =========================================================================

    public function test_extracts_equipment_line_containing_av_keyword(): void
    {
        $text = "2x Samsung 75\" Display\nSome other line";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $equipment = $result['equipment'][0];
        $this->assertArrayHasKey('qty',         $equipment);
        $this->assertArrayHasKey('description', $equipment);
        $this->assertArrayHasKey('location',    $equipment);
    }

    public function test_parses_quantity_with_x_separator(): void
    {
        $text = "2x Sony Projector";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame(2, $result['equipment'][0]['qty']);
        $this->assertStringContainsString('Sony Projector', $result['equipment'][0]['description']);
    }

    public function test_parses_quantity_with_space_only(): void
    {
        $text = "3 Logitech Rally Bar";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame(3, $result['equipment'][0]['qty']);
    }

    public function test_defaults_quantity_to_one_when_no_leading_number(): void
    {
        $text = "Crestron Controller Unit";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame(1, $result['equipment'][0]['qty']);
    }

    public function test_strips_trailing_price_from_description(): void
    {
        $text = "1 Samsung 55\" Monitor £1,200.00";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertStringNotContainsString('£', $result['equipment'][0]['description']);
    }

    public function test_deduplicates_identical_equipment_lines(): void
    {
        $text = implode("\n", [
            '1 Sony Display Unit',
            '1 Sony Display Unit',
        ]);

        $result = $this->parser->parse($text);

        $this->assertCount(1, $result['equipment']);
    }

    public function test_filters_noise_lines_even_if_they_contain_equipment_keyword(): void
    {
        // "Description" is a QuoteWerks column header — must be filtered by isNoise()
        $text = "Description\nQty\nUnit Price";

        $result = $this->parser->parse($text);

        $this->assertEmpty($result['equipment']);
    }

    public function test_filters_description_too_short(): void
    {
        // < 5 chars after cleanup — Gate 3 rejects it
        $text = "1x HDMI";

        $result = $this->parser->parse($text);

        // "HDMI" alone after stripping the leading qty is only 4 chars and
        // must be filtered entirely — the equipment array should be empty.
        $this->assertEmpty(
            $result['equipment'],
            '"1x HDMI" should produce no equipment items — "HDMI" is only 4 chars after qty strip.'
        );

        // Belt-and-braces: if somehow an item slips through, its description
        // must still meet the minimum length.
        foreach ($result['equipment'] as $item) {
            $this->assertGreaterThanOrEqual(5, strlen($item['description']));
        }
    }

    public function test_pdf_obj_line_not_extracted_as_equipment(): void
    {
        // A bare "17 0 obj" line is a PDF structural fragment.
        // Even if it followed a line with an equipment keyword it should be
        // caught by isNoise() Gate 2 and never reach the description check.
        $text = implode("\n", [
            '17 0 obj',          // PDF object header — isNoise() must drop it
            '2 Sony Projector',  // real equipment line — should survive
        ]);

        $result = $this->parser->parse($text);

        // The projector is extracted; "17 0 obj" must never appear as a description.
        $descriptions = array_column($result['equipment'], 'description');
        foreach ($descriptions as $desc) {
            $this->assertDoesNotMatchRegularExpression('/^\d+\s+\d+\s+obj$/i', trim($desc));
        }
    }

    public function test_rejects_implausibly_large_quantity(): void
    {
        // Quantities > 500 should fall back to qty=1 or not be parsed as qty
        $text = "9999 Sony Display";

        $result = $this->parser->parse($text);

        if (! empty($result['equipment'])) {
            $this->assertLessThanOrEqual(500, $result['equipment'][0]['qty']);
        }
    }

    public function test_equipment_location_populated_when_room_in_description(): void
    {
        $text = "1 Samsung Display for Boardroom";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertNotEmpty($result['equipment'][0]['location']);
        $this->assertStringContainsStringIgnoringCase('boardroom', $result['equipment'][0]['location']);
    }

    public function test_equipment_location_empty_when_no_room_in_description(): void
    {
        $text = "1 Sony Projector XGA model";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame('', $result['equipment'][0]['location']);
    }

    // =========================================================================
    // TASK EXTRACTION
    // =========================================================================

    public function test_extracts_task_starting_with_install_verb(): void
    {
        $text = "Install 75\" display in boardroom\nUnrelated line";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['tasks']);
        $this->assertStringContainsStringIgnoringCase('install', $result['tasks'][0]);
    }

    public function test_extracts_task_with_supply_and_install_multi_word_verb(): void
    {
        $text = "Supply and install Crestron control system";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['tasks']);
        $this->assertStringContainsStringIgnoringCase('supply and install', $result['tasks'][0]);
    }

    public function test_extracts_task_with_mount_verb(): void
    {
        $text = "Mount display bracket to wall";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['tasks']);
    }

    public function test_deduplicates_identical_tasks(): void
    {
        $text = implode("\n", [
            'Install display in room',
            'Install display in room',
        ]);

        $result = $this->parser->parse($text);

        $this->assertCount(1, $result['tasks']);
    }

    public function test_task_is_truncated_to_80_chars(): void
    {
        $longTask = 'Install ' . str_repeat('a very long description about the equipment ', 4);
        $text     = $longTask;

        $result = $this->parser->parse($text);

        foreach ($result['tasks'] as $task) {
            $this->assertLessThanOrEqual(80, strlen($task));
        }
    }

    public function test_tasks_capped_at_twenty(): void
    {
        $lines = [];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = "Install item number {$i} somewhere in the building";
        }
        $text = implode("\n", $lines);

        $result = $this->parser->parse($text);

        $this->assertLessThanOrEqual(20, count($result['tasks']));
    }

    public function test_line_with_no_task_verb_not_extracted_as_task(): void
    {
        $text = "Quote Date: 01/01/2024\nClient: Acme Ltd\nTotal: £5,000";

        $result = $this->parser->parse($text);

        $this->assertEmpty($result['tasks']);
    }

    // =========================================================================
    // ROOM EXTRACTION
    // =========================================================================

    public function test_extracts_boardroom_from_line(): void
    {
        $text = "Works to be carried out in the Boardroom area";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['rooms']);
        $this->assertStringContainsStringIgnoringCase('boardroom', $result['rooms'][0]);
    }

    public function test_extracts_meeting_room_multi_word_keyword(): void
    {
        $text = "Display installation — Meeting Room 3";

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['rooms']);
        $this->assertStringContainsStringIgnoringCase('meeting room', $result['rooms'][0]);
    }

    public function test_deduplicates_identical_rooms(): void
    {
        $text = implode("\n", [
            'Install display in Reception area',
            'Mount screen in Reception area',
        ]);

        $result = $this->parser->parse($text);

        // Unique rooms only
        $lower = array_map('strtolower', $result['rooms']);
        $this->assertSame(count(array_unique($lower)), count($result['rooms']));
    }

    public function test_skips_lines_longer_than_120_chars_for_room_extraction(): void
    {
        $longLine = str_repeat('a', 121) . ' boardroom ' . str_repeat('b', 10);

        $result = $this->parser->parse($longLine);

        $this->assertEmpty($result['rooms']);
    }

    public function test_tagged_part_number_strips_leading_ocr_punctuation_noise(): void
    {
        $text = implode("\n", [
            'SITENAMESTART Example Client SITENAMEEND',
            'SHIPADDSTART 10 High Street, London SW1A 1AA SHIPADDEND',
            'QUOTENUMSTART 21CQ30246-06-OPS QUOTENUMEND',
            'PARTSTART ~LHBSWAFWLGCXEN PARTEND PARTDESCSTART Samsung 65 inch display PARTDESCEND QTYSTART 1.00 QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame('LHBSWAFWLGCXEN', $result['equipment'][0]['part_number']);
    }

    public function test_tagged_part_number_strips_trailing_punctuation_noise(): void
    {
        $text = implode("\n", [
            'SITENAMESTART Example Client SITENAMEEND',
            'SHIPADDSTART 10 High Street, London SW1A 1AA SHIPADDEND',
            'QUOTENUMSTART 21CQ30246-06-OPS QUOTENUMEND',
            'PARTSTART LH65QETELGCXEN, PARTEND PARTDESCSTART Samsung display PARTDESCEND QTYSTART 2.00 QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame('LH65QETELGCXEN', $result['equipment'][0]['part_number']);
    }

    public function test_tagged_parser_accepts_dot_separated_numeric_part_numbers(): void
    {
        $text = implode("\n", [
            'SITENAMESTART Example Client SITENAMEEND',
            'SHIPADDSTART 10 High Street, London SW1A 1AA SHIPADDEND',
            'PARTSTART 910.1995.900 PARTEND PARTDESCSTART Logitech Tap Cat5e PARTDESCEND QTYSTART 1.00 QTYEND',
            'PARTSTART 911.0498.900 PARTEND PARTDESCSTART Logitech Tap HDMI Kit PARTDESCEND QTYSTART 2.00 QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertCount(2, $result['equipment']);
        $this->assertSame('910.1995.900', $result['equipment'][0]['part_number']);
        $this->assertSame('911.0498.900', $result['equipment'][1]['part_number']);
    }

    public function test_tagged_parser_extracts_qty_when_trailing_in_partdesc_block(): void
    {
        $text = implode("\n", [
            'SITENAMESTART Example Client SITENAMEEND',
            'SHIPADDSTART 10 High Street, London SW1A 1AA SHIPADDEND',
            'PARTSTART 960-001227 PARTEND PARTDESCSTART Logitech Rally Conference Camera 1.00 PARTDESCEND QTYSTART QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame('960-001227', $result['equipment'][0]['part_number']);
        $this->assertSame(1, $result['equipment'][0]['qty']);
        $this->assertStringNotContainsString('1.00', $result['equipment'][0]['description']);
    }

    public function test_tagged_column_layout_extracts_company_site_name_and_part_rows(): void
    {
        $text = implode("\n", [
            'Mtg Room Fit out',
            'SITENAMESTART',
            'SITENAMEEND',
            'Integra Building Ltd',
            'SHIPCOMPSTART',
            'SHIPCOMPEND',
            'SHIPADDSTART',
            '21CQ30246-06-OPS',
            'West Burton Power Station',
            'Retford',
            'Rich -0771 8386409 (Site)',
            'DN22 9BL Nottinghamshire',
            'United Kingdom',
            'SHIPADDEND',
            'PARTSTART',
            'PARTEND',
            'PARTDESCSTART',
            '4.00',
            'LH65WAFWLGCXEN',
            'PARTDESCEND',
            'QTYSTART',
            'QTYEND',
            'Samsung 65 Interactive Display',
            'PARTSTART',
            'PARTEND',
            'PARTDESCSTART',
            '1.00',
            '36742',
            'PARTDESCEND',
            'QTYSTART',
            'QTYEND',
            'USB-A to USB-B Cable',
        ]);

        $result = $this->parser->parse($text);

        $this->assertSame('Integra Building Ltd', $result['client']);
        $this->assertSame('Mtg Room Fit out', $result['site_name']);
        $this->assertStringContainsString('West Burton Power Station', $result['site']);
        $this->assertStringNotContainsString('0771', $result['site']);

        $this->assertGreaterThanOrEqual(2, count($result['equipment']));
        $this->assertSame('LH65WAFWLGCXEN', $result['equipment'][0]['part_number']);
        $this->assertSame(4, $result['equipment'][0]['qty']);
        $this->assertStringContainsString('Samsung', $result['equipment'][0]['description']);
    }

    public function test_tagged_parser_handles_qtvend_variant_and_rejects_time_like_part_number(): void
    {
        $text = implode("\n", [
            'Mtg Room Fit out',
            'SITENAMESTART',
            'SITENAMEEND',
            'Integra Building Ltd',
            'SHIPCOMPSTART',
            'SHIPCOMPEND',
            'SHIPEMAILSTART jamesscarlett@integrabuildings.co.uk SHIPEMAILEND',
            'SHIPADDSTART West Burton Power Station DN22 9BL SHIPADDEND',
            'OVERVIEWTITLESTART Small Room - 4 Person OVERVIEWTITLEEND',
            'PARTSTART R9861633EUB2 PARTEND PARTDESCSTART Clickshare Bar Pro PARTDESCEND QTYSTART 4.00 QTVEND',
            'PARTSTART 9am PARTEND PARTDESCSTART 21st Engineering AV Team In-Hours Mon-Friday 9am - 50M PARTDESCEND QTYSTART 100 QTVEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertNotEmpty($result['equipment']);
        $this->assertSame('James Scarlett', $result['prepared_by']);
        $this->assertSame('R9861633EUB2', $result['equipment'][0]['part_number']);
        $this->assertSame('', $result['equipment'][1]['part_number']);
        $this->assertSame(1, $result['equipment'][1]['qty']);
    }

    public function test_overview_text_preserved_when_sharing_line_with_section_tokens(): void
    {
        // Regression test for the 21CQ30069 Restaurant Display defect where the
        // quote's short 2-sentence overview ended up losing its first sentence
        // because tight PDF word-wrap placed the prose on the same extracted
        // line as OVERVIEWTXTSTART/END and PARTSTART tags. The fallback line
        // extractor was dropping any line that contained a structural token,
        // so the prose prefix was lost even though Step 1's between-markers
        // regex should have rescued it. Lock in the fix.
        $text = implode("\n", [
            'SITENAMESTART Example Client SITENAMEEND',
            'OVERVIEWTITLESTART Restaurant Display OVERVIEWTITLEEND',
            'OVERVIEWTXTSTART The Restaurant Area will have 2 x NEC 55" MultiSync ME552 Displays wall mounted and shall use the integrated USB connection OVERVIEWTXTEND',
            'for media play back. A USB- A male to female extension will allow easy input of a USB device for media playback. PARTSTART PARTEND PARTDESCSTART PARTDESCEND QTYSTART 2 60005923 QTYEND',
            'Sharp 55" Multisync ME552 Commercial Display',
        ]);

        $result = $this->parser->parse($text);

        // The rescued overview must contain BOTH the in-markers first sentence
        // AND the post-marker continuation prose.
        $overviews = $result['room_overviews'] ?? [];
        $this->assertNotEmpty($overviews, 'room_overviews should include the Restaurant Display section');

        $restaurant = null;
        foreach ($overviews as $ro) {
            if (trim((string) ($ro['room'] ?? '')) === 'Restaurant Display') {
                $restaurant = $ro;
                break;
            }
        }
        $this->assertNotNull($restaurant, 'Restaurant Display overview must be present');

        $overview = (string) ($restaurant['overview'] ?? '');
        $this->assertStringContainsString(
            'The Restaurant Area will have',
            $overview,
            'First sentence (between OVERVIEWTXT markers) must survive parsing'
        );
        $this->assertStringContainsString(
            'for media play back',
            $overview,
            'Continuation after OVERVIEWTXTEND but on same line as PARTSTART must survive'
        );
        $this->assertStringContainsString(
            'USB- A male to female extension',
            $overview,
            'Full continuation prose must be preserved'
        );

        // Tag tokens themselves must NOT appear in the output.
        $this->assertStringNotContainsString('OVERVIEWTXT',   $overview);
        $this->assertStringNotContainsString('PARTSTART',     $overview);
        $this->assertStringNotContainsString('PARTDESCSTART', $overview);
        $this->assertStringNotContainsString('QTYSTART',      $overview);
    }

    public function test_header_token_lines_are_dropped_not_leaked_into_section_text(): void
    {
        // Defensive test: header tokens (SHIP*, SITENAME*, etc.) must never
        // be rescued by the same logic that preserves prose around section
        // tokens. A line like "SHIPCONTSTART Lizzie Thorpe SHIPCONTEND" must
        // NOT leak "Lizzie Thorpe" into a section overview, even if it falls
        // inside a section's raw text window due to page-break layout.
        $text = implode("\n", [
            'OVERVIEWTITLESTART Meeting Room OVERVIEWTITLEEND',
            'OVERVIEWTXTSTART Install a display and mount OVERVIEWTXTEND',
            // Simulated page-header spill inside the section window
            'SHIPCONTSTART Lizzie Thorpe SHIPCONTEND',
            'SITENAMESTART Volkswagen National Learning Centre SITENAMEEND',
            'Additional scope note on second line.',
            'PARTSTART LHBSW PARTEND PARTDESCSTART Samsung Display PARTDESCEND QTYSTART 1 QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $overviews = $result['room_overviews'] ?? [];
        $meetingRoom = null;
        foreach ($overviews as $ro) {
            if (trim((string) ($ro['room'] ?? '')) === 'Meeting Room') {
                $meetingRoom = $ro;
                break;
            }
        }
        $this->assertNotNull($meetingRoom);

        $overview = (string) ($meetingRoom['overview'] ?? '');
        $this->assertStringContainsString('Install a display and mount', $overview);
        $this->assertStringContainsString('Additional scope note',        $overview);
        $this->assertStringNotContainsString('Lizzie Thorpe',             $overview);
        $this->assertStringNotContainsString('Volkswagen National',       $overview);
    }

    public function test_tagged_equipment_deduplicates_same_area_and_part_number(): void
    {
        $text = implode("\n", [
            'OVERVIEWTITLESTART Small Room - 4 Person OVERVIEWTITLEEND',
            'PARTSTART LHBSWAFWLGCXEN PARTEND PARTDESCSTART Samsung, 65 Black Interactive Display PARTDESCEND QTYSTART 4.00 QTYEND',
            'PARTSTART LHBSWAFWLGCXEN PARTEND PARTDESCSTART Samsung, 65 Black Interactive Display PARTDESCEND QTYSTART 5.00 QTYEND',
        ]);

        $result = $this->parser->parse($text);

        $this->assertCount(1, $result['equipment']);
        $this->assertSame(4, $result['equipment'][0]['qty']);
    }

    // =========================================================================
    // NORMALISATION
    // =========================================================================

    public function test_client_output_is_trimmed_and_under_80_chars(): void
    {
        // A very long "Client:" value — the result must be trimmed and capped.
        $longName = str_repeat('Acme ', 20) . 'Limited';
        $text     = 'Client: ' . $longName;

        $result = $this->parser->parse($text);

        $this->assertSame(trim($result['client']), $result['client'], 'Client must be trimmed');
        $this->assertLessThanOrEqual(80, strlen($result['client']), 'Client max 80 chars');
    }

    public function test_site_output_is_trimmed_and_under_150_chars(): void
    {
        // A very long "Ship To:" value — the result must be trimmed and capped.
        $longSite = 'Ship To: ' . str_repeat('Long Road Avenue, ', 12) . 'London';

        $result = $this->parser->parse($longSite);

        $this->assertSame(trim($result['site']), $result['site'], 'Site must be trimmed');
        $this->assertLessThanOrEqual(150, strlen($result['site']), 'Site max 150 chars');
    }

    // =========================================================================
    // EMPTY / EDGE CASES
    // =========================================================================

    public function test_empty_string_returns_sensible_defaults(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame('',      $result['client']);
        $this->assertSame('',      $result['site']);
        $this->assertSame('RAMS-001', $result['ref']);
        $this->assertSame([],      $result['equipment']);
        $this->assertSame([],      $result['tasks']);
        $this->assertSame([],      $result['rooms']);
    }

    public function test_purely_numeric_lines_are_filtered_before_extraction(): void
    {
        // toLines() requires at least one alphabetic character —
        // this line should never reach any extraction method.
        $text = "12345\n67890\nClient: Acme Ltd";

        $result = $this->parser->parse($text);

        // Client should still be found; the numeric lines caused no crash.
        $this->assertSame('Acme Ltd', $result['client']);
    }

    public function test_pdf_xref_lines_do_not_cause_equipment_false_positives(): void
    {
        // xref-style lines mixed with a real equipment line
        $text = implode("\n", [
            '0000000017 00000 n',
            '17 0 obj',
            '2 Sony Display Unit',
        ]);

        $result = $this->parser->parse($text);

        // Only the real equipment line should survive
        foreach ($result['equipment'] as $item) {
            $this->assertDoesNotMatchRegularExpression('/^\d{5,}\s+\d{5}\s+[fn]$/i', $item['description']);
        }
    }

    public function test_parse_handles_windows_line_endings(): void
    {
        $text = "Client: Acme Ltd\r\nShip To: 10 High Street, London\r\nQ12345";

        $result = $this->parser->parse($text);

        $this->assertSame('Acme Ltd', $result['client']);
        $this->assertNotEmpty($result['site']);
    }
}

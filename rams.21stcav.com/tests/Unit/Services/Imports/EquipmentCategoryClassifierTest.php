<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\EquipmentCategoryClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EquipmentCategoryClassifier (260725-qw3, extended 260815-sup).
 *
 * Verifies the 9-canonical-value vocabulary, the priority-ordered decision
 * tree (specific → broad), and the explicit-category short-circuit behaviour.
 */
class EquipmentCategoryClassifierTest extends TestCase
{
    private EquipmentCategoryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new EquipmentCategoryClassifier();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Explicit-category short-circuit
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function explicit_canonical_category_short_circuits_over_keyword_matching(): void
    {
        // "Sony display" would keyword-match nothing specific (falls to hardware).
        // But an explicit category=service_contracts must win.
        $this->assertSame(
            'service_contracts',
            $this->classifier->classify(['category' => 'service_contracts', 'name' => 'Sony display']),
        );
    }

    /** @test */
    public function explicit_hardware_short_circuits_over_cable_keyword(): void
    {
        // "Cat6 cable" would normally classify as cables, but user's explicit
        // dropdown pick of hardware should be respected.
        $this->assertSame(
            'hardware',
            $this->classifier->classify(['category' => 'hardware', 'name' => 'Cat6 cable']),
        );
    }

    /** @test */
    public function each_of_the_nine_canonical_values_is_returned_verbatim(): void
    {
        foreach (EquipmentCategoryClassifier::CATEGORIES as $canonical) {
            $this->assertSame(
                $canonical,
                $this->classifier->classify(['category' => $canonical, 'name' => 'anything']),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: hardware_supply_only (260815-sup — manual-selection only)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function hardware_supply_only_survives_the_save_round_trip(): void
    {
        $this->assertSame(
            'hardware_supply_only',
            $this->classifier->classify(['category' => 'hardware_supply_only', 'name' => 'Client-owned PTZ camera rig']),
        );
    }

    /** @test */
    public function hardware_supply_only_is_in_the_canonical_categories(): void
    {
        $this->assertContains('hardware_supply_only', EquipmentCategoryClassifier::CATEGORIES);
    }

    /** @test */
    public function no_keyword_maps_to_hardware_supply_only_a_realistic_camera_lens_lighting_line_stays_hardware(): void
    {
        // Realistic "Digital Production Studio" style line (see 260815-sup
        // PLAN.md Why) — must classify as plain hardware via the keyword
        // tree. hardware_supply_only is manual-selection only; nothing in a
        // description reliably signals "supplied but not installed".
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'SONY-FX6',
            'name'        => 'Sony FX6 Full-Frame Cinema Camera with 24-105mm f/4 G Lens and LED Lighting Kit',
            'description' => 'Client-owned production camera, lens and lighting package for the studio.',
        ]));
    }

    /** @test */
    public function fabricated_category_values_fall_through_to_keyword_matching(): void
    {
        // 'display' is a fabricated value from the old QW classifier — it must
        // NOT be respected as canonical, and must fall through to keyword
        // matching on the description.
        $this->assertSame(
            'hardware', // "Sony 75\" 4K UHD" doesn't match anything specific
            $this->classifier->classify(['category' => 'display', 'name' => 'Sony 75" 4K UHD']),
        );
    }

    /** @test */
    public function empty_item_returns_hardware(): void
    {
        $this->assertSame('hardware', $this->classifier->classify([]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: hardware (default)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function generic_display_defaults_to_hardware(): void
    {
        $this->assertSame(
            'hardware',
            $this->classifier->classify(['name' => 'Sony BRAVIA 75" 4K UHD Display']),
        );
        $this->assertSame(
            'hardware',
            $this->classifier->classify(['description' => 'Crestron DM-NVX-360 encoder']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: cables
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function cable_keywords_classify_as_cables(): void
    {
        $this->assertSame(
            'cables',
            $this->classifier->classify(['name' => 'Kramer Cat6 patch cable 3m']),
        );
        $this->assertSame(
            'cables',
            $this->classifier->classify(['description' => '25m HDMI cable premium']),
        );
        $this->assertSame(
            'cables',
            $this->classifier->classify(['name' => 'TRUNKING 50mm x 25m']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: consumables
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function consumable_keywords_classify_as_consumables(): void
    {
        $this->assertSame(
            'consumables',
            $this->classifier->classify(['name' => 'Rawlplug 8mm anchor pack']),
        );
        $this->assertSame(
            'consumables',
            $this->classifier->classify(['description' => 'Cable ties 200mm x 100']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: services
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function services_keywords_classify_as_services(): void
    {
        // Word-boundary matches in description text
        $this->assertSame(
            'services',
            $this->classifier->classify(['name' => 'Installation labour — 2 engineers']),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify(['name' => 'HANDOVER - client walkthrough']),
        );
        // QW SKU-token matches on part_number (canonical 21CAV catalogue)
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'PROGRAMMING1',
                'name'        => 'Crestron programming day rate',
            ]),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'DELIVERY',
                'name'        => 'Charge to site',
            ]),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'INSTALL2',
                'name'        => '21st Engineering AV Team In-Hours',
            ]),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'PROJECTMANAGEMENT',
                'name'        => 'Project Management On Site',
            ]),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'SSVOTHER',
                'name'        => 'Site Survey OTHER (incl Parking/Travel)',
            ]),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'part_number' => 'RAMS',
                'name'        => 'RAMS - Detailed Risk & Method Statement',
            ]),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 260725-fx2 — regression: hardware descriptions must not false-trigger
    //                          on 'support' / 'survey' / 'install' substrings
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function crestron_uc_kit_supports_wording_stays_hardware(): void
    {
        // Real Tilda-quote description — "supports single or dual video
        // displays" was matching the bare 'support' keyword and being
        // classified as services. Fixed by moving to word-boundary +
        // dropping 'support' from the description keyword list.
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'UC-CX100-T',
            'name'        => 'Crestron Flex Advanced Video Conference System Integrator Kit',
            'description' => 'The Crestron Flex integrator kit provides a customisable video conference solution for use with Microsoft Teams® Rooms software. It supports single or dual video displays and features a UC presentation transmitter, a tabletop touch screen and a UC bracket assembly.',
        ]));
    }

    /** @test */
    public function chief_display_mount_site_survey_note_stays_hardware(): void
    {
        // Real Tilda-quote XSM1U row — the "*NOTE, a different mounting
        // solution may be required depending on the design of the wall
        // furniture. To be confirmed during the site survey." trailing
        // text was matching the bare 'survey' keyword and being classified
        // as services. Fixed by dropping 'survey' from description matcher
        // (only matched via SSVOTHER-style part_number now).
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'XSM1U',
            'name'        => 'Chief X-Large Fusion Micro-Adjustable Fixed Wall Display Mount',
            'description' => '*NOTE, a different mounting solution may be required depending on the design of the wall furniture. To be confirmed during the site survey.',
        ]));
    }

    /** @test */
    public function product_names_containing_installer_stay_hardware(): void
    {
        // Bare 'install' would match "installer" / "installed" / "installation"
        // via str_contains. Word-boundary + dropped-from-description means only
        // 'installation' (full word) triggers, and only when it appears standalone.
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'FOO-123',
            'name'        => 'DIN-rail mounted installer-friendly bracket',
        ]));
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'FOO-456',
            'name'        => 'Rack shelf — pre-installed vented panel',
        ]));
    }

    /** @test */
    public function product_names_containing_management_stay_hardware(): void
    {
        // The words "management console" / "cable management" appear in
        // many hardware descriptions. Previously matched 'management' →
        // services. Now excluded from description matcher.
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'FOO-789',
            'name'        => 'Extron network management console',
        ]));
    }

    /** @test */
    public function product_names_containing_training_stay_hardware(): void
    {
        // Similar to management — many hardware descriptions mention
        // training capabilities, training rooms, etc.
        $this->assertSame('hardware', $this->classifier->classify([
            'part_number' => 'FOO-101',
            'name'        => 'Interactive display for training rooms',
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: service_contracts
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function service_contract_keywords_classify_as_service_contracts(): void
    {
        $this->assertSame(
            'service_contracts',
            $this->classifier->classify(['name' => 'Sony 3-year warranty extension']),
        );
        $this->assertSame(
            'service_contracts',
            $this->classifier->classify(['description' => 'Poly ProSupport 5 years']),
        );
        $this->assertSame(
            'service_contracts',
            $this->classifier->classify(['name' => 'HP Care Pack — swap out coverage']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: customer_supplied
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function customer_supplied_keywords_classify_as_customer_supplied(): void
    {
        $this->assertSame(
            'customer_supplied',
            $this->classifier->classify(['name' => '**CLIENT SUPPLIED** existing display 55"']),
        );
        $this->assertSame(
            'customer_supplied',
            $this->classifier->classify(['description' => 'BYOD Cat6a patch panel provided by customer']),
        );
        $this->assertSame(
            'customer_supplied',
            $this->classifier->classify(['name' => 'Existing PC — client supplied']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Category: option
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function option_keywords_classify_as_option(): void
    {
        $this->assertSame(
            'option',
            $this->classifier->classify(['name' => 'Optional room booking panel']),
        );
        $this->assertSame(
            'option',
            $this->classifier->classify(['description' => 'Option — extended microphone kit']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Priority ordering — specific matches beat broader ones
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function warranty_beats_cable_in_priority_order(): void
    {
        // "Warranty extension - Cat6 cable" should classify as service_contracts,
        // NOT cables, because service_contracts comes first in the decision tree.
        $this->assertSame(
            'service_contracts',
            $this->classifier->classify(['name' => 'Warranty extension - Cat6 cable']),
        );
    }

    /** @test */
    public function client_supplied_beats_display_in_priority_order(): void
    {
        // Would keyword-match nothing for display specifically → hardware default;
        // but client-supplied prefix must win.
        $this->assertSame(
            'customer_supplied',
            $this->classifier->classify(['name' => '**CLIENT SUPPLIED** Sony 75" display']),
        );
    }

    /** @test */
    public function optional_beats_all_other_keyword_buckets(): void
    {
        // "Optional Cat6 patch cable installation" — option is highest priority,
        // even though 'cable' and 'install' would also match downstream.
        $this->assertSame(
            'option',
            $this->classifier->classify(['name' => 'Optional Cat6 patch cable installation']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Case insensitivity
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function case_variations_of_warranty_all_classify_as_service_contracts(): void
    {
        foreach (['WARRANTY', 'Warranty', 'warranty', 'WaRrAnTy'] as $variant) {
            $this->assertSame(
                'service_contracts',
                $this->classifier->classify(['name' => $variant.' extension']),
                "Failed on variant: {$variant}",
            );
        }
    }

    /** @test */
    public function case_variations_of_client_supplied_all_classify_as_customer_supplied(): void
    {
        foreach (['CLIENT SUPPLIED', 'client supplied', 'Client Supplied'] as $variant) {
            $this->assertSame(
                'customer_supplied',
                $this->classifier->classify(['name' => $variant.' display']),
                "Failed on variant: {$variant}",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Field aggregation — name + description + part_number
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function part_number_alone_can_drive_classification(): void
    {
        // Part number field contributes to the haystack too.
        $this->assertSame(
            'cables',
            $this->classifier->classify([
                'name'        => 'Blank',
                'description' => 'Blank',
                'part_number' => 'CAT6-PATCH-3M',
            ]),
        );
    }

    /** @test */
    public function description_alone_can_drive_classification(): void
    {
        $this->assertSame(
            'services',
            $this->classifier->classify([
                'name'        => '',
                'description' => 'Onsite installation and configuration day',
            ]),
        );
    }
}

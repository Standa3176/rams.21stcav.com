<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\EquipmentCategoryClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EquipmentCategoryClassifier (260725-qw3).
 *
 * Verifies the 7-canonical-value vocabulary, the priority-ordered decision
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
    public function each_of_the_seven_canonical_values_is_returned_verbatim(): void
    {
        foreach (EquipmentCategoryClassifier::CATEGORIES as $canonical) {
            $this->assertSame(
                $canonical,
                $this->classifier->classify(['category' => $canonical, 'name' => 'anything']),
            );
        }
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
        $this->assertSame(
            'services',
            $this->classifier->classify(['name' => 'Installation labour — 2 engineers']),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify(['description' => 'PROGRAMMING1 - Crestron programming day rate']),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify(['name' => 'HANDOVER - client training session']),
        );
        $this->assertSame(
            'services',
            $this->classifier->classify(['name' => 'DELIVERY charge to site']),
        );
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

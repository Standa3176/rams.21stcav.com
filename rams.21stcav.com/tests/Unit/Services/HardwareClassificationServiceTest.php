<?php

namespace Tests\Unit\Services;

use App\Services\HardwareClassificationService;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for HardwareClassificationService.
 *
 * Pure function, no Laravel dependencies — extends PHPUnit\Framework\TestCase
 * directly to keep the suite fast (no Laravel bootstrap).
 */
class HardwareClassificationServiceTest extends TestCase
{
    private HardwareClassificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new HardwareClassificationService();
    }

    public function test_empty_name_is_not_hardware(): void
    {
        $this->assertFalse($this->svc->isHardware(''));
        $this->assertFalse($this->svc->isHardware('   '));
    }

    public function test_additional_placeholder_is_not_hardware(): void
    {
        $this->assertFalse($this->svc->isHardware('Additional'));
        $this->assertFalse($this->svc->isHardware('  additional  '));
    }

    public function test_item_type_consumable_forces_false(): void
    {
        $this->assertFalse($this->svc->isHardware('Samsung 75" Display', 'consumable'));
    }

    public function test_item_type_professional_service_forces_false(): void
    {
        $this->assertFalse($this->svc->isHardware('Samsung 75" Display', 'professional_service'));
    }

    public function test_item_type_hardware_forces_true_even_when_name_is_ambiguous(): void
    {
        // Without item_type the name "Installation Kit" would match install
        // keyword. item_type=hardware overrides that.
        $this->assertTrue($this->svc->isHardware('Installation Kit', 'hardware'));
    }

    public function test_non_hardware_categories_force_false(): void
    {
        foreach (['cables', 'consumables', 'services', 'option', 'labour'] as $cat) {
            $this->assertFalse(
                $this->svc->isHardware('Any Name', '', $cat),
                "Category '{$cat}' should force non-hardware"
            );
        }
    }

    public function test_category_case_insensitive(): void
    {
        $this->assertFalse($this->svc->isHardware('Any', '', 'SERVICES'));
        $this->assertFalse($this->svc->isHardware('Any', '', 'CaBlEs'));
    }

    public function test_genuine_hardware_passes(): void
    {
        $this->assertTrue($this->svc->isHardware('Samsung QM75B 75" display'));
        $this->assertTrue($this->svc->isHardware('Logitech Rally Bar'));
        $this->assertTrue($this->svc->isHardware('Crestron DM-NVX-D30'));
        $this->assertTrue($this->svc->isHardware('Shure MXA920 Ceiling Array'));
    }

    public function test_services_and_warranties_stripped_by_keyword(): void
    {
        $this->assertFalse($this->svc->isHardware('Site Survey'));
        $this->assertFalse($this->svc->isHardware('Engineering Team'));
        $this->assertFalse($this->svc->isHardware('Configuration Service'));
        $this->assertFalse($this->svc->isHardware('Extended Warranty 3 Year'));
        $this->assertFalse($this->svc->isHardware('3 Year Warranty'));
        $this->assertFalse($this->svc->isHardware('Support Plan'));
        $this->assertFalse($this->svc->isHardware('Project Management'));
        $this->assertFalse($this->svc->isHardware('Delivery & Carriage'));
        $this->assertFalse($this->svc->isHardware('Labour'));
        $this->assertFalse($this->svc->isHardware('Method Statement'));
        $this->assertFalse($this->svc->isHardware('Risk Assessment'));
    }

    public function test_cables_stripped_by_cable_pattern(): void
    {
        $this->assertFalse($this->svc->isHardware('Cat6 Cable 3m'));
        $this->assertFalse($this->svc->isHardware('Cat6a Cable'));
        $this->assertFalse($this->svc->isHardware('Lindy 10m Cat6 Cable'));
        $this->assertFalse($this->svc->isHardware('HDMI Cable 2m'));
        $this->assertFalse($this->svc->isHardware('DisplayPort Cable'));
        $this->assertFalse($this->svc->isHardware('Fibre Cable 50m'));
    }

    public function test_cable_tie_stripped_as_consumable(): void
    {
        $this->assertFalse($this->svc->isHardware('Cable Ties (pack of 100)'));
    }

    public function test_patch_leads_and_network_cables_stripped(): void
    {
        $this->assertFalse($this->svc->isHardware('Cat6 Patch Lead'));
        $this->assertFalse($this->svc->isHardware('Snagless Patch Cable'));
        $this->assertFalse($this->svc->isHardware('Network Cable'));
    }

    public function test_discount_and_tax_lines_stripped(): void
    {
        $this->assertFalse($this->svc->isHardware('Discount'));
        $this->assertFalse($this->svc->isHardware('Credit Note'));
        $this->assertFalse($this->svc->isHardware('VAT'));
        $this->assertFalse($this->svc->isHardware('FOC'));
        $this->assertFalse($this->svc->isHardware('Free of Charge Spare'));
    }
}

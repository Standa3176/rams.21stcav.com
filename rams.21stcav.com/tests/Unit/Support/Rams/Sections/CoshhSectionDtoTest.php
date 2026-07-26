<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\CoshhSectionDto;
use PHPUnit\Framework\TestCase;

class CoshhSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_coshh_inventory(): void
    {
        $dto = new CoshhSectionDto(inventory: [
            [
                'product'   => 'Isopropyl Alcohol (IPA)',
                'use'       => 'Screen and lens cleaning.',
                'ghs_codes' => ['H225', 'H319', 'H336'],
                'controls'  => ['Nitrile gloves', 'Well-ventilated area'],
            ],
        ]);

        $this->assertSame('Isopropyl Alcohol (IPA)', $dto->inventory[0]['product']);
        $this->assertSame(['H225', 'H319', 'H336'], $dto->inventory[0]['ghs_codes']);
    }

    public function test_from_array_normalises_partial_rows(): void
    {
        $dto = CoshhSectionDto::fromArray([
            'inventory' => [
                ['product' => 'IPA'],                                     // missing use/codes/controls
                ['ghs_codes' => 'H225'],                                  // scalar coerced to array
            ],
        ]);

        $this->assertSame(
            ['product' => 'IPA', 'use' => '', 'ghs_codes' => [], 'controls' => []],
            $dto->inventory[0]
        );
        $this->assertSame(['H225'], $dto->inventory[1]['ghs_codes']);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new CoshhSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_inventory_populated(): void
    {
        $this->assertFalse(CoshhSectionDto::fromArray(['inventory' => [['product' => 'x']]])->isEmpty());
    }
}

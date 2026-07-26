<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\EnvironmentalSectionDto;
use PHPUnit\Framework\TestCase;

class EnvironmentalSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_environmental_bullets(): void
    {
        $dto = new EnvironmentalSectionDto(
            wasteDisposal:      ['Segregate cable offcuts', 'Client-approved skip only'],
            noiseDustVibration: ['Drilling out-of-hours where possible', 'Dust extraction on drill'],
        );

        $this->assertCount(2, $dto->wasteDisposal);
        $this->assertCount(2, $dto->noiseDustVibration);
    }

    public function test_from_array_coerces_scalars_to_strings(): void
    {
        $dto = EnvironmentalSectionDto::fromArray([
            'waste_disposal'       => ['x', 42],
            'noise_dust_vibration' => 'single item as scalar',
        ]);

        $this->assertSame(['x', '42'], $dto->wasteDisposal);
        $this->assertSame(['single item as scalar'], $dto->noiseDustVibration);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new EnvironmentalSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_bucket_populated(): void
    {
        $this->assertFalse(EnvironmentalSectionDto::fromArray(['waste_disposal' => ['x']])->isEmpty());
        $this->assertFalse(EnvironmentalSectionDto::fromArray(['noise_dust_vibration' => ['y']])->isEmpty());
    }
}

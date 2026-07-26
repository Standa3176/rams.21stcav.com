<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\WelfareSectionDto;
use PHPUnit\Framework\TestCase;

class WelfareSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_welfare_arrangements(): void
    {
        $dto = new WelfareSectionDto(
            toilets:       'On-site — ground floor',
            washing:       'Adjacent to toilets',
            restArea:      'Client staff kitchen',
            firstAid:      'On-site first aider (Bob Client)',
            drinkingWater: 'Kitchenette tap + water fountain',
        );

        $this->assertSame('On-site — ground floor', $dto->toilets);
        $this->assertSame('Kitchenette tap + water fountain', $dto->drinkingWater);
    }

    public function test_from_array_is_tolerant_of_missing_keys(): void
    {
        $dto = WelfareSectionDto::fromArray(['toilets' => 'x']);
        $this->assertSame('x', $dto->toilets);
        $this->assertSame('', $dto->washing);
        $this->assertSame('', $dto->restArea);
        $this->assertSame('', $dto->firstAid);
        $this->assertSame('', $dto->drinkingWater);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new WelfareSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_field_populated(): void
    {
        $this->assertFalse(WelfareSectionDto::fromArray(['toilets' => 'x'])->isEmpty());
        $this->assertFalse(WelfareSectionDto::fromArray(['first_aid' => 'y'])->isEmpty());
    }
}

<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\ExclusionsSectionDto;
use PHPUnit\Framework\TestCase;

class ExclusionsSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_exclusion_bullets(): void
    {
        $dto = new ExclusionsSectionDto(items: [
            'Mains electrical works (by others).',
            'Structural alterations to walls.',
        ]);

        $this->assertCount(2, $dto->items);
    }

    public function test_from_array_coerces_scalars_to_strings(): void
    {
        $dto = ExclusionsSectionDto::fromArray(['items' => ['x', 42, null]]);
        $this->assertSame(['x', '42', ''], $dto->items);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new ExclusionsSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_items_populated(): void
    {
        $this->assertFalse(ExclusionsSectionDto::fromArray(['items' => ['x']])->isEmpty());
    }
}

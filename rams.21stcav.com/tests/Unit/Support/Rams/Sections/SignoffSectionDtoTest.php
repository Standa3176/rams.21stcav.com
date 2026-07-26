<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\SignoffSectionDto;
use PHPUnit\Framework\TestCase;

class SignoffSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_signoff_sides(): void
    {
        $dto = new SignoffSectionDto(
            company: ['name' => 'Alex Bloggs', 'position' => 'Lead Engineer', 'date' => '2026-07-26', 'sig' => ''],
            client:  ['name' => '', 'position' => '', 'date' => '', 'sig' => ''],
        );

        $this->assertSame('Alex Bloggs', $dto->company['name']);
        $this->assertSame('', $dto->client['name']);
    }

    public function test_from_array_normalises_partial_sides(): void
    {
        $dto = SignoffSectionDto::fromArray([
            'company' => ['name' => 'AB'],
        ]);

        $this->assertSame(
            ['name' => 'AB', 'position' => '', 'date' => '', 'sig' => ''],
            $dto->company
        );
        $this->assertSame(
            ['name' => '', 'position' => '', 'date' => '', 'sig' => ''],
            $dto->client
        );
    }

    public function test_is_empty_when_both_sides_blank(): void
    {
        $this->assertTrue((new SignoffSectionDto())->isEmpty());
        $this->assertTrue(SignoffSectionDto::fromArray([])->isEmpty());
        // Default-shape (all-blank) sides also count as empty.
        $this->assertTrue(SignoffSectionDto::fromArray([
            'company' => ['name' => '', 'position' => ''],
            'client'  => ['name' => '', 'sig' => ''],
        ])->isEmpty());
    }

    public function test_is_empty_false_when_any_side_has_a_populated_field(): void
    {
        $this->assertFalse(
            SignoffSectionDto::fromArray(['company' => ['name' => 'AB']])->isEmpty()
        );
        $this->assertFalse(
            SignoffSectionDto::fromArray(['client' => ['date' => '2026-07-26']])->isEmpty()
        );
    }
}

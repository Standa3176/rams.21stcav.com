<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\CoverSectionDto;
use PHPUnit\Framework\TestCase;

class CoverSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_cover_data_succeeds(): void
    {
        $dto = new CoverSectionDto(
            client:              'Tilda Ltd',
            site:                '3 Priory Road, Rickmansworth',
            projectRef:          '21CQ29531',
            rooms:               ['Board Room', 'Bistro'],
            date:                '2026-07-26',
            preparedBy:          'Alex Bloggs',
            clientContactName:   'Sarah Client',
            clientContactPhone:  '020 7946 0000',
            revision:            'Rev 1.0',
            status:              'FOR REVIEW',
            leadEngineer:        'Alex Bloggs',
            additionalEngineers: ['Bob', 'Charlie'],
            vehicles:            ['AB12 CDE'],
        );

        $this->assertSame('Tilda Ltd', $dto->client);
        $this->assertSame(['Board Room', 'Bistro'], $dto->rooms);
        $this->assertSame(['Bob', 'Charlie'], $dto->additionalEngineers);
        $this->assertSame('Rev 1.0', $dto->revision);
    }

    public function test_from_array_is_tolerant_of_missing_keys(): void
    {
        $dto = CoverSectionDto::fromArray(['client' => 'Only Client Populated']);

        $this->assertSame('Only Client Populated', $dto->client);
        $this->assertSame('', $dto->site);
        $this->assertSame('', $dto->projectRef);
        $this->assertSame([], $dto->rooms);
        $this->assertSame([], $dto->additionalEngineers);
        $this->assertSame([], $dto->vehicles);
    }

    public function test_from_array_coerces_non_string_scalars_to_strings(): void
    {
        // Some upstream sources may hand ints/nulls — the DTO must not crash.
        $dto = CoverSectionDto::fromArray([
            'client'   => 'X',
            'rooms'    => ['Room A', 42, null],  // int/null coerced
            'vehicles' => 'AB12 CDE',            // scalar coerced to array
        ]);

        $this->assertSame(['Room A', '42', ''], $dto->rooms);
        $this->assertSame(['AB12 CDE'], $dto->vehicles);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new CoverSectionDto())->isEmpty());
        $this->assertTrue(CoverSectionDto::fromArray([])->isEmpty());
    }

    public function test_is_empty_returns_false_when_any_field_populated(): void
    {
        $this->assertFalse(CoverSectionDto::fromArray(['client' => 'X'])->isEmpty());
        $this->assertFalse(CoverSectionDto::fromArray(['rooms' => ['A']])->isEmpty());
        $this->assertFalse(CoverSectionDto::fromArray(['vehicles' => ['V1']])->isEmpty());
    }
}

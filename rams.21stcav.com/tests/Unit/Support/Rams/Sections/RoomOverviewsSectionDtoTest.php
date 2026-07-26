<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\RoomOverviewsSectionDto;
use PHPUnit\Framework\TestCase;

class RoomOverviewsSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_room_narrative_rows(): void
    {
        $dto = new RoomOverviewsSectionDto(rooms: [
            [
                'room'          => 'Board Room',
                'overview'      => 'Existing 65" display and legacy codec.',
                'summary'       => 'MTR upgrade with Poly X50.',
                'solution_type' => 'MTR',
                'works_summary' => 'Remove existing; install X50 + touch panel.',
                'room_type'     => 'meeting_room',
            ],
        ]);

        $this->assertCount(1, $dto->rooms);
        $this->assertSame('MTR', $dto->rooms[0]['solution_type']);
    }

    public function test_from_array_normalises_partial_rows(): void
    {
        $dto = RoomOverviewsSectionDto::fromArray([
            'rooms' => [
                ['room' => 'Room A'],                                          // only room set
                ['overview' => 'Overview text', 'solution_type' => 'BYOD'],    // only two keys
            ],
        ]);

        $this->assertCount(2, $dto->rooms);
        $this->assertSame('Room A', $dto->rooms[0]['room']);
        $this->assertSame('',        $dto->rooms[0]['solution_type']);
        $this->assertSame('BYOD',    $dto->rooms[1]['solution_type']);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new RoomOverviewsSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_rooms_populated(): void
    {
        $this->assertFalse(RoomOverviewsSectionDto::fromArray(['rooms' => [['room' => 'A']]])->isEmpty());
    }
}

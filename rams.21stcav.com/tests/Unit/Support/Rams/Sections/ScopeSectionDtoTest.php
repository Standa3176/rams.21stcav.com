<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\ScopeSectionDto;
use PHPUnit\Framework\TestCase;

class ScopeSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_scope_data(): void
    {
        $dto = new ScopeSectionDto(
            activities:   ['Install AV', 'Commission and handover'],
            perRoomScope: ['Board Room' => ['Fix TV to wall', 'Install codec']],
            newInstall:   [['item_name' => 'Poly X50', 'qty' => '2', 'room' => 'Board Room', 'notes' => '']],
            decommission: [['item_name' => 'Old projector', 'qty' => '1', 'room' => 'Board Room', 'notes' => 'Client keeps']],
            retained:     [['item_name' => 'Speakers', 'qty' => '2', 'room' => 'Bistro', 'notes' => '']],
        );

        $this->assertCount(2, $dto->activities);
        $this->assertSame(['Fix TV to wall', 'Install codec'], $dto->perRoomScope['Board Room']);
        $this->assertSame('Poly X50', $dto->newInstall[0]['item_name']);
        $this->assertSame('Client keeps', $dto->decommission[0]['notes']);
    }

    public function test_from_array_normalises_equipment_rows(): void
    {
        $dto = ScopeSectionDto::fromArray([
            'new_install' => [
                ['item_name' => 'X50'],                 // missing qty/room/notes
                ['qty' => '1', 'room' => 'Bistro'],     // missing item_name/notes
            ],
        ]);

        $this->assertCount(2, $dto->newInstall);
        $this->assertSame(
            ['item_name' => 'X50', 'qty' => '', 'room' => '', 'notes' => ''],
            $dto->newInstall[0]
        );
    }

    public function test_from_array_coerces_per_room_scope_values_to_strings(): void
    {
        $dto = ScopeSectionDto::fromArray([
            'per_room_scope' => ['Room A' => ['do thing', 42]],
        ]);

        $this->assertSame(['do thing', '42'], $dto->perRoomScope['Room A']);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new ScopeSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_bucket_populated(): void
    {
        $this->assertFalse(ScopeSectionDto::fromArray(['activities' => ['x']])->isEmpty());
        $this->assertFalse(ScopeSectionDto::fromArray(['new_install' => [['item_name' => 'x']]])->isEmpty());
    }
}

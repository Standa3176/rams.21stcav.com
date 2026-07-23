<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\Adapters\WorksheetEditAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pure array-in / array-out tests for WorksheetEditAdapter::applyRemoveRoom.
 *
 * Worksheet rooms live in generated_data['rooms'][] keyed on the "name"
 * field (differs from RAMS's reviewed_data['room_overviews'] keyed on "room").
 * All per-room content (install_steps, tools, category_summary,
 * room_works_description) is nested under the room entry — filtering it out
 * removes the entire scope in one op.
 *
 * Introduced 2026-07-23 (quick task 260723-rr1) mirroring the RAMS op fix.
 */
class WorksheetEditAdapter_RemoveRoomTest extends TestCase
{
    private WorksheetEditAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new WorksheetEditAdapter();
    }

    private function payloadWith(array $roomNames): array
    {
        return [
            'rooms' => array_map(
                fn (string $n) => [
                    'name'             => $n,
                    'install_steps'    => ["Step for {$n}"],
                    'tools'            => ['Cordless drill'],
                    'category_summary' => "Summary for {$n}",
                ],
                $roomNames,
            ),
        ];
    }

    public function test_remove_room_filters_named_room(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Saffron']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['rooms'], 'name');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_is_case_insensitive(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'SAFFRON']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['rooms'], 'name');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_is_whitespace_tolerant(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => "  Saffron\n"]);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['rooms'], 'name');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_reindexes_array_after_removal(): void
    {
        $payload = $this->payloadWith(['A', 'B', 'C', 'D']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'B']);

        $rooms = $result['payload']['rooms'];
        $this->assertSame([0, 1, 2], array_keys($rooms));
        $this->assertSame(['A', 'C', 'D'], array_column($rooms, 'name'));
    }

    public function test_remove_room_with_empty_name_returns_invalid_op(): void
    {
        $payload = $this->payloadWith(['Oregano']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => '']);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_op', $result['code']);
    }

    public function test_remove_room_with_unknown_name_is_idempotent_success(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Tarragon']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['rooms'], 'name');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_survives_missing_rooms_key(): void
    {
        $payload = [];

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Anything']);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['payload']['rooms']);
    }

    public function test_remove_room_preserves_nested_room_content_for_other_rooms(): void
    {
        $payload = $this->payloadWith(['Kept', 'Removed']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Removed']);

        $this->assertCount(1, $result['payload']['rooms']);
        $kept = $result['payload']['rooms'][0];
        $this->assertSame('Kept', $kept['name']);
        $this->assertSame(['Step for Kept'], $kept['install_steps']);
        $this->assertSame(['Cordless drill'], $kept['tools']);
        $this->assertSame('Summary for Kept', $kept['category_summary']);
    }

    public function test_remove_room_is_in_allowed_operations(): void
    {
        $this->assertContains('remove_room', $this->adapter->allowedOperations());
    }

    public function test_remove_room_has_operation_schema(): void
    {
        $schemas = $this->adapter->operationSchemas();
        $this->assertArrayHasKey('remove_room', $schemas);
        $this->assertArrayHasKey('room', $schemas['remove_room']['args']);
    }
}

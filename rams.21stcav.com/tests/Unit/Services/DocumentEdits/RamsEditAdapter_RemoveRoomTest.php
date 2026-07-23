<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\Adapters\RamsEditAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pure array-in / array-out tests for RamsEditAdapter::applyRemoveRoom.
 *
 * The op filters `reviewed_data.room_overviews[]` by case-insensitive room
 * name match. That's the single source of truth the RAMS regen pipeline
 * reads (RamsBuilderService derives per-room method statement steps,
 * hazards, equipment scope and the top-level rooms[] from it), so removing
 * a room here excludes it from every downstream section on next regen.
 *
 * Introduced 2026-07-23 (quick task 260723-rr1) after diagnosing that
 * "exclude Saffron" was being silently mapped to add_exclusion (which just
 * appends free text to the scope-exclusions clause and doesn't affect
 * which rooms are generated).
 */
class RamsEditAdapter_RemoveRoomTest extends TestCase
{
    private RamsEditAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new RamsEditAdapter();
    }

    private function payloadWith(array $roomNames): array
    {
        return [
            'reviewed_data' => [
                'room_overviews' => array_map(
                    fn (string $r) => ['room' => $r, 'overview' => "Overview for {$r}"],
                    $roomNames,
                ),
            ],
        ];
    }

    public function test_remove_room_filters_named_room(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Saffron']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['reviewed_data']['room_overviews'], 'room');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_is_case_insensitive(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        // Real-world case — parser produced ALL-CAPS section headers.
        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'SAFFRON']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['reviewed_data']['room_overviews'], 'room');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_is_whitespace_tolerant(): void
    {
        $payload = $this->payloadWith(['Oregano', 'Cinnamon', 'Saffron']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => '  Saffron  ']);

        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['reviewed_data']['room_overviews'], 'room');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_reindexes_array_after_removal(): void
    {
        $payload = $this->payloadWith(['A', 'B', 'C', 'D']);

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'B']);

        $rooms = $result['payload']['reviewed_data']['room_overviews'];
        // 0-based sequential keys — no gap where 'B' was.
        $this->assertSame([0, 1, 2], array_keys($rooms));
        $this->assertSame(['A', 'C', 'D'], array_column($rooms, 'room'));
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

        // Chat retries shouldn't error — a repeated apply must succeed even
        // after the room is already gone.
        $this->assertTrue($result['ok']);
        $remaining = array_column($result['payload']['reviewed_data']['room_overviews'], 'room');
        $this->assertSame(['Oregano', 'Cinnamon'], $remaining);
    }

    public function test_remove_room_survives_missing_room_overviews_key(): void
    {
        // Documents that never had rooms recorded (edge case) should not crash.
        $payload = ['reviewed_data' => []];

        $result = $this->adapter->applyOperation($payload, ['op' => 'remove_room', 'room' => 'Anything']);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['payload']['reviewed_data']['room_overviews']);
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
        // Must clearly discourage the AI from picking add_exclusion for this intent.
        $this->assertStringContainsString(
            'add_exclusion',
            $schemas['remove_room']['notes'] ?? '',
            'Schema notes must warn the AI not to reach for add_exclusion',
        );
    }
}

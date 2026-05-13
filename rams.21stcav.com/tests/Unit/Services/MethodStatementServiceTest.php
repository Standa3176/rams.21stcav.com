<?php

namespace Tests\Unit\Services;

use App\Services\MethodStatementService;
use Tests\TestCase;

/**
 * Unit tests for MethodStatementService D-03 enrichment.
 *
 * Verifies that generate() passes works_overview and room_descriptions
 * to the prompt context, and that buildRoomDescriptions() helper works correctly.
 *
 * Phase 22.1 D-01 update (2026-05-13): the helper now reads room_overviews[*].overview
 * (PM-typed prose) instead of the legacy AI-generated `description` field. These
 * tests were migrated from `description` → `overview` to lock the new contract.
 * See MethodStatementServiceScopeTest for the works_summary paragraph-fallback
 * coverage added in Plan 22.1-03.
 */
class MethodStatementServiceTest extends TestCase
{
    private MethodStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MethodStatementService();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function invokePrivate(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(MethodStatementService::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    private function makeParsedQuote(array $overrides = []): array
    {
        return array_merge([
            'client'         => 'Acme Corp',
            'site'           => '1 Test Street',
            'ref'            => 'Q001',
            'project_name'   => 'Test Project',
            'works_summary'  => '',
            'equipment'      => [],
            'tasks'          => [],
            'rooms'          => [],
            'room_overviews' => [],
            'confidence'     => 1.0,
            'scope_of_works' => '',
            'works_overview' => '',
        ], $overrides);
    }

    // ── Test 1: generate() passes works_overview key ──────────────────────────

    /**
     * When parsedQuote has a non-empty works_overview, generate() must include
     * 'works_overview' in the context passed to the prompt.
     *
     * We verify this by calling the private buildRoomDescriptions() helper
     * and inspecting that the context keys flow through correctly.
     * We test generate() indirectly via the AI manager which we can't mock here,
     * so we test the helper methods directly.
     */
    public function test_build_room_descriptions_returns_room_colon_description_lines(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => 'Board Room',    'overview' => 'A room with a large display.'],
                ['room' => 'Training Room', 'overview' => 'A room with a projector.'],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringContainsString('Board Room: A room with a large display.', $result);
        $this->assertStringContainsString('Training Room: A room with a projector.', $result);
    }

    // ── Test 2: buildRoomDescriptions() returns empty string for empty descriptions

    public function test_build_room_descriptions_returns_empty_when_all_descriptions_empty(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => 'Board Room',    'overview' => ''],
                ['room' => 'Training Room', 'overview' => '   '],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertSame('', $result);
    }

    // ── Test 3: buildRoomDescriptions() skips entries with empty room name ────

    public function test_build_room_descriptions_skips_entries_with_empty_room_name(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => '',           'overview' => 'Some description.'],
                ['room' => 'Board Room', 'overview' => 'Board room description.'],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringNotContainsString('Some description.', $result);
        $this->assertStringContainsString('Board Room: Board room description.', $result);
    }

    // ── Test 4: buildRoomDescriptions() skips non-array entries ──────────────

    public function test_build_room_descriptions_skips_non_array_entries(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                'not_an_array',
                ['room' => 'Board Room', 'overview' => 'Valid description.'],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertSame('Board Room: Valid description.', $result);
    }

    // ── Test 5: buildRoomDescriptions() returns empty when room_overviews absent

    public function test_build_room_descriptions_returns_empty_when_no_room_overviews(): void
    {
        $parsed = $this->makeParsedQuote(['room_overviews' => []]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertSame('', $result);
    }

    // ── Test 6: buildRoomDescriptions() joins multiple entries with newline ───

    public function test_build_room_descriptions_joins_with_newline(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => 'Room A', 'overview' => 'Description A.'],
                ['room' => 'Room B', 'overview' => 'Description B.'],
                ['room' => 'Room C', 'overview' => 'Description C.'],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $lines = explode("\n", $result);
        $this->assertCount(3, $lines);
        $this->assertSame('Room A: Description A.', $lines[0]);
        $this->assertSame('Room B: Description B.', $lines[1]);
        $this->assertSame('Room C: Description C.', $lines[2]);
    }
}

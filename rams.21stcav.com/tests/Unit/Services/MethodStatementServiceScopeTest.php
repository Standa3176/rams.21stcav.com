<?php

namespace Tests\Unit\Services;

use App\Services\MethodStatementService;
use Tests\TestCase;

/**
 * Phase 22.1 Plan 03 Task 2 — locks the D-01 + D-07 input-side changes
 * in MethodStatementService:
 *
 *   - buildRoomDescriptions() reads room_overviews[*].overview (NOT
 *     description). When overview is empty, falls back to joining the
 *     works_summary bullets into a single paragraph (D-01).
 *   - buildRoomOverviewSummary() drops the `?? $row['summary']` fallback.
 *     works_summary is the sole canonical source. A row with the legacy
 *     summary populated but works_summary empty produces NO output (D-07).
 *
 * Mirrors the existing MethodStatementServiceTest reflection pattern so
 * private helpers can be exercised without an end-to-end AI call.
 *
 * @see app/Services/MethodStatementService.php
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-01 / D-07
 */
class MethodStatementServiceScopeTest extends TestCase
{
    private MethodStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MethodStatementService();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

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

    // ══════════════════════════════════════════════════════════════════════════
    // D-01: buildRoomDescriptions reads `overview` not `description`
    // ══════════════════════════════════════════════════════════════════════════

    public function test_buildRoomDescriptions_reads_overview_field_not_description(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                [
                    'room'        => 'Boardroom',
                    'overview'    => 'PM prose for Boardroom describes the install scope.',
                    // Legacy description should be IGNORED now.
                    'description' => 'LEGACY AI prose that should not be read.',
                ],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringContainsString(
            'Boardroom: PM prose for Boardroom describes the install scope.',
            $result,
            'D-01: must read room_overviews[*].overview',
        );
        $this->assertStringNotContainsString(
            'LEGACY AI prose',
            $result,
            'D-01: must NOT read room_overviews[*].description anymore',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-01: works_summary paragraph fallback when overview empty
    // ══════════════════════════════════════════════════════════════════════════

    public function test_buildRoomDescriptions_falls_back_to_works_summary_paragraph_when_overview_empty(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                [
                    'room'          => 'Cinnamon',
                    'overview'      => '',
                    'works_summary' => "- Install 98\" display\n- Deploy Crestron Flex\n- Provision power and data",
                ],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringContainsString('Cinnamon:', $result);
        $this->assertStringContainsString('Install 98', $result);
        $this->assertStringContainsString('Deploy Crestron Flex', $result);
        $this->assertStringContainsString('Provision power and data', $result);
        // The works_summary should be joined as a paragraph — no leading bullet markers.
        $this->assertStringNotContainsString('- Install', $result,
            'works_summary fallback must strip leading "- " bullet markers');
    }

    public function test_buildRoomDescriptions_falls_back_to_works_summary_returns_empty_when_both_empty(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => 'Empty', 'overview' => '', 'works_summary' => ''],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertSame('', $result, 'Both overview and works_summary empty → no output for that row.');
    }

    public function test_buildRoomDescriptions_overview_wins_when_both_populated(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                [
                    'room'          => 'Boardroom',
                    'overview'      => 'PM-typed prose has priority.',
                    'works_summary' => '- Bullet that should not appear in the description',
                ],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringContainsString('PM-typed prose has priority.', $result);
        $this->assertStringNotContainsString('Bullet that should not appear', $result);
    }

    public function test_buildRoomDescriptions_skips_entries_with_empty_room_name(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                ['room' => '', 'overview' => 'has prose but no room name'],
                ['room' => 'Valid', 'overview' => 'has prose with name'],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomDescriptions', [$parsed]);

        $this->assertStringContainsString('Valid: has prose with name', $result);
        $this->assertStringNotContainsString('has prose but no room name', $result);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-07: buildRoomOverviewSummary drops the legacy summary fallback
    // ══════════════════════════════════════════════════════════════════════════

    public function test_buildRoomOverviewSummary_no_legacy_summary_fallback(): void
    {
        // Row has legacy summary populated but works_summary empty.
        // Before D-07: would have read $row['summary'] as fallback.
        // After D-07: works_summary empty → row produces no bullet block.
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                [
                    'room'          => 'LegacyRoom',
                    'overview'      => '',
                    'works_summary' => '',
                    'summary'       => '- LEGACY bullet that should NOT be read',
                ],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomOverviewSummary', [$parsed]);

        $this->assertStringNotContainsString(
            'LEGACY bullet',
            $result,
            'D-07: $row[\'summary\'] fallback must be dropped — legacy summary key is no longer read',
        );
    }

    public function test_buildRoomOverviewSummary_reads_works_summary_only(): void
    {
        $parsed = $this->makeParsedQuote([
            'room_overviews' => [
                [
                    'room'          => 'Boardroom',
                    'overview'      => '',
                    'works_summary' => "- Install 98\" display",
                ],
            ],
        ]);

        $result = $this->invokePrivate('buildRoomOverviewSummary', [$parsed]);

        $this->assertStringContainsString('Boardroom:', $result);
        $this->assertStringContainsString('Install 98', $result);
    }
}

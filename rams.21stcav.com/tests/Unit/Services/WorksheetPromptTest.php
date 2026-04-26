<?php

namespace Tests\Unit\Services;

use App\Core\AI\Prompts\WorksheetPrompt;
use Tests\TestCase;

/**
 * Unit tests for WorksheetPrompt D-04 enrichment.
 *
 * Verifies that ROOM DESCRIPTION and PROJECT OVERVIEW context blocks are
 * included/omitted depending on whether the room values are populated.
 */
class WorksheetPromptTest extends TestCase
{
    private function makeProjectMeta(array $overrides = []): array
    {
        return array_merge([
            'name'        => 'Test Project',
            'client_name' => 'Acme Corp',
        ], $overrides);
    }

    private function makeRoom(array $overrides = []): array
    {
        return array_merge([
            'room_name'   => 'Board Room',
            'equipment'   => [],
            'description' => '',
            'works_overview' => '',
        ], $overrides);
    }

    // ── Test 1: ROOM DESCRIPTION block included when description non-empty ────

    public function test_build_includes_room_description_block_when_non_empty(): void
    {
        $room = $this->makeRoom([
            'description' => 'A large boardroom with an 86-inch display and conferencing system.',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $this->assertStringContainsString('ROOM DESCRIPTION (use for context only):', $built);
        $this->assertStringContainsString('A large boardroom with an 86-inch display and conferencing system.', $built);
    }

    // ── Test 2: PROJECT OVERVIEW block included when works_overview non-empty ─

    public function test_build_includes_project_overview_block_when_non_empty(): void
    {
        $room = $this->makeRoom([
            'works_overview' => 'Supply and install AV systems across three meeting rooms.',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $this->assertStringContainsString('PROJECT OVERVIEW (use for context only):', $built);
        $this->assertStringContainsString('Supply and install AV systems across three meeting rooms.', $built);
    }

    // ── Test 3: both blocks omitted when values are empty ─────────────────────

    public function test_build_omits_both_blocks_when_values_empty(): void
    {
        $room = $this->makeRoom([
            'description'    => '',
            'works_overview' => '',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $this->assertStringNotContainsString('ROOM DESCRIPTION (use for context only):', $built);
        $this->assertStringNotContainsString('PROJECT OVERVIEW (use for context only):', $built);
    }

    // ── Test 4: INSTRUCTIONS constraint text unchanged ────────────────────────

    public function test_instructions_constraint_text_unchanged(): void
    {
        $room  = $this->makeRoom();
        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        // Assert the "do not invent" rule is present in some form. Exact
        // wording shifts as the prompt evolves; the contract we lock is
        // the no-invention guarantee.
        $this->assertMatchesRegularExpression(
            '/Base steps ONLY.*do not invent/is',
            $built
        );
    }

    // ── Test 5: blocks appear before INSTRUCTIONS section ────────────────────

    public function test_description_block_appears_before_instructions(): void
    {
        $room = $this->makeRoom([
            'description' => 'A room with a display.',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $descPos         = strpos($built, 'ROOM DESCRIPTION');
        $instructionsPos = strpos($built, 'INSTRUCTIONS:');

        $this->assertNotFalse($descPos,         'ROOM DESCRIPTION block not found');
        $this->assertNotFalse($instructionsPos, 'INSTRUCTIONS section not found');
        $this->assertLessThan($instructionsPos, $descPos, 'ROOM DESCRIPTION must appear before INSTRUCTIONS');
    }

    // ── Test 6: overview block appears before INSTRUCTIONS section ────────────

    public function test_overview_block_appears_before_instructions(): void
    {
        $room = $this->makeRoom([
            'works_overview' => 'Project-level summary.',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $overviewPos     = strpos($built, 'PROJECT OVERVIEW');
        $instructionsPos = strpos($built, 'INSTRUCTIONS:');

        $this->assertNotFalse($overviewPos,     'PROJECT OVERVIEW block not found');
        $this->assertNotFalse($instructionsPos, 'INSTRUCTIONS section not found');
        $this->assertLessThan($instructionsPos, $overviewPos, 'PROJECT OVERVIEW must appear before INSTRUCTIONS');
    }

    // ── Test 7: both blocks included when both values populated ───────────────

    public function test_build_includes_both_blocks_when_both_populated(): void
    {
        $room = $this->makeRoom([
            'description'    => 'A boardroom with conferencing.',
            'works_overview' => 'Three-room AV install.',
        ]);

        $prompt = WorksheetPrompt::forRoom($room, $this->makeProjectMeta());
        $built  = $prompt->build();

        $this->assertStringContainsString('ROOM DESCRIPTION (use for context only):', $built);
        $this->assertStringContainsString('PROJECT OVERVIEW (use for context only):', $built);
    }
}

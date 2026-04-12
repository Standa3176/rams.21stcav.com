<?php

namespace Tests\Unit\Services;

use App\Core\AI\Prompts\MethodStatementPrompt;
use Tests\TestCase;

/**
 * Unit tests for MethodStatementPrompt D-03 enrichment.
 *
 * Verifies that the prompt includes/omits works_overview and room_descriptions
 * context blocks depending on whether the values are populated.
 */
class MethodStatementPromptTest extends TestCase
{
    // ── Test 1: works_overview appears in built prompt when non-empty ─────────

    public function test_build_includes_project_overview_when_works_overview_non_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'   => 'Test Site',
            'scope_summary'  => 'Supply and install AV.',
            'activities'     => [],
            'works_overview' => 'This is a two-sentence project overview for the board room.',
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Project overview:', $built);
        $this->assertStringContainsString('This is a two-sentence project overview for the board room.', $built);
    }

    // ── Test 2: room_descriptions appears in built prompt when non-empty ──────

    public function test_build_includes_room_descriptions_when_non_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'      => 'Test Site',
            'scope_summary'     => 'Supply and install AV.',
            'activities'        => [],
            'room_descriptions' => "Board Room: A room with a large display.\nTraining Room: A room with a projector.",
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Room descriptions:', $built);
        $this->assertStringContainsString('Board Room: A room with a large display.', $built);
        $this->assertStringContainsString('Training Room: A room with a projector.', $built);
    }

    // ── Test 3: both lines omitted when context values are empty ─────────────

    public function test_build_omits_both_lines_when_context_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'      => 'Test Site',
            'scope_summary'     => 'Supply and install AV.',
            'activities'        => [],
            'works_overview'    => '',
            'room_descriptions' => '',
        ]);

        $built = $prompt->build();

        $this->assertStringNotContainsString('Project overview:', $built);
        $this->assertStringNotContainsString('Room descriptions:', $built);
    }

    // ── Test 4: Phase 4 instruction references room descriptions ─────────────

    public function test_phase_4_instruction_references_room_descriptions(): void
    {
        $prompt = new MethodStatementPrompt();
        $built  = $prompt->build();

        $this->assertStringContainsString('room descriptions', $built);
    }

    // ── Test 5: works_overview omitted but room_descriptions included ─────────

    public function test_build_includes_only_room_descriptions_when_works_overview_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'      => 'Test Site',
            'scope_summary'     => 'AV install.',
            'activities'        => [],
            'works_overview'    => '',
            'room_descriptions' => 'Board Room: A large boardroom.',
        ]);

        $built = $prompt->build();

        $this->assertStringNotContainsString('Project overview:', $built);
        $this->assertStringContainsString('Room descriptions:', $built);
        $this->assertStringContainsString('Board Room: A large boardroom.', $built);
    }

    // ── Test 6: room_descriptions omitted but works_overview included ─────────

    public function test_build_includes_only_works_overview_when_room_descriptions_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'      => 'Test Site',
            'scope_summary'     => 'AV install.',
            'activities'        => [],
            'works_overview'    => 'Two-sentence project overview.',
            'room_descriptions' => '',
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Project overview:', $built);
        $this->assertStringContainsString('Two-sentence project overview.', $built);
        $this->assertStringNotContainsString('Room descriptions:', $built);
    }
}

<?php

namespace Tests\Unit\Prompts;

use App\Core\AI\Prompts\SurveyQuestionsPrompt;
use Tests\TestCase;

/**
 * Contract tests for SurveyQuestionsPrompt.
 *
 * These tests are RED in Wave 0 — production class does not yet exist.
 * Tests will error (class not found) until Plan 02 creates the prompt.
 *
 * Contract:
 *   - systemMessage() contains 'pre-install' and 'British English'
 *   - maxTokens() returns 1024
 *   - temperature() returns 0.2
 *   - build() with context returns string containing 'questions' keyword
 */
class SurveyQuestionsPromptTest extends TestCase
{
    // ─── Test 1: systemMessage contains 'pre-install' (case insensitive) ──────

    /**
     * System message must reference pre-install checks.
     */
    public function test_system_message_contains_pre_install(): void
    {
        $prompt = new SurveyQuestionsPrompt();

        $this->assertStringContainsStringIgnoringCase('pre-install', $prompt->systemMessage());
    }

    // ─── Test 2: systemMessage contains 'British English' ─────────────────────

    /**
     * System message must instruct British English usage.
     */
    public function test_system_message_contains_british_english(): void
    {
        $prompt = new SurveyQuestionsPrompt();

        $this->assertStringContainsString('British English', $prompt->systemMessage());
    }

    // ─── Test 3: maxTokens returns 1024 ──────────────────────────────────────

    /**
     * maxTokens must return exactly 1024.
     */
    public function test_max_tokens_returns_1024(): void
    {
        $prompt = new SurveyQuestionsPrompt();

        $this->assertSame(1024, $prompt->maxTokens());
    }

    // ─── Test 4: temperature returns 0.2 ─────────────────────────────────────

    /**
     * temperature must return exactly 0.2.
     */
    public function test_temperature_returns_0_2(): void
    {
        $prompt = new SurveyQuestionsPrompt();

        $this->assertSame(0.2, $prompt->temperature());
    }

    // ─── Test 5: build() output contains 'questions' keyword ─────────────────

    /**
     * build() with all context keys must return a string containing the
     * 'questions' keyword in its JSON output instruction.
     */
    public function test_build_with_all_context_keys_returns_string_containing_questions(): void
    {
        $prompt = new SurveyQuestionsPrompt();
        $prompt = $prompt->withContext([
            'solution_type_slug' => 'conference-room',
            'checklist_lines'    => "- Does the room have a projector?\n- Is there a presentation screen?",
            'equipment'          => 'Display screen, HDMI matrix',
            'works_overview'     => 'Supply and install AV in a conference room.',
            'room_description'   => 'A meeting room with an 86-inch display.',
            'room_summary'       => 'Conference room AV install.',
        ]);

        $built = $prompt->build();

        $this->assertIsString($built);
        $this->assertStringContainsString('questions', $built);
    }
}

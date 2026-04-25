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

    // ─── Test 6: equipment list is rendered with names + quantities ───────────

    /**
     * The build() output must include each equipment item by its short name
     * with a quantity, so the AI can reference items specifically rather than
     * via vague phrases like "the display".
     */
    public function test_build_renders_equipment_list_with_names_and_quantities(): void
    {
        $prompt = (new SurveyQuestionsPrompt())->withContext([
            'solution_type_slug' => 'video-conferencing',
            'checklist_lines'    => ['Solid wall confirmed at display height'],
            'equipment'          => [
                ['quantity' => 1, 'name' => 'Cisco Room Kit EQ',  'category' => 'VC'],
                ['quantity' => 2, 'name' => 'NEC ME552 Display', 'category' => 'Display'],
            ],
            'works_overview'   => '',
            'room_description' => '',
            'room_summary'     => '',
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Cisco Room Kit EQ', $built);
        $this->assertStringContainsString('NEC ME552 Display', $built);
        $this->assertStringContainsString('1x',                $built); // qty 1 of VC bar
        $this->assertStringContainsString('2x',                $built); // qty 2 of displays
    }

    // ─── Test 7: prompt requires per-item, dimension-anchored questions ───────

    /**
     * The new prompt instructs the AI to:
     *   - reference equipment by specific name (not "the display")
     *   - cover the install dimensions: mounting, power, network, cable
     *     routing, sight lines/coverage, existing infrastructure
     *   - generate 6–10 questions (not 4–8)
     * Lock those instructions so a future edit can't silently dilute them.
     */
    public function test_prompt_instructs_per_equipment_questions_across_six_dimensions(): void
    {
        $prompt = (new SurveyQuestionsPrompt())->withContext([
            'solution_type_slug' => 'video-conferencing',
            'checklist_lines'    => ['Solid wall confirmed'],
            'equipment'          => [['quantity' => 1, 'name' => 'NEC ME552']],
            'works_overview'     => '',
            'room_description'   => '',
            'room_summary'       => '',
        ]);

        $built = $prompt->build();

        // Per-item naming requirement
        $this->assertMatchesRegularExpression(
            '/(by short.*name|reference.*equipment.*by|name the item|specific.*name)/i',
            $built,
            'Prompt must instruct the AI to name specific equipment items'
        );

        // Six dimensions must be present
        $this->assertMatchesRegularExpression('/\bMOUNTING\b/i',           $built, 'Mounting dimension missing');
        $this->assertMatchesRegularExpression('/\bPOWER\b/i',              $built, 'Power dimension missing');
        $this->assertMatchesRegularExpression('/\bNETWORK\b/i',            $built, 'Network dimension missing');
        $this->assertMatchesRegularExpression('/\bCABLE ROUTING\b/i',      $built, 'Cable routing dimension missing');
        $this->assertMatchesRegularExpression('/\bSIGHT LINES\b/i',        $built, 'Sight lines / coverage dimension missing');
        $this->assertMatchesRegularExpression('/\bEXISTING INFRASTRUCTURE\b/i', $built, 'Existing infrastructure dimension missing');

        // Quantity range bumped to 6–10
        $this->assertStringContainsString('6–10', $built);
    }

    // ─── Test 8a: prompt enforces a per-question word cap ────────────────────

    /**
     * The prompt must instruct the AI to keep each question short (<= 18 words,
     * one sentence). Engineers tick on a tablet on-site; long contract-clause
     * sentences are unreadable in that flow.
     */
    public function test_prompt_enforces_short_question_word_cap(): void
    {
        $prompt = (new SurveyQuestionsPrompt())->withContext([
            'solution_type_slug' => 'general',
            'checklist_lines'    => [],
            'equipment'          => [['quantity' => 1, 'name' => 'NEC ME552']],
            'works_overview'     => '',
            'room_description'   => '',
            'room_summary'       => '',
        ]);

        $built = $prompt->build();

        $this->assertMatchesRegularExpression(
            '/(18 words or fewer|max(?:imum)?\s+18\s+words|KEEP IT SHORT)/i',
            $built,
            'Prompt must impose a hard length cap on questions'
        );

        // Anti-padding rules
        $this->assertMatchesRegularExpression(
            '/(no nested clauses|no.*"prior to works commencing"|do not pad|no contract.*clause)/i',
            $built,
            'Prompt must explicitly ban padding boilerplate'
        );
    }

    // ─── Test 9: prompt explicitly forbids vague references ──────────────────

    /**
     * The prompt must explicitly forbid vague phrasing like "the display"
     * in favour of concrete model names. Lock that contract.
     */
    public function test_prompt_forbids_vague_equipment_references(): void
    {
        $prompt = (new SurveyQuestionsPrompt())->withContext([
            'solution_type_slug' => 'general',
            'checklist_lines'    => [],
            'equipment'          => [['quantity' => 1, 'name' => 'NEC ME552']],
            'works_overview'     => '',
            'room_description'   => '',
            'room_summary'       => '',
        ]);

        $built = $prompt->build();

        $this->assertMatchesRegularExpression(
            '/(do NOT use vague|not.*"the display"|not "the equipment")/i',
            $built,
            'Prompt must instruct the AI to avoid vague references like "the display"'
        );
    }
}

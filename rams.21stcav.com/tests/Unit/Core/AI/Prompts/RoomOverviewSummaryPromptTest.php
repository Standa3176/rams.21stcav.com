<?php

namespace Tests\Unit\Core\AI\Prompts;

use App\Core\AI\Prompts\RoomOverviewSummaryPrompt;
use Tests\TestCase;

/**
 * Phase 22.1 Plan 03 Task 2 — locks the D-01 schema change to
 * RoomOverviewSummaryPrompt: the prompt MUST NOT instruct the AI to
 * produce a `description` field. The remaining single output is `summary`
 * (bullets only).
 *
 * Rationale (D-01): the engineer-typed `overview` is already a human-
 * authored prose paragraph that downstream services consume. The
 * AI-generated `description` was redundant AND invisible to engineers
 * (no edit UI exposed it), violating the CLAUDE.md "AI output must be
 * reviewable" principle.
 *
 * Test failure here means a regression has re-added description either:
 *   - in the systemMessage() instructions, or
 *   - in the build() example JSON schema.
 *
 * @see app/Core/AI/Prompts/RoomOverviewSummaryPrompt.php
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-01
 */
class RoomOverviewSummaryPromptTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════════
    // D-01 contract lock — systemMessage
    // ══════════════════════════════════════════════════════════════════════════

    public function test_system_message_contains_no_description_field_instructions(): void
    {
        $prompt = new RoomOverviewSummaryPrompt();
        $sys    = $prompt->systemMessage();

        $this->assertStringNotContainsString(
            'description',
            strtolower($sys),
            'D-01: RoomOverviewSummaryPrompt must not instruct AI to produce a description field.',
        );
    }

    public function test_system_message_still_describes_bullet_output(): void
    {
        $prompt = new RoomOverviewSummaryPrompt();
        $sys    = $prompt->systemMessage();

        // The bullet-list instructions remain — `summary` is the single
        // surviving output field.
        $this->assertStringContainsString('bullet', strtolower($sys),
            'systemMessage must still describe the bullet output (the remaining single output).');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-01 contract lock — build() example schema
    // ══════════════════════════════════════════════════════════════════════════

    public function test_build_output_does_not_request_description_in_example_schema(): void
    {
        $prompt = (new RoomOverviewSummaryPrompt())
            ->withContext(['rooms' => [['room' => 'X', 'overview' => 'test']]]);
        $built = $prompt->build();

        $this->assertStringNotContainsString(
            '"description"',
            $built,
            'D-01: build() must not include "description" in the example JSON schema.',
        );
        $this->assertStringContainsString(
            '"summary"',
            $built,
            'build() must still include "summary" as the canonical AI output field.',
        );
    }

    public function test_build_with_empty_context_does_not_error_and_still_excludes_description(): void
    {
        $prompt = new RoomOverviewSummaryPrompt();
        $built  = $prompt->build();

        $this->assertIsString($built);
        $this->assertStringNotContainsString('"description"', $built);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Sanity — temperature + maxTokens stable
    // ══════════════════════════════════════════════════════════════════════════

    public function test_prompt_metadata_unchanged_by_schema_trim(): void
    {
        $prompt = new RoomOverviewSummaryPrompt();
        $this->assertSame(2000, $prompt->maxTokens());
        $this->assertSame(0.15, $prompt->temperature());
    }
}

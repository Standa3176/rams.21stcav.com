<?php

namespace Tests\Unit\Services;

use App\Core\AI\Prompts\MethodStatementPrompt;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 5 — MethodStatementPrompt must embed
 * site_conditions into the built prompt and its systemMessage must instruct
 * the model to cite the fields verbatim (with a "do NOT invent" guard).
 */
class MethodStatementSiteConditionsTest extends TestCase
{
    // ── systemMessage instructs the model to use site_conditions ────────────

    public function test_system_message_mentions_site_conditions_rule(): void
    {
        $prompt = new MethodStatementPrompt();
        $msg = $prompt->systemMessage();

        $this->assertStringContainsString('site_conditions', $msg,
            'systemMessage must name the site_conditions block explicitly');
        $this->assertStringContainsString('cite the relevant conditions', $msg,
            'systemMessage must instruct the model to cite fields per room');
        $this->assertStringContainsString('Do NOT invent', $msg,
            'systemMessage must include the "Do NOT invent" injection-defence guard');
        $this->assertStringContainsString('mounting_heights', $msg,
            'systemMessage must name mounting_heights as a citable field');
        $this->assertStringContainsString('wall_construction', $msg,
            'systemMessage must name wall_construction as a citable field');
        $this->assertStringContainsString('brackets_required', $msg,
            'systemMessage must name brackets_required as a citable field');
    }

    // ── build() emits Site conditions block when non-empty ──────────────────

    public function test_build_includes_site_conditions_block_when_populated(): void
    {
        $prompt = (new MethodStatementPrompt())->withContext([
            'site_address'    => 'Test Site',
            'scope_summary'   => 'AV install',
            'activities'      => [],
            'site_conditions' => [
                'Boardroom' => [
                    'mounting_heights'         => ['display' => 1900],
                    'wall_construction'        => ['type' => 'Plasterboard on metal stud'],
                    'wall_needs_reinforcement' => true,
                    'brackets_required'        => [['type' => 'Chief PPP-M2AS']],
                ],
            ],
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Site conditions', $built,
            'built prompt must include a Site conditions block when populated');
        $this->assertStringContainsString('Boardroom', $built);
        $this->assertStringContainsString('1900', $built);
        $this->assertStringContainsString('Plasterboard on metal stud', $built);
        $this->assertStringContainsString('Chief PPP-M2AS', $built);
        $this->assertStringContainsString('do NOT invent', $built);
    }

    // ── build() omits the block entirely when empty ─────────────────────────

    public function test_build_omits_site_conditions_block_when_empty(): void
    {
        $prompt = (new MethodStatementPrompt())->withContext([
            'site_address'    => 'Test Site',
            'scope_summary'   => 'AV install',
            'activities'      => [],
            'site_conditions' => [],
        ]);

        $built = $prompt->build();

        $this->assertStringNotContainsString('Site conditions', $built,
            'built prompt must omit the block entirely when no engineer_feedback is provided');
    }
}

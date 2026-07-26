<?php

namespace Tests\Unit\Services;

use App\Core\AI\Prompts\OmManualPrompt;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 6 — OmManualPrompt::forContent() must embed
 * site_conditions into the built prompt and its systemMessage must instruct
 * the model to cite the fields verbatim (with a "Do NOT invent" guard).
 */
class OmManualPromptSiteConditionsTest extends TestCase
{
    // ── systemMessage instructs the model to use site_conditions ────────────

    public function test_content_system_message_mentions_site_conditions_rule(): void
    {
        $prompt = OmManualPrompt::forContent();
        $msg = $prompt->systemMessage();

        $this->assertStringContainsString('site_conditions', $msg,
            'systemMessage must name the site_conditions block explicitly');
        $this->assertStringContainsString('cite the relevant', $msg,
            'systemMessage must instruct the model to cite fields per room');
        $this->assertStringContainsString('Do NOT invent', $msg,
            'systemMessage must include the "Do NOT invent" injection-defence guard');
        $this->assertStringContainsString('mounting_heights', $msg,
            'systemMessage must reference mounting_heights → install notes mapping');
        $this->assertStringContainsString('finished floor level', $msg,
            'systemMessage must specify FFL for mounting-height citation');
        $this->assertStringContainsString('access_notes', $msg,
            'systemMessage must reference access_notes → maintenance-access mapping');
    }

    // ── build() emits Site conditions block when non-empty ──────────────────

    public function test_content_build_includes_site_conditions_when_populated(): void
    {
        $prompt = OmManualPrompt::forContent();

        $built = $prompt->build([
            'project_name'    => 'Test project',
            'client_name'     => 'Test client',
            'site_address'    => 'Test site',
            'rooms'           => [],
            'site_conditions' => [
                'Boardroom' => [
                    'mounting_heights' => ['display' => 1900],
                    'wall_construction'=> ['type' => 'Plasterboard on metal stud'],
                    'access_notes'     => 'ceiling grid 600×600, no asbestos flag',
                ],
            ],
        ]);

        $this->assertStringContainsString('SITE CONDITIONS', $built);
        $this->assertStringContainsString('Boardroom', $built);
        $this->assertStringContainsString('1900', $built);
        $this->assertStringContainsString('Plasterboard on metal stud', $built);
        $this->assertStringContainsString('ceiling grid 600', $built);
        $this->assertStringContainsString('do NOT invent', $built);
    }

    // ── build() omits the block entirely when empty ─────────────────────────

    public function test_content_build_omits_site_conditions_when_empty(): void
    {
        $prompt = OmManualPrompt::forContent();

        $built = $prompt->build([
            'project_name'    => 'Test project',
            'client_name'     => 'Test client',
            'site_address'    => 'Test site',
            'rooms'           => [],
            'site_conditions' => [],
        ]);

        $this->assertStringNotContainsString('SITE CONDITIONS', $built);
    }
}

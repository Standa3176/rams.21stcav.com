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

    // ═══════════════════════════════════════════════════════════════════════
    // 260725-rd1 — Per-room granularity, kit-specific detail, RA-ID cross-refs
    // ═══════════════════════════════════════════════════════════════════════

    public function test_system_message_enforces_per_room_granularity(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('Per-room granularity', $system,
            '260725-rd1: systemMessage missing per-room granularity rule.');
        $this->assertStringContainsString('name the specific room', $system,
            '260725-rd1: systemMessage does not instruct the AI to name specific rooms.');
    }

    public function test_system_message_enforces_kit_specific_make_and_model(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('Kit-specific detail', $system,
            '260725-rd1: systemMessage missing kit-specific detail rule.');
        $this->assertStringContainsString('specific make + model', $system,
            '260725-rd1: systemMessage does not instruct the AI to use specific make + model.');
    }

    public function test_system_message_enforces_associated_risks_cross_reference(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('Associated Risks:', $system,
            '260725-rd1: systemMessage missing "Associated Risks:" cross-reference rule.');
        $this->assertStringContainsString('RA01', $system,
            '260725-rd1: systemMessage does not show the RA{NN} ID format.');
        $this->assertStringContainsString('Risk-ID cross-references', $system,
            '260725-rd1: systemMessage missing risk-ID cross-references rule heading.');
    }

    public function test_build_emits_risk_register_with_ra_ids_when_hazards_supplied(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address' => 'Test Site',
            'scope_summary' => 'AV install.',
            'activities'   => [],
            'hazards'      => [
                ['hazard' => 'Working at height'],
                ['hazard' => 'Manual handling'],
                ['hazard' => 'Electrical isolation'],
            ],
        ]);

        $built = $prompt->build();

        $this->assertStringContainsString('Risk register', $built,
            '260725-rd1: prompt missing "Risk register" context block.');
        // The hazard name is sentinel-wrapped (Audit M-04) so the RA-ID prefix
        // and the hazard name are separated by the wrap tags. Assert both
        // pieces appear on the same numbered line (RA-ID immediately followed
        // by the sentinel-wrapped name).
        $this->assertMatchesRegularExpression(
            '/RA01: .*Working at height/',
            $built,
            '260725-rd1: RA01 not emitted for first hazard.',
        );
        $this->assertMatchesRegularExpression(
            '/RA02: .*Manual handling/',
            $built,
            '260725-rd1: RA02 not emitted for second hazard.',
        );
        $this->assertMatchesRegularExpression(
            '/RA03: .*Electrical isolation/',
            $built,
            '260725-rd1: RA03 not emitted for third hazard.',
        );
    }

    public function test_build_omits_risk_register_when_hazards_empty(): void
    {
        $prompt = new MethodStatementPrompt();
        $prompt = $prompt->withContext([
            'site_address'  => 'Test Site',
            'scope_summary' => 'AV install.',
            'activities'    => [],
            'hazards'       => [],
        ]);

        $built = $prompt->build();

        // The block-header phrase (with the trailing "(use these RA-IDs...)")
        // must not appear when no hazards were supplied. The bare "Risk
        // register" phrase does appear in the requirements section as a
        // reference; that reference is not context leakage.
        $this->assertStringNotContainsString(
            'Risk register (use these RA-IDs verbatim when cross-referencing):',
            $built,
            '260725-rd1: Risk register context block emitted even though hazards[] is empty.',
        );
        // And no RA{NN}: prefix lines should appear.
        $this->assertDoesNotMatchRegularExpression(
            '/RA\d{2}:\s/',
            $built,
            '260725-rd1: RA-ID entries emitted even though hazards[] is empty.',
        );
    }

    public function test_build_body_instructs_each_phase_to_end_with_associated_risks_line(): void
    {
        $prompt = new MethodStatementPrompt();
        $built  = $prompt->build();

        $this->assertStringContainsString('final "Associated Risks:', $built,
            '260725-rd1: prompt body missing the "final Associated Risks" per-phase requirement.');
    }
}

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

    /**
     * 260817-r5e supersedes the 260725-rd1 assertion that used to live here.
     *
     * rd1 asked the AI for an "Associated Risks: RA01, …" line while
     * RamsComplianceUpgradeService independently derived its own — so every
     * rendered phase carried TWO lines with different RA-IDs. The
     * deterministic service is now the sole producer, so the prompt must
     * FORBID the line rather than require it. The real guarantee is the strip
     * in RamsComplianceUpgradeService (see MethodStatementAssociatedRisksTest);
     * this asserts the prompt no longer acts as a second producer.
     */
    public function test_system_message_forbids_the_ai_from_writing_risk_cross_references(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('never output an "Associated Risks" line', $system,
            '260817-r5e: systemMessage must explicitly forbid the AI from writing an Associated Risks line.');
        $this->assertStringNotContainsString('each phase MUST end with a final line', $system,
            '260817-r5e: the rd1 rule instructing the AI to emit an Associated Risks line has been reinstated — that recreates the two-producer defect.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 260817-r5e Item 4 — verbatim product identifiers
    //
    // 21CQ30960's quote distinguished GRAPHITE Rally mic pods (reused from
    // the Willen decommission) from new WHITE pods. The generated method
    // statement called the reused ones white throughout, so an engineer
    // picking to it takes the wrong item.
    //
    // This can only ever REDUCE paraphrase — the deterministic equipment
    // schedule remains the authoritative pick list.
    // ═══════════════════════════════════════════════════════════════════════

    public function test_system_message_requires_verbatim_product_identifiers(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('Verbatim product identifiers', $system,
            '260817-r5e Item 4: systemMessage missing the verbatim-identifier rule.');
        $this->assertStringContainsString('colour, finish, size, variant and supply-status qualifiers', $system,
            '260817-r5e Item 4: the rule must name the qualifiers that got dropped (colour was the one that bit).');
        $this->assertStringContainsString('Do not paraphrase', $system,
            '260817-r5e Item 4: systemMessage does not forbid paraphrasing item names.');
    }

    public function test_system_message_forbids_merging_similarly_named_variants(): void
    {
        $prompt = new MethodStatementPrompt();
        $system = $prompt->systemMessage();

        $this->assertStringContainsString('Never merge variants', $system,
            '260817-r5e Item 4: systemMessage missing the no-merging rule.');
        $this->assertStringContainsString('DISTINCT products', $system,
            '260817-r5e Item 4: the rule must state that two similarly-named items are distinct.');
        $this->assertStringContainsString('different physical unit', $system,
            '260817-r5e Item 4: the rule must cover the reused-vs-new case (decommission/retained vs new install).');
    }

    public function test_build_body_carries_the_verbatim_and_no_merge_rules(): void
    {
        $prompt = new MethodStatementPrompt();
        $built  = $prompt->build();

        $this->assertStringContainsString('Reproduce item names VERBATIM', $built,
            '260817-r5e Item 4: prompt body missing the verbatim-reproduction requirement.');
        $this->assertStringContainsString('are DIFFERENT products', $built,
            '260817-r5e Item 4: prompt body missing the similarly-named-variants requirement.');
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
        // 260817-r5e: header re-worded when the AI stopped being a producer of
        // the cross-reference. Asserting the OLD string here would pass even
        // with hazards supplied — i.e. prove nothing.
        $this->assertStringNotContainsString(
            'Risk register (context only — do NOT cite these RA-IDs in your output):',
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

    /**
     * 260817-r5e — inverse of the rd1 assertion this replaces. The prompt body
     * must not ask for a per-phase Associated Risks bullet; the deterministic
     * cross-reference in RamsComplianceUpgradeService owns that line.
     */
    public function test_build_body_forbids_a_per_phase_associated_risks_bullet(): void
    {
        $prompt = new MethodStatementPrompt();
        $built  = $prompt->build();

        $this->assertStringNotContainsString('final "Associated Risks:', $built,
            '260817-r5e: prompt body reinstates the "final Associated Risks" per-phase requirement — that is the second producer the fix removed.');
        $this->assertStringContainsString('Do NOT add an "Associated Risks" bullet', $built,
            '260817-r5e: prompt body missing the explicit prohibition on Associated Risks bullets.');
    }
}

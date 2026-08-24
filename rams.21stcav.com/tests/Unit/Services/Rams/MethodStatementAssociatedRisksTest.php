<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Quick task 260817-r5e Item 1 — ONE producer of the "Associated Risks" line.
 *
 * The 21CQ30960-OPS Rev 1.0 review found every §6.6 method-statement step
 * carrying TWO risk cross-reference lines with DIFFERENT RA-IDs: one written
 * by the AI (MethodStatementPrompt asked for it) and one derived by
 * RamsComplianceUpgradeService::crossReferenceMethodStatementRisks.
 *
 * The deterministic service is now the sole producer. These tests lock:
 *
 *   1. Model-authored "Associated Risks: …" bullets are stripped from
 *      phase steps — so no phase can render two lines. Fails if the prompt
 *      producer is reinstated AND the strip removed.
 *   2. The surviving line's RA-IDs all exist in the document's own risk
 *      register. Dangling references are the failure mode that matters, and
 *      the pre-fix code keyed off $h['id'] while both renderers label rows
 *      by array position.
 *   3. A step that merely mentions risks in prose is NOT stripped.
 *
 * Reflection exercises the private static directly — mirrors
 * RamsComplianceUpgradeServiceCacheTest.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Core/AI/Prompts/MethodStatementPrompt.php
 */
class MethodStatementAssociatedRisksTest extends TestCase
{
    private function crossReference(array $data): array
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, 'crossReferenceMethodStatementRisks');
        $m->setAccessible(true);

        return $m->invoke(null, $data);
    }

    /**
     * Three hazards whose names carry keywords the matcher recognises.
     * Ids are deliberately NON-SEQUENTIAL (4/9/11) — the shape produced when
     * RamsDataBuilderService::normalise drops an unlabelled hazard row but
     * keeps the survivors' original ids. Both renderers label the Ref column
     * from the ROW POSITION, so the only correct references here are
     * RA01/RA02/RA03.
     */
    private function hazards(): array
    {
        return [
            ['id' => 4,  'hazard' => 'Working at height',  'controls' => ['Use podium steps']],
            ['id' => 9,  'hazard' => 'Manual handling',    'controls' => ['Team lift']],
            ['id' => 11, 'hazard' => 'Electrical contact', 'controls' => ['Isolate and lock off']],
        ];
    }

    /**
     * Phase 26 Plan 06 — variable-length register regression (HAZ-01..04).
     *
     * 8 hazards with non-sequential ids, mirroring hazards()' shape. The
     * fixed 11-hazard register this app used to always inject is gone as of
     * Plan 26-03/26-04; the register is now variable-length (a near-empty
     * scope yields 9 rows — 4 always + 5 confirm-tier — while a busier scope
     * can yield many more). RA{NN} refs must still resolve 1:1 at THIS size,
     * not just at the old fixture's size of 3.
     */
    private function biggerHazards(): array
    {
        return [
            ['id' => 4,  'hazard' => 'Working at height',                'controls' => ['Use podium steps']],
            ['id' => 9,  'hazard' => 'Manual handling',                  'controls' => ['Team lift']],
            ['id' => 11, 'hazard' => 'Electrical contact',                'controls' => ['Isolate and lock off']],
            ['id' => 15, 'hazard' => 'Slips, trips and falls',            'controls' => ['Keep walkways clear']],
            ['id' => 16, 'hazard' => 'Fixings into walls, ceilings and pillars', 'controls' => ['Cable avoidance tool scan']],
            ['id' => 20, 'hazard' => 'Occupied premises',                 'controls' => ['Coordinate with site staff']],
            ['id' => 22, 'hazard' => 'Restricted access and ceiling voids', 'controls' => ['Use a spotter']],
            ['id' => 25, 'hazard' => 'Fire and evacuation',               'controls' => ['Know the muster point']],
        ];
    }

    /** Count how many "Associated Risks" lines a rendered phase would show. */
    private function countRiskLines(array $phase): int
    {
        $count = 0;
        foreach ((array) ($phase['steps'] ?? []) as $step) {
            if (preg_match('/^\s*[-•*\s]*associated\s+risks?\s*[:\-–—]/iu', (string) $step) === 1) {
                $count++;
            }
        }
        if (trim((string) ($phase['associated_risks_label'] ?? '')) !== '') {
            $count++;
        }

        return $count;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. Exactly one Associated Risks line per phase
    // ══════════════════════════════════════════════════════════════════════

    public function test_model_authored_associated_risks_bullet_is_stripped(): void
    {
        $result = $this->crossReference([
            'hazards' => $this->hazards(),
            'method_statement' => ['phases' => [[
                'title' => 'Step 3 — Display Installation',
                'steps' => [
                    'Mount the 75" display bracket at height in the Boardroom.',
                    'Associated Risks: RA01, RA02, RA03, RA04, RA05, RA06',
                ],
            ]]],
        ]);

        $phase = $result['method_statement']['phases'][0];

        $this->assertSame(
            ['Mount the 75" display bracket at height in the Boardroom.'],
            $phase['steps'],
            '260817-r5e Item 1: the model-authored "Associated Risks" bullet must be stripped from steps — it is the second, contradictory producer.',
        );
        $this->assertNotSame('', trim((string) ($phase['associated_risks_label'] ?? '')),
            '260817-r5e Item 1: the deterministic label must still be produced — stripping must not leave the phase with no cross-reference at all.');
        $this->assertSame(1, $this->countRiskLines($phase),
            '260817-r5e Item 1: a rendered phase must carry EXACTLY ONE Associated Risks line.');
    }

    public function test_no_phase_produces_two_associated_risks_lines(): void
    {
        $result = $this->crossReference([
            'hazards' => $this->hazards(),
            'method_statement' => ['phases' => [
                [
                    'title' => 'Step 1 — Arrival & Site Induction',
                    'steps' => ['Toolbox talk and PPE check.', 'Associated Risks: RA01, RA03'],
                ],
                [
                    'title' => 'Step 4 — Cable Containment',
                    'steps' => ['Pull cable at height above the ceiling grid.', '- Associated risks — RA01, RA09, RA10'],
                ],
                [
                    'title' => 'Step 6 — Rack Build',
                    'steps' => ['Manual handling of the equipment rack.', '• Associated Risk: RA02'],
                ],
            ]],
        ]);

        foreach ($result['method_statement']['phases'] as $i => $phase) {
            $this->assertLessThanOrEqual(1, $this->countRiskLines($phase),
                "260817-r5e Item 1: phase {$i} renders more than one Associated Risks line.");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. Every emitted RA-ID exists in the document's own risk register
    // ══════════════════════════════════════════════════════════════════════

    public function test_emitted_ra_ids_all_exist_in_the_rendered_risk_register(): void
    {
        $hazards = $this->hazards();

        $result = $this->crossReference([
            'hazards' => $hazards,
            'method_statement' => ['phases' => [
                ['title' => 'Step 3 — Display Installation', 'steps' => ['Mount the display at height in the Boardroom.']],
                ['title' => 'Step 5 — Rack Build',           'steps' => ['Manual handling of the rack into position.']],
                ['title' => 'Step 7 — Commissioning',        'steps' => ['Electrical connection and power-on checks.']],
            ]],
        ]);

        // The renderers label risk-register rows RA01..RA{count} by array
        // position (DocxBuilderService:1221, rams-v2.blade.php:1393).
        $valid = [];
        foreach (array_keys(array_values($hazards)) as $idx) {
            $valid[] = 'RA' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);
        }

        $sawAtLeastOne = false;

        foreach ($result['method_statement']['phases'] as $phase) {
            $label = (string) ($phase['associated_risks_label'] ?? '');
            if (trim($label) === '') {
                continue;
            }

            preg_match_all('/RA\d{2}/', $label, $m);
            $this->assertNotEmpty($m[0], 'Label present but carried no RA-IDs: ' . $label);

            foreach ($m[0] as $ref) {
                $sawAtLeastOne = true;
                $this->assertContains($ref, $valid,
                    "260817-r5e Item 1: {$ref} is a DANGLING reference — the risk register only contains " . implode(', ', $valid) . '.');
            }
        }

        $this->assertTrue($sawAtLeastOne,
            'Fixture produced no cross-references at all — the assertion above would have proved nothing.');
    }

    /**
     * Phase 26 Plan 06 — same invariant as
     * test_emitted_ra_ids_all_exist_in_the_rendered_risk_register() above,
     * proven at count=8 instead of count=3. Both tests must coexist as
     * regression guards for different register sizes: the fixed 11-hazard
     * baseline is gone (Plan 26-03/26-04), so the register genuinely varies
     * in length job-to-job now, and RA-ref resolution must not be a
     * coincidence of the old fixed size.
     */
    public function test_emitted_ra_ids_all_exist_in_the_rendered_risk_register_at_variable_length(): void
    {
        $hazards = $this->biggerHazards();

        $result = $this->crossReference([
            'hazards' => $hazards,
            'method_statement' => ['phases' => [
                ['title' => 'Step 3 — Display Installation', 'steps' => ['Mount the display at height in the Boardroom.']],
                ['title' => 'Step 5 — Rack Build',           'steps' => ['Manual handling of the rack into position.']],
                ['title' => 'Step 7 — Commissioning',        'steps' => ['Electrical connection and power-on checks.']],
                ['title' => 'Step 8 — Ceiling Void Cabling',  'steps' => ['Pull cable above the ceiling grid in the void.']],
                ['title' => 'Step 9 — Occupied Areas',        'steps' => ['Work around staff while the building remains occupied.']],
            ]],
        ]);

        // The renderers label risk-register rows RA01..RA{count} by array
        // position (DocxBuilderService:1230, rams-v2.blade.php:1393) —
        // generic across any register length.
        $valid = [];
        foreach (array_keys(array_values($hazards)) as $idx) {
            $valid[] = 'RA' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);
        }

        $sawAtLeastOne = false;

        foreach ($result['method_statement']['phases'] as $phase) {
            $label = (string) ($phase['associated_risks_label'] ?? '');
            if (trim($label) === '') {
                continue;
            }

            preg_match_all('/RA\d{2}/', $label, $m);
            $this->assertNotEmpty($m[0], 'Label present but carried no RA-IDs: ' . $label);

            foreach ($m[0] as $ref) {
                $sawAtLeastOne = true;
                $this->assertContains($ref, $valid,
                    "Phase 26 Plan 06: {$ref} is a DANGLING reference at register size 8 — the risk register only contains " . implode(', ', $valid) . '.');
            }
        }

        $this->assertTrue($sawAtLeastOne,
            'Fixture produced no cross-references at all — the assertion above would have proved nothing.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. Prose that merely mentions risks is not a cross-reference line
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_genuine_instruction_mentioning_risks_is_not_stripped(): void
    {
        $instruction = 'Brief the team on the associated risks before starting work at height.';

        $result = $this->crossReference([
            'hazards' => $this->hazards(),
            'method_statement' => ['phases' => [[
                'title' => 'Step 1 — Arrival & Site Induction',
                'steps' => [$instruction],
            ]]],
        ]);

        $this->assertContains(
            $instruction,
            $result['method_statement']['phases'][0]['steps'],
            '260817-r5e Item 1: the strip must be anchored to the start of the bullet — a work instruction that mentions risks is not a cross-reference line.',
        );
    }
}

<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Quick task 260817-r5e Item 3 — §6.4 must not contradict the risk
 * assessment or the method statement on a work-at-height control.
 *
 * 21CQ30960-OPS Rev 1.0 stated:
 *
 *     "Podium steps excluded — working height does not require a working
 *      platform."
 *
 * while RA01's controls listed podium steps and Step 8 instructed operatives
 * to remove them. Two failures in one sentence: the document contradicts
 * itself, and "working height does not require a working platform" is a
 * safety judgement the generator inferred from a prose hint.
 *
 * The fix drops an access-equipment type only when nothing else in the
 * document references it, and never writes an exclusion claim at all.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 */
class AccessEquipmentContradictionTest extends TestCase
{
    private function upgradeAccessDetail(array $data): array
    {
        $result = RamsComplianceUpgradeService::upgrade($data);

        return (array) ($result['access_equipment_detail'] ?? []);
    }

    /** The 21CQ30960 shape: a "no podium" hint that the rest of the doc contradicts. */
    private function contradictingDocument(): array
    {
        return [
            'scope_of_works' => 'All displays are wall-mounted at low level; no podium steps required for this installation.',
            'hazards' => [
                [
                    'id'       => 1,
                    'hazard'   => 'Working at height',
                    'controls' => [
                        'Use appropriate access equipment (podium steps, tower, or MEWP) — no improvised access',
                    ],
                ],
            ],
            'method_statement' => ['phases' => [
                [
                    'title' => 'Step 8 — Completion & Sign-Off',
                    'steps' => ['Remove all podium steps, waste and packaging from site.'],
                ],
            ]],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // The contradiction case
    // ══════════════════════════════════════════════════════════════════════

    public function test_no_exclusion_claim_when_the_equipment_is_used_elsewhere_in_the_document(): void
    {
        $detail = $this->upgradeAccessDetail($this->contradictingDocument());
        $items  = (array) ($detail['items'] ?? []);

        $blob = strtolower(implode(' | ', $items));

        $this->assertStringNotContainsString('excluded', $blob,
            '260817-r5e Item 3: §6.4 still asserts podium steps are excluded while RA01 lists them as a control and Step 8 removes them.');
        $this->assertStringNotContainsString('does not require a working platform', $blob,
            '260817-r5e Item 3: the generator is not entitled to make that safety judgement from a prose hint.');

        // ...and because the document DOES rely on podium steps, they stay
        // listed. Silently dropping them would leave Step 8 telling operatives
        // to remove kit the access schedule never issued.
        $this->assertNotEmpty(
            array_filter($items, static fn (string $s): bool => stripos($s, 'podium') !== false),
            '260817-r5e Item 3: podium steps are referenced by RA01 and Step 8 — §6.4 must still list them.',
        );
    }

    public function test_a_referenced_type_survives_even_a_ground_level_declaration(): void
    {
        $data = $this->contradictingDocument();
        $data['scope_of_works'] = 'All works at ground level — reachable from the floor.';

        $items = (array) ($this->upgradeAccessDetail($data)['items'] ?? []);

        $this->assertNotEmpty(
            array_filter($items, static fn (string $s): bool => stripos($s, 'podium') !== false),
            '260817-r5e Item 3: a "ground level" hint must not strip equipment the method statement tells operatives to remove.',
        );
        $this->assertStringNotContainsString('no platform access equipment required', strtolower(implode(' ', $items)),
            '260817-r5e Item 3: categorical "no platform access equipment required" claim still emitted.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // The non-contradiction case — the PM hint still does something
    // ══════════════════════════════════════════════════════════════════════

    public function test_unreferenced_platform_equipment_is_dropped_silently(): void
    {
        $detail = $this->upgradeAccessDetail([
            'scope_of_works' => 'Desk-level works only, at ground level throughout.',
            // Hazards and steps that reference NO platform-class access kit.
            'hazards' => [
                ['id' => 1, 'hazard' => 'Manual handling', 'controls' => ['Team lift for items over 20 kg']],
            ],
            'method_statement' => ['phases' => [
                ['title' => 'Step 1 — Arrival & Site Induction', 'steps' => ['Toolbox talk and PPE check.']],
            ]],
        ]);

        $items = (array) ($detail['items'] ?? []);
        $blob  = strtolower(implode(' | ', $items));

        $this->assertStringNotContainsString('podium',  $blob,
            '260817-r5e Item 3: nothing references podium steps and the PM declared ground level — the line should be gone.');
        $this->assertStringNotContainsString('tower',   $blob);
        $this->assertStringNotContainsString('mewp',    $blob);

        // Dropped SILENTLY — no claim about why.
        $this->assertStringNotContainsString('excluded', $blob,
            '260817-r5e Item 3: omission must be silent, not an exclusion claim.');
        $this->assertStringNotContainsString('not require', $blob);
        $this->assertStringNotContainsString('working height confirmed', $blob,
            '260817-r5e Item 3: the pre-fix "Working height confirmed at ground/floor level — no platform access equipment required." claim is still emitted.');
        $this->assertStringNotContainsString('no platform access equipment required', $blob);

        // Ladders / kick stool survive, so this is a real filter rather than
        // an empty list that would pass every assertion above.
        $this->assertStringContainsString('ladder', $blob,
            'Filter removed everything — the assertions above would prove nothing.');

        $requirements = strtolower(implode(' | ', (array) ($detail['requirements'] ?? [])));
        $this->assertStringNotContainsString('pasma', $requirements,
            '260817-r5e Item 3: tower certification requirement kept after the tower line was dropped.');
        $this->assertStringNotContainsString('ipaf', $requirements);
    }

    public function test_default_access_list_is_untouched_without_a_pm_hint(): void
    {
        $detail = $this->upgradeAccessDetail([
            'hazards'          => [],
            'method_statement' => ['phases' => []],
        ]);

        $blob = strtolower(implode(' | ', (array) ($detail['items'] ?? [])));

        $this->assertStringContainsString('podium', $blob);
        $this->assertStringContainsString('tower',  $blob);
        $this->assertStringContainsString('mewp',   $blob);
        $this->assertStringNotContainsString('excluded', $blob);
    }
}

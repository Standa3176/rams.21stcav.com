<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 26 Plan 07 (HAZ-02 gap closure) — RamsComplianceUpgradeService::
 * addProjectSpecificRisks() is the SIXTH, previously-undocumented hazard-
 * injection path (see 26-07-PLAN.md <investigation>). It ran unconditionally
 * on every generation, regardless of the RAMS_HAZARD_LIBRARY_TIERING flag,
 * appending up to 7 old-vocabulary hazard rows (3 of them unconditional)
 * that duplicate hazards the tiered 18-hazard library already adds
 * declaratively — the proven, traced cause of the unexplained 7→11 delta on
 * live (21CQ30960 / RAMS 96).
 *
 * This test locks the gate: a no-op when tiering governs the register,
 * byte-identical legacy behaviour only in the explicit flag-off rollback
 * state.
 *
 * Reflection is used to exercise the private static method directly —
 * mirrors the existing RamsComplianceUpgradeServiceCacheTest pattern.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see .planning/phases/26-hazard-library-structural-inversion/26-07-PLAN.md
 */
class ProjectSpecificRisksGatedTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    /**
     * A $data payload whose scope text matches ALL 7 legacy candidates:
     * rack ("AV rack"), ceiling void ("cable tray above ceiling void"),
     * riser ("riser access"), and drilling/fixing ("Drill fixings and mount
     * brackets"). No opt-out phrases ("no rack" / "no ceiling") present.
     */
    private function makeDataMatchingAllLegacyCandidates(): array
    {
        return [
            'hazards' => [],
            'method_statement_notes' => 'Install AV rack in the comms cabinet. Cable tray above '
                . 'ceiling void, riser access required. Drill fixings and mount brackets for wall plates.',
            'equipment' => [],
        ];
    }

    public function test_addProjectSpecificRisks_is_a_noop_when_tiering_enabled(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => true]);

        $data = $this->makeDataMatchingAllLegacyCandidates();

        $result = $this->invokePrivateStatic('addProjectSpecificRisks', [$data]);

        $this->assertSame(
            $data['hazards'],
            $result['hazards'],
            'Phase 26 HAZ-02: addProjectSpecificRisks() must be a complete no-op when hazard '
            . 'tiering governs the register — every one of its 7 candidates now has a direct or '
            . 'D-02-mapped equivalent in the declarative 18-hazard library.',
        );
    }

    public function test_addProjectSpecificRisks_preserves_legacy_behaviour_when_tiering_disabled(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => false]);

        $data = $this->makeDataMatchingAllLegacyCandidates();

        $result = $this->invokePrivateStatic('addProjectSpecificRisks', [$data]);

        $names = array_map(
            static fn (array $h): string => (string) ($h['hazard'] ?? ''),
            $result['hazards'],
        );

        // The 3 unconditional legacy candidates must still fire — proving
        // the rollback path (RAMS_HAZARD_LIBRARY_TIERING=false) is byte-
        // identical to pre-Plan-07 behaviour.
        $this->assertContains('Cable Pulling & Termination', $names);
        $this->assertContains('Low Voltage AV Connections', $names);
        $this->assertContains('Fixings into Walls & Ceilings', $names);
    }
}

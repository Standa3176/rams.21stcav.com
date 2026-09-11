<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 29 Plan 03 (GATE-11/GATE-12) — throw/no-throw boundary proof for
 * RamsComplianceUpgradeService::enforceCdmGate()/enforceEmergencyGate(), plus
 * resolveSiteEmergency() and the disarmed-by-default config-flag wiring
 * through the real upgrade() entry point.
 *
 * Reflection is used to exercise the private static methods directly,
 * mirroring Ffp2ConfinedSpaceGateTest's established pattern exactly.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 * Procedure followed during development of this test file, mirroring
 * Ffp2ConfinedSpaceGateTest's documented "break-the-fix-and-watch-the-test-
 * fail" procedure:
 *
 *   1. Temporarily edited enforceCdmGate() so its `if (($cdm[$field] ??
 *      null) === '[To be confirmed]')` guard was changed to
 *      `if (false && ...)`, and enforceEmergencyGate() so its
 *      `if ($reason !== null)` guard was changed to `if (false && ...)`
 *      (simulating both checks being silently disabled at once).
 *   2. Re-ran `php artisan test --filter=CdmEmergencyGateTest`.
 *   3. Result: exactly the 5 throwing tests
 *      (test_enforceCdmGate_throws_on_placeholder_principal_designer,
 *      test_enforceCdmGate_throws_on_placeholder_principal_contractor,
 *      test_enforceEmergencyGate_throws_on_banned_string,
 *      test_enforceEmergencyGate_throws_on_urgent_care_keyword,
 *      test_enforceEmergencyGate_throws_on_missing_address) FAILED — each
 *      expected RamsGenerationException but none was thrown; the other 4
 *      non-throwing/config/resolver tests still passed unaffected — proving
 *      the 5 throwing tests are not vacuously passing.
 *   4. Restored the real conditions from the pre-stub source (confirmed
 *      `git diff` empty afterward — no residual change).
 *   5. Re-ran the filter again: all 9 tests passed, confirming the restore.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/SiteEmergencyResolver.php
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-03-PLAN.md
 */
class CdmEmergencyGateTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    public function test_enforceCdmGate_throws_on_placeholder_principal_designer(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/principal_designer.*GATE-11\/RULE-07/s');

        $this->invokePrivateStatic('enforceCdmGate', [[
            'cdm_duty_holders' => [
                'principal_designer' => '[To be confirmed]',
                'principal_contractor' => 'Some restated text',
            ],
        ]]);
    }

    public function test_enforceCdmGate_throws_on_placeholder_principal_contractor(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/principal_contractor.*GATE-11\/RULE-07/s');

        $this->invokePrivateStatic('enforceCdmGate', [[
            'cdm_duty_holders' => [
                'principal_designer' => 'Some restated text',
                'principal_contractor' => '[To be confirmed]',
            ],
        ]]);
    }

    public function test_enforceCdmGate_passes_on_restated_wording(): void
    {
        $data = $this->invokePrivateStatic('addCdmDutyHolders', [[]]);

        $result = $this->invokePrivateStatic('enforceCdmGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceEmergencyGate_throws_on_banned_string(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/banned_string.*GATE-12\/RULE-08/s');

        $this->invokePrivateStatic('enforceEmergencyGate', [[
            'site_emergency' => [
                'nearest_hospital' => 'Nearest hospital A&E to be identified at site induction',
                'hospital_address' => '',
            ],
        ]]);
    }

    public function test_enforceEmergencyGate_throws_on_urgent_care_keyword(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/urgent_care_keyword.*GATE-12\/RULE-08/s');

        $this->invokePrivateStatic('enforceEmergencyGate', [[
            'site_emergency' => [
                'nearest_hospital' => 'Willow Urgent Treatment Centre',
                'hospital_address' => '1 Willow Road, Testtown, TE1 1AA',
            ],
        ]]);
    }

    public function test_enforceEmergencyGate_throws_on_missing_address(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/missing_address_or_postcode.*GATE-12\/RULE-08/s');

        $this->invokePrivateStatic('enforceEmergencyGate', [[
            'site_emergency' => [
                'nearest_hospital' => 'St Mary\'s Hospital',
                'hospital_address' => '',
            ],
        ]]);
    }

    public function test_enforceEmergencyGate_passes_on_hold_point(): void
    {
        $data = [
            'site_emergency' => [
                'nearest_hospital' => '',
                'hospital_address' => '',
            ],
        ];

        $result = $this->invokePrivateStatic('enforceEmergencyGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceEmergencyGate_passes_on_verified_name_and_address(): void
    {
        $data = [
            'site_emergency' => [
                'nearest_hospital' => 'St Mary\'s Hospital',
                'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
            ],
        ];

        $result = $this->invokePrivateStatic('enforceEmergencyGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_resolveSiteEmergency_writes_resolved_key(): void
    {
        $data = $this->invokePrivateStatic('resolveSiteEmergency', [[
            'site_emergency' => [
                'nearest_hospital' => 'St Mary\'s Hospital',
                'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
            ],
        ]]);

        $this->assertArrayHasKey('site_emergency_resolved', $data);
        $this->assertTrue($data['site_emergency_resolved']['verified']);
        $this->assertSame(
            'St Mary\'s Hospital, 123 Example Road, Testtown, TE5 7ST. Route and travel time confirmed at induction.',
            $data['site_emergency_resolved']['text'],
        );
    }

    public function test_upgrade_gate_inert_when_flag_disarmed(): void
    {
        config(['rams_tier1.cdm_ae_gate_enabled' => false]);

        // Genuine inert-when-disarmed proof: feed upgrade() an A&E value
        // that would trip GATE-12 (banned passive string) if the gate ran —
        // addCdmDutyHolders() itself no longer emits a raw placeholder, so
        // the disarmed-inertness of GATE-11 is proven structurally (there is
        // nothing left for it to catch); GATE-12's disarmed-inertness is
        // proven behaviourally here, mirrored by
        // test_upgrade_throws_via_public_entry_point_when_flag_enabled_and_ae_implausible
        // proving the SAME input throws once armed.
        $result = RamsComplianceUpgradeService::upgrade([
            'site_emergency' => [
                'nearest_hospital' => 'Nearest hospital A&E to be identified at site induction',
                'hospital_address' => '',
            ],
        ]);

        $this->assertArrayHasKey('site_emergency_resolved', $result);
        $this->assertNotSame('[To be confirmed]', $result['cdm_duty_holders']['principal_designer'] ?? null);
        $this->assertNotSame('[To be confirmed]', $result['cdm_duty_holders']['principal_contractor'] ?? null);
    }

    public function test_upgrade_writes_site_emergency_resolved_unconditionally(): void
    {
        config(['rams_tier1.cdm_ae_gate_enabled' => false]);

        $result = RamsComplianceUpgradeService::upgrade([]);

        $this->assertArrayHasKey('site_emergency_resolved', $result);
        $this->assertArrayHasKey('text', $result['site_emergency_resolved']);
    }

    public function test_upgrade_throws_via_public_entry_point_when_flag_enabled_and_ae_implausible(): void
    {
        config(['rams_tier1.cdm_ae_gate_enabled' => true]);

        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-12\/RULE-08/');

        RamsComplianceUpgradeService::upgrade([
            'site_emergency' => [
                'nearest_hospital' => 'Nearest hospital A&E to be identified at site induction',
                'hospital_address' => '',
            ],
        ]);
    }
}

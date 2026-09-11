<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 29 Plan 03 (RULE-07) — proves addCdmDutyHolders() emits the
 * restated CDM duty-holder wording unconditionally, on every job, and that
 * none of the four forbidden statements (standards-and-legislation.md:35-41)
 * ever appear in the resulting array.
 *
 * Reflection-invokes the private static method, mirroring
 * Ffp2ConfinedSpaceGateTest's established pattern.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see .planning/reference/21cav-rams-skill/references/standards-and-legislation.md:17-41
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-03-PLAN.md
 */
class CdmDutyHolderWordingTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    private function cdm(array $data = []): array
    {
        $result = $this->invokePrivateStatic('addCdmDutyHolders', [$data]);

        return $result['cdm_duty_holders'];
    }

    public function test_client_key_is_unchanged(): void
    {
        $cdm = $this->cdm(['project' => ['client' => 'Acme Ltd']]);

        $this->assertSame('Acme Ltd', $cdm['client']);
    }

    public function test_client_key_falls_back_to_placeholder_name(): void
    {
        $cdm = $this->cdm([]);

        $this->assertSame('[Client Name]', $cdm['client']);
    }

    public function test_principal_designer_is_never_the_bare_placeholder(): void
    {
        $cdm = $this->cdm([]);

        $this->assertNotSame('[To be confirmed]', $cdm['principal_designer']);
        $this->assertSame(RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE, $cdm['principal_designer']);
    }

    public function test_principal_contractor_is_never_the_bare_placeholder(): void
    {
        $cdm = $this->cdm([]);

        $this->assertNotSame('[To be confirmed]', $cdm['principal_contractor']);
        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
            $cdm['principal_contractor'],
        );
    }

    public function test_contractor_note_carries_the_verbatim_anticipated_sole_contractor_sentence(): void
    {
        $cdm = $this->cdm([]);

        $this->assertSame(
            '21CAV is currently anticipated to be the sole contractor for the AV installation scope. The client '
                . 'shall confirm whether the overall project involves, or is likely to involve, more than one '
                . 'contractor before works commence.',
            $cdm['contractor_note'],
        );
    }

    public function test_notification_never_asserts_principal_contractor_must_notify(): void
    {
        $cdm = $this->cdm([]);

        $this->assertMatchesRegularExpression(
            '/Client\'s duty/i',
            $cdm['notification'],
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Principal Contractor\s+must\s+notify/i',
            $cdm['notification'],
        );
    }

    public function test_contractor_subcontractor_regulation_unchanged(): void
    {
        $cdm = $this->cdm([]);

        $this->assertSame('21st Century AV Ltd', $cdm['contractor']);
        $this->assertSame('21st Century AV Ltd', $cdm['subcontractor']);
        $this->assertSame('Construction (Design and Management) Regulations 2015', $cdm['cdm_regulation']);
    }

    public function test_project_manager_and_site_supervisor_keep_data_dependent_placeholder(): void
    {
        $cdm = $this->cdm([]);

        $this->assertSame('[To be confirmed]', $cdm['project_manager']);
        $this->assertSame('[To be confirmed]', $cdm['site_supervisor']);
    }

    public function test_applied_unconditionally_with_no_occupied_premises_branch(): void
    {
        // RESEARCH.md Finding 6 / Assumption A2: no deterministic
        // "occupied premises" signal exists in this codebase, so the
        // restated wording is applied on every call regardless of any
        // input shape — there is no branch to feed a differing input into.
        $withProject = $this->cdm(['project' => ['client' => 'Acme Ltd']]);
        $withoutProject = $this->cdm([]);

        $this->assertSame($withProject['principal_designer'], $withoutProject['principal_designer']);
        $this->assertSame($withProject['principal_contractor'], $withoutProject['principal_contractor']);
        $this->assertSame($withProject['contractor_note'], $withoutProject['contractor_note']);
        $this->assertSame($withProject['notification'], $withoutProject['notification']);
    }

    /**
     * @dataProvider forbiddenStatementProvider
     */
    public function test_forbidden_statement_never_appears(string $forbidden): void
    {
        $cdm = $this->cdm([]);
        $haystack = implode(' | ', array_map('strval', $cdm));

        $this->assertStringNotContainsStringIgnoringCase($forbidden, $haystack);
    }

    public static function forbiddenStatementProvider(): array
    {
        return [
            'unequivocal sole contractor assertion' => ['21CAV is the sole contractor'],
            'client retains principal designer' => ['retains Principal Designer'],
            'principal contractor must notify' => ['Principal Contractor must notify'],
        ];
    }

    public function test_forbidden_regulation_4_or_5_duty_discharge_never_appears(): void
    {
        $cdm = $this->cdm([]);
        $haystack = implode(' | ', array_map('strval', $cdm));

        $this->assertDoesNotMatchRegularExpression(
            '/21CAV\s+discharges\s+its\s+duties\s+under\s+Regulations?\s+[45]/i',
            $haystack,
        );
    }
}

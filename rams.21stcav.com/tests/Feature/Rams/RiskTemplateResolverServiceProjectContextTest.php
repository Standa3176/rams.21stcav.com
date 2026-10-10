<?php

namespace Tests\Feature\Rams;

use App\Services\RiskTemplateResolverService;
use Tests\TestCase;

/**
 * Quick task 261010-cdf — proves the live defect: a survey room recorded as
 * working at 2–4m or 4m+ produced NO working-at-height hazard, NO Hard Hat,
 * and NO access equipment from resolveFromProjectContext(), because the
 * method only branched on the legacy `over_2m` value and the current
 * engineer-wizard values (`2_to_4m`, `over_4m`) fell through to nothing.
 *
 * Tests 1 and 2 are RED before the fix (see the quoted failure output in
 * the quick task SUMMARY). Tests 3 and 4 are the non-regression baseline —
 * already GREEN before the fix and must remain GREEN after it.
 *
 * @see app/Services/RiskTemplateResolverService.php:367-408
 */
class RiskTemplateResolverServiceProjectContextTest extends TestCase
{
    private function resolve(string $workingHeight): array
    {
        return app(RiskTemplateResolverService::class)->resolveFromProjectContext([
            'rooms' => [[
                'name'  => 'Room A',
                'risks' => [['working_height' => $workingHeight]],
            ]],
        ]);
    }

    public function test_2_to_4m_produces_the_full_working_at_height_hazard(): void
    {
        $result = $this->resolve('2_to_4m');

        $hazard = collect($result['hazards'])->firstWhere('title', 'Working at height');
        $this->assertNotNull($hazard, 'Expected a "Working at height" hazard for 2_to_4m.');
        $this->assertSame(['Room A'], $hazard['rooms']);

        $this->assertContains('Hard Hat', $result['ppe']);
        $this->assertContains('Podium Steps', $result['access_equipment']);
        $this->assertContains('Access Tower (if above 3 m)', $result['access_equipment']);
        $this->assertContains('MEWP (if required)', $result['access_equipment']);
    }

    public function test_over_4m_produces_the_full_working_at_height_hazard(): void
    {
        $result = $this->resolve('over_4m');

        $hazard = collect($result['hazards'])->firstWhere('title', 'Working at height');
        $this->assertNotNull($hazard, 'Expected a "Working at height" hazard for over_4m.');
        $this->assertSame(['Room A'], $hazard['rooms']);

        $this->assertContains('Hard Hat', $result['ppe']);
        $this->assertContains('Podium Steps', $result['access_equipment']);
        $this->assertContains('Access Tower (if above 3 m)', $result['access_equipment']);
        $this->assertContains('MEWP (if required)', $result['access_equipment']);
    }

    public function test_legacy_over_2m_still_produces_the_full_working_at_height_hazard(): void
    {
        // Non-regression baseline: this value already worked before the fix
        // and must keep working identically afterwards.
        $result = $this->resolve('over_2m');

        $hazard = collect($result['hazards'])->firstWhere('title', 'Working at height');
        $this->assertNotNull($hazard, 'Expected a "Working at height" hazard for the legacy over_2m value.');
        $this->assertSame(['Room A'], $hazard['rooms']);

        $this->assertContains('Hard Hat', $result['ppe']);
        $this->assertContains('Podium Steps', $result['access_equipment']);
        $this->assertContains('Access Tower (if above 3 m)', $result['access_equipment']);
        $this->assertContains('MEWP (if required)', $result['access_equipment']);
    }

    public function test_under_2m_produces_only_the_low_level_hazard_and_is_not_widened(): void
    {
        // Non-regression baseline: under_2m must stay the low-level branch,
        // never widened into the full above-2m hazard.
        $result = $this->resolve('under_2m');

        $hazard = collect($result['hazards'])->firstWhere('title', 'Working at height (low level)');
        $this->assertNotNull($hazard, 'Expected the low-level hazard for under_2m.');

        $this->assertNull(
            collect($result['hazards'])->firstWhere('title', 'Working at height'),
            'under_2m must not also produce the full "Working at height" hazard.'
        );

        $this->assertContains('Hard Hat', $result['ppe']);
        $this->assertSame([], $result['access_equipment']);
    }
}

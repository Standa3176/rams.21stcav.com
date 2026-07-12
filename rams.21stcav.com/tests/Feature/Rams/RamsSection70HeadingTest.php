<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Quick task 260712-w5k Task 4 regression test.
 *
 * Locks the confirmed-good behaviour of PDF Section 7.0 heading render.
 * Section 7.0 heading MUST render in BOTH branches of the emergency table:
 * populated table (@if($hasSiteEmerg) true) AND empty amber warning banner
 * (@else true).
 *
 * Verification-time grep on 260712-twi Tilda RAMS #87 falsely reported
 * "Site Emergency" missing — actual heading text is "Site-Specific Emergency
 * Details" (with hyphen). This test locks the exact heading string against
 * future accidental renaming, and locks the regression that Section 7.0
 * heading is never made conditional on data population.
 */
class RamsSection70HeadingTest extends TestCase
{
    private function ramsStub(array $reviewedData = []): object
    {
        $stub                = new \stdClass();
        $stub->project_name  = 'Test Project';
        $stub->project_ref   = 'TEST-001';
        $stub->form_data     = [];
        $stub->client_name   = 'Test Client';
        $stub->site_address  = 'Test Site Address';
        $stub->created_at    = Carbon::create(2026, 7, 12);
        $stub->reviewed_data = $reviewedData;

        return $stub;
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'scope_of_works'  => 'Test scope',
            'project'         => [
                'name'         => 'Test Project',
                'ref'          => 'TEST-001',
                'client'       => 'Test Client',
                'site_address' => 'Test Site Address',
            ],
            'hazards'          => [],
            'ppe'              => [],
            'persons_at_risk'  => [],
            'team'             => [],
            'method_statement' => ['phases' => []],
            'quote'            => [],
            'site_logistics'   => [],
        ], $overrides);
    }

    public function test_heading_renders_when_emergency_data_populated(): void
    {
        // Populate every key the Section 7.0 populated-table branch reads —
        // template uses direct `$siteEmerg['key']` access, so missing keys
        // trigger an "undefined array key" warning under strict test mode.
        $siteEmerg = [
            'nearest_hospital'            => 'Royal Berkshire Hospital A&E',
            'hospital_address'            => 'London Road, Reading, RG1 5AN',
            'fire_assembly_point'         => 'Front car park by main gate',
            'fire_warden_name'            => 'Sarah Johnson',
            'fire_warden_contact'         => '07700 000000',
            'first_aider_name'            => 'Mark Williams',
            'first_aider_contact'         => '07700 111111',
            'defibrillator_location'      => 'Reception lobby',
            'electrical_isolation_switch' => 'Main DB in comms room B1-Rm-04',
            'fire_extinguisher_class'     => 'CO2 (electrical equipment)',
        ];

        $html = view('pdf.rams', [
            'data' => $this->baseData(['site_emergency' => $siteEmerg]),
            'rams' => $this->ramsStub(),
        ])->render();

        // Heading MUST render — this is the regression lock
        $this->assertStringContainsString('7.0 Site-Specific Emergency Details', $html);
        $this->assertStringContainsString('sec-subheading', $html);
        // Populated branch marker
        $this->assertStringContainsString('Royal Berkshire', $html);
        // Amber warning banner MUST NOT fire when data populated
        $this->assertStringNotContainsString('TBC AT SITE INDUCTION', $html);
    }

    public function test_heading_renders_when_emergency_data_empty(): void
    {
        // Empty case — no site_emergency key at all
        $html = view('pdf.rams', [
            'data' => $this->baseData(),
            'rams' => $this->ramsStub(),
        ])->render();

        // Heading MUST still render — this is the regression lock
        $this->assertStringContainsString('7.0 Site-Specific Emergency Details', $html);
        $this->assertStringContainsString('sec-subheading', $html);
        // Amber warning banner branch active
        $this->assertStringContainsString('TBC AT SITE INDUCTION', $html);
        $this->assertStringContainsString('border: 2pt solid #c00', $html);
    }

    public function test_heading_renders_when_emergency_all_keys_present_but_blank(): void
    {
        // Edge case — sub-array present but all values are empty strings.
        // This is what happens when engineer submits the review form with
        // every emergency field left blank.
        $siteEmerg = [
            'nearest_hospital'            => '',
            'hospital_address'            => '',
            'fire_assembly_point'         => '',
            'fire_warden_name'            => '',
            'fire_warden_contact'         => '',
            'first_aider_name'            => '',
            'first_aider_contact'         => '',
            'defibrillator_location'      => '',
            'electrical_isolation_switch' => '',
            'fire_extinguisher_class'     => '',
        ];

        $html = view('pdf.rams', [
            'data' => $this->baseData(['site_emergency' => $siteEmerg]),
            'rams' => $this->ramsStub(),
        ])->render();

        // Heading MUST render regardless of populated/empty branch
        $this->assertStringContainsString('7.0 Site-Specific Emergency Details', $html);
        // array_filter reduces all-blank to empty → warning banner branch fires
        $this->assertStringContainsString('TBC AT SITE INDUCTION', $html);
    }
}

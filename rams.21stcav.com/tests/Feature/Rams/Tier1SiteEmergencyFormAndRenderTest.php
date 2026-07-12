<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 3 feature tests.
 *
 * Locks the Site Emergency review-form fieldset + controller persistence +
 * PDF Section 7.0 render (populated table vs red "TBC AT SITE INDUCTION"
 * warning banner) + RIDDOR routing matrix.
 *
 * Tests cover:
 *   1. POST to updateAndDownload with site_emergency_* fields persists
 *      the 8 sub-keys into reviewed_data['site_emergency'].
 *   2. PDF renders the 7.0 populated table + RIDDOR routing matrix
 *      when reviewed_data['site_emergency'] is populated.
 *   3. PDF renders the red warning banner when site_emergency is empty.
 */
class Tier1SiteEmergencyFormAndRenderTest extends TestCase
{
    use RefreshDatabase;

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

    private function renderWith(array $data, array $reviewedData = []): string
    {
        return view('pdf.rams', [
            'data' => $data,
            'rams' => $this->ramsStub($reviewedData),
        ])->render();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Controller persists site_emergency into reviewed_data
    // ══════════════════════════════════════════════════════════════════════════

    public function test_review_form_persists_site_emergency_into_reviewed_data(): void
    {
        // Reduce noise from failing DOCX render on the fake path.
        \Illuminate\Support\Facades\Log::spy();

        $user = User::factory()->create();
        $rams = RamsDocument::create([
            'user_id'      => $user->id,
            'project_ref'  => 'TEST-001',
            'project_name' => 'Test Project',
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Test Street',
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet-4-6',
            'form_data'    => [],
            'reviewed_data' => [
                'project' => [
                    'project_name' => 'Test Project',
                    'quote_ref'    => 'TEST-001',
                    'client_name'  => 'Acme Ltd',
                    'site_address' => '123 Test Street',
                ],
            ],
            'status'   => RamsDocument::STATUS_AWAITING_REVIEW,
            'filename' => null,
        ]);

        $this->actingAs($user)->post(route('rams.update-and-download', $rams), [
            'project_name'    => 'Test Project',
            'client_name'     => 'Acme Ltd',
            'site_address'    => '123 Test Street',
            'site_emergency_nearest_hospital'       => 'Royal Berkshire Hospital A&E',
            'site_emergency_hospital_address'       => 'London Road, Reading, RG1 5AN',
            'site_emergency_fire_assembly_point'    => 'Front car park by main gate',
            'site_emergency_fire_warden_name'       => 'Sarah Johnson',
            'site_emergency_fire_warden_contact'    => '07700 000000',
            'site_emergency_first_aider_name'       => 'Mark Williams',
            'site_emergency_first_aider_contact'    => '07700 111111',
            'site_emergency_defibrillator_location' => 'Reception lobby',
        ]);

        $rams->refresh();

        $this->assertSame(
            'Royal Berkshire Hospital A&E',
            $rams->reviewed_data['site_emergency']['nearest_hospital'] ?? null,
        );
        $this->assertSame(
            'Front car park by main gate',
            $rams->reviewed_data['site_emergency']['fire_assembly_point'] ?? null,
        );
        $this->assertSame(
            '07700 111111',
            $rams->reviewed_data['site_emergency']['first_aider_contact'] ?? null,
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. PDF renders populated 7.0 table + RIDDOR routing matrix
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pdf_renders_populated_site_emergency_table(): void
    {
        $siteEmerg = [
            'nearest_hospital'       => 'Royal Berkshire Hospital A&E',
            'hospital_address'       => 'London Road, Reading, RG1 5AN',
            'fire_assembly_point'    => 'Front car park by main gate',
            'fire_warden_name'       => 'Sarah Johnson',
            'fire_warden_contact'    => '07700 000000',
            'first_aider_name'       => 'Mark Williams',
            'first_aider_contact'    => '07700 111111',
            'defibrillator_location' => 'Reception lobby',
        ];

        $html = $this->renderWith(
            $this->baseData(['site_emergency' => $siteEmerg]),
        );

        $this->assertStringContainsString('7.0 Site-Specific Emergency Details', $html);
        $this->assertStringContainsString('Nearest A&amp;E Hospital', $html);
        $this->assertStringContainsString('Royal Berkshire Hospital', $html);
        $this->assertStringContainsString('Sarah Johnson', $html);
        // RIDDOR routing matrix (always renders, populated or not)
        $this->assertStringContainsString('RIDDOR Reporting Matrix', $html);
        $this->assertStringContainsString('0345 300 9923', $html);
        $this->assertStringContainsString('F2508', $html);
        // Red banner must NOT appear when data is populated.
        $this->assertStringNotContainsString('TBC AT SITE INDUCTION', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. PDF renders red warning banner when site_emergency is empty
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pdf_renders_warning_banner_when_site_emergency_empty(): void
    {
        // No site_emergency key at all → banner path
        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('7.0 Site-Specific Emergency Details', $html);
        $this->assertStringContainsString('TBC AT SITE INDUCTION', $html);
        $this->assertStringContainsString('border: 2pt solid #c00', $html);
        // Populated-table content markers must NOT appear.
        $this->assertStringNotContainsString('Royal Berkshire', $html);
    }
}

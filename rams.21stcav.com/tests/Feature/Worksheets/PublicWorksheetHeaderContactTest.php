<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 260602-mlt — Public worksheet header "Site contact: {name} · {tel-link}" line.
 *
 * Source of truth: $worksheet->project->latestPackage->extracted_data['ship_contact'/'ship_phone'].
 * Phone normalisation: leading '0' is rewritten to '+44' in the tel: href (UK), original
 * formatting preserved in the visible label.
 *
 * 3 scenarios:
 *   1. Both present  → "Site contact: John Smith · 0118 937 3787" with tel:+441189373787 href.
 *   2. Name only     → "Site contact: John Smith" with NO tel: href (no dangling separator).
 *   3. Both empty    → no "Site contact:" line at all.
 */
class PublicWorksheetHeaderContactTest extends TestCase
{
    use RefreshDatabase;

    /** Helper — create a worksheet + project, optionally seed a package with ship_contact/ship_phone. */
    private function makeWorksheetWithContact(?string $shipContact, ?string $shipPhone): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Always create a package (test scenarios 2 and 3 leave the keys empty / missing).
        $extracted = [];
        if ($shipContact !== null) {
            $extracted['ship_contact'] = $shipContact;
        }
        if ($shipPhone !== null) {
            $extracted['ship_phone'] = $shipPhone;
        }

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'fixture.pdf',
            'quote_path'     => 'fixtures/fixture.pdf',
            'extracted_data' => $extracted,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Reading Borough Council AV Refresh',
            'project_ref'    => '21CQ30362-01-OPS',
            'client_name'    => 'Reading Borough Council',
            'site_address'   => '10 High Street, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'                    => 'Main Hall',
                        'is_surveyed'             => true,
                        'install_steps'           => '',
                        'cable_route_desc'        => '',
                        'power_outlet_count'      => 0,
                        'requires_additional_power' => false,
                        'network_port_count'      => 0,
                        'existing_cabling'        => '',
                        'equipment'               => [],
                    ],
                ],
            ],
        ]);
    }

    public function test_site_contact_line_renders_with_tel_link_when_name_and_phone_present(): void
    {
        $worksheet = $this->makeWorksheetWithContact('John Smith', '0118 937 3787');

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertSee('Site contact:');
        $response->assertSee('John Smith');
        // UK normalisation: '0118 937 3787' → '+441189373787' (whitespace stripped + leading 0 → +44).
        $response->assertSee('href="tel:+441189373787"', escape: false);
        // Visible label preserves original formatting (with spaces).
        $response->assertSee('0118 937 3787');
    }

    public function test_site_contact_line_renders_name_only_with_no_tel_link_when_phone_absent(): void
    {
        $worksheet = $this->makeWorksheetWithContact('John Smith', '');

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertSee('Site contact:');
        $response->assertSee('John Smith');
        // No phone → no tel: anywhere on the page (the only place this string appears
        // is in our new partial; nothing else on a worksheet view uses tel: hrefs).
        $response->assertDontSee('tel:', escape: false);
        // No dangling middle-dot separator after the name.
        $response->assertDontSee('John Smith · ', escape: false);
    }

    public function test_no_site_contact_line_at_all_when_both_name_and_phone_empty(): void
    {
        $worksheet = $this->makeWorksheetWithContact('', '');

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertDontSee('Site contact:');
    }
}

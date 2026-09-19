<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for 260919-chb — the public worksheet signature pad was
 * silently wiped by mobile `resize` events (on-screen keyboard open/close,
 * iOS Safari URL-bar collapse) because the old handler treated every resize
 * as a genuine layout change and unconditionally cleared the canvas, `dirty`,
 * and the hidden `signature_image` input.
 *
 * Out of scope for automation: actually simulating a mobile on-screen
 * keyboard opening (a `resize` event with a height change but no width
 * change) and asserting the canvas's drawn pixels survive it. There is no
 * browser/canvas runtime under PHPUnit here — these are render-level
 * assertions on the compiled Blade output, proving the width-gate guard and
 * the captureSignature() wiring exist and the old destructive block is gone.
 * The actual mobile-keyboard and device-rotation behaviour is covered by the
 * manual phone checklist in this plan's checkpoint task.
 */
class PublicWorksheetSignaturePadResizeTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme Boardroom AV Refresh',
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Co.',
            'site_address' => '1 Test Street, London',
            'status'       => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => [
                    'name'         => 'Acme Boardroom AV Refresh',
                    'client_name'  => 'Acme Co.',
                    'site_address' => '1 Test Street, London',
                    'quote_reference' => 'Q-100001',
                ],
                'rooms' => [
                    [
                        'name'                    => 'Boardroom',
                        'is_surveyed'             => true,
                        'install_steps'           => '1. Mount display',
                        'cable_route_desc'        => 'Cable from rack to wall',
                        'power_outlet_count'      => 2,
                        'requires_additional_power' => false,
                        'network_port_count'      => 1,
                        'existing_cabling'        => 'Cat6 in floor box',
                        'equipment'               => [
                            ['name' => 'Samsung QM75B', 'quantity' => 1, 'part_no' => 'QM75B'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_public_worksheet_view_renders_with_width_gated_resize_handler(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('widthChanged', false);
    }

    public function test_public_worksheet_view_no_longer_contains_the_unconditional_resize_reset(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertDontSee('Reset on resize — drawing on resized canvas would be misaligned.', false);
    }

    public function test_public_worksheet_view_captures_signature_on_stroke_end(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('captureSignature', false);
        // Untouched checkbox/comments gate markers — proves this plan did not
        // regress must-have 4.
        $response->assertSee('refreshSignoffSubmitState', false);
        $response->assertSee('signed_with_comments', false);
    }
}

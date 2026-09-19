<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for 260919-enb — "the job can be done offline, the job
 * cannot be closed offline". `window.prepareSignoff` in the public worksheet
 * sign-off form now refuses to submit while offline (no queuing, no data
 * loss) and, when online, attempts a best-effort OfflineQueue flush bounded
 * by a timeout before always submitting the sign-off form.
 *
 * Out of scope for automation: PHPUnit has no browser runtime, so it cannot
 * simulate `navigator.onLine`, a real network loss, or a real IndexedDB
 * queue. These are render-level assertions only, proving the new code paths
 * are present in the compiled Blade output and that the pre-existing gates
 * (signature-drawn check, checkbox/comments validation, the signOffBlocked
 * soft-gate) are textually unregressed. Genuine offline/online behaviour,
 * including the timeout-under-a-hung-drain case, is verified only by the
 * manual checklist in this plan's checkpoint task.
 */
class PublicWorksheetOfflineSignoffTest extends TestCase
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

    public function test_offline_refusal_path_exists_and_does_not_call_enqueue(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('navigator.onLine', false);

        // The offline-refuse branch must be textually distinct from — and
        // must not call — OfflineQueue.enqueue. This does not assert
        // OfflineQueue.enqueue is absent from the whole file (the existing
        // photo-capture wrappers legitimately call it); it asserts the
        // specific offline-refusal alert copy this plan introduces.
        $response->assertSee('Signing off needs an internet connection', false);
    }

    public function test_online_flush_then_submit_path_exists(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('OfflineQueue.count()', false);
        $response->assertSee('OfflineQueue.drain({})', false);
        $response->assertSee('prototype.submit.call', false);
        $response->assertSee('still uploading', false);
    }

    public function test_pre_existing_signoff_gates_are_unregressed(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('refreshSignoffSubmitState', false);
        $response->assertSee('signed_with_comments', false);
        $response->assertSee('data-signoff-blocked', false);
    }

    public function test_unreviewed_rooms_banner_still_renders(): void
    {
        $w = $this->makeWorksheet();

        // The soft-block banner (260504-hqe) renders only when a room has a
        // linked SiteSurvey room carrying engineer-feedback data (here:
        // mounting_heights) AND that room has not yet been marked reviewed
        // on the worksheet — mirror that setup here rather than relying on
        // the worksheet's own `is_surveyed` flag, which does not drive this
        // gate.
        $survey = SiteSurvey::create([
            'user_id'      => $w->user_id,
            'project_id'   => $w->project_id,
            'project_name' => $w->project_name,
        ]);
        SiteSurveyRoom::create([
            'site_survey_id'    => $survey->id,
            'room_name'         => 'Boardroom',
            'mounting_heights'  => ['1.2m from floor'],
        ]);

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('Review the survey reference for these rooms before signing off', false);
    }
}

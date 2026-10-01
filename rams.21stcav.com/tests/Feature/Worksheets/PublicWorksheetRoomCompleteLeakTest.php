<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-46.7-04-01 — the unauthenticated engineer link rendered
 * `pre_install_confirmations.room_complete.{room}.completed_by` RAW, in two places:
 * a title attribute on the "✓ Complete" pill and the visible "Room Complete by ..." banner
 * text. The value is never a person's name — markRoomComplete() writes it as
 * `ip:{addr}|actor:{12-char hex}` (M-06, 2026-07), an audit-correlation stamp, the same
 * shape RV-03 already bans for `device_label_photos.captured_by` and
 * `worksheet_signoffs.ip_address` / `.user_agent`. This is the fourth column of that shape,
 * and the first one sitting outside CockpitEvidencePresenter's reach entirely — on the
 * engineer's own public page, which a CLIENT may also open and sign.
 *
 * The fix is render-only: the view stops echoing `completed_by`. The completion FACT
 * (the "Complete" pill / "Room Complete" banner) and its DATE must still render — this
 * test proves both the removal and the non-regression in one place, over a realistic
 * current-shape value AND a legacy-shaped value (no ip:/actor: prefix at all), so the fix
 * is "never render this key", not "never render this shape".
 */
class PublicWorksheetRoomCompleteLeakTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(string $completedBy): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

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
                        'name'                      => 'Main Hall',
                        'is_surveyed'               => true,
                        'install_steps'             => '',
                        'cable_route_desc'          => '',
                        'power_outlet_count'        => 0,
                        'requires_additional_power' => false,
                        'network_port_count'        => 0,
                        'existing_cabling'          => '',
                        'equipment'                 => [],
                    ],
                ],
            ],
            'pre_install_confirmations' => [
                'room_complete' => [
                    'Main Hall' => [
                        'completed_at' => '2026-09-15T10:30:00+00:00',
                        'completed_by' => $completedBy,
                    ],
                ],
            ],
        ]);
    }

    public function test_room_complete_audit_stamp_never_renders_for_realistic_shape(): void
    {
        $completedBy = 'ip:203.0.113.4|actor:a1b2c3d4e5f6';

        $worksheet = $this->makeWorksheet($completedBy);

        // Non-vacuity: the raw stamp is genuinely present in the database fixture
        // before we assert its absence from the rendered page.
        $this->assertSame($completedBy, $worksheet->roomCompletedBy('Main Hall'));

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();

        // The leak: neither render site may echo any part of the audit stamp.
        $response->assertDontSee('203.0.113.4', escape: false);
        $response->assertDontSee('a1b2c3d4e5f6', escape: false);
        $response->assertDontSee('actor:', escape: false);

        // The completion FACT and its DATE must still render — only the actor stamp goes.
        $response->assertSee('Complete', escape: false);
        $response->assertSee('15 Sep 2026', escape: false);
    }

    public function test_room_complete_audit_stamp_never_renders_for_legacy_shape(): void
    {
        // Legacy-shaped value: a bare short string, no ip:/actor: prefix at all.
        $completedBy = 'abc12345';

        $worksheet = $this->makeWorksheet($completedBy);

        // Non-vacuity: confirm the legacy value is genuinely stored before asserting absence.
        $this->assertSame($completedBy, $worksheet->roomCompletedBy('Main Hall'));

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();

        // The fix must be "never render completed_by", not "never render the ip:/actor: shape" —
        // this bare legacy value must ALSO never appear.
        $response->assertDontSee('abc12345', escape: false);

        // The completion FACT and its DATE must still render.
        $response->assertSee('Complete', escape: false);
        $response->assertSee('15 Sep 2026', escape: false);
    }
}

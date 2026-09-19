<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for 260919-f8e — an offline-queued serial label photo
 * that finishes uploading on reconnect (auto-drain, "Retry all", or a
 * per-item retry) never surfaced the AI-extraction confirm/edit modal, so
 * the AI-read values sat unconfirmed and the engineer walked away believing
 * the serial was captured. This wires OfflineQueue.drain()'s existing
 * onSuccess hook to a sessionStorage-backed pending-confirm queue that
 * auto-opens the existing openLabelReview() modal.
 *
 * Out of scope for automation: actually simulating an offline capture, a
 * real IndexedDB drain(), sessionStorage persistence across an actual
 * browser reload, or the modal's visual chaining across multiple queued
 * labels. There is no browser/IndexedDB runtime under PHPUnit here — these
 * are render-level assertions proving the new code paths exist, are wired
 * into all three drain() call sites, and that the pre-existing
 * capture/confirm/reviewLabel/OfflineQueue code is textually unregressed.
 * Genuine offline-to-reconnect-to-confirm behaviour, multi-label chaining,
 * and the Cancel-then-reload-shows-prompt-again persistence are verified
 * only by the manual checklist in this plan's checkpoint task.
 */
class PublicWorksheetLabelConfirmOnReconnectTest extends TestCase
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

    public function test_public_worksheet_view_wires_pending_confirm_queue_markers(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('__lcHandleLabelUploadSuccess', false);
        $response->assertSee('__lcMaybePrompt', false);
        $response->assertSee('wsPendingLabelConfirms_', false);
    }

    public function test_public_worksheet_view_wires_onsuccess_into_all_three_drain_call_sites(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $html = $response->getContent();

        $count = substr_count($html, 'onSuccess: window.__lcHandleLabelUploadSuccess');

        $this->assertGreaterThanOrEqual(
            3,
            $count,
            'Expected at least 3 drain() call sites (auto-drain, Retry all, per-item retry) '
                . 'to pass onSuccess: window.__lcHandleLabelUploadSuccess, found ' . $count
        );
    }

    public function test_public_worksheet_view_threads_queued_and_onoverlayremoved_through_open_label_review(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee(
            'function openLabelReview({ photoId, token, photoUrl, extracted, queued, onOverlayRemoved }) {',
            false
        );
        $response->assertSee('onOverlayRemoved', false);
    }

    public function test_public_worksheet_view_pre_existing_capture_confirm_and_review_markers_unregressed(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('window.captureLabel', false);
        $response->assertSee('async function reviewLabel(photoId, token)', false);
        $response->assertSee("label-photos/' + photoId + '/confirm", false);
        $response->assertSee('OfflineQueue.enqueue', false);
    }

    public function test_public_worksheet_view_indexeddb_schema_version_unchanged(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('const DB_VERSION = 1;', false);
    }
}

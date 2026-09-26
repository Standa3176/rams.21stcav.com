<?php

namespace Tests\Feature\Assets;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 03 Task 1 — D-05's missing half.
 *
 * The CAPTURE has existed since Phase 4: a `DeviceLabelPhoto` is OCR'd,
 * confirmed, and its values are written onto a `Device`. What has never
 * existed is a place to READ it. This is the list.
 *
 * ── THE REGISTER IS WHAT WAS CAPTURED, NOT THE BILL OF MATERIALS ────────────
 *
 * A device with no serial, no MAC, no asset tag and no confirmed label photo
 * is a device NOBODY HAS BEEN TO YET. Putting it on the register would make
 * the register a copy of the quote, which the office already has.
 *
 * ── THE LEAK THIS FILE GUARDS ───────────────────────────────────────────────
 *
 * `device_label_photos.captured_by` holds `ip:…|actor:<sha256 slice>` and
 * ONCE HELD THE FIRST 8 HEX CHARS OF A WORKSHEET UUID TOKEN — a migration
 * (2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php) had to
 * null every legacy value. This screen reads device-label data, so the rule is
 * live here, not theoretical. Every leak assertion below seeds a REALISTIC
 * stamp and pairs the `assertDontSee` with a positive assertion, so the test
 * cannot pass because there was nothing to leak.
 *
 * @see app/Http/Controllers/ProjectAssetListController.php
 * @see resources/views/projects/asset-list.blade.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-05)
 */
class ProjectAssetListTest extends TestCase
{
    use RefreshDatabase;

    /** The realistic stamp every leak assertion is fired against. */
    private const ACTOR_STAMP = 'ip:10.0.0.1|actor:deadbeefcafe';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private ?User $staff = null;

    private function project(): Project
    {
        $this->staff = User::factory()->create();

        return Project::factory()->create(['user_id' => $this->staff->id]);
    }

    private function device(Project $project, array $attributes = []): Device
    {
        return Device::create(array_merge([
            'project_id'  => $project->id,
            'room_name'   => 'Boardroom',
            'description' => 'Display',
            'qty'         => 1,
        ], $attributes));
    }

    private function labelPhoto(Device $device, bool $confirmed): DeviceLabelPhoto
    {
        return DeviceLabelPhoto::create([
            'project_id'  => $device->project_id,
            'device_id'   => $device->id,
            'room_name'   => $device->room_name,
            'photo_path'  => 'worksheet-label-photos/label.jpg',
            'confirmed'   => $confirmed,
            'captured_at' => now(),
            'captured_by' => self::ACTOR_STAMP,
        ]);
    }

    private function viewAs(Project $project): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff ?? User::factory()->create())
            ->get(route('projects.asset-list', $project));
    }

    private function exportAs(Project $project): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff ?? User::factory()->create())
            ->get(route('projects.asset-list.export', $project));
    }

    // ── What is on the register ──────────────────────────────────────────────

    public function test_every_device_carrying_a_captured_identifier_appears_once_with_its_room(): void
    {
        $project = $this->project();

        $this->device($project, [
            'room_name'     => 'Boardroom',
            'description'   => 'Sony 85-inch display',
            'manufacturer'  => 'Sony',
            'model'         => 'FW-85BZ40H',
            'part_no'       => 'FW85BZ40H',
            'serial_number' => 'SN-BOARDROOM-0001',
        ]);
        $this->device($project, [
            'room_name'   => 'Reception',
            'description' => 'Yealink A30 bar',
            'mac_address' => '00:1A:2B:3C:4D:5E',
        ]);
        $this->device($project, [
            'room_name'   => 'Training Room',
            'description' => 'BrightSign player',
            'asset_tag'   => 'AV-ASSET-0042',
        ]);

        $response = $this->viewAs($project);

        $response->assertOk();
        $response->assertSee('SN-BOARDROOM-0001', false);
        $response->assertSee('00:1A:2B:3C:4D:5E', false);
        $response->assertSee('AV-ASSET-0042', false);
        $response->assertSee('Boardroom', false);
        $response->assertSee('Reception', false);
        $response->assertSee('Training Room', false);

        // Exactly once — a join that fanned out over label photos would show
        // the same asset twice and the office would count it twice.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'SN-BOARDROOM-0001'),
            'The asset appeared more than once — the register fanned out.',
        );
    }

    public function test_a_device_with_nothing_captured_is_not_on_the_register(): void
    {
        $project = $this->project();

        $this->device($project, ['description' => 'QUOTED BUT NEVER VISITED']);
        $captured = $this->device($project, [
            'description'   => 'Actually fitted unit',
            'serial_number' => 'SN-REAL-0001',
        ]);

        $response = $this->viewAs($project);

        $response->assertOk();
        // Non-vacuity: the register is not simply empty.
        $response->assertSee('SN-REAL-0001', false);
        $response->assertDontSee('QUOTED BUT NEVER VISITED', false);
        $this->assertNotNull($captured->id);
    }

    public function test_a_confirmed_label_photo_puts_a_device_on_the_register_and_an_unconfirmed_one_does_not(): void
    {
        $project = $this->project();

        $confirmed = $this->device($project, ['description' => 'Unit with a CONFIRMED label']);
        $this->labelPhoto($confirmed, true);

        $pending = $this->device($project, ['description' => 'Unit with a PENDING label only']);
        $this->labelPhoto($pending, false);

        $response = $this->viewAs($project);

        $response->assertOk();
        $response->assertSee('Unit with a CONFIRMED label', false);
        $response->assertDontSee('Unit with a PENDING label only', false);
    }

    public function test_a_project_with_nothing_captured_renders_a_named_empty_state(): void
    {
        $project = $this->project();
        $this->device($project, ['description' => 'Quoted only']);

        $response = $this->viewAs($project);

        $response->assertOk();
        $response->assertSee('No assets captured on site yet', false);
    }

    public function test_rows_group_by_room_and_sort_by_room_then_description(): void
    {
        $project = $this->project();

        $this->device($project, ['room_name' => 'Reception', 'description' => 'Zebra unit', 'serial_number' => 'S-4']);
        $this->device($project, ['room_name' => 'Boardroom', 'description' => 'Zulu unit',  'serial_number' => 'S-2']);
        $this->device($project, ['room_name' => 'Boardroom', 'description' => 'Alpha unit', 'serial_number' => 'S-1']);
        $this->device($project, ['room_name' => 'Reception', 'description' => 'Alpha unit', 'serial_number' => 'S-3']);

        $content = $this->viewAs($project)->assertOk()->getContent();

        $positions = [
            strpos($content, 'S-1'),
            strpos($content, 'S-2'),
            strpos($content, 'S-3'),
            strpos($content, 'S-4'),
        ];

        $sorted = $positions;
        sort($sorted);

        $this->assertSame(
            $sorted,
            $positions,
            'Rows are not ordered by room then description.',
        );
    }

    // ── The leak ─────────────────────────────────────────────────────────────

    public function test_the_capture_actor_stamp_never_reaches_the_asset_list(): void
    {
        $project = $this->project();

        $device = $this->device($project, [
            'description'   => 'Unit whose label was photographed',
            'serial_number' => 'SN-LEAK-GUARD-0001',
        ]);
        $this->labelPhoto($device, true);

        $response = $this->viewAs($project);

        $response->assertOk();
        // NON-VACUITY FIRST — the device whose photo carries the stamp IS on
        // the page, so there was genuinely something to leak.
        $response->assertSee('SN-LEAK-GUARD-0001', false);
        $response->assertDontSee(self::ACTOR_STAMP, false);
        $response->assertDontSee('deadbeefcafe', false);
        $response->assertDontSee('10.0.0.1', false);
    }

    public function test_the_capture_actor_stamp_never_reaches_the_csv_either(): void
    {
        $project = $this->project();

        $device = $this->device($project, [
            'description'   => 'Unit whose label was photographed',
            'serial_number' => 'SN-CSV-LEAK-0001',
        ]);
        $this->labelPhoto($device, true);

        $csv = $this->exportAs($project)->assertOk()->streamedContent();

        $this->assertStringContainsString('SN-CSV-LEAK-0001', $csv);
        $this->assertStringNotContainsString(self::ACTOR_STAMP, $csv);
        $this->assertStringNotContainsString('deadbeefcafe', $csv);
    }

    public function test_an_ocr_derived_value_is_escaped_on_the_page(): void
    {
        // Serials and part numbers reach `devices` from OCR of a photo taken
        // on an unauthenticated link. Treat them as untrusted text.
        $project = $this->project();
        $this->device($project, [
            'description'   => 'Unit with a hostile label',
            'serial_number' => '<script>alert(1)</script>',
        ]);

        $response = $this->viewAs($project);

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ── The CSV ──────────────────────────────────────────────────────────────

    public function test_the_csv_export_carries_a_header_row_and_the_same_assets_as_the_screen(): void
    {
        $project = $this->project();

        $this->device($project, [
            'room_name'     => 'Boardroom',
            'description'   => 'Sony 85-inch display',
            'manufacturer'  => 'Sony',
            'model'         => 'FW-85BZ40H',
            'part_no'       => 'FW85BZ40H',
            'qty'           => 2,
            'serial_number' => 'SN-CSV-0001',
            'mac_address'   => '00:1A:2B:3C:4D:5E',
            'asset_tag'     => 'AV-0042',
        ]);
        $this->device($project, ['description' => 'Quoted only, never visited']);

        $response = $this->exportAs($project);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type') ?? '');

        $csv = $response->streamedContent();

        // fputcsv quotes any field containing a space — the header line is
        // asserted EXACTLY as PHP writes it, not as it reads in a spec.
        $this->assertStringContainsString(
            'Room,Description,Manufacturer,Model,"Part No",Qty,"Serial Number","MAC Address","Asset Tag"',
            $csv,
        );
        $this->assertStringContainsString('SN-CSV-0001', $csv);
        $this->assertStringContainsString('00:1A:2B:3C:4D:5E', $csv);
        $this->assertStringContainsString('AV-0042', $csv);

        // Same set as the screen — a device off the register is off the CSV.
        $this->assertStringNotContainsString('Quoted only, never visited', $csv);
    }

    public function test_the_csv_row_count_matches_the_registers_row_count(): void
    {
        $project = $this->project();

        foreach (['S-1', 'S-2', 'S-3'] as $serial) {
            $this->device($project, ['description' => 'Unit ' . $serial, 'serial_number' => $serial]);
        }
        $this->device($project, ['description' => 'Off-register unit']);

        $csv = $this->exportAs($project)->assertOk()->streamedContent();

        $dataRows = array_values(array_filter(
            array_map('trim', explode("\n", $csv)),
            static fn (string $line): bool => $line !== '',
        ));

        // 1 header + 3 assets.
        $this->assertCount(4, $dataRows, 'The CSV and the screen disagree on the register.');
    }

    // ── Authorisation ────────────────────────────────────────────────────────

    public function test_an_unauthenticated_request_cannot_read_the_asset_list(): void
    {
        $project = $this->project();

        $this->get(route('projects.asset-list', $project))->assertRedirect(route('login'));
        $this->get(route('projects.asset-list.export', $project))->assertRedirect(route('login'));
    }
}

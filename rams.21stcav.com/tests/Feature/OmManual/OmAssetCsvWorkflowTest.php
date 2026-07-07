<?php

namespace Tests\Feature\OmManual;

use App\Models\Device;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Feature tests for the Asset Register CSV workflow (download pre-populated
 * template + upload completed CSV → bulk-update device rows).
 *
 * Contract under test:
 *   - Template download streams CSV with the exact header shape the import
 *     path expects — matched device_id column, fillable columns, plus the
 *     read-only reference columns (part_no / description / etc.)
 *   - Template rows pre-fill every column that's already populated on the
 *     device (so the user only has to fill in the blanks)
 *   - Import matches rows by device_id; unknown IDs and cross-project IDs
 *     are silently skipped (defence-in-depth against tampered spreadsheets)
 *   - Import writes ONLY the fillable columns — a user editing "description"
 *     in the template does not overwrite the source record's description
 *   - Rows with every fillable cell blank are skipped, not used to clear
 *     the existing values on the row (the "I only meant to update the
 *     Crestron rows" property)
 */
class OmAssetCsvWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeManualWithDevices(User $user, int $deviceCount = 2): array
    {
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'CSV Test Project',
            'client_name'  => 'CSV Client Ltd',
            'site_address' => '1 CSV Way, London',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $manual = OmManual::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
            'project_ref'  => 'CSV-REF-01',
            'status'       => 'draft',
        ]);

        $devices = [];
        for ($i = 1; $i <= $deviceCount; $i++) {
            $devices[] = Device::create([
                'project_id'   => $project->id,
                'room_name'    => "Room {$i}",
                'description'  => "Device {$i}",
                'part_no'      => "PART-{$i}",
                'manufacturer' => 'ACME',
                'qty'          => 1,
            ]);
        }

        return ['project' => $project, 'manual' => $manual, 'devices' => $devices];
    }

    public function test_template_download_streams_csv_with_expected_headers_and_prefilled_reference_columns(): void
    {
        $user = User::factory()->create();
        ['manual' => $manual, 'devices' => $devices] = $this->makeManualWithDevices($user, 2);

        // Populate one asset field so we can prove pre-filled cells survive the round-trip.
        $devices[0]->update(['serial_number' => 'SN-EXISTING-1']);

        $response = $this->actingAs($user)
            ->get(route('om-manuals.devices.csv-template', $manual));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition') ?? '');

        $body = $response->streamedContent();
        // BOM at head so Excel picks up UTF-8 encoding.
        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3));

        $lines = explode("\n", trim($body));
        $this->assertGreaterThanOrEqual(3, count($lines), 'Expect header + one row per device.');

        // Strip BOM off the header for the assertion.
        $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));
        $this->assertSame([
            'device_id', 'room_name', 'part_no', 'description', 'manufacturer',
            'serial_number', 'mac_address', 'ip_address', 'vlan', 'port',
            'firmware_version', 'asset_tag', 'commissioning_date', 'warranty_expiry',
        ], $header);

        // First data row should be device[0] with its pre-existing serial number.
        $row = str_getcsv($lines[1]);
        $this->assertSame((string) $devices[0]->id, $row[0]);
        $this->assertSame('Room 1',                  $row[1]);
        $this->assertSame('PART-1',                  $row[2]);
        $this->assertSame('Device 1',                $row[3]);
        $this->assertSame('SN-EXISTING-1',           $row[5]);
    }

    public function test_import_writes_only_fillable_columns_and_leaves_read_only_columns_untouched(): void
    {
        $user = User::factory()->create();
        ['manual' => $manual, 'devices' => $devices] = $this->makeManualWithDevices($user, 1);

        // A hostile row: user edits `description` and `part_no` to garbage,
        // but also fills in the legit fillable columns. Only the fillable
        // ones should land; the identifier columns must remain untouched.
        $csv = "\xEF\xBB\xBF"
            . "device_id,room_name,part_no,description,manufacturer,serial_number,mac_address,ip_address,vlan,port,firmware_version,asset_tag,commissioning_date,warranty_expiry\n"
            . "{$devices[0]->id},HACKED ROOM,HACKED-PART,HACKED DESC,HACKED MFG,SN-42,AA:BB:CC:00:11:22,10.10.5.5,20,GigE0/1,v1.2.3,ASSET-42,2026-08-15,2029-08-15\n";

        $file = UploadedFile::fake()->createWithContent('assets.csv', $csv);

        $response = $this->actingAs($user)
            ->post(route('om-manuals.devices.import-csv', $manual), ['asset_csv' => $file]);

        $response->assertRedirect(route('om-manuals.edit-devices', $manual));
        $response->assertSessionHas('success');

        $devices[0]->refresh();

        // Fillable columns updated.
        $this->assertSame('SN-42',              $devices[0]->serial_number);
        $this->assertSame('AA:BB:CC:00:11:22',  $devices[0]->mac_address);
        $this->assertSame('10.10.5.5',          $devices[0]->ip_address);
        $this->assertSame('20',                 $devices[0]->vlan);
        $this->assertSame('GigE0/1',            $devices[0]->port);
        $this->assertSame('v1.2.3',             $devices[0]->firmware_version);
        $this->assertSame('ASSET-42',           $devices[0]->asset_tag);
        $this->assertSame('2026-08-15',         $devices[0]->commissioning_date?->format('Y-m-d'));
        $this->assertSame('2029-08-15',         $devices[0]->warranty_expiry?->format('Y-m-d'));

        // Read-only columns untouched.
        $this->assertSame('Room 1',   $devices[0]->room_name);
        $this->assertSame('PART-1',   $devices[0]->part_no);
        $this->assertSame('Device 1', $devices[0]->description);
        $this->assertSame('ACME',     $devices[0]->manufacturer);
    }

    public function test_import_skips_rows_with_no_fillable_values_so_a_partial_upload_does_not_clear_others(): void
    {
        // The "I only meant to update the Crestron rows" property. A user
        // downloads the full template, edits only some rows, uploads the
        // whole file. Rows with every fillable cell blank must not have
        // their existing values wiped.
        $user = User::factory()->create();
        ['manual' => $manual, 'devices' => $devices] = $this->makeManualWithDevices($user, 2);

        // Row 1 already has a serial from a previous pass; the CSV leaves
        // every fillable cell blank for it. Row 2 gets fresh data.
        $devices[0]->update(['serial_number' => 'SN-KEEP-1', 'ip_address' => '10.0.0.1']);

        $csv = "device_id,room_name,part_no,description,manufacturer,serial_number,mac_address,ip_address,vlan,port,firmware_version,asset_tag,commissioning_date,warranty_expiry\n"
            . "{$devices[0]->id},Room 1,PART-1,Device 1,ACME,,,,,,,,\n"
            . "{$devices[1]->id},Room 2,PART-2,Device 2,ACME,SN-NEW-2,,,,,,,,\n";

        $file = UploadedFile::fake()->createWithContent('assets.csv', $csv);

        $this->actingAs($user)
            ->post(route('om-manuals.devices.import-csv', $manual), ['asset_csv' => $file]);

        $devices[0]->refresh();
        $devices[1]->refresh();

        // Row 1's pre-existing values preserved because its CSV row had no
        // fillable data.
        $this->assertSame('SN-KEEP-1', $devices[0]->serial_number);
        $this->assertSame('10.0.0.1',  $devices[0]->ip_address);

        // Row 2 updated as usual.
        $this->assertSame('SN-NEW-2',  $devices[1]->serial_number);
    }

    public function test_import_skips_rows_whose_device_id_belongs_to_a_different_project(): void
    {
        // Defence in depth against a tampered spreadsheet — a user can't
        // paste device_ids from another project's template and mutate rows
        // they don't own.
        $user = User::factory()->create();
        ['manual' => $manual] = $this->makeManualWithDevices($user, 1);

        // Second project owned by the same user (auth passes) with its own
        // device. We must NOT let a CSV row for that device land through
        // this OM's import endpoint.
        $otherProject = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Other Project',
            'client_name'  => 'Other',
            'site_address' => '2 Elsewhere',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);
        $otherDevice = Device::create([
            'project_id'   => $otherProject->id,
            'room_name'    => 'Other Room',
            'description'  => 'Other Device',
            'part_no'      => 'OTHER-PART',
            'manufacturer' => 'ACME',
            'qty'          => 1,
        ]);

        $csv = "device_id,room_name,part_no,description,manufacturer,serial_number,mac_address,ip_address,vlan,port,firmware_version,asset_tag,commissioning_date,warranty_expiry\n"
            . "{$otherDevice->id},X,X,X,X,SN-CROSS,,,,,,,,\n";

        $file = UploadedFile::fake()->createWithContent('assets.csv', $csv);

        $this->actingAs($user)
            ->post(route('om-manuals.devices.import-csv', $manual), ['asset_csv' => $file]);

        $otherDevice->refresh();
        $this->assertNull($otherDevice->serial_number,
            'Cross-project device_id must be silently skipped, not written.');
    }

    public function test_import_rejects_csv_missing_the_device_id_header(): void
    {
        $user = User::factory()->create();
        ['manual' => $manual] = $this->makeManualWithDevices($user, 1);

        $csv = "room_name,part_no,serial_number\nRoom 1,PART-1,SN-BROKEN\n";

        $file = UploadedFile::fake()->createWithContent('bad.csv', $csv);

        $response = $this->actingAs($user)
            ->post(route('om-manuals.devices.import-csv', $manual), ['asset_csv' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('device_id', session('error') ?? '');
    }

    public function test_import_rejects_when_no_file_uploaded(): void
    {
        $user = User::factory()->create();
        ['manual' => $manual] = $this->makeManualWithDevices($user, 1);

        $response = $this->actingAs($user)
            ->post(route('om-manuals.devices.import-csv', $manual), []);

        $response->assertRedirect();
        $response->assertSessionHasErrors('asset_csv');
    }
}

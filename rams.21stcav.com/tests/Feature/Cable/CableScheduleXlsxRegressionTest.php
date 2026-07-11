<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use App\Services\CableScheduleXlsxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 22 D-10 invariant: the 5 new port-FK + override-note columns on
 * `cable_schedule_items` MUST NOT change CableScheduleXlsxService output
 * one byte.
 *
 * The XLSX surface reads only cable_id / from_location / to_location /
 * cable_type / cores / approx_length_m / notes — verified by grep against
 * app/Services/CableScheduleXlsxService.php (no `source_port` / `dest_device`
 * / `connector_override` substrings present in the source).
 *
 * Pitfall 1 protection: if anyone adds `protected $with = ['sourcePort', ...]`
 * to CableScheduleItem, every read fires 4 LEFT JOINs against device_ports +
 * devices. The query-log test asserts the XLSX render does NOT touch
 * device_ports — proves $with stays empty.
 *
 * @see app/Services/CableScheduleXlsxService.php
 * @see app/Models/CableScheduleItem.php (D-10 guard comment block)
 */
class CableScheduleXlsxRegressionTest extends TestCase
{
    use RefreshDatabase;

    // T1-A (2026-07-11): XLSX now has 9 columns (Signal inserted between
    // Cable Type and Cores). Byte-identity still holds because both
    // fixtures leave signal_type NULL → both render an empty Signal cell.
    public function test_xlsx_byte_identical_for_null_and_populated_fks(): void
    {
        // PhpSpreadsheet is a runtime dependency of CableScheduleXlsxService.
        // On dev machines without it installed, skip cleanly — mirrors the
        // D2-binary skip pattern in SchematicGeneratorServiceTest (lines 93-96).
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet not installed in this environment; XLSX byte-identity regression skipped.');
        }

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Fixture A: 3 items with NULL FKs (legacy shape).
        $scheduleA = CableSchedule::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'project_name' => 'Byte Test', 'project_ref' => 'BYTE-01',
            'client_name' => 'Acme', 'status' => CableSchedule::STATUS_DRAFT,
        ]);
        foreach (range(1, 3) as $i) {
            CableScheduleItem::create([
                'cable_schedule_id' => $scheduleA->id,
                'cable_id'          => "CAB-{$i}",
                'from_location'     => "From {$i}",
                'to_location'       => "To {$i}",
                'cable_type'        => 'HDMI',
                'cores'             => '4',
                'approx_length_m'   => 10.5,
                'notes'             => "Note {$i}",
                'sort_order'        => $i,
            ]);
        }

        // Fixture B: SAME visible columns + populated Phase 22 FK columns.
        $device = Device::create([
            'project_id' => $project->id, 'description' => 'Acme X',
            'manufacturer' => 'Acme', 'model' => 'X', 'part_no' => 'X-1', 'qty' => 1,
        ]);
        $stencil = DeviceStencil::create([
            'part_number' => 'x-1', 'manufacturer' => 'Acme', 'model' => 'X',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        $port = DevicePort::create([
            'device_stencil_id' => $stencil->id, 'label' => 'HDMI 1',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);

        $scheduleB = CableSchedule::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'project_name' => 'Byte Test', 'project_ref' => 'BYTE-01',
            'client_name' => 'Acme', 'status' => CableSchedule::STATUS_DRAFT,
        ]);
        foreach (range(1, 3) as $i) {
            CableScheduleItem::create([
                'cable_schedule_id' => $scheduleB->id,
                'cable_id'          => "CAB-{$i}",
                'from_location'     => "From {$i}",
                'to_location'       => "To {$i}",
                'cable_type'        => 'HDMI',
                'cores'             => '4',
                'approx_length_m'   => 10.5,
                'notes'             => "Note {$i}",
                'sort_order'        => $i,
                // ── Phase 22 FK columns — should be INVISIBLE to the XLSX ─────
                'source_device_id'        => $device->id,
                'source_port_id'          => $port->id,
                'dest_device_id'          => $device->id,
                'dest_port_id'            => $port->id,
                'connector_override_note' => 'test override',
            ]);
        }

        $svc = app(CableScheduleXlsxService::class);

        // The service includes a "Generated: <today>" line in the project info
        // row — both renders happen in the same test so the date is identical.
        $pathA = $svc->build($scheduleA->fresh()->load('items'));
        $pathB = $svc->build($scheduleB->fresh()->load('items'));

        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);

        $this->assertSame(
            hash_file('sha256', $pathA),
            hash_file('sha256', $pathB),
            'D-10 invariant violated: XLSX byte-output changed when FK columns were populated. '
            . 'CableScheduleXlsxService must read ONLY the legacy text columns.'
        );
    }

    public function test_xlsx_export_query_log_does_not_touch_device_ports(): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet not installed in this environment; Pitfall-1 query-log regression skipped.');
        }

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $device = Device::create([
            'project_id' => $project->id, 'description' => 'Acme X',
            'manufacturer' => 'Acme', 'model' => 'X', 'part_no' => 'X-1', 'qty' => 1,
        ]);
        $stencil = DeviceStencil::create([
            'part_number' => 'x-1', 'manufacturer' => 'Acme', 'model' => 'X',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        $port = DevicePort::create([
            'device_stencil_id' => $stencil->id, 'label' => 'HDMI 1',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);

        $schedule = CableSchedule::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'project_name' => 'Query Log', 'status' => CableSchedule::STATUS_DRAFT,
        ]);
        CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id,
            'cable_id' => 'CAB-1', 'from_location' => 'A', 'to_location' => 'B',
            'cable_type' => 'HDMI',
            'source_device_id' => $device->id, 'source_port_id' => $port->id,
            'dest_device_id'   => $device->id, 'dest_port_id'   => $port->id,
            'sort_order' => 0,
        ]);

        // Discard fixture-setup queries (I1) so the assertion targets the
        // XLSX render-time query log only.
        DB::enableQueryLog();
        DB::flushQueryLog();

        $svc = app(CableScheduleXlsxService::class);
        $svc->build($schedule->fresh()->load('items'));

        $log = DB::getQueryLog();
        $this->assertNotEmpty($log, 'Expected at least one query during XLSX render (the source_filename update).');

        foreach ($log as $entry) {
            $q = (string) $entry['query'];
            $this->assertStringNotContainsStringIgnoringCase(
                'device_ports',
                $q,
                'D-10 invariant violated: XLSX render touched device_ports. '
                . 'CableScheduleItem::$with must stay empty — eager-load at the call site only. Query: ' . $q
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'left join',
                $q,
                'D-10 invariant violated: XLSX render fired a LEFT JOIN. '
                . 'CableScheduleItem::$with must stay empty. Query: ' . $q
            );
        }

        DB::disableQueryLog();
    }

    /**
     * Source-level D-10 guard: the v1.3 surface files MUST NOT reference any
     * of the 5 Phase 22 columns (source_device_id, source_port_id,
     * dest_device_id, dest_port_id, connector_override_note). This test runs
     * everywhere (no runtime deps) so it's the canary that fires first if
     * anyone wires the new columns into the legacy XLSX / schematic stack.
     *
     * NB (T2-A / 260711-n2x): CableScheduleGeneratorService.php is intentionally
     * REMOVED from surfaceFiles because T2-A opts the generator into writing
     * source_device_id / source_port_id (flat path) — the whole point of T2-A
     * is that the deterministic generator now emits port-level FKs at write
     * time so the port-picker preselects without a follow-up
     * cables:backfill-port-fks run. The XLSX + schematic stack remains guarded
     * — those v1.3 read paths still must not couple to the FK columns.
     */
    public function test_v13_surface_files_have_zero_phase22_column_references(): void
    {
        $surfaceFiles = [
            base_path('app/Services/CableScheduleXlsxService.php'),
            // CableScheduleGeneratorService.php removed 260711-n2x — see docblock above.
            base_path('app/Services/Drawings/SchematicGeneratorService.php'),
            base_path('app/Services/Drawings/SchematicD2SourceBuilder.php'),
            base_path('app/Services/Drawings/DrawingDataResolverService.php'),
        ];

        // The 5 Phase 22 column names are unique strings; we forbid them
        // outright. Relationship METHOD invocations (->sourcePort(),
        // ->destDevice() etc.) are forbidden too — but the bare camelCase
        // tokens 'sourcePort' / 'destPort' are NOT, because v1.3's
        // SchematicD2SourceBuilder uses those as local variables holding
        // source_port / dest_port STRINGS from extracted_data['cables']
        // (Phase 17, pre-Phase 22 — unrelated to the new FK relationships).
        $forbiddenSubstrings = [
            'source_device_id',
            'source_port_id',
            'dest_device_id',
            'dest_port_id',
            'connector_override_note',
            '->sourceDevice',
            '->sourcePort',
            '->destDevice',
            '->destPort',
        ];

        foreach ($surfaceFiles as $path) {
            $this->assertFileExists($path, "v1.3 surface file missing: {$path}");
            $contents = file_get_contents($path);
            foreach ($forbiddenSubstrings as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "D-10 invariant violated: {$path} references Phase 22 column/relation '{$needle}'. "
                    . 'The v1.3 surface must remain unchanged by Phase 22. '
                    . 'See app/Models/CableScheduleItem.php — eager-load at the call site only.'
                );
            }
        }
    }
}

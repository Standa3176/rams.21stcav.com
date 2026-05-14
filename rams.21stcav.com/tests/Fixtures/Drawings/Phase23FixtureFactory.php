<?php

namespace Tests\Fixtures\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

/**
 * Phase 23 deterministic fixture factory. Returns Project instances loaded
 * with the relations Plan 02-06 tests need. Each factory is idempotent:
 * runs against ::refreshDatabase. Stencil rows are sourced from the Phase 21
 * seed pack via DeviceStencilSeeder — fixtures attach FK relations only.
 *
 * Per 23-RESEARCH.md "Fixture projects (4)" lines 759-765.
 *
 * Determinism contract (per CONTEXT D-LOCK-5/6):
 *   - No Str::random / no now() in fixture data.
 *   - All names / refs / metadata use stable hard-coded strings.
 *   - User created via firstOrCreate(email) so subsequent calls reuse the same row.
 *   - Equipment list ordering is hard-coded — calling smallMtr() twice produces
 *     two Projects with identical extracted_data shape in identical order.
 *   - Plan 23-01 Task 3 XtenAvDeterminismHarnessTest verifies this for smallMtr.
 *
 * Stencil resolution strategy:
 *   - Each factory references seed-pack part_numbers verbatim. If the seeder
 *     hasn't run in the test DB, the factory invokes `db:seed --class=DeviceStencilSeeder`
 *     idempotently (the seeder is itself idempotent per Plan 21-02). Without
 *     this, Project::devicesWithStencils() would auto-create Tier 1 placeholders
 *     on first call — the second call would hit cache, but the first-call
 *     side effect would differ from the second, breaking determinism on
 *     factory-construction-time vs first-render-time.
 */
class Phase23FixtureFactory
{
    /**
     * Small Teams Room — 5 curated devices + 6 port-to-port cables.
     *
     * Happy path for DRAW-42/43/44/45. All 5 part_numbers map to the
     * spike-promoted Tier 2 stencils (mxgraph_xml carries <constraint>
     * elements per Plan 21-02 Step A) so port-to-port routing exercises
     * the OQ-4 Path A branch.
     *
     * Devices: Neat Bar Pro / Samsung QM65C-T / ClickShare Bar Pro /
     *          Sennheiser TCC2 / Netgear GS312TP
     */
    public static function smallMtr(): Project
    {
        self::ensureSeeded();

        $user = self::user();

        $equipment = [
            [
                'part_number'  => 'neat-bar-pro',
                'manufacturer' => 'Neat',
                'model'        => 'Bar Pro',
                'name'         => 'Neat Bar Pro Videobar',
                'quantity'     => 1,
                'area'         => 'Boardroom',
                'category'     => 'hardware',
            ],
            [
                'part_number'  => 'samsung-qm65c-t',
                'manufacturer' => 'Samsung',
                'model'        => 'QM65C-T',
                'name'         => 'Samsung QM65 Display',
                'quantity'     => 1,
                'area'         => 'Boardroom',
                'category'     => 'hardware',
            ],
            [
                'part_number'  => 'bar-pro',
                'manufacturer' => 'Barco',
                'model'        => 'ClickShare Bar Pro',
                'name'         => 'ClickShare Bar Pro BYOD',
                'quantity'     => 1,
                'area'         => 'Boardroom',
                'category'     => 'hardware',
            ],
            [
                'part_number'  => 'sennheiser-tcc2',
                'manufacturer' => 'Sennheiser',
                'model'        => 'TeamConnect Ceiling 2',
                'name'         => 'Sennheiser TCC2 Ceiling Microphone',
                'quantity'     => 1,
                'area'         => 'Boardroom',
                'category'     => 'hardware',
            ],
            [
                'part_number'  => 'gs312tp',
                'manufacturer' => 'Netgear',
                'model'        => 'GS312TP',
                'name'         => 'Netgear GS312TP Rack Switch',
                'quantity'     => 1,
                'area'         => 'Boardroom',
                'category'     => 'hardware',
            ],
        ];

        $project = Project::factory()->create([
            'user_id'         => $user->id,
            'name'            => 'Fixture: Small Teams Room',
            'client_name'     => 'Fixture Client Ltd',
            'site_address'    => '1 Fixture Way, London',
            'quote_reference' => 'Q-FIX-SMTR-001',
            'status'          => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'fixture-small-mtr.pdf',
            'quote_path'        => 'fixtures/small-mtr.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Fixture small Teams Room install.',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);

        // Create devices + cable schedule with port-to-port FKs.
        $devices  = self::createDevicesForProject($project, $equipment);
        $schedule = self::createScheduleForProject($project, $user);
        self::wireSmallMtrCables($schedule, $devices);

        return $project->fresh();
    }

    /**
     * Boardroom — ~30 devices across RACK + CEILING + WALL + TABLE zones,
     * ~25 cables. Below D-06 threshold on most signal types -> no sub-sheets
     * (DRAW-47 below-threshold negative case).
     *
     * Uses the 5 spike stencils + 5 Tier 1.5 Crestron/Netgear stencils to
     * exercise the OQ-4 Path B device-edge fallback alongside happy-path
     * port routing.
     */
    public static function boardroom(): Project
    {
        self::ensureSeeded();
        $user = self::user();

        $equipment = self::boardroomEquipment();

        $project = Project::factory()->create([
            'user_id'         => $user->id,
            'name'            => 'Fixture: Boardroom',
            'client_name'     => 'Fixture Client Ltd',
            'site_address'    => '2 Fixture Way, London',
            'quote_reference' => 'Q-FIX-BRDM-001',
            'status'          => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'fixture-boardroom.pdf',
            'quote_path'        => 'fixtures/boardroom.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Fixture boardroom install.',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);

        $devices  = self::createDevicesForProject($project, $equipment);
        $schedule = self::createScheduleForProject($project, $user);
        self::wireBoardroomCables($schedule, $devices);

        return $project->fresh();
    }

    /**
     * Paging system — ~12 devices, ~15 cables (>=5 per signal type) ->
     * emits multiple sub-sheets (DRAW-47 above-threshold positive case).
     */
    public static function pagingSystem(): Project
    {
        self::ensureSeeded();
        $user = self::user();

        $equipment = self::pagingSystemEquipment();

        $project = Project::factory()->create([
            'user_id'         => $user->id,
            'name'            => 'Fixture: Paging System',
            'client_name'     => 'Fixture Client Ltd',
            'site_address'    => '3 Fixture Way, London',
            'quote_reference' => 'Q-FIX-PGNG-001',
            'status'          => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'fixture-paging.pdf',
            'quote_path'        => 'fixtures/paging.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Fixture paging system install.',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);

        $devices  = self::createDevicesForProject($project, $equipment);
        $schedule = self::createScheduleForProject($project, $user);
        self::wirePagingCables($schedule, $devices);

        return $project->fresh();
    }

    /**
     * Legacy NULL-FK — 8 devices + 6 cables, 3 of which have ALL 4 port FKs
     * NULL but populated from_location / to_location text. Exercises D-07
     * NULL-FK fallback ladder.
     */
    public static function legacyNullFk(): Project
    {
        self::ensureSeeded();
        $user = self::user();

        $equipment = self::legacyEquipment();

        $project = Project::factory()->create([
            'user_id'         => $user->id,
            'name'            => 'Fixture: Legacy NULL-FK',
            'client_name'     => 'Fixture Client Ltd',
            'site_address'    => '4 Fixture Way, London',
            'quote_reference' => 'Q-FIX-LGCY-001',
            'status'          => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'fixture-legacy.pdf',
            'quote_path'        => 'fixtures/legacy.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Legacy NULL-FK fixture.',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);

        $devices  = self::createDevicesForProject($project, $equipment);
        $schedule = self::createScheduleForProject($project, $user);
        self::wireLegacyCables($schedule, $devices);

        return $project->fresh();
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    /**
     * Idempotent user creation. firstOrCreate keeps determinism — calling
     * smallMtr() twice reuses the same User row.
     */
    private static function user(): User
    {
        return User::firstOrCreate(
            ['email' => 'phase23-fixture@example.test'],
            [
                'name'     => 'Phase 23 Fixture User',
                'password' => 'fixture',
            ],
        );
    }

    /**
     * Idempotently seed stencils. Phase 23 fixtures reference part_numbers
     * from Phase 21's seed pack — without these rows, Project::devicesWithStencils()
     * would auto-create Tier 1 placeholders on first read, breaking
     * determinism between factory build time and renderer read time.
     *
     * The seeder is itself idempotent (Plan 21-02): rerunning produces zero
     * new rows. Cheap to call on every fixture build.
     */
    private static function ensureSeeded(): void
    {
        if (DeviceStencil::count() === 0) {
            \Artisan::call('db:seed', ['--class' => 'DeviceStencilSeeder', '--force' => true]);
        }
    }

    /**
     * Create Device rows with FK to the seeded DeviceStencil rows. Returns
     * a part_number => Device map for cable wiring.
     *
     * @return array<string, Device>
     */
    private static function createDevicesForProject(Project $project, array $equipment): array
    {
        $map = [];
        $i   = 0;
        foreach ($equipment as $line) {
            $i++;
            $device = Device::create([
                'project_id'   => $project->id,
                'description'  => $line['name'],
                'manufacturer' => $line['manufacturer'],
                'model'        => $line['model'],
                'part_no'      => $line['part_number'],
                'room_name'    => $line['area'] ?? 'Fixture Room',
                'qty'          => $line['quantity'],
            ]);
            $map[$line['part_number']] = $device;
        }

        return $map;
    }

    private static function createScheduleForProject(Project $project, User $user): CableSchedule
    {
        return CableSchedule::create([
            'user_id'         => $user->id,
            'project_id'      => $project->id,
            'project_ref'     => $project->quote_reference ?? 'Q-FIX-000',
            'project_name'    => $project->name,
            'client_name'     => $project->client_name,
            'source_filename' => 'fixture-schedule-' . $project->id . '.xlsx',
            'status'          => CableSchedule::STATUS_GENERATING,
        ]);
    }

    /**
     * Looks up a port by stencil part_number + port_id slug. Returns null if
     * the seeded stencil has no matching port (Tier 1.5 stencils carry no
     * port rows by design — used to test OQ-4 Path B fallback).
     */
    private static function port(string $partNumber, string $portId): ?DevicePort
    {
        $stencil = DeviceStencil::where('part_number', $partNumber)->first();
        if ($stencil === null) {
            return null;
        }

        return DevicePort::where('device_stencil_id', $stencil->id)
            ->where('port_id', $portId)
            ->first();
    }

    private static function wireSmallMtrCables(CableSchedule $schedule, array $devices): void
    {
        // Canonical Teams Room signal chain: Neat -> Display (HDMI), Neat -> ClickShare (USB-C),
        // Sennheiser -> Neat (audio in / lan), Netgear -> Neat (LAN), Netgear -> ClickShare (LAN),
        // Netgear -> Sennheiser (PoE).
        $rows = [
            [
                'cable_id'         => 'HDMI-001',
                'cable_type'       => 'HDMI',
                'src_pn'           => 'neat-bar-pro',
                'src_port'         => 'hdmi-out',
                'dst_pn'           => 'samsung-qm65c-t',
                'dst_port'         => 'hdmi-1',
                'from'             => 'Neat Bar Pro (HDMI Out)',
                'to'               => 'Samsung QM65 (HDMI 1)',
            ],
            [
                'cable_id'         => 'USB-001',
                'cable_type'       => 'USB-C',
                'src_pn'           => 'bar-pro',
                'src_port'         => 'usb-c',
                'dst_pn'           => 'neat-bar-pro',
                'dst_port'         => 'usb-c',
                'from'             => 'ClickShare Bar Pro (USB-C)',
                'to'               => 'Neat Bar Pro (USB-C)',
            ],
            [
                'cable_id'         => 'LAN-001',
                'cable_type'       => 'CAT6',
                'src_pn'           => 'gs312tp',
                'src_port'         => 'port-1',
                'dst_pn'           => 'neat-bar-pro',
                'dst_port'         => 'lan',
                'from'             => 'Netgear GS312TP (Port 1)',
                'to'               => 'Neat Bar Pro (LAN)',
            ],
            [
                'cable_id'         => 'LAN-002',
                'cable_type'       => 'CAT6',
                'src_pn'           => 'gs312tp',
                'src_port'         => 'port-2',
                'dst_pn'           => 'sennheiser-tcc2',
                'dst_port'         => 'lan',
                'from'             => 'Netgear GS312TP (Port 2)',
                'to'               => 'Sennheiser TCC2 (LAN)',
            ],
            [
                'cable_id'         => 'LAN-003',
                'cable_type'       => 'CAT6',
                'src_pn'           => 'gs312tp',
                'src_port'         => 'port-3',
                'dst_pn'           => 'bar-pro',
                'dst_port'         => 'lan',
                'from'             => 'Netgear GS312TP (Port 3)',
                'to'               => 'ClickShare Bar Pro (LAN)',
            ],
            [
                'cable_id'         => 'AUDIO-001',
                'cable_type'       => 'XLR',
                'src_pn'           => 'sennheiser-tcc2',
                'src_port'         => 'audio-out',
                'dst_pn'           => 'neat-bar-pro',
                'dst_port'         => 'audio-in',
                'from'             => 'Sennheiser TCC2 (Audio Out)',
                'to'               => 'Neat Bar Pro (Audio In)',
            ],
        ];

        $i = 0;
        foreach ($rows as $row) {
            $i++;
            $srcDevice = $devices[$row['src_pn']] ?? null;
            $dstDevice = $devices[$row['dst_pn']] ?? null;
            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'cable_id'          => $row['cable_id'],
                'from_location'     => $row['from'],
                'to_location'       => $row['to'],
                'cable_type'        => $row['cable_type'],
                'cores'             => null,
                'approx_length_m'   => 5,
                'sort_order'        => $i,
                'source_device_id'  => $srcDevice?->id,
                'source_port_id'    => self::port($row['src_pn'], $row['src_port'])?->id,
                'dest_device_id'    => $dstDevice?->id,
                'dest_port_id'      => self::port($row['dst_pn'], $row['dst_port'])?->id,
            ]);
        }
    }

    private static function wireBoardroomCables(CableSchedule $schedule, array $devices): void
    {
        // 10 cables — below the D-06 thresholds of >=5 cables per signal AND
        // >=3 devices touching that signal — so SheetPaginator emits ONLY the
        // system overview (no sub-sheets). DRAW-47 below-threshold case.
        $rows = [
            ['HDMI-001',  'HDMI',  'neat-bar-pro',     'samsung-qm65c-t'],
            ['HDMI-002',  'HDMI',  'neat-bar-pro',     'samsung-qm65c-t'],
            ['USB-001',   'USB-C', 'bar-pro',          'neat-bar-pro'],
            ['LAN-001',   'CAT6',  'gs312tp',          'neat-bar-pro'],
            ['LAN-002',   'CAT6',  'gs312tp',          'sennheiser-tcc2'],
            ['LAN-003',   'CAT6',  'gs312tp',          'tss-1070-b-s-lb-kit'],
            ['LAN-004',   'CAT6',  'gs312tp',          'cen-odt-c-poe'],
            ['AUDIO-001', 'XLR',   'sennheiser-tcc2',  'neat-bar-pro'],
            ['HDMI-003',  'HDMI',  'am3-111-ikit',     'samsung-qm65c-t'],
            ['LAN-005',   'CAT6',  'gs312tp',          'am3-111-ikit'],
        ];

        self::insertSimpleCables($schedule, $devices, $rows);
    }

    private static function wirePagingCables(CableSchedule $schedule, array $devices): void
    {
        // Above-threshold: emits audio + network sub-sheets.
        // 7 audio cables + 7 network cables + 1 control cable (control
        // below threshold) = 15 cables total, >=5 per major signal type.
        $rows = [
            // audio chain (7)
            ['AUDIO-001', 'XLR',  'sennheiser-tcc2',  'neat-bar-pro'],
            ['AUDIO-002', 'XLR',  'sennheiser-tcc2',  'gs312tp'],
            ['AUDIO-003', 'XLR',  'sennheiser-tcc2',  'samsung-qm65c-t'],
            ['AUDIO-004', 'XLR',  'sennheiser-tcc2',  'bar-pro'],
            ['AUDIO-005', 'XLR',  'sennheiser-tcc2',  'tss-1070-b-s-lb-kit'],
            ['AUDIO-006', 'XLR',  'sennheiser-tcc2',  'am3-111-ikit'],
            ['AUDIO-007', 'XLR',  'sennheiser-tcc2',  'cen-odt-c-poe'],
            // network chain (7)
            ['LAN-001',   'CAT6', 'gs312tp',          'neat-bar-pro'],
            ['LAN-002',   'CAT6', 'gs312tp',          'sennheiser-tcc2'],
            ['LAN-003',   'CAT6', 'gs312tp',          'tss-1070-b-s-lb-kit'],
            ['LAN-004',   'CAT6', 'gs312tp',          'am3-111-ikit'],
            ['LAN-005',   'CAT6', 'gs312tp',          'cen-odt-c-poe'],
            ['LAN-006',   'CAT6', 'gs312tp',          'bar-pro'],
            ['LAN-007',   'CAT6', 'gs312tp',          'samsung-qm65c-t'],
            // control (1)
            ['CTRL-001',  'RS232', 'tss-1070-b-s-lb-kit', 'samsung-qm65c-t'],
        ];

        self::insertSimpleCables($schedule, $devices, $rows);
    }

    private static function wireLegacyCables(CableSchedule $schedule, array $devices): void
    {
        // 6 cables — 3 with FKs populated, 3 with all-NULL FKs but populated
        // from_location/to_location text. Exercises D-07 fallback ladder.
        $populated = [
            ['LAN-001', 'CAT6', 'gs312tp', 'neat-bar-pro'],
            ['LAN-002', 'CAT6', 'gs312tp', 'sennheiser-tcc2'],
            ['LAN-003', 'CAT6', 'gs312tp', 'bar-pro'],
        ];
        self::insertSimpleCables($schedule, $devices, $populated);

        $nullRows = [
            ['cable_id' => 'LGCY-001', 'cable_type' => 'CAT6', 'from' => 'Patch Panel A (1)', 'to' => 'Cabinet 1 (Switch Port 12)'],
            ['cable_id' => 'LGCY-002', 'cable_type' => 'HDMI', 'from' => 'Wall Plate (HDMI)', 'to' => 'Projector (HDMI 1)'],
            ['cable_id' => 'LGCY-003', 'cable_type' => 'XLR',  'from' => 'Mic Pool',          'to' => 'Mixing Desk Channel 4'],
        ];

        $i = count($populated);
        foreach ($nullRows as $row) {
            $i++;
            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'cable_id'          => $row['cable_id'],
                'from_location'     => $row['from'],
                'to_location'       => $row['to'],
                'cable_type'        => $row['cable_type'],
                'approx_length_m'   => 10,
                'sort_order'        => $i,
                'source_device_id'  => null,
                'source_port_id'    => null,
                'dest_device_id'    => null,
                'dest_port_id'      => null,
            ]);
        }
    }

    /**
     * Bulk-create cable rows where every row resolves to known stencils with
     * matching ports. Used by boardroom / paging-system / legacy-populated.
     * Rows whose port lookup misses (Tier 1.5 stencils have no DevicePort
     * rows seeded) write NULL into source_port_id / dest_port_id — exactly
     * the D-07 fallback case Plan 03 exercises.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows  [cable_id, cable_type, src_pn, dst_pn]
     */
    private static function insertSimpleCables(CableSchedule $schedule, array $devices, array $rows): void
    {
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            [$cableId, $cableType, $srcPn, $dstPn] = $row;
            $srcDevice = $devices[$srcPn] ?? null;
            $dstDevice = $devices[$dstPn] ?? null;
            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'cable_id'          => $cableId,
                'from_location'     => $srcPn . ' (port)',
                'to_location'       => $dstPn . ' (port)',
                'cable_type'        => $cableType,
                'approx_length_m'   => 5,
                'sort_order'        => $i,
                'source_device_id'  => $srcDevice?->id,
                'source_port_id'    => null,  // Tier 1.5 stencils have no port rows seeded
                'dest_device_id'    => $dstDevice?->id,
                'dest_port_id'      => null,
            ]);
        }
    }

    /**
     * Boardroom equipment — 10 hardware lines across RACK / CEILING / WALL /
     * TABLE zones. Mix of Tier 2 (5 spike stencils) + Tier 1.5 (5 Crestron/
     * Netgear/Samsung stencils flagged needs_phase_24_curation).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function boardroomEquipment(): array
    {
        return [
            ['part_number' => 'neat-bar-pro',        'manufacturer' => 'Neat',       'model' => 'Bar Pro',                  'name' => 'Neat Bar Pro Videobar',       'quantity' => 1, 'area' => 'Boardroom',   'category' => 'hardware'],
            ['part_number' => 'samsung-qm65c-t',     'manufacturer' => 'Samsung',    'model' => 'QM65C-T',                  'name' => 'Samsung QM65 Display',        'quantity' => 2, 'area' => 'Boardroom',   'category' => 'hardware'],
            ['part_number' => 'bar-pro',             'manufacturer' => 'Barco',      'model' => 'ClickShare Bar Pro',       'name' => 'ClickShare Bar Pro',          'quantity' => 1, 'area' => 'Boardroom',   'category' => 'hardware'],
            ['part_number' => 'sennheiser-tcc2',     'manufacturer' => 'Sennheiser', 'model' => 'TeamConnect Ceiling 2',    'name' => 'Sennheiser TCC2 Ceiling Mic', 'quantity' => 1, 'area' => 'Boardroom',   'category' => 'hardware'],
            ['part_number' => 'gs312tp',             'manufacturer' => 'Netgear',    'model' => 'GS312TP',                  'name' => 'Netgear GS312TP Rack Switch', 'quantity' => 1, 'area' => 'Equipment Rack', 'category' => 'hardware'],
            ['part_number' => 'tss-1070-b-s-lb-kit', 'manufacturer' => 'Crestron',   'model' => 'TSS-1070-B-S-LB-KIT',      'name' => 'Crestron TSS-1070 Touch Panel', 'quantity' => 1, 'area' => 'Boardroom Table', 'category' => 'hardware'],
            ['part_number' => 'am3-111-ikit',        'manufacturer' => 'Crestron',   'model' => 'AM3-111-IKIT',             'name' => 'Crestron AM3 AirMedia Receiver', 'quantity' => 1, 'area' => 'Equipment Rack', 'category' => 'hardware'],
            ['part_number' => 'cen-odt-c-poe',       'manufacturer' => 'Crestron',   'model' => 'CEN-ODT-C-POE',            'name' => 'Crestron CEN-ODT Occupancy Sensor', 'quantity' => 1, 'area' => 'Boardroom Ceiling', 'category' => 'hardware'],
            ['part_number' => 'gsm4212p-100eus',     'manufacturer' => 'Netgear',    'model' => 'GSM4212P-100EUS',          'name' => 'Netgear GSM4212P PoE Switch', 'quantity' => 1, 'area' => 'Equipment Rack', 'category' => 'hardware'],
            ['part_number' => 'fw-98bz53l',          'manufacturer' => 'Samsung',    'model' => 'FW-98BZ53L',               'name' => 'Samsung 98" Display Screen',  'quantity' => 1, 'area' => 'Boardroom Wall', 'category' => 'hardware'],
        ];
    }

    /**
     * Paging system equipment — 9 hardware lines biased toward audio + network
     * signal types so the SheetPaginator exceeds D-06 thresholds and emits
     * AV-202 (audio) + AV-205 (network) sub-sheets.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pagingSystemEquipment(): array
    {
        return [
            ['part_number' => 'neat-bar-pro',        'manufacturer' => 'Neat',       'model' => 'Bar Pro',               'name' => 'Neat Bar Pro Videobar',     'quantity' => 1, 'area' => 'Reception',  'category' => 'hardware'],
            ['part_number' => 'samsung-qm65c-t',     'manufacturer' => 'Samsung',    'model' => 'QM65C-T',               'name' => 'Samsung QM65 Display',      'quantity' => 1, 'area' => 'Reception',  'category' => 'hardware'],
            ['part_number' => 'bar-pro',             'manufacturer' => 'Barco',      'model' => 'ClickShare Bar Pro',    'name' => 'ClickShare Bar Pro BYOD',   'quantity' => 1, 'area' => 'Reception',  'category' => 'hardware'],
            ['part_number' => 'sennheiser-tcc2',     'manufacturer' => 'Sennheiser', 'model' => 'TeamConnect Ceiling 2', 'name' => 'Sennheiser TCC2 Paging Mic','quantity' => 1, 'area' => 'Paging Station', 'category' => 'hardware'],
            ['part_number' => 'gs312tp',             'manufacturer' => 'Netgear',    'model' => 'GS312TP',               'name' => 'Netgear GS312TP Switch',    'quantity' => 1, 'area' => 'Equipment Rack', 'category' => 'hardware'],
            ['part_number' => 'tss-1070-b-s-lb-kit', 'manufacturer' => 'Crestron',   'model' => 'TSS-1070-B-S-LB-KIT',   'name' => 'Crestron TSS-1070 Call Station','quantity' => 1, 'area' => 'Reception Desk', 'category' => 'hardware'],
            ['part_number' => 'am3-111-ikit',        'manufacturer' => 'Crestron',   'model' => 'AM3-111-IKIT',          'name' => 'Crestron AM3 Receiver',     'quantity' => 1, 'area' => 'Equipment Rack', 'category' => 'hardware'],
            ['part_number' => 'cen-odt-c-poe',       'manufacturer' => 'Crestron',   'model' => 'CEN-ODT-C-POE',         'name' => 'Crestron CEN-ODT Ceiling Sensor','quantity' => 1, 'area' => 'Reception Ceiling', 'category' => 'hardware'],
            ['part_number' => 'fw-98bz53l',          'manufacturer' => 'Samsung',    'model' => 'FW-98BZ53L',            'name' => 'Samsung Signage Display',   'quantity' => 1, 'area' => 'Reception Wall', 'category' => 'hardware'],
        ];
    }

    /**
     * Legacy equipment — 8 lines, mix of seeded + uncatalogued part numbers.
     * Uncatalogued ones trigger Tier 1 auto-create via DeviceStencilCacheService
     * on first devicesWithStencils() call (intentional).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function legacyEquipment(): array
    {
        return [
            ['part_number' => 'neat-bar-pro',    'manufacturer' => 'Neat',       'model' => 'Bar Pro',               'name' => 'Legacy Neat Bar Pro',         'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
            ['part_number' => 'samsung-qm65c-t', 'manufacturer' => 'Samsung',    'model' => 'QM65C-T',               'name' => 'Legacy Samsung Display',      'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
            ['part_number' => 'bar-pro',         'manufacturer' => 'Barco',      'model' => 'ClickShare Bar Pro',    'name' => 'Legacy ClickShare',           'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
            ['part_number' => 'sennheiser-tcc2', 'manufacturer' => 'Sennheiser', 'model' => 'TeamConnect Ceiling 2', 'name' => 'Legacy Sennheiser Ceiling Mic', 'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
            ['part_number' => 'gs312tp',         'manufacturer' => 'Netgear',    'model' => 'GS312TP',               'name' => 'Legacy Netgear Switch',       'quantity' => 1, 'area' => 'Rack',   'category' => 'hardware'],
            ['part_number' => 'lgcy-uncatalog-1','manufacturer' => 'Unknown',    'model' => 'Mystery Box',           'name' => 'Legacy Uncatalogued Device',  'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
            ['part_number' => 'lgcy-uncatalog-2','manufacturer' => 'Unknown',    'model' => 'Old Rack Switch',       'name' => 'Legacy Rack Switch',          'quantity' => 1, 'area' => 'Rack',   'category' => 'hardware'],
            ['part_number' => 'lgcy-uncatalog-3','manufacturer' => 'Unknown',    'model' => 'Wall Plate',            'name' => 'Legacy Wall Plate',           'quantity' => 1, 'area' => 'Room 1', 'category' => 'hardware'],
        ];
    }
}

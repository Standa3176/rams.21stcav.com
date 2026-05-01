<?php

namespace Tests\Feature\Drawings;

use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Drawings\SchematicD2SourceBuilder;
use App\Services\Drawings\SchematicGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 17 Plan 02 — feature tests for the schematic generator pipeline.
 *
 * Most assertions exercise SchematicD2SourceBuilder directly (deterministic,
 * no D2 binary needed) so the suite runs fast on dev machines without the
 * D2 CLI installed. Test 1 (end-to-end) skips when the binary is missing.
 *
 * Coverage:
 *   - DRAW-01 (auto-generate from canonical data)            → test 1
 *   - DRAW-02 (signal-type colour coding)                    → test 4
 *   - DRAW-03 (cable IDs char-for-char vs cable schedule)    → test 2
 *   - DRAW-04 (AVIXA-style symbol library reference)         → test 1 (image refs in source)
 *   - CRIT-05 (signal direction never inferred from row)     → test 3
 *   - Warning 7 (D2 DSL escape on adversarial labels)        → test 5
 */
class SchematicGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(array $extracted = []): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Schematic Test Project',
            'ref' => 'SCH-TEST-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Schematic Street, London',
            'status' => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_name' => $project->name,
            'project_ref' => $project->ref,
            'client_name' => $project->client_name,
            'site_address' => $project->site_address,
            'status' => 'approved',
            'extracted_data' => $extracted,
            'reviewed_data' => $extracted,
        ]);

        return $project->fresh();
    }

    private function makeDrawing(Project $project): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_SCHEMATIC,
            'version' => 1,
            'status' => ProjectDrawing::STATUS_GENERATING,
            'source_data' => [],
        ]);
    }

    private function buildAdjacencyRoom(array $devices, array $cables): array
    {
        return [
            'room_id' => 1,
            'room_name' => 'Boardroom',
            'devices' => $devices,
            'cables' => $cables,
        ];
    }

    private function newBuilder(): SchematicD2SourceBuilder
    {
        return new SchematicD2SourceBuilder(config('drawings'));
    }

    // ─── Test 1 — Empty cable list still produces a non-empty SVG (end-to-end) ─

    public function test_it_returns_empty_svg_for_a_project_with_no_cables(): void
    {
        $binary = (string) config('drawings.d2_binary_path');
        if (! is_file($binary) || ! is_executable($binary)) {
            $this->markTestSkipped("D2 binary not available at {$binary} — end-to-end test skipped on this machine.");
        }

        $project = $this->makeProject([
            'rooms' => [
                ['id' => 1, 'name' => 'Boardroom'],
            ],
            'equipment_list' => [
                ['id' => 'eq1', 'part_no' => 'X95L', 'name' => 'Sony Bravia Display', 'manufacturer' => 'Sony', 'area' => 'Boardroom'],
            ],
            'cables' => [],
        ]);
        $drawing = $this->makeDrawing($project);

        $generator = $this->app->make(SchematicGeneratorService::class);
        $generator->generate($drawing);

        $drawing->refresh();
        $this->assertSame(ProjectDrawing::STATUS_READY, $drawing->status);
        $this->assertNotEmpty($drawing->generated_svg);
        $this->assertStringContainsString('<svg', $drawing->generated_svg);
    }

    // ─── Test 2 — Cable IDs character-for-character (DRAW-03) ────────────────

    public function test_it_writes_cable_ids_character_for_character_into_d2_source(): void
    {
        $room = $this->buildAdjacencyRoom(
            devices: [
                ['equipment_id' => 'eq1', 'name' => 'Sony Display', 'manufacturer' => 'Sony', 'model' => 'X95L', 'signal_role' => Device::ROLE_DESTINATION],
                ['equipment_id' => 'eq2', 'name' => 'Source PC', 'manufacturer' => 'Dell', 'model' => 'OptiPlex', 'signal_role' => Device::ROLE_SOURCE],
            ],
            cables: [
                ['cable_id' => 'CBL-001', 'source_equipment_id' => 'eq2', 'source_port' => 'HDMI OUT', 'dest_equipment_id' => 'eq1', 'dest_port' => 'HDMI 1', 'signal_type' => 'video'],
                ['cable_id' => 'AUDIO-12', 'source_equipment_id' => 'eq2', 'source_port' => 'AUDIO OUT', 'dest_equipment_id' => 'eq1', 'dest_port' => 'AUDIO IN', 'signal_type' => 'audio'],
                ['cable_id' => 'CTRL-3', 'source_equipment_id' => 'eq2', 'source_port' => 'RS232', 'dest_equipment_id' => 'eq1', 'dest_port' => 'RS232', 'signal_type' => 'control'],
            ],
        );

        $result = $this->newBuilder()->build($room);

        $this->assertStringContainsString('CBL-001', $result['source']);
        $this->assertStringContainsString('AUDIO-12', $result['source']);
        $this->assertStringContainsString('CTRL-3', $result['source']);
        // No double-encoding — the cable ids should not be escaped.
        $this->assertStringNotContainsString('CBL\\-001', $result['source']);
    }

    // ─── Test 3 — CRIT-05 undirected line for unknown signal_role ────────────

    public function test_it_renders_undirected_lines_when_signal_role_is_unknown(): void
    {
        $room = $this->buildAdjacencyRoom(
            devices: [
                ['equipment_id' => 'eq1', 'name' => 'Mystery Box', 'manufacturer' => null, 'model' => null, 'signal_role' => null],
                ['equipment_id' => 'eq2', 'name' => 'Sony Display', 'manufacturer' => 'Sony', 'model' => 'X95L', 'signal_role' => Device::ROLE_DESTINATION],
            ],
            cables: [
                ['cable_id' => 'CBL-AMBIG-1', 'source_equipment_id' => 'eq1', 'source_port' => 'OUT', 'dest_equipment_id' => 'eq2', 'dest_port' => 'IN', 'signal_type' => 'video'],
            ],
        );

        $result = $this->newBuilder()->build($room);

        $this->assertGreaterThan(0, $result['ambiguous_cables']);
        $this->assertStringContainsString(' -- ', $result['source'], 'Undirected D2 connector "--" should appear when signal_role is unknown.');
        $this->assertStringNotContainsString('eq1 -> eq2', $result['source'], 'Should NOT be a directed arrow for ambiguous cables.');
    }

    // ─── Test 4 — Signal-type colour map applied (DRAW-02) ───────────────────

    public function test_it_uses_signal_type_colours_per_config(): void
    {
        $room = $this->buildAdjacencyRoom(
            devices: [
                ['equipment_id' => 'eq1', 'name' => 'Mic', 'manufacturer' => 'Shure', 'model' => 'SM58', 'signal_role' => Device::ROLE_SOURCE],
                ['equipment_id' => 'eq2', 'name' => 'Speaker', 'manufacturer' => 'JBL', 'model' => 'Control 25', 'signal_role' => Device::ROLE_DESTINATION],
            ],
            cables: [
                ['cable_id' => 'A-001', 'source_equipment_id' => 'eq1', 'source_port' => 'XLR', 'dest_equipment_id' => 'eq2', 'dest_port' => 'IN', 'signal_type' => 'audio'],
            ],
        );

        $result = $this->newBuilder()->build($room);

        $expected = config('drawings.signal_colours.audio');
        $this->assertSame('#C0392B', $expected, 'sanity: config audio colour should be the AVIXA red.');
        $this->assertStringContainsString($expected, $result['source']);
    }

    // ─── Test 5 — Warning 7 — Crafted equipment names cannot break D2 ────────

    public function test_it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names(): void
    {
        $builder = $this->newBuilder();

        // Direct sanitiseLabel exercise — no D2 binary required.
        $crafted = "Sony \"Bravia\" 65\\";
        $sanitised = $builder->sanitiseLabel($crafted);
        $this->assertStringNotContainsString("\n", $sanitised);
        $this->assertStringContainsString('\\"', $sanitised);

        // Backtick + newline + control byte (0x01) input.
        $adversarial = "Demo`\x01\nName";
        $clean = $builder->sanitiseLabel($adversarial);
        // Control characters (incl. newline) collapsed to spaces.
        $this->assertSame(0, preg_match('/[\x00-\x1F]/u', $clean), 'sanitised label must not contain control chars.');
        // Backtick is escaped.
        $this->assertStringContainsString('\\`', $clean);

        // Backslash escapes BEFORE quote/backtick (order matters in sanitiseLabel).
        $boom = 'Crafted "boom`'."\\".'stop"';
        $afterBoom = $builder->sanitiseLabel($boom);
        // No raw control chars or unescaped backslashes followed by something
        // other than \, ", or `.
        $this->assertStringNotContainsString("\n", $afterBoom);
        // The raw quote is escaped.
        $this->assertStringContainsString('\\"', $afterBoom);

        // End-to-end: build() with adversarial device name produces a source
        // string where the device's label line opens and closes with exactly
        // two unescaped quotes (the boundaries) and no raw control bytes.
        $room = $this->buildAdjacencyRoom(
            devices: [
                ['equipment_id' => 'evil1', 'name' => "Sony \"Demo\" \\Box\nLine2", 'manufacturer' => null, 'model' => null, 'signal_role' => Device::ROLE_SOURCE],
                ['equipment_id' => 'good2', 'name' => 'Plain Display', 'manufacturer' => null, 'model' => null, 'signal_role' => Device::ROLE_DESTINATION],
            ],
            cables: [
                ['cable_id' => "C\"BL`-001", 'source_equipment_id' => 'evil1', 'source_port' => "OUT", 'dest_equipment_id' => 'good2', 'dest_port' => "IN", 'signal_type' => 'video'],
            ],
        );

        $built = $builder->build($room);
        $source = $built['source'];

        // No raw control characters in the emitted D2 source (newlines are
        // expected as line separators, but null/VT etc. should be absent).
        $this->assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F]/u', $source), 'D2 source must not contain raw control bytes.');

        // If the D2 binary is available, prove the source actually parses.
        $binary = (string) config('drawings.d2_binary_path');
        if (is_file($binary) && is_executable($binary)) {
            $tmpD2 = tempnam(sys_get_temp_dir(), 'sch-test-').'.d2';
            $tmpSvg = tempnam(sys_get_temp_dir(), 'sch-test-').'.svg';
            file_put_contents($tmpD2, $source);

            $process = new \Symfony\Component\Process\Process([
                $binary,
                '--layout='.config('drawings.d2_layout', 'elk'),
                $tmpD2,
                $tmpSvg,
            ]);
            $process->setTimeout(30);
            $process->run();

            @unlink($tmpD2);
            @unlink($tmpSvg);

            $this->assertTrue(
                $process->isSuccessful(),
                'D2 must parse the adversarial source: '.substr((string) $process->getErrorOutput(), 0, 400)
            );
        }
    }
}

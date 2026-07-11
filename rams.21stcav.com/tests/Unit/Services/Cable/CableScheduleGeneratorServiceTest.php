<?php

namespace Tests\Unit\Services\Cable;

use App\Core\Modules\Projects\ProjectDataService;
use App\Services\CableScheduleGeneratorService;
use Mockery;
use Tests\TestCase;

/**
 * T1-C: word-boundary matchesAny() kills known false positives.
 *
 * Tests go through the public buildRowsFromEquipmentLines API so we
 * exercise both the classification path AND the T1-A signal_type
 * extension in one hit. No RefreshDatabase — buildRowsFromEquipmentLines
 * is pure in-memory and doesn't touch the DB.
 *
 * Under the old str_contains implementation:
 *   - 'Microsoft Teams License' would match microphone branch ('mic' in 'Microsoft')
 *   - 'Ceiling Lamp Fixture' would match amplifier branch ('amp' in 'Lamp')
 *   - 'Cisco Room Kit' would bleed into the HDBaseT branch ('csc' in 'Cisco')
 *
 * After the word-boundary fix, all three false positives disappear while
 * genuine 'Shure MXW Microphone' + 'LEA Audio Amplifier' still classify
 * correctly.
 */
class CableScheduleGeneratorServiceTest extends TestCase
{
    private function make(): CableScheduleGeneratorService
    {
        // buildRowsFromEquipmentLines does not touch ProjectDataService — the
        // mock is only required to satisfy the constructor's readonly typed
        // dependency. Zero interactions expected.
        $projectData = Mockery::mock(ProjectDataService::class);

        return new CableScheduleGeneratorService($projectData);
    }

    public function test_microsoft_teams_license_does_not_classify_as_microphone(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Microsoft Teams License'],
        ]);

        // Regression note: under the old str_contains impl, 'mic' inside
        // 'Microsoft' would route this to the microphone branch and produce
        // an XLR / Shure cable row. After T1-C: falls through to TBC.
        //
        // NB — Microsoft Teams License routes through the classifier's
        // 'services'/'option' bucket in real quotes (category='services');
        // this test intentionally passes NO category so it hits the pure
        // name-based path where the false positive lived.
        $this->assertCount(1, $rows, 'Expected one row from the fixture line.');
        $row = $rows[0];

        $this->assertNotSame('XLR', $row['cable_type']);
        $this->assertNotSame('Cat6 (Shure network)', $row['cable_type']);
        $this->assertSame('TBC', $row['cable_type']);
        $this->assertSame('unknown', $row['signal_type']);
    }

    public function test_shure_mxw_microphone_still_classifies_as_microphone(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Shure/MXW branch inside the microphone block routes to the
        // Shure network Cat6.
        $this->assertSame('Cat6 (Shure network)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        // T1-A shape assertion — proves signal_type flowed through
        // buildRowsFromEquipmentLines end-to-end.
        $this->assertArrayHasKey('signal_type', $row);
    }

    public function test_ceiling_lamp_fixture_does_not_classify_as_amplifier(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Ceiling Lamp Fixture'],
        ]);

        // Under old code: 'amp' matches inside 'Lamp' → routes to amplifier.
        // After T1-C: falls through to TBC.
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertNotSame('Audio Multicore', $row['cable_type']);
        $this->assertNotSame('Cat6 (Dante)', $row['cable_type']);
        $this->assertSame('TBC', $row['cable_type']);
        $this->assertSame('unknown', $row['signal_type']);
    }

    public function test_lea_audio_amplifier_still_classifies_as_amplifier(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'LEA Audio Amplifier'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // LEA is Dante-native per the isDante check on 'lea', so the
        // amplifier branch routes to Cat6 (Dante).
        $this->assertSame('Cat6 (Dante)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        $this->assertArrayHasKey('signal_type', $row);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

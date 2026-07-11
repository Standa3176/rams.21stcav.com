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

    // ─────────────────────────────────────────────────────────────────────────
    // T1-D — quoted cable products override cable_type by signal_type
    // ─────────────────────────────────────────────────────────────────────────

    public function test_quoted_hdmi_cable_overrides_video_rows(): void
    {
        // Kramer HDMI product classifies as cable_consumable (isCableProduct:
        // 'hdmi' + 'cable') and pins video-signal rows to the quoted product.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer C-HM/HM-6 HDMI Cable', 'category' => 'cables'],
        ]);

        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow, 'Expected a video row for the Samsung display.');
        $this->assertSame('Kramer C-HM/HM-6 HDMI Cable', $displayRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Kramer C-HM/HM-6 HDMI Cable | ', $displayRow['notes']);
    }

    public function test_quoted_belden_cable_overrides_audio_rows(): void
    {
        // Belden XLR classifies to audio; Kramer HDMI to video. Each
        // consumable pins its own signal_type only.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Belden 8451 XLR Audio Cable', 'category' => 'cables'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
        ]);

        $micRow = collect($rows)->firstWhere('signal_type', 'audio');
        $this->assertNotNull($micRow, 'Expected an audio row for the mic.');
        $this->assertSame('Belden 8451 XLR Audio Cable', $micRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Belden 8451 XLR Audio Cable | ', $micRow['notes']);

        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow);
        $this->assertSame('Kramer HDMI Cable', $displayRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Kramer HDMI Cable | ', $displayRow['notes']);
    }

    public function test_shure_cat6_reclassifies_from_network_to_audio(): void
    {
        // 'Shure Cat6 Patch Cable' would classify as 'network' via cat6, but
        // the shure+network special case pins it to 'audio'. The Shure mic
        // audio row should then adopt this consumable.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
            ['name' => 'Shure Cat6 Patch Cable', 'category' => 'cables'],
        ]);

        $micRow = collect($rows)->firstWhere('signal_type', 'audio');
        $this->assertNotNull($micRow, 'Expected an audio row for the Shure microphone.');
        $this->assertSame('Shure Cat6 Patch Cable', $micRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Shure Cat6 Patch Cable | ', $micRow['notes']);
    }

    public function test_no_consumables_leaves_rows_unchanged(): void
    {
        // Regression guard: rows without any cable_consumable input retain
        // the pre-T1-D inferred cable_type + notes exactly.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Pre-T1-D shape — HDMI 2.0 + video, no "Quoted:" prefix.
        $this->assertSame('HDMI 2.0', $row['cable_type']);
        $this->assertSame('video', $row['signal_type']);
        $this->assertStringNotContainsString('Quoted:', $row['notes']);
    }

    public function test_multiple_consumables_of_same_signal_type_join_with_slash(): void
    {
        // Two HDMI cable products both classify to 'video' — override display
        // joins them in array order with ' / '.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
            ['name' => 'Cat6 Patch Lead', 'category' => 'cables'],
        ]);

        // The 'Kramer HDMI Cable' pins video; the 'Cat6 Patch Lead' pins
        // network. So video row gets Kramer HDMI.
        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow);
        $this->assertSame('Kramer HDMI Cable', $displayRow['cable_type']);

        // Two hdmi cables at once → joined with ' / '.
        $rows2 = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
            ['name' => 'Premium Displayport Cable', 'category' => 'cables'],
        ]);
        $displayRow2 = collect($rows2)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow2);
        $this->assertSame(
            'Kramer HDMI Cable / Premium Displayport Cable',
            $displayRow2['cable_type'],
            'Same-signal_type consumables must join with " / " in array order.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

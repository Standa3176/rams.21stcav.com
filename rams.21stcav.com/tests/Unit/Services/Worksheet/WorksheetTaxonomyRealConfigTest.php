<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\WorksheetClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the LIVE config/worksheet_taxonomy.php file.
 *
 * Unlike WorksheetClassifierTest (which uses a minimal fixture taxonomy to
 * test tier mechanics), this test loads the REAL config and asserts that
 * items previously seen in production quotes still classify to sensible
 * canonical categories. Prevents accidental deletion / regression of live
 * classification rules.
 *
 * Locked cases stem from the 19-item unclassified warning on Tilda
 * worksheet 14 (project 88, 2026-07-27) plus the 8 tier-3 drift items
 * flagged in the same document. Fixed in 260727-fx6.
 */
class WorksheetTaxonomyRealConfigTest extends TestCase
{
    private WorksheetClassifier $svc;

    protected function setUp(): void
    {
        parent::setUp();
        // Load the real config file directly so this test detects drift when
        // the file is edited. Bypasses Laravel's config cache.
        $taxonomy   = require dirname(__DIR__, 4).'/config/worksheet_taxonomy.php';
        $this->svc  = new WorksheetClassifier($taxonomy);
    }

    /**
     * Every Tilda 21CQ29531-05-OPS worksheet-14 kit line that landed in the
     * "REVIEW — could not be classified" warning must now resolve to a real
     * category. Failing a case here means either the taxonomy was reverted or
     * a description shape drifted.
     */
    #[DataProvider('tildaUnclassifiedItemsProvider')]
    public function test_tilda_previously_unclassified_items_now_classify(
        string $name,
        string $expectedCategory,
    ): void {
        $verdict = $this->svc->classify(['name' => $name], []);

        $this->assertSame(
            $expectedCategory,
            $verdict['category'],
            "Expected '{$name}' to classify as '{$expectedCategory}', got '{$verdict['category']}' (tier {$verdict['tier']}, reason: {$verdict['reason']})",
        );
        $this->assertNotSame(
            'unclassified',
            $verdict['category'],
            "'{$name}' fell to 'unclassified' — taxonomy regression.",
        );
    }

    /**
     * Bank of the exact strings that appeared in Tilda worksheet 14's QA
     * warnings section. Pulled verbatim from
     * `worksheet_14_20260727_094120_251765 (1).docx` on 2026-07-27.
     *
     * The category assignments reflect a product-classification decision made
     * in 260727-fx6. If a future product owner wants (say) AirMedia to sit
     * under a new 'wireless_presentation' category rather than 'control',
     * update BOTH this fixture and the taxonomy in one commit.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tildaUnclassifiedItemsProvider(): array
    {
        return [
            // Scheduling touch screens — all TSW-1070 / TSS-1070 series.
            'crestron_scheduling_touch_oregano' => [
                'Crestron 10.1 in. Room Scheduling Touch Screen Black Smooth, includes one TSW-1070-LB-B-S light bar',
                'control',
            ],
            'crestron_scheduling_touch_general_vanilla' => [
                'Crestron 10.1 in. Room Scheduling Touch Screen (Vanilla) Black Smooth, includes one TSW-1070-LB-B-S light bar',
                'control',
            ],
            'crestron_scheduling_touch_general_poppy' => [
                'Crestron 10.1 in. Room Scheduling Touch Screen (Poppy) Black Smooth, includes one TSW-1070-LB-B-S light bar',
                'control',
            ],
            'crestron_scheduling_touch_general_nutmeg' => [
                'Crestron 10.1 in. Room Scheduling Touch Screen (Nutmeg) Black Smooth, includes one TSW-1070-LB-B-S light bar',
                'control',
            ],

            // Automate VX camera-switching processor + calibration accessory.
            'crestron_automate_vx_switcher' => [
                'Crestron Automate VX voice-activated camera switching solution - Crestron 1Beyond Cameras automatically switch based on the location of the active speaking participant.',
                'video_conferencing',
            ],
            'crestron_automate_vx_automeasure' => [
                'Crestron Automate VX AutoMeasure Cubes',
                'video_conferencing',
            ],

            // AirMedia wireless BYOD receivers + endpoints.
            'crestron_airmedia_endpoint' => [
                'AirMedia Series 3 Connect Endpoint',
                'control',
            ],
            'crestron_airmedia_kit' => [
                'Crestron AirMedia Series 3 kit - AM-3100-WF receiver and AM-TX3-100 adaptor',
                'control',
            ],

            // Multisurface mount kit — glass-wall flush mount for scheduling
            // panels. Classifies as mount_inherit at tier 2; with an empty
            // roomContext (this test) the sentinel `mount_accessory` is
            // emitted — a rendered category on the worksheet (NOT the
            // `unclassified` warning bucket). In real quotes with a nearby
            // scheduling panel, context resolves it to `control`.
            'crestron_multisurface_mount_kit' => [
                'Crestron Multisurface Mount Kit for 10.1 in. Room Scheduling Panel',
                'mount_accessory',
            ],

            // SR camera with SKU-style comma-separated description.
            'crestron_sr_ptz_camera' => [
                'IV,CAMERA,4K,PTZ,TRACK,SPEAKER, DUAL,12XZOOM,BLK',
                'video_conferencing',
            ],

            // SurgeX / power conditioning — pre-fix had no manufacturer rule.
            'surgex_sequencing_surge' => [
                'SurgeX Sequencing Surge Protector And Power Conditioner',
                'rack',
            ],

            // Tier-3-drift cases — pre-fix classified but with fallback flag.
            // Should now hit tier 2 cleanly under the new Crestron audio rule.
            'crestron_saros_speaker' => [
                'Crestron Saros 6.5 in. 2-Way In-Ceiling Speaker White Textured, Single',
                'audio',
            ],
            'crestron_xseries_amp' => [
                'Crestron X-Series Amplifier, 300 W',
                'audio',
            ],
            'crestron_1beyond_p20_camera' => [
                'Crestron 1 Beyond p20 PTZ Camera - 20x Optical Zoom, Moon Gray',
                'video_conferencing',
            ],
            'crestron_1beyond_p12_camera' => [
                'Crestron 1 Beyond p12 PTZ Camera, 12x Optical Zoom, Moon Gray',
                'video_conferencing',
            ],
        ];
    }

    /**
     * Post-fix tier upgrade — the four Crestron families that pre-fix only
     * survived via tier-3 keyword fallback (drift warning) must now hit
     * tier 2. Tier value is not asserted at 2 exactly because 'mount_inherit'
     * still resolves via context in some renders — but no case here should
     * legitimately need tier 3 anymore.
     */
    public function test_tier3_drift_cases_now_hit_tier2(): void
    {
        $cases = [
            ['manufacturer' => 'crestron', 'name' => 'Crestron 1 Beyond p20 PTZ Camera - 20x Optical Zoom, Moon Gray'],
            ['manufacturer' => 'crestron', 'name' => 'Crestron 1 Beyond p12 PTZ Camera, 12x Optical Zoom, Moon Gray'],
            ['manufacturer' => 'crestron', 'name' => 'Crestron Saros 6.5 in. 2-Way In-Ceiling Speaker White Textured, Single'],
            ['manufacturer' => 'crestron', 'name' => 'Crestron X-Series Amplifier, 300 W'],
        ];

        foreach ($cases as $c) {
            $v = $this->svc->classify($c, []);
            $this->assertLessThanOrEqual(
                2,
                $v['tier'],
                "Expected tier ≤2 for '{$c['name']}' post-260727-fx6, got tier {$v['tier']} (fallback_used={$v['fallback_used']}).",
            );
        }
    }
}

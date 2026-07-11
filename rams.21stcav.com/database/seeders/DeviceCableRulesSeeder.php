<?php

namespace Database\Seeders;

use App\Models\DeviceCableRule;
use Illuminate\Database\Seeder;

/**
 * Quick task 260711-q7q — 13 canonical cable inference rules.
 *
 * Idempotent (updateOrCreate keyed on priority). Every rule mirrors a
 * branch from the pre-refactor
 * CableScheduleGeneratorService::inferCableRun() 13-branch cascade —
 * byte-for-byte identical output is enforced by
 * DeviceCableRuleInferenceTest.
 *
 * IMPORTANT: The mic + amp branches split into TWO rows each because
 * their original branches carried an inner isShure / isDante toggle.
 * Priority ordering (40 mic_shure < 41 mic_generic, 60 amp_dante <
 * 61 amp_analog) guarantees the Shure and Dante variants match first,
 * preserving byte-for-byte parity.
 *
 * DO NOT PARAPHRASE keyword arrays — the regression test compares
 * output against known-good pre-refactor strings.
 */
class DeviceCableRulesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rules() as $rule) {
            DeviceCableRule::updateOrCreate(
                ['priority' => $rule['priority']],
                $rule + ['is_active' => true],
            );
        }

        DeviceCableRule::flushCache();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(): array
    {
        return [
            [
                'priority'    => 10,
                'keywords'    => ['display', 'screen', 'monitor', 'tv', 'samsung', 'lg', 'sony', 'uhd', '4k', 'oled', 'qled', 'qm85', 'qe65', 'qe75', 'projector'],
                'cable_type'  => 'HDMI 2.0',
                'signal_type' => 'video',
                'cores'       => null,
                'to_endpoint' => 'AV Rack / Matrix Switcher',
                'notes'       => 'Signal: HDMI from source/matrix',
            ],
            [
                'priority'    => 20,
                'keywords'    => ['hdbaset', 'extender', 'csc'],
                'cable_type'  => 'Cat6a (shielded)',
                'signal_type' => 'video',
                'cores'       => null,
                'to_endpoint' => 'Display / Receiver',
                'notes'       => 'HDBaseT link — max 70m Cat6a',
            ],
            [
                'priority'    => 30,
                'keywords'    => ['speaker', 'pendant', 'loudspeaker'],
                'cable_type'  => '2-core speaker cable (1.5mm LSZH)',
                'signal_type' => 'speaker',
                'cores'       => '2',
                'to_endpoint' => 'Amplifier output',
                'notes'       => 'Speaker level from amplifier',
            ],
            // Shure microphone variant — must match before the generic mic
            // rule (priority 41) so 'Shure MXW Microphone' picks Cat6.
            [
                'priority'    => 40,
                'keywords'    => ['shure', 'mxw', 'mx'],
                'cable_type'  => 'Cat6 (Shure network)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to_endpoint' => 'Shure access point / DSP',
                'notes'       => 'Shure Microflex Wireless',
            ],
            [
                'priority'    => 41,
                'keywords'    => ['microphone', 'mic', 'mxw', 'lavalier'],
                'cable_type'  => 'XLR',
                'signal_type' => 'audio',
                'cores'       => '3',
                'to_endpoint' => 'DSP / Mixer input',
                'notes'       => 'Analogue mic input',
            ],
            [
                'priority'    => 50,
                'keywords'    => ['dsp', 'q-sys', 'qsys', 'biamp', 'audio processor'],
                'cable_type'  => 'Cat6 (Dante/AES67)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to_endpoint' => 'Network switch (AV VLAN)',
                'notes'       => 'Dante audio network',
            ],
            // Dante amplifier variant — must match before the generic
            // amp rule (priority 61) so LEA / Dante amps pick Cat6.
            [
                'priority'    => 60,
                'keywords'    => ['dante', 'lea'],
                'cable_type'  => 'Cat6 (Dante)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to_endpoint' => 'Network switch (AV VLAN)',
                'notes'       => 'Dante amplifier — network audio',
            ],
            [
                'priority'    => 61,
                'keywords'    => ['amplifier', 'amp', 'lea audio', 'lea '],
                'cable_type'  => 'Audio Multicore',
                'signal_type' => 'audio',
                'cores'       => null,
                'to_endpoint' => 'DSP output',
                'notes'       => 'Analogue from DSP',
            ],
            [
                'priority'    => 70,
                'keywords'    => ['cisco', 'room kit', 'codec', 'poly', 'logitech'],
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'video',
                'cores'       => null,
                'to_endpoint' => 'Network switch (PoE)',
                'notes'       => 'VC codec — requires PoE+ or local PSU',
            ],
            [
                'priority'    => 80,
                'keywords'    => ['camera', 'ptz', 'quad cam', 'webcam'],
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'video',
                'cores'       => null,
                'to_endpoint' => 'Codec / Network switch (PoE)',
                'notes'       => 'Camera — PoE powered',
            ],
            [
                'priority'    => 90,
                'keywords'    => ['navigator', 'touch panel', 'keypad', 'button panel'],
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'control',
                'cores'       => null,
                'to_endpoint' => 'Network switch (PoE)',
                'notes'       => 'Control interface — PoE powered',
            ],
            [
                'priority'    => 100,
                'keywords'    => ['control', 'crestron', 'extron', 'amx', 'sensor', 'partition'],
                'cable_type'  => 'Cat6',
                'signal_type' => 'control',
                'cores'       => null,
                'to_endpoint' => 'Control processor',
                'notes'       => 'Control signal',
            ],
            [
                'priority'    => 110,
                'keywords'    => ['switch', 'netgear', 'cisco switch'],
                'cable_type'  => 'Cat6',
                'signal_type' => 'network',
                'cores'       => null,
                'to_endpoint' => 'Building network / patch panel',
                'notes'       => 'Uplink to client network',
            ],
            [
                'priority'    => 120,
                'keywords'    => ['patch panel', 'keystone'],
                'cable_type'  => 'Cat6',
                'signal_type' => 'network',
                'cores'       => null,
                'to_endpoint' => 'Network switch',
                'notes'       => 'Patch panel termination',
            ],
            [
                'priority'    => 130,
                'keywords'    => ['mxwapx', 'access point', 'wap'],
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to_endpoint' => 'Network switch (PoE)',
                'notes'       => 'Wireless mic access point',
            ],
        ];
    }
}

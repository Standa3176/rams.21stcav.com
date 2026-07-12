<?php

namespace Database\Seeders;

use App\Models\DeviceCableRule;
use Illuminate\Database\Seeder;

/**
 * Quick task 260711-q7q — 13 canonical cable inference rules.
 * Quick task 260712-euh — length_tiers added to 12 rules + 5 new rules
 * (USB2, USB3, DisplayPort, SDI, Optical fibre) at priorities 140-144.
 * Total row count after seeding = 20.
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
 * DO NOT PARAPHRASE keyword arrays OR the flat cable_type strings —
 * the regression test compares output against known-good pre-refactor
 * strings. When length_tiers is set, the tier picker in inferCableRun()
 * walks the tier list ascending on max_m and returns the first tier
 * whose max_m ≥ the row's approx_length_m; that tier's cable_type
 * OVERRIDES the flat cable_type. At null length the picker returns
 * tier 1 whose cable_type matches the pre-260712-euh flat string, so
 * every existing regression test remains byte-for-byte identical.
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
                'priority'     => 10,
                'keywords'     => ['display', 'screen', 'monitor', 'tv', 'samsung', 'lg', 'sony', 'uhd', '4k', 'oled', 'qled', 'qm85', 'qe65', 'qe75', 'projector'],
                'cable_type'   => 'HDMI 2.0',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'AV Rack / Matrix Switcher',
                'notes'        => 'Signal: HDMI from source/matrix',
                'length_tiers' => [
                    ['max_m' => 15,  'cable_type' => 'HDMI 2.0',                 'cores' => null, 'to_endpoint' => 'AV Rack / Matrix Switcher', 'notes' => 'Passive HDMI — under 15m'],
                    ['max_m' => 70,  'cable_type' => 'Cat6a (shielded) HDBaseT', 'cores' => null, 'to_endpoint' => 'HDBaseT receiver at display', 'notes' => 'HDBaseT link — 15–70m Cat6a shielded'],
                    ['max_m' => 300, 'cable_type' => 'HDMI over fibre extender', 'cores' => null, 'to_endpoint' => 'Fibre receiver at display',   'notes' => 'Fibre HDMI extender — long run'],
                ],
            ],
            [
                'priority'     => 20,
                'keywords'     => ['hdbaset', 'extender', 'csc'],
                'cable_type'   => 'Cat6a (shielded)',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'Display / Receiver',
                'notes'        => 'HDBaseT link — max 70m Cat6a',
                'length_tiers' => [
                    ['max_m' => 100, 'cable_type' => 'Cat6a (shielded)',       'cores' => null, 'to_endpoint' => 'Display / Receiver',      'notes' => 'HDBaseT max 100m Cat6a'],
                    ['max_m' => 300, 'cable_type' => 'HDBaseT over fibre',     'cores' => null, 'to_endpoint' => 'Fibre HDBaseT extender', 'notes' => 'HDBaseT-over-fibre extender'],
                ],
            ],
            [
                'priority'     => 30,
                'keywords'     => ['speaker', 'pendant', 'loudspeaker'],
                'cable_type'   => '2-core speaker cable (1.5mm LSZH)',
                'signal_type'  => 'speaker',
                'cores'        => '2',
                'to_endpoint'  => 'Amplifier output',
                'notes'        => 'Speaker level from amplifier',
                'length_tiers' => [
                    ['max_m' => 30,  'cable_type' => '2-core speaker cable (1.5mm LSZH)',         'cores' => '2', 'to_endpoint' => 'Amplifier output', 'notes' => 'Speaker level — under 30m'],
                    ['max_m' => 100, 'cable_type' => '4-core star quad speaker cable (2.5mm LSZH)', 'cores' => '4', 'to_endpoint' => 'Amplifier output', 'notes' => 'Long speaker run — star quad, thicker gauge'],
                ],
            ],
            // Shure microphone variant — must match before the generic mic
            // rule (priority 41) so 'Shure MXW Microphone' picks Cat6.
            [
                'priority'     => 40,
                'keywords'     => ['shure', 'mxw', 'mx'],
                'cable_type'   => 'Cat6 (Shure network)',
                'signal_type'  => 'audio',
                'cores'        => null,
                'to_endpoint'  => 'Shure access point / DSP',
                'notes'        => 'Shure Microflex Wireless',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (Shure network)',            'cores' => null, 'to_endpoint' => 'Shure access point / DSP', 'notes' => 'Shure MXW Cat6 up to 90m'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + Shure media converter',   'cores' => null, 'to_endpoint' => 'Fibre + Shure converter', 'notes' => 'Long Shure MXW run — fibre + media converter'],
                ],
            ],
            // Priority 41 generic microphone — analogue XLR is fine to 100m+,
            // no meaningful tier swap. length_tiers stays null.
            [
                'priority'     => 41,
                'keywords'     => ['microphone', 'mic', 'mxw', 'lavalier'],
                'cable_type'   => 'XLR',
                'signal_type'  => 'audio',
                'cores'        => '3',
                'to_endpoint'  => 'DSP / Mixer input',
                'notes'        => 'Analogue mic input',
                'length_tiers' => null,
            ],
            [
                'priority'     => 50,
                'keywords'     => ['dsp', 'q-sys', 'qsys', 'biamp', 'audio processor'],
                'cable_type'   => 'Cat6 (Dante/AES67)',
                'signal_type'  => 'audio',
                'cores'        => null,
                'to_endpoint'  => 'Network switch (AV VLAN)',
                'notes'        => 'Dante audio network',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (Dante/AES67)',             'cores' => null, 'to_endpoint' => 'Network switch (AV VLAN)', 'notes' => 'Dante audio over Cat6'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + Dante media converter',  'cores' => null, 'to_endpoint' => 'Fibre + Dante converter',  'notes' => 'Long Dante run — fibre + Dante media converter'],
                ],
            ],
            // Priority 60 Dante amplifier — same tier logic as priority 50,
            // but kept flat here to prove null-tier fallthrough still works.
            [
                'priority'     => 60,
                'keywords'     => ['dante', 'lea'],
                'cable_type'   => 'Cat6 (Dante)',
                'signal_type'  => 'audio',
                'cores'        => null,
                'to_endpoint'  => 'Network switch (AV VLAN)',
                'notes'        => 'Dante amplifier — network audio',
                'length_tiers' => null,
            ],
            // Priority 61 analogue amplifier — analogue speaker-level runs
            // don't tier-swap. length_tiers stays null.
            [
                'priority'     => 61,
                'keywords'     => ['amplifier', 'amp', 'lea audio', 'lea '],
                'cable_type'   => 'Audio Multicore',
                'signal_type'  => 'audio',
                'cores'        => null,
                'to_endpoint'  => 'DSP output',
                'notes'        => 'Analogue from DSP',
                'length_tiers' => null,
            ],
            [
                'priority'     => 70,
                'keywords'     => ['cisco', 'room kit', 'codec', 'poly', 'logitech'],
                'cable_type'   => 'Cat6 (PoE)',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'Network switch (PoE)',
                'notes'        => 'VC codec — requires PoE+ or local PSU',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (PoE)',                    'cores' => null, 'to_endpoint' => 'Network switch (PoE)',   'notes' => 'VC codec Cat6 PoE'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + PoE media converter',   'cores' => null, 'to_endpoint' => 'Fibre + PoE converter',  'notes' => 'Long codec run — fibre + PoE media converter'],
                ],
            ],
            [
                'priority'     => 80,
                'keywords'     => ['camera', 'ptz', 'quad cam', 'webcam'],
                'cable_type'   => 'Cat6 (PoE)',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'Codec / Network switch (PoE)',
                'notes'        => 'Camera — PoE powered',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (PoE)',                    'cores' => null, 'to_endpoint' => 'Codec / Network switch (PoE)', 'notes' => 'Camera Cat6 PoE'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + PoE media converter',   'cores' => null, 'to_endpoint' => 'Fibre + PoE converter',        'notes' => 'Long camera run — fibre + PoE media converter'],
                ],
            ],
            [
                'priority'     => 90,
                'keywords'     => ['navigator', 'touch panel', 'keypad', 'button panel'],
                'cable_type'   => 'Cat6 (PoE)',
                'signal_type'  => 'control',
                'cores'        => null,
                'to_endpoint'  => 'Network switch (PoE)',
                'notes'        => 'Control interface — PoE powered',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (PoE)',                    'cores' => null, 'to_endpoint' => 'Network switch (PoE)',  'notes' => 'Control PoE Cat6'],
                    ['max_m' => 200, 'cable_type' => 'Fibre + PoE media converter',   'cores' => null, 'to_endpoint' => 'Fibre + PoE converter', 'notes' => 'Long control run — fibre + PoE media converter'],
                ],
            ],
            [
                'priority'     => 100,
                'keywords'     => ['control', 'crestron', 'extron', 'amx', 'sensor', 'partition'],
                'cable_type'   => 'Cat6',
                'signal_type'  => 'control',
                'cores'        => null,
                'to_endpoint'  => 'Control processor',
                'notes'        => 'Control signal',
                'length_tiers' => [
                    ['max_m' => 100, 'cable_type' => 'Cat6',                       'cores' => null, 'to_endpoint' => 'Control processor',  'notes' => 'Control Cat6'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + media converter',    'cores' => null, 'to_endpoint' => 'Fibre + converter',  'notes' => 'Long control run — fibre + media converter'],
                ],
            ],
            [
                'priority'     => 110,
                'keywords'     => ['switch', 'netgear', 'cisco switch'],
                'cable_type'   => 'Cat6',
                'signal_type'  => 'network',
                'cores'        => null,
                'to_endpoint'  => 'Building network / patch panel',
                'notes'        => 'Uplink to client network',
                'length_tiers' => [
                    ['max_m' => 100, 'cable_type' => 'Cat6',                       'cores' => null, 'to_endpoint' => 'Building network / patch panel', 'notes' => 'Uplink Cat6'],
                    ['max_m' => 500, 'cable_type' => 'Single-mode fibre uplink',   'cores' => null, 'to_endpoint' => 'Fibre patch panel',              'notes' => 'Long uplink — SMF fibre'],
                ],
            ],
            [
                'priority'     => 120,
                'keywords'     => ['patch panel', 'keystone'],
                'cable_type'   => 'Cat6',
                'signal_type'  => 'network',
                'cores'        => null,
                'to_endpoint'  => 'Network switch',
                'notes'        => 'Patch panel termination',
                'length_tiers' => [
                    ['max_m' => 100, 'cable_type' => 'Cat6',                       'cores' => null, 'to_endpoint' => 'Network switch',   'notes' => 'Cat6 patch'],
                    ['max_m' => 500, 'cable_type' => 'Single-mode fibre patch',    'cores' => null, 'to_endpoint' => 'Fibre patch panel', 'notes' => 'Long patch — SMF fibre'],
                ],
            ],
            [
                'priority'     => 130,
                'keywords'     => ['mxwapx', 'access point', 'wap'],
                'cable_type'   => 'Cat6 (PoE)',
                'signal_type'  => 'audio',
                'cores'        => null,
                'to_endpoint'  => 'Network switch (PoE)',
                'notes'        => 'Wireless mic access point',
                'length_tiers' => [
                    ['max_m' => 90,  'cable_type' => 'Cat6 (PoE)',                  'cores' => null, 'to_endpoint' => 'Network switch (PoE)',  'notes' => 'WAP Cat6 PoE'],
                    ['max_m' => 300, 'cable_type' => 'Fibre + PoE media converter', 'cores' => null, 'to_endpoint' => 'Fibre + PoE converter', 'notes' => 'Long WAP run — fibre + PoE media converter'],
                ],
            ],
            // ── 260712-euh: 5 NEW rules at priorities 140-144 ─────────────
            [
                'priority'     => 140,
                'keywords'     => ['usb 2.0', 'usb2', 'usb 2'],
                'cable_type'   => 'USB 2.0',
                'signal_type'  => 'usb',
                'cores'        => null,
                'to_endpoint'  => 'USB host',
                'notes'        => 'USB 2.0 — 5m passive max',
                'length_tiers' => [
                    ['max_m' => 5,  'cable_type' => 'USB 2.0',                          'cores' => null, 'to_endpoint' => 'USB host',              'notes' => 'USB 2.0 — 5m passive max'],
                    ['max_m' => 20, 'cable_type' => 'USB 2.0 with active repeater',     'cores' => null, 'to_endpoint' => 'USB host',              'notes' => 'USB 2.0 — active repeater'],
                    ['max_m' => 50, 'cable_type' => 'USB over fibre extender',          'cores' => null, 'to_endpoint' => 'Fibre USB extender',    'notes' => 'USB 2.0 over fibre'],
                ],
            ],
            [
                'priority'     => 141,
                'keywords'     => ['usb 3.0', 'usb3', 'usb-c', 'usb 3'],
                'cable_type'   => 'USB 3.0',
                'signal_type'  => 'usb',
                'cores'        => null,
                'to_endpoint'  => 'USB host',
                'notes'        => 'USB 3.0 — 3m passive max',
                'length_tiers' => [
                    ['max_m' => 3,  'cable_type' => 'USB 3.0',                          'cores' => null, 'to_endpoint' => 'USB host',              'notes' => 'USB 3.0 — 3m passive max'],
                    ['max_m' => 15, 'cable_type' => 'Active optical USB 3.0',           'cores' => null, 'to_endpoint' => 'USB host',              'notes' => 'USB 3.0 — active optical'],
                    ['max_m' => 50, 'cable_type' => 'USB 3.0 over fibre extender',      'cores' => null, 'to_endpoint' => 'Fibre USB 3.0 extender', 'notes' => 'USB 3.0 over fibre'],
                ],
            ],
            [
                'priority'     => 142,
                'keywords'     => ['displayport', 'dp ', 'dp1.4', 'dp 1.4', 'dp2.1'],
                'cable_type'   => 'DisplayPort 1.4',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'DP host',
                'notes'        => 'DisplayPort — 2m passive max at 4K60',
                'length_tiers' => [
                    ['max_m' => 2,   'cable_type' => 'DisplayPort 1.4',                  'cores' => null, 'to_endpoint' => 'DP host',               'notes' => 'DisplayPort — 2m passive max at 4K60'],
                    ['max_m' => 15,  'cable_type' => 'Active DisplayPort optical',       'cores' => null, 'to_endpoint' => 'DP host',               'notes' => 'DisplayPort — active optical'],
                    ['max_m' => 100, 'cable_type' => 'DisplayPort over fibre extender',  'cores' => null, 'to_endpoint' => 'Fibre DP extender',     'notes' => 'DisplayPort over fibre'],
                ],
            ],
            [
                // 12G-SDI tier deferred (max_m is smaller than 3G, so the
                // ascending-first-match walk would never trigger it — future
                // enhancement needs a bandwidth-aware tier picker).
                'priority'     => 143,
                'keywords'     => ['sdi', '3g-sdi', '12g-sdi', 'bnc'],
                'cable_type'   => '3G-SDI coax',
                'signal_type'  => 'video',
                'cores'        => null,
                'to_endpoint'  => 'SDI monitor / router',
                'notes'        => '3G-SDI over coax',
                'length_tiers' => [
                    ['max_m' => 100, 'cable_type' => '3G-SDI coax',              'cores' => null, 'to_endpoint' => 'SDI monitor / router', 'notes' => '3G-SDI over coax'],
                    ['max_m' => 500, 'cable_type' => 'SDI over fibre extender',  'cores' => null, 'to_endpoint' => 'Fibre SDI extender',   'notes' => 'Long SDI run — fibre extender'],
                ],
            ],
            [
                'priority'     => 144,
                'keywords'     => ['fibre', 'fiber', 'om3', 'om4', 'os2', 'sfp', 'lc-lc', 'sc-sc'],
                'cable_type'   => 'OM4 multimode fibre',
                'signal_type'  => 'network',
                'cores'        => null,
                'to_endpoint'  => 'Fibre patch panel',
                'notes'        => 'Optical fibre run',
                'length_tiers' => [
                    ['max_m' => 550,   'cable_type' => 'OM4 multimode fibre',       'cores' => null, 'to_endpoint' => 'Fibre patch panel', 'notes' => 'OM4 multimode fibre — up to 550m'],
                    ['max_m' => 40000, 'cable_type' => 'OS2 single-mode fibre',     'cores' => null, 'to_endpoint' => 'Fibre patch panel', 'notes' => 'OS2 single-mode fibre — long haul'],
                ],
            ],
        ];
    }
}

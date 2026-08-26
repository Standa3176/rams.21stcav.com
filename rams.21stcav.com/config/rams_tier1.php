<?php

/*
|--------------------------------------------------------------------------
| WARNING — SAFETY-CRITICAL DEFAULTS
|--------------------------------------------------------------------------
|
| WARNING — safety-critical defaults. All content in this file has been
| drafted from industry-standard AV install practice but MUST be reviewed
| by 21CAV's H&S consultant before real-world use in litigation-adjacent
| RAMS documents. Engineers may override any of these defaults per-project
| via the review form. This file is a fallback layer only.
|
| Quick task 260712-twi (Tier-1 AV RAMS Content Upgrade).
|
| Consumer: App\Services\Rams\Tier1RamsDefaultsService — injects these
| defaults into $data when the corresponding reviewed_data / generated_data
| key is empty. Engineer-supplied values ALWAYS win.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master kill-switch
    |--------------------------------------------------------------------------
    |
    | When false, Tier1RamsDefaultsService::injectDefaultsIntoRamsData()
    | returns $data unchanged, the PDF Section 5 render-time defensive
    | fallback is skipped, the COSHH baseline table is replaced by the
    | legacy 4-item bullet list, and no standards table is rendered when
    | $data['standards_references'] is empty.
    |
    */
    'enabled' => env('RAMS_TIER1_DEFAULTS', true),

    /*
    |--------------------------------------------------------------------------
    | Hazard tiering kill-switch (Phase 26)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY the Phase 26 hazard include-when auto-population — i.e.
    | Plan 26-04's HazardIncludeWhenResolver wiring into the RAMS build
    | pipeline. Intentionally decoupled from 'enabled' above: that flag
    | also gates standards_references and coshh_products/coshh_baseline,
    | neither of which Phase 26 touches. Setting
    | RAMS_HAZARD_LIBRARY_TIERING=false disables auto-population only —
    | explicit engineer hazard picks are unaffected. The register does
    | NOT fall back to the old fixed 11-hazard baseline array formerly
    | defined here (removed by this plan) — it simply contains only what
    | was explicitly picked. This is the one-.env-edit rollback the
    | phase's live-validation constraint requires.
    |
    */
    'hazard_tiering_enabled' => env('RAMS_HAZARD_LIBRARY_TIERING', true),

    /*
    |--------------------------------------------------------------------------
    | Display-lift gate kill-switch (Phase 27, GATE-09)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY RamsComplianceUpgradeService::enforceDisplayLiftGate() — the
    | independent re-check of every display item's stated manual-handling
    | team size against App\Services\Rams\DisplayLiftPolicy::violatesPolicy()
    | (4+ operatives at any size, 2 operatives above 90", or 1 operative at
    | 55" or larger). When false, enforceDisplayLiftGate() is never called —
    | upgrade() proceeds byte-identical to pre-GATE-09 behaviour, no redeploy
    | required. This is this milestone's live-validation posture applied to
    | GATE-09 specifically: see 27-CONTEXT.md's Reversibility discretion item
    | and T-27-03 in 27-03-PLAN.md's threat register.
    |
    */
    'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),

    /*
    |--------------------------------------------------------------------------
    | Baseline COSHH inventory
    |--------------------------------------------------------------------------
    |
    | Injected into $data['coshh_baseline'] (non-clobbering — the existing
    | $data['coshh'] engineer-additions key is preserved). GHS / CLP hazard
    | codes are verbatim from spec (H2xx physical, H3xx health, H4xx env).
    | Refer to product SDS for exact classification; SDS binder is held in
    | Vehicle 1 tool cabinet.
    |
    */
    'coshh_products' => [

        [
            'product'      => 'Isopropyl Alcohol (IPA) — cleaning solvent',
            'typical_use'  => 'Screen and lens cleaning; contact-terminal cleaning before soldering.',
            'ghs_codes'    => ['H225', 'H319', 'H336'],
            'controls'     => [
                'Use in well-ventilated area only; do not use inside sealed rack cabinet with power on.',
                'Nitrile gloves and safety glasses; avoid skin contact and inhalation.',
                'Store in original labelled container; keep away from ignition sources.',
            ],
        ],

        [
            'product'      => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37',
            'typical_use'  => 'Cable termination on legacy speaker circuits and control wiring.',
            'ghs_codes'    => ['H360', 'H373'],
            'controls'     => [
                'Wash hands after handling; do not eat, drink or smoke in soldering area.',
                'Fume-extraction fan or open window during soldering; solder in short bursts.',
                'Not to be used by anyone pregnant or planning pregnancy — substitute lead-free.',
            ],
        ],

        [
            'product'      => 'Rosin (Colophony) Flux — solder flux',
            'typical_use'  => 'Applied to solder joints to promote wetting and remove oxides.',
            'ghs_codes'    => ['H317'],
            'controls'     => [
                'Local exhaust ventilation or activated-carbon fume filter during any soldering.',
                'Nitrile gloves; wash forearms if flux splash occurs.',
                'Stop use if respiratory sensitisation symptoms develop; report to Ops Manager.',
            ],
        ],

        [
            'product'      => 'Expanding Foam — cable-penetration fire-stop',
            'typical_use'  => 'Sealing cable penetrations through walls, floors and fire compartments.',
            'ghs_codes'    => ['H319', 'H332', 'H334', 'H351'],
            'controls'     => [
                'FFP3 mask, nitrile gloves and safety glasses mandatory during application.',
                'Do NOT use inside comms rooms with active clients present; schedule out-of-hours.',
                'Discard uncured cans as hazardous waste — do not puncture, do not incinerate.',
            ],
        ],

        [
            'product'      => 'Contact Cleaner (aerosol) — electrical contact cleaner',
            'typical_use'  => 'Cleaning connectors, potentiometers and switch contacts on retained equipment.',
            'ghs_codes'    => ['H222', 'H336'],
            'controls'     => [
                'Do NOT use on live equipment — isolate first; some formulations are flammable.',
                'Use in well-ventilated area; short bursts only; do not empty full can in one session.',
                'Store aerosols below 50°C; do not leave in direct sun in vehicle.',
            ],
        ],

        [
            'product'      => 'Cable Pulling Lubricant',
            'typical_use'  => 'Cable pulls through conduit or containment where friction risks jacket damage.',
            'ghs_codes'    => ['H319'],
            'controls'     => [
                'Nitrile gloves and safety glasses; avoid eye contact.',
                'Wipe up spills immediately — leaves slip hazard on hard floors.',
                'Confirm compatibility with cable jacket material (LSZH vs PVC) before application.',
            ],
        ],

        [
            'product'      => 'Silicone Thermal Compound — heatsink paste',
            'typical_use'  => 'Applied between codec or amp CPU and heatsink during on-site repair only.',
            'ghs_codes'    => ['H319', 'H315'],
            'controls'     => [
                'Nitrile gloves; avoid skin and eye contact.',
                'Wash hands after use — silicone residue contaminates screen and finger-swipe surfaces.',
                'Store in tool cabinet; small quantities only carried on site.',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Standards & Guidance references (Section 3 table)
    |--------------------------------------------------------------------------
    |
    | Injected into $data['standards_references'] when the reviewed data
    | supplies none. Rendered as the "Standards & Guidance Applicable to
    | This Works" table in PDF Section 3.
    |
    */
    'standards_references' => [

        [
            'ref'        => 'BS 7671:2018+A2:2022',
            'title'      => 'Requirements for Electrical Installations (IET Wiring Regulations, 18th Edition)',
            'applies_to' => 'Every fixed-cable termination, mains connection and rack power distribution installed on this project.',
        ],

        [
            'ref'        => 'BS 6701:2016+A1:2020',
            'title'      => 'Telecommunications equipment and telecommunications cabling — Specification for installation, operation and maintenance',
            'applies_to' => 'Structured cabling, comms-room installations and any patch-panel or RJ45 termination works.',
        ],

        [
            'ref'        => 'BS EN 60849',
            'title'      => 'Sound systems for emergency purposes',
            'applies_to' => 'Voice alarm, evacuation announcement and life-safety audio installation works where in scope.',
        ],

        [
            'ref'        => 'BS 8492',
            'title'      => 'Public address (PA) systems — Code of practice',
            'applies_to' => 'Public address and general paging systems installed within the works scope.',
        ],

        [
            'ref'        => 'CDM 2015',
            'title'      => 'Construction (Design and Management) Regulations 2015',
            'applies_to' => 'Duty holder roles (Client, Principal Designer, Principal Contractor, 21CAV as sub-contractor) and pre-construction information for this project.',
        ],

        [
            'ref'        => 'HSG 47',
            'title'      => 'Avoiding danger from underground services',
            'applies_to' => 'Any external drilling, floor-box installation or below-ground penetration on site.',
        ],

        [
            'ref'        => 'HSG 273',
            'title'      => 'The safe use of vehicles on construction sites',
            'applies_to' => 'Site vehicles used to deliver AV equipment; loading, unloading and reversing operations.',
        ],

        [
            'ref'        => 'AVIXA F502.01',
            'title'      => 'AV Systems Performance Verification',
            'applies_to' => 'Post-install commissioning verification methodology and system sign-off criteria applied on this project.',
        ],

        [
            'ref'        => 'PUWER 1998',
            'title'      => 'Provision and Use of Work Equipment Regulations 1998',
            'applies_to' => 'All power tools, access equipment, MEWP and lifting equipment used during the works.',
        ],

        [
            'ref'        => 'BS EN 60825-1:2014+A11:2021',
            'title'      => 'Safety of laser products — Part 1: Equipment classification and requirements',
            'applies_to' => 'Every laser-based projector, laser rangefinder, or laser-alignment tool installed or used on this project. Class 1 or 2 devices are permitted in normal installation; Class 3R and above require documented risk assessment, warning signage, and where practicable installation geometry that keeps the primary beam above 2.1m head height in any accessible area.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | AV-specific requirement bullets for MethodStatementPrompt
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Core\AI\Prompts\MethodStatementPrompt::build() as the
    | source of the 4 AV-specific requirement bullets appended to the JSON
    | prompt's Requirements: list. These are formatting hints — the AI is
    | told to INCLUDE these considerations if the supplied scope calls for
    | them, not to invent them. Same guardrail pattern as the existing
    | "penultimate step MUST cover Integration, Testing & Commissioning"
    | bullet.
    |
    */
    'av_prompt_bullets' => [

        'If cable routing crosses live-services zones (containment, tray, existing conduit), the Installation step must call out isolation and \'test-before-touch\' verification of any existing power/data circuit encountered.',
        'Any control-system programming or DSP configuration step must specify that engineers work OFF the live signal path (staging PC or bench-programmed) before hot-cutover, and that the client IT contact is informed before any network device joins the LAN.',
        'Where new displays, speakers or cabling attach to plant that another trade owns (ceiling grid, partitions, structural steel), the relevant step must reference coordination with that trade before penetration or fixing.',
        'The Commissioning step must reference power-cycle and network-fail recovery verification for every codec, DSP or control processor deployed.',

    ],

];

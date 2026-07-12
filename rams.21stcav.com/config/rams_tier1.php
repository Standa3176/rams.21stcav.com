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
    | Baseline AV hazard register
    |--------------------------------------------------------------------------
    |
    | Injected into $data['hazards'] when the reviewed data supplies no
    | hazards. Each hazard MUST carry >= 3 industry-standard control
    | measures. Likelihood/severity scores are pre-mitigation and residual
    | (post-mitigation) 1-5. Reviewed by AV install ops as best-effort
    | tier-one competence baseline for UK AV installation works.
    |
    | See CDM 2015, HSG 65, AVIXA F502.01, PUWER 1998, MHOR 1992.
    |
    */
    'baseline_hazards' => [

        [
            'hazard'          => 'Working at Height',
            'persons_at_risk' => ['21CAV Engineers', 'Other Trades', 'Client Staff'],
            'pre_likelihood'  => 4,
            'pre_severity'    => 4,
            'controls'        => [
                'Class 1 stepladder EN131 rated with 3 points of contact at all times when in use.',
                'Podium steps preferred over ladders where work exceeds 5 minutes duration.',
                'PASMA or IPAF certification held by any engineer using mobile access tower or MEWP.',
                'Buddy system in operation whenever any engineer works above 2 metres.',
                'Access equipment inspected daily before use and recorded on Site Inspection Log.',
                'Fall arrest / restraint PPE deployed where edge protection is not present.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 3,
        ],

        [
            'hazard'          => 'Manual Handling of AV Equipment',
            'persons_at_risk' => ['21CAV Engineers', 'Other Trades'],
            'pre_likelihood'  => 4,
            'pre_severity'    => 3,
            'controls'        => [
                'Manual Handling Operations Regulations 1992 (MHOR) compliant lift assessment before any single-item lift over 20kg.',
                'Two-person lift mandatory for displays 55" and above and any rack shell weighing over 25kg.',
                'Mechanical aids (sack trolley, panel dolly, pallet truck) used in preference to manual lifts where site permits.',
                'Route survey completed before large item is moved from vehicle to install location.',
                'Loading and unloading zones agreed with site contact and coned off before works.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
        ],

        [
            'hazard'          => 'Electrical Isolation & Live Working',
            'persons_at_risk' => ['21CAV Engineers', 'Client Staff', 'Other Trades'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 5,
            'controls'        => [
                'BS 7671:2018+A2:2022 compliant isolation — lock-off and tag-off applied to every circuit before termination or connection works.',
                'Test-before-touch verification with proven voltage-indicator on every conductor before any physical contact.',
                'Only competent persons (18th Edition qualified or equivalent) perform any works on fixed electrical installation.',
                'Live working NOT permitted except where absolutely unavoidable and authorised in writing under a permit-to-work.',
                'Rescue plan and shock-treatment first-aid kit on site whenever any electrical works are in progress.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 4,
        ],

        [
            'hazard'          => 'Slips, Trips & Falls from Cable Runs and Site Clutter',
            'persons_at_risk' => ['21CAV Engineers', 'Client Staff', 'Members of Public'],
            'pre_likelihood'  => 4,
            'pre_severity'    => 3,
            'controls'        => [
                'Loose cabling routed against skirtings or through cable-covers rated for the expected foot-traffic load.',
                'Temporary cable runs across walkways covered with heavy-duty yellow cable-trunking or matting.',
                'Site kept clean, tools stored back to toolbox at every task-change, no offcuts left on floor.',
                'Wet-floor / drilling-debris signage deployed when floor becomes temporarily hazardous.',
                'Wet vacuum used to remove drilling debris from client carpet or hard floor before area is re-opened.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
        ],

        [
            'hazard'          => 'Drilling into Ceilings, Walls and Floors — Unknown Services Behind Substrate',
            'persons_at_risk' => ['21CAV Engineers', 'Client Staff', 'Other Trades'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 5,
            'controls'        => [
                'HSG 47 "Avoiding danger from underground services" reviewed for any works on external walls, floor slabs or below-ground penetrations.',
                'Cable / pipe / metal detector scan carried out on every proposed drill site before drill goes in.',
                'Client asset drawings requested and reviewed for services in target substrate; recorded on Permit to Drill.',
                'Trial-pilot drill (2mm bit) always precedes final fixing bore; stop and re-assess if trial reveals unexpected substrate.',
                'FFP2 respiratory PPE + safety goggles worn during any drilling into plasterboard, masonry or MDF.',
                'Dust extraction attachment used on drill where surface is finished and dust would settle on client property.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 4,
        ],

        [
            'hazard'          => 'Working in Occupied Client Space',
            'persons_at_risk' => ['21CAV Engineers', 'Client Staff', 'Members of Public'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'controls'        => [
                'Working area segregated from client thoroughfares using barriers, cones and signage before works commence.',
                'Client site contact informed of noise / disruption windows in advance and daily.',
                'Toolbox talk delivered to any client staff who must transit the works area.',
                'Where feasible, noisiest works scheduled outside client core operating hours.',
                'Emergency-exit and fire-escape routes kept clear at all times regardless of works progress.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
        ],

        [
            'hazard'          => 'Use of Access Equipment — Ladders, MEWPs, Mobile Towers',
            'persons_at_risk' => ['21CAV Engineers', 'Other Trades'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 4,
            'controls'        => [
                'IPAF PAL card mandatory for any engineer operating a Mobile Elevated Work Platform (scissor lift, cherry picker).',
                'PASMA card mandatory for any engineer erecting or dismantling a mobile access tower.',
                'Daily pre-use inspection of every access-equipment item logged on Site Inspection Log; defects tagged out.',
                'Ground-level surface confirmed level, load-rated and clear of tripping hazards before tower or MEWP is set up.',
                'Wind-speed check for any external MEWP works — no works above 12.5 m/s (Beaufort 6) mean wind speed.',
                'Second person (spotter) at ground level during any powered platform movement.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 3,
        ],

        [
            'hazard'          => 'Engineer Fatigue from Long Working Days',
            'persons_at_risk' => ['21CAV Engineers', 'Other Trades', 'Members of Public'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'controls'        => [
                'Working Time Regulations 1998 observed — 11 hour minimum daily rest between shifts, break every 6 hours.',
                'Shift patterns exceeding 10 hours require Project Manager approval and a rotating second engineer for high-risk tasks.',
                'Overnight-driving assessment before any works following a 300+ mile client journey.',
                'Engineers report fatigue-related concerns to Lead Engineer without penalty; task reassignment or stand-down authorised.',
                'No working-at-height, no live-electrical works, no MEWP operation after hour 10 of a working shift.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
        ],

    ],

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

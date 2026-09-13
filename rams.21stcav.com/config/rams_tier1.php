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
    | FFP2 / confined-space gate kill-switch (Phase 28, GATE-06/GATE-07)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY
    | RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate() — the
    | independent re-check of every hazard NAME and every surviving hazard
    | control line (via App\Services\Rams\ControlTextRuleViolations::detect())
    | for the 'ffp2' and 'confined_space' rule keys, plus a raw case-
    | insensitive substring check for the literal token FFP2 across
    | $data['ppe'] and $data['ppe_matrix'][*]['ppe']. When false,
    | enforceFfp2AndConfinedSpaceGate() is never called — upgrade() proceeds
    | byte-identical to pre-GATE-06/07 behaviour, no redeploy required.
    |
    | A NEW, INDEPENDENT flag per D-08 — deliberately never reuses
    | RAMS_DISPLAY_LIFT_GATE, so GATE-09 can never be accidentally disarmed
    | by a GATE-06/07 rollback, or vice versa. See
    | 28-CONTEXT.md's D-08 discretion item and this plan's threat register
    | (T-28-06-01).
    |
    */
    'ffp2_confined_space_gate_enabled' => env('RAMS_PPE_CEILING_ELECTRICAL_GATE', true),

    /*
    |--------------------------------------------------------------------------
    | CDM duty-holder / emergency-arrangements gate kill-switch (Phase 29,
    | GATE-11/GATE-12)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY RamsComplianceUpgradeService::enforceCdmGate() and
    | ::enforceEmergencyGate() (added in Plan 29-03) — the independent
    | throwing re-checks of the CDM duty-holder table (RULE-07) and the
    | site_emergency A&E branch (RULE-08, via
    | App\Services\Rams\SiteEmergencyResolver::classify()). When false,
    | neither method is called — upgrade() proceeds byte-identical to
    | pre-GATE-11/12 behaviour, no redeploy required.
    |
    | A NEW, INDEPENDENT flag per D-03 — deliberately never reuses
    | RAMS_DISPLAY_LIFT_GATE (GATE-09) or RAMS_PPE_CEILING_ELECTRICAL_GATE
    | (GATE-06/07), so one gate's rollback can never accidentally disarm
    | another's.
    |
    | UNLIKE the two precedents above, this flag's default is FALSE — a
    | deliberate divergence (29-CONTEXT.md D-03, 29-RESEARCH.md Pitfall 5):
    | "Copy-pasting the `env('RAMS_..._GATE', true)` pattern from
    | GATE-06/07/09 verbatim for GATE-11/12 would arm the gate by default on
    | next deploy, before the CDM backfill has run — reproducing the exact
    | 'content gate defaulting ON is a deploy-order trap' failure Phase 28's
    | own retrospective explicitly warns against." GATE-06/07/09 could
    | default `true` because the corpus was already measured clean at ship
    | time; GATE-11/12's corpus (46/54 production rows still carrying the
    | CDM placeholder, per 29-MEASUREMENT.md) has not been. Deploy order:
    | ship code (this flag stays false) -> run the Plan 29-05 backfill ->
    | verify a live regeneration -> flip `RAMS_CDM_AE_GATE=true` as a
    | separate one-line `.env` change.
    |
    */
    'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),

    /*
    |--------------------------------------------------------------------------
    | Structural gates kill-switch (Phase 30, GATE-01/GATE-02/GATE-04)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY RamsComplianceUpgradeService::enforceOrphanControlGate()
    | (GATE-01 — a method step / hazard control referencing a document,
    | permit or hold point with no supporting hazard row AND no supporting
    | client-responsibility entry), ::enforceAreaCoverageGate() (GATE-02 —
    | every area/room has at least one method step), and
    | ::enforceResidualScoreGate() (GATE-04 — residual score never exceeds
    | initial score; a residual SEVERITY reduction is flagged via
    | compliance_warnings for human review, not thrown). When false, none
    | of the three methods is ever called — upgrade() proceeds
    | byte-identical to pre-Phase-30 behaviour, no redeploy required.
    |
    | These three are dispatched under ONE flag (not three) because D-04
    | judged their failure mode identical — a false positive on legitimate
    | output — so they can only ever be usefully flipped together; three
    | extra config blocks would buy no rollback granularity. A NEW,
    | INDEPENDENT flag — deliberately never reuses RAMS_DISPLAY_LIFT_GATE,
    | RAMS_PPE_CEILING_ELECTRICAL_GATE or RAMS_CDM_AE_GATE, so a rollback
    | of one gate generation can never accidentally disarm another's.
    |
    | Ships DISARMED (defaults false) per D-03: unlike GATE-06/07/09 (which
    | could default true because the corpus was already measured clean at
    | ship time), Phase 30's corpus has not been measured. Deploy order:
    | ship code (this flag stays false) -> verify a live regeneration ->
    | flip RAMS_STRUCTURAL_GATES=true as a separate one-line .env change.
    |
    */
    'structural_gates_enabled' => env('RAMS_STRUCTURAL_GATES', false),

    /*
    |--------------------------------------------------------------------------
    | Missing risk reference gate kill-switch (Phase 30, GATE-14)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY RamsComplianceUpgradeService::enforceMissingRiskRefGate()
    | (GATE-14 — a method step failing to cite hazards its own text plainly
    | implies, per the missing_risk_implications map below). When false,
    | the method is never called — upgrade() proceeds byte-identical to
    | pre-GATE-14 behaviour, no redeploy required.
    |
    | Deliberately its OWN flag, separate from RAMS_STRUCTURAL_GATES, per
    | D-04: GATE-14 infers a missing hazard citation from a deterministic
    | keyword-implication map rather than re-checking an already-derived
    | value, which is a distinct failure mode (bad hazard inference) from
    | the structural trio's orphan/coverage/score checks — it MUST be
    | killable without disarming GATE-01/02/04. A NEW, INDEPENDENT flag —
    | never reuses RAMS_DISPLAY_LIFT_GATE, RAMS_PPE_CEILING_ELECTRICAL_GATE,
    | RAMS_CDM_AE_GATE or RAMS_STRUCTURAL_GATES.
    |
    | Ships DISARMED (defaults false) per D-03 — same measured-corpus
    | rationale as RAMS_STRUCTURAL_GATES above. Deploy order: ship code
    | (stays false) -> verify a live regeneration -> flip
    | RAMS_MISSING_RISK_REF_GATE=true as a separate one-line .env change.
    |
    */
    'missing_risk_ref_gate_enabled' => env('RAMS_MISSING_RISK_REF_GATE', false),

    /*
    |--------------------------------------------------------------------------
    | Hot-works contradiction gate kill-switch (Phase 30, GATE-13)
    |--------------------------------------------------------------------------
    |
    | Gates ONLY RamsComplianceUpgradeService::enforceHotWorksGate()
    | (GATE-13 — a document asserting "no hot works" while a hot-works
    | permit is required, per addPermitAndIsolation()'s rule text at
    | ~:939, or solder/flux is listed in COSHH). When false, the method is
    | never called — upgrade() proceeds byte-identical to pre-GATE-13
    | behaviour, no redeploy required.
    |
    | A NEW, INDEPENDENT flag per D-04 — never reuses
    | RAMS_DISPLAY_LIFT_GATE, RAMS_PPE_CEILING_ELECTRICAL_GATE,
    | RAMS_CDM_AE_GATE, RAMS_STRUCTURAL_GATES or
    | RAMS_MISSING_RISK_REF_GATE, because GATE-13 flips a full phase LATER
    | than the rest (see D-02 below) — a single phase-wide flag is
    | impossible here.
    |
    | D-02: GATE-13 ships WHOLE but DISARMED in Phase 30, and is flipped on
    | only in Phase 31. Its COSHH half cannot fire correctly today:
    | Tier1RamsDefaultsService::injectDefaultsIntoRamsData() sets
    | $data['coshh_baseline'] UNCONDITIONALLY (:81, docblock :34 says
    | "ALWAYS set") from this file's coshh_products baseline, which carries
    | Tin/Lead Solder (:162 below) and Rosin Flux (:173 below). Every
    | generated RAMS therefore already lists solder and flux, so an armed
    | GATE-13 would error on any document also asserting "no hot works" —
    | the common case. The permit half is equally unready:
    | addPermitAndIsolation() emits its hot-works-permit rule line on every
    | document unconditionally (~:939 below). Phase 31 (GATE-10/RULE-05)
    | makes the COSHH table job-conditional, which is the prerequisite for
    | arming this flag. Deploy order: ship code (stays false through
    | Phase 30) -> Phase 31 makes coshh_baseline job-conditional -> verify
    | a live regeneration -> flip RAMS_HOT_WORKS_GATE=true as a separate
    | one-line .env change.
    |
    */
    'hot_works_gate_enabled' => env('RAMS_HOT_WORKS_GATE', false),

    /*
    |--------------------------------------------------------------------------
    | GATE-01 orphan-control trigger vocabulary (Phase 30, D-06/D-07)
    |--------------------------------------------------------------------------
    |
    | Data, not code, so the trigger vocabulary is tunable without a
    | deploy — a gate's false-positive rate can only be learned from the
    | live corpus, and D-03 means that learning happens post-deploy. Each
    | row's `phrase` is literal lowercase text a method step or hazard
    | control may contain (a reference to a document, permit or hold
    | point); `signal` is the key GATE-01 checks on the hazard side via
    | App\Services\Rams\StructuralGateVocabulary (which reuses
    | HazardIncludeWhenResolver's TIER2/TIER3 const maps, D-07 — do not
    | invent a second, parallel vocabulary); `label` is human wording for
    | the error message. Every `signal` value below MUST already exist as
    | a key in HazardIncludeWhenResolver's const maps — enforced by
    | tests/Unit/Services/Rams/StructuralGateConfigTest.php.
    |
    | GATE-01 errors when EITHER the hazard row OR the client-responsibility
    | entry is missing for a triggered phrase — both are required support
    | (D-05). The asbestos row is the canonical PORTING-NOTES example: a
    | step mentioning "asbestos register" with no Asbestos-Containing
    | Materials hazard row and no matching client-responsibility entry is
    | an orphan control.
    |
    */
    'structural_gate_triggers' => [

        ['phrase' => 'asbestos register', 'signal' => 'asbestos', 'label' => 'asbestos register'],
        ['phrase' => 'asbestos survey', 'signal' => 'asbestos', 'label' => 'asbestos survey'],
        ['phrase' => 'refurbishment and demolition survey', 'signal' => 'asbestos', 'label' => 'refurbishment and demolition (R&D) survey'],
        ['phrase' => 'pre-2000', 'signal' => 'asbestos', 'label' => 'pre-2000 building age reference'],
        ['phrase' => 'pre 2000', 'signal' => 'asbestos', 'label' => 'pre-2000 building age reference'],
        ['phrase' => 'age unknown', 'signal' => 'asbestos', 'label' => 'building age unknown reference'],
        ['phrase' => 'built before 2000', 'signal' => 'asbestos', 'label' => 'built-before-2000 building age reference'],
        // Permit-to-work is issued in this business's own wording for
        // ceiling void / riser / restricted-area access
        // (addPermitAndIsolation() rule text) — ceiling_void_access is the
        // closest existing signal, not a purpose-built "permit" signal.
        ['phrase' => 'permit to work', 'signal' => 'ceiling_void_access', 'label' => 'permit to work'],
        // Isolation certificate / hot-works permit both concern electrical
        // isolation controls in addPermitAndIsolation()'s own rule text —
        // mains_connection is the closest existing signal.
        ['phrase' => 'isolation certificate', 'signal' => 'mains_connection', 'label' => 'isolation certificate'],
        ['phrase' => 'hot-works permit', 'signal' => 'mains_connection', 'label' => 'hot-works permit'],
        ['phrase' => 'hold point', 'signal' => 'occupied_premises', 'label' => 'hold point'],

    ],

    /*
    |--------------------------------------------------------------------------
    | GATE-14 missing-risk implication map (Phase 30, D-06/D-07)
    |--------------------------------------------------------------------------
    |
    | Explicitly NOT crossReferenceMethodStatementRisks()'s $keywordRiskMap
    | (RamsComplianceUpgradeService.php ~:1015-1027) — that map is the exact
    | code responsible for the defect GATE-14 exists to catch (it is
    | intersection-based: a keyword must appear in BOTH the step text AND
    | the hazard name, so implied-but-unworded hazards are silently
    | dropped). Reusing it here would be the "a gate must never re-derive
    | the thing it is checking" anti-pattern the enforceDisplayLiftGate()
    | docblock (~:1268-1276) warns against.
    |
    | Each row's `phrase` is step-action text (lowercase substring match
    | against the combined method-statement phase text); `signal` is the
    | HazardIncludeWhenResolver signal key GATE-14 checks for on the
    | hazard side, via the same App\Services\Rams\StructuralGateVocabulary
    | helper GATE-01 uses (D-07 — one shared vocabulary); `label` is human
    | wording for the warning message. Every `signal` value below MUST
    | already exist as a key in HazardIncludeWhenResolver's const maps —
    | enforced by tests/Unit/Services/Rams/StructuralGateConfigTest.php.
    |
    | GATE-14 only fires when the implied hazard is PRESENT in the
    | register but not cited by the step (a citation gap) — never when the
    | hazard is absent entirely (that is GATE-01/HAZ territory, not a
    | missing citation). See StructuralGateVocabulary /
    | enforceMissingRiskRefGate() for that safety property.
    |
    */
    'missing_risk_implications' => [

        ['phrase' => 'wall mount', 'signal' => 'mounting_above_reach', 'label' => 'working at height (wall mount)'],
        ['phrase' => 'onto the bracket', 'signal' => 'mounting_above_reach', 'label' => 'working at height (bracket fixing)'],
        ['phrase' => 'lift the display', 'signal' => 'mounting_above_reach', 'label' => 'working at height (display lift)'],
        ['phrase' => 'above 2m', 'signal' => 'mounting_above_reach', 'label' => 'working at height (above 2m)'],
        ['phrase' => 'stepladder', 'signal' => 'mounting_above_reach', 'label' => 'working at height (stepladder)'],
        ['phrase' => 'podium', 'signal' => 'mounting_above_reach', 'label' => 'working at height (podium steps)'],
        ['phrase' => 'ceiling void', 'signal' => 'ceiling_void_access', 'label' => 'ceiling void access'],

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

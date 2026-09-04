<?php

namespace Database\Seeders;

use App\Models\HazardTemplate;
use App\Services\Rams\DisplayLiftPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the global hazard template library from the 21cav-rams skill's
 * 18-hazard library (.planning/reference/21cav-rams-skill/references/hazard-library.md).
 *
 * Phase 26 (Hazard Library Structural Inversion) — HAZ-01/HAZ-03. This file is
 * the version-controlled source of truth (D-01); hazard_templates is the
 * runtime store the app reads from. Every row carries an `include_when`
 * condition (D-05/D-06):
 *   - 'always'        — tier 1, unconditional (4 hazards).
 *   - 'signal:<key>'   — tier 2, deterministic keyword/tag match (9 hazards).
 *   - 'confirm:<key>'  — tier 3, always surfaced requiring human confirmation
 *                         (5 hazards) — never an AI-evaluated condition.
 *
 * Run with:
 *   php artisan db:seed --class=HazardTemplateSeeder
 *
 * Safe to run multiple times (D-03):
 *   - Upserts by name — updates an existing global template in place rather
 *     than duplicating it.
 *   - Never truncates.
 *   - Never touches is_global=false (user-created) rows.
 *   - Deletes only the is_global=true rows this reseed supersedes (the old
 *     13-hazard library folded into the 18 per D-02) — scoped strictly to
 *     is_global=true so it can never reach a user's own row.
 *
 * D-02's fold mapping (old app hazard names -> nearest skill hazard) is NOT
 * duplicated in this file. Its single, executable, machine-readable form is
 * `App\Services\Rams\LegacyHazardNameFoldMap` (Phase 26 Plan 08), consumed
 * by `HazardLibraryService::fuzzyMatch()` — read that class, not this
 * docblock, for the authoritative 16-entry mapping.
 */
class HazardTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $hazards = $this->standardHazards();

        foreach ($hazards as $hazard) {
            // Idempotent: update if a global template with this name already exists
            $existing = HazardTemplate::where('is_global', true)
                ->where('name', $hazard['name'])
                ->first();

            $payload = array_merge($hazard, [
                'user_id'   => null,   // null = global
                'is_global' => true,
            ]);

            if ($existing) {
                $existing->update($payload);
                continue;
            }

            HazardTemplate::create($payload);
        }

        // ── Orphan-row cleanup (D-02): the old 13-hazard library is folded
        // into this seeder's 18 include_when-carrying hazards; any is_global
        // row whose name is no longer emitted here is a superseded row and
        // is removed. Scoped to is_global=true only — the where() clause
        // means this can never reach an is_global=false (user-created) row,
        // regardless of name collision. Not a truncate.
        $newNames = array_column($hazards, 'name');

        $deletedCount = HazardTemplate::where('is_global', true)
            ->whereNotIn('name', $newNames)
            ->delete();

        $this->command->info(
            'HazardTemplateSeeder: ' . count($hazards) . ' standard hazards seeded, '
            . $deletedCount . ' superseded global row(s) removed.'
        );
    }

    // ── 21cav-rams skill hazard library (18 hazards) ───────────────────────

    private function standardHazards(): array
    {
        return [
            // ── 1. Working at height (tier 2 — signal:mounting_above_reach) ───
            [
                'name'            => 'Working at height',
                'description'     => 'Use of access equipment for AV mounting and works above standing reach.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'Select appropriate access equipment: podium steps or mobile access tower. Kick stools for low-level access only.',
                    'Confirm ceiling or mounting height at survey. Where working height exceeds safe tower range, a MEWP with an IPAF-certified operator is required and this RAMS is reissued.',
                    'Inspect all access equipment before each use — do not use damaged items. Towers erected, altered and dismantled by PASMA-trained operatives only, and tagged.',
                    'Set up access equipment on stable, level ground only. Castors locked. Never move a platform with a person or tools on it.',
                    'Maintain three points of contact when ascending or descending. Step ladders are not used as a working platform for extended or two-handed tasks.',
                    'Erect barriers and warning signs below any overhead work. A second operative attends at ground level at all times.',
                    'Wear a hard hat within the overhead works exclusion zone.',
                    'Never over-reach — reposition access equipment as required. Tools raised and lowered in a bag or tethered.',
                    'Do not use chairs, boxes or improvised platforms as working platforms.',
                ],
                'include_when'    => 'signal:mounting_above_reach',
            ],

            // ── 2. Manual handling (tier 2 — signal:display_mount_or_rack) ────
            [
                'name'            => 'Manual handling',
                'description'     => 'Moving, lifting and positioning AV displays, mounts and equipment.',
                'pre_likelihood'  => 4,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 3,
                'controls'        => [
                    'Mechanical aids (sack trucks, lifting trolleys, panel lifter) are used where the weight, dimensions, shape, route or the task-specific manual handling assessment indicates they are required. There is no fixed safe lifting weight in law — team size and aids follow the assessment, not a kg threshold.',
                    DisplayLiftPolicy::genericBandSummary(),
                    // NOTE: DisplayLiftPolicy::wallMountRemovalStatement() is deliberately
                    // NOT listed here. `references/hazard-library.md` marks that control
                    // "(Removal jobs only — omit entirely on an installation-only job.)"
                    // and house-rules.md's "Removal / strip-out language is removal-only"
                    // says the same. A static control on this hazard fires on every job
                    // matching signal:display_mount_or_rack, including installation-only
                    // work, which would assert strip-out activity that is not in scope.
                    // The statement is emitted conditionally instead, by
                    // RamsComplianceUpgradeService::deriveMaterialHandling()'s
                    // scope_items.decommission scan. See 27-08 in deferred-items.md.
                    'Pre-plan the route and clear all access paths before moving equipment. Passenger lift used between floors — no carrying on stairs.',
                    'Wear appropriate gloves and safety footwear at all times.',
                    'Conduct a task-specific manual handling assessment prior to every lift. Any operative may stop a lift.',
                    'Take regular breaks to avoid fatigue during prolonged lifting tasks. Do not lay displays face-down.',
                ],
                'include_when'    => 'signal:display_mount_or_rack',
            ],

            // ── 3. Electrical (tier 2 — signal:mains_connection) ──────────────
            [
                'name'            => 'Electrical',
                'description'     => 'Electric shock or burns when making or breaking mains connections.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'All electrical work to be carried out by competent, authorised persons only. No live working under any circumstances.',
                    'Isolate and lock off power circuits before making any connection or disconnecting decommissioned equipment.',
                    'Test for dead using an approved GS38-compliant voltage indicator, proved on a known source before and after testing.',
                    'Do not use damaged cables, plugs or extension leads — remove from service immediately.',
                    'All temporary power supplies to use RCD protection. All tools PAT tested and in date.',
                    'Comply with BS 7671 (IET Wiring Regulations) 18th Edition at all times.',
                    'Notify the client facilities team before isolating any shared power circuit. Any hardwired supply is isolated by the client\'s authorised person with site lock-off and tagging applied.',
                ],
                'include_when'    => 'signal:mains_connection',
            ],

            // ── 4. Slips, trips and falls (tier 1 — always) ───────────────────
            [
                'name'            => 'Slips, trips and falls',
                'description'     => 'Trips and slips from cables, tools and local floor hazards.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Keep all work areas clean and tidy — return tools to bags after each task.',
                    'Secure trailing cables immediately with cable covers or matting. No cable left across a walkway overnight.',
                    'Erect hazard warning signs and barriers around active work zones.',
                    'Ensure adequate lighting in all areas where work is taking place.',
                    'Wear steel-toe-cap safety footwear at all times on site.',
                    'Report and isolate any spills or wet surfaces immediately.',
                ],
                'include_when'    => 'always',
            ],

            // ── 5. Noise and vibration (tier 2 — signal:drilling_or_percussive)
            [
                'name'            => 'Noise and vibration',
                'description'     => 'Exposure to noise and hand-arm vibration from drilling and power tools.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Use hearing protection when exposed to sustained drilling or power tool noise.',
                    'Limit noisy works to agreed times and inform occupants in advance.',
                    'Use low-vibration SDS tools where practicable and take regular breaks. Trigger time managed to keep daily HAV exposure below the EAV.',
                ],
                'include_when'    => 'signal:drilling_or_percussive',
            ],

            // ── 6. Occupied premises (tier 3 — confirm:occupied_premises) ─────
            [
                'name'            => 'Occupied premises',
                'description'     => 'Working in a live building with staff or public present.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Maintain clean, segregated work areas with clear signage and barriers.',
                    'Coordinate work windows to minimise disruption to occupants.',
                    'Protect client property and ensure confidentiality of visible data.',
                    'Tools, materials and part-installed equipment never left unattended in occupied areas. All areas made safe at the end of each working day.',
                ],
                'include_when'    => 'confirm:occupied_premises',
            ],

            // ── 7. Restricted access and ceiling voids (tier 2 — signal:ceiling_void_access)
            [
                'name'            => 'Restricted access and ceiling voids',
                'description'     => 'Restricted access into ceiling voids, comms rooms and enclosures.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Confirm ventilation and safe access before entering ceiling voids, comms rooms or enclosures. These are not classified as confined spaces, but access is restricted and is treated as a controlled activity.',
                    'Asbestos register reviewed and signed off before any void is opened.',
                    'Void visually inspected with a head torch before any hand or tool is introduced. Existing services identified and not disturbed.',
                    'No standing, kneeling or leaning on the suspended ceiling grid. All loads supported from the structural soffit or a purpose-designed ceiling mount kit only.',
                    'Do not obstruct escape routes; maintain clear access at all times.',
                    'Ensure a second person is aware of entry and available for assistance. Tiles removed one at a time, stacked flat and replaced the same working day.',
                ],
                'include_when'    => 'signal:ceiling_void_access',
            ],

            // ── 8. Cable pulling and termination (tier 2 — signal:first_fix_cabling)
            [
                'name'            => 'Cable pulling and termination',
                'description'     => 'Cable pulling, crimping and termination during first-fix cabling.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 2,
                'post_likelihood' => 1,
                'post_severity'   => 1,
                'controls'        => [
                    'Cable pulling carried out in pairs for runs over 15 m.',
                    'Cable lubricant used on long conduit pulls to reduce force.',
                    'Eye protection worn during cable termination and crimping.',
                    'Sharp cable ends covered immediately after cutting.',
                    'Work area kept clear of cable coils to prevent trip hazard.',
                ],
                'include_when'    => 'signal:first_fix_cabling',
            ],

            // ── 9. Low voltage AV connections (tier 1 — always) ───────────────
            [
                'name'            => 'Low voltage AV connections',
                'description'     => 'Connecting and disconnecting low-voltage AV cabling and equipment.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'All AV equipment powered down before connecting or disconnecting cables.',
                    'Visual inspection of cables and connectors before each use.',
                    'No work on mains-voltage circuits — all mains connections by qualified electrician.',
                    'PAT-tested power supplies and extension leads only.',
                    'Equipment earthing verified before power-on. PoE device power draw confirmed within the switch budget before connection.',
                ],
                'include_when'    => 'always',
            ],

            // ── 10. Fixings into walls, ceilings and pillars (tier 2 — signal:any_penetration)
            [
                'name'            => 'Fixings into walls, ceilings and pillars',
                'description'     => 'Drilling and fixing into walls, ceilings and pillars to mount equipment.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'Verify substrate type (plasterboard, masonry, concrete, steel) before drilling. Client written confirmation of wall reinforcement obtained where the substrate is gypsum.',
                    'Use correct anchor type and size for the substrate and load, with a minimum 4:1 safety factor over combined display and bracket weight.',
                    'Do not fix into unknown surfaces — confirm with site/building management. Where a mounting point is a cladding or non-structural finish, agree an alternative position with the client.',
                    'Check for hidden services (pipes, cables, reinforcement) with a calibrated detector in both scan modes before any penetration. Depth stops set on all drills.',
                    'Any penetration of a fire-rated element is stopped and referred to the client for a specified fire-stopping detail before proceeding.',
                    'Pull-test fixings to confirm load capacity before mounting equipment. Photograph every completed bracket for the handover file.',
                ],
                'include_when'    => 'signal:any_penetration',
            ],

            // ── 11. Dust from drilling and cutting (tier 2 — signal:any_drilling)
            [
                'name'            => 'Dust from drilling and cutting',
                'description'     => 'Dust and respirable silica generated by drilling and cutting.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'FFP3 dust mask and safety glasses worn during all drilling and cutting. All operatives face-fit tested.',
                    'Use on-tool dust extraction (M-class vacuum) or a drill dust collection shroud where practicable.',
                    'Lay dust sheets below work area to contain debris and protect furniture and floor finishes.',
                    'Vacuum work area immediately after drilling — dry sweeping is prohibited.',
                    'Inform building occupants of dust-generating works in advance and exclude them from the immediate area.',
                ],
                'include_when'    => 'signal:any_drilling',
            ],

            // ── 12. Asbestos-containing materials (tier 3 — confirm:asbestos) ──
            [
                'name'            => 'Asbestos-containing materials',
                'description'     => 'Risk of disturbing asbestos-containing materials during penetrative work or void access.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 5,
                'controls'        => [
                    'No penetrative work, ceiling void access or removal of building fabric takes place until the client asbestos register and management survey have been provided, reviewed and signed off by the lead engineer.',
                    'Where the register does not cover a specific location, a refurbishment and demolition survey must be provided by the client before work in that location proceeds.',
                    'All operatives hold current UKATA or IATP Asbestos Awareness (Category A) training.',
                    'If suspected ACM is encountered or disturbed: STOP work, withdraw all persons, prevent access, do not clean up, and report immediately to client facilities and the Project Manager.',
                    '21CAV will not remove, drill, cut, sand or otherwise disturb any material suspected of containing asbestos under any circumstances.',
                ],
                'include_when'    => 'confirm:asbestos',
            ],

            // ── 13. Vehicle and plant movement (tier 3 — confirm:vehicle_plant)
            [
                'name'            => 'Vehicle and plant movement',
                'description'     => 'Vehicle and mobile plant movement in warehouses, workshops, yards and loading bays.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'Site induction covering traffic routes, segregation and speed limits completed by every operative before entering the area.',
                    'Class 2 high-visibility clothing mandatory throughout the area.',
                    'A safe working window and exclusion arrangement agreed in advance with the area supervisor.',
                    'Work area physically segregated with barriers, cones and signage for the full duration, including the access equipment footprint.',
                    'Designated pedestrian walkways used at all times. No work under, adjacent to or within the operating radius of moving plant.',
                    'FLT operations suspended in the immediate work area or physically separated by barriers. Eye contact obtained with any plant operator before moving within their working area.',
                ],
                'include_when'    => 'confirm:vehicle_plant',
            ],

            // ── 14. Lone and small-team working (tier 3 — confirm:lone_working)
            [
                'name'            => 'Lone and small-team working',
                'description'     => 'Working alone or in a small team with limited on-site support.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'No operative works at height, in a ceiling void or on any electrical task alone. A buddy is present for all such tasks.',
                    'Daily check-in and check-out with the Project Manager at the start and end of each shift.',
                    'Site security and reception advised of the engineers present on site each day.',
                    'Mobile phone signal confirmed in each work area; emergency contact numbers carried by every operative.',
                ],
                'include_when'    => 'confirm:lone_working',
            ],

            // ── 15. Fire and evacuation (tier 1 — always) ──────────────────────
            [
                'name'            => 'Fire and evacuation',
                'description'     => 'Fire risk and disruption to evacuation routes during the works.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'Fire alarm procedure, escape routes, assembly point and site-specific evacuation arrangements covered at induction and briefed to every operative.',
                    'Escape routes, fire exits and fire equipment never obstructed by cable, tools, equipment or access platforms.',
                    'Fire doors never wedged or held open. Cable never routed through a fire door.',
                    'Any penetration of a fire-rated element fire-stopped to the original compartment rating using a client-specified system, or referred to the client before proceeding.',
                    'No hot works of any kind included in this scope.',
                ],
                'include_when'    => 'always',
            ],

            // ── 16. COSHH substances (tier 1 — always) ─────────────────────────
            [
                'name'            => 'COSHH substances',
                'description'     => 'Use and storage of hazardous substances such as adhesives, cleaners and sealants.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 2,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'COSHH assessments and safety data sheets carried in the vehicle documentation file and available on request.',
                    'Minimum quantities carried. Products used only for their intended purpose and in accordance with the SDS.',
                    'Adequate ventilation maintained; aerosols never used in confined or unventilated spaces.',
                    'Nitrile gloves and eye protection worn when decanting or applying. No decanting into unlabelled containers.',
                    'Products stored upright in a secure van locker away from heat sources. A spill kit is carried in the vehicle.',
                ],
                'include_when'    => 'always',
            ],

            // ── 17. Occupational road risk (tier 3 — confirm:road_risk) ────────
            [
                'name'            => 'Occupational road risk',
                'description'     => 'Road risk from travel to and between sites.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'Journeys planned in advance with realistic travel times; departure times set to avoid excessive combined driving and working hours.',
                    'Regular rest breaks taken; a minimum 15-minute break every two hours of driving.',
                    'Combined driving and working time managed so no operative exceeds a safe daily duty period. Overnight accommodation arranged where travel plus shift length would exceed safe limits.',
                    'Vehicles serviced, taxed, insured, MOT\'d and subject to weekly driver checks.',
                    'Loads secured and weight distributed correctly; displays transported on edge in purpose-made restraints.',
                    'No handheld mobile phone use while driving.',
                ],
                'include_when'    => 'confirm:road_risk',
            ],

            // ── 18. Decommissioning and WEEE (tier 2 — signal:strip_out_or_decommission)
            [
                'name'            => 'Decommissioning and WEEE',
                'description'     => 'Removal, storage and disposal of decommissioned AV equipment.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Confirm in writing with the client who is responsible for waste removal before mobilisation.',
                    'Decommissioned equipment retained for reuse is logged by serial number, protected and stored in a secure area agreed with the client. It is not waste and is not to be disposed of.',
                    'Equipment for reuse stored flat or on an A-frame, never leaned against walls or stacked.',
                    'Any waste removed by 21CAV is transferred under a valid waste transfer note to a licensed carrier; WEEE items routed to an approved treatment facility.',
                    'Batteries and power supplies segregated from general waste. Packaging flattened and recycled.',
                ],
                'include_when'    => 'signal:strip_out_or_decommission',
            ],
        ];
    }
}

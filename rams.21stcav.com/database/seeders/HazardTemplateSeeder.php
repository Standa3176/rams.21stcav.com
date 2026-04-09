<?php

namespace Database\Seeders;

use App\Models\HazardTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the global hazard template library with standard UK AV installation hazards.
 *
 * Run with:
 *   php artisan db:seed --class=HazardTemplateSeeder
 *
 * Safe to run multiple times — skips any template whose name already exists globally.
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

        $this->command->info('HazardTemplateSeeder: ' . count($hazards) . ' standard hazards seeded.');
    }

    // ── Standard UK AV installation hazard library ────────────────────────────

    private function standardHazards(): array
    {
        return [
            // ── 1. Manual Handling ────────────────────────────────────────────
            [
                'name'            => 'Manual Handling',
                'description'     => 'Moving, lifting, and positioning AV equipment, screens, and racks.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Use mechanical aids (sack trucks, lifting trolleys) for items over 20 kg.',
                    'Team lift required for screens and equipment over 40" — minimum two persons.',
                    'Pre-plan the route and clear all access paths before moving equipment.',
                    'Wear appropriate gloves and safety footwear at all times.',
                    'Conduct a task-specific manual handling assessment prior to lifting.',
                    'Take regular breaks to avoid fatigue during prolonged lifting tasks.',
                ],
            ],

            // ── 2. Slips, Trips & Falls (Same Level) ──────────────────────────
            [
                'name'            => 'Slips, Trips & Falls (Same Level)',
                'description'     => 'Trips and slips from cables, tools, and local floor hazards.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Keep all work areas clean and tidy — return tools to bags after each task.',
                    'Secure trailing cables immediately with cable covers or gaffer tape.',
                    'Erect hazard warning signs and barriers around active work zones.',
                    'Ensure adequate lighting in all areas where work is taking place.',
                    'Wear steel-toe-cap safety footwear at all times on site.',
                    'Report and isolate any spills or wet surfaces immediately.',
                ],
            ],

            // ── 3. Working at Height ──────────────────────────────────────────
            [
                'name'            => 'Working at Height',
                'description'     => 'Use of ladders, steps, or access equipment for overhead AV works.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'Select appropriate access equipment: podium steps, kick stools, or access tower.',
                    'Inspect all access equipment before each use — do not use damaged items.',
                    'Set up access equipment on stable, level ground only.',
                    'Maintain three points of contact when ascending or descending ladders.',
                    'Erect barriers and warning signs below any overhead work.',
                    'Wear a hard hat within the overhead works exclusion zone.',
                    'Never over-reach — reposition access equipment as required.',
                    'Do not use chairs, boxes or improvised platforms as working platforms.',
                ],
            ],

            // ── 4. Electrical Hazards ─────────────────────────────────────────
            [
                'name'            => 'Electrical Hazards',
                'description'     => 'Electric shock or burns when working on power or AV connections.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'All electrical work to be carried out by competent, authorised persons only.',
                    'Isolate and lock off power circuits before making any electrical connections.',
                    'Test for dead using an approved voltage indicator before touching any conductors.',
                    'Do not use damaged cables, plugs or extension leads — remove from service immediately.',
                    'All temporary power supplies to use RCD protection.',
                    'Comply with BS 7671 (IET Wiring Regulations) 18th Edition at all times.',
                    'Notify the client facilities team before isolating any shared power circuits.',
                ],
            ],

            // ── 5. Struck by Falling Objects ──────────────────────────────────
            [
                'name'            => 'Struck by Falling Objects',
                'description'     => 'Dropped tools or equipment during overhead works.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'Use tool lanyards for all hand tools when working at height.',
                    'Establish a clearly marked exclusion zone below all overhead work.',
                    'Verify load-bearing capacity of all fixings before mounting AV equipment.',
                    'Hard hats to be worn by all persons within the exclusion zone.',
                    'Use safety straps on displays during installation until fully secured.',
                    'Never leave partially fixed equipment unattended during installation.',
                ],
            ],

            // ── 6. Dust & Debris (Including Drilling) ─────────────────────────
            [
                'name'            => 'Dust & Debris (Including Drilling)',
                'description'     => 'Dust from drilling and cutting in occupied areas.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Check the asbestos register or obtain an asbestos survey before any drilling.',
                    'Use dust extraction equipment when drilling into walls, floors or ceilings.',
                    'Wear FFP2 or FFP3 dust masks during all drilling and cutting operations.',
                    'Wear safety glasses/goggles during drilling and cutting.',
                    'Seal off the work area from occupied spaces using temporary screens or sheeting.',
                    'Dispose of all waste and debris in accordance with site waste procedures.',
                ],
            ],

            // ── 7. Hidden Services (Electrical, Plumbing, Gas) ────────────────
            [
                'name'            => 'Hidden Services (Electrical, Plumbing, Gas)',
                'description'     => 'Risk of striking hidden services during drilling and fixing.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 5,
                'post_likelihood' => 1,
                'post_severity'   => 4,
                'controls'        => [
                    'Use a cable and pipe detector (CAT & Genny) before every drilling operation.',
                    'Obtain up-to-date services drawings from the client facilities team prior to works.',
                    'Mark all detected services clearly before any drilling commences.',
                    'If uncertain, do not drill — seek written confirmation from the client.',
                    'Ensure first aid and emergency contact numbers are known before commencing.',
                ],
            ],

            // ── 8. Sharps & Hand / Power Tools ────────────────────────────────
            [
                'name'            => 'Sharps & Hand / Power Tools',
                'description'     => 'Cuts or abrasions from tools, cable ends, or sharp materials.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 2,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Wear cut-resistant gloves when handling raw cable ends and sheet metal.',
                    'Inspect all hand tools before use — do not use damaged or worn tools.',
                    'Power tools must be PAT tested and inspection tags in date.',
                    'Keep blade guards fitted on all cutting tools when not in active use.',
                    'First aid kit to be on site and its location communicated to all operatives.',
                    'Report and record all cuts, lacerations and near-misses.',
                ],
            ],

            // ── 9. Lone Working ──────────────────────────────────────────────
            [
                'name'            => 'Lone Working',
                'description'     => 'Working without direct supervision or support.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'controls'        => [
                    'Lone working must be pre-approved by the 21CAV project manager.',
                    'Lone worker to check in with a nominated contact at start and end of every session.',
                    'Use a lone worker app or buddy check-in system for isolated work areas.',
                    'Mobile phone must be fully charged with network coverage confirmed.',
                    'Emergency procedures to be communicated before commencing lone work.',
                    'No lone working at height or with high-risk power tools.',
                ],
            ],

            // ── 10. Display Installation / Wall Mounting ─────────────────────
            [
                'name'            => 'Display Installation / Wall Mounting',
                'description'     => 'Risk of display or bracket failure during and after mounting.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'Survey wall substrate before mounting — confirm masonry, stud, or structural steel and select appropriate fixings.',
                    'Use fixing load ratings appropriate to the combined weight of bracket and display.',
                    'Two-person lift required for all displays above 40" — use suction lifting aids where available.',
                    'Retain safety straps or anti-tilt cables on all wall-mounted displays until bracket is fully torqued.',
                    'Torque all fixings to bracket manufacturer guidance using a calibrated torque wrench.',
                    'Conduct a pull-test on the installed bracket before hanging the display.',
                    'Record fixing type, depth, and torque values in the commissioning documentation.',
                ],
            ],

            // ── 11. Fixings / Substrate Failure ──────────────────────────────
            [
                'name'            => 'Fixings / Substrate Failure',
                'description'     => 'Inadequate fixings or unsuitable substrate causing mounted equipment to fall.',
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'post_likelihood' => 1,
                'post_severity'   => 3,
                'controls'        => [
                    'Obtain building drawings or structural information from the client before drilling.',
                    'Use a materials detector to identify hollow stud, solid masonry, or metal behind plasterboard.',
                    'Never fix into plasterboard or lightweight partition alone — locate studs or use appropriate hollow-wall fixings rated to the load.',
                    'Where substrate suitability cannot be confirmed, refer to structural engineer guidance before proceeding.',
                    'Use resin anchors for fixings into aerated concrete (e.g. Thermalite) — never expansion bolts.',
                    'Label all fixing locations in as-built documentation.',
                ],
            ],

            // ── 12. Cable Installation in Ceiling Voids ───────────────────────
            [
                'name'            => 'Cable Installation in Ceiling Voids',
                'description'     => 'Hazards from restricted access, dust, fibres, and hidden services in ceiling voids.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Check the asbestos register before removing any ceiling tiles or making any penetrations.',
                    'Wear an FFP2 or FFP3 mask and safety glasses when working in ceiling voids.',
                    'Use a head torch and ensure adequate lighting before entering any void.',
                    'Do not apply body weight to ceiling tile frames or suspended ceiling systems.',
                    'Use a cable and pipe detector before drilling or cutting any penetration.',
                    'Fire-stop all cable penetrations through fire compartment walls and floors immediately after cabling.',
                    'Brief the client facilities team before opening any ceiling void adjacent to occupied areas.',
                ],
            ],

            // ── 13. Interaction with Other Trades ────────────────────────────
            [
                'name'            => 'Interaction with Other Trades',
                'description'     => 'Conflicts, strikes, or interference with other contractors working in the same area.',
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 2,
                'post_severity'   => 2,
                'controls'        => [
                    'Attend site co-ordination meetings and agree sequencing of works with the principal contractor.',
                    'Establish clear zone delineation for AV works — erect barriers to prevent uninvited access to active areas.',
                    'Brief all operatives on which other trades are on site and the areas they occupy.',
                    'Do not re-route or interfere with cabling or equipment installed by other trades without written authority.',
                    'Report any damage to other trades\' work to the principal contractor or site manager immediately.',
                    'Ensure overhead exclusion zones are communicated to all trades working below.',
                ],
            ],
        ];
    }
}

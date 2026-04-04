<?php

namespace App\Services;

/**
 * Provides a static hazard library for AV installation projects.
 *
 * All hazards are generated locally — no AI required. The activities
 * detected by EquipmentClassifierService are used to adjust likelihood
 * scores for activity-specific risks (e.g. working at height is lower
 * when no ceiling works are detected).
 *
 * Risk score = Likelihood (1–5) × Severity (1–5)
 * Colour bands:
 *   Low      ≤ 3   (green)
 *   Medium   4–6   (amber)
 *   High     7–12  (orange)
 *   Critical ≥ 13  (red)
 */
class RiskMatrixService
{
    // ── Risk colour hex values (matching DocxBuilderService palette) ──────────
    public const RISK_GREEN  = 'D4EDDA';
    public const RISK_AMBER  = 'FFF3CD';
    public const RISK_ORANGE = 'FFD0A0';
    public const RISK_RED    = 'FFDEDE';

    // ── Full hazard library ───────────────────────────────────────────────────

    private const HAZARD_LIBRARY = [
        [
            'id'              => 1,
            'hazard'          => 'Manual Handling',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'controls'        => [
                'Use mechanical aids (sack trucks, lifting trolleys) for items over 20 kg.',
                'Team lift required for screens and equipment over 40" — minimum two persons.',
                'Pre-plan the route and clear all access paths before moving equipment.',
                'Wear appropriate gloves and safety footwear at all times.',
                'Conduct a task-specific manual handling assessment prior to lifting.',
                'Take regular breaks to avoid fatigue during prolonged lifting tasks.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
            'activity_flag'   => null,   // always included
        ],
        [
            'id'              => 2,
            'hazard'          => 'Slips, Trips & Falls (Same Level)',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Members of Public'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'controls'        => [
                'Keep all work areas clean and tidy — return tools to bags after each task.',
                'Secure trailing cables immediately with cable covers or gaffer tape.',
                'Erect hazard warning signs and barriers around active work zones.',
                'Ensure adequate lighting in all areas where work is taking place.',
                'Wear steel-toe-cap safety footwear at all times on site.',
                'Report and isolate any spills or wet surfaces immediately.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
            'activity_flag'   => null,
        ],
        [
            'id'              => 3,
            'hazard'          => 'Working at Height',
            'persons_at_risk' => ['21CAV Staff', 'Other Contractors'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 4,
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
            'post_likelihood' => 1,
            'post_severity'   => 3,
            'activity_flag'   => 'ceiling_or_display',  // adjusted if no overhead work
        ],
        [
            'id'              => 4,
            'hazard'          => 'Electrical Hazards',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 5,
            'controls'        => [
                'All electrical work to be carried out by competent, authorised persons only.',
                'Isolate and lock off power circuits before making any electrical connections.',
                'Test for dead using an approved voltage indicator before touching any conductors.',
                'Do not use damaged cables, plugs or extension leads — remove from service immediately.',
                'All temporary power supplies to use RCD protection.',
                'Comply with BS 7671 (IET Wiring Regulations) 18th Edition at all times.',
                'Notify the client facilities team before isolating any shared power circuits.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 4,
            'activity_flag'   => null,
        ],
        [
            'id'              => 5,
            'hazard'          => 'Struck by Falling Objects',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Members of Public'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 4,
            'controls'        => [
                'Use tool lanyards for all hand tools when working at height.',
                'Establish a clearly marked exclusion zone below all overhead work.',
                'Verify load-bearing capacity of all fixings before mounting AV equipment.',
                'Hard hats to be worn by all persons within the exclusion zone.',
                'Use safety straps on displays during installation until fully secured.',
                'Never leave partially fixed equipment unattended during installation.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 3,
            'activity_flag'   => 'ceiling_or_display',
        ],
        [
            'id'              => 6,
            'hazard'          => 'Dust & Debris (Including Drilling)',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 3,
            'controls'        => [
                'Check the asbestos register or obtain an asbestos survey before any drilling.',
                'Use dust extraction equipment when drilling into walls, floors or ceilings.',
                'Wear FFP2 or FFP3 dust masks during all drilling and cutting operations.',
                'Wear safety glasses/goggles during drilling and cutting.',
                'Seal off the work area from occupied spaces using temporary screens or sheeting.',
                'Dispose of all waste and debris in accordance with site waste procedures.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 2,
            'activity_flag'   => 'drilling',   // elevated if drilling detected
        ],
        [
            'id'              => 7,
            'hazard'          => 'Hidden Services (Electrical, Plumbing, Gas)',
            'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Building Occupants'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 5,
            'controls'        => [
                'Use a cable and pipe detector (CAT & Genny) before every drilling operation.',
                'Obtain up-to-date services drawings from the client facilities team prior to works.',
                'Mark all detected services clearly before any drilling commences.',
                'If uncertain, do not drill — seek written confirmation from the client.',
                'Ensure first aid and emergency contact numbers are known before commencing.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 4,
            'activity_flag'   => 'drilling',
        ],
        [
            'id'              => 8,
            'hazard'          => 'Sharps & Hand / Power Tools',
            'persons_at_risk' => ['21CAV Staff'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 2,
            'controls'        => [
                'Wear cut-resistant gloves when handling raw cable ends and sheet metal.',
                'Inspect all hand tools before use — do not use damaged or worn tools.',
                'Power tools must be PAT tested and inspection tags in date.',
                'Keep blade guards fitted on all cutting tools when not in active use.',
                'First aid kit to be on site and its location communicated to all operatives.',
                'Report and record all cuts, lacerations and near-misses.',
            ],
            'post_likelihood' => 2,
            'post_severity'   => 2,
            'activity_flag'   => null,
        ],
        [
            'id'              => 9,
            'hazard'          => 'Lone Working',
            'persons_at_risk' => ['21CAV Staff'],
            'pre_likelihood'  => 2,
            'pre_severity'    => 3,
            'controls'        => [
                'Lone working must be pre-approved by the 21CAV project manager.',
                'Lone worker to check in with a nominated contact at start and end of every session.',
                'Use a lone worker app or buddy check-in system for isolated work areas.',
                'Mobile phone must be fully charged with network coverage confirmed.',
                'Emergency procedures to be communicated before commencing lone work.',
                'No lone working at height or with high-risk power tools.',
            ],
            'post_likelihood' => 1,
            'post_severity'   => 2,
            'activity_flag'   => null,
        ],
    ];

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Return all hazards from the library, with likelihood adjusted based
     * on the activities and flags detected by EquipmentClassifierService.
     *
     * @param  string[]  $activities        Activity keys (e.g. 'ceiling_works', 'display_installation')
     * @param  bool      $drillingRequired  Whether drilling/fixing operations are needed
     * @return array     Hazard rows ready for use in RamsBuilderService / WordDocumentService
     */
    public function getHazards(array $activities = [], bool $drillingRequired = false): array
    {
        $hasCeilingOrDisplay = ! empty(array_intersect($activities, ['ceiling_works', 'display_installation']));

        $hazards = [];

        foreach (self::HAZARD_LIBRARY as $h) {
            $hazard = $h;

            // Adjust working-at-height and struck-by when no overhead work detected
            if ($hazard['activity_flag'] === 'ceiling_or_display' && ! $hasCeilingOrDisplay) {
                $hazard['pre_likelihood']  = 1;
                $hazard['post_likelihood'] = 1;
            }

            // Reduce dust/hidden-services risk when no drilling detected
            if ($hazard['activity_flag'] === 'drilling' && ! $drillingRequired) {
                $hazard['pre_likelihood']  = 1;
                $hazard['post_likelihood'] = 1;
            }

            // Remove the internal flag key before passing downstream
            unset($hazard['activity_flag']);

            $hazards[] = $hazard;
        }

        return $hazards;
    }

    /**
     * Return the background hex colour for a given risk score.
     */
    public function riskColour(int $score): string
    {
        return match (true) {
            $score <= 3  => self::RISK_GREEN,
            $score <= 6  => self::RISK_AMBER,
            $score <= 12 => self::RISK_ORANGE,
            default      => self::RISK_RED,
        };
    }

    /**
     * Return the human-readable risk band label for a given score.
     */
    public function riskLabel(int $score): string
    {
        return match (true) {
            $score <= 3  => 'Low',
            $score <= 6  => 'Medium',
            $score <= 12 => 'High',
            default      => 'Critical',
        };
    }
}

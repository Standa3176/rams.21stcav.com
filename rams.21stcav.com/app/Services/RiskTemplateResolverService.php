<?php

namespace App\Services;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
use App\Models\HazardTemplate;
use App\Services\Rams\HazardIncludeWhenResolver;
use Illuminate\Support\Collection;

/**
 * Resolves the full risk template for an AV installation project.
 *
 * Input:  activities array + drilling flag from EquipmentClassifierService.
 * Output: [
 *   'hazards'          => array,   // 9-hazard library with adjusted likelihoods
 *   'ppe'              => array,   // required PPE items (base list, no form data merged)
 *   'access_equipment' => array,   // required access equipment types
 * ]
 *
 * All output is generated locally — no AI required.
 * Form-supplied PPE overrides are merged downstream in RamsDataBuilderService.
 */
class RiskTemplateResolverService
{
    public function __construct(
        private readonly HazardLibraryService $hazardLibrary,
        private readonly HazardIncludeWhenResolver $includeWhenResolver,
    ) {}

    // ── PPE ───────────────────────────────────────────────────────────────────

    private const PPE_BASE = [
        'Safety Boots (steel toe cap)',
        'Hi-Visibility Vest',
        'Safety Glasses',
        'Latex / Nitrile Gloves',
    ];

    /**
     * Activity keys that add extra PPE items.
     * Multi-activity intersections handled by looping all matching entries.
     */
    private const PPE_ACTIVITY_MAP = [
        'ceiling_works'        => ['Hard Hat', 'Dust Mask (FFP2)'],
        'display_installation' => ['Hard Hat'],
        'audio_installation'   => ['Hearing Protection'],
    ];

    // ── Access equipment ──────────────────────────────────────────────────────

    /**
     * Activity keys that require specific access equipment on site.
     * More-specific entries (ceiling_works) are listed first.
     */
    private const ACCESS_EQUIPMENT_MAP = [
        'ceiling_works'        => ['Podium Steps', 'Access Tower (if above 3 m)'],
        'display_installation' => ['Podium Steps', 'Kick Stool'],
        'av_rack'              => ['Kick Stool'],
    ];

    private const ACCESS_EQUIPMENT_DEFAULT = ['Kick Stool'];

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Resolve hazards, PPE and access equipment for the detected activities.
     *
     * @param  string[]  $activities        Activity keys from EquipmentClassifierService
     * @param  bool      $drillingRequired  Whether drilling / fixing is needed
     * @return array     { hazards: array, ppe: array, access_equipment: array }
     */
    public function resolve(
        array $activities,
        bool $drillingRequired = false,
        ?int $userId = null,
        array $hazardNames = [],
        array $personsAtRisk = [],
        string $scopeNarrative = '',
    ): array
    {
        return [
            'hazards'          => $this->buildHazards($userId, $hazardNames, $personsAtRisk, $activities, $drillingRequired, $scopeNarrative),
            'ppe'              => $this->buildPpe($activities, $drillingRequired),
            'access_equipment' => $this->buildAccessEquipment($activities),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function buildPpe(array $activities, bool $drillingRequired): array
    {
        $ppe = self::PPE_BASE;

        foreach (self::PPE_ACTIVITY_MAP as $activity => $items) {
            if (in_array($activity, $activities, true)) {
                $ppe = array_merge($ppe, $items);
            }
        }

        // Drilling without ceiling_works still requires a dust mask.
        if ($drillingRequired && ! in_array('ceiling_works', $activities, true)) {
            $ppe[] = 'Dust Mask (FFP2)';
        }

        return array_values(array_unique($ppe));
    }

    private function buildAccessEquipment(array $activities): array
    {
        $equipment = [];

        foreach (self::ACCESS_EQUIPMENT_MAP as $activity => $items) {
            if (in_array($activity, $activities, true)) {
                $equipment = array_merge($equipment, $items);
            }
        }

        if (empty($equipment)) {
            return self::ACCESS_EQUIPMENT_DEFAULT;
        }

        return array_values(array_unique($equipment));
    }

    // ── Hazards ──────────────────────────────────────────────────────────────

    /**
     * Build hazard rows from the Hazard Library.
     *
     * Explicit hazard names (manual RAMS form / regenerate) are always
     * resolved and included. When hazard tiering is enabled
     * (config('rams_tier1.hazard_tiering_enabled')), the tiered
     * HazardIncludeWhenResolver additionally evaluates the full visible
     * library against the job's captured signals (activities, drilling
     * flag, scope narrative) and merges its matches in — deduplicated
     * against the explicit picks. When tiering is disabled, only the
     * explicit picks are returned: zero auto-population, and the old fixed
     * baseline is never resurrected (the reversibility guarantee).
     *
     * @param  int|null  $userId
     * @param  string[]  $hazardNames
     * @param  string[]  $personsAtRisk
     * @param  string[]  $activities
     * @return array
     */
    private function buildHazards(
        ?int $userId,
        array $hazardNames,
        array $personsAtRisk,
        array $activities = [],
        bool $drillingRequired = false,
        string $scopeNarrative = '',
    ): array {
        $userId = $userId ?? 0;

        $names = array_values(array_filter(
            array_map('strval', $hazardNames),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        $resolved = $this->resolveHazards($userId, $names, $activities, $drillingRequired, $scopeNarrative);

        $people = array_values(array_unique(array_filter(
            array_map('strval', $personsAtRisk),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        )));

        if (empty($people)) {
            $people = ['21CAV Staff'];
        }

        $rows = [];
        $i = 1;
        foreach ($resolved as $h) {
            $rows[] = [
                'id'                 => $i++,
                'hazard'             => (string) ($h->name ?? ''),
                'persons_at_risk'    => $people,
                'pre_likelihood'     => (int) ($h->pre_likelihood  ?? 3),
                'pre_severity'       => (int) ($h->pre_severity    ?? 3),
                'controls'           => array_values(array_filter(
                    array_map('strval', (array) ($h->controls ?? [])),
                    static fn (string $s): bool => strlen(trim($s)) > 0,
                )),
                'post_likelihood'    => (int) ($h->post_likelihood ?? 1),
                'post_severity'      => (int) ($h->post_severity   ?? 2),
                'needs_confirmation' => (bool) ($h->needs_confirmation ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Resolve hazards: explicit picks (always resolved via the library's
     * fuzzy-match) merged with tiered include-when matches when tiering is
     * enabled. Explicit picks always win — a tiered match never replaces or
     * drops one.
     *
     * @return Collection
     */
    private function resolveHazards(int $userId, array $names, array $activities, bool $drillingRequired, string $scopeNarrative): Collection
    {
        $explicit = $this->hazardLibrary->resolveFromSeeds($userId, $names);

        if (! config('rams_tier1.hazard_tiering_enabled', true)) {
            return $explicit;
        }

        $tiered = $this->fetchTieredCandidates($userId, $activities, $drillingRequired, $scopeNarrative);

        foreach ($tiered as $match) {
            $alreadyPresent = $match->id !== null
                ? $explicit->contains('id', $match->id)
                : $explicit->contains(fn ($e) => strtolower((string) ($e->name ?? '')) === strtolower((string) $match->name));

            if (! $alreadyPresent) {
                $explicit->push($match);
            }
        }

        return $explicit;
    }

    /**
     * Fetch the full visible hazard library and evaluate it against the
     * job's captured signals via HazardIncludeWhenResolver. Extracted from
     * resolveHazards() so BOTH callers of tier evaluation — resolveHazards()
     * itself and the public tieredRowsNotAlreadyPresent() below — go through
     * the exact same evaluation call. Tier logic can never diverge between
     * the two entry points.
     *
     * @return Collection<int, HazardTemplate>
     */
    private function fetchTieredCandidates(int $userId, array $activities, bool $drillingRequired, string $scopeNarrative): Collection
    {
        $library = HazardTemplate::visibleTo($userId)->get();

        return $this->includeWhenResolver->resolve($library, [
            'activities'        => $activities,
            'drilling_required' => $drillingRequired,
            'scope_narrative'   => $scopeNarrative,
        ]);
    }

    /**
     * Reusable tier-1/3 fetch-and-dedup entry point for callers that already
     * hold a fully-formed hazard register (not just a list of names to
     * resolve) — specifically RamsBuilderService::reviewedToRisk(), which
     * merges tier-1 (always) and tier-3 (confirm) hazard candidates onto an
     * already-built register of reviewed engineer picks, without
     * re-resolving those picks through resolveFromSeeds() a second time.
     *
     * Honours the same rams_tier1.hazard_tiering_enabled reversibility
     * guarantee as resolveHazards(): when tiering is disabled this returns
     * an empty array unconditionally, never resurrecting the old fixed
     * baseline.
     *
     * @param  string[]  $existingHazardNames  Names already present in the caller's register.
     *   Dedup is case-insensitive and whitespace-trimmed (the sole comparison
     *   rule — plain strings never carry a hazard_templates.id to compare).
     * @param  string[]  $activities
     * @return array  Hazard rows in the same shape buildHazards() emits, minus
     *   'id' (the caller owns id sequencing for its merged register) and
     *   with score_reviewed forced false (an injected candidate has never
     *   been human-reviewed).
     */
    public function tieredRowsNotAlreadyPresent(
        array $existingHazardNames,
        array $activities = [],
        bool $drillingRequired = false,
        string $scopeNarrative = '',
        int $userId = 0,
    ): array {
        if (! config('rams_tier1.hazard_tiering_enabled', true)) {
            return [];
        }

        $candidates = $this->fetchTieredCandidates($userId, $activities, $drillingRequired, $scopeNarrative);

        $existingNormalised = array_map(
            static fn (string $n): string => strtolower(trim($n)),
            $existingHazardNames,
        );

        $rows = [];
        foreach ($candidates as $match) {
            $normalisedName = strtolower(trim((string) ($match->name ?? '')));

            if (in_array($normalisedName, $existingNormalised, true)) {
                continue;
            }

            $rows[] = [
                'hazard'             => (string) ($match->name ?? ''),
                'persons_at_risk'    => ['21CAV Staff', 'Client Staff', 'Others'],
                'pre_likelihood'     => (int) ($match->pre_likelihood  ?? 3),
                'pre_severity'       => (int) ($match->pre_severity    ?? 3),
                'controls'           => array_values(array_filter(
                    array_map('strval', (array) ($match->controls ?? [])),
                    static fn (string $s): bool => strlen(trim($s)) > 0,
                )),
                'post_likelihood'    => (int) ($match->post_likelihood ?? 1),
                'post_severity'      => (int) ($match->post_severity   ?? 2),
                'score_reviewed'     => false,
                'needs_confirmation' => (bool) ($match->needs_confirmation ?? false),
            ];
        }

        return $rows;
    }

    // =========================================================================
    // PROJECT CONTEXT ENTRY POINT
    // =========================================================================

    /**
     * Resolve hazards, PPE, and access equipment directly from a ProjectContext.
     *
     * This method is deterministic and does NOT use the HazardLibrary.
     * It is driven entirely by survey-captured risk fields, making it suitable
     * for the SiteSurvey → ProjectContext pipeline.
     *
     * INPUT:
     * {
     *   "rooms": [
     *     {
     *       "name":       string,
     *       "activities": string[],   // controlled vocabulary
     *       "risks": [{
     *         "working_height":      string,   // "over_2m" | "under_2m" | "ground_level" | ""
     *         "out_of_hours":        bool,
     *         "permits_required":    bool,
     *         "manual_handling_risk": bool,
     *       }]
     *     }
     *   ]
     * }
     *
     * OUTPUT:
     * {
     *   "hazards": [{
     *     "title":       string,
     *     "description": string,
     *     "rooms":       string[],   // room names where this hazard applies
     *   }],
     *   "ppe":              string[],   // deduplicated across all rooms
     *   "access_equipment": string[],   // deduplicated across all rooms
     * }
     *
     * Hazard rules (per room):
     *   working_height = "over_2m"   → "Working at height" + hard hat + ladder/tower/MEWP
     *   working_height = "under_2m"  → "Working at height (low level)" + hard hat
     *   cable_installation activity  → "Trip hazard from cables"
     *   manual_handling_risk = true  → "Manual handling injury" + back support
     *   out_of_hours = true          → "Out-of-hours working" + hi-vis
     *   permits_required = true      → "Permit-to-work required"
     *   Always                       → safety boots (baseline PPE)
     *
     * @param  array  $context  Output of ProjectContextBuilder::build()
     * @return array  { hazards: array, ppe: array, access_equipment: array }
     */
    public function resolveFromProjectContext(array $context): array
    {
        $rooms = (array) ($context['rooms'] ?? []);

        // Hazards indexed by title so duplicate titles across rooms merge their room lists
        $hazardMap      = [];
        $allPpe         = ['Safety Boots (steel toe cap)']; // always required
        $allAccessEquip = [];

        foreach ($rooms as $room) {
            $roomName    = trim((string) ($room['name']       ?? 'Unknown room'));
            $activities  = (array) ($room['activities']       ?? []);
            $primaryRisk = (array) (($room['risks'] ?? [])[0] ?? []);

            $workingHeight   = strtolower(trim((string) ($primaryRisk['working_height']      ?? '')));
            $manualHandling  = (bool) ($primaryRisk['manual_handling_risk'] ?? false);
            $outOfHours      = (bool) ($primaryRisk['out_of_hours']         ?? false);
            $permitsRequired = (bool) ($primaryRisk['permits_required']     ?? false);

            // ── Working at height ─────────────────────────────────────────────

            if ($workingHeight === 'over_2m') {
                $this->mergeHazard($hazardMap, $roomName,
                    'Working at height',
                    'Work above 2 m. Risk of falls causing serious injury or death. ' .
                    'Use appropriate access equipment, maintain 3-point contact, ' .
                    'inspect equipment before use.'
                );
                $allPpe[]         = 'Hard Hat';
                $allAccessEquip[] = 'Podium Steps';
                $allAccessEquip[] = 'Access Tower (if above 3 m)';
                $allAccessEquip[] = 'MEWP (if required)';

            } elseif ($workingHeight === 'under_2m') {
                $this->mergeHazard($hazardMap, $roomName,
                    'Working at height (low level)',
                    'Work below 2 m. Risk of minor falls. ' .
                    'Use appropriate low-level access equipment on a stable, level surface.'
                );
                $allPpe[] = 'Hard Hat';
            }

            // ── Cable installation → trip hazard ──────────────────────────────

            if (in_array('cable_installation', $activities, true)) {
                $this->mergeHazard($hazardMap, $roomName,
                    'Trip hazard from cables',
                    'Cable runs temporarily exposed during installation. ' .
                    'Use cable covers or warning signage; segregate work area where possible.'
                );
            }

            // ── Manual handling ───────────────────────────────────────────────

            if ($manualHandling) {
                $this->mergeHazard($hazardMap, $roomName,
                    'Manual handling injury',
                    'Equipment or materials require manual handling. Risk of musculoskeletal injury. ' .
                    'Assess load before lifting; use mechanical aids; team-lift for items over 20 kg.'
                );
                $allPpe[] = 'Back Support Belt';
            }

            // ── Out-of-hours working ──────────────────────────────────────────

            if ($outOfHours) {
                $this->mergeHazard($hazardMap, $roomName,
                    'Out-of-hours working',
                    'Works scheduled outside normal business hours. Risk of inadequate supervision ' .
                    'and reduced emergency response. Follow lone-worker procedure; confirm emergency ' .
                    'contacts before works commence.'
                );
                $allPpe[] = 'Hi-Visibility Vest';
            }

            // ── Permits required ──────────────────────────────────────────────

            if ($permitsRequired) {
                $this->mergeHazard($hazardMap, $roomName,
                    'Permit-to-work required',
                    'Site requires formal permits before works may begin. Obtain all permits from ' .
                    'site manager before entering the work area. Works must not commence without a ' .
                    'valid, signed permit.'
                );
            }

            // ═══════════════════════════════════════════════════════════════════
            // ENGINEER-FEEDBACK BRANCHES (quick task 260503-tfb)
            //
            // Surveys captured before quick task 260503-rgg landed have no
            // engineer_feedback block — Task 1 of this quick task guarantees
            // an empty array `[]` in that case, so this whole sub-section is
            // a strict no-op on legacy surveys.
            //
            // Hazard titles below MUST match existing global library template
            // names verbatim — no new HazardTemplate seeder entries.
            // ═══════════════════════════════════════════════════════════════════

            $ef = (array) ($room['engineer_feedback'] ?? []);
            if (empty($ef)) {
                continue; // legacy survey — no engineer data — skip new branches
            }

            // ── Working at height tier (HIGH > MEDIUM > LOW, mutually exclusive) ──
            // Resolves to the single existing 'Working at Height' template;
            // tier (LOW/MEDIUM/HIGH) is encoded in the hazard description text.
            $methods = array_map('strtolower', (array) ($ef['work_at_height_methods'] ?? []));
            $maxH    = $ef['max_mounting_height_m'] ?? null; // float|null

            $isHigh   = in_array('mewp', $methods, true)
                        || in_array('scaffold', $methods, true)
                        || ($maxH !== null && $maxH > 4.0);
            $isMedium = ! $isHigh && (
                            in_array('tower', $methods, true)
                            || ($maxH !== null && $maxH > 2.0)
                        );
            $isLow    = ! $isHigh && ! $isMedium && (
                            in_array('ladder', $methods, true)
                            || in_array('podium', $methods, true)
                            || ($maxH !== null && $maxH <= 2.0 && $maxH > 0)
                        );

            if ($isHigh) {
                $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
                    'HIGH-tier working at height: MEWP or scaffold required, OR mounting points above 4 m. ' .
                    'Use only trained operators for MEWP. Erect rescue plan; barrier exclusion zone below works. ' .
                    'Maintain three-point contact and harness where required.'
                );
                $allPpe[]         = 'Hard Hat';
                $allPpe[]         = 'Safety Harness';
                $allAccessEquip[] = 'MEWP (operator certified)';
                $allAccessEquip[] = 'Scaffold (if specified)';
            } elseif ($isMedium) {
                $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
                    'MEDIUM-tier working at height: access tower OR mounting points 2 m – 4 m. ' .
                    'Tower must be erected by competent person. Barrier zone below works.'
                );
                $allPpe[]         = 'Hard Hat';
                $allAccessEquip[] = 'Access Tower';
            } elseif ($isLow) {
                $this->mergeHazard($hazardMap, $roomName, 'Working at Height',
                    'LOW-tier working at height: ladder, podium, or mounting at or below 2 m. ' .
                    'Maintain three points of contact; do not over-reach.'
                );
                $allPpe[]         = 'Hard Hat';
                $allAccessEquip[] = 'Podium Steps';
            }
            // 'na' or no signal → no override (existing under_2m / over_2m logic
            // from primaryRisk above may still emit its own row).

            // ── Wall prep hazards (each independent) ───────────────────────────
            if (! empty($ef['wall_needs_chase_out'])) {
                $this->mergeHazard($hazardMap, $roomName, 'Dust & Debris (Including Drilling)',
                    'Wall chasing required for cable conduit. High dust generation. ' .
                    'Use FFP3 dust mask, on-tool extraction, and seal off occupied areas.'
                );
                $allPpe[] = 'Dust Mask (FFP3)';
            }
            if (! empty($ef['wall_needs_reinforcement'])) {
                $this->mergeHazard($hazardMap, $roomName, 'Fixings / Substrate Failure',
                    'Wall reinforcement required to safely carry mounted equipment load. ' .
                    'Confirm structural fixings rated for full bracket + display weight; CAT-and-Genny scan before drilling.'
                );
            }
            if (! empty($ef['wall_needs_conduit'])) {
                $this->mergeHazard($hazardMap, $roomName, 'Hidden Services (Electrical, Plumbing, Gas)',
                    'Conduit installation requires drilling through wall structure. ' .
                    'Mandatory CAT-and-Genny scan; obtain services drawings before any penetration.'
                );
            }

            // ── Long cable pulls (manual handling) ─────────────────────────────
            $cableRoutes = (array) ($ef['cable_routes'] ?? []);
            $hasLongPull = false;
            foreach ($cableRoutes as $cr) {
                $len = (float) (is_array($cr) ? ($cr['length_m'] ?? 0) : 0);
                if ($len > 30.0) {
                    $hasLongPull = true;
                    break;
                }
            }
            if ($hasLongPull) {
                $this->mergeHazard($hazardMap, $roomName, 'Manual Handling',
                    'Long cable pull (>30 m) creates manual handling risk. ' .
                    'Two-person team; use cable rollers / pulling lubricant; take rest breaks.'
                );
            }
        }

        return [
            'hazards'          => array_values($hazardMap),
            'ppe'              => array_values(array_unique($allPpe)),
            'access_equipment' => array_values(array_unique($allAccessEquip)),
        ];
    }

    // ── Private — hazard map helper ───────────────────────────────────────────

    /**
     * Add or merge a hazard entry into the indexed hazard map.
     * If the title already exists, the room name is appended to its rooms list.
     *
     * @param  array   &$map       Reference to the hazardMap accumulator.
     * @param  string  $roomName   Room this hazard originates from.
     * @param  string  $title      Short hazard title (used as map key).
     * @param  string  $description  Control measures / mitigation text.
     */
    private function mergeHazard(array &$map, string $roomName, string $title, string $description): void
    {
        if (! isset($map[$title])) {
            $map[$title] = [
                'title'       => $title,
                'description' => $description,
                'rooms'       => [$roomName],
            ];
        } else {
            if (! in_array($roomName, $map[$title]['rooms'], true)) {
                $map[$title]['rooms'][] = $roomName;
            }
        }
    }
}

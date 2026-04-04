<?php

namespace App\Services;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
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
    ): array
    {
        return [
            'hazards'          => $this->buildHazards($userId, $hazardNames, $personsAtRisk),
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
     * If explicit hazard names are provided (manual RAMS form), resolve ONLY those
     * names and keep the output aligned with template controls.
     *
     * If no names are provided (auto flow), fall back to the mandatory baseline.
     *
     * @param  int|null  $userId
     * @param  string[]  $hazardNames
     * @param  string[]  $personsAtRisk
     * @return array
     */
    private function buildHazards(?int $userId, array $hazardNames, array $personsAtRisk): array
    {
        $userId = $userId ?? 0;

        $names = array_values(array_filter(
            array_map('strval', $hazardNames),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        $resolved = $this->resolveHazards($userId, $names);

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
                'id'              => $i++,
                'hazard'          => (string) ($h->name ?? ''),
                'persons_at_risk' => $people,
                'pre_likelihood'  => (int) ($h->pre_likelihood  ?? 3),
                'pre_severity'    => (int) ($h->pre_severity    ?? 3),
                'controls'        => array_values(array_filter(
                    array_map('strval', (array) ($h->controls ?? [])),
                    static fn (string $s): bool => strlen(trim($s)) > 0,
                )),
                'post_likelihood' => (int) ($h->post_likelihood ?? 1),
                'post_severity'   => (int) ($h->post_severity   ?? 2),
            ];
        }

        return $rows;
    }

    /**
     * Resolve hazards from the library.
     *
     * If explicit names are provided, do NOT add mandatory baselines.
     * If empty, include the mandatory baseline hazards.
     *
     * @return Collection
     */
    private function resolveHazards(int $userId, array $names): Collection
    {
        $includeMandatory = empty($names);

        return $this->hazardLibrary->resolveFromSeeds($userId, $names, $includeMandatory);
    }
}

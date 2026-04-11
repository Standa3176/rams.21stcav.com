<?php

declare(strict_types=1);

namespace App\Core\Modules\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Canonical data merge service for the RAMS platform.
 *
 * Entry point:
 *   resolve(Project $project): array
 *
 * Returns a structured dataset consuming all available data sources in priority order:
 *   reviewed_data > quotewerks_sql > extracted_data > defaults
 *
 * Survey data enriches the 'rooms' key only.
 *
 * This service is READ-ONLY. resolve() never writes to the database.
 * Downstream generators MUST consume exclusively from this service — no direct
 * access to extracted_data, reviewed_data, or survey tables from within generators.
 *
 * @see DATA-01, DATA-02, DATA-04, DATA-05
 * @note DATA-03 (generator wiring) is delivered in generator phase plans, not here.
 */
class ProjectDataService
{
    /** Fields with confidence below this threshold are flagged as low-confidence. */
    public const CONFIDENCE_THRESHOLD = 0.7;

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a canonical dataset for the given project.
     *
     * @param  Project $project  The project to resolve.
     * @return array             Canonical dataset with keys: project, equipment, rooms, activities, risks, survey, programme, cables, meta.
     */
    public function resolve(Project $project): array
    {
        $package = $project->relationLoaded('latestPackage')
            ? $project->latestPackage
            : $project->latestPackage()->first();

        $survey = $project->relationLoaded('siteSurveys')
            ? $project->siteSurveys
                  ->where('status', 'completed')
                  ->whereNull('superseded_at')
                  ->first()
            : $project->siteSurveys()
                  ->where('status', 'completed')
                  ->whereNull('superseded_at')
                  ->latest()
                  ->first();

        [$source, $dataSource, $confidence] = $this->resolveSourceTier($package);

        return [
            'project'    => $this->resolveProjectFields($project),
            'equipment'  => $this->resolveEquipment($source, $dataSource, $confidence),
            'rooms'      => $this->resolveRooms($source, $survey, $dataSource, $confidence),
            'activities' => $this->resolveActivities($source, $dataSource, $confidence),
            'risks'      => $this->resolveRisks($source, $survey, $dataSource, $confidence),
            'survey'     => $this->resolveSurveyMeta($survey),
            'programme'  => $this->resolveProgramme($source, $dataSource, $confidence),
            'cables'     => $this->resolveCables($source, $dataSource, $confidence),
            'meta'       => [
                'data_source'     => $dataSource,
                'has_survey'      => $survey !== null,
                'survey_complete' => $this->isSurveyComplete($survey),
                'confidence'      => $confidence,
            ],
        ];
    }

    /**
     * Return true if the given confidence value is below the low-confidence threshold.
     *
     * @param  float $confidence  Field-level confidence score.
     * @return bool
     */
    public function isLowConfidence(float $confidence): bool
    {
        return $confidence < self::CONFIDENCE_THRESHOLD;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Source resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determine the authoritative source tier for this package.
     *
     * Priority (DATA-05):
     *   reviewed_data → manual (1.0)
     *   quotewerks_sql meta.source → quotewerks_sql (0.95)
     *   extracted_data → pdf (0.85)
     *   no package → defaults (0.0)
     *
     * @return array [sourceArray, dataSourceString, confidenceFloat]
     */
    private function resolveSourceTier(?object $package): array
    {
        if ($package === null) {
            return [[], 'defaults', 0.0];
        }

        if (isset($package->reviewed_data) && $package->reviewed_data !== null) {
            return [(array) $package->reviewed_data, 'manual', 1.0];
        }

        $extracted  = (array) ($package->extracted_data ?? []);
        $metaSource = $extracted['meta']['source'] ?? 'pdf';

        if ($metaSource === 'quotewerks_sql') {
            return [$extracted, 'quotewerks_sql', 0.95];
        }

        return [$extracted, 'pdf', 0.85];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Field resolvers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveProjectFields(Project $project): array
    {
        return [
            'id'              => $project->id,
            'name'            => $project->name,
            'client_name'     => $project->client_name,
            'site_address'    => $project->site_address,
            'quote_reference' => $project->quote_reference ?? $project->ref,
            'status'          => $project->status,
            'created_at'      => $project->created_at?->toISOString(),
        ];
    }

    private function resolveEquipment(array $source, string $dataSource, float $confidence): array
    {
        // Prefer hardware_list (pre-classified by ExtractQuoteJob) so RAMS, O&M,
        // and worksheets only receive physical hardware — no services or consumables.
        // Fall back to equipment_list with item_type filtering for older packages.
        if (! empty($source['hardware_list'])) {
            $items = $source['hardware_list'];
        } else {
            $all   = $source['equipment'] ?? $source['equipment_list'] ?? [];
            $items = array_values(array_filter((array) $all, function (array $item): bool {
                $type = $item['item_type'] ?? '';
                if ($type === 'consumable' || $type === 'professional_service') {
                    return false;
                }
                return true;
            }));
        }

        if (empty($items)) {
            return [];
        }

        return array_map(fn(array $item) => array_merge($item, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $items));
    }

    private function resolveRooms(array $source, ?object $survey, string $dataSource, float $confidence): array
    {
        $quoteRooms = $source['rooms'] ?? $source['groups'] ?? [];

        // Guard against string entries — the parser can return site names as plain strings.
        $rooms = array_map(fn(array $room) => array_merge($room, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values(array_filter((array) $quoteRooms, fn ($r) => is_array($r))));

        // Survey enriches rooms with physical details (above all package tiers for room data).
        // Phase 3 will implement full fuzzy merge; Phase 1 provides the hook.
        if ($survey !== null) {
            $rooms = $this->mergeSurveyRooms($rooms, $survey);
        }

        return $rooms;
    }

    private function resolveActivities(array $source, string $dataSource, float $confidence): array
    {
        $items = $source['activities'] ?? $source['works'] ?? [];
        return array_map(fn($item) => array_merge((array) $item, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $items));
    }

    private function resolveRisks(array $source, ?object $survey, string $dataSource, float $confidence): array
    {
        $items = $source['risks'] ?? $source['hazards'] ?? [];
        return array_map(fn($item) => array_merge((array) $item, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $items));
    }

    private function resolveSurveyMeta(?object $survey): array
    {
        if ($survey === null) {
            return ['has_survey' => false, 'submitted_at' => null, 'rooms' => []];
        }

        $submittedAt = null;
        if (isset($survey->completed_at) && $survey->completed_at !== null) {
            $submittedAt = is_string($survey->completed_at)
                ? $survey->completed_at
                : $survey->completed_at->toISOString();
        } elseif (isset($survey->updated_at) && $survey->updated_at !== null) {
            $submittedAt = is_string($survey->updated_at)
                ? $survey->updated_at
                : $survey->updated_at->toISOString();
        }

        return [
            'has_survey'         => true,
            'submitted_at'       => $submittedAt,
            'site_risks'         => $survey->site_risks,
            'access_constraints' => $survey->access_constraints,
            'h_and_s_notes'      => $survey->h_and_s_notes,
            'general_notes'      => $survey->general_notes,
            'rooms'              => $this->normalizeRooms($survey->rooms),
        ];
    }

    private function resolveProgramme(array $source, string $dataSource, float $confidence): array
    {
        $items = $source['programme'] ?? [];
        return array_map(fn($item) => array_merge((array) $item, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $items));
    }

    private function resolveCables(array $source, string $dataSource, float $confidence): array
    {
        $items = $source['cables'] ?? $source['cable_list'] ?? [];
        return array_map(fn($item) => array_merge((array) $item, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $items));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Survey helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determine whether the survey is considered complete.
     * Supports both Eloquent SiteSurvey model and plain objects used in tests.
     */
    private function isSurveyComplete(?object $survey): bool
    {
        if ($survey === null) {
            return false;
        }

        $status = is_array($survey) ? ($survey['status'] ?? null) : ($survey->status ?? null);

        return $status === 'completed';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Survey merge — relational fuzzy matching (Phase 3)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Merge SiteSurveyRoom records into the quote-derived rooms array.
     *
     * Loads rooms from the SiteSurveyRoom Eloquent relation (never from room_data JSON).
     * Uses fuzzy name matching via similar_text() at a 0.70 threshold.
     * Matched survey rooms override quote room physical details at confidence 0.95.
     * Unmatched survey rooms are appended as orphan entries with quote_room_matched: false.
     *
     * @param  array   $quoteRooms  Quote-derived room entries (each is an array).
     * @param  object  $survey      SiteSurvey model (or compatible stub).
     * @return array                Merged rooms array with survey enrichment and orphan appends.
     */
    private function mergeSurveyRooms(array $quoteRooms, object $survey): array
    {
        $survey->loadMissing('rooms');

        $normalizedSurveyRooms = $this->normalizeRooms($survey->rooms);

        $matchedSurveyIndexes = [];

        $result = array_map(function (array $quoteRoom) use ($normalizedSurveyRooms, &$matchedSurveyIndexes) {
            $quoteName  = (string) ($quoteRoom['room_name'] ?? $quoteRoom['name'] ?? '');
            $bestScore  = 0.0;
            $bestIndex  = -1;

            foreach ($normalizedSurveyRooms as $idx => $surveyRoom) {
                $surveyName = (string) ($surveyRoom['room_name'] ?? '');
                $score      = $this->roomSimilarity($quoteName, $surveyName);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $idx;
                }
            }

            if ($bestScore >= 0.70 && $bestIndex !== -1) {
                $matchedSurveyIndexes[$bestIndex] = true;

                // Survey fields win for physical details; re-apply survey annotation
                return array_merge($quoteRoom, $normalizedSurveyRooms[$bestIndex], [
                    'data_source' => 'survey',
                    'confidence'  => 0.95,
                ]);
            }

            return $quoteRoom;
        }, $quoteRooms);

        // Append unmatched survey rooms as orphan entries
        foreach ($normalizedSurveyRooms as $idx => $surveyRoom) {
            if (! isset($matchedSurveyIndexes[$idx])) {
                $result[] = array_merge($surveyRoom, [
                    'quote_room_matched' => false,
                    'data_source'        => 'survey',
                    'confidence'         => 0.95,
                ]);
            }
        }

        return $result;
    }

    /**
     * Normalise an iterable of SiteSurveyRoom records into the D-10 canonical field list.
     *
     * Extracts generator-relevant fields only; excludes items_to_remove, items_to_retain,
     * and existing_condition (strip-out info, not generator inputs).
     * All entries carry data_source: 'survey' and confidence: 0.95 (D-11).
     *
     * @param  iterable $surveyRooms  Collection or array of SiteSurveyRoom records / stubs.
     * @return array
     */
    private function normalizeRooms(iterable $surveyRooms): array
    {
        $result = [];

        foreach ($surveyRooms as $room) {
            $result[] = [
                // Identity
                'room_name'                 => $room->room_name,
                'room_ref'                  => $room->room_ref,
                'floor'                     => $room->floor,
                'area_type'                 => $room->area_type,
                'space_type'                => $room->space_type,
                // Dimensions
                'room_width_m'              => $room->room_width_m,
                'room_depth_m'              => $room->room_depth_m,
                'room_height_m'             => $room->room_height_m,
                'ceiling_type'              => $room->ceiling_type,
                'ceiling_height_m'          => $room->ceiling_height_m,
                'wall_material'             => $room->wall_material,
                'floor_type'                => $room->floor_type,
                // AV scope
                'av_requirements'           => $room->av_requirements,
                'av_equipment_list'         => $room->av_equipment_list,
                // Services
                'has_power'                 => $room->has_power,
                'has_network'               => $room->has_network,
                'power_outlet_count'        => $room->power_outlet_count,
                'network_port_count'        => $room->network_port_count,
                'requires_additional_power' => $room->requires_additional_power,
                'existing_cabling'          => $room->existing_cabling,
                // Infrastructure
                'rack_unit_space'           => $room->rack_unit_space,
                'cable_route_desc'          => $room->cable_route_desc,
                // Audio
                'speaker_count'             => $room->speaker_count,
                'speaker_type'              => $room->speaker_type,
                'speaker_mounting'          => $room->speaker_mounting,
                'bg_noise_db'               => $room->bg_noise_db,
                // Displays
                'display_size_in'           => $room->display_size_in,
                'display_orient'            => $room->display_orient,
                'display_mounting'          => $room->display_mounting,
                // Access / notes
                'access_notes'              => $room->access_notes,
                'notes'                     => $room->notes,
                // Completion
                'is_completed'              => $room->is_completed,
                'completed_at'              => $room->completed_at instanceof \DateTimeInterface
                    ? $room->completed_at->format(\DateTime::ATOM)
                    : $room->completed_at,
                // Annotation (D-11)
                'data_source'               => 'survey',
                'confidence'                => 0.95,
            ];
        }

        return $result;
    }

    /**
     * Compute name similarity between two room name strings using similar_text().
     *
     * Returns a 0.0–1.0 float (0 = no similarity, 1.0 = identical).
     *
     * @param  string $a  First room name.
     * @param  string $b  Second room name.
     * @return float
     */
    private function roomSimilarity(string $a, string $b): float
    {
        similar_text(strtolower(trim($a)), strtolower(trim($b)), $pct);

        return $pct / 100.0;
    }
}

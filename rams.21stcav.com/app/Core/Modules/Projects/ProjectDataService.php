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
            ? $project->siteSurveys->where('status', 'completed')->first()
            : $project->siteSurveys()->where('status', 'completed')->latest()->first();

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
        $items = $source['equipment'] ?? $source['equipment_list'] ?? [];
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

        $rooms = array_map(fn(array $room) => array_merge($room, [
            'data_source' => $dataSource,
            'confidence'  => $confidence,
        ]), array_values((array) $quoteRooms));

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
            'has_survey'   => true,
            'submitted_at' => $submittedAt,
            'rooms'        => $survey->room_data ?? [],
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
    // Survey merge (Phase 1 stub — full fuzzy merge in Phase 3)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Merge survey room data into the quote-derived rooms array.
     *
     * Phase 1 stub: exact name match only. Phase 3 will implement fuzzy matching (D-26).
     * Survey data has higher confidence than package data for physical room details.
     */
    private function mergeSurveyRooms(array $quoteRooms, object $survey): array
    {
        $roomData = is_array($survey->room_data ?? null)
            ? $survey->room_data
            : [];

        $surveyRooms = collect($roomData)
            ->keyBy(fn($r) => strtolower(trim(is_array($r) ? ($r['name'] ?? '') : ($r->name ?? ''))));

        return array_map(function (array $room) use ($surveyRooms) {
            $key        = strtolower(trim($room['name'] ?? ''));
            $surveyRoom = $surveyRooms->get($key);

            if ($surveyRoom) {
                // Survey enriches with physical details at higher confidence
                return array_merge($room, (array) $surveyRoom, [
                    'data_source' => 'survey',
                    'confidence'  => 0.95,
                ]);
            }

            return $room;
        }, $quoteRooms);
    }
}

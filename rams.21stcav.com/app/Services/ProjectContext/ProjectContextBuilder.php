<?php

namespace App\Services\ProjectContext;

use App\Models\SiteSurvey;
use InvalidArgumentException;

/**
 * ProjectContextBuilder
 *
 * Entry point for the survey → ProjectContext pipeline.
 *
 * Accepts a SiteSurvey model and returns a unified internal structure
 * that downstream services (RiskTemplateResolverService, CableScheduleBuilderService,
 * RAMS hazard generation) can all consume from a single source.
 *
 * Usage:
 *   $context = ProjectContextBuilder::build($survey);
 *   $context = ProjectContextBuilder::buildFromPayload($surveyData);
 *
 * OUTPUT structure:
 * {
 *   "project_id": int,
 *   "rooms": [
 *     {
 *       "name":               string,
 *       "type":               string,
 *       "activities":         string[],   // controlled vocabulary
 *       "infrastructure":     array,
 *       "equipment":          [{ "type", "status", "location" }],
 *       "risks":              [{ "working_height", "access_equipment", "out_of_hours",
 *                                "permits_required", "manual_handling_risk" }],
 *       "cable_requirements": [{ "equipment_type", "equipment_status", "equipment_location",
 *                                "cable_type", "estimated_distance" }]
 *     }
 *   ]
 * }
 *
 * Activity controlled vocabulary (exhaustive):
 *   display_installation | audio_installation | vc_installation |
 *   control_installation | cable_installation | commissioning
 *
 * This service never persists anything and has no external service dependencies.
 * All mapping is delegated to SurveyToProjectContextMapper (deterministic, no AI).
 */
class ProjectContextBuilder
{
    /**
     * Build a ProjectContext array from a SiteSurvey model.
     *
     * @param  SiteSurvey  $survey  Model with survey_data cast to array.
     * @return array                Fully mapped ProjectContext structure.
     *
     * @throws InvalidArgumentException  When survey_data is missing or malformed.
     */
    public static function build(SiteSurvey $survey): array
    {
        $surveyData = $survey->survey_data;

        if (empty($surveyData) || ! is_array($surveyData)) {
            throw new InvalidArgumentException(
                "SiteSurvey #{$survey->id} has no survey_data payload. " .
                'Complete the mobile wizard before building ProjectContext.'
            );
        }

        if (empty($surveyData['rooms'])) {
            throw new InvalidArgumentException(
                "SiteSurvey #{$survey->id} survey_data contains no rooms."
            );
        }

        // Pass the SiteSurveyRoom relation into the mapper so per-room
        // engineer-feedback fields (mounting heights, work-at-height methods,
        // cable routes, wall prep, brackets, table/floor box info) are merged
        // into the rooms[] output for downstream RAMS generation.
        $context = SurveyToProjectContextMapper::mapWithModelRooms(
            $surveyData,
            $survey->rooms
        );

        // Supplement project_id from model relationship when payload omits it
        if (empty($context['project_id']) && $survey->project_id) {
            $context['project_id'] = (int) $survey->project_id;
        }

        // Attach site-level engineer feedback (NOT in survey_data JSON — these
        // live on the SiteSurvey model columns added in quick task 260503-rgg).
        $context['site_logistics'] = [
            'comms_room_access_status' => (string) ($survey->comms_room_access_status ?? ''),
            'comms_room_access_notes'  => (string) ($survey->comms_room_access_notes  ?? ''),
            'parking_restraints'       => (string) ($survey->parking_restraints       ?? ''),
            'distance_from_base_miles' => $survey->distance_from_base_miles, // may be null
            'distance_from_base_notes' => (string) ($survey->distance_from_base_notes ?? ''),
            'site_access_notes'        => (string) ($survey->site_access_notes        ?? ''),
            'delivery_routes'          => (string) ($survey->delivery_routes          ?? ''),
        ];

        return $context;
    }

    /**
     * Build from a raw survey_data array directly (useful in tests and jobs
     * where the model may not be available).
     *
     * @param  array  $surveyData  Raw canonical payload.
     * @return array               Mapped ProjectContext structure.
     */
    public static function buildFromPayload(array $surveyData): array
    {
        return SurveyToProjectContextMapper::map($surveyData);
    }
}

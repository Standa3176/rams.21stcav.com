<?php

namespace App\Services;

use App\Models\RamsDocument;

/**
 * Loads and normalises RAMS review data for display in the review UI.
 *
 * Priority:
 *   reviewed_data  (human-corrected) → if set, use this
 *   extracted_data (machine output)  → fallback to this
 *   empty defaults                   → if neither is set
 *
 * Always returns the canonical review schema so Blade templates can
 * be written with unconditional array access — no null guards needed.
 */
class RamsReviewDataService
{
    // =========================================================================
    // PUBLIC ENTRY POINTS
    // =========================================================================

    /**
     * Load review data for a given RAMS document record.
     * Returns a fully normalised array ready for Blade rendering.
     */
    public function load(RamsDocument $record): array
    {
        $raw = $record->reviewed_data ?: $record->extracted_data ?: [];
        $normalised = $this->normalise($raw);

        // If reviewed_data exists but room_overviews are missing or empty,
        // fall back to extracted_data. Also backfill empty per-room overviews.
        if (! empty($record->extracted_data['room_overviews'])) {
            $extracted = $this->normaliseRoomOverviews($record->extracted_data['room_overviews']);

            if (empty($normalised['room_overviews'])) {
                $normalised['room_overviews'] = $extracted;
            } else {
                $byRoom = [];
                foreach ($extracted as $row) {
                    $key = mb_strtolower(trim((string) ($row['room'] ?? '')));
                    if ($key !== '') {
                        $byRoom[$key] = $row;
                    }
                }

                $normalised['room_overviews'] = array_map(function ($row) use ($byRoom) {
                    $key = mb_strtolower(trim((string) ($row['room'] ?? '')));
                    if ($key !== '' && isset($byRoom[$key])) {
                        $ex = $byRoom[$key];
                        // Phase 22.1 D-01 / D-07: only the canonical `overview` field
                        // is backfilled from extracted_data. The legacy `summary` and
                        // `description` backfills are removed — the one-off
                        // `summary → works_summary` migration is handled by
                        // rams:backfill-room-overview-summary (Plan 03 Task 1) and the
                        // schema-trim drops both keys from the normaliser output.
                        if (trim((string) ($row['overview'] ?? '')) === '') {
                            $row['overview'] = (string) ($ex['overview'] ?? '');
                        }
                    }
                    return $row;
                }, $normalised['room_overviews']);
            }
        }

        return $normalised;
    }

    /**
     * Normalise an arbitrary payload to the canonical review schema.
     * Safe to call with empty arrays or partially-filled data.
     */
    public function normalise(array $data): array
    {
        return [
            'project'                => $this->normaliseProject($data['project'] ?? []),
            'equipment'              => $this->normaliseEquipment($data['equipment'] ?? []),
            'activities'             => $this->normaliseActivities($data['activities'] ?? []),
            'hazards'                => $this->normaliseHazards($data['hazards'] ?? []),
            'ppe'                    => $this->normaliseStringArray($data['ppe'] ?? []),
            'access'                 => $this->normaliseAccess($data['access'] ?? []),
            'method_statement_notes' => (string) ($data['method_statement_notes'] ?? ''),
            'scope_of_works'         => (string) ($data['scope_of_works']  ?? ''),
            'works_overview'         => (string) ($data['works_overview']  ?? ''),
            'room_overviews'         => $this->normaliseRoomOverviews($data['room_overviews'] ?? []),
            'meta'                   => $this->normaliseMeta($data['meta'] ?? []),
            'programme'              => $this->normaliseProgramme($data['programme'] ?? []),
            'site_logistics'         => $this->normaliseSiteLogistics($data['site_logistics'] ?? []),
        ];
    }

    // =========================================================================
    // PRIVATE NORMALISERS
    // =========================================================================

    private function normaliseProject(mixed $raw): array
    {
        // Phase 22.1 D-09: project shape is exactly 11 keys. The legacy
        // `overview` field is dropped — nothing in the application reads
        // reviewed_data.project.overview (audited 2026-05-13). The raw
        // QuoteWerks overview prose continues to live at the top-level
        // extracted_data['overview'] where the review form can still
        // surface it as a starting-value suggestion.
        $p = is_array($raw) ? $raw : [];
        return [
            'project_name' => (string) ($p['project_name'] ?? ''),
            'quote_ref'    => (string) ($p['quote_ref']    ?? ''),
            'client_name'  => (string) ($p['client_name']  ?? ''),
            'site_name'    => (string) ($p['site_name']    ?? ''),
            'site_address' => (string) ($p['site_address'] ?? ''),
            'site_contact' => (string) ($p['site_contact'] ?? ''),
            'prepared_by'  => (string) ($p['prepared_by']  ?? ''),
            'project_manager'      => (string) ($p['project_manager']      ?? ''),
            'lead_engineer'        => (string) ($p['lead_engineer']        ?? ''),
            'additional_engineers' => (string) ($p['additional_engineers'] ?? ''),
            'programmer'           => (string) ($p['programmer']           ?? ''),
        ];
    }

    private function normaliseEquipment(mixed $raw): array
    {
        if (! is_array($raw) || empty($raw)) {
            return [['quantity' => 1, 'part_number' => '', 'name' => '']];
        }

        return array_values(array_map(
            fn ($e) => [
                'quantity'    => max(1, (int) ($e['quantity']    ?? 1)),
                'part_number' => (string) ($e['part_number'] ?? ''),
                'name'        => (string) ($e['name']        ?? ''),
                'area'        => (string) ($e['area']        ?? ''),
                'category'    => (string) ($e['category']    ?? ''),
            ],
            $raw,
        ));
    }

    private function normaliseActivities(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            fn ($a) => [
                'key'   => (string) ($a['key']   ?? ''),
                'label' => (string) ($a['label'] ?? ''),
            ],
            $raw,
        ));
    }

    /**
     * Phase 26 Plan 05 (HAZ-04): the review screen must pre-fill and let an
     * engineer edit real numeric L×S scores — never silently commit a typical
     * score the app chose. This normaliser is the schema gate every hazard
     * row must survive: `risk` (Low/Medium/High) is fully superseded by four
     * numeric fields plus a `score_reviewed` marker and the tiered resolver's
     * `needs_confirmation` flag (Plan 26-04 / D-06).
     *
     * Every numeric field is clamped independently to 1-5 when present and
     * numeric; a missing or non-numeric field falls back to the matching
     * value from an inline High/Medium/Low bucket map (keyed off the legacy
     * `risk` string, defaulting to Medium) — the same convention
     * RamsBuilderService::riskLevelsFromString() already uses, extended with
     * a residual pair via reviewedToRisk()'s existing "one lower" pattern.
     * This keeps old reviewed_data rows that only ever had `risk` normalising
     * correctly with no data migration required.
     */
    private function normaliseHazards(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        // High/Medium/Low => [pre_likelihood, pre_severity, post_likelihood, post_severity]
        $legacyBuckets = [
            'high'   => ['pre_likelihood' => 4, 'pre_severity' => 4, 'post_likelihood' => 3, 'post_severity' => 3],
            'medium' => ['pre_likelihood' => 3, 'pre_severity' => 3, 'post_likelihood' => 2, 'post_severity' => 2],
            'low'    => ['pre_likelihood' => 2, 'pre_severity' => 2, 'post_likelihood' => 1, 'post_severity' => 1],
        ];

        return array_values(array_map(
            function ($h) use ($legacyBuckets) {
                $bucketKey = strtolower(trim((string) ($h['risk'] ?? '')));
                $bucket = $legacyBuckets[$bucketKey] ?? $legacyBuckets['medium'];

                $score = function (string $key) use ($h, $bucket) {
                    $value = $h[$key] ?? null;
                    return is_numeric($value) ? max(1, min(5, (int) $value)) : $bucket[$key];
                };

                return [
                    'activity_key'       => (string) ($h['activity_key'] ?? ''),
                    'hazard'             => (string) ($h['hazard']       ?? ''),
                    'pre_likelihood'     => $score('pre_likelihood'),
                    'pre_severity'       => $score('pre_severity'),
                    'post_likelihood'    => $score('post_likelihood'),
                    'post_severity'      => $score('post_severity'),
                    'score_reviewed'     => (bool) ($h['score_reviewed'] ?? false),
                    'needs_confirmation' => (bool) ($h['needs_confirmation'] ?? false),
                    'control_measures'   => $this->normaliseStringArray($h['control_measures'] ?? []),
                ];
            },
            $raw,
        ));
    }

    private function normaliseAccess(mixed $raw): array
    {
        $a = is_array($raw) ? $raw : [];
        return [
            'ladders'          => (bool) ($a['ladders']          ?? false),
            'tower'            => (bool) ($a['tower']            ?? false),
            'scissor_lift'     => (bool) ($a['scissor_lift']     ?? false),
            'out_of_hours'     => (bool) ($a['out_of_hours']     ?? false),
            'live_environment' => (bool) ($a['live_environment'] ?? false),
        ];
    }

    private function normaliseRoomOverviews(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        // Phase 22.1 D-02 / D-07 / D-08 / D-01: canonical shape is exactly
        // 4 keys (room / overview / works_summary / solution_type_id).
        // Legacy `summary` / `description` / `scope` are intentionally
        // dropped — the one-off `summary → works_summary` migration is
        // handled by `rams:backfill-room-overview-summary` (Plan 03 Task
        // 1) BEFORE any reader stops consuming the summary key.
        return array_values(array_map(
            fn ($r) => [
                'room'             => (string) ($r['room']             ?? ''),
                'overview'         => (string) ($r['overview']         ?? ''),
                'works_summary'    => (string) ($r['works_summary']    ?? ''),
                'solution_type_id' => (int)    ($r['solution_type_id'] ?? 0) ?: null,
            ],
            $raw,
        ));
    }

    private function normaliseMeta(mixed $raw): array
    {
        $m = is_array($raw) ? $raw : [];
        return [
            'parser_confidence' => isset($m['parser_confidence'])
                                    ? (float) $m['parser_confidence']
                                    : null,
            'source'            => (string) ($m['source'] ?? 'extracted'),
        ];
    }

    private function normaliseStringArray(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $raw),
            fn (string $s) => strlen(trim($s)) > 0,
        ));
    }

    private function normaliseProgramme(mixed $raw): array
    {
        $p = is_array($raw) ? $raw : [];
        $engineers = array_values(array_filter(
            array_map('strval', (array) ($p['additional_engineers'] ?? [])),
            fn (string $s) => strlen(trim($s)) > 0,
        ));
        $wh = in_array($p['working_hours'] ?? '', ['in_hours', 'out_of_hours'], true)
            ? $p['working_hours']
            : 'in_hours';
        return [
            'project_manager_name'  => (string) ($p['project_manager_name']  ?? ''),
            'project_manager_phone' => (string) ($p['project_manager_phone'] ?? ''),
            'project_manager_email' => (string) ($p['project_manager_email'] ?? ''),
            'lead_engineer_name'    => (string) ($p['lead_engineer_name']    ?? ''),
            'lead_engineer_phone'   => (string) ($p['lead_engineer_phone']   ?? ''),
            'additional_engineers'  => $engineers,
            'programmers'           => array_values(array_filter(
                array_map('strval', (array) ($p['programmers'] ?? [])),
                fn (string $s) => strlen(trim($s)) > 0,
            )),
            'working_hours'         => $wh,
            'planned_start_date'    => (string) ($p['planned_start_date'] ?? ''),
            'planned_end_date'      => (string) ($p['planned_end_date']   ?? ''),
            'ongoing'               => (bool)   ($p['ongoing']            ?? false),
            'planned_start_time'    => (string) ($p['planned_start_time'] ?? ''),
        ];
    }

    private function normaliseSiteLogistics(mixed $raw): array
    {
        $s = is_array($raw) ? $raw : [];
        $validAccessTypes = ['no_special', 'induction', 'reception', 'security', 'other'];
        return [
            'contact_name'        => (string) ($s['contact_name']        ?? ''),
            'contact_phone'       => (string) ($s['contact_phone']       ?? ''),
            'contact_email'       => (string) ($s['contact_email']       ?? ''),
            'delivery_area'       => (string) ($s['delivery_area']       ?? ''),
            'restrictions'        => (string) ($s['restrictions']        ?? ''),
            'commissioning_notes' => (string) ($s['commissioning_notes'] ?? ''),
            // New logistics fields
            'parking'             => in_array($s['parking'] ?? '', ['yes', 'no'], true)
                                        ? $s['parking']
                                        : '',
            'parking_notes'       => (string) ($s['parking_notes']       ?? ''),
            'install_floor'       => (string) ($s['install_floor']       ?? ''),
            'access_type'         => in_array($s['access_type'] ?? '', $validAccessTypes, true)
                                        ? $s['access_type']
                                        : '',
            'access_notes'        => (string) ($s['access_notes']        ?? ''),
        ];
    }
}

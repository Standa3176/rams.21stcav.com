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
                        if (trim((string) ($row['overview'] ?? '')) === '') {
                            $row['overview'] = (string) ($ex['overview'] ?? '');
                        }
                        if (trim((string) ($row['summary'] ?? '')) === '') {
                            $row['summary'] = (string) ($ex['summary'] ?? '');
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
            'room_overviews'         => $this->normaliseRoomOverviews($data['room_overviews'] ?? []),
            'meta'                   => $this->normaliseMeta($data['meta'] ?? []),
        ];
    }

    // =========================================================================
    // PRIVATE NORMALISERS
    // =========================================================================

    private function normaliseProject(mixed $raw): array
    {
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
            'overview'     => (string) ($p['overview']     ?? ''),
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

    private function normaliseHazards(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            fn ($h) => [
                'activity_key'     => (string) ($h['activity_key'] ?? ''),
                'hazard'           => (string) ($h['hazard']       ?? ''),
                'risk'             => in_array($h['risk'] ?? '', ['Low', 'Medium', 'High'])
                                        ? $h['risk']
                                        : 'Medium',
                'control_measures' => $this->normaliseStringArray($h['control_measures'] ?? []),
            ],
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

        return array_values(array_map(
            fn ($r) => [
                'room'     => (string) ($r['room']     ?? ''),
                'overview' => (string) ($r['overview'] ?? ''),
                'summary'  => (string) ($r['summary']  ?? ''),
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
}

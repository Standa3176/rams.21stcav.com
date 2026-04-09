<?php

namespace App\Services;

use App\Models\Project;

/**
 * ProjectContextResolver — read-only service.
 *
 * Normalises project context from existing data sources so that downstream
 * services (survey pre-fill, worksheet generation, cable schedule seeding, etc.)
 * all read from the same canonical location: the latest ProjectPackage's
 * extracted_data (which is the human-reviewed, approved snapshot).
 *
 * This service NEVER persists anything.
 *
 * Returned structure:
 * [
 *   'project'    => [
 *       'id'           => int,
 *       'name'         => string,
 *       'ref'          => string|null,
 *       'client_name'  => string,
 *       'site_address' => string,
 *   ],
 *   'equipment'  => [
 *       ['name' => string, 'quantity' => int, 'area' => string, 'category' => string],
 *       ...
 *   ],
 *   'activities' => [
 *       ['key' => string, 'label' => string],
 *       ...
 *   ],
 *   'rooms'      => [
 *       ['room' => string, 'overview' => string, 'summary' => string],
 *       ...
 *   ],
 * ]
 */
class ProjectContextResolver
{
    /**
     * Build a normalised project context array from the latest reviewed package.
     *
     * Falls back gracefully when no package exists — returns the project's own
     * fields with empty equipment/activity/room arrays.
     *
     * @param  Project  $project  Must already be loaded (no additional DB queries
     *                            unless latestPackage is not eagerly loaded).
     */
    public function resolve(Project $project): array
    {
        // Prefer the already-loaded relationship to avoid an extra query.
        $package       = $project->relationLoaded('latestPackage')
            ? $project->latestPackage
            : $project->latestPackage()->first();

        $extracted     = (array) ($package?->extracted_data ?? []);

        return [
            'project'    => $this->resolveProjectFields($project, $extracted),
            'equipment'  => $this->resolveEquipment($extracted),
            'activities' => $this->resolveActivities($extracted),
            'rooms'      => $this->resolveRooms($extracted),
        ];
    }

    // ─── Private resolvers ────────────────────────────────────────────────────

    /**
     * Merge project model fields with any overrides in extracted_data.
     * The Project model's own columns are always used as the authority for
     * name, ref, client_name, and site_address — the extracted snapshot may
     * have stale values from an older quote.
     */
    private function resolveProjectFields(Project $project, array $extracted): array
    {
        return [
            'id'           => $project->id,
            'name'         => $project->name,
            'ref'          => $project->ref,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
        ];
    }

    /**
     * Normalise the equipment array from extracted_data.
     *
     * Accepted source keys (produced by QuoteParserService):
     *   extracted_data['equipment']  — canonical equipment list
     *
     * Each returned item is guaranteed to have: name, quantity, area, category.
     */
    private function resolveEquipment(array $extracted): array
    {
        $raw = (array) ($extracted['equipment'] ?? []);

        return array_values(array_filter(array_map(function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $name = trim((string) ($item['name'] ?? $item['description'] ?? ''));
            if ($name === '') {
                return null;
            }

            return [
                'name'     => $name,
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'area'     => trim((string) ($item['area'] ?? $item['location'] ?? '')),
                'category' => strtolower(trim((string) ($item['category'] ?? 'hardware'))),
            ];
        }, $raw)));
    }

    /**
     * Normalise the activities array from extracted_data.
     *
     * Each returned item has: key, label.
     */
    private function resolveActivities(array $extracted): array
    {
        $raw = (array) ($extracted['activities'] ?? []);

        return array_values(array_filter(array_map(function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                return null;
            }

            return [
                'key'   => $key,
                'label' => trim((string) ($item['label'] ?? $key)),
            ];
        }, $raw)));
    }

    /**
     * Normalise the room_overviews array from extracted_data.
     *
     * Each returned item has: room (name), overview (long text), summary (short text).
     * Rooms with an empty name are silently dropped.
     *
     * If room_overviews does not cover every area present in the equipment list
     * (common when OVERVIEWTITLE tags were absent from the source quote), the
     * missing areas are appended as stub entries so that createFromProject()
     * always creates one room per distinct equipment area.
     */
    private function resolveRooms(array $extracted): array
    {
        $raw = (array) ($extracted['room_overviews'] ?? []);

        $rooms = array_values(array_filter(array_map(function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $room = trim((string) ($item['room'] ?? ''));
            if ($room === '') {
                return null;
            }

            return [
                'room'             => $room,
                'overview'         => trim((string) ($item['overview']         ?? '')),
                'summary'          => trim((string) ($item['summary']          ?? '')),
                'works_summary'    => trim((string) ($item['works_summary']    ?? '')),
                'solution_type_id' => (int) ($item['solution_type_id'] ?? 0) ?: null,
            ];
        }, $raw)));

        // Supplement with any equipment areas not already covered by room_overviews.
        // This handles projects where the structured parser found areas but no overview
        // text was extracted (e.g. quotes without OVERVIEWTITLE tags).
        $covered   = array_map(fn(array $r): string => strtolower($r['room']), $rooms);
        $equipment = (array) ($extracted['equipment'] ?? []);

        foreach ($equipment as $item) {
            if (! is_array($item)) {
                continue;
            }
            // Only hardware items create survey rooms — cables, services, options are excluded
            $category = strtolower(trim((string) ($item['category'] ?? 'hardware')));
            if ($category !== 'hardware') {
                continue;
            }
            $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
            if ($area === '' || in_array(strtolower($area), $covered, true)) {
                continue;
            }
            $rooms[]   = ['room' => $area, 'overview' => '', 'summary' => '', 'works_summary' => '', 'solution_type_id' => null];
            $covered[] = strtolower($area);
        }

        return $rooms;
    }
}

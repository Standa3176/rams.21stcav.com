<?php

namespace App\Services;

use App\Models\Project;

/**
 * Safely backfills empty Project fields from newly uploaded quote data.
 *
 * Rules:
 *   - NEVER overwrites a field that already has a value.
 *   - Only touches the fields listed in SAFE_FIELDS.
 *   - If nothing needs updating, no database write is performed.
 *   - Never changes status, lifecycle timestamps, or any non-metadata field.
 */
class ProjectSyncFromQuoteService
{
    /**
     * Fields that may be backfilled if currently blank.
     * All other project fields are left untouched.
     */
    private const SAFE_FIELDS = ['ref', 'client_name', 'site_address', 'works_description'];

    /**
     * Backfill empty project fields from parsed/form data.
     *
     * @param  Project  $project   The resolved project to potentially update.
     * @param  array    $parsed    Output from QuoteParserService::parse().
     * @param  array    $formData  User-supplied overrides.
     * @return bool                True if any field was updated, false otherwise.
     */
    public function sync(Project $project, array $parsed, array $formData): bool
    {
        // Build candidate values (form > parsed)
        $candidates = [
            'ref'               => $this->coalesce(
                                       $formData['project_ref']   ?? '',
                                       $parsed['ref']             ?? '',
                                   ),
            'client_name'       => $this->coalesce(
                                       $formData['client_name']   ?? '',
                                       $parsed['client']          ?? '',
                                   ),
            'site_address'      => $this->coalesce(
                                       $formData['site_address']  ?? '',
                                       $parsed['site']            ?? '',
                                   ),
            'works_description' => $this->coalesce(
                                       $formData['works_description'] ?? '',
                                   ),
        ];

        // Strip placeholder ref
        if (($candidates['ref'] ?? '') === 'RAMS-001') {
            $candidates['ref'] = '';
        }

        $updates = [];

        foreach (self::SAFE_FIELDS as $field) {
            $candidate = trim($candidates[$field] ?? '');

            // Only backfill if:
            //   1. The candidate value is non-empty, AND
            //   2. The project field is currently blank/null
            // NEVER overwrite a field that already has a value.
            if ($candidate !== '' && empty($project->$field)) {
                $updates[$field] = $candidate;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $project->update($updates);

        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function coalesce(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}

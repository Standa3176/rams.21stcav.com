<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectQuote;
use App\Models\User;

/**
 * Creates a ProjectQuote version record linked to a resolved Project.
 *
 * Version numbering:
 *   - Version 1 if this is the first quote uploaded for the project.
 *   - max(version_number) + 1 for subsequent uploads.
 *
 * Duplicate guard:
 *   If a ProjectQuote already exists for the same project_id + quote_reference
 *   + original_filename combination, the existing record is returned and no new
 *   version is created. This prevents accidental re-submission of the same file
 *   from creating a phantom version increment.
 *
 * Previous versions are never modified or deleted.
 */
class ProjectQuoteVersionService
{
    /**
     * Create and persist a new ProjectQuote version for the given project,
     * or return the existing record if the same file/reference was already uploaded.
     *
     * @param  Project  $project          The resolved or newly created project.
     * @param  User     $uploader         The authenticated user performing the upload.
     * @param  string   $originalFilename The original filename from the browser upload.
     * @param  string   $storedFilename   The relative path where the PDF was stored (use Storage::path() for absolute).
     * @param  array    $parsed           Output from QuoteParserService::parse().
     * @param  array    $formData         User-supplied overrides.
     * @return ProjectQuote
     */
    public function create(
        Project $project,
        User    $uploader,
        string  $originalFilename,
        string  $storedFilename,
        array   $parsed,
        array   $formData,
    ): ProjectQuote {
        // Prefer form-supplied values; fall back to parsed values.
        $quoteRef    = $this->coalesce($formData['project_ref']  ?? '', $parsed['ref']    ?? '');
        $clientName  = $this->coalesce($formData['client_name']  ?? '', $parsed['client'] ?? '');
        $siteAddress = $this->coalesce($formData['site_address'] ?? '', $parsed['site']   ?? '');

        // Strip the default placeholder ref so it is not stored as a quote reference.
        if ($quoteRef === 'RAMS-001') {
            $quoteRef = '';
        }

        // ── Duplicate guard ───────────────────────────────────────────────────
        // If the same file (identified by project + reference + original filename)
        // has already been uploaded, return the existing record instead of creating
        // a duplicate version. This is safe: project_id is always set at this point.
        $existing = ProjectQuote::where('project_id', $project->id)
            ->where('original_filename', $originalFilename)
            ->when(
                $quoteRef !== '',
                fn ($q) => $q->where('quote_reference', $quoteRef),
                fn ($q) => $q->whereNull('quote_reference'),
            )
            ->first();

        if ($existing) {
            return $existing;
        }

        // ── Create new version ────────────────────────────────────────────────
        $nextVersion = $this->nextVersionNumber($project);

        return ProjectQuote::create([
            'project_id'        => $project->id,
            'uploaded_by'       => $uploader->id,
            'original_filename' => $originalFilename,
            'stored_filename'   => $storedFilename,    // relative path
            'quote_reference'   => $quoteRef ?: null,
            'quote_date'        => null,               // QuoteParserService does not extract dates yet
            'client_name'       => $clientName  ?: null,
            'site_name'         => null,               // not extracted by current parser
            'site_address'      => $siteAddress ?: null,
            'parsed_snapshot'   => $parsed,
            'version_number'    => $nextVersion,
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function nextVersionNumber(Project $project): int
    {
        $max = ProjectQuote::where('project_id', $project->id)
            ->max('version_number');

        return $max ? (int) $max + 1 : 1;
    }

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

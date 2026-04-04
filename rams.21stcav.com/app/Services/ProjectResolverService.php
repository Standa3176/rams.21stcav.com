<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectQuote;
use App\Models\User;

/**
 * Deterministically resolves or creates a Project from parsed quote data.
 *
 * Matching is entirely local PHP — no AI, no fuzzy string similarity.
 * Matches are attempted in confidence order; the first match wins.
 * If nothing matches, a new Project is created.
 *
 * ALL queries are scoped to the authenticated user to prevent cross-user leakage.
 *
 * Matching order:
 *   1. Exact quote_reference match against existing project_quotes records
 *      (scoped to projects owned by this user)
 *   2. Exact project ref (projects.ref) match (user-scoped)
 *   3. Normalised project name + site address match (user-scoped)
 *   4. Normalised client name + site address match (user-scoped)
 *   5. No match → create new Project
 *
 * Normalisation: trim, collapse whitespace, lowercase.
 * Null/empty fields are never used as match criteria.
 *
 * Return value: array{project: Project, action: string, reason: string}
 *   action = 'created' | 'matched'
 *   reason = 'quote_reference' | 'project_ref' | 'name_address'
 *           | 'client_address' | 'new_project'
 */
class ProjectResolverService
{
    /**
     * Resolve or create a Project for the given parsed quote data.
     *
     * @param  array  $parsed   Output from QuoteParserService::parse()
     * @param  array  $formData User-supplied overrides (client_name, site_address, project_ref, project_name)
     * @param  User   $user     The authenticated user performing the upload
     * @return array{project: Project, action: string, reason: string}
     */
    public function resolve(array $parsed, array $formData, User $user): array
    {
        // ── Resolve working values (form overrides parser) ────────────────────
        $quoteRef    = $this->coalesce($formData['project_ref']  ?? '', $parsed['ref']    ?? '');
        $clientName  = $this->coalesce($formData['client_name']  ?? '', $parsed['client'] ?? '');
        $siteAddress = $this->coalesce($formData['site_address'] ?? '', $parsed['site']   ?? '');
        $projectName = $this->coalesce($formData['project_name'] ?? '', $parsed['project_name'] ?? '');

        // Strip default placeholder ref produced by QuoteParserService
        if ($quoteRef === 'RAMS-001') {
            $quoteRef = '';
        }

        // ── 1. Exact quote reference match (user-scoped) ──────────────────────
        // Joins through the project relationship to guarantee the quote belongs
        // to a project owned by this user — prevents cross-user data leakage.
        if ($quoteRef !== '') {
            $pq = ProjectQuote::where('quote_reference', $quoteRef)
                ->whereNotNull('project_id')
                ->whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->with('project')
                ->latest()
                ->first();

            if ($pq && $pq->project) {
                return [
                    'project' => $pq->project,
                    'action'  => 'matched',
                    'reason'  => 'quote_reference',
                ];
            }
        }

        // ── 2. Exact project ref match (user-scoped) ──────────────────────────
        if ($quoteRef !== '') {
            $project = Project::where('user_id', $user->id)
                ->where('ref', $quoteRef)
                ->first();

            if ($project) {
                return [
                    'project' => $project,
                    'action'  => 'matched',
                    'reason'  => 'project_ref',
                ];
            }
        }

        // ── 3. Normalised project name + site address match (user-scoped) ─────
        if ($projectName !== '' && $siteAddress !== '') {
            $normName    = $this->normalize($projectName);
            $normAddress = $this->normalize($siteAddress);

            $project = Project::where('user_id', $user->id)
                ->get()
                ->first(fn (Project $p) =>
                    $this->normalize((string) $p->name) === $normName &&
                    $this->normalize((string) $p->site_address) === $normAddress
                );

            if ($project) {
                return [
                    'project' => $project,
                    'action'  => 'matched',
                    'reason'  => 'name_address',
                ];
            }
        }

        // ── 4. Normalised client name + site address match (user-scoped) ──────
        if ($clientName !== '' && $siteAddress !== '') {
            $normClient  = $this->normalize($clientName);
            $normAddress = $this->normalize($siteAddress);

            $project = Project::where('user_id', $user->id)
                ->get()
                ->first(fn (Project $p) =>
                    $this->normalize((string) $p->client_name) === $normClient &&
                    $this->normalize((string) $p->site_address) === $normAddress
                );

            if ($project) {
                return [
                    'project' => $project,
                    'action'  => 'matched',
                    'reason'  => 'client_address',
                ];
            }
        }

        // ── 5. No match — create new Project ──────────────────────────────────
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => $this->resolveProjectName($projectName, $clientName, $quoteRef),
            'ref'          => $quoteRef ?: null,
            'client_name'  => $clientName ?: 'Unknown Client',
            'site_address' => $siteAddress ?: 'Unknown Site',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        return [
            'project' => $project,
            'action'  => 'created',
            'reason'  => 'new_project',
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Return the first non-empty value from the candidates, or '' if all empty.
     */
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

    /**
     * Trim, collapse repeated whitespace, lowercase.
     * Safe on empty strings.
     * Used for ALL matching comparisons.
     */
    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($value)));
    }

    /**
     * Build a meaningful project name when no explicit name was provided.
     * Falls back through: client + " — " + ref, client only, ref only, generic.
     */
    private function resolveProjectName(string $projectName, string $clientName, string $quoteRef): string
    {
        if ($projectName !== '') {
            return $projectName;
        }

        if ($clientName !== '' && $quoteRef !== '') {
            return "{$clientName} — {$quoteRef}";
        }

        if ($clientName !== '') {
            return $clientName;
        }

        if ($quoteRef !== '') {
            return "Quote {$quoteRef}";
        }

        return 'New Project (' . now()->format('d M Y') . ')';
    }
}

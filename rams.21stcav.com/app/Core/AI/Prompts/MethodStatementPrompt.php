<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt DTO for AI-generated RAMS Method Statement phases.
 *
 * Generates exactly six phases covering the standard UK AV installation
 * sequence. All output is plain professional prose — no markdown, no tables,
 * no invented details.
 *
 * Context keys accepted by build():
 *
 *   site_address      string    — installation site name or address
 *   scope_summary     string    — plain-English description of the works
 *   activities        string[]  — activity keys from EquipmentClassifierService
 *   equipment_summary string    — brief comma-separated list of main equipment types
 *   hazard_summary    string    — brief comma-separated list of primary hazard categories
 *   rooms             string[]  — affected rooms / areas (optional)
 *   room_overview_summaries string — room summaries (optional)
 *   works_overview    string    — project-level 2–3 sentence executive summary (optional)
 *   room_descriptions string    — newline-delimited "Room: prose" entries from content pack (optional)
 *   is_retry          bool      — true on second attempt (appends retrySuffix)
 *
 * Expected AI response schema (JSON envelope required by MethodStatementService):
 *   {
 *     "phases": [
 *       { "title": "string", "steps": ["string", ...] },
 *       ...   // exactly 6 phases
 *     ]
 *   }
 *
 * Phase titles are fixed to the standard UK AV installation sequence:
 *   1. Pre-Start Checks
 *   2. Delivery and Materials Handling
 *   3. Access Equipment Setup
 *   4. Installation Works
 *   5. Cable Termination and Testing
 *   6. Final Checks and Handover
 *
 * The system message enforces plain-text prose inside each phase.
 * Steps must not contain markdown, bullet symbols, bold text, or tables.
 */
class MethodStatementPrompt extends BasePrompt
{
    // =========================================================================
    // BasePrompt overrides
    // =========================================================================

    public function systemMessage(): string
    {
        return implode(' ', [
            'You are writing a professional UK RAMS Method Statement for an AV installation contractor.',
            'Rules:',
            '- Use clear, concise professional language.',
            '- Follow logical installation sequence.',
            '- Do NOT invent equipment or site details.',
            '- Do NOT include tables or markdown.',
            '- Output plain text only.',
        ]);
    }

    public function maxTokens(): int
    {
        // 6 phases × up to 5 steps × ~30 words ≈ 900 words ≈ ~1 200 tokens.
        // 2 500 provides generous headroom for larger projects with many rooms.
        return 2500;
    }

    public function temperature(): float
    {
        // Slightly higher than default: method statements benefit from
        // natural variation in phrasing while remaining deterministic overall.
        return 0.3;
    }

    // =========================================================================
    // Prompt builder
    // =========================================================================

    public function build(array $context = []): string
    {
        // Explicit $context overrides anything stored via withContext().
        $ctx = array_merge($this->storedContext, $context);

        $site          = $this->resolveSite($ctx);
        $scope         = $this->resolveScope($ctx);
        $activities    = $this->resolveActivities($ctx);
        $equipment     = $this->resolveEquipment($ctx);
        $hazards       = $this->resolveHazards($ctx);
        $rooms         = $this->resolveRooms($ctx);
        $roomSummaries = $this->resolveRoomSummaries($ctx);
        $worksOverview    = $this->resolveWorksOverview($ctx);
        $roomDescriptions = $this->resolveRoomDescriptions($ctx);
        $isRetry       = (bool) ($ctx['is_retry'] ?? false);

        // Build optional supplementary lines — omitted when empty so the
        // prompt stays compact and does not waste tokens on blank labels.
        $equipmentLine        = $equipment        ? "\nKey equipment: {$equipment}"                  : '';
        $hazardsLine          = $hazards          ? "\nPrimary hazards: {$hazards}"                  : '';
        $roomsLine            = $rooms            ? "\nAffected areas: {$rooms}"                     : '';
        $roomSummaryLine      = $roomSummaries    ? "\nRoom summaries: {$roomSummaries}"             : '';
        $worksOverviewLine    = $worksOverview    ? "\nProject overview: {$worksOverview}"           : '';
        $roomDescriptionsLine = $roomDescriptions ? "\nRoom descriptions:\n{$roomDescriptions}"      : '';

        $retry = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
Write a method statement for the following UK AV installation project.

Project details:
Site: {$site}
Scope: {$scope}
Activities: {$activities}{$equipmentLine}{$hazardsLine}{$roomsLine}{$roomSummaryLine}{$worksOverviewLine}{$roomDescriptionsLine}

Return ONLY the following JSON structure with exactly six phases in this order:
{
  "phases": [
    {"title": "1. Pre-Start Checks",              "steps": ["..."]},
    {"title": "2. Delivery and Materials Handling","steps": ["..."]},
    {"title": "3. Access Equipment Setup",         "steps": ["..."]},
    {"title": "4. Installation Works",             "steps": ["..."]},
    {"title": "5. Cable Termination and Testing",  "steps": ["..."]},
    {"title": "6. Final Checks and Handover",      "steps": ["..."]}
  ]
}

Requirements:
- Each phase must have 3 to 5 steps.
- Phase 1 must include: toolbox talk briefing, asbestos register check before drilling, permit-to-work confirmation if required, assembly point confirmation, and coordination with client IT/network access where relevant.
- Phase 2 must include: delivery vehicle access/parking or loading bay coordination, confirmation of goods lift suitability (or contingency plan), and handling of any displaced existing systems (retain/relocate/decommission).
- Phase 3 must include: maximum working height for access equipment, competency requirements (e.g., PASMA/WAH training), and a rescue plan for work at height.
- Phase 4 must describe the installation methodology (how), not just what is being installed. Focus only on the equipment and solution types listed in the scope, room summaries, and room descriptions above. Include: cable routing/containment and fire-stopping, segregation of data/audio/power, display or screen mounting sequence and safe lifting procedures, rack build sequence (if applicable), network or control system configuration steps specific to the equipment being installed, and sequencing/phasing considerations for an occupied site. Do NOT mention any brand, product, or technology not referenced in the scope or equipment list above. Include room names only where they add clarity.
- Phase 5 must include: labelling convention for all cables and interfaces, confirmation of test equipment calibration, signal path verification and functional testing appropriate to the equipment being installed, and any commissioning steps specific to the installed solution (e.g. network configuration, audio level setting, or display calibration). Do NOT reference any product brand, platform, or protocol not present in the scope above.
- Phase 6 must include: removal of access equipment and waste from the actual work areas, end-user training, and a snagging/defects process before final sign-off.
- Each step is one plain-English sentence. No bullet points, no bold, no markdown.
- Steps must be specific to the project details above. Do not use generic filler.
- Do not add phases, rename phases, or reorder phases.{$retry}
PROMPT;
    }

    // =========================================================================
    // Private — context resolution helpers
    // =========================================================================

    private function resolveSite(array $ctx): string
    {
        $site = trim((string) ($ctx['site_address'] ?? ''));

        return $site !== '' ? $site : 'the project site';
    }

    private function resolveScope(array $ctx): string
    {
        // Accept either key; scope_summary is set by MethodStatementService,
        // project_summary may be set directly by callers who use this prompt standalone.
        $scope = trim((string) ($ctx['scope_summary'] ?? $ctx['project_summary'] ?? ''));

        return $scope !== '' ? $scope : 'AV installation works as per quotation';
    }

    private function resolveActivities(array $ctx): string
    {
        $raw = (array) ($ctx['activities'] ?? []);

        // Convert snake_case activity keys to readable labels.
        $labels = array_map(
            static fn (string $key): string => ucwords(str_replace('_', ' ', $key)),
            array_filter($raw, static fn ($v): bool => is_string($v) && $v !== ''),
        );

        return $labels ? implode(', ', $labels) : 'AV installation';
    }

    private function resolveEquipment(array $ctx): string
    {
        return trim((string) ($ctx['equipment_summary'] ?? ''));
    }

    private function resolveHazards(array $ctx): string
    {
        return trim((string) ($ctx['hazard_summary'] ?? ''));
    }

    private function resolveRooms(array $ctx): string
    {
        $raw = array_filter(
            (array) ($ctx['rooms'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        );

        return $raw ? implode(', ', $raw) : '';
    }

    private function resolveRoomSummaries(array $ctx): string
    {
        return trim((string) ($ctx['room_overview_summaries'] ?? ''));
    }

    private function resolveWorksOverview(array $ctx): string
    {
        return trim((string) ($ctx['works_overview'] ?? ''));
    }

    private function resolveRoomDescriptions(array $ctx): string
    {
        return trim((string) ($ctx['room_descriptions'] ?? ''));
    }
}

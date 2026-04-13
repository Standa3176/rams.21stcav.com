<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt DTO for AI-generated RAMS Method Statement phases.
 *
 * Generates project-specific numbered steps covering the UK AV installation
 * sequence. Step count is dynamic (5–9) based on project complexity.
 * All output is plain professional prose — no markdown, no tables,
 * no invented details.
 *
 * Context keys accepted by build():
 *
 *   site_address         string    — installation site name or address
 *   scope_summary        string    — plain-English description of the works
 *   activities           string[]  — activity keys from EquipmentClassifierService
 *   equipment_summary    string    — brief comma-separated list of main equipment types
 *   hazard_summary       string    — brief comma-separated list of primary hazard categories
 *   rooms                string[]  — affected rooms / areas (optional)
 *   room_overview_summaries string — room summaries (optional)
 *   works_overview       string    — project-level 2–3 sentence executive summary (optional)
 *   room_descriptions    string    — newline-delimited "Room: prose" entries from content pack (optional)
 *   decommission_items   string[]  — item names from decommission scope bucket (optional)
 *   retained_items       string[]  — item names from retained scope bucket (optional)
 *   new_install_items    string[]  — item names from new install scope bucket (optional)
 *   is_retry             bool      — true on second attempt (appends retrySuffix)
 *
 * Expected AI response schema (JSON envelope required by MethodStatementService):
 *   {
 *     "phases": [
 *       { "title": "Step 1 — Arrival & Site Induction", "steps": ["string", ...] },
 *       ...   // 5–9 steps
 *     ]
 *   }
 *
 * NOTE: The JSON key is "phases" (not "steps") for backward compatibility with
 * MethodStatementGeneratorService which reads $response['phases'].
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
        // Up to 9 steps × up to 8 bullets × ~30 words ≈ 2 160 words ≈ ~2 880 tokens.
        // 3 500 provides generous headroom for complex projects.
        return 3500;
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

        // Scope bucket item lists
        $decommItems = array_values(array_filter(
            (array) ($ctx['decommission_items'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));
        $retainItems = array_values(array_filter(
            (array) ($ctx['retained_items'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));
        $newItems    = array_values(array_filter(
            (array) ($ctx['new_install_items'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));

        // Build optional supplementary lines — omitted when empty.
        $equipmentLine        = $equipment        ? "\nKey equipment: {$equipment}"             : '';
        $hazardsLine          = $hazards          ? "\nPrimary hazards: {$hazards}"             : '';
        $roomsLine            = $rooms            ? "\nAffected areas: {$rooms}"                : '';
        $roomSummaryLine      = $roomSummaries    ? "\nRoom summaries: {$roomSummaries}"        : '';
        $worksOverviewLine    = $worksOverview    ? "\nProject overview: {$worksOverview}"      : '';
        $roomDescriptionsLine = $roomDescriptions ? "\nRoom descriptions:\n{$roomDescriptions}" : '';
        $decommLine           = $decommItems      ? "\nDecommission items: " . implode(', ', $decommItems) : '';
        $retainLine           = $retainItems      ? "\nRetained items: "     . implode(', ', $retainItems) : '';
        $newItemsLine         = $newItems         ? "\nNew install items: "  . implode(', ', $newItems)    : '';

        $retry = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
Write a project-specific method statement for the following UK AV installation.

Project details:
Site: {$site}
Scope: {$scope}
Activities: {$activities}{$equipmentLine}{$decommLine}{$retainLine}{$newItemsLine}{$hazardsLine}{$roomsLine}{$roomSummaryLine}{$worksOverviewLine}{$roomDescriptionsLine}

Return ONLY the following JSON structure:
{
  "phases": [
    { "title": "Step 1 — Arrival & Site Induction", "steps": ["..."] },
    { "title": "Step 2 — [Next logical step]",      "steps": ["..."] }
  ]
}

Requirements:
- Generate between 5 and 9 steps total depending on project complexity.
- Step 1 MUST be "Arrival & Site Induction" and include: toolbox talk, asbestos register check, permit-to-work confirmation, assembly point confirmation, PPE check.
- Include a Decommissioning step only if decommission items are listed above. Title it "Step N — Decommissioning & Handback". Reference only the listed decommission items by name.
- Include a Retained Equipment Check step only if retained items are listed. Reference only the listed retained items.
- Include one or more Installation steps referencing the new install items by name. Do not invent any equipment not listed above.
- The penultimate step MUST cover Integration, Testing & Commissioning with signal path verification.
- The final step MUST be Completion & Sign-Off covering removal of access equipment and waste, end-user training, and snagging sign-off.
- Each step must have 4 to 8 bullet points.
- Each bullet point is one plain-English sentence. No markdown, no bold, no symbols.
- Do not reference any brand, product, or technology not present in the scope data above.{$retry}
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

    /**
     * Extract decommission item names from context, filtering empty strings.
     *
     * @return string[]
     */
    private function resolveDecommissionItems(array $ctx): array
    {
        return array_values(array_filter(
            (array) ($ctx['decommission_items'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));
    }

    /**
     * Extract new install item names from context, filtering empty strings.
     *
     * @return string[]
     */
    private function resolveNewInstallItems(array $ctx): array
    {
        return array_values(array_filter(
            (array) ($ctx['new_install_items'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== '',
        ));
    }
}

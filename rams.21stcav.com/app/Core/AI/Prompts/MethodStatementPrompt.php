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
    // Audit M-04 — sentinel-wrap user-controllable fields. The trait
    // owns the constants, the wrapUserData() neutraliser, and the
    // system-message note so every sibling prompt uses the same
    // defence pattern.
    use \App\Core\AI\Prompts\Concerns\WrapsUserData;

    // =========================================================================
    // BasePrompt overrides
    // =========================================================================

    public function systemMessage(): string
    {
        // 260725-rd1 — 3 new rules for parity with the hand-crafted Tilda
        // reference (21CQ29531-05-OPS Rev1.1):
        //   1. Per-room granularity   — every step names the specific room(s)
        //   2. Kit-specific detail    — use specific make + model from the
        //                                equipment list, not generic terms
        //   3. Risk-ID cross-refs     — WITHDRAWN by 260817-r5e (see below)
        //
        // 260817-r5e — the AI must NOT author risk cross-references. Until
        // this task, rule 3 asked the model for an "Associated Risks: …" line
        // AND RamsComplianceUpgradeService::crossReferenceMethodStatementRisks
        // independently derived its own — so every rendered phase carried TWO
        // lines with DIFFERENT RA-IDs (observed in 21CQ30960-OPS Rev 1.0).
        // The deterministic service is now the sole producer; a model-chosen
        // risk cross-reference on a safety document is exactly the thing that
        // must not be improvised. The service also STRIPS any such line the
        // model emits anyway — models do not reliably obey negative
        // instructions, so the prohibition below is defence-in-depth, not the
        // guarantee.
        return implode(' ', [
            'You are writing a professional UK RAMS Method Statement for an AV installation contractor.',
            'Rules:',
            '- Use clear, concise professional language.',
            '- Follow logical installation sequence.',
            '- Do NOT invent equipment or site details.',
            '- Do NOT include tables or markdown.',
            '- Output plain text only.',
            '- Per-room granularity: every step that touches a physical space MUST name the specific room(s) it applies to (e.g. "within Boardroom"). Do not write generic "in all rooms" instructions when a room list is provided.',
            '- Kit-specific detail: when a step references a piece of kit, name the specific make + model from the supplied equipment list (e.g. "Sennheiser TeamConnect Ceiling Mic", not "the microphone"). Only reference kit that appears in the supplied list.',
            '- Risk cross-references are NOT yours to write: never output an "Associated Risks" line, an RA-ID list, or any other risk-register cross-reference. They are derived deterministically from the risk register after generation and any line you write would contradict them. The risk register is supplied to you as context only, so your steps address the right hazards.',
            // 260726-fx4 Task 5 — engineer-feedback grounding.
            '- When site_conditions is provided for a room, cite the relevant conditions in the method step for that room (e.g. wall_construction → "in the plasterboard partition wall"; brackets_required → name the specific bracket model; mounting_heights → quote the millimetre value from finished floor level; cable_routes → follow the engineer-noted route). Do NOT invent conditions that aren\'t in the data.',
            '- ' . self::userDataNote(),
        ]);
    }

    public function maxTokens(): int
    {
        // 260726-rf2: bumped 3500 → 8000. The pre-fix cap truncated on any
        // project with 100+ equipment items + per-room granularity + kit-specific
        // detail + RA-ID cross-references from 260725-rd1 Task 3 (Tilda 21CQ29531
        // record_id 92 blew past 3500 → static fallback fired → generic template
        // shipped in the RAMS). 8000 gives headroom for ~15 rooms × 9 phases with
        // kit-specific detail while staying well inside Claude Sonnet's 8192-token
        // per-response ceiling.
        return 8000;
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

        // Audit M-04 — every user-controllable field is wrapped in sentinel
        // tags before interpolation. `activities` is derived from a classifier
        // enum (not user text) and stays untagged.
        $site          = $this->wrapUserData($this->resolveSite($ctx));
        $scope         = $this->wrapUserData($this->resolveScope($ctx));
        $activities    = $this->resolveActivities($ctx);
        $equipment     = $this->wrapUserData($this->resolveEquipment($ctx));
        $hazards       = $this->wrapUserData($this->resolveHazards($ctx));
        $rooms         = $this->wrapUserData($this->resolveRooms($ctx));
        $roomSummaries = $this->wrapUserData($this->resolveRoomSummaries($ctx));
        $worksOverview    = $this->wrapUserData($this->resolveWorksOverview($ctx));
        $roomDescriptions = $this->wrapUserData($this->resolveRoomDescriptions($ctx));
        $isRetry       = (bool) ($ctx['is_retry'] ?? false);

        // Scope bucket item lists — each item is a user-supplied string
        // (quote-PDF derived), so tag each one individually before joining.
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

        $decommTagged = array_map(fn (string $s): string => $this->wrapUserData($s), $decommItems);
        $retainTagged = array_map(fn (string $s): string => $this->wrapUserData($s), $retainItems);
        $newTagged    = array_map(fn (string $s): string => $this->wrapUserData($s), $newItems);

        // 260726-fx4 Task 5 — engineer-feedback site conditions block. Only
        // included when at least one room has non-empty conditions so we
        // don't waste tokens on an empty JSON scaffold. The whole payload is
        // wrapped as user data (sentinel-tagged) because SiteConditionsBuilder
        // reads from operator-typed survey fields; injection guard applies.
        $siteConditions = (array) ($ctx['site_conditions'] ?? []);
        $siteConditionsLine = '';
        if (! empty($siteConditions)) {
            $rawJson       = json_encode($siteConditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $wrappedJson   = $this->wrapUserData((string) $rawJson);
            $siteConditionsLine = "\nSite conditions (from engineer site survey — cite these verbatim per room, do NOT invent):\n" . $wrappedJson;
        }

        // 260725-rd1 — Risk list with stable RA-IDs, surfaced with the same
        // numbering the docx renderer uses (RA{NN} by hazard array index —
        // see DocxBuilderService::buildRiskAssessment).
        //
        // 260817-r5e — this block is now CONTEXT ONLY. It exists so the steps
        // address the hazards the assessment actually identified; the AI must
        // not cite RA-IDs back at us (RamsComplianceUpgradeService derives the
        // cross-reference deterministically and strips anything the model
        // emits). The header wording no longer invites cross-referencing.
        $riskItems = array_values(array_filter(
            array_map(
                static function ($h): string {
                    if (! is_array($h)) {
                        return is_string($h) ? trim($h) : '';
                    }

                    return trim((string) ($h['hazard'] ?? ($h['name'] ?? '')));
                },
                (array) ($ctx['hazards'] ?? []),
            ),
            static fn (string $s): bool => $s !== '',
        ));
        $riskListLine = '';
        if (! empty($riskItems)) {
            $lines = [];
            foreach ($riskItems as $i => $name) {
                $id = 'RA' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $lines[] = $id . ': ' . $this->wrapUserData($name);
            }
            $riskListLine = "\nRisk register (context only — do NOT cite these RA-IDs in your output):\n" . implode("\n", $lines);
        }

        // Build optional supplementary lines — omitted when empty.
        $equipmentLine        = $equipment        ? "\nKey equipment: {$equipment}"             : '';
        $hazardsLine          = $hazards          ? "\nPrimary hazards: {$hazards}"             : '';
        $roomsLine            = $rooms            ? "\nAffected areas: {$rooms}"                : '';
        $roomSummaryLine      = $roomSummaries    ? "\nRoom summaries: {$roomSummaries}"        : '';
        $worksOverviewLine    = $worksOverview    ? "\nProject overview: {$worksOverview}"      : '';
        $roomDescriptionsLine = $roomDescriptions ? "\nRoom descriptions:\n{$roomDescriptions}" : '';
        $decommLine           = $decommTagged     ? "\nDecommission items: " . implode(', ', $decommTagged) : '';
        $retainLine           = $retainTagged     ? "\nRetained items: "     . implode(', ', $retainTagged) : '';
        $newItemsLine         = $newTagged        ? "\nNew install items: "  . implode(', ', $newTagged)    : '';

        $retry = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
Write a project-specific method statement for the following UK AV installation.

Project details:
Site: {$site}
Scope: {$scope}
Activities: {$activities}{$equipmentLine}{$decommLine}{$retainLine}{$newItemsLine}{$hazardsLine}{$roomsLine}{$roomSummaryLine}{$worksOverviewLine}{$roomDescriptionsLine}{$siteConditionsLine}{$riskListLine}

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
- Per-room granularity: every step that touches a physical space must name the specific room(s) it applies to using the room names from "Affected areas" / "Room descriptions" above. Do not write generic "in all rooms" instructions when a specific room list is available.
- Kit-specific detail: when a step references a piece of kit, name the specific make + model from the "Key equipment" / "New install items" list above (e.g. "the Sennheiser TeamConnect Ceiling Mic Medium Housing unit", not "the microphone"). Only reference kit that appears in the supplied list; do not invent equipment.
- Use room descriptions where provided to keep steps room-specific.
- The penultimate step MUST cover Integration, Testing & Commissioning with signal path verification.
- The final step MUST be Completion & Sign-Off covering removal of access equipment and waste, end-user training, and snagging sign-off.
- If cable routing crosses live-services zones (containment, tray, existing conduit), the Installation step must call out isolation and 'test-before-touch' verification of any existing power/data circuit encountered.
- Any control-system programming or DSP configuration step must specify that engineers work OFF the live signal path (staging PC or bench-programmed) before hot-cutover, and that the client's IT contact is informed before any network device joins the LAN.
- Where new displays, speakers or cabling attach to plant that another trade owns (ceiling grid, partitions, structural steel), the relevant step must reference coordination with that trade before penetration or fixing.
- The Commissioning step must reference power-cycle and network-fail recovery verification for every codec, DSP or control processor deployed.
- Each phase must have 4 to 8 bullet points. Do NOT add an "Associated Risks" bullet or any RA-ID cross-reference — those are generated deterministically from the risk register after your output is parsed.
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

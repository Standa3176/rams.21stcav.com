<?php

namespace App\Core\AI\Prompts;

/**
 * AI prompt for Worksheet install step generation — one call per room.
 *
 * Grounds the AI in structured equipment and survey data from ProjectDataService.
 * The AI is instructed to structure what is provided — never invent scope.
 *
 * Expected JSON response shape:
 *   { "install_steps": "1. Mount display...\n2. Run HDMI cable..." }
 *
 * The $room array may also contain two optional content pack context fields:
 *   description    string — prose paragraph from content pack (optional; use for context only)
 *   works_overview string — project-level executive summary (optional; use for context only)
 *
 * Usage:
 *   $prompt = WorksheetPrompt::forRoom($room, $projectMeta);
 *   $result = AIManager::run($prompt, [], 'claude');
 *   $steps  = $result['install_steps'] ?? null;
 */
class WorksheetPrompt extends BasePrompt
{
    private array $room;
    private array $projectMeta;

    private function __construct(array $room, array $projectMeta)
    {
        $this->room        = $room;
        $this->projectMeta = $projectMeta;
    }

    // ── Named constructor ─────────────────────────────────────────────────────

    /**
     * Factory: create a prompt for a single room.
     *
     * @param  array $room         Room entry from ProjectDataService::resolve()['rooms']
     * @param  array $projectMeta  Project fields from ProjectDataService::resolve()['project']
     * @return static
     */
    public static function forRoom(array $room, array $projectMeta): static
    {
        return new static($room, $projectMeta);
    }

    // ── Overrides ─────────────────────────────────────────────────────────────

    public function systemMessage(): string
    {
        return 'You are a senior UK AV installation expert writing engineer job cards. '
             . 'Use British English spelling throughout. '
             . 'Respond ONLY with valid JSON — no markdown fences, no commentary.';
    }

    public function maxTokens(): int
    {
        return 4096;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    /**
     * Build the user prompt string from room and project context.
     *
     * Receives $context as array (merged by AIManager) but also uses
     * $this->storedContext (set via withContext()) and the constructor data.
     *
     * @param  array $context  Additional context from AIManager (usually empty for Worksheet).
     * @return string
     */
    public function build(array $context = []): string
    {
        $room = $context['room'] ?? $this->room;
        $meta = $context['project'] ?? $this->projectMeta;

        $roomName      = $room['room_name'] ?? $room['name'] ?? 'Unknown Room';
        $projectName   = $meta['name'] ?? 'Unknown Project';
        $clientName    = $meta['client_name'] ?? '';

        // ── Equipment list ────────────────────────────────────────────────────
        $equipment = $room['equipment'] ?? [];
        $equipmentLines = [];
        foreach ($equipment as $item) {
            $qty  = $item['quantity'] ?? 1;
            $name = $item['name'] ?? $item['description'] ?? 'Unknown Item';
            $cat  = $item['category'] ?? '';
            $equipmentLines[] = "  - {$qty}x {$name}" . ($cat ? " [{$cat}]" : '');
        }
        $equipmentBlock = $equipmentLines
            ? implode("\n", $equipmentLines)
            : '  (No equipment listed)';

        // ── Survey fields ─────────────────────────────────────────────────────
        $surveyLines = [];

        if (! empty($room['ceiling_type'])) {
            $surveyLines[] = '  - Ceiling type: ' . $room['ceiling_type'];
        }
        if (! empty($room['ceiling_height_m'])) {
            $surveyLines[] = '  - Ceiling height: ' . $room['ceiling_height_m'] . 'm';
        }
        if (! empty($room['wall_material'])) {
            $surveyLines[] = '  - Wall material: ' . $room['wall_material'];
        }
        if (! empty($room['display_mounting'])) {
            $surveyLines[] = '  - Display mounting: ' . $room['display_mounting'];
        }
        if (! empty($room['display_size_in'])) {
            $surveyLines[] = '  - Display size: ' . $room['display_size_in'] . '"';
        }
        if (! empty($room['speaker_mounting'])) {
            $surveyLines[] = '  - Speaker mounting: ' . $room['speaker_mounting'];
        }
        if (! empty($room['cable_route_desc'])) {
            $surveyLines[] = '  - Cable route: ' . $room['cable_route_desc'];
        }
        if (! empty($room['access_notes'])) {
            $surveyLines[] = '  - Access notes: ' . $room['access_notes'];
        }
        if (! empty($room['notes'])) {
            $surveyLines[] = '  - Room notes: ' . $room['notes'];
        }

        $surveyBlock = $surveyLines
            ? implode("\n", $surveyLines)
            : '  (No survey data available for this room)';

        // ── Content pack context (framing only — AI must not invent from these) ──
        $roomDescription = trim((string) ($room['description']    ?? ''));
        $worksOverview   = trim((string) ($room['works_overview'] ?? ''));

        $descriptionBlock = $roomDescription
            ? "\nROOM DESCRIPTION (use for context only):\n  {$roomDescription}"
            : '';
        $overviewBlock = $worksOverview
            ? "\nPROJECT OVERVIEW (use for context only):\n  {$worksOverview}"
            : '';

        // ── Assemble prompt ───────────────────────────────────────────────────
        return <<<PROMPT
You are an AV installation engineer preparing a job card for site.

Project: {$projectName}
Client: {$clientName}
Room: {$roomName}

EQUIPMENT TO INSTALL IN THIS ROOM:
{$equipmentBlock}

SITE SURVEY DATA FOR THIS ROOM:
{$surveyBlock}{$descriptionBlock}{$overviewBlock}

INSTRUCTIONS:
- List the install steps for this room as a numbered sequence (3–5 steps maximum).
- Base steps ONLY on the equipment and survey data provided above — do not invent items, cable types, or equipment not listed.
- Be concise and practical. Each step should be one sentence.
- Include mounting, cabling, connection, and commissioning actions only if the equipment above requires them.
- If no survey data is available, write steps based on the equipment list alone.

Return ONLY valid JSON with this exact shape:
{ "install_steps": "1. Step one\n2. Step two\n3. Step three" }
PROMPT;
    }
}

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
- List the install steps for this room as a numbered sequence (8–12 steps for a
  typical room; scale up to 14 only when the kit list is large or complex).
- Reference equipment by SHORT, SPECIFIC name in each step that applies to a
  particular item (e.g. "Sony 98″ BZ53L display", "Cisco Room Kit EQ codec",
  "Crestron 1Beyond P20 PTZ camera"). Do NOT use vague phrases like "the
  display", "the codec", "the audio system".
- Cover the install in this logical order, omitting any phase the equipment
  list does not require:
    1. ARRIVAL / SITE READY — confirm room access, kit on site, scope agreed.
    2. CONTAINMENT / FIRST FIX — trunking, conduit, fire-stop where called for.
    3. CABLE PULL — name the runs (HDMI, Cat6, speaker, control) and the
       endpoints in this room.
    4. STRUCTURAL FIXING — wall / ceiling fixings, including substrate
       verification and bracket installation.
    5. EQUIPMENT MOUNT — display, codec, camera, speakers, panels, sensors.
    6. TERMINATION & CONNECTION — connectors, patching, power-on sequence.
    7. CONFIGURATION — IP / DSP / control programming where applicable.
    8. INTEGRATION & TEST — VC test call, signal path, audio levels,
       BYOD presentation.
    9. HANDOVER — labels, as-built notes, client sign-off.
- Each step ONE sentence, ≤25 words. British English. Imperative voice
  ("Mount the Sony 98″ display…"), not gerund ("Mounting the display…").
- Base steps ONLY on the equipment and survey data above — do not invent
  items, cable types, or rooms that are not listed.
- If no survey data is available, write steps from the equipment list alone
  and add a closing step "Confirm fixings, cable routes, and power/network
  drops on arrival before first fix."

Return ONLY valid JSON with this exact shape:
{ "install_steps": "1. Step one\n2. Step two\n3. Step three\n…" }
PROMPT;
    }
}

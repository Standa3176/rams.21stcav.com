<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for summarising room/space work descriptions.
 *
 * Expected input context:
 *   rooms: [
 *     { "room": "Boardroom", "overview": "..." },
 *     ...
 *   ]
 *
 * Expected output JSON:
 *   { "summaries": [ { "room": "Boardroom", "summary": "Room Type: ...\nDisplay: ..." }, ... ] }
 *
 * The summary for each room is a structured, labelled key-value block — one
 * field per line — suitable for inclusion in RAMS, O&M and worksheet documents.
 */
class RoomOverviewSummaryPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode("\n", [
            'You are an AV integration specialist writing structured room summaries for technical documents.',
            '',
            'For each room you receive a phrased overview description. Extract the key AV facts and',
            'return them as a labelled field-per-line summary using ONLY the following field labels',
            '(omit any label where the overview provides no relevant information):',
            '',
            '  Room Type:      — type / capacity description  e.g. "Large Meeting Room (8–10 Persons)"',
            '  Display:        — screen/display device + mounting  e.g. "65\" Samsung Interactive (Wall Mounted)"',
            '  Projector:      — projector + screen if applicable  e.g. "Epson EB-L265F + 120\" Fixed Screen"',
            '  VC System:      — video conferencing bar/codec  e.g. "ClickShare Bar PRO (Under Display)"',
            '  Audio:          — speakers / microphones / PA  e.g. "2x Ceiling Speaker + Microphone Array"',
            '  Control:        — control system / touch panel  e.g. "AMX 7\" Touch Panel"',
            '  Connectivity:   — signal routing / inputs  e.g. "Wireless (USB-C Buttons) + Optional USB"',
            '  Power:          — new socket count  e.g. "2x Socket"',
            '  Data:           — new data point count  e.g. "2x Cat6"',
            '  Accessories:    — ancillary items  e.g. "ClickShare Tray, Cable Management"',
            '',
            'Rules:',
            '- Use ONLY information present in the overview — do NOT invent equipment or specs.',
            '- Omit any field label if the overview has nothing to say about that category.',
            '- Keep each field value concise (one line, under 80 characters).',
            '- Do NOT add introductory sentences, bullet characters, or numbering.',
            '- Output valid JSON only.',
        ]);
    }

    public function maxTokens(): int
    {
        return 1200;
    }

    public function temperature(): float
    {
        return 0.15;
    }

    public function build(array $context = []): string
    {
        $ctx   = array_merge($this->storedContext, $context);
        $rooms = $ctx['rooms'] ?? [];

        // Build room payload, stripping internal fields not needed by AI
        $roomPayload = array_map(function (array $r): array {
            return [
                'room'            => $r['room']            ?? '',
                'overview'        => $r['overview']        ?? '',
                'solution_type'   => $r['solution_type']   ?? null,
                'solution_method' => $r['solution_method'] ?? null,
            ];
        }, $rooms);

        // Remove nulls for brevity
        $roomPayload = array_map(function (array $r): array {
            return array_filter($r, fn ($v) => $v !== null && $v !== '');
        }, $roomPayload);

        $payload = json_encode(array_values($roomPayload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Build optional solution type guidance block
        $hasSolutionType = false;
        foreach ($rooms as $r) {
            if (! empty($r['solution_type'])) { $hasSolutionType = true; break; }
        }
        $solutionGuidance = $hasSolutionType
            ? "\nWhere a solution_type is provided, use it to inform the Room Type label and ensure relevant\nfield labels are included (e.g. for PA System include Audio/Speaker fields; for LED Video Wall\ninclude Pixel Pitch, Cabinet Size etc). Where solution_method is provided, you may reference\nthe typical process steps to infer Control, Infrastructure and Connectivity details.\n"
            : '';

        $exampleSummary = 'Room Type: Small Meeting Room (4-6 Persons)\\nDisplay: 65" Samsung Interactive (Wall Mounted)\\nVC System: ClickShare Bar PRO (Under Display)\\nConnectivity: Wireless (USB-C Buttons) + Optional USB\\nPower: 2x Socket\\nData: 2x Cat6\\nAccessories: ClickShare Tray';

        return <<<PROMPT
Generate a structured AV works summary for each room below.
{$solutionGuidance}
Input (JSON array of rooms with overview text):
{$payload}

Return ONLY this JSON structure.

CRITICAL JSON RULE: The "summary" field must be a single-line JSON string.
Use the two-character escape sequence \\n (backslash followed by n) to represent
line breaks between fields. Do NOT insert actual line breaks / newlines inside
a JSON string value — this produces invalid JSON that cannot be parsed.

{
  "summaries": [
    {
      "room": "Room Name",
      "summary": "Room Type: ...\\nDisplay: ...\\nVC System: ..."
    }
  ]
}

Example of a correctly formatted single-room response:
{
  "summaries": [
    {
      "room": "Small Meeting Room",
      "summary": "{$exampleSummary}"
    }
  ]
}
PROMPT;
    }
}

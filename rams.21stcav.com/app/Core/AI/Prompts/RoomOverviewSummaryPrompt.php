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
 *   {
 *     "summaries": [
 *       {
 *         "room": "Boardroom",
 *         "summary": "- Installation of Sony 98″ display within …\n- Deployment of Crestron Flex Integrator Kit …"
 *       },
 *       ...
 *     ]
 *   }
 *
 * The summary for each room is a list of install-action bullets (one per line,
 * each prefixed with "- ") suitable for inclusion in RAMS, O&M, worksheet, and
 * site survey documents.
 *
 * Phase 22.1 D-01 (2026-05-13): the legacy AI-generated `description` prose
 * field is dropped. Engineers consume the PM-typed `overview` directly as
 * the prose source for downstream services (MethodStatementService::
 * buildRoomDescriptions reads it), keeping the "AI output must be reviewable"
 * principle from CLAUDE.md intact — the AI no longer produces an invisible
 * field that no edit UI exposes.
 */
class RoomOverviewSummaryPrompt extends BasePrompt
{
    // Audit M-04 — the room `overview` field is PM-typed prose (the exact
    // path a hostile author would use to inject instructions), so wrap the
    // whole rooms JSON payload before interpolation.
    use \App\Core\AI\Prompts\Concerns\WrapsUserData;

    public function systemMessage(): string
    {
        return implode("\n", [
            'You are an AV integration specialist writing install-action checklists for technical documents.',
            '',
            'For each room you receive a phrased overview paragraph. Convert it into a list of',
            'install-action bullet points — one concrete action per line — that an engineer can',
            'tick off on site and that drops cleanly into RAMS, O&M, worksheets, and site surveys.',
            '',
            'BULLET RULES:',
            '- One install / provision / integration action per bullet, prefixed with "- " (dash + space).',
            '- Start each bullet with a concrete verb noun-phrase: "Installation of", "Provision of",',
            '  "Deployment of", "Integration with", "Configuration of", "Implementation of".',
            '- Mention the room/space name when the overview names one ("within the Cinnamon room",',
            '  "across Cinnamon and Saffron rooms"). Engineers need to know which room each item lands in.',
            '- British English. Use ″ or "inch" but be consistent across all bullets.',
            '- Drop sales fluff: "the new", "world-class", "cutting-edge", "fully integrated solution".',
            '  Keep only what an engineer needs to plan and verify the install.',
            '- One sentence per bullet, ≤25 words. No nested clauses.',
            '- Do NOT invent kit, brands, sizes, rooms, or features the overview does not mention.',
            '- If the overview hedges ("other sizes are available depending on design"), reflect that',
            '  hedge in a single bullet rather than inventing alternatives.',
            '- 5–12 bullets is typical. Quantity scales with the kit complexity.',
            '',
            'EXAMPLE',
            'OVERVIEW:',
            '  Cinnamon now has a Sony 98" display chosen — other larger sizes are available',
            '  depending on your room design. Cinnamon and Saffron are now using the Crestron Flex',
            '  integrator kit, which also offers full room control from a single Crestron panel,',
            '  wireless BYOD via the Crestron AirMedia platform, and integrates fully with the new',
            '  Crestron 1Beyond cameras and the existing Shure DSP and new Sennheiser ceiling mics.',
            '  I have also added the Crestron Occupancy Sensor into Cinnamon and Saffron, both of',
            '  which will be powered by the Crestron PoE+ switch (which will also power the room',
            '  booking panels too).',
            '',
            'BULLETS:',
            '  - Installation of Sony 98″ display within the Cinnamon room (with provision for alternative larger sizes depending on final room design)',
            '  - Deployment of Crestron Flex Integrator Kit across Cinnamon and Saffron rooms',
            '  - Provision of full room control via a single Crestron touch panel',
            '  - Implementation of wireless BYOD presentation using Crestron AirMedia',
            '  - Integration with Crestron 1Beyond cameras for enhanced video conferencing',
            '  - Integration with existing Shure DSP',
            '  - Installation of Sennheiser ceiling microphones for improved audio capture',
            '  - Installation of Crestron occupancy sensor within Cinnamon and Saffron rooms',
            '  - Provision of power via Crestron PoE+ switch to support occupancy sensors and room booking panels',
            '',
            'Output valid JSON only.',
            '',
            self::userDataNote(),
        ]);
    }

    public function maxTokens(): int
    {
        return 2000;
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

        // Audit M-04 — wrap the whole JSON payload as one block so the
        // JSON shape is preserved for the model but every embedded
        // `room` + `overview` string is inside the untrusted sentinel.
        $rawPayload = json_encode(array_values($roomPayload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload    = $this->wrapUserData((string) $rawPayload);

        // Build optional solution type guidance block
        $hasSolutionType = false;
        foreach ($rooms as $r) {
            if (! empty($r['solution_type'])) { $hasSolutionType = true; break; }
        }
        $solutionGuidance = $hasSolutionType
            ? "\nWhere a solution_type is provided, use it to flavour the bullets so the install actions\nmatch the typical fit-out for that solution. Where solution_method is provided, the listed\nsteps may inspire individual bullets (e.g. \"Configuration of …\", \"Commissioning of …\").\n"
            : '';

        $exampleSummary = '- Installation of 65″ Samsung Interactive display, wall-mounted on the presentation wall\\n- Deployment of ClickShare Bar PRO under the display for wireless video conferencing\\n- Provision of wireless BYOD presentation via USB-C buttons with optional USB connection\\n- Provision of 2× new mains sockets and 2× Cat6 data points at the display and rack locations';

        return <<<PROMPT
Generate an install-action bullet list AV works summary for each room below.
{$solutionGuidance}
Input (JSON array of rooms with overview text):
{$payload}

Return ONLY this JSON structure.

CRITICAL JSON RULE: The "summary" field must be a single-line JSON string.
Use the two-character escape sequence \\n (backslash followed by n) to represent
line breaks between bullets. Do NOT insert actual line breaks / newlines inside
a JSON string value — this produces invalid JSON that cannot be parsed.

{
  "summaries": [
    {
      "room": "Room Name",
      "summary": "- Installation of …\\n- Deployment of …\\n- Provision of …"
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

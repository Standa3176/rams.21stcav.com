<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for summarising room/space work descriptions.
 *
 * Expected input context:
 *   rooms: [
 *     { "room": "Breakout Area", "overview": "..." },
 *     ...
 *   ]
 *
 * Expected output JSON:
 *   { "summaries": [ { "room": "Breakout Area", "summary": "..." }, ... ] }
 */
class RoomOverviewSummaryPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode(' ', [
            'You summarise AV installation room descriptions into concise client-friendly language.',
            'Rules:',
            '- Keep each summary to 1–2 sentences.',
            '- Do NOT invent equipment or rooms.',
            '- Use plain professional English.',
            '- Output JSON only.',
        ]);
    }

    public function maxTokens(): int
    {
        return 800;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function build(array $context = []): string
    {
        $ctx = array_merge($this->storedContext, $context);
        $rooms = $ctx['rooms'] ?? [];

        $payload = json_encode($rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Summarise the following room work descriptions.

Input (JSON array):
{$payload}

Return ONLY this JSON structure:
{
  "summaries": [
    { "room": "Room Name", "summary": "Short 1–2 sentence summary" }
  ]
}
PROMPT;
    }
}

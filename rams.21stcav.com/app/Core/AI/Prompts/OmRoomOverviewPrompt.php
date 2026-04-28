<?php

namespace App\Core\AI\Prompts;

/**
 * Per-room "How the System Works" narrative for the Tier 1 O&M Manual.
 *
 * Constraints baked into the prompt (per Tier 1 spec):
 *   - Plain English, non-technical reader.
 *   - Strictly 80–120 words.
 *   - Only mention equipment present in the supplied list.
 *   - No markdown, no fences — JSON object with a single 'narrative' key.
 *
 * Context shape:
 *   ['room' => 'Meeting Room 201',
 *    'equipment' => ['98″ iiyama Display', 'Logitech Rally Camera', ...]]
 */
class OmRoomOverviewPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return 'You are a senior UK AV installation engineer writing a non-technical room overview '
             . 'for a corporate client O&M Manual. '
             . 'Output JSON only — a single object with key "narrative" containing 80–120 words of '
             . 'plain-English prose. Do NOT mention any equipment not in the supplied list. '
             . 'Do NOT speculate about brand features. No markdown, no fences.';
    }

    public function maxTokens(): int
    {
        return 350;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function build(array $context = []): string
    {
        $context = array_merge($this->storedContext, $context);
        $room    = trim((string) ($context['room'] ?? 'Room'));

        $equipment = is_array($context['equipment'] ?? null) ? $context['equipment'] : [];
        $equipment = array_values(array_filter(
            array_map(static fn ($d) => trim((string) $d), $equipment),
            static fn ($d) => $d !== ''
        ));

        $equipmentList = empty($equipment)
            ? '(no equipment provided)'
            : '- ' . implode("\n- ", $equipment);

        return <<<PROMPT
Room: {$room}

Installed equipment:
{$equipmentList}

Write a single paragraph (80–120 words) explaining, in plain English for a
non-technical client, how this room's AV system works end-to-end. Cover:
  - Inputs / sources (e.g. laptop input, video calls).
  - Processing / control method (e.g. DSP, Teams Rooms appliance).
  - Outputs (display(s), speakers).
  - How a typical user starts a meeting.

Strict rules:
  - Mention only the equipment listed above.
  - No marketing language or speculative features.
  - No markdown, no fences.
  - Output exactly this shape:
    {"narrative": "<your paragraph here>"}
PROMPT;
    }
}

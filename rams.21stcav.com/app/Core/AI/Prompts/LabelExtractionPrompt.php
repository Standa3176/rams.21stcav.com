<?php

namespace App\Core\AI\Prompts;

/**
 * Vision prompt — reads an equipment label photo and returns the structured
 * fields needed by the asset register: part number, serial number, MAC,
 * model. Engineer can correct before save.
 *
 * Usage:
 *   $prompt = (new LabelExtractionPrompt())->setImage($base64, 'image/jpeg');
 *   $extracted = $manager->provider()->execute($prompt);
 */
class LabelExtractionPrompt extends BasePrompt
{
    public function build(array $context = []): string
    {
        return <<<PROMPT
        You are reading the manufacturer label / sticker on a piece of audio-visual hardware.
        The image shows a label printed or stuck onto the equipment.

        Extract the following fields. Return UNKNOWN as the value for any field
        that is not visible or you cannot read with high confidence.

        Required JSON schema (return EXACTLY this shape):
        {
          "part_number":   "string or UNKNOWN",
          "serial_number": "string or UNKNOWN",
          "mac_address":   "string or UNKNOWN (formatted with colons, e.g. 00:1A:2B:3C:4D:5E)",
          "model":         "string or UNKNOWN",
          "manufacturer":  "string or UNKNOWN",
          "confidence":    "high | medium | low"
        }

        Rules:
        - Trim whitespace from each value.
        - Normalise MAC addresses to uppercase with colon separators.
        - DO NOT invent values. Use UNKNOWN if the field is not visible.
        - 'confidence' reflects your overall reading of the label
          (low if blurry / partially obscured / glare).
        - Return ONLY the JSON object — no markdown, no commentary.
        PROMPT;
    }

    public function systemMessage(): string
    {
        return 'You are an expert at reading hardware identification labels '
             . 'on audio-visual equipment. You are accurate and conservative — '
             . 'you mark fields UNKNOWN rather than guess. Respond ONLY with valid JSON.';
    }

    public function maxTokens(): int
    {
        return 512;
    }

    public function temperature(): float
    {
        return 0.1;
    }
}

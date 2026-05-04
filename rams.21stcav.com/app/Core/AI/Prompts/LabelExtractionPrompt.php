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
        You are reading equipment labels in a product photograph for an
        installation engineer. The photo shows a piece of AV hardware (or its
        packaging) with one or more labels visible. Multiple labels may
        appear (TDRA / TCRA / FCC approval stamps, recycling marks, etc.) —
        IGNORE compliance / approval / recycling stamps. Focus on the MAIN
        product identification label, which typically contains a barcode,
        an "S/N:" or "Serial:" prefix, a part / model number, and the
        manufacturer's name or logo.

        Extract these fields, reading TEXT visible in the image. The label
        does not need to fill the frame — read what is legible even if the
        photo includes the desk / keyboard / packaging around it.

        Required JSON schema (return EXACTLY this shape, no other keys):
        {
          "part_number":   "string or UNKNOWN",
          "serial_number": "string or UNKNOWN",
          "mac_address":   "string or UNKNOWN (formatted with colons, e.g. 00:1A:2B:3C:4D:5E)",
          "model":         "string or UNKNOWN",
          "manufacturer":  "string or UNKNOWN",
          "confidence":    "high | medium | low"
        }

        Rules:
        - READ the values you can see. Engineers WILL review and edit before
          saving, so a best-effort reading is more useful than UNKNOWN.
        - Use UNKNOWN ONLY when the field is genuinely not present on the
          label (e.g. consumer products often have no MAC address) OR when
          the text is so obscured/blurry/cut-off that no characters are
          discernible.
        - Trim whitespace from values; normalise MAC addresses to uppercase
          with colon separators.
        - "manufacturer" can be inferred from the company logo, brand name,
          or recycling URL (e.g. "logitech.com/recycling" → "Logitech").
        - "model" is the human-readable product name (e.g. "MX Master 4 for
          Business"); "part_number" is the alphanumeric part code (e.g.
          "910-007617"). They are different fields — populate both when both
          are visible.
        - "serial_number" usually appears next to "S/N:" or "Serial:" — it is
          the per-unit identifier, not a part number.
        - "confidence" — high = label clearly legible, medium = readable
          with some effort, low = mostly illegible or only partial reading.
        - Return ONLY the JSON object — no markdown fences, no commentary.
        PROMPT;
    }

    public function systemMessage(): string
    {
        return 'You are an expert at reading equipment identification labels '
             . 'in installation photos. Engineers always review and edit the '
             . 'extracted values before saving, so a best-effort reading of '
             . 'visible text is more useful than UNKNOWN. Use UNKNOWN only '
             . 'when text is genuinely illegible or absent. Respond ONLY '
             . 'with the requested JSON object.';
    }

    public function maxTokens(): int
    {
        return 512;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    /**
     * Override the global Claude model for label OCR. Sonnet 4.6 (the global
     * default) is too conservative on small/angled labels and consistently
     * returns UNKNOWN, low-confidence even on legible text. Opus 4.7 has
     * materially stronger vision/OCR and is the right model for this single
     * vision-heavy use case. Falls back to the global default if the
     * configured model is invalid (Anthropic returns 400 → AIManager retries
     * once before raising AIGenerationException).
     */
    public function modelOverride(): ?string
    {
        return env('CLAUDE_VISION_MODEL', 'claude-opus-4-7');
    }
}

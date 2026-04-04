<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for generating a cable schedule from pre-extracted equipment lines.
 *
 * The PDF is processed locally before reaching this prompt:
 *   PDF → PdfTextExtractorService → QuoteLineExtractorService → (lines)
 *   $result = AIManager::run(new CableSchedulePrompt($lines));
 *
 * No PDF binary or base64 is ever sent to the AI model.
 *
 * @param string[] $lines  Quantity-prefixed equipment lines, e.g. ["2 Logitech Rally Bar Graphite"]
 */
class CableSchedulePrompt extends BasePrompt
{
    /** @param string[] $lines */
    public function __construct(private readonly array $lines = []) {}

    public function systemMessage(): string
    {
        return 'You are an AV installation expert. '
             . 'Infer cable schedule data from a list of AV equipment line items. '
             . 'Respond ONLY with valid JSON — no markdown, no commentary.';
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function build(array $context = []): string
    {
        $isRetry     = (bool) ($context['is_retry'] ?? false);
        $retrySuffix = $isRetry ? $this->retrySuffix() : '';

        $lineList = empty($this->lines)
            ? '(no equipment lines extracted)'
            : implode("\n", $this->lines);

        return <<<PROMPT
Below is a list of AV equipment line items extracted from a QuoteWerks quote.
Infer the cable runs required to connect this equipment and produce a cable schedule.

EQUIPMENT LINES
---------------
{$lineList}

INSTRUCTIONS
------------
1. Return ONLY valid JSON — no markdown fences, no preamble, no commentary.
2. The JSON must contain a single key "cables" whose value is an array of cable objects.
3. For every cable run you can reasonably infer from the equipment list:
   - cable_id:         sequential reference (e.g. "CAB-001")
   - from_location:    source room/rack/device
   - to_location:      destination room/rack/device
   - cable_type:       e.g. "HDMI", "CAT6", "Speakon", "IEC Power", "SDI", "RS232"
   - cores:            e.g. "4-pair", "2-core", "single", "19-pin"
   - approx_length_m:  estimated length in metres as a number; use null if unknown
   - notes:            any relevant note or null
4. If the equipment list is empty or contains insufficient detail, return: {"cables":[]}

Return only the JSON object.{$retrySuffix}
PROMPT;
    }
}

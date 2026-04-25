<?php

namespace App\Core\AI\Prompts;

/**
 * Tidy raw quote-import line items into clean, document-ready rows.
 *
 * Quote PDFs come from QuoteWerks with all-caps part numbers and verbose
 * marketing descriptions like "SAMSUNG QM55C HG2 4K UHD COMMERCIAL DISPLAY 55
 * INCH". Engineers want short, recognisable phrases on RAMS / worksheets /
 * cable schedules — "Samsung 55″ QM55C display", "Crestron Saros 6.5″
 * speaker — pair", etc.
 *
 * Expected input context:
 *   items: [
 *     { "id": 0, "part_number": "...", "name": "...", "category": "..." },
 *     ...
 *   ]
 *
 * Expected output JSON:
 *   {
 *     "items": [
 *       { "id": 0, "part_number": "CLEANED", "name": "Short clear desc" },
 *       ...
 *     ]
 *   }
 *
 * The id round-trips so the controller can match output rows to input
 * rows — order of returned items is not guaranteed.
 */
class EquipmentLineCleanupPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode("\n", [
            'You normalise AV equipment line items extracted from sales quote PDFs into',
            'clean, short, document-ready rows. Return ONLY valid JSON, no commentary.',
            '',
            'PART NUMBER rules:',
            '- UPPERCASE alphanumeric, hyphens, slashes, dots only.',
            '- Strip stray whitespace, leading dashes, trailing punctuation.',
            '- Leave blank if the input has no real part number (e.g. service lines).',
            '',
            'NAME (description) rules:',
            '- Short. Manufacturer + model/family + key spec (size / channel count / colour /',
            '  pair). 3–8 words is ideal.',
            '- British English. "Pair" not "PR", "inch" written as ″ or "inch" — pick one and',
            '  be consistent across all lines in the response.',
            '- Drop marketing fluff: "Commercial Grade", "4K UHD HDR", "Solution",',
            '  "Includes mounting hardware". Keep what an engineer needs to identify the kit.',
            '- Drop the part number from the description if it is repeated there.',
            '- Pluralise / annotate where helpful: "speaker — pair", "remote × 2".',
            '',
            'EXAMPLES:',
            '  in:  qty=1, part="SAMSUNG QM55C", name="SAMSUNG QM55C HG2 4K UHD COMMERCIAL DISPLAY 55 INCH"',
            '  out: part_number="SAMSUNG-QM55C", name="Samsung 55″ QM55C display"',
            '',
            '  in:  qty=2, part="C2N-CB12-W-T", name="CRESTRON SAROS PD6.5T-W-T-EACH PENDANT SPEAKER 6.5IN WHITE - SUPPLIED IN PAIR"',
            '  out: part_number="C2N-CB12-W-T", name="Crestron Saros 6.5″ pendant speaker — pair"',
            '',
            '  in:  qty=1, part="", name="Project management — 2 days on-site"',
            '  out: part_number="", name="Project management — 2 days on-site"',
            '',
            'Do NOT invent quantities, part numbers, or features that are not implied by the input.',
        ]);
    }

    public function maxTokens(): int
    {
        // ~80 tokens per line × up to 60 lines = 4800.
        return 4096;
    }

    public function temperature(): float
    {
        return 0.1;
    }

    public function build(array $context = []): string
    {
        $ctx   = array_merge($this->storedContext, $context);
        $items = (array) ($ctx['items'] ?? []);

        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id   = (int)    ($item['id']          ?? 0);
            $part = (string) ($item['part_number'] ?? '');
            $name = (string) ($item['name']        ?? '');
            $cat  = (string) ($item['category']    ?? '');
            $qty  = (string) ($item['quantity']    ?? '');

            $lines[] = sprintf(
                '  { "id": %d, "qty": %s, "part_number": %s, "name": %s, "category": %s }',
                $id,
                json_encode($qty,  JSON_UNESCAPED_UNICODE),
                json_encode($part, JSON_UNESCAPED_UNICODE),
                json_encode($name, JSON_UNESCAPED_UNICODE),
                json_encode($cat,  JSON_UNESCAPED_UNICODE),
            );
        }

        $itemsBlock = $lines
            ? "[\n" . implode(",\n", $lines) . "\n]"
            : '[]';

        return <<<PROMPT
Clean up the following AV equipment line items per the rules in the system message.
Return ONLY valid JSON of the shape:
{ "items": [ { "id": 0, "part_number": "...", "name": "..." }, ... ] }

The "id" on each output item MUST match the input id so the caller can pair rows.
Do not omit rows; if a row needs no change, return it with the original values.

INPUT:
{$itemsBlock}
PROMPT;
    }
}

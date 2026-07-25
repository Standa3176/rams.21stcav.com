<?php

namespace App\Core\AI\Prompts;

/**
 * Tidy raw quote-import line descriptions into clean, document-ready phrases.
 *
 * Quote PDFs come from QuoteWerks with verbose marketing descriptions like
 * "SAMSUNG QM55C HG2 4K UHD COMMERCIAL DISPLAY 55 INCH". Engineers want
 * short, recognisable phrases on RAMS / worksheets / cable schedules —
 * "Samsung 55″ QM55C display", "Crestron Saros 6.5″ speaker — pair", etc.
 *
 * SCOPE (260725-fx1): descriptions ONLY. Earlier versions of this prompt
 * also rewrote part numbers, but PMs reported unwanted mutation of the
 * canonical QW SKUs and wanted the button to be a pure description-tidier.
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
 *       { "id": 0, "name": "Short clear desc" },
 *       ...
 *     ]
 *   }
 *
 * The id round-trips so the controller can match output rows to input rows —
 * order of returned items is not guaranteed.
 */
class EquipmentLineCleanupPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode("\n", [
            'You rewrite AV equipment line descriptions extracted from sales quote PDFs into',
            'clean, short, document-ready phrases. Return ONLY valid JSON, no commentary.',
            '',
            'You do NOT touch part numbers. You do NOT return a part_number field. Only names.',
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
            '- Leave service / labour / management lines close to their original wording — those',
            '  are already engineer-readable.',
            '',
            'EXAMPLES:',
            '  in:  qty=1, part="SAMSUNG QM55C", name="SAMSUNG QM55C HG2 4K UHD COMMERCIAL DISPLAY 55 INCH"',
            '  out: name="Samsung 55″ QM55C display"',
            '',
            '  in:  qty=2, part="C2N-CB12-W-T", name="CRESTRON SAROS PD6.5T-W-T-EACH PENDANT SPEAKER 6.5IN WHITE - SUPPLIED IN PAIR"',
            '  out: name="Crestron Saros 6.5″ pendant speaker — pair"',
            '',
            '  in:  qty=1, part="", name="Project management — 2 days on-site"',
            '  out: name="Project management — 2 days on-site"',
            '',
            'Do NOT invent quantities, part numbers, or features that are not implied by the input.',
        ]);
    }

    public function maxTokens(): int
    {
        // Descriptions-only output is ~40 tokens per line (was ~80 when we
        // also emitted part_number). Controller batches at 40 rows per call
        // so per-request response is ~1600 tokens well inside 4096.
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

            // part_number + category + qty are context for the model to
            // pick a good description — they're NOT rewritten in the output.
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
Rewrite the "name" field of each of the following AV equipment lines per the
rules in the system message. DO NOT modify or return part numbers.

Return ONLY valid JSON of the shape:
{ "items": [ { "id": 0, "name": "..." }, ... ] }

The "id" on each output item MUST match the input id so the caller can pair rows.
Do not omit rows; if a name needs no change, return it with the original text.

INPUT:
{$itemsBlock}
PROMPT;
    }
}

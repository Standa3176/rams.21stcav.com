<?php

namespace App\Core\AI\Prompts;

/**
 * Convert a free-form AV scope narrative into clean install-action bullets.
 *
 * Sales / pre-sales people write room scope as flowing prose ("Cinnamon now
 * has a Sony 98" display chosen — other larger sizes are available …").
 * Engineers want each install action as its own line item that can be ticked
 * off and that drops cleanly into RAMS / O&M / site-survey planned-works
 * blocks.
 *
 * Expected input context:
 *   text: the raw narrative paragraph(s)
 *   project_name: optional, used for tone but not invented into bullets
 *
 * Expected output JSON:
 *   { "bullets": ["Installation of …", "Provision of …", "Integration with …", …] }
 *
 * Each bullet is one concrete install / provision / integration action. The
 * AI must NOT invent kit, rooms, or features that the prose does not mention.
 */
class WorksBulletsPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode("\n", [
            'You convert a free-form AV scope narrative into a list of clean,',
            'install-action bullet points suitable for RAMS, O&M and site-survey',
            'planned-works blocks. Respond ONLY with valid JSON.',
            '',
            'BULLET RULES:',
            '- One install / provision / integration action per bullet.',
            '- Start each bullet with a concrete verb noun-phrase: "Installation of",',
            '  "Provision of", "Deployment of", "Integration with", "Configuration of".',
            '- Mention the room/space ("within the Cinnamon room", "across Cinnamon and',
            '  Saffron rooms") whenever the prose names one. Engineers need to know which',
            '  room each item lands in.',
            '- British English. Use "inch" or ″ but be consistent across all bullets.',
            '- Drop sales fluff: "the new", "world-class", "cutting-edge". Keep only',
            '  what an engineer needs to plan and verify the install.',
            '- One sentence per bullet, ≤25 words. No nested clauses.',
            '- Do NOT invent kit, brands, sizes, rooms, or features that the prose does',
            '  not mention. If the prose hedges ("other sizes are available"), reflect',
            '  that hedge in a single bullet rather than inventing alternatives.',
            '',
            'EXAMPLE',
            'INPUT:',
            '  Cinnamon now has a Sony 98" display chosen - other larger sizes are',
            '  available depending on your room design.',
            '  Cinnamon and Saffron are now using the Crestron Flex integrator kit, which',
            '  also offers full room control from a single Crestron panel, wireless BYOD',
            '  via the Creston AirMedia platform, and integrates fully with the new',
            '  Crestron 1Beyond cameras and the existing Shure DSP and new Sennheiser',
            '  ceiling mics.',
            '  I have also added the Crestron Occupancy Sensor into Cinnamon and Saffron,',
            '  both of which will be powered by the Crestron PoE+ switch (which will also',
            '  power the room booking panels too).',
            '',
            'OUTPUT:',
            '  Installation of Sony 98″ display within the Cinnamon room (with provision for alternative larger sizes depending on final room design)',
            '  Deployment of Crestron Flex Integrator Kit across Cinnamon and Saffron rooms',
            '  Provision of full room control via a single Crestron touch panel',
            '  Implementation of wireless BYOD presentation using Crestron AirMedia',
            '  Integration with Crestron 1Beyond cameras for enhanced video conferencing',
            '  Integration with existing Shure DSP',
            '  Installation of Sennheiser ceiling microphones for improved audio capture',
            '  Installation of Crestron occupancy sensor within Cinnamon and Saffron rooms',
            '  Provision of power via Crestron PoE+ switch to support occupancy sensors and room booking panels',
        ]);
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function build(array $context = []): string
    {
        $ctx     = array_merge($this->storedContext, $context);
        $text    = trim((string) ($ctx['text']         ?? ''));
        $project = trim((string) ($ctx['project_name'] ?? ''));

        $projBlock = $project !== ''
            ? "PROJECT: {$project}\n\n"
            : '';

        return <<<PROMPT
{$projBlock}Convert the following AV scope narrative into install-action bullets per the
rules in the system message. Return ONLY valid JSON of the shape:
{ "bullets": ["…", "…", …] }

NARRATIVE:
{$text}
PROMPT;
    }
}

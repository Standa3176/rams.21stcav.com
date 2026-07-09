<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for generating an AI Scope of Works paragraph from room overview data.
 *
 * Expected context keys:
 *   project_name  string   — project name
 *   client_name   string   — client name (optional)
 *   site_address  string   — site address (optional)
 *   room_lines    string   — bullet-point room/solution summary
 *
 * Expected AI response JSON:
 *   { "scope_of_works": "Plain-text paragraph describing the full scope...", "works_overview": "2–3 sentence executive summary..." }
 */
class ScopeOfWorksPrompt extends BasePrompt
{
    // Audit M-04 — project / client / site / room-lines are all quote-PDF
    // sourced or PM-typed, so wrap them before interpolation.
    use \App\Core\AI\Prompts\Concerns\WrapsUserData;

    public function systemMessage(): string
    {
        return implode(' ', [
            'You are a senior UK AV project manager writing a professional Scope of Works paragraph for a RAMS document.',
            'Write clear, concise, professional British English.',
            'Do not use bullet points, headings, or markdown.',
            'Do NOT invent equipment or details not provided.',
            'Output valid JSON only.',
            'Also produce a `works_overview` field: a 2–3 sentence executive summary of the overall project.',
            'The works_overview is shorter than scope_of_works — suitable for a cover page or document header.',
            'No bullet points, no markdown. Plain British English prose.',
            self::userDataNote(),
        ]);
    }

    public function maxTokens(): int
    {
        return 900;
    }

    public function temperature(): float
    {
        return 0.3;
    }

    public function build(array $context = []): string
    {
        $ctx = array_merge($this->storedContext, $context);

        // Audit M-04 — every user-controllable field wrapped in sentinels.
        $projectName = $this->wrapUserData((string) ($ctx['project_name'] ?? 'this project'));
        $clientName  = $this->wrapUserData((string) ($ctx['client_name']  ?? ''));
        $siteAddress = $this->wrapUserData((string) ($ctx['site_address'] ?? ''));
        $roomLines   = $this->wrapUserData((string) ($ctx['room_lines']   ?? ''));

        $clientLine  = $clientName  ? "\nClient: {$clientName}"  : '';
        $siteLine    = $siteAddress ? "\nSite: {$siteAddress}"   : '';

        return <<<PROMPT
Generate a Scope of Works paragraph for a UK AV installation RAMS document.

Project: {$projectName}{$clientLine}{$siteLine}

Room / solution breakdown:
{$roomLines}

Return ONLY the following JSON structure:
{"scope_of_works": "...single professional paragraph here...", "works_overview": "...2-3 sentence executive summary here..."}

Requirements:
- 4 to 7 sentences covering the overall works across all rooms listed above.
- Be specific to the solutions and rooms listed — do not use vague filler.
- Plain British English prose, no bullet points, no markdown, no headings.
- Do not invent equipment or details not present in the room breakdown above.
- works_overview: 2–3 sentences maximum. Shorter and higher-level than scope_of_works. Suitable for a cover page. No bullet points.
PROMPT;
    }
}

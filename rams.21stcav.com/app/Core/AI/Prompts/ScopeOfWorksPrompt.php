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
 *   { "scope_of_works": "Plain-text paragraph describing the full scope..." }
 */
class ScopeOfWorksPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return implode(' ', [
            'You are a senior UK AV project manager writing a professional Scope of Works paragraph for a RAMS document.',
            'Write clear, concise, professional British English.',
            'Do not use bullet points, headings, or markdown.',
            'Do NOT invent equipment or details not provided.',
            'Output valid JSON only.',
        ]);
    }

    public function maxTokens(): int
    {
        return 600;
    }

    public function temperature(): float
    {
        return 0.3;
    }

    public function build(array $context = []): string
    {
        $ctx         = array_merge($this->storedContext, $context);
        $projectName = trim((string) ($ctx['project_name'] ?? 'this project'));
        $clientName  = trim((string) ($ctx['client_name']  ?? ''));
        $siteAddress = trim((string) ($ctx['site_address'] ?? ''));
        $roomLines   = trim((string) ($ctx['room_lines']   ?? ''));

        $clientLine  = $clientName  ? "\nClient: {$clientName}"  : '';
        $siteLine    = $siteAddress ? "\nSite: {$siteAddress}"   : '';

        return <<<PROMPT
Generate a Scope of Works paragraph for a UK AV installation RAMS document.

Project: {$projectName}{$clientLine}{$siteLine}

Room / solution breakdown:
{$roomLines}

Return ONLY the following JSON structure:
{"scope_of_works": "...single professional paragraph here..."}

Requirements:
- 4 to 7 sentences covering the overall works across all rooms listed above.
- Be specific to the solutions and rooms listed — do not use vague filler.
- Plain British English prose, no bullet points, no markdown, no headings.
- Do not invent equipment or details not present in the room breakdown above.
PROMPT;
    }
}

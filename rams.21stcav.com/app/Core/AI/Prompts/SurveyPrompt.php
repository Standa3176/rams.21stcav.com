<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for AI-assisted site survey analysis.
 *
 * Given completed survey room data, the AI identifies:
 *  - AV installation risks and considerations per room
 *  - Infrastructure requirements (power, network, cable routes)
 *  - Recommendations for equipment placement
 *
 * Context keys:
 *   project_name  string
 *   site_address  string
 *   rooms         array   — array of room objects from site_survey_rooms
 *   general_notes string  — surveyor's general notes
 */
class SurveyPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return 'You are an experienced AV systems surveyor for UK commercial installations. '
             . 'Analyse survey data and provide structured installation recommendations. '
             . 'Respond ONLY with valid JSON.';
    }

    public function maxTokens(): int
    {
        return 4096;
    }

    public function build(array $context = []): string
    {
        $projectName  = $context['project_name']  ?? 'AV Installation Project';
        $site         = $context['site_address']  ?? 'Site Address';
        $generalNotes = $context['general_notes'] ?? '';
        $rooms        = json_encode($context['rooms'] ?? [], JSON_PRETTY_PRINT);
        $isRetry      = (bool) ($context['is_retry'] ?? false);
        $retrySuffix  = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
Analyse this site survey and provide installation recommendations for an AV project.

PROJECT: {$projectName}
SITE:    {$site}

GENERAL NOTES
-------------
{$generalNotes}

ROOM SURVEY DATA
----------------
{$rooms}

INSTRUCTIONS
------------
1. Analyse each room and identify AV installation considerations.
2. Flag any infrastructure deficiencies (power, network, ceiling type, access).
3. Recommend equipment placement and cable routing approaches.
4. Highlight any H&S risks for the installation team.
5. Return ONLY valid JSON — no markdown, no preamble.

REQUIRED JSON SCHEMA
--------------------
{
  "summary": "string",
  "overall_risk_level": "Low|Medium|High",
  "rooms": [
    {
      "room_name": "string",
      "av_suitability": "Good|Fair|Poor",
      "considerations": ["string"],
      "infrastructure_issues": ["string"],
      "recommendations": ["string"],
      "installation_risks": ["string"],
      "estimated_cable_route_complexity": "Simple|Moderate|Complex"
    }
  ],
  "site_wide_recommendations": ["string"],
  "pre_installation_actions": ["string"]
}{$retrySuffix}
PROMPT;
    }
}

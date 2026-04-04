<?php

namespace App\Core\AI\Prompts;

/**
 * Prompt for generating a complete RAMS document from project scope + hazard library.
 *
 * Context keys accepted by build():
 *   project_name      string   — required
 *   client_name       string   — required
 *   site_address      string   — required
 *   works_description string   — required
 *   hazard_ids        int[]    — IDs from engineering_knowledge_library to include
 *   ppe               string[] — PPE items pre-selected by engineer
 *   persons_at_risk   string[] — persons at risk pre-selected by engineer
 *   project_ref       string   — optional document reference
 *   doc_author        string   — optional author name
 *   is_retry          bool     — true on second attempt
 */
class RamsPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return 'You are a UK Health & Safety expert specialising in AV (Audio-Visual) '
             . 'installation projects. Your responses must comply with UK HSE legislation. '
             . 'Respond ONLY with valid JSON — no markdown fences, no commentary.';
    }

    public function maxTokens(): int
    {
        return 6144;
    }

    public function build(array $context = []): string
    {
        $projectName  = $context['project_name']      ?? 'AV Installation Project';
        $client       = $context['client_name']        ?? 'Client';
        $site         = $context['site_address']       ?? 'Site Address';
        $scope        = $context['works_description']  ?? 'AV installation works as per quote.';
        $ref          = $context['project_ref']        ?? 'RAMS-001';
        $author       = $context['doc_author']         ?? '';
        $ppe          = implode(', ', $context['ppe']            ?? []);
        $persons      = implode(', ', $context['persons_at_risk'] ?? []);
        $isRetry      = (bool) ($context['is_retry'] ?? false);

        // Hazards from the library are passed as pre-resolved text items
        $hazardBlock  = $this->formatHazards($context['hazards'] ?? []);

        $retrySuffix  = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
You are generating a complete RAMS (Risk Assessment and Method Statement) for a UK AV installation project.

PROJECT DETAILS
---------------
Project Name:      {$projectName}
Project Ref:       {$ref}
Client:            {$client}
Site Address:      {$site}
Works Description: {$scope}
Document Author:   {$author}

PRE-SELECTED HAZARDS FROM HAZARD LIBRARY
-----------------------------------------
The following hazards MUST be included. Do not remove or skip any.
{$hazardBlock}

ADDITIONAL CONTEXT
------------------
PPE (pre-selected):           {$ppe}
Persons at Risk (pre-selected): {$persons}

INSTRUCTIONS
------------
1. Return ONLY valid JSON — no markdown fences, no preamble.
2. Expand each hazard with site-specific control measures relevant to AV installation.
3. Generate a detailed, phase-by-phase method statement matching the scope of works.
4. Fill in all required UK regulations applicable to this installation type.
5. Risk scores: likelihood (1–5) × severity (1–5) = risk rating.

REQUIRED JSON SCHEMA
--------------------
{
  "project": {
    "ref": "string",
    "name": "string",
    "client": "string",
    "site_address": "string",
    "works_description": "string",
    "subtitle": "string",
    "document_status": "For Construction",
    "doc_author": "string"
  },
  "hazards": [
    {
      "id": 1,
      "hazard": "string",
      "consequences": ["string"],
      "pre_likelihood": 1-5,
      "pre_severity": 1-5,
      "controls": ["string"],
      "post_likelihood": 1-5,
      "post_severity": 1-5,
      "persons_at_risk": ["string"]
    }
  ],
  "ppe": ["string"],
  "persons_at_risk": ["string"],
  "regulations": ["string"],
  "method_statement": {
    "introduction": "string",
    "scope_of_works": [{"room": "string", "drawing_ref": "string", "equipment": "string"}],
    "exclusions": [{"item": "string", "responsible_party": "string", "description": "string"}],
    "general_procedures": ["string"],
    "phases": [
      {
        "name": "string",
        "description": "string",
        "procedures": ["string"]
      }
    ],
    "quality_checks": ["string"]
  }
}{$retrySuffix}
PROMPT;
    }

    private function formatHazards(array $hazards): string
    {
        if (empty($hazards)) {
            return '(No hazards pre-selected — generate appropriate hazards for the scope of works.)';
        }

        $lines = [];
        foreach ($hazards as $i => $h) {
            $name        = is_array($h) ? ($h['name'] ?? $h['hazard'] ?? '') : (string) $h;
            $description = is_array($h) ? ($h['description'] ?? '') : '';
            $lines[]     = ($i + 1) . '. ' . $name . ($description ? ' — ' . $description : '');
        }

        return implode("\n", $lines);
    }
}

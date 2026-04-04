<?php

namespace App\Core\AI\Prompts;

/**
 * Prompts for O&M Manual generation — two-pass approach:
 *   Pass 1: Extract project data + equipment list from QuoteWerks PDF
 *   Pass 2: Generate full O&M manual content from the extracted structured data
 *
 * Use OmManualPrompt::forExtraction() or OmManualPrompt::forContent().
 */
class OmManualPrompt extends BasePrompt
{
    private string $mode;

    private function __construct(string $mode)
    {
        $this->mode = $mode;
    }

    // ── Named constructors ────────────────────────────────────────────────────

    /**
     * Pass 1: Extraction prompt — used with context data supplied by
     * OmManualGeneratorService after local PDF extraction. No PDF binary
     * is sent to the AI; the prompt receives pre-processed text only.
     */
    public static function forExtraction(): static
    {
        return new static('extraction');
    }

    /**
     * Pass 2: Generate full O&M content from the structured extraction result.
     */
    public static function forContent(): static
    {
        return new static('content');
    }

    // ── Overrides ─────────────────────────────────────────────────────────────

    public function systemMessage(): string
    {
        return match ($this->mode) {
            'extraction' => 'You are an AV project document analyser. '
                          . 'Extract structured data from QuoteWerks PDF quotes. '
                          . 'Respond ONLY with valid JSON.',
            'content'    => 'You are a senior AV systems engineer writing O&M manuals '
                          . 'for UK commercial installations. '
                          . 'Respond ONLY with valid JSON — no markdown, no commentary.',
            default      => parent::systemMessage(),
        };
    }

    public function maxTokens(): int
    {
        return match ($this->mode) {
            'content' => 8192,
            default   => 4096,
        };
    }

    public function build(array $context = []): string
    {
        return match ($this->mode) {
            'extraction' => $this->buildExtractionPrompt($context),
            'content'    => $this->buildContentPrompt($context),
            default      => throw new \LogicException("Unknown OmManualPrompt mode: {$this->mode}"),
        };
    }

    // ── Prompt builders ───────────────────────────────────────────────────────

    private function buildExtractionPrompt(array $context): string
    {
        $isRetry     = (bool) ($context['is_retry'] ?? false);
        $retrySuffix = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
You are an AV project document analyser. Extract all project information and equipment from this QuoteWerks quote PDF.

Return ONLY a valid JSON object with this exact structure (no markdown, no explanation):
{
  "project_name": "string",
  "client_name": "string",
  "site_address": "string",
  "project_ref": "string or null",
  "rooms": [
    {
      "name": "string",
      "floor": "string or null",
      "equipment": [
        {
          "name": "string",
          "model": "string or null",
          "manufacturer": "string or null",
          "quantity": 1,
          "category": "Display|Camera|Microphone|Speaker|DSP|Controller|Switch|Cabling|Mount|Other"
        }
      ]
    }
  ],
  "notes": "string or null"
}{$retrySuffix}
PROMPT;
    }

    private function buildContentPrompt(array $context): string
    {
        $projectName = $context['project_name']  ?? 'AV Installation Project';
        $client      = $context['client_name']   ?? 'Client';
        $site        = $context['site_address']  ?? 'Site Address';
        $ref         = $context['project_ref']   ?? '';
        $rooms       = json_encode($context['rooms'] ?? [], JSON_PRETTY_PRINT);
        $notes       = $context['notes']         ?? '';
        $isRetry     = (bool) ($context['is_retry'] ?? false);
        $retrySuffix = $isRetry ? $this->retrySuffix() : '';

        return <<<PROMPT
You are generating a complete O&M (Operations & Maintenance) Manual for a UK AV installation.

PROJECT DETAILS
---------------
Project Name: {$projectName}
Project Ref:  {$ref}
Client:       {$client}
Site Address: {$site}
Notes:        {$notes}

INSTALLED EQUIPMENT (by room)
-----------------------------
{$rooms}

INSTRUCTIONS
------------
1. Return ONLY valid JSON — no markdown fences, no preamble.
2. For every equipment item provide: description, key specs, installation notes,
   operational guide, maintenance schedule, troubleshooting, and manufacturer contacts.
3. Include a system overview and general maintenance schedule.
4. All content must be professional, accurate, and appropriate for UK commercial AV.

REQUIRED JSON SCHEMA
--------------------
{
  "project": {
    "name": "string",
    "ref": "string",
    "client": "string",
    "site_address": "string",
    "issue_date": "string",
    "revision": "A"
  },
  "system_overview": "string",
  "rooms": [
    {
      "name": "string",
      "description": "string",
      "equipment": [
        {
          "name": "string",
          "model": "string",
          "manufacturer": "string",
          "quantity": 1,
          "category": "string",
          "description": "string",
          "key_specifications": ["string"],
          "installation_notes": "string",
          "operation_guide": "string",
          "maintenance_schedule": "string",
          "troubleshooting": [{"symptom": "string", "solution": "string"}],
          "manufacturer_contact": "string"
        }
      ]
    }
  ],
  "general_maintenance": {
    "daily": ["string"],
    "weekly": ["string"],
    "monthly": ["string"],
    "annual": ["string"]
  },
  "support_contacts": [
    {"role": "string", "name": "string", "phone": "string", "email": "string"}
  ]
}{$retrySuffix}
PROMPT;
    }
}

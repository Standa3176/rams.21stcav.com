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
    // Audit M-04 — sentinel-wrap user-controllable fields interpolated into
    // the content-generation prompt. Extraction pass doesn't interpolate
    // untrusted text (the PDF is a separate content channel), but the
    // system-message note is added there too so the model still knows how
    // to interpret sentinels if any downstream caller ever adds them.
    use \App\Core\AI\Prompts\Concerns\WrapsUserData;

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
                          . 'Respond ONLY with valid JSON. ' . self::userDataNote(),
            'content'    => 'You are a senior AV systems engineer writing O&M manuals '
                          . 'for UK commercial installations. '
                          . 'Respond ONLY with valid JSON — no markdown, no commentary. '
                          // 260726-fx4 Task 6 — engineer-feedback grounding.
                          . 'When site_conditions is provided for a room, cite the relevant '
                          . 'conditions in the per-equipment installation notes (mounting_heights '
                          . 'quoted verbatim in mm FFL — e.g. "Display mounted at 1900mm from '
                          . 'finished floor level"; wall_construction + brackets_required drive '
                          . 'the maintenance-access notes; access_notes drives ceiling-void / '
                          . 'floor-box procedures). Do NOT invent conditions that aren\'t in the '
                          . 'data. '
                          . self::userDataNote(),
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

    /**
     * Build the Pass 2 content generation prompt.
     *
     * Context keys used:
     *   - project_name, client_name, site_address, project_ref, notes (strings)
     *   - scope_of_works (optional string) — rendered as PROJECT SCOPE block when non-empty
     *   - rooms (array) — each room may include a 'description' field with the reviewed
     *     AV solution narrative for that space; used to ground system description and
     *     operating procedures per room
     *   - is_retry (bool) — appends retry suffix when true
     */
    private function buildContentPrompt(array $context): string
    {
        // Audit M-04 — every user-controllable field is wrapped in sentinel
        // tags before interpolation. `$rooms` is JSON-encoded structured
        // data extracted from the quote PDF, so its string fields carry
        // the same trust risk as the top-level project fields.
        $projectName  = $this->wrapUserData((string) ($context['project_name']  ?? 'AV Installation Project'));
        $client       = $this->wrapUserData((string) ($context['client_name']   ?? 'Client'));
        $site         = $this->wrapUserData((string) ($context['site_address']  ?? 'Site Address'));
        $ref          = $this->wrapUserData((string) ($context['project_ref']   ?? ''));
        $notes        = $this->wrapUserData((string) ($context['notes']         ?? ''));
        $scopeOfWorks = $this->wrapUserData((string) ($context['scope_of_works'] ?? ''));

        // Rooms is structured JSON — wrap the whole payload as one block
        // rather than field-by-field so the JSON shape is preserved for
        // the model but the whole thing is marked untrusted.
        $rawRooms     = json_encode($context['rooms'] ?? [], JSON_PRETTY_PRINT);
        $rooms        = $this->wrapUserData((string) $rawRooms);

        $isRetry      = (bool) ($context['is_retry'] ?? false);
        $retrySuffix  = $isRetry ? $this->retrySuffix() : '';

        $scopeBlock = $scopeOfWorks !== ''
            ? "\nPROJECT SCOPE\n-------------\n{$scopeOfWorks}\n"
            : '';

        // 260726-fx4 Task 6 — engineer-feedback site conditions per room,
        // sentinel-wrapped as user data. Omit the block entirely when empty
        // so we don't waste tokens on empty scaffolding.
        $siteConditions      = (array) ($context['site_conditions'] ?? []);
        $siteConditionsBlock = '';
        if (! empty($siteConditions)) {
            $rawJson             = json_encode($siteConditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $wrappedJson         = $this->wrapUserData((string) $rawJson);
            $siteConditionsBlock = "\nSITE CONDITIONS (per room, from engineer site survey — cite verbatim, do NOT invent)\n"
                                 . "---------------------------------------------------------------------------------\n"
                                 . "{$wrappedJson}\n";
        }

        return <<<PROMPT
You are generating a complete O&M (Operations & Maintenance) Manual for a UK AV installation.

PROJECT DETAILS
---------------
Project Name: {$projectName}
Project Ref:  {$ref}
Client:       {$client}
Site Address: {$site}
Notes:        {$notes}
{$scopeBlock}{$siteConditionsBlock}
INSTALLED EQUIPMENT (by room)
-----------------------------
{$rooms}

INSTRUCTIONS
------------
1. Return ONLY valid JSON — no markdown fences, no preamble.
2. For every equipment item provide FOUR fields and only these four:
   installation (physical mounting, electrical, network),
   operation (day-to-day user actions),
   maintenance (schedule + tasks combined into a single narrative),
   warnings (safety limits, isolation requirements, known issues).
   Do NOT emit troubleshooting, key_specifications, support_contacts,
   daily/weekly/monthly/annual ops arrays, or installation_notes — those
   fields were removed in 260726-fx4 Task 7 because they forced the model
   to hallucinate specifics it didn't have (invented support phone
   numbers, generic "wipe with microfiber cloth quarterly" filler).
3. Where a room `description` field is provided in the equipment data, use
   it to ground the system description and operating procedure for that
   room — this is the reviewed AV solution narrative for that space.
4. Include a system overview and general maintenance schedule.
5. All content must be professional, accurate, and appropriate for UK
   commercial AV.

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
          "installation": "string",
          "operation": "string",
          "maintenance": "string",
          "warnings": "string"
        }
      ]
    }
  ],
  "general_maintenance": {
    "daily": ["string"],
    "weekly": ["string"],
    "monthly": ["string"],
    "annual": ["string"]
  }
}{$retrySuffix}
PROMPT;
    }
}

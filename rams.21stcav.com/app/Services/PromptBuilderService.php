<?php

namespace App\Services;

class PromptBuilderService
{
    /**
     * Build a structured AI prompt from the RAMS form data.
     *
     * Expected keys in $formData:
     *   - works_description  (string)
     *   - hazards            (array of hazard name strings)
     *   - site_address       (string)
     *   - client_name        (string)
     *
     * @param  array  $formData
     * @return string
     */
    public function build(array $formData): string
    {
        $worksDescription = $formData['works_description'] ?? 'Not specified';
        // Cap works_description — long descriptions push the prompt over Claude's stable
        // response threshold, causing truncated JSON. 1,200 chars is ample for scope context.
        $worksDescription = substr($worksDescription, 0, 1200);

        $siteAddress      = $formData['site_address']      ?? 'Not specified';
        $clientName       = $formData['client_name']        ?? 'Not specified';
        $hazards          = $formData['hazards']            ?? [];

        // AV standard fallback hazards — ensures RAMS are always valid even if
        // the form is submitted with no hazards ticked
        if (empty($hazards)) {
            $hazards = [
                'Working at Height',
                'Manual Handling',
                'Electrical Equipment',
                'Use of Power Tools',
                'Cable Trip Hazards',
                'Dust Generation',
                'Occupied Premises',
            ];
        }

        $hazardList = implode("\n", array_map(
            fn (int $i, string $h) => "  " . ($i + 1) . ". {$h}",
            array_keys($hazards),
            array_values($hazards)
        ));

        $retrySuffix = isset($formData['_retry_suffix'])
            ? "\n\n" . trim($formData['_retry_suffix'])
            : '';

        return <<<PROMPT
You are a UK Health & Safety expert specialising in AV (Audio-Visual) installation work.

Generate a complete Risk Assessment and Method Statement (RAMS) for the project details below.

PROJECT DETAILS
---------------
Client name       : {$clientName}
Site address      : {$siteAddress}
Works description : {$worksDescription}

IDENTIFIED HAZARDS
------------------
{$hazardList}

INSTRUCTIONS
------------
1. Return ONLY valid JSON — no markdown code fences, no preamble, no commentary.
2. The JSON MUST match exactly the schema defined below.
3. For every hazard listed above, generate a hazard entry with ALL of the following:
   - A unique numeric `id` (starting at 1)
   - `hazard`: the hazard name as provided
   - `consequences`: array of 2–4 realistic consequence strings
   - `pre_likelihood`:  integer 1–5 (before controls applied)
   - `pre_severity`:    integer 1–5 (before controls applied)
   - `controls`: array of EXACTLY 4–6 specific, actionable control measures
       • Each control must be relevant to AV installation work in the UK
       • Each control must reference at least one applicable UK regulation or approved
         code of practice (e.g. PUWER 1998, MHSWR 1999, WAHR 2005, CDM 2015,
         Manual Handling Operations Regulations 1992, Electricity at Work Regulations 1989,
         COSHH 2002, HSE guidance HSG150, etc.)
   - `post_likelihood`: integer 1–5 (after controls applied — MUST be lower than pre_likelihood)
   - `post_severity`:   integer 1–5 (after controls applied — MUST be ≤ pre_severity)
4. Populate `ppe` with an array of strings listing all PPE required across the full scope of works.
5. Populate `persons_at_risk` with an array of strings (e.g. "AV Installers", "Site Visitors").
6. Populate `regulations` with an array of the key UK regulations applicable to the overall works.
7. Set project.ref to a plausible short reference code (e.g. "RAMS-001").
8. Set project.subtitle to a concise one-line identifier combining the site location, client name,
   and type of installation — e.g. "Queens Road 3 | Southwark Council | Full AV Installation".
9. Set project.document_status to "For Construction" unless the works are clearly at design/tender stage,
   in which case use "For Review".
10. Populate method_statement fully. Only include scope_of_works items, phases, and procedures
    that are explicitly described in the works_description above. Do not include phases or equipment
    not mentioned (e.g. if programming is not in scope, omit that phase).

JSON SCHEMA (return data matching this structure — no extra top-level keys):
{
  "project": {
    "ref":               "string",
    "name":              "string — a short descriptive project name",
    "client":            "string",
    "site_address":      "string",
    "works_description": "string",
    "subtitle":          "string — e.g. 'Queens Road 3 | Southwark Council | Full AV Installation'",
    "document_status":   "string — typically 'For Construction' or 'For Review'"
  },
  "hazards": [
    {
      "id":               1,
      "hazard":           "string",
      "consequences":     ["string"],
      "pre_likelihood":   3,
      "pre_severity":     4,
      "controls":         ["string"],
      "post_likelihood":  1,
      "post_severity":    2
    }
  ],
  "ppe":             ["string"],
  "persons_at_risk": ["string"],
  "regulations":     ["string"],
  "method_statement": {
    "introduction":       "string — 2-4 sentence overview of the installation approach and sequence",
    "scope_of_works":     [{"room": "string — room or area name", "drawing_ref": "string — or 'N/A'", "equipment": "string — equipment and works to be carried out"}],
    "exclusions":         [{"item": "string", "responsible_party": "string", "description": "string — what is excluded and why"}],
    "general_procedures": ["string — applies to all phases, e.g. site induction, safe working practices"],
    "phases":             [{"name": "string — e.g. 'Phase 1: First Fix'", "description": "string — brief overview of this phase", "procedures": ["string — individual sequential step"]}],
    "quality_checks":     ["string — inspection or test to be carried out on completion"]
  }
}

Return only the JSON object. Do not include any text before or after it.{$retrySuffix}
PROMPT;
    }

    /**
     * Build a prompt instructing the AI to extract project data from an uploaded
     * QuoteWerks PDF (and optional site drawings) and generate a full RAMS.
     *
     * @param  bool        $hasDrawings  True when drawing files have been supplied.
     * @param  string|null $retrySuffix  Appended on retry attempts.
     * @return string
     */
    public function buildFromFiles(bool $hasDrawings = false, ?string $retrySuffix = null): string
    {
        $drawingInstruction = $hasDrawings
            ? "You have also been provided with one or more site drawings or floor plans. "
              . "Study them carefully and identify any additional hazards visible in the drawings "
              . "(e.g. working at height, confined spaces, floor penetrations, ceiling voids, "
              . "rack/equipment locations, cable routes, proximity to other services). "
              . "Incorporate those hazards into the hazards array alongside those derived from the quote. "
              . "Use drawing references (sheet number, revision, or filename) when populating "
              . "the drawing_ref column of scope_of_works where applicable."
            : "No site drawings have been supplied. Generate hazards based solely on the quote content. "
              . "Set drawing_ref to 'N/A' for all scope_of_works rows.";

        $retrySuffixText = $retrySuffix ? "\n\n" . trim($retrySuffix) : '';

        return <<<PROMPT
You are a UK Health & Safety expert specialising in AV (Audio-Visual) installation work.

You have been provided with a QuoteWerks sales quote PDF for an AV installation project.
Your task is to extract all project information from the quote and generate a complete
Risk Assessment and Method Statement (RAMS) in the exact JSON format described below.

{$drawingInstruction}

EXTRACTION INSTRUCTIONS
-----------------------
From the quote PDF, extract:
- Client / company name
- Site address or delivery address
- A short descriptive project name (derive from the scope of equipment or works)
- A project reference code (use the QuoteWerks quote number if visible, otherwise use "RAMS-001")
- A works description: summarise the full scope of AV works described in the quote in 3-6 sentences.
  Include equipment types (displays, AV racks, cabling, control systems, etc.),
  mounting methods, cable routes where apparent, and commissioning tasks.
- A subtitle combining the site location, client name and installation type,
  e.g. "Queens Road 3 | Southwark Council | Full AV Installation"

If any of the above cannot be found in the quote, use these fallbacks:
- project.ref:          "RAMS-001"
- project.name:         "AV Installation"
- project.client:       "Client"
- project.site_address: "See quote"
- project.subtitle:     "AV Installation Project"

IMPORTANT — FIELDS NOT IN QUOTE
---------------------------------
The following fields cannot be extracted from the quote. Do NOT invent values for them:
- Engineering team members
- Emergency contact names and telephone numbers
- Document author

SCOPE CONSTRAINT — CRITICAL
-----------------------------
Only include in scope_of_works, exclusions, and method statement phases the equipment,
systems, and works that are actually quoted and sold. Do NOT include phases or procedures
for items not in the quote scope. For example:
- If programming or commissioning is NOT on the quote, omit a programming phase.
- If structured cabling is NOT quoted, do not include a structured cabling scope item.
- If the quote only covers supply and install of displays, reflect only that scope.
Always derive scope directly from what is itemised or described in the quote.

HAZARD GENERATION INSTRUCTIONS
-------------------------------
Based on the scope of AV works described in the quote and any site drawings provided,
identify ALL relevant hazards. At minimum include hazards for:
- Electrical work (cable routing, equipment installation, rack wiring)
- Manual handling (lifting screens, racks, equipment)
- Working at height (ceiling/wall mounting, cable management, raised platforms)
- Use of power tools and hand tools
- Dust generation (drilling, chasing)
- Slips, trips and falls (trailing cables, cluttered access routes)
- Occupied premises (working around staff, students, or members of the public)
Include additional hazards appropriate to the specific scope of this project.

For every hazard, generate:
- A unique numeric id (starting at 1)
- hazard: the hazard name (concise, 2-6 words)
- consequences: array of 2-4 realistic consequence strings
- pre_likelihood:  integer 1-5 (likelihood before controls applied)
- pre_severity:    integer 1-5 (severity before controls applied)
- controls: array of EXACTLY 4-6 specific, actionable control measures, each referencing
  at least one applicable UK regulation or approved code of practice
  (e.g. PUWER 1998, MHSWR 1999, WAHR 2005, CDM 2015,
  Manual Handling Operations Regulations 1992,
  Electricity at Work Regulations 1989, COSHH 2002, HSE guidance HSG150, etc.)
- post_likelihood: integer 1-5 (MUST be lower than pre_likelihood)
- post_severity:   integer 1-5 (MUST be <= pre_severity)

Populate ppe with all PPE items required for the full scope of works.
Populate persons_at_risk with all persons who may be affected.
Populate regulations with the key UK regulations applicable to the overall works.

Set project.document_status to "For Construction" unless the quote is clearly at tender/design stage.

OUTPUT FORMAT
-------------
Return ONLY valid JSON matching this exact schema — no markdown fences, no preamble, no commentary:

{
  "project": {
    "ref":               "string",
    "name":              "string",
    "client":            "string",
    "site_address":      "string",
    "works_description": "string",
    "subtitle":          "string — e.g. 'Queens Road 3 | Southwark Council | Full AV Installation'",
    "document_status":   "string — typically 'For Construction' or 'For Review'"
  },
  "hazards": [
    {
      "id":               1,
      "hazard":           "string",
      "consequences":     ["string"],
      "pre_likelihood":   3,
      "pre_severity":     4,
      "controls":         ["string"],
      "post_likelihood":  1,
      "post_severity":    2
    }
  ],
  "ppe":             ["string"],
  "persons_at_risk": ["string"],
  "regulations":     ["string"],
  "method_statement": {
    "introduction":       "string — 2-4 sentence overview of the installation approach and sequence",
    "scope_of_works":     [{"room": "string — room or area name", "drawing_ref": "string — or 'N/A'", "equipment": "string — equipment and works to be carried out"}],
    "exclusions":         [{"item": "string", "responsible_party": "string", "description": "string — what is excluded and why"}],
    "general_procedures": ["string — applies to all phases, e.g. site induction, safe working practices"],
    "phases":             [{"name": "string — e.g. 'Phase 1: First Fix'", "description": "string — brief overview of this phase", "procedures": ["string — individual sequential step"]}],
    "quality_checks":     ["string — inspection or test to be carried out on completion"]
  }
}

Return only the JSON object. Do not include any text before or after it.{$retrySuffixText}
PROMPT;
    }


    /**
     * Build a text-only prompt for the PDF-to-text pipeline.
     *
     * Receives pre-extracted quote text and a structured equipment list.
     * No PDF or image blocks — plain text only, which is faster and more reliable.
     *
     * @param  string      $quoteText   Cleaned text extracted from the QuoteWerks PDF
     * @param  array       $equipment   Structured equipment list from EquipmentExtractorService
     * @param  string|null $retrySuffix Appended on retry attempts
     * @return string
     */
    /**
     * Build a compact structured prompt from a parsed QuoteWerks quote.
     *
     * $parsedQuote shape (from QuoteParserService::parse()):
     *   client, site, ref, equipment[], tasks[], rooms[]
     *
     * Claude NEVER receives raw quote text — only clean structured fields.
     * This is the recommended architecture: extract locally, prompt minimally.
     */
    public function buildFromText(string $quoteText, array $equipment, ?string $retrySuffix = null): string
    {
        // This signature is kept for interface compatibility.
        // If a parsedQuote is passed via $equipment as ['_parsed' => true, ...], use it directly.
        // Otherwise fall back to condensed raw text (legacy path).
        if (! empty($equipment['_parsed'])) {
            return $this->buildFromParsedQuote($equipment, $retrySuffix);
        }

        // Legacy path — condense raw text and send structured equipment list
        return $this->buildFromCondensedText($quoteText, $equipment, $retrySuffix);
    }

    /**
     * Build prompt from a fully parsed QuoteParserService result.
     * This is the preferred path — no raw quote text reaches Claude.
     */
    public function buildFromParsedQuote(array $parsed, ?string $retrySuffix = null): string
    {
        $client = $parsed['client'] ?: 'Unknown Client';
        $site   = $parsed['site']   ?: 'See quote';
        $ref    = $parsed['ref']    ?: 'RAMS-001';

        // Format equipment list
        $equipLines = [];
        foreach (($parsed['equipment'] ?? []) as $item) {
            $qty  = $item['qty'] > 1 ? "{$item['qty']}x " : '';
            $loc  = ! empty($item['location']) ? " ({$item['location']})" : '';
            $equipLines[] = "- {$qty}{$item['description']}{$loc}";
        }
        $equipBlock = empty($equipLines)
            ? '- (no equipment identified — generate hazards based on client/site context)'
            : implode("\n", $equipLines);

        // Format tasks list — cap at 15 tasks to keep prompt concise
        $taskLines = [];
        foreach (array_slice($parsed['tasks'] ?? [], 0, 15) as $task) {
            $taskLines[] = "- {$task}";
        }
        $taskBlock = empty($taskLines)
            ? '- Install and commission AV equipment as listed above'
            : implode("\n", $taskLines);

        // AV standard fallback hazards injected into the prompt when parser
        // finds no tasks (ensures RAMS always has a valid hazard set)
        $fallbackHazards = empty($parsed['tasks'])
            ? "\nNOTE: Include at minimum these standard AV hazards: Working at Height, "
              . "Manual Handling, Electrical Equipment, Use of Power Tools, Cable Trip Hazards, "
              . "Dust Generation, Occupied Premises."
            : '';

        // Format rooms list
        $rooms     = $parsed['rooms'] ?? [];
        $roomBlock = empty($rooms)
            ? '(derive from equipment locations above)'
            : implode(', ', $rooms);

        $retrySuffixText = $retrySuffix ? "\n\n" . trim($retrySuffix) : '';

        return <<<PROMPT
You are a UK Health & Safety expert specialising in AV installation.

Generate a RAMS JSON document for the project below.

PROJECT:
Client   : {$client}
Site     : {$site}
Ref      : {$ref}
Rooms    : {$roomBlock}

EQUIPMENT:
{$equipBlock}

INSTALLATION TASKS:
{$taskBlock}

{$fallbackHazards}
RULES:
1. Return ONLY the JSON object — no markdown, no explanation, nothing else.
2. works_description: max 3 sentences. introduction: max 2 sentences.
3. Each hazard control: max 20 words, must cite a UK regulation.
4. Each procedure step: max 15 words.
5. Generate at least 7 hazards: electrical, manual handling, working at height,
   power tools, dust, slips/trips, occupied premises, plus equipment-specific risks.
6. post_likelihood MUST be lower than pre_likelihood. post_severity MUST be <= pre_severity.
7. The JSON MUST end with a closing } character. If you are running low on space,
   finish the current array item, close all open [ and { with ] and }, and stop.
   A shorter but valid JSON is better than a long truncated one.

JSON SCHEMA (return this structure exactly):
{
  "project": {
    "ref": "string",
    "name": "string — short project name",
    "client": "string",
    "site_address": "string",
    "works_description": "string — max 3 sentences",
    "subtitle": "string — Site | Client | AV Installation",
    "document_status": "For Construction"
  },
  "hazards": [
    {
      "id": 1,
      "hazard": "string — 2-5 words",
      "consequences": ["string — max 10 words"],
      "pre_likelihood": 3,
      "pre_severity": 4,
      "controls": ["string — control with UK regulation ref, max 20 words"],
      "post_likelihood": 1,
      "post_severity": 2
    }
  ],
  "ppe": ["string"],
  "persons_at_risk": ["string"],
  "regulations": ["string"],
  "method_statement": {
    "introduction": "string — max 2 sentences",
    "scope_of_works": [{"room": "string", "drawing_ref": "N/A", "equipment": "string — brief"}],
    "exclusions": [{"item": "string", "responsible_party": "Client", "description": "string"}],
    "general_procedures": ["string — max 12 words"],
    "phases": [{"name": "string", "description": "string — 1 sentence", "procedures": ["string — max 12 words"]}],
    "quality_checks": ["string — max 12 words"]
  }
}

Start with { and end with }{$retrySuffixText}
PROMPT;
    }

    /**
     * Legacy path: condense raw quote text and build prompt.
     * Used when QuoteParserService has not been run upstream.
     */
    private function buildFromCondensedText(string $quoteText, array $equipment, ?string $retrySuffix = null): string
    {
        $equipLines = [];
        foreach ($equipment as $item) {
            $line = "- [{$item['type']}] {$item['model']}";
            if (! empty($item['location'])) {
                $line .= " ({$item['location']})";
            }
            $equipLines[] = $line;
        }
        $equipBlock = empty($equipLines)
            ? '(derive from quote text below)'
            : implode("\n", $equipLines);

        // Hard cap — prevents token overflow
        $quoteText = $this->condenseQuoteText($quoteText, 4000);

        $retrySuffixText = $retrySuffix ? "\n\n" . trim($retrySuffix) : '';

        return <<<PROMPT
You are a UK Health & Safety expert specialising in AV installation.

Analyse the condensed QuoteWerks text and equipment list. Generate a RAMS JSON document.

CRITICAL: Return ONLY valid JSON. No markdown, no explanation. Max string lengths:
works_description=3 sentences, introduction=2 sentences, controls=20 words, procedures=12 words.
If you run out of tokens, close all open { and [ immediately.

QUOTE TEXT:
{$quoteText}

EQUIPMENT:
{$equipBlock}

Return this exact JSON structure (start with {, end with }):
{
  "project": {"ref":"string","name":"string","client":"string","site_address":"string","works_description":"string — max 3 sentences","subtitle":"string","document_status":"For Construction"},
  "hazards": [{"id":1,"hazard":"string","consequences":["string"],"pre_likelihood":3,"pre_severity":4,"controls":["string with UK reg"],"post_likelihood":1,"post_severity":2}],
  "ppe": ["string"],
  "persons_at_risk": ["string"],
  "regulations": ["string"],
  "method_statement": {"introduction":"string","scope_of_works":[{"room":"string","drawing_ref":"N/A","equipment":"string"}],"exclusions":[{"item":"string","responsible_party":"Client","description":"string"}],"general_procedures":["string"],"phases":[{"name":"string","description":"string","procedures":["string"]}],"quality_checks":["string"]}
}{$retrySuffixText}
PROMPT;
    }

    /**
     * Condense quote text preserving highest-value lines (equipment, refs, client info).
     */
    private function condenseQuoteText(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        $lines  = preg_split('/\r?\n/', $text);
        $scored = [];

        foreach ($lines as $line) {
            $lower = strtolower(trim($line));
            if (strlen($lower) < 3) continue;

            $score = 0;
            if (preg_match('/\d+/', $line))                                       $score += 2;
            if (preg_match('/client|customer|company|site|address|deliver/i', $line)) $score += 3;
            if (preg_match('/quote|ref|number|order/i', $line))                   $score += 3;
            if (preg_match('/display|screen|mount|camera|mic|speaker|rack|cable|switch|controller/i', $line)) $score += 4;
            if (preg_match('/install|supply|commission|configure|connect/i', $line)) $score += 2;
            if (preg_match('/sony|samsung|lg|logitech|yealink|biamp|qsc|shure|crestron|extron|chief/i', $line)) $score += 3;
            if (preg_match('/^(terms|conditions|page|prepared|issued|vat reg)/i', $lower)) $score -= 5;

            $scored[] = ['line' => $line, 'score' => $score, 'pos' => strpos($text, $line)];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $kept = []; $length = 0;
        foreach ($scored as $item) {
            $lineLen = strlen($item['line']) + 1;
            if ($length + $lineLen > $maxChars) break;
            $kept[]  = $item;
            $length += $lineLen;
        }

        usort($kept, fn($a, $b) => $a['pos'] <=> $b['pos']);

        return implode("\n", array_column($kept, 'line')) . "\n[...condensed]";
    }


    /**
     * Build a prompt instructing the AI to extract a cable schedule from a QuoteWerks PDF.
     * Returns a JSON object with a "cables" array.
     */
    public function buildForCableSchedule(): string
    {
        return <<<PROMPT
You are an AV (Audio-Visual) installation expert.

You have been provided with a QuoteWerks sales quote PDF.
Extract every cable run implied or listed in the quote and return them as structured data.

For each cable run, identify:
- cable_id:        a short reference (e.g. "C01", "C02") — generate sequentially if not in quote
- from_location:   the source location (room name, rack, device)
- to_location:     the destination location (room name, rack, device, outlet)
- cable_type:      the cable type (e.g. "Cat6A", "HDMI 2.0", "Speakon", "Multicore", "SC Fibre", "LSOH Mains")
- cores:           number of cores or pairs where applicable (e.g. "4 pair", "2 core", "1 pair") — null if not applicable
- approx_length_m: estimated length in metres (integer or decimal) — estimate from scope if not stated, null if impossible
- notes:           any relevant notes (e.g. "routed in trunking", "concealed in ceiling void") — null if none

Return ONLY a valid JSON object with a single "cables" key containing the array:

{
  "cables": [
    {
      "cable_id": "C01",
      "from_location": "string",
      "to_location": "string",
      "cable_type": "string",
      "cores": "string or null",
      "approx_length_m": 10.5,
      "notes": "string or null"
    }
  ]
}

If no cable information can be extracted, return: {"cables": []}
Return only the JSON object. No markdown, no preamble, no commentary.
PROMPT;
    }
}

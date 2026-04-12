<?php

namespace App\Core\AI\Prompts;

/**
 * AI prompt for generating pre-install check questions per survey room.
 *
 * Called once per room by GenerateSurveyQuestionsJob. Receives room context
 * from the project's reviewed data: solution type, equipment list, and scope summaries.
 *
 * Expected JSON response shape:
 *   { "questions": ["Is there a power outlet within 1m of the display position?", ...] }
 *
 * Questions must be pre-install verification checks only — not open-ended design
 * questions. The AI must not invent scope beyond what is provided.
 *
 * Usage:
 *   $prompt = new SurveyQuestionsPrompt();
 *   $result = AIManager::run($prompt, [
 *       'solution_type_slug' => 'conferencing',
 *       'checklist_lines'    => ['Power within 1m of display', 'Wall confirmed solid'],
 *       'equipment'          => [['quantity' => 1, 'name' => '65" Display', 'category' => 'Display']],
 *       'works_overview'     => 'Installation of conferencing system...',
 *       'room_description'   => 'Boardroom with existing ceiling void.',
 *       'room_summary'       => 'Solution: Conferencing | Display: 65"',
 *   ]);
 *   $questions = $result['questions'] ?? [];
 */
class SurveyQuestionsPrompt extends BasePrompt
{
    // ── Overrides ─────────────────────────────────────────────────────────

    public function systemMessage(): string
    {
        return 'You are a senior UK AV installation engineer generating pre-install site verification checks. '
             . 'Use British English spelling throughout. '
             . 'Respond ONLY with valid JSON — no markdown fences, no commentary.';
    }

    public function maxTokens(): int
    {
        return 1024;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    // ── Prompt builder ────────────────────────────────────────────────────

    /**
     * Build the user prompt from per-room context.
     *
     * @param  array  $context  Keys: solution_type_slug, checklist_lines, equipment,
     *                                works_overview, room_description, room_summary
     * @return string
     */
    public function build(array $context = []): string
    {
        $ctx = array_merge($this->storedContext, $context);

        $solutionSlug    = $ctx['solution_type_slug'] ?? 'general';
        $checklistLines  = (array) ($ctx['checklist_lines'] ?? []);
        $equipment       = (array) ($ctx['equipment'] ?? []);
        $worksOverview   = trim((string) ($ctx['works_overview'] ?? ''));
        $roomDescription = trim((string) ($ctx['room_description'] ?? ''));
        $roomSummary     = trim((string) ($ctx['room_summary'] ?? ''));

        // ── Equipment block ───────────────────────────────────────────────
        $equipmentLines = [];
        foreach ($equipment as $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty  = $item['quantity'] ?? $item['qty'] ?? 1;
            $name = $item['name'] ?? $item['description'] ?? 'Unknown Item';
            $cat  = $item['category'] ?? '';
            $equipmentLines[] = "  - {$qty}x {$name}" . ($cat ? " [{$cat}]" : '');
        }
        $equipmentBlock = $equipmentLines
            ? implode("\n", $equipmentLines)
            : '  (No equipment listed for this room)';

        // ── Survey checklist block ────────────────────────────────────────
        $checklistBlock = $checklistLines
            ? implode("\n", array_map(fn ($l) => "  - {$l}", $checklistLines))
            : '  (No checklist lines available)';

        // ── Optional context blocks ───────────────────────────────────────
        $overviewBlock = $worksOverview
            ? "\nPROJECT SCOPE (use for context only — do not invent items):\n  {$worksOverview}"
            : '';

        $descriptionBlock = $roomDescription
            ? "\nROOM DESCRIPTION:\n  {$roomDescription}"
            : '';

        $summaryBlock = $roomSummary
            ? "\nROOM SUMMARY:\n  {$roomSummary}"
            : '';

        return <<<PROMPT
You are an AV installation engineer preparing a pre-installation site check for this room.

SOLUTION TYPE: {$solutionSlug}

EXISTING SURVEY CHECKLIST FOR THIS SOLUTION TYPE:
{$checklistBlock}

EQUIPMENT TO BE INSTALLED IN THIS ROOM:
{$equipmentBlock}{$overviewBlock}{$descriptionBlock}{$summaryBlock}

INSTRUCTIONS:
- Generate 4–8 pre-install verification check questions for this room.
- Questions must verify physical site conditions BEFORE installation begins (e.g. power availability, structural suitability, cable route clearance, access constraints).
- Base questions ONLY on the solution type, checklist, and equipment provided above.
- Do NOT invent equipment, scope, or conditions not mentioned above.
- Do NOT ask open-ended design questions — only yes/no verifiable site conditions.
- Use British English spelling.
- Return a JSON array (not an object) of question strings.

Return ONLY valid JSON with this exact shape:
{ "questions": ["Question one?", "Question two?", "Question three?"] }
PROMPT;
    }
}

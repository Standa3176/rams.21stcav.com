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
You are a senior UK AV installation engineer writing a pre-installation site check
for ONE specific room. The engineer will tick yes / no / other for each question
on a tablet, on-site, before installation begins.

SOLUTION TYPE: {$solutionSlug}

SOLUTION-TYPE CHECKLIST (use these as a strong anchor — pick the items that apply
given the actual equipment and adapt them to the kit and room):
{$checklistBlock}

EQUIPMENT TO BE INSTALLED IN THIS ROOM (for each item, generate at least one
install-readiness question that names the item — e.g. mention "Samsung 65"
display" not "the display"):
{$equipmentBlock}{$overviewBlock}{$descriptionBlock}{$summaryBlock}

INSTRUCTIONS:
- Generate 6–10 pre-install verification questions tailored to THIS room and
  THIS equipment. Quantity scales with kit complexity.
- Reference specific equipment by short, recognisable name in each question that
  applies to a particular item (e.g. "Cisco Room Kit EQ", "75″ NEC ME552",
  "Q-SYS Core 8Flex"). Do NOT use vague phrases like "the display" or
  "the equipment".
- Cover these dimensions across the question set (omit a dimension only if no
  item in the equipment list relates to it):
    1. MOUNTING — wall material / weight rating / structural suitability /
       fixing type confirmed for each mounted item
    2. POWER — circuit available, distance to socket, isolation point,
       PoE budget on the network switch where PoE devices are listed
    3. NETWORK — port count and live patching, VLAN, distance to switch,
       PoE class for IP devices
    4. CABLE ROUTING — path type, distance, fire-stop requirements, dressing
    5. SIGHT LINES & COVERAGE — viewing distance for displays, mic pickup
       zones for microphones, speaker spread for speakers
    6. EXISTING INFRASTRUCTURE — kit being retained, integrated with, or
       displaced (only relevant when scope mentions "existing" or upgrade)
- Phrase questions as concrete yes/no checks an engineer can verify with eyes,
  a tape measure, or a voltage tester. Avoid open-ended design questions.
- Avoid duplicate questions — each question should add new information.
- Do NOT invent equipment, scope, or site conditions not mentioned above.
- Use British English spelling and AV industry terminology
  (e.g. "first fix", "containment", "PoE", "PSU", "DSP", "HDBaseT").
- Return a JSON array (not an object) of question strings, in priority order
  (mounting and power first, soft checks last).

Return ONLY valid JSON with this exact shape (no markdown, no commentary):
{ "questions": ["Question one?", "Question two?", "Question three?"] }
PROMPT;
    }
}

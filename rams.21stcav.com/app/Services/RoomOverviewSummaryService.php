<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\RoomOverviewSummaryPrompt;
use App\Exceptions\AIGenerationException;
use Illuminate\Support\Facades\Log;

class RoomOverviewSummaryService
{
    /**
     * @param  array  $roomOverviews  [['room' => '', 'overview' => '', 'works_summary' => ''], ...]
     * @return array  same structure with updated works_summary bullets
     *
     * Phase 22.1 closure (Plan 07): the canonical persistence key is
     * `works_summary` (single source of truth per D-07 in 22.1-CONTEXT.md).
     * The AI's JSON output key is still `summary` (RoomOverviewSummaryPrompt
     * contract — unchanged), but this service translates AI `summary` →
     * canonical `works_summary` before returning. Callers persist the rows
     * verbatim into `extracted_data['room_overviews']`.
     *
     * Phase 22.1 D-01: the legacy `description` field is not produced — the
     * AI prompt no longer instructs the model to emit it, and downstream
     * services (MethodStatementService::buildRoomDescriptions) read PM-typed
     * `overview` directly.
     */
    public function summarize(array $roomOverviews): array
    {
        $rows = array_values(array_filter(
            $roomOverviews,
            fn ($r) => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        ));

        if (empty($rows)) {
            return $roomOverviews;
        }

        $roomsForAi = array_values(array_filter(
            array_map(fn ($r) => [
                'room'     => (string) ($r['room'] ?? ''),
                'overview' => (string) ($r['overview'] ?? ''),
            ], $rows),
            fn ($r) => trim((string) ($r['overview'] ?? '')) !== ''
        ));

        if (empty($roomsForAi)) {
            // No overviews to summarise — return unchanged rows with empty
            // works_summary. NOT a fallback situation (AI wasn't even asked),
            // so we don't set the _summary_fallback flag here.
            return array_map(function ($r) {
                $r['works_summary'] = '';
                return $r;
            }, $roomOverviews);
        }

        try {
            $prompt  = (new RoomOverviewSummaryPrompt())->withContext(['rooms' => $roomsForAi]);
            $result  = AIManager::run($prompt, ['rooms' => $roomsForAi]);
            $decoded = is_string($result) ? json_decode($result, true) : $result;

            $summaries = [];
            foreach (($decoded['summaries'] ?? []) as $item) {
                $room = trim((string) ($item['room'] ?? ''));
                if ($room !== '') {
                    $summaries[$room] = [
                        'summary' => trim((string) ($item['summary'] ?? '')),
                    ];
                }
            }

            return array_map(function ($r) use ($summaries) {
                $room     = (string) ($r['room'] ?? '');
                if ($room !== '' && isset($summaries[$room]) && $summaries[$room]['summary'] !== '') {
                    // AI ran and returned real content — clear any fallback marker.
                    $r['works_summary']     = $summaries[$room]['summary'];
                    $r['_summary_fallback'] = false;
                } else {
                    // AI ran but returned empty for this row → treat as fallback.
                    $r['works_summary']     = $this->fallbackSummary((string) ($r['overview'] ?? ''));
                    $r['_summary_fallback'] = true;
                }
                return $r;
            }, $roomOverviews);
        } catch (AIGenerationException $e) {
            Log::warning('RoomOverviewSummaryService: AI summary failed, using fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        // AI unavailable — mark every row as _summary_fallback so downstream
        // renderers can badge "AI unavailable — click Generate to retry"
        // instead of silently rendering a plausible-looking "Works: ..." line.
        return array_map(function ($r) {
            $r['works_summary']     = $this->fallbackSummary((string) ($r['overview'] ?? ''));
            $r['_summary_fallback'] = true;
            return $r;
        }, $roomOverviews);
    }

    /**
     * Fallback works_summary when the AI is unavailable or returned empty.
     *
     * Quick task 260726-fx4 Task 4 — returns an EMPTY STRING instead of the
     * old "Works: <first sentence>" pseudo-summary. That prefix looked like
     * AI-generated bullet content to reviewers but was actually just the
     * PM's own phrased overview cut short — it masqueraded as real content
     * and hid the fact that the AI never ran.
     *
     * The method is retained (not deleted) so callers keep working and future
     * heuristics can be added without changing signatures. Combine with the
     * `_summary_fallback` marker on each row to differentiate "AI didn't run"
     * from "AI ran and returned empty" downstream.
     *
     * @param  string $overview  PM-authored phrased overview (unused in current shape)
     * @return string            Always an empty string in the 260726-fx4 shape.
     */
    private function fallbackSummary(string $overview): string
    {
        return '';
    }
}

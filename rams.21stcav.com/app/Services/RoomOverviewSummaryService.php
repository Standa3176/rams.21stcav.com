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
                $overview = (string) ($r['overview'] ?? '');
                if ($room !== '' && isset($summaries[$room])) {
                    $r['works_summary'] = $summaries[$room]['summary'] !== ''
                        ? $summaries[$room]['summary']
                        : $this->fallbackSummary($overview);
                } else {
                    $r['works_summary'] = $this->fallbackSummary($overview);
                }
                return $r;
            }, $roomOverviews);
        } catch (AIGenerationException $e) {
            Log::warning('RoomOverviewSummaryService: AI summary failed, using fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_map(function ($r) {
            $r['works_summary'] = $this->fallbackSummary((string) ($r['overview'] ?? ''));
            return $r;
        }, $roomOverviews);
    }

    private function fallbackSummary(string $overview): string
    {
        $text = trim($overview);
        if ($text === '') {
            return '';
        }

        // Build a minimal structured block from keywords we can detect without AI.
        $lines = [];

        // Try to derive a room type hint from the room name (passed via context elsewhere;
        // here we just use the first sentence of the overview as a fallback label).
        $firstSentence = preg_split('/(?<=[.!?])\s+/', $text)[0] ?? $text;
        $lines[] = 'Works: ' . (mb_strlen($firstSentence) > 120
            ? mb_substr($firstSentence, 0, 120) . '…'
            : trim($firstSentence));

        return implode("\n", $lines);
    }
}

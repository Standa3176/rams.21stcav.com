<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\RoomOverviewSummaryPrompt;
use App\Exceptions\AIGenerationException;
use Illuminate\Support\Facades\Log;

class RoomOverviewSummaryService
{
    /**
     * @param  array  $roomOverviews  [['room' => '', 'overview' => '', 'summary' => ''], ...]
     * @return array  same structure with updated summaries
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
                $r['summary'] = '';
                return $r;
            }, $roomOverviews);
        }

        try {
            $prompt = (new RoomOverviewSummaryPrompt())->withContext(['rooms' => $roomsForAi]);
            $result = AIManager::run($prompt, ['rooms' => $roomsForAi]);
            $decoded = is_string($result) ? json_decode($result, true) : $result;

            $summaries = [];
            foreach (($decoded['summaries'] ?? []) as $item) {
                $room = trim((string) ($item['room'] ?? ''));
                $summary = trim((string) ($item['summary'] ?? ''));
                if ($room !== '' && $summary !== '') {
                    $summaries[$room] = $summary;
                }
            }

            return array_map(function ($r) use ($summaries) {
                $room = (string) ($r['room'] ?? '');
                $overview = (string) ($r['overview'] ?? '');
                $r['summary'] = $room !== '' && isset($summaries[$room])
                    ? $summaries[$room]
                    : $this->fallbackSummary($overview);
                return $r;
            }, $roomOverviews);
        } catch (AIGenerationException $e) {
            Log::warning('RoomOverviewSummaryService: AI summary failed, using fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_map(function ($r) {
            $r['summary'] = $this->fallbackSummary((string) ($r['overview'] ?? ''));
            return $r;
        }, $roomOverviews);
    }

    private function fallbackSummary(string $overview): string
    {
        $text = trim($overview);
        if ($text === '') {
            return '';
        }

        $sentence = preg_split('/(?<=[.!?])\s+/', $text)[0] ?? $text;
        $sentence = trim($sentence);

        return mb_strlen($sentence) > 200 ? mb_substr($sentence, 0, 200) . '…' : $sentence;
    }
}

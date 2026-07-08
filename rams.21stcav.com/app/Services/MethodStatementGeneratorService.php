<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Generates method statement phases for a RAMS document.
 *
 * This is the only stage in the RAMS pipeline that uses AI.
 * It wraps MethodStatementService and adds:
 *   1. An AI response cache layer (ai_cache table) — keyed by a SHA-256
 *      hash of the project summary, activities, equipment summary, and
 *      hazard labels. Identical inputs skip the AI call entirely.
 *   2. Content-validation with retry logic on top of the AI call.
 *
 * Validation rules (all must pass):
 *   - Between 4 and 6 phases
 *   - Each phase has at least 2 steps
 *   - Total flattened text length >= 200 characters
 *   - Does not contain the phrase "I cannot"
 *   - Does not contain markdown table syntax (| --- | separators)
 *
 * If both attempts fail validation, the static fallback is returned
 * so RAMS generation always completes.
 *
 * generateFallback() is called directly by RamsBuilderService when
 * confidence < 0.5, in which case no AI call is made at all.
 */
class MethodStatementGeneratorService
{
    /** Minimum total character count across all phase steps. */
    private const MIN_CONTENT_LENGTH = 200;

    /** Minimum number of phases required for the result to be valid. */
    private const MIN_PHASE_COUNT = 4;

    /** Maximum number of phases allowed for the result to be valid. */
    private const MAX_PHASE_COUNT = 9;

    /** Each phase must have at least this many steps. */
    private const MIN_STEPS_PER_PHASE = 2;

    /** Maximum number of generate() attempts before falling back. */
    private const MAX_ATTEMPTS = 2;

    /** Number of phases the fallback must return. */
    private const FALLBACK_PHASE_COUNT = 6;

    /**
     * Audit M-04 (2026-07) — mandatory Step 1 H&S phrases. The prompt already
     * REQUIRES the model to include these; the validator now enforces it so a
     * prompt-injection payload that flips Step 1 (e.g. "skip permit-to-work",
     * "no PPE required") cannot land as generated content. Compared
     * case-insensitively as substrings — any wording that includes all five is
     * accepted, so the model retains room to phrase them naturally.
     *
     * If any are missing, isValid() returns false → retry → fall back to the
     * static 6-phase template that hardcodes every phrase.
     */
    private const MANDATORY_STEP1_PHRASES = [
        'toolbox talk',
        'asbestos',
        'permit-to-work',
        'assembly point',
        'ppe',
    ];

    /**
     * Audit M-04 — injection-attempt tells. A well-formed method statement
     * describes site work; it never asks the reader to disregard prior
     * instructions or echoes back the sentinels from the input prompt.
     * If any of these appear the response is rejected without a retry.
     */
    private const INJECTION_MARKER_PHRASES = [
        'ignore the above',
        'ignore previous',
        'ignore all previous',
        'disregard the above',
        'disregard previous',
        'disregard all previous',
        'system prompt',
        '<<user_data>>',
        '<<end_user_data>>',
    ];

    public function __construct(
        private readonly MethodStatementService $methodStatement,
        private readonly AICacheService         $cache,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Generate method statement phases via AI, with caching, content
     * validation, and retry.
     *
     * @param  array  $parsedQuote  Output from QuoteParserService
     * @param  array  $classified   Output from EquipmentClassifierService
     * @param  array  $hazards      Hazard rows from RiskTemplateResolverService
     * @return array  ['phases' => [['title' => string, 'steps' => string[]], ...]]
     */
    public function generate(array $parsedQuote, array $classified, array $hazards = []): array
    {
        $cacheKey = $this->buildCacheKey($parsedQuote, $classified, $hazards);

        // ── Cache read ────────────────────────────────────────────────────────
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            try {
                $decoded = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded) && $this->isValid($decoded)) {
                    Log::info('MethodStatementGeneratorService: cache hit', [
                        'hash' => $cacheKey,
                    ]);

                    return $decoded;
                }

                Log::warning('MethodStatementGeneratorService: cached response failed validation, re-generating', [
                    'hash'   => $cacheKey,
                    'reason' => is_array($decoded) ? $this->failureReason($decoded) : 'decoded value is not an array',
                ]);
            } catch (\JsonException $e) {
                Log::warning('MethodStatementGeneratorService: cached response JSON decode failed, re-generating', [
                    'hash'  => $cacheKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── AI generation with retry ──────────────────────────────────────────
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->methodStatement->generate($parsedQuote, $classified, $hazards);

            if ($this->isValid($result)) {
                // ── Cache write ───────────────────────────────────────────────
                $this->cache->store(
                    $cacheKey,
                    $this->buildCachePrompt($parsedQuote, $classified, $hazards),
                    json_encode($result),
                );

                Log::info('MethodStatementGeneratorService: AI result stored to cache', [
                    'hash'        => $cacheKey,
                    'phase_count' => count($result['phases'] ?? []),
                ]);

                return $result;
            }

            Log::warning('MethodStatementGeneratorService: output failed content validation', [
                'attempt' => $attempt,
                'reason'  => $this->failureReason($result),
                'max'     => self::MAX_ATTEMPTS,
            ]);
        }

        Log::warning('MethodStatementGeneratorService: all attempts failed validation, using static fallback.', [
            'attempts_made' => self::MAX_ATTEMPTS,
        ]);

        return $this->normalizeFallback();
    }

    /**
     * Return the static fallback method statement without making any AI call.
     *
     * Called by RamsBuilderService when parse confidence is below threshold.
     * Not cached — the fallback is static and needs no DB round-trip.
     */
    public function generateFallback(): array
    {
        return $this->normalizeFallback();
    }

    // =========================================================================
    // PRIVATE — FALLBACK NORMALISATION
    // =========================================================================

    /**
     * Return exactly FALLBACK_PHASE_COUNT (5) phases.
     *
     * Slices any extra phases returned by the underlying fallback and pads
     * with a generic phase if fewer than 5 are present.
     */
    private function normalizeFallback(): array
    {
        $result = $this->methodStatement->fallback();

        $phases = $result['phases'] ?? [];

        $phases = array_slice($phases, 0, self::FALLBACK_PHASE_COUNT);

        while (count($phases) < self::FALLBACK_PHASE_COUNT) {
            $phases[] = [
                'title' => 'General Works',
                'steps' => ['Carry out works in accordance with RAMS and site conditions.'],
            ];
        }

        return ['phases' => array_values($phases)];
    }

    // =========================================================================
    // PRIVATE — CACHE KEY
    // =========================================================================

    /**
     * Build the SHA-256 cache key from the four inputs that determine the
     * content of the generated method statement.
     */
    private function buildCacheKey(array $parsedQuote, array $classified, array $hazards): string
    {
        return $this->cache->hash(
            json_encode($this->buildCacheComponents($parsedQuote, $classified, $hazards))
        );
    }

    /**
     * Build the canonical prompt string stored alongside the response.
     */
    private function buildCachePrompt(array $parsedQuote, array $classified, array $hazards): string
    {
        return json_encode($this->buildCacheComponents($parsedQuote, $classified, $hazards));
    }

    /**
     * Assemble the four cache-key components into a canonical, deterministic array.
     */
    private function buildCacheComponents(array $parsedQuote, array $classified, array $hazards): array
    {
        $activities = $classified['activities'] ?? [];
        sort($activities);

        $hazardLabels = array_values(array_filter(
            array_map(
                static fn ($h): string => is_array($h) ? (string) ($h['hazard'] ?? '') : (string) $h,
                $hazards,
            ),
            static fn (string $s): bool => $s !== '',
        ));
        sort($hazardLabels);

        $equipmentTop = array_values(array_filter(array_map(
            static function ($e): string {
                if (! is_array($e)) {
                    return '';
                }
                $qty  = (int) ($e['qty'] ?? 1);
                $desc = trim((string) ($e['description'] ?? ''));
                if ($desc === '') {
                    return '';
                }
                return ($qty > 0 ? $qty . '× ' : '') . $desc;
            },
            array_slice((array) ($parsedQuote['equipment'] ?? []), 0, 6)
        ), static fn (string $s): bool => $s !== ''));

        $rooms = array_values(array_filter(
            array_map('strval', (array) ($parsedQuote['rooms'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));
        sort($rooms);

        return [
            'prompt_version'   => 'v2-20260330',
            'project_summary'   => $this->resolveProjectSummary($parsedQuote, $classified),
            'activities'        => $activities,
            'equipment_summary' => (string) ($classified['summary'] ?? ''),
            'hazards'           => $hazardLabels,
            'equipment_top'     => $equipmentTop,
            'rooms'             => $rooms,
            'room_overview_summaries' => $this->buildRoomOverviewSummary($parsedQuote),
        ];
    }

    /**
     * Resolve the project summary string.
     */
    private function resolveProjectSummary(array $parsedQuote, array $classified): string
    {
        if (! empty($parsedQuote['tasks'])) {
            return implode('; ', array_slice($parsedQuote['tasks'], 0, 5));
        }

        if (! empty($classified['summary'])) {
            return $classified['summary'];
        }

        return 'AV installation works as per quotation';
    }

    /**
     * Build a compact room summary string for cache keys so room changes re-generate.
     */
    private function buildRoomOverviewSummary(array $parsed): string
    {
        $rows = array_filter(
            (array) ($parsed['room_overviews'] ?? []),
            static fn ($r): bool => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        );

        if (empty($rows)) {
            return '';
        }

        // Pre-load solution types referenced in room_overviews
        $stIds = array_filter(array_unique(array_map(
            fn ($r) => (int) ($r['solution_type_id'] ?? 0),
            $rows
        )));
        $solutionTypes = [];
        if (! empty($stIds)) {
            foreach (\App\Models\SolutionType::whereIn('id', $stIds)->get() as $st) {
                $solutionTypes[$st->id] = $st;
            }
        }

        $parts = [];
        foreach ($rows as $row) {
            $room = trim((string) ($row['room'] ?? ''));
            // Phase 22.1 D-07: dropped the `?? $row['summary']` legacy fallback.
            // `works_summary` is the single canonical source. The one-off
            // `summary → works_summary` backfill is handled by
            // rams:backfill-room-overview-summary (Plan 03 Task 1).
            $summary  = trim((string) ($row['works_summary'] ?? ''));
            $overview = trim((string) ($row['overview']      ?? ''));

            if ($room === '') {
                continue;
            }

            if ($summary === '' && $overview !== '') {
                $summary = $this->firstSentence($overview);
            }

            $stId  = (int) ($row['solution_type_id'] ?? 0);
            $st    = $stId ? ($solutionTypes[$stId] ?? null) : null;
            $label = $st ? "{$room} [{$st->name}]" : $room;

            if ($summary === '' && ! $st) {
                continue;
            }

            $methodNote = '';
            if ($st && $st->install_method) {
                $steps      = $st->methodLines();
                $methodNote = ' [Install: ' . implode('; ', array_slice($steps, 0, 3)) . ']';
            }

            $parts[] = "{$label}: {$summary}{$methodNote}";
        }

        return $parts ? implode(' | ', $parts) : '';
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $sentence = preg_split('/(?<=[.!?])\s+/', $text)[0] ?? $text;
        $sentence = trim($sentence);

        return mb_strlen($sentence) > 220 ? mb_substr($sentence, 0, 220) . '…' : $sentence;
    }

    // =========================================================================
    // PRIVATE — CONTENT VALIDATION
    // =========================================================================

    /**
     * Return true when the AI output passes all content quality guards.
     */
    private function isValid(array $result): bool
    {
        $phases = $result['phases'] ?? [];

        if (empty($phases)) {
            return false;
        }

        // Guard 1: phase count must be within the allowed range (4–6).
        $phaseCount = count($phases);
        if ($phaseCount < self::MIN_PHASE_COUNT || $phaseCount > self::MAX_PHASE_COUNT) {
            return false;
        }

        // Guard 2: each phase must have at least MIN_STEPS_PER_PHASE steps.
        foreach ($phases as $phase) {
            $steps = array_filter(
                (array) ($phase['steps'] ?? []),
                static fn ($s): bool => strlen(trim((string) $s)) > 0,
            );

            if (count($steps) < self::MIN_STEPS_PER_PHASE) {
                return false;
            }
        }

        $text = $this->flattenText($phases);

        // Guard 3: minimum content length.
        if (strlen($text) < self::MIN_CONTENT_LENGTH) {
            return false;
        }

        // Guard 4: AI refusal / incapability phrase.
        if (stripos($text, 'i cannot') !== false) {
            return false;
        }

        // Guard 5: markdown table separator row.
        if (preg_match('/\|[\s:]*-{2,}[\s:]*\|/', $text)) {
            return false;
        }

        // Guard 6 (audit M-04): mandatory Step 1 H&S phrases must all be
        // present somewhere in the response. Missing any is a compliance
        // failure — either the model wandered off, or a prompt-injection
        // succeeded in stripping the safety brief.
        $lowered = strtolower($text);
        foreach (self::MANDATORY_STEP1_PHRASES as $phrase) {
            if (strpos($lowered, $phrase) === false) {
                return false;
            }
        }

        // Guard 7 (audit M-04): injection-attempt tells. Any of these in the
        // output means either the model was tricked or it echoed our own
        // sentinel tags back — both cases are unsafe to render.
        foreach (self::INJECTION_MARKER_PHRASES as $marker) {
            if (strpos($lowered, $marker) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Concatenate all phase titles and step text into a single string.
     */
    private function flattenText(array $phases): string
    {
        $parts = [];

        foreach ($phases as $phase) {
            if (isset($phase['title'])) {
                $parts[] = (string) $phase['title'];
            }

            foreach ((array) ($phase['steps'] ?? []) as $step) {
                $parts[] = (string) $step;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Return a human-readable reason why the given result failed validation.
     */
    private function failureReason(array $result): string
    {
        $phases = $result['phases'] ?? [];

        if (empty($phases)) {
            return 'phases array is empty';
        }

        $count = count($phases);
        if ($count < self::MIN_PHASE_COUNT || $count > self::MAX_PHASE_COUNT) {
            return sprintf(
                'expected %d–%d phases, got %d',
                self::MIN_PHASE_COUNT,
                self::MAX_PHASE_COUNT,
                $count,
            );
        }

        foreach ($phases as $i => $phase) {
            $steps = array_filter(
                (array) ($phase['steps'] ?? []),
                static fn ($s): bool => strlen(trim((string) $s)) > 0,
            );

            if (count($steps) < self::MIN_STEPS_PER_PHASE) {
                return sprintf(
                    'phase %d has %d step(s), minimum is %d',
                    $i + 1,
                    count($steps),
                    self::MIN_STEPS_PER_PHASE,
                );
            }
        }

        $text = $this->flattenText($phases);

        if (strlen($text) < self::MIN_CONTENT_LENGTH) {
            return sprintf(
                'content too short (%d chars, minimum %d)',
                strlen($text),
                self::MIN_CONTENT_LENGTH,
            );
        }

        if (stripos($text, 'i cannot') !== false) {
            return 'contains AI refusal phrase "I cannot"';
        }

        if (preg_match('/\|[\s:]*-{2,}[\s:]*\|/', $text)) {
            return 'contains markdown table syntax';
        }

        // Audit M-04 — reason strings for the new guards.
        $lowered = strtolower($text);
        foreach (self::MANDATORY_STEP1_PHRASES as $phrase) {
            if (strpos($lowered, $phrase) === false) {
                return sprintf('missing mandatory H&S phrase "%s"', $phrase);
            }
        }
        foreach (self::INJECTION_MARKER_PHRASES as $marker) {
            if (strpos($lowered, $marker) !== false) {
                return sprintf('contains injection marker "%s"', $marker);
            }
        }

        return 'unknown';
    }
}

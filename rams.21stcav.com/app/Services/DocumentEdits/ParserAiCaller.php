<?php

namespace App\Services\DocumentEdits;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\BasePrompt;

/**
 * Thin wrapper around AIManager::run for the parser pipeline.
 *
 * The wrapper exists so the parser can be unit-tested without hitting the
 * real AI manager / HTTP: tests mock ParserAiCaller via the container and
 * return deterministic strings (or throw to simulate timeouts).
 *
 * AIManager::run normally returns the already-decoded JSON array. The parser
 * service handles both shapes:
 *   - success: array from AIManager
 *   - failure: AIManager throws → caller surfaces parse_failed per attempt
 */
class ParserAiCaller
{
    /**
     * Call the AI manager with the given prompt. Returns the decoded JSON
     * payload produced by the model.
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable  Propagates AIManager / provider errors so the
     *                     parser can catch and retry.
     */
    public function call(BasePrompt $prompt, ?string $modelName = null): array
    {
        $provider = $modelName ?: (string) config('ai.default', 'claude');
        return AIManager::run($prompt, [], $provider);
    }
}

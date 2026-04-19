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
     * Provider vs model_name:
     *   AIManager::run accepts a PROVIDER key ('claude' | 'openai' | etc.),
     *   NOT a model identifier like 'gpt-4o' or 'claude-sonnet-4-6'. User-
     *   supplied `model_name` is therefore treated as AUDIT-ONLY and used
     *   as the provider key only when it happens to match a configured
     *   provider. Anything else falls back to the default provider — so a
     *   reasonable `model_name: "gpt-4o"` never causes parse to hard-fail
     *   at the AIManager layer.
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable  Propagates AIManager / provider errors so the
     *                     parser can catch and retry.
     */
    public function call(BasePrompt $prompt, ?string $modelName = null): array
    {
        return AIManager::run($prompt, [], $this->resolveProvider($modelName));
    }

    /**
     * Narrow `model_name` to a provider key ONLY when it matches a
     * configured provider. Otherwise return the configured default.
     * Keeps the model_name audit trail intact without risking a provider
     * lookup miss inside AIManager.
     */
    public function resolveProvider(?string $modelName): string
    {
        $default = (string) config('ai.default', 'claude');
        if ($modelName === null || $modelName === '') {
            return $default;
        }

        $configuredProviders = array_keys((array) config('ai.providers', []));
        if (empty($configuredProviders)) {
            // Provider config not present (e.g. tests) — fall back to the
            // well-known keys before defaulting.
            $configuredProviders = ['claude', 'openai'];
        }

        $candidate = strtolower(trim($modelName));
        return in_array($candidate, $configuredProviders, true) ? $candidate : $default;
    }
}

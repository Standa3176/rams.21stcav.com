<?php

namespace App\Core\AI;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Prompts\BasePrompt;
use App\Core\AI\Providers\ClaudeProvider;
use App\Core\AI\Providers\OpenAIProvider;
use App\Services\AICacheService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Enterprise AI Manager
 *
 * Central entry point for all AI interactions across modules.
 * Resolves the configured provider, handles retries, and provides
 * convenience wrappers for the two common patterns:
 *
 *   1. Execute a typed Prompt DTO with automatic retry:
 *      $data = AIManager::run(new RamsPrompt(), $context);
 *
 *   2. Execute against an explicit provider:
 *      $data = AIManager::make('claude')->execute($prompt);
 *
 * All modules MUST use AIManager — they must not instantiate providers directly.
 */
class AIManager
{
    private const MAX_ATTEMPTS = 2;

    // ── Static factory ────────────────────────────────────────────────────────

    /**
     * Resolve and return a provider instance.
     *
     * @param  string|null  $provider  'claude' | 'openai' | null (uses config default)
     */
    public static function make(?string $provider = null): AIProviderContract
    {
        $key = $provider ?? config('ai.default', 'claude');

        return match ($key) {
            'claude' => app(ClaudeProvider::class),
            'openai' => app(OpenAIProvider::class),
            default  => throw new \InvalidArgumentException(
                "Unknown AI provider '{$key}'. Supported: claude, openai."
            ),
        };
    }

    // ── Prompt execution with retry ───────────────────────────────────────────

    /**
     * Execute a BasePrompt with automatic retry on failure or invalid JSON.
     *
     * On the second attempt, the retrySuffix is appended to the prompt text
     * so the model knows to correct its output format.
     *
     * @param  BasePrompt   $prompt    Prompt DTO (not yet built).
     * @param  array        $context   Data merged into prompt->build($context).
     * @param  string|null  $provider  Override provider; null = config default.
     * @return array                   Decoded JSON response.
     *
     * @throws \App\Exceptions\AIGenerationException  After all attempts exhausted.
     */
    public static function run(BasePrompt $prompt, array $context = [], ?string $provider = null): array
    {
        $ai       = static::make($provider);
        $cache    = app(AICacheService::class);
        $attempts = 0;

        // ── Cache check (first attempt only, before any retry suffix is added) ──
        $builtText = $prompt->build($context);
        $hash      = $cache->hash($builtText);

        // Vision/PDF prompts have variable binary input (image / pdf bytes) that
        // is NOT part of the prompt text. Caching by prompt-text alone would
        // serve the same response for every image submitted to the same prompt
        // class — actively wrong. Skip the cache entirely for prompts that
        // attach binary content.
        $skipCache = $prompt->usesImage() || $prompt->usesPdf();
        $cached    = $skipCache ? null : $cache->get($hash);

        if ($cached !== null) {
            $decoded = json_decode($cached, true);

            if (is_array($decoded)) {
                Log::info('AI cache hit', ['prompt' => get_class($prompt), 'hash' => $hash]);
                return $decoded;
            }
        }

        // ── Live call with retry ───────────────────────────────────────────────
        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            $isRetry = $attempts > 1;

            if ($isRetry) {
                $context['is_retry'] = true;
            }

            // Clone the prompt and propagate the current context (including is_retry)
            // so that build() inside execute() has access to all context data.
            $builtPrompt = clone $prompt;
            $builtPrompt->withContext($context);

            try {
                $result = $ai->execute($builtPrompt);

                if (! empty($result)) {
                    Log::info('AI generation succeeded', [
                        'provider' => $ai->getProviderKey(),
                        'prompt'   => get_class($prompt),
                        'attempt'  => $attempts,
                    ]);

                    // Store in cache (use the original first-attempt text as the key).
                    // Skip for vision / PDF prompts — see $skipCache above.
                    if (! $skipCache) {
                        $cache->store($hash, $builtText, json_encode($result), $ai->getProviderKey());
                    }

                    return $result;
                }

                Log::warning('AI returned empty result', [
                    'provider' => $ai->getProviderKey(),
                    'attempt'  => $attempts,
                ]);
            } catch (RuntimeException $e) {
                Log::warning('AI provider attempt failed', [
                    'provider' => $ai->getProviderKey(),
                    'prompt'   => get_class($prompt),
                    'attempt'  => $attempts,
                    'error'    => $e->getMessage(),
                ]);

                if ($attempts >= self::MAX_ATTEMPTS) {
                    throw new \App\Exceptions\AIGenerationException(
                        "AI generation failed after {$attempts} attempt(s): " . $e->getMessage(),
                        previous: $e
                    );
                }
            }
        }

        throw new \App\Exceptions\AIGenerationException(
            'AI generation failed: the provider returned an empty response after '
            . self::MAX_ATTEMPTS . ' attempts.'
        );
    }

    // ── Convenience wrappers ──────────────────────────────────────────────────

    /**
     * Execute a plain text prompt and return decoded JSON.
     * For ad-hoc use where a typed Prompt DTO is not warranted.
     */
    public static function completeJson(string $prompt, array $options = [], ?string $provider = null): array
    {
        return static::make($provider)->completeJson($prompt, $options);
    }

    /**
     * Execute a PDF + prompt and return decoded JSON.
     */
    public static function completeWithPdf(
        string  $pdfBase64,
        string  $prompt,
        array   $options   = [],
        ?string $provider  = null
    ): array {
        return static::make($provider)->completeWithPdf($pdfBase64, $prompt, $options);
    }

    // ── Provider inspection ───────────────────────────────────────────────────

    /**
     * Return the default provider key from config.
     */
    public static function defaultProvider(): string
    {
        return config('ai.default', 'claude');
    }

    /**
     * Return all configured provider keys.
     */
    public static function availableProviders(): array
    {
        return array_keys(config('ai.providers', []));
    }
}

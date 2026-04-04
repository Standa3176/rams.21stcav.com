<?php

namespace App\Services\AI;

class AIProviderFactory
{
    /**
     * Resolve and return the correct AI provider instance via the service container.
     *
     * @param  string|null  $provider  One of: 'claude', 'openai'
     *                                 Falls back to config('ai.default') when null.
     *
     * @throws \InvalidArgumentException for unknown provider keys.
     */
    public static function make(?string $provider = null): AIProviderInterface
    {
        $provider ??= config('ai.default', 'claude');

        $class = match ($provider) {
            'openai' => OpenAIProvider::class,
            'claude' => ClaudeProvider::class,
            default  => throw new \InvalidArgumentException(
                "Unknown AI provider '{$provider}'. Valid options: claude, openai."
            ),
        };

        // Using app()->make() lets the container inject PromptBuilderService
        // (and any future dependencies) automatically, making providers testable.
        return app()->make($class);
    }
}

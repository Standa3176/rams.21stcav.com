<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Manages AI provider settings: persisting .env values and pinging providers
 * to verify connectivity. Extracted from RamsController to keep the controller
 * focused on HTTP request/response handling.
 */
class AiSettingsService
{
    /**
     * Persist AI provider settings to .env and clear the config cache.
     *
     * @param  array{
     *     default_provider: string,
     *     claude_model?: string,
     *     openai_model?: string,
     *     openai_endpoint?: string,
     *     claude_api_key?: string,
     *     openai_api_key?: string,
     * } $data
     */
    public function save(array $data): void
    {
        $updates = [
            'AI_DEFAULT_PROVIDER' => $data['default_provider'],
            'CLAUDE_MODEL'        => $data['claude_model']    ?? 'claude-sonnet-4-6',
            'OPENAI_MODEL'        => $data['openai_model']    ?? 'gpt-4o',
            'OPENAI_ENDPOINT'     => $data['openai_endpoint'] ?? 'https://api.openai.com/v1/chat/completions',
        ];

        if (! empty($data['claude_api_key'])) {
            $updates['CLAUDE_API_KEY'] = $data['claude_api_key'];
        }

        if (! empty($data['openai_api_key'])) {
            $updates['OPENAI_API_KEY'] = $data['openai_api_key'];
        }

        $this->writeEnvValues($updates);

        // Remove compiled config cache so the next request re-reads .env.
        Artisan::call('config:clear');
    }

    /**
     * Return the configured default provider key (e.g. 'claude', 'openai').
     */
    public function defaultProvider(): string
    {
        return config('ai.default', 'claude');
    }

    /**
     * Return the model name for the currently configured default provider.
     */
    public function defaultModel(): string
    {
        return config('ai.providers.' . $this->defaultProvider() . '.model', '');
    }

    /**
     * Ping the given provider with a minimal request to verify connectivity.
     *
     * @throws RuntimeException  If the provider is unreachable or the key is invalid.
     */
    public function testConnection(string $provider): void
    {
        match ($provider) {
            'claude' => $this->pingClaude(),
            'openai' => $this->pingOpenAi(),
            default  => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function pingClaude(): void
    {
        $apiKey   = config('ai.providers.claude.api_key');
        $model    = config('ai.providers.claude.model');
        $endpoint = config('ai.providers.claude.endpoint');

        if (empty($apiKey)) {
            throw new RuntimeException('No Claude API key is configured.');
        }

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(15)->post($endpoint, [
            'model'      => $model,
            'max_tokens' => 5,
            'messages'   => [['role' => 'user', 'content' => 'Reply with OK']],
        ]);

        if ($response->failed()) {
            $msg = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('Claude API error ' . $response->status() . ': ' . $msg);
        }
    }

    private function pingOpenAi(): void
    {
        $apiKey   = config('ai.providers.openai.api_key');
        $model    = config('ai.providers.openai.model');
        $endpoint = config('ai.providers.openai.endpoint');

        if (empty($apiKey)) {
            throw new RuntimeException('No OpenAI API key is configured.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(15)->post($endpoint, [
            'model'      => $model,
            'max_tokens' => 5,
            'messages'   => [['role' => 'user', 'content' => 'Reply with OK']],
        ]);

        if ($response->failed()) {
            $msg = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('OpenAI API error ' . $response->status() . ': ' . $msg);
        }
    }

    /**
     * Write or update key=value pairs in the .env file atomically.
     * Values containing spaces or special characters are double-quoted.
     */
    private function writeEnvValues(array $values): void
    {
        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        foreach ($values as $key => $value) {
            // Collapse newlines to prevent .env line injection
            $str  = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
            // Quote values that contain spaces, double-quotes, $, backslash, backtick or #
            $safe = preg_match('/[\s"$\\\\`#]/', $str)
                ? '"' . str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $str) . '"'
                : $str;

            if (preg_match('/^' . preg_quote($key, '/') . '=/m', $envContent)) {
                $envContent = preg_replace(
                    '/^' . preg_quote($key, '/') . '=.*/m',
                    $key . '=' . $safe,
                    $envContent,
                );
            } else {
                $envContent .= PHP_EOL . $key . '=' . $safe;
            }
        }

        // Atomic write: write to temp file, then rename over the original.
        $tmpPath = $envPath . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $envContent, LOCK_EX);
        rename($tmpPath, $envPath);
    }
}

<?php

namespace App\Core\AI\Providers;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Prompts\BasePrompt;
use App\Services\AIUsageService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Claude provider for the enterprise AI abstraction layer.
 *
 * Uses Claude's Messages API with native PDF document vision support.
 * Config keys: ai.providers.claude.api_key / model / endpoint
 */
class ClaudeProvider implements AIProviderContract
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_TIMEOUT   = 180;

    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = config('ai.providers.claude.api_key', '');
        $this->model    = config('ai.providers.claude.model', 'claude-sonnet-4-6');
        $this->endpoint = config('ai.providers.claude.endpoint', 'https://api.anthropic.com/v1/messages');
    }

    // ── AIProviderContract ────────────────────────────────────────────────────

    public function completeJson(string $prompt, array $options = []): array
    {
        $payload = $this->buildTextPayload($prompt, $options);

        return $this->dispatch($payload, $options);
    }

    public function completeWithPdf(string $pdfBase64, string $prompt, array $options = []): array
    {
        $payload = $this->buildPdfPayload($pdfBase64, $prompt, $options);

        return $this->dispatch($payload, $options);
    }

    public function execute(BasePrompt $prompt): array
    {
        $text    = $prompt->build();
        $options = [
            'max_tokens' => $prompt->maxTokens(),
            'system'     => $prompt->systemMessage(),
            'prompt_class' => get_class($prompt),
        ];

        if ($prompt->usesPdf()) {
            return $this->completeWithPdf($prompt->getPdfBase64(), $text, $options);
        }

        return $this->completeJson($text, $options);
    }

    public function getProviderKey(): string
    {
        return 'claude';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildTextPayload(string $prompt, array $options): array
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (! empty($options['system'])) {
            $payload['system'] = $options['system'];
        }

        return $payload;
    }

    private function buildPdfPayload(string $pdfBase64, string $prompt, array $options): array
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $pdfBase64,
                            ],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ],
        ];

        if (! empty($options['system'])) {
            $payload['system'] = $options['system'];
        }

        return $payload;
    }

    private function dispatch(array $payload, array $options): array
    {
        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type'      => 'application/json',
        ])
        ->timeout($options['timeout'] ?? self::DEFAULT_TIMEOUT)
        ->post($this->endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Claude API error [{$response->status()}]: " . $response->body()
            );
        }

        $inputTokens  = $response->json('usage.input_tokens');
        $outputTokens = $response->json('usage.output_tokens');
        $totalTokens  = $response->json('usage.total_tokens');
        if ($totalTokens === null && $inputTokens !== null && $outputTokens !== null) {
            $totalTokens = (int) $inputTokens + (int) $outputTokens;
        }

        app(AIUsageService::class)->record([
            'provider'      => $this->getProviderKey(),
            'model'         => $this->model,
            'prompt'        => $options['prompt_class'] ?? null,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => $totalTokens,
        ]);

        $stopReason = $response->json('stop_reason', '');

        if ($stopReason === 'max_tokens') {
            throw new RuntimeException(
                'Claude response was truncated (max_tokens reached). '
                . 'Increase maxTokens() on the prompt or reduce the input size.'
            );
        }

        $raw = $this->stripFences($response->json('content.0.text', ''));

        // Safety net: replace literal unescaped newlines/tabs inside JSON string values.
        // Claude occasionally emits real newlines inside string values, which is invalid JSON.
        // This regex replaces \n and \t that appear inside a quoted string (between " ... ")
        // with their proper JSON escape sequences.
        $sanitised = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/su',
            function (array $m): string {
                $inner = str_replace(["\r\n", "\r", "\n", "\t"], ['\\n', '\\n', '\\n', '\\t'], $m[1]);
                return '"' . $inner . '"';
            },
            $raw
        ) ?? $raw;

        $decoded = json_decode($sanitised, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Claude returned invalid JSON: ' . json_last_error_msg()
                . ' — Raw: ' . substr($raw, 0, 500)
            );
        }

        return $decoded;
    }

    private function stripFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        return trim($text);
    }
}
